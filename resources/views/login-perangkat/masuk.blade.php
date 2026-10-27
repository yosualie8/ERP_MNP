@extends('layouts.app', ['judul' => 'Masuk lewat QR'])

@section('isi')
    <div class="kartu" style="max-width: 420px; margin: 80px auto; text-align: center;">
        <h2 style="margin-top: 0;">PT Multi Niaga Putra</h2>
        @if ($login)
            <p>Masuk sebagai <strong>{{ $login->user->name ?: $login->user->email }}</strong><br><span class="redup">{{ $login->user->email }}</span></p>
            <form method="POST" action="{{ route('login-perangkat.masuk', $token) }}">
                @csrf
                <button class="tombol" type="submit">Masuk</button>
            </form>
            <p class="redup" style="margin-bottom: 0;">QR ini hanya bisa dipakai sekali, berlaku sampai {{ $login->kedaluwarsa_pada->format('H:i') }}.</p>
        @else
            <div class="pesan galat">QR ini sudah dipakai atau sudah kedaluwarsa.</div>
            <p class="redup" style="margin-bottom: 0;">Buat QR baru di laptop: menu 📱 Login HP lain.</p>
        @endif
    </div>
@endsection
