@extends('layouts.publik')

@section('judul', 'Kebijakan Privasi')

@section('isi')
    <article class="dokumen bungkus">
        <h1>Kebijakan Privasi</h1>
        <p class="redup">Berlaku sejak {{ config('mnp.dokumen_berlaku_sejak') }}</p>

        <p>
            Kebijakan ini menjelaskan data apa yang dikumpulkan oleh {{ config('mnp.nama_aplikasi') }} ("Aplikasi"), aplikasi internal milik
            {{ config('mnp.nama_perusahaan') }} ("Perusahaan"), dan bagaimana data tersebut digunakan.
        </p>

        <h2>1. Data yang dikumpulkan</h2>
        <ul>
            <li><b>Data akun Google</b> saat login: nama, alamat email, ID akun Google, dan foto profil.</li>
            <li><b>Data keuangan internal</b> yang dicatat pengguna: transaksi kas harian, nominal, PIC, keterangan, kode GL, cost center, kendaraan, dan nomor DO.</li>
            <li>
                <b>Izin Google</b> yang diberikan pengguna saat login:
                <ul>
                    <li><code>spreadsheets</code>: membaca dan memperbarui spreadsheet Google Sheets milik pengguna, dipakai untuk spreadsheet kas perusahaan.</li>
                    <li><code>drive.readonly</code>: melihat daftar file Google Drive pengguna, supaya spreadsheet kas dapat dipilih dari Aplikasi. Aplikasi tidak mengubah atau menghapus file Drive.</li>
                </ul>
                Token akses Google disimpan terenkripsi di server Perusahaan.
            </li>
        </ul>

        <h2>2. Penggunaan data</h2>
        <ul>
            <li>Data akun Google dipakai untuk memastikan hanya pengguna terdaftar yang dapat masuk dan untuk mencatat siapa yang membuat atau mengubah data.</li>
            <li>Isi spreadsheet yang dibaca melalui izin Google dipakai hanya untuk menampilkan, merekap, dan memperbarui data kas Perusahaan di dalam Aplikasi.</li>
            <li>Data tidak dijual, tidak dipakai untuk iklan, tidak dipakai untuk melatih model kecerdasan buatan, dan tidak dibagikan kepada pihak ketiga, kecuali diwajibkan oleh hukum.</li>
        </ul>
        <p>
            Penggunaan dan pemindahan informasi yang diterima dari Google API mematuhi
            <a href="https://developers.google.com/terms/api-services-user-data-policy" target="_blank" rel="noopener">Google API Services User Data Policy</a>,
            termasuk ketentuan Limited Use.
        </p>

        <h2>3. Penyimpanan dan keamanan</h2>
        <p>
            Data disimpan di server milik Perusahaan dengan koneksi terenkripsi (HTTPS). Token Google disimpan dalam bentuk terenkripsi.
            Akses ke Aplikasi dibatasi untuk pengguna yang didaftarkan administrator, dan database dicadangkan secara berkala.
        </p>

        <h2>4. Pencabutan akses dan penghapusan data</h2>
        <ul>
            <li>Pengguna dapat keluar dari Aplikasi kapan saja. Administrator dapat menghapus token Google yang tersimpan atas permintaan.</li>
            <li>Pemilik akun Google dapat mencabut akses Aplikasi melalui <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a>.</li>
            <li>Permintaan penghapusan akun dan data pribadi dapat dikirim ke <a href="mailto:{{ config('mnp.email_kontak') }}">{{ config('mnp.email_kontak') }}</a> dan diproses paling lambat 30 hari.</li>
        </ul>

        <h2>5. Kontak</h2>
        <p>
            Pertanyaan mengenai kebijakan ini dapat dikirim ke administrator {{ config('mnp.nama_perusahaan') }} melalui
            <a href="mailto:{{ config('mnp.email_kontak') }}">{{ config('mnp.email_kontak') }}</a>.
        </p>

        <h2>6. Perubahan kebijakan</h2>
        <p>Kebijakan ini dapat diperbarui sewaktu-waktu. Tanggal berlaku di atas akan disesuaikan setiap kali ada perubahan.</p>
    </article>
@endsection
