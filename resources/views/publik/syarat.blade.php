@extends('layouts.publik')

@section('judul', 'Syarat Layanan')

@section('isi')
    <article class="dokumen bungkus">
        <h1>Syarat Layanan</h1>
        <p class="redup">Berlaku sejak {{ config('mnp.dokumen_berlaku_sejak') }}</p>

        <p>
            Dengan masuk dan menggunakan {{ config('mnp.nama_aplikasi') }} ("Aplikasi"), Anda menyetujui syarat berikut.
            Aplikasi disediakan oleh {{ config('mnp.nama_perusahaan') }} ("Perusahaan") untuk keperluan internal.
        </p>

        <h2>1. Siapa yang boleh menggunakan</h2>
        <p>
            Aplikasi hanya untuk pengguna yang didaftarkan administrator Perusahaan. Akun Google yang tidak terdaftar akan ditolak
            dan izin yang terlanjur diberikan akan dicabut otomatis.
        </p>

        <h2>2. Akun dan keamanan</h2>
        <ul>
            <li>Login dilakukan dengan akun Google. Pengguna bertanggung jawab menjaga keamanan akun Google masing-masing.</li>
            <li>Pengguna wajib segera memberi tahu administrator bila menduga akunnya dipakai pihak lain.</li>
        </ul>

        <h2>3. Penggunaan yang diperbolehkan</h2>
        <ul>
            <li>Aplikasi dipakai untuk mencatat, merekap, dan melaporkan kas serta biaya operasional Perusahaan.</li>
            <li>Pengguna dilarang memasukkan data palsu, mengakses data di luar kewenangannya, atau mencoba mengganggu, membobol, maupun membebani Aplikasi.</li>
        </ul>

        <h2>4. Data dan izin Google</h2>
        <p>
            Saat login, pengguna memberikan izin kepada Aplikasi untuk membaca dan memperbarui Google Sheets serta melihat daftar file Google Drive.
            Izin ini dipakai sesuai <a href="{{ route('privasi') }}">Kebijakan Privasi</a>. Data keuangan yang dicatat di Aplikasi adalah milik Perusahaan.
        </p>

        <h2>5. Ketersediaan layanan</h2>
        <p>
            Perusahaan berupaya menjaga Aplikasi tetap berjalan, namun Aplikasi dapat sewaktu-waktu tidak tersedia karena pemeliharaan atau gangguan.
            Pengguna tetap bertanggung jawab memeriksa kebenaran angka sebelum dipakai untuk keputusan keuangan.
        </p>

        <h2>6. Penghentian akses</h2>
        <p>Administrator dapat menonaktifkan akses pengguna kapan saja, misalnya bila pengguna tidak lagi bekerja di Perusahaan atau melanggar syarat ini.</p>

        <h2>7. Perubahan syarat</h2>
        <p>Syarat ini dapat diperbarui sewaktu-waktu. Penggunaan Aplikasi setelah perubahan berarti pengguna menyetujui syarat yang baru.</p>

        <h2>8. Kontak</h2>
        <p>Pertanyaan mengenai syarat ini dapat dikirim ke <a href="mailto:{{ config('mnp.email_kontak') }}">{{ config('mnp.email_kontak') }}</a>.</p>
    </article>
@endsection
