<?php

namespace App\Console\Commands;

use App\Models\KasRiwayat;
use App\Models\Ritasi;
use App\Models\RitasiMenunggu;
use App\Models\UjDetail;
use App\Support\GoogleSheets;
use App\Support\KasSeabank;
use App\Support\LembarRitasi;
use App\Support\NomorMobil;
use App\Support\ValidasiUj;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Uang tanah yang dibayar lebih dulu lewat No Surat Jalan ("[SJ LS-2611-0730]" di keterangan, owner 11 Nov 2026) tertahan di Reimburse UJ
 * karena belum ber-No DO (owner 14 Nov 2026). Begitu rit surat jalan itu tersimpan di Ritasi dengan DO-nya, No DO-nya diisi otomatis di
 * Kas Seabank kolom L (lalu aplikasi), sehingga bisa direimburse. Hanya baris yang belum direimburse; DT rit harus sama dengan No Mobil.
 * Kasus asal: UJ-16935 Aris DT 056 (LS-2611-0730 → DO 16396), terlanjur direimburse sebelum aturan ini.
 */
class IsiDoSj extends Command
{
    protected $signature = 'mnp:isi-do-sj {--simulasi : Hanya tampilkan, tanpa menulis}';

    protected $description = 'Isi No DO uang tanah [SJ …] di Kas UJ begitu rit surat jalannya sudah ber-DO di Ritasi';

    public function handle(): int
    {
        $calon = UjDetail::where('biaya_transfer', false)->where('keterangan', 'like', '%[SJ %')
            ->where(fn ($q) => $q->whereNull('no_do')->orWhere('no_do', ''))->whereNull('tanggal_reimburse')->orderBy('baris')->get()
            ->filter(fn (UjDetail $d) => ValidasiUj::jenisKategori($d->kategori) === 'ut');
        if ($calon->isEmpty()) {
            return self::SUCCESS;
        }

        $isi = [];
        foreach ($calon as $d) {
            $seri = RitasiMenunggu::seriDariKeterangan($d->keterangan);
            $k = LembarRitasi::kunciAngka($seri);
            if (! $k) {
                continue;
            }
            // Rit yang No Seri-nya sama dengan penanda, atau penanda + akhiran ("LS-2611-0720-Kendal"), sama seperti doDibayarLewatSj().
            $rit = Ritasi::whereNotNull('no_do')->where('no_do', '!=', '')
                ->where(fn ($q) => $q->where(\Illuminate\Support\Facades\DB::raw("TRIM(LEADING '0' FROM REPLACE(no_seri, ' ', ''))"), (string) $k)
                    ->orWhere('no_seri', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $k).'-%'))
                ->get(['no_do', 'no_seri', 'no_lambung', 'tanggal'])
                ->filter(fn ($r) => ($ks = (string) LembarRitasi::kunciAngka($r->no_seri)) === (string) $k || str_starts_with($ks, $k.'-'));
            $do = $rit->map(fn ($r) => trim(str_replace(['`', "'"], '', (string) $r->no_do)))->filter()->unique();
            if ($do->isEmpty()) {
                continue;
            }
            if ($do->count() > 1) {
                $this->warn("{$d->id_uj}: SJ {$seri} cocok ke beberapa DO (".$do->implode(', ').') — dilewati, isi manual.');

                continue;
            }
            $dtRit = NomorMobil::rapikan($rit->first()->no_lambung);
            if ($dtRit && $d->no_mobil && $dtRit !== NomorMobil::rapikan($d->no_mobil)) {
                $this->warn("{$d->id_uj}: SJ {$seri} di Ritasi milik {$dtRit}, bukan {$d->no_mobil} — dilewati, periksa manual.");

                continue;
            }
            $isi[] = ['d' => $d, 'do' => $do->first(), 'seri' => $seri];
        }
        if (! $isi) {
            return self::SUCCESS;
        }
        foreach ($isi as $x) {
            $this->line("{$x['d']->id_uj} (baris {$x['d']->baris}, {$x['d']->no_mobil}) SJ {$x['seri']} → DO {$x['do']}");
        }
        if ($this->option('simulasi')) {
            return self::SUCCESS;
        }

        $ditulis = Cache::lock('tulis-uj-sheet', 120)->block(60, function () use ($isi) {
            $sheets = GoogleSheets::wajib();
            $l = KasSeabank::LEMBAR;
            $baca = $sheets->nilaiMentah(KasSeabank::id(), array_map(fn ($x) => "{$l}!A{$x['d']->baris}:N{$x['d']->baris}", $isi));
            $data = [];
            $ok = [];
            foreach ($isi as $x) {
                $d = $x['d'];
                $r = $baca["{$l}!A{$d->baris}:N{$d->baris}"][0] ?? [];
                // Sheet harus masih sama: ID UJ, No DO kosong, belum ada tanggal reimburse.
                if (trim((string) ($r[0] ?? '')) !== (string) $d->id_uj || trim((string) ($r[11] ?? '')) !== '' || trim((string) ($r[13] ?? '')) !== '') {
                    $this->warn("{$d->id_uj}: isi sheet baris {$d->baris} sudah berubah — dilewati (dicoba lagi sesudah impor berikutnya).");

                    continue;
                }
                // No DO bernol depan ("0039") tetap teks.
                $data["{$l}!L{$d->baris}"] = [[preg_match('/^0\d/', $x['do']) ? "'".$x['do'] : $x['do']]];
                $ok[] = $x;
            }
            if ($data) {
                $sheets->tulis(KasSeabank::id(), $data);
                foreach ($ok as $x) {
                    UjDetail::whereKey($x['d']->id)->where('id_uj', $x['d']->id_uj)->update(['no_do' => $x['do']]);
                    KasRiwayat::create(['aksi' => 'uj-do-sj', 'lembar' => 'Seabank', 'baris_awal' => $x['d']->baris, 'baris_akhir' => $x['d']->baris,
                        'ringkasan' => mb_strimwidth("{$x['d']->id_uj} {$x['d']->kategori} {$x['d']->no_mobil} ".rp((int) $x['d']->nominal)
                            ." dibayar lewat SJ {$x['seri']}: No DO diisi otomatis {$x['do']} (rit SJ itu sudah ber-DO di Ritasi) — kini bisa direimburse.", 0, 490, '…')]);
                }
            }

            return count($ok);
        });
        $this->info("No DO terisi: {$ditulis} baris.");

        return self::SUCCESS;
    }
}
