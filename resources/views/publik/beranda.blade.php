@extends('layouts.publik')

@push('gaya')
    <style>
        .pembuka { background: linear-gradient(180deg, var(--aksen-muda), #fff); }
        .pembuka .bungkus { padding-top: 72px; padding-bottom: 72px; max-width: 760px; text-align: center; }
        .pembuka p.label { color: var(--aksen); font-weight: 600; font-size: 13px; letter-spacing: .05em; text-transform: uppercase; margin: 0; }
        .pembuka h1 { font-size: clamp(30px, 5vw, 44px); line-height: 1.2; margin: 12px 0 16px; }
        .pembuka p.isi { font-size: 18px; color: var(--redup); margin: 0 auto 28px; }
        .google { display: inline-flex; align-items: center; gap: 10px; border: 1px solid #cbd2d9; background: #fff; color: var(--teks); padding: 11px 20px; border-radius: 7px; text-decoration: none; font-weight: 500; }
        .fitur { padding-top: 56px; padding-bottom: 56px; }
        .fitur h2 { font-size: 24px; margin: 0 0 24px; }
        .kisi { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
        .kisi div { border: 1px solid var(--garis); border-radius: 10px; padding: 20px; }
        .kisi h3 { margin: 0 0 6px; font-size: 16px; }
        .kisi p { margin: 0; color: var(--redup); font-size: 14px; }
        .akses { background: var(--gelap); color: #d9e2ec; border-radius: 14px; padding: 28px; margin-bottom: 56px; }
        .akses h2 { color: #fff; margin: 0 0 8px; font-size: 20px; }
        .akses a { color: #8fe3c0; }
    </style>
@endpush

@section('isi')
    <section class="pembuka">
        <div class="bungkus">
            <p class="label">{{ config('mnp.nama_perusahaan') }}</p>
            <h1>Kas harian dan biaya operasional dalam satu aplikasi</h1>
            <p class="isi">
                {{ config('mnp.nama_aplikasi') }} adalah aplikasi internal untuk mencatat pengeluaran kas harian, mengelompokkan biaya per kode GL
                dan cost center, serta menyusun laporan kas bulanan perusahaan.
            </p>
            <a class="google" href="{{ route('auth.google.redirect') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="#4285F4" d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5a5.6 5.6 0 0 1-2.4 3.7v3h3.9c2.3-2.1 3.5-5.2 3.5-8.9Z"/>
                    <path fill="#34A853" d="M12 24c3.2 0 5.9-1.1 7.9-2.9l-3.9-3c-1 .7-2.3 1.1-4 1.1-3.1 0-5.7-2.1-6.6-4.9H1.4v3.1A12 12 0 0 0 12 24Z"/>
                    <path fill="#FBBC05" d="M5.4 14.3a7.2 7.2 0 0 1 0-4.6V6.6H1.4a12 12 0 0 0 0 10.8l4-3.1Z"/>
                    <path fill="#EA4335" d="M12 4.8c1.8 0 3.4.6 4.6 1.8l3.4-3.4A12 12 0 0 0 1.4 6.6l4 3.1C6.3 6.9 8.9 4.8 12 4.8Z"/>
                </svg>
                Login dengan Google
            </a>
            <p class="redup">Khusus pengguna yang terdaftar</p>
        </div>
    </section>

    <section class="bungkus fitur">
        <h2>Yang dikerjakan aplikasi</h2>
        <div class="kisi">
            <div><h3>Kas harian</h3><p>Pencatatan pengeluaran per hari: nominal, PIC, keterangan, kendaraan, dan nomor DO.</p></div>
            <div><h3>Kode GL &amp; cost center</h3><p>Setiap pengeluaran dikelompokkan ke kode GL dan cost center agar rekap biaya konsisten.</p></div>
            <div><h3>Laporan bulanan</h3><p>Rekap kas per bulan dengan saldo, debit, dan kredit, siap dicocokkan dengan bank.</p></div>
            <div><h3>Sinkron Google Sheets</h3><p>Membaca spreadsheet kas yang sudah dipakai tim, sehingga data lama tetap terpakai.</p></div>
        </div>
    </section>

    <section class="bungkus">
        <div class="akses">
            <h2>Akses terbatas &amp; data terlindungi</h2>
            <p style="margin: 0;">
                Hanya akun Google yang didaftarkan administrator yang dapat masuk. Izin Google dipakai untuk membaca dan memperbarui
                spreadsheet kas milik perusahaan. Baca <a href="{{ route('privasi') }}">Kebijakan Privasi</a> dan
                <a href="{{ route('syarat') }}">Syarat Layanan</a>.
            </p>
        </div>
    </section>
@endsection
