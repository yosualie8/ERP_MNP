@extends('layouts.app', ['judul' => 'Master Supplier'])

@section('lebar', '1500px')

@section('isi')
    @php($tgl = fn ($t) => \App\Http\Controllers\SparepartController::tgl($t))
    <style>
        .sup-atas { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
        .sup-atas .chip { margin: 0; }
        table.kas td.lebar { min-width: 200px; max-width: 340px; white-space: normal; font-size: 12px; }
        .kecil { font-size: 11.5px; color: var(--redup); }
    </style>

    <p class="redup" style="margin-top: 0;">Data induk supplier sparepart. <b>Rekening</b> diambil dari <b>Daftar Rekening</b> (nama pemilik rekening = nama supplier) dan
        <b>pembelian</b> dari Database Sparepart (hanya supplier yang sudah diverifikasi Admin Plant) — supaya tersambung, nama supplier harus sama persis dengan nama di sana.</p>

    <div class="sup-atas">
        <a href="{{ route('supplier.index') }}" @class(['chip', 'aktif' => ! $semuaStatus])>Aktif · {{ $semuaStatus ? $baris->where('s.aktif', true)->count() : $baris->count() }}</a>
        @if ($jumlahNonaktif)<a href="{{ route('supplier.index', ['semua' => 1]) }}" @class(['chip', 'aktif' => $semuaStatus])>Semua (termasuk {{ $jumlahNonaktif }} nonaktif)</a>@endif
        @if ($bolehUbah)<a href="{{ route('supplier.create') }}" class="tombol" style="margin-left: auto;">+ Tambah supplier</a>@endif
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Nama Supplier</th><th>Kontak</th><th>Telepon</th><th>Alamat</th><th>Barang</th><th>Rekening (Daftar Rekening)</th>
                <th class="angka">Kali Beli</th><th class="angka">Total Belanja</th><th>Pembelian Terakhir</th><th>Item Sering Dibeli</th><th>Catatan</th><th>Status</th><th data-tanpa-saring data-tanpa-urut></th></tr></thead>
            <tbody>
                @forelse ($baris as $b)
                    @php($s = $b->s)
                    <tr class="t">
                        <td><b>{{ $s->nama }}</b></td>
                        <td>{{ $s->kontak }}</td>
                        <td style="white-space: nowrap;">@if ($s->telepon)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $s->telepon) }}">{{ $s->telepon }}</a>@endif</td>
                        <td class="lebar">{{ $s->alamat }}</td>
                        <td class="lebar">{{ $s->barang ?: $b->kategori->implode(', ') }}@if (! $s->barang && $b->kategori->isNotEmpty()) <span class="kecil">(dari pembelian)</span>@endif</td>
                        <td style="white-space: nowrap;">@forelse ($b->rekening as $r){{ $r->bank }} {{ $r->no_rekening }}@if (! $loop->last)<br>@endif @empty<span class="redup">—</span>@endforelse</td>
                        <td class="angka" data-n="{{ $b->n }}">{{ $b->n ?: '' }}</td>
                        <td class="angka" data-n="{{ $b->total }}">{{ $b->n ? rp($b->total) : '' }}</td>
                        <td data-n="{{ $b->terakhir }}" style="white-space: nowrap;">{{ $tgl($b->terakhir) }}</td>
                        <td class="lebar">{{ $b->item->implode(', ') }}@if ($b->item_lain) <span class="kecil">+{{ $b->item_lain }} item lain</span>@endif
                            @if ($b->n && $bolehSparepart)<div><a href="{{ route('sparepart.index', ['tab' => 'riwayat', 'periode' => 'semua', 'q' => $s->nama]) }}" style="font-size: 12px;">Lihat pembelian →</a></div>@endif</td>
                        <td class="lebar redup">{{ $s->catatan }}</td>
                        <td><span @class(['label', 'hijau' => $s->aktif])>{{ $s->aktif ? 'Aktif' : 'Nonaktif' }}</span></td>
                        <td>@if ($bolehUbah)<a href="{{ route('supplier.edit', $s) }}" class="tombol-edit">Edit</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="redup" style="padding: 20px 14px;">Belum ada supplier.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
