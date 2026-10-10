{{-- Gaya bersama halaman Dashboard (Kas Harian, UJ, Aktivitas Admin). --}}
    <style>
        .dash-kartu { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; margin-bottom: 14px; }
        .dash-kartu .kartu { margin: 0; }
        /* Judul kartu & tabel merah supaya mudah dibedakan dari isinya. */
        .dash-kartu h4 { margin: 0 0 10px; padding-bottom: 6px; font-size: 13px; color: var(--aksen-terang); text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid var(--aksen); }
        .kartu .dash-judul { color: var(--aksen-terang); }
        .angka-besar { font-size: 30px; font-weight: 700; line-height: 1.1; }
        .dash-baris { display: flex; justify-content: space-between; gap: 10px; padding: 5px 0; border-bottom: 1px solid var(--garis); font-size: 13px; }
        .dash-baris:last-child { border-bottom: 0; }
    </style>
