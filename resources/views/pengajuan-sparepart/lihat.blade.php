@extends('layouts.app', ['judul' => 'Pengajuan Sparepart '.$p->nomor])

@section('lebar', '1300px')

@section('isi')
    @php($qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ','))
    <style>
        .psp-kepala { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px 18px; margin-bottom: 14px; font-size: 13.5px; }
        .psp-kepala .k { font-size: 12px; color: var(--redup); }
        .psp-aksi { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .psp-aksi form { margin: 0; }
        .kecil { font-size: 11.5px; color: var(--redup); }
        .beda-naik { color: var(--merah); }
        .beda-turun { color: var(--sukses); }
        table.kas tfoot td { font-weight: 700; border-top: 2px solid var(--garis-kuat); }
        .form-putus { border: 1px solid var(--garis); border-radius: 8px; padding: 12px 14px; margin-top: 14px; }
        .form-putus textarea { width: 100%; box-sizing: border-box; }
        @media print {
            header, .samping, .psp-aksi, .form-putus, .tak-cetak { display: none !important; }
            .kartu { border: 0 !important; box-shadow: none !important; }
        }
    </style>

    <div class="psp-aksi">
        <a href="{{ route('pengajuan-sparepart.index') }}" class="tombol polos">← Daftar pengajuan</a>
        <button type="button" class="tombol polos" onclick="window.print()">🖨 Cetak</button>
        @if ($bolehUbah)
            <a href="{{ route('pengajuan-sparepart.ubah', $p) }}" class="tombol polos">✎ Ubah</a>
            <form method="POST" action="{{ route('pengajuan-sparepart.batal', $p) }}" onsubmit="return confirm('Batalkan pengajuan {{ $p->nomor }}?')">@csrf
                <button type="submit" class="tombol polos" style="color: var(--merah);">Batalkan</button></form>
        @endif
    </div>

    <div class="kartu">
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
            <h3 style="margin: 0;">Pengajuan Sparepart {{ $p->nomor }}</h3>
            <span class="label {{ \App\Models\PengajuanSparepart::WARNA[$p->status] }}">{{ \App\Models\PengajuanSparepart::STATUS[$p->status] }}</span>
        </div>
        <div class="psp-kepala">
            <div><div class="k">Tanggal</div>{{ $p->tanggal->translatedFormat('j F Y') }}</div>
            <div><div class="k">Diajukan oleh</div>{{ $p->pembuat?->name ?? '—' }}<div class="kecil">{{ $p->created_at?->translatedFormat('j M Y H:i') }}</div></div>
            <div><div class="k">Keperluan / unit</div>{{ $p->keperluan ?? '—' }}</div>
            <div><div class="k">Total</div><b style="font-size: 18px;">Rp {{ rp($p->total) }}</b></div>
            @if ($p->catatan)<div style="grid-column: 1 / -1;"><div class="k">Catatan</div>{{ $p->catatan }}</div>@endif
            @if ($p->diputus_pada)
                <div style="grid-column: 1 / -1;"><div class="k">{{ \App\Models\PengajuanSparepart::STATUS[$p->status] }} oleh</div>{{ $p->pemutus?->name ?? '—' }} · {{ $p->diputus_pada->translatedFormat('j M Y H:i') }}
                    @if ($p->catatan_putusan)<div style="margin-top: 2px;">“{{ $p->catatan_putusan }}”</div>@endif</div>
            @endif
        </div>

        <div class="gulir" style="padding: 0; border: 1px solid var(--garis); border-radius: 8px;">
            <table class="kas" data-tanpa-saring-kolom>
                <thead><tr><th class="angka">No</th><th>Nama Sparepart</th><th>Jenis</th><th>Part Number</th><th>Merk</th><th class="angka">Qty</th><th>Satuan</th>
                    <th class="angka">Harga Satuan</th><th class="angka">Subtotal</th><th class="angka tak-cetak">Harga Database</th><th>Keterangan</th></tr></thead>
                <tbody>
                    @foreach ($p->detail as $i => $d)
                        <tr class="t">
                            <td class="angka">{{ $i + 1 }}</td>
                            <td><b>{{ $d->nama_item }}</b></td>
                            <td>{{ $d->jenis }}</td>
                            <td style="white-space: nowrap;">{{ $d->part_number }}</td>
                            <td>{{ $d->merk }}</td>
                            <td class="angka">{{ $qty($d->qty) }}</td>
                            <td>{{ $d->satuan }}</td>
                            <td class="angka">{{ rp($d->harga) }}</td>
                            <td class="angka"><b>{{ rp($d->subtotal) }}</b></td>
                            <td class="angka tak-cetak">
                                @if ($d->harga_saran !== null)
                                    {{ rp($d->harga_saran) }}
                                    @if (round($d->harga) != round($d->harga_saran) && $d->harga_saran > 0)
                                        @php($pct = ($d->harga - $d->harga_saran) / $d->harga_saran * 100)
                                        <div class="kecil {{ $pct > 0 ? 'beda-naik' : 'beda-turun' }}">{{ $pct > 0 ? '+' : '' }}{{ number_format($pct, 0, ',', '.') }}% dari harga terakhir</div>
                                    @endif
                                @else
                                    <span class="kecil">tidak ada di database</span>
                                @endif
                            </td>
                            <td style="font-size: 12.5px;">{{ $d->keterangan }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td colspan="8" class="angka">Total ({{ $p->detail->count() }} item)</td><td class="angka">Rp {{ rp($p->total) }}</td><td colspan="2" class="tak-cetak"></td></tr></tfoot>
            </table>
        </div>

        @if ($bolehPutus)
            <form method="POST" action="{{ route('pengajuan-sparepart-putus.simpan', $p) }}" class="form-putus">@csrf
                <label for="catatan_putusan">Catatan manajemen (opsional)</label>
                <textarea id="catatan_putusan" name="catatan_putusan" rows="2" maxlength="500" placeholder="mis. disetujui, beli di supplier X / tunda bulan depan"></textarea>
                <div style="display: flex; gap: 8px; margin-top: 10px;">
                    <button type="submit" name="putusan" value="disetujui" class="tombol">✓ Setujui</button>
                    <button type="submit" name="putusan" value="ditolak" class="tombol polos" style="color: var(--merah);" onclick="return confirm('Tolak pengajuan {{ $p->nomor }}?')">✕ Tolak</button>
                </div>
            </form>
        @endif
    </div>
@endsection
