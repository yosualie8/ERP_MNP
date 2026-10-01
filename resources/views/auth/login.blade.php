@extends('layouts.app', ['judul' => 'Masuk'])

@section('isi')
    <div class="kartu" style="max-width: 420px; margin: 80px auto; text-align: center;">
        <h2 style="margin-top: 0;">PT Multi Niaga Putra</h2>
        <p class="redup">Masuk dengan akun Google yang terdaftar.</p>
        @error('email')
            <div class="pesan galat">{{ $message }}</div>
        @enderror
        <a class="tombol" href="{{ route('auth.google.redirect') }}">Masuk dengan Google</a>
    </div>
@endsection
