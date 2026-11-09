<!DOCTYPE html>
<html lang="@yield('bahasa', 'en')">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $deskripsi = trim($__env->yieldContent('deskripsi')) ?: 'PT Multi Niaga Putra — heavy equipment rental and earthwork contractor for mining, forestry, agro and construction projects across Indonesia since 2019.';
    @endphp
    <meta name="description" content="{{ $deskripsi }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="@hasSection('judul')@yield('judul') · @endif{{ config('mnp.nama_perusahaan') }}">
    <meta property="og:description" content="{{ $deskripsi }}">
    <meta property="og:image" content="{{ url('profil/armada.webp') }}">
    <meta name="theme-color" content="#d33527">
    <title>@hasSection('judul')@yield('judul') · @endif{{ config('mnp.nama_perusahaan') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('profil/logo.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    {{-- Situs publik multiniagaputra.com (owner 9 Nov 2026): company profile untuk tamu; karyawan masuk ERP lewat tombol "Employee Login" (/login, atau /erp). --}}
    <style>
        :root {
            --merah: #d33527; --merah-tua: #b02a1e; --merah-muda: #fdecea;
            --gelap: #16191d; --gelap-2: #22262c; --teks: #1f2328; --redup: #5b636e; --garis: #e6e8eb; --latar-2: #f6f7f8;
            --judul: "Poppins", system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; scroll-padding-top: 76px; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 16px; color: var(--teks); background: #fff; line-height: 1.65; -webkit-font-smoothing: antialiased; }
        img { max-width: 100%; display: block; }
        a { color: var(--merah-tua); }
        h1, h2, h3 { font-family: var(--judul); line-height: 1.2; color: var(--gelap); }
        .bungkus { max-width: 1160px; margin: 0 auto; padding: 0 20px; }
        :focus-visible { outline: 3px solid var(--merah); outline-offset: 2px; }

        /* Kepala */
        .kepala { position: sticky; top: 0; z-index: 20; background: rgba(255, 255, 255, .96); backdrop-filter: saturate(1.4) blur(8px); border-bottom: 1px solid var(--garis); }
        .kepala .bungkus { display: flex; align-items: center; justify-content: space-between; gap: 10px; height: 68px; }
        .merek { display: flex; align-items: center; gap: 10px; text-decoration: none; color: var(--gelap); flex: none; }
        .merek img { width: 40px; height: 35px; }
        .merek b { font-family: var(--judul); font-size: 17px; font-weight: 700; letter-spacing: -.01em; display: block; line-height: 1.15; }
        .merek small { display: block; font-size: 11.5px; color: var(--redup); letter-spacing: .06em; text-transform: uppercase; }
        .menu { display: flex; align-items: center; gap: 4px; }
        .menu a { color: var(--teks); text-decoration: none; font-size: 15px; font-weight: 500; padding: 10px 12px; border-radius: 8px; }
        .menu a:hover { color: var(--merah-tua); background: var(--merah-muda); }
        .tombol { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 10px 20px; border-radius: 10px; font-weight: 600; font-size: 15px; text-decoration: none; border: 1px solid transparent; cursor: pointer; transition: background .2s, border-color .2s, color .2s; }
        .tombol.utama { background: var(--merah); color: #fff; }
        .tombol.utama:hover { background: var(--merah-tua); }
        .tombol.garis { border-color: rgba(255, 255, 255, .6); color: #fff; }
        .tombol.garis:hover { background: rgba(255, 255, 255, .12); }
        .tombol.gelap { background: var(--gelap); color: #fff; }
        .tombol.gelap:hover { background: var(--gelap-2); }
        .menu .tombol { margin-left: 8px; padding: 9px 16px; color: #fff; }
        .menu .tombol:hover { color: #fff; background: var(--gelap-2); }
        .login-hp { display: none; margin-left: auto; align-items: center; gap: 6px; min-height: 44px; padding: 0 14px; border-radius: 10px; background: var(--gelap); color: #fff; text-decoration: none; font-weight: 600; font-size: 14px; }
        .buka-menu { display: none; background: none; border: 1px solid var(--garis); border-radius: 10px; width: 44px; height: 44px; cursor: pointer; color: var(--gelap); }
        @media (max-width: 920px) {
            .login-hp { display: inline-flex; }
            .buka-menu { display: inline-flex; align-items: center; justify-content: center; }
            .menu { display: none; position: absolute; top: 68px; left: 0; right: 0; background: #fff; border-bottom: 1px solid var(--garis); flex-direction: column; align-items: stretch; padding: 8px 16px 16px; box-shadow: 0 12px 24px rgba(0, 0, 0, .08); }
            .menu.buka { display: flex; }
            .menu a { padding: 12px; }
            .menu .tombol { display: none; }
        }

        @media (max-width: 460px) { .merek small { display: none; } .merek b { font-size: 16px; } }

        /* Dokumen (Kebijakan Privasi, Syarat Layanan) */
        .dokumen { max-width: 760px; margin: 40px auto 64px; }
        .dokumen h1 { font-size: 30px; margin: 0 0 4px; }
        .dokumen h2 { font-size: 18px; margin: 28px 0 8px; }
        .dokumen li { margin-bottom: 6px; }
        .redup { color: var(--redup); font-size: 14px; }

        /* Kaki */
        .kaki { background: var(--gelap); color: #c4cad2; font-size: 14.5px; }
        .kaki a { color: #fff; text-decoration: none; }
        .kaki a:hover { text-decoration: underline; }
        .kaki .atas { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 32px; padding: 48px 20px 32px; }
        .kaki h3 { color: #fff; font-size: 15px; margin: 0 0 12px; }
        .kaki ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
        .kaki .merek b { color: #fff; }
        .kaki .merek small { color: #9aa3ad; }
        .kaki .merek img { filter: none; }
        .kaki .erp { margin-top: 14px; font-size: 13.5px; color: #9aa3ad; max-width: 420px; }
        .kaki .bawah { border-top: 1px solid #2c3138; }
        .kaki .bawah .bungkus { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; padding-top: 18px; padding-bottom: 18px; font-size: 13.5px; color: #9aa3ad; }
        @media (max-width: 760px) { .kaki .atas { grid-template-columns: 1fr; gap: 24px; } }
        @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } * { transition: none !important; animation: none !important; } }
    </style>
    @stack('gaya')
</head>
<body>
    <header class="kepala">
        <div class="bungkus">
            <a href="{{ route('beranda') }}" class="merek" aria-label="{{ config('mnp.nama_perusahaan') }} — home">
                <img src="{{ asset('profil/logo.svg') }}" alt="" width="40" height="35">
                <span><b>Multi Niaga Putra</b><small>Heavy Equipment &amp; Contractor</small></span>
            </a>
            <a href="{{ route('login') }}" class="login-hp" aria-label="Employee Login">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                Login
            </a>
            <button type="button" class="buka-menu" aria-label="Open menu" aria-expanded="false" aria-controls="menu-utama">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
            <nav class="menu" id="menu-utama" aria-label="Main">
                <a href="{{ route('beranda') }}#about">About</a>
                <a href="{{ route('beranda') }}#services">Services</a>
                <a href="{{ route('beranda') }}#milestones">Milestones</a>
                <a href="{{ route('beranda') }}#projects">Projects</a>
                <a href="{{ route('beranda') }}#contact">Contact</a>
                <a href="{{ route('login') }}" class="tombol gelap" title="Internal application (MNP ERP) for registered employees">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    Employee Login
                </a>
            </nav>
        </div>
    </header>

    <main>
        @yield('isi')
    </main>

    <footer class="kaki">
        <div class="bungkus atas">
            <div>
                <a href="{{ route('beranda') }}" class="merek">
                    <img src="{{ asset('profil/logo-putih.svg') }}" alt="" width="40" height="35">
                    <span><b>{{ config('mnp.nama_perusahaan') }}</b><small>Since 2019</small></span>
                </a>
                <p class="erp">
                    <b style="color:#fff">{{ config('mnp.nama_aplikasi') }}</b> is our internal application for registered employees — daily cash, equipment
                    and project operations. Sign in with a Google account registered by the administrator.
                    <a href="{{ route('login') }}">Employee Login →</a>
                </p>
            </div>
            <div>
                <h3>Company</h3>
                <ul>
                    <li><a href="{{ route('beranda') }}#about">About us</a></li>
                    <li><a href="{{ route('beranda') }}#services">Services</a></li>
                    <li><a href="{{ route('beranda') }}#projects">Project list</a></li>
                    <li><a href="{{ route('beranda') }}#permits">Licenses &amp; permits</a></li>
                </ul>
            </div>
            <div>
                <h3>Contact</h3>
                <ul>
                    <li>Jl. Raya Gading Batavia Blok LC-11 No. 8, Kelapa Gading, Jakarta Utara</li>
                    <li><a href="mailto:adm_sales@multiniagaputra.com">adm_sales@multiniagaputra.com</a></li>
                    <li><a href="mailto:ptmultiniagaputra@gmail.com">ptmultiniagaputra@gmail.com</a></li>
                </ul>
            </div>
        </div>
        <div class="bawah">
            <div class="bungkus">
                <span>&copy; {{ date('Y') }} {{ config('mnp.nama_perusahaan') }}</span>
                <span><a href="{{ route('privasi') }}">Privacy Policy</a> &nbsp;·&nbsp; <a href="{{ route('syarat') }}">Terms of Service</a></span>
            </div>
        </div>
    </footer>

    <script>
        (() => {
            const b = document.querySelector('.buka-menu'), m = document.getElementById('menu-utama');
            b.addEventListener('click', () => { const buka = m.classList.toggle('buka'); b.setAttribute('aria-expanded', buka); });
            m.addEventListener('click', e => { if (e.target.closest('a')) { m.classList.remove('buka'); b.setAttribute('aria-expanded', false); } });
        })();
    </script>
    @stack('skrip')
</body>
</html>
