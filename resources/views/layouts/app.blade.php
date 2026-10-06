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
    <title>{{ $judul ?? 'MNP' }} · PT Multi Niaga Putra</title>
    <style>
        :root { --latar: #f5f6f8; --kartu: #fff; --teks: #1f2933; --redup: #616e7c; --garis: #e4e7eb; --aksen: #0b6e4f; --merah: #b42318; --hijau-muda: #e6f4ee; --merah-muda: #fdecea; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--latar); color: var(--teks); font-size: 15px; }
        header { background: var(--kartu); border-bottom: 1px solid var(--garis); padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; }
        header strong { color: var(--aksen); }
        main { max-width: @yield('lebar', '960px'); margin: 24px auto; padding: 0 16px; }
        header nav { display: flex; gap: 4px; flex-wrap: wrap; }
        header nav a { color: var(--redup); text-decoration: none; padding: 6px 10px; border-radius: 6px; font-size: 14px; }
        header nav a.aktif, header nav a:hover { background: var(--hijau-muda); color: var(--aksen); }
        .chip { display: inline-block; padding: 4px 10px; border: 1px solid var(--garis); border-radius: 999px; font-size: 13px; color: var(--teks); text-decoration: none; margin: 0 4px 6px 0; background: var(--kartu); }
        .chip.aktif { background: var(--aksen); border-color: var(--aksen); color: #fff; }
        .angka { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .ringkas .kartu { margin: 0; padding: 14px 16px; }
        .ringkas b { display: block; font-size: 20px; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .gulir { overflow-x: auto; }
        .label { display: inline-block; font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--latar); color: var(--redup); }
        .label.merah { background: var(--merah-muda); color: var(--merah); }
        .label.hijau { background: var(--hijau-muda); color: var(--aksen); }
        table.kas { font-size: 13px; }
        table.kas td, table.kas th { padding: 6px; }
        table.kas td:first-child, table.kas th:first-child { padding-left: 14px; }
        table.kas td:last-child, table.kas th:last-child { padding-right: 14px; }
        /* Transfer (master) terang, rincian bon (detail) lebih gelap supaya kontras. */
        table.kas tr.t { background: #fff; }
        table.kas tr.t.m { background: #e6f4ea; }
        table.kas tr.t td { border-top: 2px solid #c5ccd3; border-bottom: 0; }
        table.kas tr.b { background: #e4e9ee; }
        table.kas tr.b td { color: var(--teks); border-bottom: 1px solid #d3dae1; }
        table.kas tr.b:hover { background: #d8dfe6; }
        table.kas tr.t:hover { background: #f5f7f9; }
        table.kas tr.t.m:hover { background: #d9efe0; }
        table.kas tr.b i { background: #fff; }
        /* Bon tertutup kecuali grupnya dibuka; saat tertutup, kolom Kode GL transfer berisi ringkasan akun bon. */
        table.kas tbody.grup:not(.buka) tr.b { display: none; }
        table.kas tbody.grup.buka .ringkas-gl { visibility: hidden; }
        table.kas .ringkas-gl { color: var(--redup); font-size: 12px; }
        table.kas tr.t.ada-bon { cursor: pointer; }
        table.kas .panah { display: inline-block; width: 12px; color: var(--redup); transition: transform .15s; }
        table.kas tbody.grup.buka .panah { transform: rotate(90deg); }
        table.kas .jumlah-bon { display: inline-block; min-width: 18px; padding: 0 5px; margin-left: 4px; border-radius: 9px; background: var(--latar);
            color: var(--redup); font-size: 11px; text-align: center; }
        table.kas td:first-child { white-space: nowrap; }
        .tombol-edit { font-size: 12px; padding: 2px 8px; border-radius: 6px; color: var(--aksen); text-decoration: none; border: 1px solid transparent; }
        .tombol-edit:hover { border-color: var(--aksen); background: var(--hijau-muda); }
        .tombol-hapus { background: none; border: 1px solid transparent; border-radius: 6px; color: var(--merah); cursor: pointer; font-size: 12px; padding: 2px 8px; }
        .tombol-hapus:hover { border-color: var(--merah); background: var(--merah-muda); }
        table.kas tr.b td.p { color: var(--redup); padding-left: 18px; }
        table.kas td.i { color: var(--redup); font-size: 11px; white-space: nowrap; }
        table.kas i { font-style: normal; display: inline-block; font-size: 11px; padding: 0 5px; border-radius: 4px; background: var(--latar); color: var(--redup); }
        table.kas i.x { background: var(--merah-muda); color: var(--merah); }
        input[type=search] { padding: 8px 10px; border: 1px solid var(--garis); border-radius: 8px; font-size: 14px; min-width: 260px; }
        /* Penampil foto bon (public/js/penampil-foto.js): zoom & geser seperti Photoshop. */
        .penampil { display: flex; flex-direction: column; border: 1px solid var(--garis); border-radius: 10px; overflow: hidden; background: #2a3440; height: 70vh; min-height: 320px; }
        .penampil:fullscreen { height: 100vh; border-radius: 0; }
        .penampil.kosong img { display: none; }
        .penampil.kosong .penampil-kanvas::after { content: 'Belum ada foto — klik "📷 Pilih / ambil foto"'; color: #9aa5b1; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 14px; }
        .penampil-alat { display: flex; align-items: center; gap: 4px; padding: 6px 8px; background: #1f2933; }
        .penampil-alat button { background: #3e4c59; color: #fff; border: 0; border-radius: 6px; min-width: 32px; padding: 5px 9px; font-size: 14px; cursor: pointer; }
        .penampil-alat button:hover { background: #52606d; }
        .penampil-persen { color: #cbd2d9; font-size: 12px; min-width: 44px; text-align: center; font-variant-numeric: tabular-nums; }
        .penampil-alat [data-aksi=layar] { margin-left: auto; }
        .penampil-kanvas { position: relative; flex: 1; overflow: hidden; cursor: grab; touch-action: none; outline: none; }
        .penampil-kanvas.menyeret { cursor: grabbing; }
        .penampil-kanvas img { position: absolute; left: 0; top: 0; transform-origin: 0 0; max-width: none; user-select: none; -webkit-user-drag: none; }
        .penampil-petunjuk { font-size: 11px; color: #9aa5b1; background: #1f2933; padding: 3px 8px; }
        .gambar-kecil { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .gambar-kecil button { position: relative; padding: 0; border: 2px solid transparent; border-radius: 6px; background: none; cursor: pointer; }
        .gambar-kecil button.aktif { border-color: var(--aksen); }
        .gambar-kecil img { width: 64px; height: 64px; object-fit: cover; border-radius: 4px; display: block; }
        .lampiran { text-decoration: none; font-size: 12px; white-space: nowrap; padding: 2px 6px; border-radius: 6px; }
        .lampiran.ada { background: var(--hijau-muda); color: var(--aksen); }
        .lampiran.kosong { color: var(--redup); opacity: .55; }
        .lampiran:hover { opacity: 1; background: var(--latar); }
        .kartu { background: var(--kartu); border: 1px solid var(--garis); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        .pesan { padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
        .pesan.sukses { background: var(--hijau-muda); color: var(--aksen); }
        .pesan.galat { background: var(--merah-muda); color: var(--merah); }
        .tombol { display: inline-block; background: var(--aksen); color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 15px; text-decoration: none; cursor: pointer; }
        .tombol.polos { background: none; color: var(--redup); border: 1px solid var(--garis); padding: 6px 12px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--garis); }
        th { color: var(--redup); font-weight: 600; font-size: 13px; }
        .redup { color: var(--redup); font-size: 13px; }
        a { color: var(--aksen); }
    </style>
</head>
<body>
    @auth
        <header>
            <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                <div><strong>MNP</strong> · PT Multi Niaga Putra</div>
                <nav>
                    @foreach (['dashboard' => 'Beranda', 'kas.input' => 'Input Kas', 'kas.index' => 'Kas Harian', 'uj.input' => 'Input UJ', 'uj.index' => 'Kas UJ', 'kas.rekap' => 'Rekap Biaya', 'kas.kode-gl' => 'Kode GL'] as $rute => $nama)
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
