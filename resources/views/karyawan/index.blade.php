@extends('layouts.app', ['judul' => 'Data Karyawan'])

@section('lebar', '1500px')

@section('isi')
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="redup">Status:</span>
        @foreach (\App\Models\Karyawan::STATUS as $k => $label)
            <a href="{{ route('karyawan.index', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $label }} · {{ $semua->where('status', $k)->count() }}</a>
        @endforeach
        <a href="{{ route('karyawan.index', ['status' => 'semua']) }}" @class(['chip', 'aktif' => ! $status])>Semua · {{ $semua->count() }}</a>
        @if ($n = $semua->filter(fn ($k) => $k->nik && isset($nikDobel[$k->nik]))->count())
            <span class="label merah" title="NIK yang sama dipakai lebih dari satu orang — cek KTP lalu perbaiki lewat Edit">⚠️ {{ $n }} karyawan NIK dobel</span>
        @endif
        @if ($bolehUbah)<a href="{{ route('karyawan.create') }}" class="tombol" style="margin-left: auto; padding: 8px 14px;">+ Tambah karyawan</a>@endif
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead>
                <tr><th>Nama</th><th>NIK</th><th>Status</th><th>Site</th><th>Jabatan</th><th>Bank</th><th>No rekening</th><th>Nama rekening</th><th>PIC gaji</th><th>Catatan</th><th data-tanpa-saring></th></tr>
            </thead>
            <tbody>
                @forelse ($karyawan as $k)
                    <tr class="t">
                        <td><b>{{ $k->nama }}</b></td>
                        <td style="white-space: nowrap;">{{ $k->nik }}@if ($k->nik && isset($nikDobel[$k->nik])) <span class="label merah" title="NIK sama dengan karyawan lain">dobel</span>@endif</td>
                        <td><span @class(['label', 'hijau' => $k->status === 'aktif'])>{{ $k->labelStatus() }}</span></td>
                        <td>{{ $k->site }}</td>
                        <td>{{ $k->jabatan }}</td>
                        <td>{{ $k->bank }}</td>
                        <td class="i">{{ $k->no_rekening }}</td>
                        <td>{{ $k->nama_rekening }}</td>
                        <td>{{ $k->pic_gaji }}</td>
                        <td class="redup" style="font-size: 12px; min-width: 200px;">{{ $k->catatan }}</td>
                        <td>@if ($bolehUbah)<a href="{{ route('karyawan.edit', $k) }}" class="tombol-edit">Edit</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="redup" style="padding: 20px 14px;">Belum ada data karyawan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
