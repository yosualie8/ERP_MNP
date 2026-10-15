@extends('layouts.app', ['judul' => 'Akses belum tersedia'])

@section('lebar', '640px')

@section('isi')
    <div class="kartu">
        <h3 style="margin-top: 0;">Belum ada menu untuk jabatan Anda</h3>
        <p>Akun <b>{{ auth()->user()->email }}</b> terhubung ke karyawan <b>{{ $karyawan?->nama }}</b> dengan jabatan <b>{{ $karyawan?->jabatan }}</b>, tetapi jabatan ini belum diberi menu apa pun.</p>
        <p class="redup">Menu yang bisa dibuka mengikuti jabatan. Minta Super Admin mengaturnya di Hak Akses Jabatan.</p>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="tombol polos">Keluar</button></form>
    </div>
@endsection