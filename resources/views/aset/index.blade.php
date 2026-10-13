@extends('layouts.app', ['judul' => 'Data Aset'])

@section('lebar', '1440px')

@section('isi')
    @php($tgl = fn ($t) => $t ? $t->translatedFormat('j M Y') : '')
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
        <span class="redup">Kategori:</span>
        <a href="{{ route('aset.index', array_filter(['status' => $status])) }}" @class(['chip', 'aktif' => ! $kategori])>Semua · {{ $semua->count() }}</a>
        @foreach (\App\Models\AsetTruk::KATEGORI as $k => $label)
            @if ($n = $semua->where('kategori', $k)->count())
                <a href="{{ route('aset.index', array_filter(['kategori' => $k, 'status' => $status])) }}" @class(['chip', 'aktif' => $kategori === $k])>{{ $label }} · {{ $n }}</a>
            @endif
        @endforeach
        @if ($bolehUbah)<a href="{{ route('aset.create', array_filter(['kategori' => $kategori])) }}" class="tombol" style="margin-left: auto; padding: 8px 14px;">+ Tambah aset</a>@endif
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="redup">Status:</span>
        <a href="{{ route('aset.index', array_filter(['kategori' => $kategori])) }}" @class(['chip', 'aktif' => ! $status])>Semua</a>
        @foreach (\App\Models\AsetTruk::STATUS as $k => $label)
            @if ($n = $semua->when($kategori, fn ($c) => $c->where('kategori', $kategori))->where('status', $k)->count())
                <a href="{{ route('aset.index', array_filter(['status' => $k, 'kategori' => $kategori])) }}" @class(['chip', 'aktif' => $status === $k])>{{ $label }} · {{ $n }}</a>
            @endif
        @endforeach
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead>
                <tr>
                    <th>Kode / No Lambung</th><th>Kategori</th><th>Kepemilikan</th><th>Plat</th><th>Jenis / merek</th><th>Tahun</th><th>No rangka</th><th>No mesin</th>
                    <th>STNK s.d.</th><th>KIR s.d.</th><th>Status</th><th>Driver tetap</th><th>Catatan</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($truk as $a)
                    <tr class="t">
                        <td><b>{{ $a->no_lambung }}</b></td>
                        <td>{{ $a->labelKategori() }}</td>
                        <td>{{ $a->kepemilikan === 'rental' ? 'Rental'.($a->vendor ? ' · '.$a->vendor : '') : 'Milik MNP' }}</td>
                        <td>{{ $a->plat ?? '—' }}</td>
                        <td>{{ $a->jenis }}</td>
                        <td>{{ $a->tahun }}</td>
                        <td class="i">{{ $a->no_rangka }}</td>
                        <td class="i">{{ $a->no_mesin }}</td>
                        <td style="white-space: nowrap;">{{ $tgl($a->stnk_berlaku) }}@if ($a->stnk_berlaku?->isPast()) <span class="label merah">lewat</span>@endif</td>
                        <td style="white-space: nowrap;">{{ $tgl($a->kir_berlaku) }}@if ($a->kir_berlaku?->isPast()) <span class="label merah">lewat</span>@endif</td>
                        <td><span @class(['label', 'hijau' => $a->status === 'aktif', 'kuning' => $a->status === 'perbaikan', 'merah' => $a->status === 'dijual'])>{{ $a->labelStatus() }}</span></td>
                        <td>{{ $a->driver_tetap }}</td>
                        <td class="redup" style="font-size: 12px; max-width: 360px;">{{ $a->catatan }}</td>
                        <td style="white-space: nowrap;">@if ($bolehUbah)<a href="{{ route('aset.edit', $a) }}" class="tombol-edit">Edit</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="14" class="redup" style="padding: 20px 14px;">Belum ada aset di kategori / status ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
