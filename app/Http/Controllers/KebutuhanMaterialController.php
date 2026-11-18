<?php

namespace App\Http\Controllers;

use App\Models\KasRiwayat;
use App\Models\KebutuhanMaterial;
use App\Models\KebutuhanMaterialRekaman;
use App\Models\ProyekTahap;
use App\Support\Ai\OpenAi;
use App\Support\SaranMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kebutuhan Material (owner 18 Nov 2026, menu Proyek). Owner berdiskusi dengan orang lapangan; setiap material yang sudah fix ditekan 🎤,
 * diucapkan ("semen tiga puluh sak untuk pondasi pos jaga"), lalu ⏹. Suara → teks (OpenAI, kosakata material) → AI memecah jadi item
 * (nama, qty, satuan, keterangan) → saran harga & satuan dari riwayat transaksi (App\Support\SaranMaterial). Owner mengecek harga;
 * harga yang dipastikan (dicek) menjadi riwayat untuk saran berikutnya.
 * Sinyal di proyek jelek: rekaman disimpan dulu di perangkat (IndexedDB, public/js/idb-keyval.js) dan dikirim otomatis saat sinyal ada.
 * uid dari perangkat membuat kiriman ulang tidak menggandakan item. Audio disimpan di storage/app/kebutuhan-material sebagai bukti.
 */
class KebutuhanMaterialController extends Controller
{
    public function index(Request $request): View
    {
        $dipakai = KebutuhanMaterial::whereNotNull('proyek_tahap_id')->distinct()->pluck('proyek_tahap_id');
        $tahap = ProyekTahap::query()->where(fn ($q) => $q->whereNotIn('status', ['batal', 'selesai'])->orWhereIn('id', $dipakai))
            ->orderByRaw("CASE WHEN status = 'progress' THEN 0 ELSE 1 END")->orderBy('nama')->get(['id', 'nama', 'status']);
        $pilih = $request->integer('tahap') ?: null;
        if ($pilih && ! $tahap->contains('id', $pilih)) {
            $pilih = null;
        }

        return view('kebutuhan-material.index', [
            'tahap' => $tahap->map(fn ($t) => ['id' => $t->id, 'nama' => $t->nama])->values(),
            'pilih' => $pilih,
            'data' => $pilih ? $this->isi($pilih) : null,
        ]);
    }

    /** Item & rekaman gagal satu tahap (JSON untuk halaman). */
    public function data(Request $request): JsonResponse
    {
        $id = $request->integer('tahap');
        abort_unless(ProyekTahap::whereKey($id)->exists(), 404);

        return response()->json($this->isi($id));
    }

    /** Terima satu rekaman suara (atau teks ketikan) → simpan → transkripsi → pecah jadi item. Aman dikirim ulang (uid). */
    public function rekaman(Request $request): JsonResponse
    {
        @set_time_limit(150);
        ignore_user_abort(true); // sinyal putus di tengah jalan: tetap selesaikan; kiriman ulang dengan uid sama mengambil hasilnya
        $d = $request->validate([
            'uid' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'tahap' => ['required', 'integer', 'exists:proyek_tahap,id'],
            'audio' => ['nullable', 'file', 'max:20480'],
            'teks' => ['nullable', 'string', 'max:2000'],
            'direkam_pada' => ['nullable', 'date'],
            'durasi' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ], ['audio.max' => 'Rekaman terlalu panjang (maks. 20 MB).']);
        if (! $request->hasFile('audio') && blank($d['teks'] ?? null)) {
            return response()->json(['galat' => 'Rekaman / teks kosong.'], 422);
        }

        $r = KebutuhanMaterialRekaman::firstWhere('uid', $d['uid']);
        if ($r && ($r->status === 'selesai' || ($r->status === 'baru' && $r->updated_at->gt(now()->subMinutes(3))))) {
            return response()->json(['rekaman' => $this->rekamanJson($r), 'tersimpan' => true]); // sudah / sedang diproses kiriman sebelumnya
        }
        if (! $r) {
            $direkam = isset($d['direkam_pada']) ? Carbon::parse($d['direkam_pada'])->setTimezone(config('app.timezone')) : now();
            $r = KebutuhanMaterialRekaman::create(['uid' => $d['uid'], 'proyek_tahap_id' => $d['tahap'], 'sumber' => $request->hasFile('audio') ? 'suara' : 'ketik',
                'durasi' => $d['durasi'] ?? null, 'transkrip' => $request->hasFile('audio') ? null : trim($d['teks']), 'direkam_pada' => $direkam->min(now()),
                'user_id' => $request->user()->id]);
            if ($request->hasFile('audio')) {
                $r->update(['audio' => $this->simpanAudio($request->file('audio'), $r)]);
            }
        }
        $this->proses($r, $request->user()->id);

        return response()->json(['rekaman' => $this->rekamanJson($r->fresh()), 'tersimpan' => true]);
    }

    /** Proses ulang rekaman yang gagal (mis. API AI sedang bermasalah). */
    public function ulang(Request $request, KebutuhanMaterialRekaman $rekaman): JsonResponse
    {
        @set_time_limit(150);
        abort_if($rekaman->status === 'selesai', 422, 'Rekaman ini sudah diproses.');
        $this->proses($rekaman, $request->user()->id);

        return response()->json(['rekaman' => $this->rekamanJson($rekaman->fresh())]);
    }

    public function hapusRekaman(Request $request, KebutuhanMaterialRekaman $rekaman): JsonResponse
    {
        abort_if($rekaman->item()->exists(), 422, 'Rekaman ini sudah punya item — hapus itemnya saja.');
        if ($rekaman->audio) {
            Storage::delete($rekaman->audio);
        }
        $this->catat($request, 'material-hapus', 'Hapus rekaman gagal: '.mb_strimwidth((string) $rekaman->transkrip, 0, 120, '…'), ['rekaman' => $rekaman->toArray()]);
        $rekaman->delete();

        return response()->json(['ok' => true]);
    }

    public function audio(KebutuhanMaterialRekaman $rekaman): BinaryFileResponse
    {
        abort_unless($rekaman->audio && Storage::exists($rekaman->audio), 404);

        return response()->file(Storage::path($rekaman->audio), ['Cache-Control' => 'private, max-age=86400']);
    }

    /** Tambah item manual (tanpa suara). */
    public function tambah(Request $request): JsonResponse
    {
        $d = $this->bacaItem($request, true);
        $s = SaranMaterial::untuk($d['nama_item'], $d['satuan'] ?? null);
        $k = KebutuhanMaterial::create([...$d, 'proyek_tahap_id' => $request->integer('tahap'), 'tanggal' => now()->toDateString(),
            'satuan' => $d['satuan'] ?? $s['satuan'], 'harga_saran' => $s['harga'], 'dasar_harga' => $s['dasar'], 'dicek' => isset($d['harga']),
            'urut' => (int) KebutuhanMaterial::where('proyek_tahap_id', $request->integer('tahap'))->max('urut') + 1, 'user_id' => $request->user()->id]);

        return response()->json(['item' => $this->itemJson($k)]);
    }

    public function ubah(Request $request, KebutuhanMaterial $item): JsonResponse
    {
        $d = $this->bacaItem($request, false);
        $namaBerubah = array_key_exists('nama_item', $d) && $d['nama_item'] !== $item->nama_item
            || array_key_exists('satuan', $d) && SaranMaterial::satuanBaku($d['satuan']) !== SaranMaterial::satuanBaku($item->satuan);
        $item->fill($d);
        if ($namaBerubah) {
            $s = SaranMaterial::untuk($item->nama_item, $item->satuan, $item->id);
            $item->fill(['harga_saran' => $s['harga'], 'dasar_harga' => $s['dasar'], 'satuan' => $item->satuan ?? $s['satuan']]);
        }
        if (array_key_exists('harga', $d) && ! array_key_exists('dicek', $d)) {
            $item->dicek = $d['harga'] !== null; // mengisi harga = memastikan harga
        }
        $item->save();

        return response()->json(['item' => $this->itemJson($item)]);
    }

    public function hapus(Request $request, KebutuhanMaterial $item): JsonResponse
    {
        $this->catat($request, 'material-hapus', 'Hapus kebutuhan material: '.$item->nama_item.' '.$item->qty.' '.$item->satuan, ['item' => $item->toArray()]);
        $item->delete();

        return response()->json(['ok' => true]);
    }

    /** Bukti saran harga (transaksi riwayat yang cocok) untuk dialog. */
    public function saran(Request $request): JsonResponse
    {
        $d = $request->validate(['nama' => ['required', 'string', 'max:200'], 'satuan' => ['nullable', 'string', 'max:20'], 'kecuali' => ['nullable', 'integer']]);
        $s = SaranMaterial::untuk($d['nama'], $d['satuan'] ?? null, $d['kecuali'] ?? null);
        $s['bukti'] = array_map(fn ($b) => [...$b, 'tanggal' => SaranMaterial::tgl($b['tanggal'])], $s['bukti']);

        return response()->json($s);
    }

    /** Token CSRF baru untuk halaman yang terbuka lama / dibuka dari cache saat offline. */
    public function token(): JsonResponse
    {
        return response()->json(['token' => csrf_token()]);
    }

    // ===================================================================================================

    private function proses(KebutuhanMaterialRekaman $r, int $userId): void
    {
        $r->update(['status' => 'baru', 'galat' => null]);
        try {
            if ($r->sumber === 'suara' && blank($r->transkrip)) {
                $file = new UploadedFile(Storage::path($r->audio), basename($r->audio), null, null, true);
                $r->update(['transkrip' => OpenAi::transkripsi($file, $this->kosakata())]);
            }
            $hasil = blank($r->transkrip) ? [] : $this->pecah($r->transkrip);
            DB::transaction(function () use ($r, $hasil, $userId) {
                $urut = (int) KebutuhanMaterial::where('proyek_tahap_id', $r->proyek_tahap_id)->max('urut');
                foreach ($hasil as $h) {
                    $s = SaranMaterial::untuk($h['nama_item'], $h['satuan']);
                    KebutuhanMaterial::create(['proyek_tahap_id' => $r->proyek_tahap_id, 'rekaman_id' => $r->id, 'tanggal' => ($r->direkam_pada ?? now())->toDateString(),
                        'nama_item' => mb_substr($h['nama_item'], 0, 200), 'qty' => $h['qty'], 'satuan' => mb_substr((string) ($h['satuan'] ? SaranMaterial::satuanBaku($h['satuan']) : $s['satuan']), 0, 20) ?: null,
                        'harga_saran' => $s['harga'], 'dasar_harga' => $s['dasar'], 'keterangan' => mb_substr($h['keterangan'], 0, 300) ?: null,
                        'urut' => ++$urut, 'user_id' => $userId]);
                }
                $r->update(['status' => $hasil ? 'selesai' : 'gagal', 'galat' => $hasil ? null : 'Tidak ada material yang dikenali dari ucapan ini.']);
            });
        } catch (\Throwable $e) {
            report($e);
            $r->update(['status' => 'gagal', 'galat' => mb_strimwidth($e->getMessage(), 0, 490, '…')]);
        }
    }

    /** Ucapan → item material (structured output). @return array<int, array{nama_item: string, qty: ?float, satuan: ?string, keterangan: string}> */
    private function pecah(string $teks): array
    {
        $nama = KebutuhanMaterial::query()->selectRaw('nama_item, COUNT(*) n')->groupBy('nama_item')->orderByDesc('n')->limit(150)->pluck('nama_item')->implode('; ');
        $instruksi = 'Kamu memecah ucapan owner kontraktor PT MNP (diskusi kebutuhan material proyek konstruksi dengan orang lapangan) menjadi daftar item material. '
            .'Satu ucapan bisa berisi satu atau beberapa material. Untuk tiap material: '
            .'nama_item = nama barang baku yang singkat dalam Bahasa Indonesia beserta spesifikasi penting yang diucapkan (ukuran, mutu, merk), huruf besar di awal kata, '
            .'mis. "Semen 50 kg", "Besi Beton 13 mm", "Pasir Beton", "Batu Split 2/3", "Triplek 12 mm", "Pipa PVC 4 inch", "Readymix K-300". '
            .'qty = jumlah sebagai angka (kata bilangan diubah: "tiga puluh" → 30, "setengah" → 0.5, "satu setengah" → 1.5); null bila tidak disebut. '
            .'satuan = satuan baku huruf kecil: sak, batang, lembar, m3, m2, meter, kg, ton, rit, pcs, roll, dus, liter, pail, kaleng, set, unit, ls ("kubik" → m3, "lonjor" → batang); null bila tidak disebut. '
            .'keterangan = untuk pekerjaan / lokasi / waktu / catatan lain yang diucapkan (mis. "pondasi pos jaga", "kirim minggu depan"); string kosong bila tidak ada. '
            .'Jangan mengarang item atau angka yang tidak diucapkan. Bila ucapan sama sekali bukan permintaan material, kembalikan daftar kosong.'
            .($nama ? ' Nama item yang sudah pernah dipakai (pakai ejaan yang sama bila barangnya sama): '.$nama.'.' : '');
        $skema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['item'], 'properties' => ['item' => ['type' => 'array', 'items' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['nama_item', 'qty', 'satuan', 'keterangan'],
            'properties' => ['nama_item' => ['type' => 'string'], 'qty' => ['type' => ['number', 'null']], 'satuan' => ['type' => ['string', 'null']], 'keterangan' => ['type' => 'string']],
        ]]]];
        $hasil = OpenAi::json($instruksi, $teks, 'kebutuhan_material', $skema)['item'] ?? [];

        return array_values(array_filter(array_map(fn ($h) => [
            'nama_item' => trim((string) ($h['nama_item'] ?? '')), 'qty' => isset($h['qty']) && is_numeric($h['qty']) && $h['qty'] > 0 ? (float) $h['qty'] : null,
            'satuan' => filled($h['satuan'] ?? null) ? trim($h['satuan']) : null, 'keterangan' => trim((string) ($h['keterangan'] ?? '')),
        ], $hasil), fn ($h) => $h['nama_item'] !== ''));
    }

    private function kosakata(): string
    {
        $nama = KebutuhanMaterial::query()->selectRaw('nama_item, COUNT(*) n')->groupBy('nama_item')->orderByDesc('n')->limit(40)->pluck('nama_item')->implode(', ');

        return mb_substr('Kebutuhan material proyek konstruksi PT MNP. Istilah: semen, pasir beton, pasir urug, batu split, batu belah, sirtu, sirdam, base course, '
            .'besi beton, besi ulir, wiremesh, bendrat, bekisting, triplek, multiplek, kayu kaso, reng, paku, readymix K-225, K-300, bata ringan, hebel, keramik, '
            .'cat, pipa PVC, sak, batang, lonjor, lembar, kubik, rit. '.$nama, 0, 900);
    }

    private function simpanAudio(UploadedFile $f, KebutuhanMaterialRekaman $r): string
    {
        $ext = strtolower($f->getClientOriginalExtension() ?: 'webm');
        $ext = in_array($ext, ['webm', 'mp4', 'm4a', 'ogg', 'wav', 'mp3', 'aac'], true) ? $ext : 'webm';

        return $f->storeAs('kebutuhan-material/'.now()->format('Y-m'), $r->id.'-'.$r->uid.'.'.$ext);
    }

    private function bacaItem(Request $request, bool $baru): array
    {
        $wajib = $baru ? 'required' : 'sometimes';
        $d = $request->validate([
            'tahap' => [$baru ? 'required' : 'nullable', 'integer', 'exists:proyek_tahap,id'],
            'nama_item' => [$wajib, 'string', 'max:200'],
            'qty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'satuan' => ['sometimes', 'nullable', 'string', 'max:20'],
            'harga' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'keterangan' => ['sometimes', 'nullable', 'string', 'max:300'],
            'dicek' => ['sometimes', 'boolean'],
        ]);
        unset($d['tahap']);
        if (array_key_exists('satuan', $d)) {
            $d['satuan'] = SaranMaterial::satuanBaku($d['satuan']);
        }
        foreach (['nama_item', 'keterangan'] as $k) {
            if (array_key_exists($k, $d)) {
                $d[$k] = trim((string) $d[$k]) ?: null;
            }
        }

        return $d;
    }

    private function isi(int $tahapId): array
    {
        $item = KebutuhanMaterial::with('rekaman:id,transkrip,audio,sumber')->where('proyek_tahap_id', $tahapId)
            ->orderBy('tanggal')->orderBy('urut')->orderBy('id')->get();
        $gagal = KebutuhanMaterialRekaman::where('proyek_tahap_id', $tahapId)->where('status', '!=', 'selesai')->orderBy('id')->get();

        return ['item' => $item->map(fn ($k) => $this->itemJson($k))->values(), 'gagal' => $gagal->map(fn ($r) => $this->rekamanJson($r))->values()];
    }

    private function itemJson(KebutuhanMaterial $k): array
    {
        $r = $k->relationLoaded('rekaman') ? $k->rekaman : ($k->rekaman_id ? $k->rekaman()->first(['id', 'transkrip', 'audio', 'sumber']) : null);

        return ['id' => $k->id, 'tanggal' => $k->tanggal?->toDateString(), 'nama' => $k->nama_item, 'qty' => $k->qty, 'satuan' => $k->satuan,
            'harga' => $k->harga, 'saran' => $k->harga_saran, 'dasar' => $k->dasar_harga, 'ket' => $k->keterangan, 'dicek' => $k->dicek,
            'rekaman' => $r?->id, 'transkrip' => $r?->transkrip, 'audio' => (bool) $r?->audio];
    }

    private function rekamanJson(KebutuhanMaterialRekaman $r): array
    {
        return ['id' => $r->id, 'uid' => $r->uid, 'status' => $r->status, 'galat' => $r->galat, 'transkrip' => $r->transkrip, 'audio' => (bool) $r->audio,
            'direkam' => $r->direkam_pada?->format('d/m H:i'), 'item' => $r->status === 'selesai' ? $r->item()->pluck('id') : []];
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Material', 'baris_awal' => 0, 'baris_akhir' => 0,
            'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'), 'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
