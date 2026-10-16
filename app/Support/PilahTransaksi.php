<?php

namespace App\Support;

use App\Models\BoqItem;
use App\Models\ProyekTahap;
use App\Models\TransaksiBoq;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pilah Transaksi Proyek (owner 16 Okt 2026): semua transaksi Kas Harian & Mutasi BCA PT dari proyek induk terpilih,
 * dipilah ke TAHAP DETAIL (mis. PM2 Infra Regatta vs Marina vs Infra PIK) dan ke KODE BOQ, dengan rekomendasi sistem.
 * Tahap Kas disimpan di kas_alokasi (sumber "pilah", mengalahkan Kode GL & lembar Biaya Operasional); tahap BCA di
 * mutasi_pt_tanda (Dihitung + tahap); kode BOQ keduanya di transaksi_boq.
 * Dasar rekomendasi, berurutan: tag Proyek/BOQ di sheet monitoring Infra → transaksi serupa yang sudah dipilah →
 * kata di keterangan (kamus di bawah).
 */
class PilahTransaksi
{
    public const MULAI = '2026-06-01';

    /** Kata di keterangan / tag sheet → nama tahap. */
    public const KATA_TAHAP = [
        '/regatta/i' => 'Pantai Mutiara 2 - Infra Regatta',
        '/marina/i' => 'Pantai Mutiara 2 - Infra Marina',
        '/(infra|pik)\W*(pik\W*)?T\s?0?6\b/i' => 'Infra T06',
        '/(infra|pik)\W*(pik\W*)?T\s?0?5\b/i' => 'Infra T05',
        '/(infra|pik)\W*(pik\W*)?T\s?0?4\b/i' => 'Infra T04',
    ];

    /** Kata di keterangan transaksi → pola uraian item BOQ yang cocok. */
    public const KATA_BOQ = [
        '/beton|\bcor\b|ready ?mix|\bk-?3[05]0\b|bangun rancang|\bbrik\b/i' => '/beton k-?350|beton/i',
        '/besi|ulir|angkur/i' => '/pembesian|besi/i',
        '/chemical|hilti|re-?100/i' => '/chemical/i',
        '/bobok|\bbor\b/i' => '/bobok/i',
        '/kaso|kayu|triplek|multiplek|bekisting|\bpaku\b|plywood|\bmb\b/i' => '/bekisting/i',
        '/batu ?kali/i' => '/batu kali/i',
        '/u-? ?ditch|udicth/i' => '/u ?dit?ch|udicth/i',
        '/\brcp\b|pipa/i' => '/pipa/i',
        '/sirdam|kelas a\b/i' => '/sirdam/i',
        '/makadam|kelas b\b/i' => '/makadam/i',
        '/abu batu/i' => '/abu batu/i',
        '/paving/i' => '/paving/i',
        '/kanstin/i' => '/kanstin/i',
        '/wire ?mesh|ware ?mesh|\bm6\b/i' => '/mesh/i',
        '/geote[xk]/i' => '/geote[xk]/i',
        '/mob(ilisasi|demob)|langsir|trailer|self ?loader/i' => '/mob/i',
        '/bedeng|listrik|air kerja|token/i' => '/bedeng/i',
        '/ukur|survey|dokumentasi|as ?buil/i' => '/pengukuran/i',
        '/exca|excavator|cut|fill|urug|galian/i' => '/cut and fill|cut fill|galian/i',
        '/pemadatan|stamper|vibro|compact/i' => '/pemadatan/i',
        '/\bcbr\b/i' => '/cbr/i',
        '/pasir/i' => '/pasir/i',
        '/semen/i' => '/batu kali|beton jepit/i',
    ];

    private const BULAN = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'agt' => 8, 'sep' => 9, 'okt' => 10, 'nov' => 11, 'des' => 12];

    /**
     * Tag Proyek & BOQ di sheet monitoring Infra: kas[NO ID] dan bca["Y-m-d|jumlah"] = [proyek, boq]. Disimpan 30 menit;
     * sheet tidak terbaca → kosong (rekomendasi lain tetap jalan).
     */
    public static function tagSheet(bool $segarkan = false): array
    {
        if ($segarkan) {
            Cache::forget('pilah-tag-sheet');
        }
        if ($ada = Cache::get('pilah-tag-sheet')) {
            return $ada;
        }
        try {
            $a = "'Transaksi Kas'!A2:K";
            $b = "'Mutasi PT BCA'!A2:G";
            $v = GoogleSheets::wajib()->nilai(config('mnp.sheet_infra'), [$a, $b]);
        } catch (\Throwable $e) {
            report($e);

            return ['kas' => [], 'bca' => [], 'galat' => $e->getMessage()];
        }
        $hasil = ['kas' => [], 'bca' => []];
        foreach ($v[$a] ?? [] as $x) {
            if (($no = trim((string) ($x[7] ?? ''))) !== '' && (trim((string) ($x[9] ?? '')) !== '' || trim((string) ($x[10] ?? '')) !== '')) {
                $hasil['kas'][$no] = [trim((string) ($x[9] ?? '')), trim((string) ($x[10] ?? ''))];
            }
        }
        foreach ($v[$b] ?? [] as $x) {
            $tgl = self::tanggal((string) ($x[0] ?? ''));
            $rp = (int) preg_replace('/\D/', '', (string) ($x[4] ?? ''));
            if ($tgl && $rp) {
                $hasil['bca'][$tgl.'|'.$rp] = [trim((string) ($x[5] ?? '')), trim((string) ($x[6] ?? ''))];
            }
        }
        Cache::put('pilah-tag-sheet', $hasil, now()->addMinutes(30));

        return $hasil;
    }

    /** "13-Agu-26" / "18-Agu-2026" → 2026-08-13. */
    private static function tanggal(string $v): ?string
    {
        if (! preg_match('/^(\d{1,2})-(\w{3})\w*-(\d{2,4})$/u', trim($v), $m) || ! isset(self::BULAN[strtolower($m[2])])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3], self::BULAN[strtolower($m[2])], $m[1]);
    }

    /** Tahap dari teks (tag sheet / keterangan) menurut KATA_TAHAP: [id, kata yang cocok] atau null. */
    public static function tahapDariTeks(?string $teks, array $tahapPerNama): ?array
    {
        foreach (self::KATA_TAHAP as $pola => $nama) {
            if ($teks && preg_match($pola, $teks, $m) && isset($tahapPerNama[$nama])) {
                return [$tahapPerNama[$nama], $m[0]];
            }
        }

        return null;
    }

    /** Kata penting keterangan (untuk mencari transaksi serupa): huruf ≥ 3, tanpa angka & kata umum. */
    public static function kata(?string $teks): array
    {
        $umum = ['dan', 'untuk', 'infra', 'proyek', 'trsf', 'banking', 'e-banking', 'hpp', 'biaya', 'pm', 'pik', 'pantai', 'mutiara', 'regatta', 'marina', 'pcs', 'btg', 'bks', 'sak', 'ftscy', 'ftfva', 'ws95051'];

        return collect(preg_split('/[^\pL]+/u', mb_strtolower((string) $teks)))->filter(fn ($k) => mb_strlen($k) >= 3 && ! in_array($k, $umum, true))->unique()->values()->all();
    }

    /**
     * Semua baris yang dipilah untuk tahap-tahap ini sejak $dari: Kas (tahap sekarang / Kode GL / pernah dipilah) dan
     * BCA DB (sudah bertahap di sini, atau belum dicek).
     *
     * @return Collection<int, object>
     */
    public static function baris(array $ids, string $dari): Collection
    {
        $semuaTahap = ProyekTahap::get(['id', 'nama', 'tipe', 'pos_overhead'])->keyBy('id');
        $perNama = $semuaTahap->mapWithKeys(fn ($t) => [$t->nama => $t->id])->all();
        $tag = self::tagSheet();
        $boq = TransaksiBoq::with('item')->get()->keyBy(fn ($x) => $x->sumber.'|'.$x->kunci);
        $ids = array_flip($ids);

        $kas = DaftarProyek::queryKas(true)
            ->selectRaw('b.no_id, b.tanggal, b.nominal, b.pic, b.keterangan, k.kode_asli, c.kode cc, k.ref, a.nama akun, a.kelompok kel, al.proyek_tahap_id alokasi, al.abaikan, al.sumber al_sumber, '.DaftarProyek::SQL_KUNCI.' kunci')
            ->where('b.tanggal', '>=', $dari)->orderBy('b.tanggal')->orderBy('b.no_id')->get()
            ->map(function ($r) use ($semuaTahap, $tag, $boq) {
                $tgl = substr((string) $r->tanggal, 0, 10);
                $gl = DaftarProyek::tahapKas($r->cc, $r->ref, $r->akun, $tgl);
                $tahap = $r->abaikan ? null : ($r->alokasi ?: $gl);
                $t = $tahap ? $semuaTahap[$tahap] ?? null : null;
                $hitung = $r->abaikan ? [false, 'bukan biaya (dipilah)']
                    : (! $tahap ? [false, 'tanpa tahap'] : (Overhead::diabaikan($r->kel, $r->akun, $t?->tipe, $r->keterangan)
                        ? [false, 'akun '.$r->akun.' — dari sumber lain'.($t?->tipe === 'infra' ? ' (alat berat / sparepart)' : ' (Ritasi / Data BBM)')]
                        : [true, $t?->pos_overhead ? 'biaya umum (dibagi Alokasi Biaya Umum)' : 'dihitung']));

                return (object) ['sumber' => 'kas', 'kunci' => $r->kunci, 'tanggal' => $tgl, 'nominal' => (float) $r->nominal, 'keterangan' => (string) $r->keterangan,
                    'catatan' => null, 'pic' => $r->pic, 'akun' => $r->akun, 'kode' => $r->kode_asli, 'no_id' => $r->no_id, 'tahap_gl' => $gl, 'tahap_id' => $tahap,
                    'abaikan' => (bool) $r->abaikan, 'manual' => $r->al_sumber === 'pilah', 'dihitung' => $hitung[0], 'status' => $hitung[1],
                    'tag' => $tag['kas'][(string) $r->no_id] ?? null, 'boq' => $boq['kas|'.$r->kunci]->item ?? null];
            })
            ->filter(fn ($x) => $x->manual || isset($ids[$x->tahap_id ?? $x->tahap_gl]));

        $bca = TandaMutasiPt::baris()->filter(fn ($b) => $b->m->dbcr === 'DB' && $b->m->tanggal->toDateString() >= $dari
            && (($b->tanda?->proyek_tahap_id && isset($ids[$b->tanda->proyek_tahap_id])) || $b->status === 'belum'))
            ->map(fn ($b) => (object) ['sumber' => 'bca', 'kunci' => $b->kunci, 'tanggal' => $b->m->tanggal->toDateString(), 'nominal' => (float) $b->m->jumlah,
                'keterangan' => trim(preg_replace('/\s+/', ' ', preg_replace('/^TRSF E-BANKING (DB )?\d{4}\/FT\w+\/WS\d+\s*/', '', (string) $b->m->keterangan))),
                'catatan' => $b->tanda?->catatan, 'pic' => null, 'akun' => 'Rekening PT BCA', 'kode' => null, 'no_id' => null, 'tahap_gl' => null,
                'tahap_id' => $b->status === 'dihitung' ? $b->tanda?->proyek_tahap_id : null, 'abaikan' => $b->status === 'tidak', 'manual' => (bool) $b->tanda?->status,
                'dihitung' => $b->status === 'dihitung' && $b->tanda?->proyek_tahap_id, 'status' => TandaMutasiPt::STATUS[$b->status].($b->alasan && $b->alasan !== 'ditandai manual' ? ' · '.$b->alasan : ''),
                'tag' => $tag['bca'][$b->m->tanggal->toDateString().'|'.(int) round($b->m->jumlah)] ?? null, 'boq' => $boq['bca|'.$b->kunci]->item ?? null]);

        $baris = $kas->concat($bca)->sortBy(fn ($x) => $x->tanggal.($x->sumber === 'bca' ? '1' : '0'))->values();
        self::rekomendasi($baris, $perNama);

        return $baris;
    }

    /** Isi rek_tahap [id, dasar] dan rek_boq [BoqItem, dasar] tiap baris. */
    private static function rekomendasi(Collection $baris, array $perNama): void
    {
        $items = BoqItem::orderBy('urutan')->get()->groupBy('proyek_tahap_id');
        // Pembelajaran: transaksi yang sudah diberi kode BOQ → kata keterangannya.
        $contoh = $baris->filter(fn ($x) => $x->boq)->map(fn ($x) => ['kata' => self::kata($x->keterangan.' '.$x->catatan), 'item' => $x->boq, 'tahap' => $x->boq->proyek_tahap_id])->values();
        $contohTahap = $baris->filter(fn ($x) => $x->manual && $x->tahap_id)->map(fn ($x) => ['kata' => self::kata($x->keterangan.' '.$x->catatan), 'tahap' => $x->tahap_id])->values();
        $mirip = function (array $kata, Collection $daftar) {
            $terbaik = null;
            foreach ($daftar as $c) {
                $sama = count(array_intersect($kata, $c['kata']));
                $skor = $sama / max(1, count(array_unique(array_merge($kata, $c['kata']))));
                if ($sama >= 2 && $skor >= 0.5 && $skor > ($terbaik[1] ?? 0)) {
                    $terbaik = [$c, $skor];
                }
            }

            return $terbaik[0] ?? null;
        };

        foreach ($baris as $x) {
            $teks = $x->keterangan.' '.$x->catatan;
            $kata = self::kata($teks);
            // --- tahap ---
            $x->rek_tahap = null;
            if ($x->tag && $x->tag[0] !== '' && ($t = self::tahapDariTeks($x->tag[0], $perNama))) {
                $x->rek_tahap = [$t[0], 'tag sheet Infra "'.$x->tag[0].'"'];
            } elseif (! $x->tag && $x->catatan && ($t = self::tahapDariTeks($x->catatan, $perNama))) {
                $x->rek_tahap = [$t[0], 'keterangan tambahan "'.$t[1].'"'];
            } elseif (($c = $mirip($kata, $contohTahap)) && $c['tahap'] !== $x->tahap_id) {
                $x->rek_tahap = [$c['tahap'], 'mirip transaksi yang sudah dipilah'];
            } elseif ($t = self::tahapDariTeks($x->keterangan, $perNama)) {
                $x->rek_tahap = [$t[0], 'kata "'.$t[1].'" di keterangan'];
            } elseif ($x->tag && $x->tag[0] !== '' && preg_match('/refund|ignore/i', $x->tag[0])) {
                $x->rek_tahap = ['abaikan', 'tag sheet Infra "'.$x->tag[0].'"'];
            }

            // --- BOQ (item dari tahap terpilih, atau tahap rekomendasi) ---
            $x->rek_boq = null;
            $tid = $x->tahap_id ?: (is_int($x->rek_tahap[0] ?? null) ? $x->rek_tahap[0] : null);
            $daftar = $items[$tid] ?? collect();
            if ($daftar->isEmpty()) {
                continue;
            }
            if ($x->tag && $x->tag[1] !== '') {
                $kode = array_map('trim', preg_split('/[;,\/]+/', $x->tag[1]));
                if ($it = $daftar->first(fn ($i) => in_array($i->kode, $kode, true))) {
                    $x->rek_boq = [$it, 'tag sheet Infra "'.$x->tag[1].'"'];

                    continue;
                }
            }
            if (($c = $mirip($kata, $contoh->where('tahap', $tid))) && $c['item']->id !== $x->boq?->id) {
                $x->rek_boq = [$c['item'], 'mirip transaksi yang sudah diberi kode'];

                continue;
            }
            foreach (self::KATA_BOQ as $pola => $uraian) {
                if (preg_match($pola, $teks, $m)) {
                    $cocok = $daftar->filter(fn ($i) => preg_match($uraian, $i->uraian));
                    if ($cocok->isNotEmpty()) {
                        // beberapa item cocok (mis. Bekisting jalan & tanggul) → modal terbesar, sisanya disebut
                        $cocok = $cocok->sortByDesc(fn ($i) => $i->modal() ?: $i->nilai())->values();
                        $x->rek_boq = [$cocok[0], 'kata "'.$m[0].'"'.($cocok->count() > 1 ? ' (atau '.$cocok->slice(1)->pluck('kode')->implode(', ').')' : '')];
                        break;
                    }
                }
            }
        }
    }

    /**
     * Biaya dihitung per item BOQ (Kas & BCA yang diberi kode BOQ, dihitung, dan tahapnya = tahap item).
     *
     * @return array<int, float>
     */
    public static function biayaPerItem(ProyekTahap $t): array
    {
        $hasil = [];
        foreach (self::baris([$t->id], '2000-01-01') as $x) {
            if ($x->boq && $x->dihitung && $x->tahap_id === $t->id && $x->boq->proyek_tahap_id === $t->id) {
                $hasil[$x->boq->id] = ($hasil[$x->boq->id] ?? 0) + $x->nominal;
            }
        }

        return $hasil;
    }

    /** Simpan tahap transaksi Kas: tahap / abaikan (bukan biaya) / kosong (kembali ke Kode GL). */
    public static function simpanKas(string $kunci, ?int $tahap, bool $abaikan): void
    {
        if (! $tahap && ! $abaikan) {
            DB::table('kas_alokasi')->where('kunci', $kunci)->where('sumber', 'pilah')->delete();

            return;
        }
        DB::table('kas_alokasi')->updateOrInsert(['kunci' => $kunci], ['proyek_tahap_id' => $abaikan ? null : $tahap, 'abaikan' => $abaikan, 'sumber' => 'pilah', 'updated_at' => now(), 'created_at' => now()]);
    }
}
