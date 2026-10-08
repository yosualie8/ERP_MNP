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
        header { background: #121317; border-bottom: 2px solid var(--aksen); padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; position: sticky; top: 0; z-index: 30; }
        header strong { color: var(--aksen-terang); }
        main { max-width: @yield('lebar', '960px'); margin: 24px auto; padding: 0 16px; }
        /* Sidebar: menu per kategori, tiap kategori bisa dibuka/ditutup; sidebar sendiri bisa disembunyikan (☰). */
        .tata { display: flex; align-items: flex-start; }
        .isi-utama { flex: 1; min-width: 0; }
        aside.samping { width: 228px; flex: none; position: sticky; top: 52px; height: calc(100vh - 52px); overflow-y: auto; background: #101114; border-right: 1px solid var(--garis); padding: 12px 10px 24px; }
        body.samping-tutup aside.samping { display: none; }
        .tombol-samping { background: none; border: 1px solid var(--garis); color: var(--teks); border-radius: 7px; font-size: 17px; line-height: 1; padding: 6px 9px; cursor: pointer; }
        .tombol-samping:hover { border-color: var(--aksen); color: var(--aksen-terang); }
        aside.samping a.menu { display: block; color: var(--redup); text-decoration: none; padding: 7px 10px 7px 34px; border-radius: 7px; font-size: 14px; margin: 1px 0; }
        aside.samping a.menu.atas { padding-left: 10px; color: var(--teks); }
        aside.samping a.menu:hover { background: var(--kartu-2); color: var(--teks); }
        aside.samping a.menu.aktif { background: var(--aksen-muda); color: var(--aksen-terang); font-weight: 600; box-shadow: inset 3px 0 0 var(--aksen); }
        aside.samping details { margin-top: 6px; }
        aside.samping summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 7px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #aeb4bf; user-select: none; }
        aside.samping summary::-webkit-details-marker { display: none; }
        aside.samping summary:hover { background: var(--kartu-2); color: var(--teks); }
        aside.samping summary .panah { margin-left: auto; transition: transform .15s; font-size: 10px; color: var(--redup); }
        aside.samping details[open] > summary .panah { transform: rotate(90deg); }
        aside.samping details.ada-aktif > summary { color: var(--aksen-terang); }
        aside.samping .jumlah { font-size: 10px; color: var(--redup); font-weight: 400; letter-spacing: 0; text-transform: none; }
        @media (max-width: 900px) {
            aside.samping { position: fixed; left: 0; top: 52px; z-index: 25; box-shadow: 6px 0 24px rgba(0, 0, 0, .5); }
            body:not(.samping-buka) aside.samping { display: none; }
            body.samping-buka.samping-tutup aside.samping { display: block; }
            header .surel { display: none; }
        }
        .chip { display: inline-block; padding: 4px 10px; border: 1px solid var(--garis); border-radius: 999px; font-size: 13px; color: var(--teks); text-decoration: none; margin: 0 4px 6px 0; background: var(--kartu); }
        .chip.aktif { background: var(--aksen); border-color: var(--aksen); color: #fff; }
        /* Pemilih periode: Tahun · Jan–Des · Bulan mulai/akhir (partials/periode). */
        .periode { display: flex; align-items: flex-end; gap: 10px 14px; flex-wrap: wrap; margin-bottom: 12px; }
        .periode-isian { display: flex; flex-direction: column; gap: 3px; font-size: 12px; color: var(--redup); }
        .periode select { padding: 7px 10px; border-radius: 7px; font-size: 14px; min-width: 92px; border: 1px solid var(--garis-kuat); }
        .periode select:focus { outline: none; border-color: var(--aksen); }
        .periode-bulan { display: flex; flex-wrap: wrap; gap: 4px; }
        .periode-bulan .chip { margin: 0; min-width: 46px; text-align: center; }
        .periode-bulan .chip.dalam { background: var(--aksen-muda); border-color: var(--aksen); color: var(--aksen-terang); }
        .periode-bulan .chip.kosong { opacity: .4; }
        .angka { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .ringkas .kartu { margin: 0; padding: 14px 16px; }
        .ringkas b { display: block; font-size: 20px; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .gulir { overflow-x: auto; }
        .label { display: inline-block; font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--latar); color: var(--redup); }
        .label.merah { background: var(--merah-muda); color: var(--merah); }
        .label.hijau { background: var(--sukses-muda); color: var(--sukses); }
        .label.kuning { background: rgba(224, 165, 38, .16); color: #f0c05a; }
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
        <script>
            // Sidebar disembunyikan? (diingat per browser; dipasang sebelum halaman tampil supaya tidak berkedip)
            try { if (localStorage.getItem('mnp-samping') === 'tutup') document.body.classList.add('samping-tutup'); } catch (e) {}
        </script>
        <header>
            <div style="display: flex; align-items: center; gap: 12px;">
                <button type="button" class="tombol-samping" id="tombol-samping" title="Tampilkan / sembunyikan menu" aria-label="Menu">☰</button>
                <div><strong>MNP</strong> · PT Multi Niaga Putra</div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <span class="redup surel">{{ auth()->user()->email }}</span>
                <button class="tombol polos" type="submit">Keluar</button>
            </form>
        </header>
    @endauth
    <div class="tata">
        @auth
            <aside class="samping" id="samping">
                <a href="{{ route('dashboard') }}" @class(['menu atas', 'aktif' => request()->routeIs('dashboard')])>🏠 Beranda</a>
                {{-- Hanya menu yang diberikan ke akun ini (halaman Pengguna), dikelompokkan per kategori. --}}
                @foreach (\App\Support\MenuAkses::kelompok(auth()->user()) as $kunci => $k)
                    @php($adaAktif = collect($k['menu'])->contains(fn ($m) => request()->routeIs(...$m[2])))
                    <details data-kategori="{{ $kunci }}" @class(['ada-aktif' => $adaAktif]) @if ($adaAktif) data-aktif open @endif>
                        <summary><span>{{ $k['ikon'] }}</span> {{ $k['judul'] }} <span class="jumlah">{{ count($k['menu']) }}</span><span class="panah">▶</span></summary>
                        @foreach ($k['menu'] as [$nama, $rute, $pola])
                            <a href="{{ route($rute) }}" @class(['menu', 'aktif' => request()->routeIs(...$pola)])>{{ $nama }}</a>
                        @endforeach
                    </details>
                @endforeach
                @can('super-admin')
                    @php($adaAktif = request()->routeIs('pengguna.*'))
                    <details data-kategori="pengaturan" @class(['ada-aktif' => $adaAktif]) @if ($adaAktif) data-aktif open @endif>
                        <summary><span>⚙️</span> Pengaturan <span class="jumlah">1</span><span class="panah">▶</span></summary>
                        <a href="{{ route('pengguna.index') }}" @class(['menu', 'aktif' => $adaAktif])>Pengguna</a>
                    </details>
                @endcan
            </aside>
            <script>
                (() => {
                    // Buka/tutup tiap kategori diingat per browser; kategori halaman yang sedang dibuka selalu terbuka.
                    const KUNCI = 'mnp-kategori';
                    let simpan = {};
                    try { simpan = JSON.parse(localStorage.getItem(KUNCI) || '{}'); } catch (e) {}
                    document.querySelectorAll('#samping details').forEach(d => {
                        if (!d.hasAttribute('data-aktif')) d.open = simpan[d.dataset.kategori] ?? true; // belum pernah diatur = terbuka
                        d.addEventListener('toggle', () => { simpan[d.dataset.kategori] = d.open; try { localStorage.setItem(KUNCI, JSON.stringify(simpan)); } catch (e) {} });
                    });
                    document.getElementById('tombol-samping').addEventListener('click', () => {
                        const kecil = matchMedia('(max-width: 900px)').matches;
                        if (kecil) { document.body.classList.toggle('samping-buka'); return; }
                        const tutup = document.body.classList.toggle('samping-tutup');
                        try { localStorage.setItem('mnp-samping', tutup ? 'tutup' : 'buka'); } catch (e) {}
                    });
                })();
            </script>
        @endauth
        <div class="isi-utama">
            <main>
                @if (session('success'))
                    <div class="pesan sukses">{{ session('success') }}</div>
                @endif
                @if (session('error'))
                    <div class="pesan galat">{{ session('error') }}</div>
                @endif
                @yield('isi')
            </main>
        </div>
    </div>
</body>
</html>
