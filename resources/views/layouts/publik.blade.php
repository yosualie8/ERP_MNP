<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ config('mnp.nama_aplikasi') }}: aplikasi internal {{ config('mnp.nama_perusahaan') }} untuk kas harian dan biaya operasional.">
    <title>@hasSection('judul')@yield('judul') · @endif{{ config('mnp.nama_aplikasi') }}</title>
    <style>
        :root { --teks: #1f2933; --redup: #616e7c; --garis: #e4e7eb; --aksen: #0b6e4f; --aksen-muda: #e6f4ee; --gelap: #102a43; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--teks); background: #fff; line-height: 1.6; }
        a { color: var(--aksen); }
        .bungkus { max-width: 1040px; margin: 0 auto; padding: 0 20px; }
        header { border-bottom: 1px solid var(--garis); }
        header .bungkus { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding-top: 14px; padding-bottom: 14px; }
        .merek { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--teks); }
        .logo { width: 36px; height: 36px; border-radius: 8px; background: var(--aksen); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; }
        .merek b { display: block; line-height: 1.2; }
        .merek small { color: var(--redup); font-size: 12px; }
        nav { display: flex; align-items: center; gap: 18px; font-size: 14px; }
        nav a { color: var(--redup); text-decoration: none; }
        nav a:hover { color: var(--teks); }
        .tombol { display: inline-block; background: var(--gelap); color: #fff !important; padding: 9px 16px; border-radius: 7px; text-decoration: none; font-weight: 500; }
        .dokumen { max-width: 760px; margin: 40px auto 64px; }
        .dokumen h1 { font-size: 30px; margin: 0 0 4px; }
        .dokumen h2 { font-size: 18px; margin: 28px 0 8px; }
        .dokumen li { margin-bottom: 6px; }
        .redup { color: var(--redup); font-size: 14px; }
        footer { border-top: 1px solid var(--garis); font-size: 14px; color: var(--redup); }
        footer .bungkus { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; padding-top: 20px; padding-bottom: 20px; }
        footer nav { gap: 16px; }
        @media (max-width: 640px) { nav .sembunyi-hp { display: none; } }
    </style>
    @stack('gaya')
</head>
<body>
    <header>
        <div class="bungkus">
            <a href="{{ route('beranda') }}" class="merek">
                <span class="logo" aria-hidden="true">MNP</span>
                <span><b>{{ config('mnp.nama_aplikasi') }}</b><small>{{ config('mnp.nama_perusahaan') }}</small></span>
            </a>
            <nav>
                <a href="{{ route('privasi') }}" class="sembunyi-hp">Kebijakan Privasi</a>
                <a href="{{ route('syarat') }}" class="sembunyi-hp">Syarat Layanan</a>
                <a href="{{ route('login') }}" class="tombol">Login</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('isi')
    </main>

    <footer>
        <div class="bungkus">
            <span>&copy; {{ date('Y') }} {{ config('mnp.nama_perusahaan') }}</span>
            <nav>
                <a href="{{ route('privasi') }}">Kebijakan Privasi</a>
                <a href="{{ route('syarat') }}">Syarat Layanan</a>
                <a href="mailto:{{ config('mnp.email_kontak') }}">Kontak</a>
            </nav>
        </div>
    </footer>
</body>
</html>
