<?php

namespace App\Console\Commands;

use App\Models\Proyek;
use App\Models\ProyekAlias;
use App\Models\ProyekTahap;
use App\Support\GoogleSheets;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Isi awal Daftar Proyek dari lembar "Cost Project ERP" (disusun & disetujui owner 12 Okt 2026), ditambah komisi &
 * tanggal bayar dari lembar "Cost Project" asli. Sekali saja: setelah itu Daftar Proyek dikelola di aplikasi.
 */
class ImporDaftarProyek extends Command
{
    protected $signature = 'mnp:impor-daftar-proyek {--ganti : Hapus Daftar Proyek yang ada lalu impor ulang} {--berkas= : Baca dari salinan JSON {erp, cp} (uji tanpa Google Sheets)}';

    protected $description = 'Isi awal Daftar Proyek dari lembar "Cost Project ERP"';

    /** Biaya Kas Harian yang hanya punya cost center (tanpa kode tahap) → tahap penampungnya. */
    private const KAS_CC = [
        'ASG (biaya tanpa tahap)' => 'ASG', 'Infra (tanpa tahap)' => 'Infra', 'Pantai Mutiara 2 - Infra' => 'Infra PM',
        'Pantai Mutiara 2 (biaya belum dirinci)' => 'PM', 'Industri Kendal' => 'Kendal', 'Buhut (biaya lokasi)' => 'Buhut',
        'JKT (biaya lokasi)' => 'JKT', 'DPN (biaya lokasi)' => 'DPN', 'AB (Alat Berat) (biaya lokasi)' => 'AB', 'Tanpa cost center (biaya lokasi)' => '-',
    ];

    public function handle(): int
    {
        if (Proyek::exists() && ! $this->option('ganti')) {
            $this->error('Daftar Proyek sudah berisi. Pakai --ganti untuk menghapus & mengimpor ulang.');

            return self::FAILURE;
        }
        if ($berkas = $this->option('berkas')) {
            [$erp, $cpMentah] = array_values(json_decode(file_get_contents($berkas), true));
        } else {
            $s = GoogleSheets::wajib();
            $id = config('mnp.sheet_cost_project');
            $erp = $s->nilaiMentah($id, ["'Cost Project ERP'!A2:Y400"])["'Cost Project ERP'!A2:Y400"];
            $cpMentah = $s->nilaiMentah($id, ["'Cost Project'!A2:AB400"])["'Cost Project'!A2:AB400"];
        }
        $cp = collect($cpMentah)
            ->filter(fn ($r) => trim((string) ($r[1] ?? '')) !== '')->groupBy(fn ($r) => ProyekAlias::kunci($r[1]));

        $tgl = fn ($v) => is_numeric($v) && $v > 30000 ? Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString() : null;
        $persen = fn ($nilai, $omset) => is_numeric($nilai) && is_numeric($omset) && $omset > 0 ? round($nilai / $omset * 100, 2) : null;
        $pisah = fn ($v) => collect(explode(' | ', (string) $v))->map(fn ($x) => trim(preg_replace('/\s\(\d+\)$/', '', $x)))->filter()->values();

        DB::transaction(function () use ($erp, $cp, $tgl, $persen, $pisah) {
            if ($this->option('ganti')) {
                Proyek::query()->delete();
            }
            $urutan = 0;
            $hitung = ['proyek' => 0, 'tahap' => 0, 'alias' => 0, 'alias_dobel' => 0];
            foreach ($erp as $r) {
                $r = array_pad($r, 25, '');
                $namaTahap = trim(preg_replace('/\s+/', ' ', (string) $r[2]));
                if ($namaTahap === '') {
                    continue;
                }
                $proyek = Proyek::firstOrCreate(['nama' => trim((string) $r[1])], ['urutan' => ++$urutan]);
                if (! $proyek->cost_center && ! in_array(trim((string) $r[6]), ['', '-'], true)) {
                    $proyek->update(['cost_center' => preg_replace('/\s*\(.*\)$/', '', trim((string) $r[6]))]); // "AB (Alat Berat)" → AB
                }
                $hitung['proyek'] = Proyek::count();

                $tipe = match (trim((string) $r[3])) { 'proyek (luas & volume)' => 'luas', 'ritasi' => 'ritasi', default => 'biaya' };
                $selesaiRit = is_numeric($r[15]) ? Carbon::create(1899, 12, 30)->addDays((int) $r[15]) : (trim((string) $r[15]) ? Carbon::parse($r[15]) : null);
                $status = match (strtolower(trim((string) $r[4]))) {
                    'done' => 'selesai', 'progress' => 'progress', 'cancel' => 'batal',
                    default => $tipe === 'biaya' ? 'progress' : ($selesaiRit ? ($selesaiRit->gt(now()->subDays(30)) ? 'progress' : 'selesai') : ($tipe === 'ritasi' ? 'selesai' : 'belum')),
                };
                // Komisi & tanggal bayar dari baris Cost Project asli (baris pertama yang berisi).
                $asli = $pisah($r[20])->flatMap(fn ($n) => $cp->get(ProyekAlias::kunci($n), collect()))->map(fn ($x) => array_pad($x, 28, ''));
                $ambil = fn ($i) => $asli->map(fn ($x) => $x[$i])->first(fn ($v) => $v !== '' && $v !== null);
                $luas = $tipe === 'luas';
                $tahap = ProyekTahap::create([
                    'proyek_id' => $proyek->id, 'nama' => $namaTahap, 'tipe' => $tipe, 'status' => $status,
                    ...array_combine(['surveyor', 'lokasi'], ProyekTahap::pisahSurveyor($r[5])),
                    'volume' => is_numeric($r[7]) ? $r[7] : null, 'luas' => is_numeric($r[8]) ? $r[8] : null,
                    'omset' => is_numeric($r[11]) ? (int) round($r[11]) : null,
                    'harga_m3' => $luas ? ProyekTahap::BAWAAN['harga_m3'] : null,
                    'tarif_bbm' => $luas ? ProyekTahap::BAWAAN['tarif_bbm'] : null,
                    'tarif_rental' => $luas ? ProyekTahap::BAWAAN['tarif_rental'] : null,
                    'komisi_lapangan' => $persen($ambil(22), $ambil(19)) ?? ($luas ? ProyekTahap::BAWAAN['komisi_lapangan'] : null),
                    'komisi_yosua' => $persen($ambil(24), $ambil(19)) ?? ($luas ? ProyekTahap::BAWAAN['komisi_yosua'] : null),
                    'komisi_verlix' => $persen($ambil(26), $ambil(19)) ?? ($luas ? ProyekTahap::BAWAAN['komisi_verlix'] : null),
                    'bayar_lapangan' => $tgl($ambil(23)), 'bayar_yosua' => $tgl($ambil(25)), 'bayar_verlix' => $tgl($ambil(27)),
                    'catatan' => trim((string) $r[24]) ?: null,
                ]);
                $hitung['tahap']++;

                // Alias: nama lama per sumber + nama baku (supaya input baru dengan nama baku langsung terpetakan).
                $alias = [
                    'cost_project' => $pisah($r[20]), 'ritasi' => $pisah($r[21])->push($namaTahap), 'bbm' => $pisah($r[22])->push($namaTahap),
                    'kas' => $pisah($r[23]), 'kas_cc' => collect(isset(self::KAS_CC[$namaTahap]) ? [self::KAS_CC[$namaTahap]] : []),
                ];
                foreach ($alias as $sumber => $nama) {
                    foreach ($nama->unique(fn ($n) => ProyekAlias::kunci($n)) as $n) {
                        if (ProyekAlias::where('sumber', $sumber)->where('kunci', ProyekAlias::kunci($n))->exists()) {
                            $hitung['alias_dobel']++;
                            $this->warn("Alias dobel dilewati: [$sumber] $n (tahap $namaTahap)");

                            continue;
                        }
                        ProyekAlias::create(['proyek_tahap_id' => $tahap->id, 'sumber' => $sumber, 'nama' => trim(preg_replace('/\s+/', ' ', $n)), 'kunci' => ProyekAlias::kunci($n)]);
                        $hitung['alias']++;
                    }
                }
            }
            $this->info("Proyek {$hitung['proyek']}, tahap {$hitung['tahap']}, alias {$hitung['alias']} (dobel dilewati {$hitung['alias_dobel']}).");
        });

        return self::SUCCESS;
    }
}
