<?php

namespace App\Http\Controllers;

use App\Models\AbsenKoreksi;
use App\Models\Absensi;
use App\Models\Karyawan;
use App\Models\KasRiwayat;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi jam absen oleh admin (owner 9 Nov 2026 — absensi masih banyak bolong, Valen boleh mengedit jam masuk / pulang / lembur).
 * Absen asli tidak diubah; koreksi disimpan di absen_koreksi dan ditandai ✎ (asli vs diedit, oleh siapa) di semua tampilan absen.
 * Hak: menu 'absen-ubah-jam' (Hak Akses Jabatan, bawaan Admin Mobil).
 */
class AbsenKoreksiController extends Controller
{
    public function simpan(Request $request): JsonResponse
    {
        $jam = ['nullable', 'date_format:H:i'];
        $v = $request->validate([
            'karyawan_id' => ['required', 'integer', 'exists:karyawan,id'], 'tanggal' => ['required', 'date'],
            'jam' => ['required', 'array'], 'jam.masuk' => $jam, 'jam.pulang' => $jam, 'jam.lembur_mulai' => $jam, 'jam.lembur_selesai' => $jam,
            'catatan' => ['nullable', 'string', 'max:200'],
        ], ['*.date_format' => 'Format jam HH:MM, mis. 08:00.']);
        $kid = (int) $v['karyawan_id'];
        $tanggal = Carbon::parse($v['tanggal'])->toDateString();
        $catatan = trim((string) ($v['catatan'] ?? '')) ?: null;
        $asli = Absensi::where('karyawan_id', $kid)->whereDate('tanggal', $tanggal)->get()->keyBy('jenis');
        $lama = AbsenKoreksi::where('karyawan_id', $kid)->whereDate('tanggal', $tanggal)->get()->keyBy('jenis');

        // Rencana per jenis: Carbon = jam koreksi, false = kembali ke asli (hapus koreksi), null = tidak diubah.
        $rencana = [];
        $patokan = null; // jam masuk berlaku: jam sesudahnya yang lebih kecil dianggap lewat tengah malam (hari berikutnya)
        foreach (array_keys(Absensi::JENIS) as $jenis) {
            if (! array_key_exists($jenis, $v['jam'])) {
                $rencana[$jenis] = null;
            } elseif (($v['jam'][$jenis] ?? '') === '') {
                $rencana[$jenis] = false;
            } else {
                $t = Carbon::parse("{$tanggal} {$v['jam'][$jenis]}");
                if ($patokan && $t->lt($patokan)) {
                    $t->addDay();
                }
                $sama = isset($asli[$jenis]) && $asli[$jenis]->waktu->format('Y-m-d H:i') === $t->format('Y-m-d H:i');
                $rencana[$jenis] = $sama ? false : $t;
            }
            $berlaku = $this->berlaku($rencana[$jenis], $lama[$jenis] ?? null, $asli[$jenis] ?? null);
            if ($jenis === 'masuk' && $berlaku) {
                $patokan = $berlaku;
            }
        }

        // Urutan wajib: masuk < pulang ≤ mulai lembur < selesai lembur, satu sesi maks. 18 jam (jam lebih kecil dari masuk = lewat tengah malam).
        $akhir = collect($rencana)->map(fn ($r, $j) => $this->berlaku($r, $lama[$j] ?? null, $asli[$j] ?? null));
        $urut = $akhir->filter()->all();
        $label = Absensi::JENIS;
        $sebelum = null;
        foreach ($urut as $j => $t) {
            if ($sebelum && ($j === 'lembur_mulai' ? $t->lt($urut[$sebelum]) : $t->lte($urut[$sebelum]))) {
                throw ValidationException::withMessages(['jam' => "{$label[$j]} ({$t->format('H:i')}) harus sesudah {$label[$sebelum]} ({$urut[$sebelum]->format('H:i')})."]);
            }
            $sebelum = $j;
        }
        if (count($urut) > 1 && reset($urut)->diffInMinutes(end($urut)) > 18 * 60) {
            throw ValidationException::withMessages(['jam' => 'Satu hari kerja lebih dari 18 jam ('.reset($urut)->format('H:i').' sampai '.end($urut)->format('H:i').(end($urut)->toDateString() !== reset($urut)->toDateString() ? ' hari berikutnya' : '').') — periksa lagi jamnya.']);
        }

        $ubah = [];
        DB::transaction(function () use ($rencana, $lama, $asli, $kid, $tanggal, $catatan, $request, &$ubah) {
            foreach ($rencana as $jenis => $r) {
                $sebelum = ($lama[$jenis] ?? null)?->waktu ?? ($asli[$jenis] ?? null)?->waktu;
                if ($r === false && isset($lama[$jenis])) {
                    $lama[$jenis]->delete();
                    $ubah[] = Absensi::JENIS[$jenis].' '.$sebelum->format('H:i').' → asli '.(($asli[$jenis] ?? null)?->waktu->format('H:i') ?? '(kosong)');
                } elseif ($r instanceof Carbon) {
                    $k = $lama[$jenis] ?? new AbsenKoreksi(['karyawan_id' => $kid, 'tanggal' => $tanggal, 'jenis' => $jenis]);
                    $berubah = ! $k->exists || ! $k->waktu->eq($r) || $k->catatan !== $catatan;
                    if ($berubah) {
                        $k->fill(['waktu' => $r, 'catatan' => $catatan, 'user_id' => $request->user()->id])->save();
                        $ubah[] = Absensi::JENIS[$jenis].' '.($sebelum?->format('H:i') ?? '–').' → '.$r->format('H:i');
                    }
                } elseif ($r === null && isset($lama[$jenis]) && $catatan !== null && $lama[$jenis]->catatan !== $catatan) {
                    $lama[$jenis]->update(['catatan' => $catatan]);
                }
            }
            if ($ubah) {
                $nama = Karyawan::find($kid)?->nama;
                KasRiwayat::create(['aksi' => 'absen-jam', 'lembar' => 'Absen', 'baris_awal' => 0, 'baris_akhir' => 0, 'user_id' => $request->user()->id,
                    'ringkasan' => mb_strimwidth("Jam absen {$nama} ".Carbon::parse($tanggal)->format('d-m-Y').': '.implode(', ', $ubah).($catatan ? " — {$catatan}" : ''), 0, 490, '…'),
                    'isi' => ['karyawan_id' => $kid, 'tanggal' => $tanggal, 'ubah' => $ubah, 'catatan' => $catatan]]);
            }
        });

        return response()->json(['ok' => true, 'ubah' => $ubah]);
    }

    /** Jam berlaku satu jenis sesudah rencana diterapkan. */
    private function berlaku(Carbon|false|null $rencana, ?AbsenKoreksi $lama, ?Absensi $asli): ?Carbon
    {
        return $rencana instanceof Carbon ? $rencana : ($rencana === false ? $asli?->waktu : ($lama?->waktu ?? $asli?->waktu));
    }
}
