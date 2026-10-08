@extends('layouts.app', ['judul' => 'Data Aset Truk'])

@section('lebar', '1440px')

@section('isi')
    @php($tgl = fn ($t) => $t ? $t->translatedFormat('j M Y') : '')
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="redup">Status:</span>
        <a href="{{ route('aset.index') }}" @class(['chip', 'aktif' => ! $status])>Semua · {{ $semua->count() }}</a>
        @foreach (\App\Models\AsetTruk::STATUS as $k => $label)
            @if ($n = $semua->where('status', $k)->count())
                <a href="{{ route('aset.index', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $label }} · {{ $n }}</a>
            @endif
        @endforeach
        @if ($bolehUbah)<a href="{{ route('aset.create') }}" class="tombol" style="margin-left: auto; padding: 8px 14px;">+ Tambah truk</a>@endif
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead>
                <tr>
                    <th>No Lambung</th><th>Plat</th><th>Jenis</th><th>Tahun</th><th>No rangka</th><th>No mesin</th>
                    <th>STNK s.d.</th><th>KIR s.d.</th><th>Status</th><th>Driver tetap</th><th>Catatan</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($truk as $a)
                    <tr class="t">
                        <td><b>{{ $a->no_lambung }}</b></td>
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
                    <tr><td colspan="12" class="redup" style="padding: 20px 14px;">Belum ada truk di Data Aset.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
