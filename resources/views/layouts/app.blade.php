<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Dimuat di head karena dipakai skrip di dalam halaman (Input Kas, halaman Bon). --}}
    <script src="{{ asset('js/penampil-foto.js') }}?v={{ filemtime(public_path('js/penampil-foto.js')) }}"></script>
    <script src="{{ asset('js/tebak-kode-gl.js') }}?v={{ filemtime(public_path('js/tebak-kode-gl.js')) }}"></script>
    <script src="{{ asset('js/kecilkan-foto.js') }}?v={{ filemtime(public_path('js/kecilkan-foto.js')) }}"></script>
    <script src="{{ asset('js/pilih-bank.js') }}?v={{ filemtime(public_path('js/pilih-bank.js')) }}"></script>
    <script src="{{ asset('js/tempel-tabel.js') }}?v={{ filemtime(public_path('js/tempel-tabel.js')) }}"></script>
    <title>{{ $judul ?? 'MNP' }} · PT Multi Niaga Putra</title>
    <style>
        /* Tema gelap dengan aksen merah. --hijau-muda tetap ada (dipakai halaman lama) = latar aksen tipis. */
        :root {
            color-scheme: dark;
            --latar: #0e0f12; --kartu: #17191e; --kartu-2: #1f2229; --teks: #e8e9ec; --redup: #959ba5;
            --garis: #2a2e36; --garis-kuat: #3b404b;
            --aksen: #e5484d; --aksen-terang: #ff6b70; --aksen-muda: rgba(229, 72, 77, .15);
            --merah: #ff7b7f; --merah-muda: rgba(255, 92, 97, .14);
            --sukses: #4cc38a; --sukses-muda: rgba(76, 195, 138, .14);
            --hijau-muda: var(--aksen-muda);
            --isian: #121418; --isian-garis: #444a56; --isian-fokus: #1c1416;
            --otomatis-latar: #1d1830; --otomatis-garis: #5b4c9c; --tanda: rgba(255, 107, 112, .35);
            --baris-detail: #111317; --baris-detail-sorot: #1a1d23; --baris-masuk: #13201a;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--latar); color: var(--teks); font-size: 15px; }
        input, select, textarea { background: var(--isian); color: var(--teks); border: 1px solid var(--isian-garis); }
        input::placeholder { color: #6b717c; }
        input[type=checkbox], input[type=radio] { accent-color: var(--aksen); }
        ::selection { background: rgba(229, 72, 77, .45); }
        header { background: #121317; border-bottom: 2px solid var(--aksen); padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; }
        header strong { color: var(--aksen-terang); }
        main { max-width: @yield('lebar', '960px'); margin: 24px auto; padding: 0 16px; }
        header nav { display: flex; gap: 4px; flex-wrap: wrap; }
        header nav a { color: var(--redup); text-decoration: none; padding: 6px 10px; border-radius: 6px; font-size: 14px; }
        header nav a:hover { background: var(--kartu-2); color: var(--teks); }
        header nav a.aktif { background: var(--aksen-muda); color: var(--aksen-terang); }
        .chip { display: inline-block; padding: 4px 10px; border: 1px solid var(--garis); border-radius: 999px; font-size: 13px; color: var(--teks); text-decoration: none; margin: 0 4px 6px 0; background: var(--kartu); }
        .chip.aktif { background: var(--aksen); border-color: var(--aksen); color: #fff; }
        .angka { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .ringkas .kartu { margin: 0; padding: 14px 16px; }
        .ringkas b { display: block; font-size: 20px; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .gulir { overflow-x: auto; }
        .label { display: inline-block; font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--latar); color: var(--redup); }
        .label.merah { background: var(--merah-muda); color: var(--merah); }
        .label.hijau { background: var(--sukses-muda); color: var(--sukses); }
        table.kas { font-size: 13px; }
        table.kas td, table.kas th { padding: 6px; }
        table.kas td:first-child, table.kas th:first-child { padding-left: 14px; }
        table.kas td:last-child, table.kas th:last-child { padding-right: 14px; }
        /* Transfer (master) terang, rincian bon (detail) lebih gelap supaya kontras. */
        table.kas tr.t { background: var(--kartu); }
        table.kas tr.t.m { background: var(--baris-masuk); }
        table.kas tr.t td { border-top: 2px solid var(--garis-kuat); border-bottom: 0; }
        table.kas tr.b { background: var(--baris-detail); }
        table.kas tr.b td { color: var(--teks); border-bottom: 1px solid var(--garis); }
        table.kas tr.b:hover { background: var(--baris-detail-sorot); }
        table.kas tr.t:hover { background: var(--kartu-2); }
        table.kas tr.t.m:hover { background: #172a21; }
        table.kas tr.b i { background: var(--kartu-2); }
        /* Bon tertutup kecuali grupnya dibuka; saat tertutup, kolom Kode GL transfer berisi ringkasan akun bon. */
        table.kas tbody.grup:not(.buka) tr.b, table.kas tbody.grup:not(.buka) tr.bh { display: none; }
        /* Judul kolom transaksi detail (muncul saat grup dibuka). */
        table.kas tr.bh td { background: #2a1416; color: var(--aksen-terang); font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: .04em; padding-top: 5px; padding-bottom: 5px; border-bottom: 1px solid #4a2023; }
        table.kas tr.bh td:first-child { border-left: 3px solid var(--aksen); }
        table.kas tbody.grup.buka .ringkas-gl { visibility: hidden; }
        table.kas .ringkas-gl { color: var(--redup); font-size: 12px; }
        table.kas tr.t.ada-bon { cursor: pointer; }
        table.kas .panah { display: inline-block; width: 12px; color: var(--redup); transition: transform .15s; }
        table.kas tbody.grup.buka .panah { transform: rotate(90deg); }
        table.kas .jumlah-bon { display: inline-block; min-width: 18px; padding: 0 5px; margin-left: 4px; border-radius: 9px; background: var(--latar);
            color: var(--redup); font-size: 11px; text-align: center; }
        table.kas td:first-child { white-space: nowrap; }
        .tombol-edit { font-size: 12px; padding: 2px 8px; border-radius: 6px; color: var(--teks); text-decoration: none; border: 1px solid var(--garis-kuat); }
        .tombol-edit:hover { border-color: var(--aksen); background: var(--aksen-muda); color: var(--aksen-terang); }
        .tombol-hapus { background: none; border: 1px solid transparent; border-radius: 6px; color: var(--merah); cursor: pointer; font-size: 12px; padding: 2px 8px; }
        .tombol-hapus:hover { border-color: var(--merah); background: var(--merah-muda); }
        table.kas tr.b td.p { color: var(--redup); padding-left: 18px; }
        table.kas td.i { color: var(--redup); font-size: 11px; white-space: nowrap; }
        table.kas i { font-style: normal; display: inline-block; font-size: 11px; padding: 0 5px; border-radius: 4px; background: var(--latar); color: var(--redup); }
        table.kas i.x { background: var(--merah-muda); color: var(--merah); }
        input[type=search] { padding: 8px 10px; border: 1px solid var(--isian-garis); border-radius: 8px; font-size: 14px; min-width: 260px; }
        input[type=search]:focus { outline: none; border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen-muda); }
        /* Penampil foto bon (public/js/penampil-foto.js): zoom & geser seperti Photoshop. */
        .penampil { display: flex; flex-direction: column; border: 1px solid var(--garis); border-radius: 10px; overflow: hidden; background: #0a0b0d; height: 70vh; min-height: 320px; }
        .penampil:fullscreen { height: 100vh; border-radius: 0; }
        .penampil.kosong img { display: none; }
        .penampil.kosong .penampil-kanvas::after { content: 'Belum ada foto — klik "📷 Pilih / ambil foto"'; color: #9aa5b1; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 14px; }
        .penampil-alat { display: flex; align-items: center; gap: 4px; padding: 6px 8px; background: #15171b; }
        .penampil-alat button { background: #2a2e36; color: #fff; border: 0; border-radius: 6px; min-width: 32px; padding: 5px 9px; font-size: 14px; cursor: pointer; }
        .penampil-alat button:hover { background: var(--aksen); }
        .penampil-persen { color: #cbd2d9; font-size: 12px; min-width: 44px; text-align: center; font-variant-numeric: tabular-nums; }
        .penampil-alat [data-aksi=layar] { margin-left: auto; }
        .penampil-kanvas { position: relative; flex: 1; overflow: hidden; cursor: grab; touch-action: none; outline: none; }
        .penampil-kanvas.menyeret { cursor: grabbing; }
        .penampil-kanvas img { position: absolute; left: 0; top: 0; transform-origin: 0 0; max-width: none; user-select: none; -webkit-user-drag: none; }
        .penampil-petunjuk { font-size: 11px; color: #9aa5b1; background: #15171b; padding: 3px 8px; }
        .gambar-kecil { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .gambar-kecil button { position: relative; padding: 0; border: 2px solid transparent; border-radius: 6px; background: none; cursor: pointer; }
        .gambar-kecil button.aktif { border-color: var(--aksen); }
        .gambar-kecil img { width: 64px; height: 64px; object-fit: cover; border-radius: 4px; display: block; }
        .lampiran { text-decoration: none; font-size: 12px; white-space: nowrap; padding: 2px 6px; border-radius: 6px; }
        .lampiran.ada { background: var(--aksen-muda); color: var(--aksen-terang); }
        .lampiran.kosong { color: var(--redup); opacity: .55; }
        .lampiran:hover { opacity: 1; background: var(--kartu-2); }
        .kartu { background: var(--kartu); border: 1px solid var(--garis); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        .kartu h3 { color: #fff; }
        .ringkas .kartu { border-top: 2px solid var(--aksen); }
        .pesan { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; border: 1px solid transparent; }
        .pesan.sukses { background: var(--sukses-muda); color: var(--sukses); border-color: rgba(76, 195, 138, .3); }
        .pesan.galat { background: var(--merah-muda); color: var(--merah); border-color: rgba(255, 92, 97, .3); }
        .tombol { display: inline-block; background: var(--aksen); color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 15px; text-decoration: none; cursor: pointer; }
        .tombol:hover { background: #f05358; }
        .tombol:disabled { background: #4a2a2c; color: #a8a0a1; cursor: not-allowed; }
        .tombol.polos { background: none; color: var(--teks); border: 1px solid var(--garis-kuat); padding: 6px 12px; font-size: 13px; }
        .tombol.polos:hover { border-color: var(--aksen); color: var(--aksen-terang); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--garis); }
        th { color: var(--redup); font-weight: 600; font-size: 13px; }
        .redup { color: var(--redup); font-size: 13px; }
        a { color: var(--aksen-terang); }
        code { background: var(--kartu-2); padding: 1px 5px; border-radius: 4px; }
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: var(--latar); }
        ::-webkit-scrollbar-thumb { background: #2f333c; border-radius: 6px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--aksen); }
    </style>
</head>
<body>
    @auth
        <header>
            <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                <div><strong>MNP</strong> · PT Multi Niaga Putra</div>
                <nav>
                    @foreach (['dashboard' => 'Beranda', 'kas.input' => 'Input Kas', 'kas.index' => 'Kas Harian', 'uj.input' => 'Input UJ', 'uj.index' => 'Kas UJ', 'reimburse.index' => 'Reimburse UJ','kas.rekap' => 'Rekap Biaya', 'kas.kode-gl' => 'Kode GL'] as $rute => $nama)
                        <a href="{{ route($rute) }}" @class(['aktif' => request()->routeIs($rute)])>{{ $nama }}</a>
                    @endforeach
                    @can('super-admin')
                        <a href="{{ route('pengguna.index') }}" @class(['aktif' => request()->routeIs('pengguna.*')])>Pengguna</a>
                    @endcan
                </nav>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <span class="redup">{{ auth()->user()->email }}</span>
                <button class="tombol polos" type="submit">Keluar</button>
            </form>
        </header>
    @endauth
    <main>
        @if (session('success'))
            <div class="pesan sukses">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="pesan galat">{{ session('error') }}</div>
        @endif
        @yield('isi')
    </main>
</body>
</html>
