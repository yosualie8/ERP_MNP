@extends('layouts.app', ['judul' => 'Izinkan akses ERP'])

@section('lebar', '640px')

@section('isi')
    <div class="kartu" style="margin-top: 30px;">
        @if ($galat)
            <h3 style="margin: 0 0 8px; color: var(--aksen-terang);">Tidak bisa dilanjutkan</h3>
            <p>{{ $galat }}</p>
        @else
            <h3 style="margin: 0 0 8px; color: var(--aksen-terang);">Izinkan {{ $klien['nama'] }} membaca data ERP MNP?</h3>
            <p style="margin: 0 0 12px;">Anda masuk sebagai <b>{{ auth()->user()->email }}</b>.</p>
            <ul style="margin: 0 0 14px; padding-left: 20px; line-height: 1.7;">
                <li><b>Hanya baca</b>: Kas Harian, reimburse, rekap biaya, Uang Jalan, pengajuan UJ, ritasi, aset{{ auth()->user()->isSuperAdmin() ? ', aktivitas admin' : '' }} — sesuai menu yang boleh Anda buka.</li>
                <li>Tidak bisa mengubah data aplikasi maupun Google Sheet.</li>
                <li>Setiap pemakaian tercatat atas nama Anda di log aktivitas. Akses berhenti bila email Anda dihapus dari menu Pengguna.</li>
            </ul>
            <form method="POST" action="{{ route('oauth.putuskan') }}" style="display: flex; gap: 10px;">
                @csrf
                @foreach (['client_id', 'redirect_uri', 'code_challenge', 'state', 'resource', 'scope'] as $k)
                    @if (isset($p[$k]))<input type="hidden" name="{{ $k }}" value="{{ $p[$k] }}">@endif
                @endforeach
                <button type="submit" name="keputusan" value="izinkan" class="tombol">✓ Izinkan</button>
                <button type="submit" name="keputusan" value="tolak" class="tombol polos">Tolak</button>
            </form>
        @endif
    </div>
@endsection
