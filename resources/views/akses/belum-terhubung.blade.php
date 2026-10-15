@extends('layouts.app', ['judul' => 'Akses belum tersedia'])

@section('lebar', '640px')

@section('isi')
    <div class="kartu">
        <h3 style="margin-top: 0;">Akun belum punya menu</h3>
        @if ($karyawan)
            <p>Akun <b>{{ auth()->user()->email }}</b> terhubung ke karyawan <b>{{ $karyawan->nama }}</b>, tetapi jabatannya belum diisi di Data Karyawan.</p>
        @else
            <p>Akun <b>{{ auth()->user()->email }}</b> belum terhubung ke karyawan aktif di Data Karyawan.</p>
        @endif
        <p class="redup">Menu yang bisa dibuka mengikuti <b>jabatan</b> karyawan. Minta admin mengisi email akun Google ini dan jabatan Anda di Data Karyawan.</p>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="tombol polos">Keluar</button></form>
    </div>
@endsection
