<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f1115">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Rumah">
    <link rel="manifest" href="/rumah-aset/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/rumah-aset/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="/rumah-aset/ikon-192.png">
    <title>Rumah</title>
    <style>
        :root {
            --latar: #0f1115; --kartu: #1a1d24; --kartu2: #232733; --garis: #2c313d;
            --teks: #eef0f4; --redup: #8b93a5; --hangat: #ffc864; --hijau: #4cd98a; --merah: #ff6b6b;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin: 0; background: var(--latar); color: var(--teks);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { padding: max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
            min-height: 100vh; user-select: none; -webkit-user-select: none; }
        .atas { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 4px 2px 18px; }
        .atas h1 { font-size: 28px; margin: 0; letter-spacing: -.5px; }
        .atas .sub { color: var(--redup); font-size: 14px; margin-top: 2px; }
        .titik { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--redup); margin-right: 6px; vertical-align: 1px; }
        .titik.ok { background: var(--hijau); } .titik.galat { background: var(--merah); }
        .tombol-kecil { background: var(--kartu); color: var(--teks); border: 1px solid var(--garis); border-radius: 12px;
            padding: 10px 14px; font-size: 15px; cursor: pointer; text-decoration: none; }
        .tombol-kecil:active { transform: scale(.97); }

        .skenario { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 22px; }
        .skenario button { border: none; border-radius: 16px; padding: 16px; font-size: 16px; font-weight: 600; cursor: pointer;
            display: flex; align-items: center; gap: 10px; justify-content: center; color: var(--teks); background: var(--kartu); }
        .skenario button.terang { background: linear-gradient(135deg, #ffcf70, #ffa94d); color: #2a1a00; }
        .skenario button:active { transform: scale(.97); }

        h2 { font-size: 15px; font-weight: 600; color: var(--redup); text-transform: uppercase; letter-spacing: .8px; margin: 20px 4px 10px; }
        .kisi { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
        @media (min-width: 900px) { .kisi { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); } }

        .ubin { position: relative; background: var(--kartu); border-radius: 22px; padding: 16px; min-height: 140px; cursor: pointer;
            display: flex; flex-direction: column; justify-content: space-between; transition: background .2s, transform .08s; overflow: hidden; }
        .ubin:active { transform: scale(.97); }
        .ubin.nyala { background: var(--kartu2); }
        .ubin .ikon { width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center; font-size: 24px;
            background: #2a2e39; transition: background .2s, box-shadow .2s; }
        .ubin.nyala .ikon { background: var(--warna, var(--hangat)); box-shadow: 0 0 28px var(--warna, var(--hangat)); }
        .ubin .nama { font-size: 17px; font-weight: 600; margin-top: 14px; line-height: 1.2; }
        .ubin .ket { font-size: 14px; color: var(--redup); margin-top: 3px; }
        .ubin.mati-online .ket, .ubin.offline .ket { color: var(--redup); }
        .ubin.offline { opacity: .45; }
        .ubin .lebih { position: absolute; top: 12px; right: 12px; width: 36px; height: 36px; border-radius: 50%; border: none;
            background: rgba(255,255,255,.06); color: var(--teks); font-size: 18px; cursor: pointer; }
        .ubin .tekan { margin-top: 10px; border: none; border-radius: 12px; padding: 10px; font-size: 15px; font-weight: 600;
            background: var(--hangat); color: #2a1a00; cursor: pointer; }
        .ubin .tekan:active { transform: scale(.95); }
        .ubin.sibuk::after { content: ""; position: absolute; inset: 0; background: rgba(255,255,255,.04); animation: kedip .8s infinite; }
        @keyframes kedip { 50% { opacity: .2; } }

        .bawah { margin-top: 26px; color: var(--redup); font-size: 13px; text-align: center; line-height: 1.7; }

        /* Lembar rincian lampu */
        .tirai { position: fixed; inset: 0; background: rgba(0,0,0,.55); display: none; align-items: flex-end; justify-content: center; z-index: 10; }
        .tirai.buka { display: flex; }
        .lembar { width: 100%; max-width: 520px; background: #171a21; border-radius: 26px 26px 0 0; padding: 22px 20px max(26px, env(safe-area-inset-bottom));
            animation: naik .18s ease-out; }
        @media (min-width: 700px) { .tirai { align-items: center; } .lembar { border-radius: 26px; } }
        @keyframes naik { from { transform: translateY(40px); opacity: 0; } }
        .lembar .kepala { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .lembar h3 { margin: 0; font-size: 22px; }
        .saklar { width: 64px; height: 36px; border-radius: 18px; background: #3a3f4c; border: none; position: relative; cursor: pointer; transition: background .2s; }
        .saklar::after { content: ""; position: absolute; top: 3px; left: 3px; width: 30px; height: 30px; border-radius: 50%; background: #fff; transition: left .2s; }
        .saklar.nyala { background: var(--hijau); } .saklar.nyala::after { left: 31px; }
        .label { display: flex; justify-content: space-between; font-size: 15px; color: var(--redup); margin: 18px 2px 8px; }
        input[type=range] { -webkit-appearance: none; appearance: none; width: 100%; height: 44px; border-radius: 14px; outline: none; margin: 0; }
        input[type=range]::-webkit-slider-thumb { -webkit-appearance: none; width: 30px; height: 44px; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.4); }
        input[type=range]::-moz-range-thumb { width: 30px; height: 44px; border-radius: 12px; background: #fff; border: none; }
        #r-terang { background: linear-gradient(90deg, #2a2e39, #fff3d6); }
        #r-suhu { background: linear-gradient(90deg, #ffb35c, #fff6e8 50%, #cfe3ff); }
        .warna-warna { display: grid; grid-template-columns: repeat(8, 1fr); gap: 8px; }
        .warna-warna button { aspect-ratio: 1; border-radius: 50%; border: 3px solid transparent; cursor: pointer; }
        .warna-warna button.pilih { border-color: #fff; }
        .tutup { width: 100%; margin-top: 22px; padding: 14px; border-radius: 14px; border: none; background: #2a2e39; color: var(--teks); font-size: 16px; cursor: pointer; }

        .pesan { position: fixed; left: 50%; bottom: max(24px, env(safe-area-inset-bottom)); transform: translateX(-50%); background: #3a1f22; color: #ffd6d6;
            padding: 12px 16px; border-radius: 14px; font-size: 15px; display: none; z-index: 20; max-width: 90vw; }
        .ingat { background: #3a2a12; color: #ffd99a; border-radius: 16px; padding: 14px 16px; margin-bottom: 18px; line-height: 1.5; font-size: 15px; }
        .ingat.habis { background: #3a1f22; color: #ffd6d6; }
        .masa { margin-top: 18px; background: var(--kartu); border-radius: 16px; padding: 14px 16px; font-size: 14px; color: var(--redup); line-height: 1.5; }
        .masa form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
        .masa input[type=date] { background: #0f1115; color: var(--teks); border: 1px solid var(--garis); border-radius: 10px; padding: 9px 10px; font-size: 15px; }
        .ubin.listrik { grid-column: span 2; min-height: 170px; }
        .ubin .watt { font-size: 34px; font-weight: 700; letter-spacing: -.5px; margin-top: 8px; font-variant-numeric: tabular-nums; }
        .ubin .watt small { font-size: 16px; font-weight: 600; color: var(--redup); margin-left: 3px; }
        .ubin .rinci-listrik { font-size: 13px; color: var(--redup); font-variant-numeric: tabular-nums; }
        .ubin canvas.grafik { width: 100%; height: 56px; display: block; margin-top: 8px; }
        .kosong { background: var(--kartu); border-radius: 18px; padding: 22px; color: var(--redup); line-height: 1.6; }
    </style>
</head>
<body>
    <div class="atas">
        <div>
            <h1>Rumah</h1>
            <div class="sub"><span class="titik" id="titik"></span><span id="status-teks">Menyambung…</span></div>
        </div>
        <a class="tombol-kecil" href="{{ route('dashboard') }}">ERP</a>
    </div>

    @if (! $siap)
        <div class="kosong">Kunci Tuya belum diisi di server (<code>TUYA_CLIENT_ID</code> &amp; <code>TUYA_SECRET</code> di <code>.env</code>).</div>
    @else
        @if ($masa['ingatkan'])
            <div class="ingat {{ ($masa['sisa_hari'] ?? 0) < 0 ? 'habis' : '' }}">
                @if (! $masa['tanggal'])
                    ⏰ Tanggal habis langganan <b>Tuya IoT Core</b> belum diisi. Lihat di platform.tuya.com → Cloud → project Anda (masa berlaku IoT Core), lalu isi di bagian bawah halaman ini.
                @elseif ($masa['sisa_hari'] < 0)
                    ⛔ Langganan Tuya IoT Core <b>sudah habis</b> sejak {{ $masa['tanggal']->translatedFormat('j F Y') }}. Perpanjang di platform.tuya.com, lalu ubah tanggalnya di bawah.
                @else
                    ⏰ Langganan Tuya IoT Core habis <b>{{ $masa['tanggal']->translatedFormat('j F Y') }}</b> — <b>{{ $masa['sisa_hari'] }} hari lagi</b>. Perpanjang di platform.tuya.com (IoT Core → Extend), lalu ubah tanggalnya di bawah.
                @endif
            </div>
        @endif
        <div class="skenario">
            <button type="button" class="terang" data-semua="1">💡 Semua lampu nyala</button>
            <button type="button" data-semua="0">🌙 Semua lampu mati</button>
        </div>
        <div id="isi"></div>
        <div class="bawah" id="bawah"></div>
        <div class="masa">
            Langganan Tuya IoT Core berlaku sampai
            <b>{{ $masa['tanggal'] ? $masa['tanggal']->translatedFormat('j F Y') : 'belum diisi' }}</b>{{ $masa['sisa_hari'] !== null && $masa['sisa_hari'] >= 0 ? ' ('.$masa['sisa_hari'].' hari lagi)' : '' }}.
            Pengingat muncul di sini &amp; di Beranda ERP mulai {{ \App\Support\Rumah::HARI_INGATKAN }} hari sebelum habis.
            <form method="POST" action="{{ route('rumah.masa-berlaku') }}">
                @csrf
                <input type="date" name="tanggal" value="{{ $masa['tanggal']?->toDateString() }}" required>
                <button type="submit" class="tombol-kecil">Simpan tanggal</button>
            </form>
        </div>
    @endif

    <div class="tirai" id="tirai">
        <div class="lembar" role="dialog" aria-modal="true">
            <div class="kepala">
                <h3 id="l-nama">Lampu</h3>
                <button type="button" class="saklar" id="l-saklar" aria-label="Nyala/mati"></button>
            </div>
            <div class="label"><span>Kecerahan</span><span id="l-terang-teks"></span></div>
            <input type="range" id="r-terang" min="1" max="100" step="1">
            <div class="label"><span>Suhu putih</span><span>hangat ↔ dingin</span></div>
            <input type="range" id="r-suhu" min="0" max="100" step="1">
            <div class="label"><span>Warna</span></div>
            <div class="warna-warna" id="l-warna"></div>
            <button type="button" class="tutup" id="l-tutup">Selesai</button>
        </div>
    </div>
    <div class="pesan" id="pesan"></div>

    <script>
    (() => {
        const URL_DATA = @json(route('rumah.data'));
        const URL_AKSI = @json(url('/rumah/perangkat')) + '/';
        const URL_SEMUA = @json(route('rumah.semua-lampu'));
        const CSRF = document.querySelector('meta[name=csrf-token]').content;
        const isi = document.getElementById('isi');
        if (!isi) return;

        const WARNA = [
            { n: 'Putih', h: null, css: '#fff6e0' }, { n: 'Merah', h: 0 }, { n: 'Oranye', h: 28 }, { n: 'Kuning', h: 52 },
            { n: 'Hijau', h: 120 }, { n: 'Biru muda', h: 190 }, { n: 'Biru', h: 230 }, { n: 'Ungu', h: 280 },
        ];
        let daftar = [];
        let tahanSampai = 0;          // jangan timpa tampilan dengan data lama sesaat setelah perintah
        let bukaId = null;
        const sibuk = new Set();

        const $ = (id) => document.getElementById(id);
        function pesan(t) { const p = $('pesan'); p.textContent = t; p.style.display = 'block'; clearTimeout(pesan.t); pesan.t = setTimeout(() => p.style.display = 'none', 4000); }
        function status(ok, t) { $('titik').className = 'titik ' + (ok ? 'ok' : 'galat'); $('status-teks').textContent = t; }

        async function minta(url, opsi = {}) {
            const r = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF }, ...opsi });
            if (r.status === 401 || r.status === 419) { location.reload(); throw new Error('Sesi habis'); }
            const j = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(j.galat || j.message || ('Galat ' + r.status));
            return j;
        }

        const angka = (v, d = 1) => (v === null || v === undefined) ? '-' : Number(v).toLocaleString('id-ID', { maximumFractionDigits: d });
        // Riwayat daya per stop kontak (10 menit terakhir), disimpan di browser supaya grafik tidak kosong saat aplikasi dibuka lagi.
        const RENTANG = 10 * 60 * 1000;
        let riwayat = {};
        try { riwayat = JSON.parse(localStorage.getItem('rumah-daya') || '{}'); } catch (e) {}
        function catatDaya() {
            const now = Date.now();
            daftar.filter(p => p.jenis === 'stopkontak' && p.daya !== null && p.daya !== undefined).forEach(p => {
                const r = (riwayat[p.id] || []).filter(x => now - x[0] < RENTANG);
                r.push([now, p.nyala ? p.daya : 0]);
                riwayat[p.id] = r;
            });
            try { localStorage.setItem('rumah-daya', JSON.stringify(riwayat)); } catch (e) {}
        }
        function grafik(c, data) {
            if (!c) return;
            const dpr = window.devicePixelRatio || 1, W = c.clientWidth, H = c.clientHeight;
            if (!W || !H) return;
            c.width = W * dpr; c.height = H * dpr;
            const x = c.getContext('2d'); x.scale(dpr, dpr);
            const now = Date.now();
            const maks = Math.max(10, ...data.map(d => d[1])) * 1.15;
            const px = (t) => W - ((now - t) / RENTANG) * W, py = (v) => H - 2 - (v / maks) * (H - 6);
            x.strokeStyle = 'rgba(255,255,255,.07)'; x.lineWidth = 1;
            x.beginPath(); x.moveTo(0, H / 2); x.lineTo(W, H / 2); x.stroke();
            x.fillStyle = 'rgba(255,255,255,.35)'; x.font = '10px sans-serif';
            x.fillText(`${Math.round(maks)} W`, 2, 10);
            if (data.length < 2) return;
            const grad = x.createLinearGradient(0, 0, 0, H); grad.addColorStop(0, 'rgba(255,200,100,.35)'); grad.addColorStop(1, 'rgba(255,200,100,0)');
            x.beginPath(); x.moveTo(px(data[0][0]), H);
            data.forEach(d => x.lineTo(px(d[0]), py(d[1])));
            x.lineTo(px(data[data.length - 1][0]), H); x.closePath(); x.fillStyle = grad; x.fill();
            x.beginPath(); data.forEach((d, i) => i ? x.lineTo(px(d[0]), py(d[1])) : x.moveTo(px(d[0]), py(d[1])));
            x.strokeStyle = '#ffc864'; x.lineWidth = 2; x.lineJoin = 'round'; x.stroke();
        }

        function warnaLampu(p) {
            if (p.mode === 'warna' && p.warna) return `hsl(${p.warna.h}, ${Math.max(40, p.warna.s)}%, 62%)`;
            // putih: 0 = hangat, 100 = dingin
            const t = p.suhu / 100;
            const r = Math.round(255 - t * 40), g = Math.round(200 + t * 40), b = Math.round(110 + t * 145);
            return `rgb(${r},${g},${b})`;
        }

        function ubin(p) {
            const el = document.createElement('div');
            el.className = 'ubin' + (p.nyala ? ' nyala' : '') + (!p.online ? ' offline' : '') + (sibuk.has(p.id) ? ' sibuk' : '');
            el.dataset.id = p.id;
            let ikon = '❔', ket = '';
            if (p.jenis === 'lampu') {
                ikon = '💡'; el.style.setProperty('--warna', warnaLampu(p));
                ket = p.nyala ? `Nyala · ${p.terang}%` : 'Mati';
            } else if (p.jenis === 'stopkontak') {
                ikon = '🔌'; ket = p.nyala ? 'Nyala' : 'Mati';
            } else if (p.jenis === 'fingerbot') {
                ikon = '👆'; ket = p.nyala ? 'Posisi tekan' : 'Siap';
            } else {
                ikon = '⚙️'; ket = p.nyala === null ? '' : (p.nyala ? 'Nyala' : 'Mati');
            }
            if (!p.online) ket = 'Offline';
            el.innerHTML = `<div class="ikon">${ikon}</div><div><div class="nama"></div><div class="ket"></div></div>`;
            el.querySelector('.nama').textContent = p.nama;
            el.querySelector('.ket').textContent = ket;
            if (p.jenis === 'stopkontak' && p.daya !== null && p.daya !== undefined) {
                el.classList.add('listrik');
                const w = document.createElement('div');
                w.innerHTML = `<div class="watt"></div><div class="rinci-listrik"></div><canvas class="grafik"></canvas>`;
                w.querySelector('.watt').innerHTML = `${angka(p.nyala ? p.daya : 0)}<small>W</small>`;
                w.querySelector('.rinci-listrik').textContent = `${angka(p.tegangan)} V · ${angka((p.arus || 0) / 1000, 2)} A · grafik 10 menit`;
                el.appendChild(w);
                requestAnimationFrame(() => grafik(w.querySelector('canvas'), riwayat[p.id] || []));
            }
            if (p.jenis === 'lampu' && p.online) {
                const b = document.createElement('button'); b.className = 'lebih'; b.type = 'button'; b.textContent = '⋯'; b.setAttribute('aria-label', 'Atur ' + p.nama);
                b.addEventListener('click', (e) => { e.stopPropagation(); bukaLembar(p.id); });
                el.appendChild(b);
            }
            if (p.jenis === 'fingerbot' && p.online) {
                const b = document.createElement('button'); b.className = 'tekan'; b.type = 'button'; b.textContent = 'Tekan';
                b.addEventListener('click', (e) => { e.stopPropagation(); ubah(p, 'nyala', !p.nyala); });
                el.appendChild(b);
            }
            if (p.online && p.nyala !== null) el.addEventListener('click', () => {
                // Stop kontak: konfirmasi dulu sebelum dimatikan (ubinnya besar, gampang tersentuh saat melihat grafik).
                if (p.jenis === 'stopkontak' && p.nyala && !confirm('Matikan ' + p.nama + '?')) return;
                ubah(p, 'nyala', !p.nyala);
            });
            return el;
        }

        function gambar() {
            const kelompok = [
                ['Lampu', daftar.filter(p => p.jenis === 'lampu')],
                ['Stop kontak', daftar.filter(p => p.jenis === 'stopkontak')],
                ['Finger bot', daftar.filter(p => p.jenis === 'fingerbot')],
                ['Lainnya', daftar.filter(p => p.jenis === 'lain')],
            ];
            const frag = document.createDocumentFragment();
            for (const [judul, isiK] of kelompok) {
                if (!isiK.length) continue;
                const h = document.createElement('h2'); h.textContent = judul; frag.appendChild(h);
                const k = document.createElement('div'); k.className = 'kisi';
                isiK.forEach(p => k.appendChild(ubin(p)));
                frag.appendChild(k);
            }
            isi.replaceChildren(frag);
            const gw = daftar.filter(p => p.jenis === 'gateway');
            $('bawah').textContent = gw.map(g => `${g.nama}: ${g.online ? 'online' : 'OFFLINE'}`).join(' · ');
            if (bukaId) isiLembar();
        }

        async function muat(segarkan = false) {
            if (document.hidden) return;
            try {
                const j = await minta(URL_DATA + (segarkan ? '?segarkan=1' : ''));
                if (Date.now() < tahanSampai) return;
                daftar = j.perangkat;
                catatDaya();
                gambar();
                const on = daftar.filter(p => p.jenis !== 'gateway' && p.online).length;
                status(true, `${on} perangkat online · diperbarui ${new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`);
            } catch (e) { status(false, e.message); }
        }

        async function ubah(p, aksi, nilai) {
            // Tampilkan hasil dulu (optimis), lalu kirim.
            const lama = { ...p, warna: p.warna ? { ...p.warna } : null };
            if (aksi === 'nyala') p.nyala = !!nilai;
            if (aksi === 'terang') { p.terang = +nilai; p.nyala = true; }
            if (aksi === 'suhu') { p.suhu = +nilai; p.mode = 'putih'; p.nyala = true; }
            if (aksi === 'putih') { p.mode = 'putih'; p.nyala = true; }
            if (aksi === 'warna') { p.mode = 'warna'; p.warna = { h: nilai.h, s: nilai.s }; p.nyala = true; }
            sibuk.add(p.id); tahanSampai = Date.now() + 4000; gambar();
            try {
                await minta(URL_AKSI + p.id, { method: 'POST', body: JSON.stringify({ aksi, nilai }) });
            } catch (e) {
                Object.assign(p, lama); pesan(`${p.nama}: ${e.message}`);
            } finally {
                sibuk.delete(p.id); gambar();
                setTimeout(() => muat(), 2500);
            }
        }

        document.querySelectorAll('[data-semua]').forEach(b => b.addEventListener('click', async () => {
            const nyala = b.dataset.semua === '1';
            daftar.filter(p => p.jenis === 'lampu' && p.online).forEach(p => { p.nyala = nyala; sibuk.add(p.id); });
            tahanSampai = Date.now() + 5000; gambar();
            try { await minta(URL_SEMUA, { method: 'POST', body: JSON.stringify({ nyala }) }); }
            catch (e) { pesan(e.message); }
            finally { sibuk.clear(); setTimeout(() => muat(), 2500); }
        }));

        // ---- Lembar rincian lampu ----
        const rTerang = $('r-terang'), rSuhu = $('r-suhu');
        WARNA.forEach(w => {
            const b = document.createElement('button'); b.type = 'button'; b.title = w.n; b.setAttribute('aria-label', w.n);
            b.style.background = w.css || `hsl(${w.h}, 95%, 55%)`;
            b.addEventListener('click', () => {
                const p = daftar.find(x => x.id === bukaId); if (!p) return;
                w.h === null ? ubah(p, 'putih') : ubah(p, 'warna', { h: w.h, s: 100 });
            });
            b.dataset.h = w.h === null ? 'putih' : w.h;
            $('l-warna').appendChild(b);
        });
        function bukaLembar(id) { bukaId = id; isiLembar(); $('tirai').classList.add('buka'); }
        function tutupLembar() { bukaId = null; $('tirai').classList.remove('buka'); }
        function isiLembar() {
            const p = daftar.find(x => x.id === bukaId); if (!p) return tutupLembar();
            $('l-nama').textContent = p.nama;
            $('l-saklar').classList.toggle('nyala', !!p.nyala);
            if (document.activeElement !== rTerang) rTerang.value = p.terang;
            if (document.activeElement !== rSuhu) rSuhu.value = p.suhu;
            $('l-terang-teks').textContent = rTerang.value + '%';
            $('l-warna').querySelectorAll('button').forEach(b => {
                const pilih = p.mode === 'putih' ? b.dataset.h === 'putih' : (p.warna && String(p.warna.h) === b.dataset.h);
                b.classList.toggle('pilih', !!pilih);
            });
        }
        $('l-saklar').addEventListener('click', () => { const p = daftar.find(x => x.id === bukaId); if (p) ubah(p, 'nyala', !p.nyala); });
        rTerang.addEventListener('input', () => $('l-terang-teks').textContent = rTerang.value + '%');
        rTerang.addEventListener('change', () => { const p = daftar.find(x => x.id === bukaId); if (p) ubah(p, 'terang', +rTerang.value); rTerang.blur(); });
        rSuhu.addEventListener('change', () => { const p = daftar.find(x => x.id === bukaId); if (p) ubah(p, 'suhu', +rSuhu.value); rSuhu.blur(); });
        $('l-tutup').addEventListener('click', tutupLembar);
        $('tirai').addEventListener('click', (e) => { if (e.target.id === 'tirai') tutupLembar(); });

        // Segarkan otomatis tiap 3 detik selama halaman terlihat (berhenti saat aplikasi di latar belakang); langsung segarkan saat dibuka lagi.
        let jalan = false;
        async function putaran() { if (jalan) return; jalan = true; try { await muat(); } finally { jalan = false; } }
        muat(true);
        setInterval(putaran, 3000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) muat(true); });
    })();
    </script>
</body>
</html>
