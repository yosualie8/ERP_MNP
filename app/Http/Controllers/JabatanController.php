<?php

namespace App\Http\Controllers;

use App\Models\JabatanMenu;
use App\Models\Karyawan;
use App\Models\KasRiwayat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Master Jabatan: daftar jabatan karyawan (tabel jabatan_menu — sekaligus dasar Hak Akses Jabatan). Jabatan di Data Karyawan
 * harus dipilih dari sini. Ganti nama ikut memperbarui jabatan karyawan; hapus hanya bila tidak dipakai karyawan mana pun.
 */
class JabatanController extends Controller
{
    public function index(): View
    {
        $pakai = Karyawan::whereNotNull('jabatan')->selectRaw("jabatan, sum(status = 'aktif') aktif, count(*) semua")->groupBy('jabatan')->get()->keyBy('jabatan');

        return view('jabatan.index', [
            'jabatan' => JabatanMenu::orderBy('jabatan')->get()->map(fn ($j) => (object) [
                'id' => $j->id, 'nama' => $j->jabatan, 'menu' => count($j->menu ?? []), 'um' => $j->um_harian,
                'aktif' => (int) ($pakai[$j->jabatan]->aktif ?? 0), 'semua' => (int) ($pakai[$j->jabatan]->semua ?? 0),
            ]),
            // Jabatan yang dipakai karyawan tetapi belum ada di master (mis. data lama) — perlu ditambahkan / diganti.
            'liar' => $pakai->keys()->diff(JabatanMenu::pluck('jabatan'))->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $nama = $this->nama($request);
        $um = (int) preg_replace('/\D/', '', (string) $request->input('um_harian')) ?: null;
        $j = JabatanMenu::create(['jabatan' => $nama, 'menu' => JabatanMenu::BAWAAN, 'um_harian' => $um, 'user_id' => $request->user()->id]);
        $this->catat($request, 'jabatan-tambah', "Jabatan baru: {$nama}", $j->toArray());

        return back()->with('success', "Jabatan {$nama} ditambahkan (menu awal: Absen — atur di Hak Akses Jabatan).");
    }

    public function update(Request $request, JabatanMenu $jabatan): RedirectResponse
    {
        $lama = $jabatan->jabatan;
        $nama = $this->nama($request, $jabatan);
        if ($nama === $lama) {
            return back();
        }
        $n = DB::transaction(function () use ($jabatan, $lama, $nama, $request) {
            $jabatan->update(['jabatan' => $nama, 'user_id' => $request->user()->id]);

            return Karyawan::where('jabatan', $lama)->update(['jabatan' => $nama]);
        });
        $this->catat($request, 'jabatan-ubah', "Jabatan {$lama} → {$nama} ({$n} karyawan ikut diubah)", ['lama' => $lama, 'baru' => $nama, 'karyawan' => $n]);

        return back()->with('success', "Jabatan {$lama} diganti menjadi {$nama}; {$n} karyawan ikut diperbarui.");
    }

    /** Tarif dasar UM per hari semua jabatan sekaligus (kosong = tidak dibayar UM harian, mis. gaji bulanan). */
    public function simpanUm(Request $request): RedirectResponse
    {
        $baru = collect((array) $request->input('um', []))->map(fn ($v) => (int) preg_replace('/\D/', '', (string) $v) ?: null);
        $ubah = [];
        foreach (JabatanMenu::whereIn('id', $baru->keys())->get() as $j) {
            $v = $baru[$j->id];
            if ($v !== $j->um_harian) {
                $ubah[$j->jabatan] = [$j->um_harian, $v];
                $j->update(['um_harian' => $v, 'user_id' => $request->user()->id]);
            }
        }
        if ($ubah) {
            $this->catat($request, 'jabatan-um', 'Tarif UM per hari diubah: '.collect($ubah)->map(fn ($x, $j) => $j.' '.($x[0] ? rp($x[0]) : '–').' → '.($x[1] ? rp($x[1]) : '–'))->implode('; '), $ubah);
        }

        return back()->with('success', $ubah ? count($ubah).' tarif UM per hari disimpan.' : 'Tidak ada tarif yang berubah.');
    }

    public function hapus(Request $request, JabatanMenu $jabatan): RedirectResponse
    {
        $n = Karyawan::where('jabatan', $jabatan->jabatan)->count();
        if ($n) {
            return back()->with('error', "Jabatan {$jabatan->jabatan} masih dipakai {$n} karyawan — ganti jabatan mereka dulu, atau ganti nama jabatan ini.");
        }
        $this->catat($request, 'jabatan-hapus', "Jabatan dihapus: {$jabatan->jabatan}", $jabatan->toArray());
        $jabatan->delete();

        return back()->with('success', "Jabatan {$jabatan->jabatan} dihapus.");
    }

    /** Nama jabatan dirapikan (spasi); tidak boleh kembar walau beda huruf besar/kecil. */
    private function nama(Request $request, ?JabatanMenu $kecuali = null): string
    {
        $nama = trim(preg_replace('/\s+/', ' ', (string) $request->input('nama')));
        $request->merge(['nama' => $nama]);
        $request->validate(['nama' => ['required', 'string', 'max:100']], ['nama.required' => 'Nama jabatan belum diisi.']);
        $kembar = JabatanMenu::whereRaw('LOWER(jabatan) = ?', [mb_strtolower($nama)])->when($kecuali, fn ($q) => $q->whereKeyNot($kecuali->id))->value('jabatan');
        if ($kembar) {
            throw \Illuminate\Validation\ValidationException::withMessages(['nama' => "Jabatan \"{$kembar}\" sudah ada."]);
        }

        return $nama;
    }

    private function catat(Request $request, string $aksi, string $ringkasan, array $isi): void
    {
        KasRiwayat::create(['aksi' => $aksi, 'lembar' => 'Karyawan', 'baris_awal' => 0, 'baris_akhir' => 0, 'ringkasan' => mb_strimwidth($ringkasan, 0, 490, '…'),
            'isi' => $isi, 'user_id' => $request->user()->id]);
    }
}
