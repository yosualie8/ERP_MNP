<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pengajuan uang jalan (belum ditransfer); direalisasikan per detail lewat Input UJ. */
class UjPengajuan extends Model
{
    protected $table = 'uj_pengajuan';

    public const STATUS = ['diajukan' => 'Diajukan', 'sebagian' => 'Sebagian terealisasi', 'selesai' => 'Selesai', 'batal' => 'Dibatalkan'];

    protected $fillable = ['tanggal', 'keterangan', 'nama', 'bank', 'rekening', 'nominal', 'status', 'user_id'];

    protected $casts = ['tanggal' => 'date'];

    public function detail(): HasMany
    {
        return $this->hasMany(UjPengajuanDetail::class)->orderBy('urut');
    }

    /** Transfer Kas Harian yang membiayai pengajuan ini (dipilih admin). */
    public function transfer(): HasMany
    {
        return $this->hasMany(UjPengajuanTransfer::class)->orderBy('kas_tanggal')->orderBy('kas_no_id');
    }

    /** Penanda transfer (owner menandai pengajuan yang ia bayar sebelum transfernya ada di Kas Harian). */
    public function tanda(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(UjTransferTanda::class, 'uj_transfer_tanda_pengajuan')->orderBy('uj_transfer_tanda.waktu');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Owner 23 Okt 2026: pengajuan hanya boleh direalisasikan di Input UJ bila dananya sudah ditransfer (tertaut transaksi Kas Harian)
     * atau sudah ditandai transfer. Pengajuan tanpa nominal transfer (dibayar penuh dari saldo sopir) selalu boleh.
     */
    public function siapRealisasi(): bool
    {
        $ada = fn (string $rel) => $this->relationLoaded($rel) ? $this->{$rel}->isNotEmpty() : $this->{$rel}()->exists();

        return (int) $this->nominal <= 0 || $ada('transfer') || $ada('tanda');
    }

    public function scopeSiapRealisasi($query)
    {
        return $query->where(fn ($q) => $q->where('nominal', '<=', 0)->orWhereHas('transfer')->orWhereHas('tanda'));
    }

    public function kode(): string
    {
        return 'PUJ-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Status dari detailnya: semua menunggu = diajukan, sebagian terealisasi, semua selesai/batal. Nominal (yang ditransfer) =
     * jumlah baris yang TIDAK dibatalkan, dikurangi bagian dari saldo sopir (owner 22 Okt 2026: total harus berkurang saat baris
     * dibatalkan, mis. lewat sinkron bongkaran). Pengajuan yang batal seluruhnya tetap menyimpan nominal aslinya.
     */
    public function hitungStatus(): void
    {
        $d = $this->detail()->get();
        $real = $d->whereIn('status', ['terealisasi', 'dialihkan'])->count(); // dialihkan = dana sudah keluar
        $tunggu = $d->where('status', 'menunggu')->count();
        $aktif = $d->where('status', '!=', 'batal');
        $this->update(['status' => $tunggu === 0 ? ($real ? 'selesai' : 'batal') : ($real ? 'sebagian' : 'diajukan')]
            + ($aktif->isNotEmpty() ? ['nominal' => (int) $aktif->sum(fn ($x) => $x->nominal - $x->saldo)] : []));
    }
}
