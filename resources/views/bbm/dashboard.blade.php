@extends('layouts.app', ['judul' => 'Dashboard BBM & HM'])

@section('lebar', '1500px')

@section('isi')
    @php
        // Angka Indonesia; nol di belakang koma dibuang (hanya bagian desimal — 78.070 tetap 78.070).
        $l = function ($v, $d = 1) { if ($v === null) return '–'; $t = number_format((float) $v, $d, ',', '.'); return $d > 0 ? rtrim(rtrim($t, '0'), ',') : $t; };
        $namaBulan = fn ($m) => \Illuminate\Support\Carbon::parse($m.'-01')->translatedFormat('M y');
    @endphp
    <style>
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 20px; margin-top: 2px; }
        .ringkas small { color: var(--redup); font-size: 12px; }
        .grafik { width: 100%; height: 280px; display: block; }
        .grafik text { fill: var(--redup); font-size: 11px; }
        .grafik .sumbu { stroke: var(--garis); }
        .grafik .batang { fill: #3b6fb6; }
        .grafik .batang2 { fill: #2f7a55; }
        .grafik .garis-rasio { fill: none; stroke: #f0c05a; stroke-width: 2.5; }
        .grafik .titik { fill: #f0c05a; }
        .grafik text.nilai { fill: var(--teks); font-size: 11px; font-weight: 600; }
        .grafik text.nilai-rasio { fill: #f0c05a; font-size: 11px; font-weight: 700; }
        .grafik text.halo { stroke: var(--kartu); stroke-width: 3px; stroke-linejoin: round; paint-order: stroke; }
        .legenda { display: flex; gap: 16px; font-size: 12px; color: var(--redup); margin-bottom: 4px; flex-wrap: wrap; }
        .legenda i { display: inline-block; width: 12px; height: 12px; border-radius: 2px; vertical-align: -1px; margin-right: 4px; font-style: normal; }
        tr.pilih-alat { cursor: pointer; }
        tr.pilih-alat.aktif td { background: rgba(229, 72, 77, .10); }
        .mini { width: 140px; height: 28px; display: block; }
        .catatan-metode { font-size: 13px; line-height: 1.55; }
        .catatan-metode ul { margin: 4px 0 0; padding-left: 18px; }
        .dua { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 1000px) { .dua { grid-template-columns: 1fr; } }
    </style>

    <div style="margin-bottom: 10px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
        <span class="redup">Periode:</span>
        @foreach ([3, 6, 12, 24] as $b)
            <a href="{{ route('bbm.dashboard', ['bulan' => $b]) }}" @class(['chip', 'aktif' => $b === $bulan])>{{ $b }} bulan</a>
        @endforeach
        <span class="redup" style="margin-left: 8px;">{{ $namaBulan($daftarBulan[0]) }} – {{ $namaBulan(end($daftarBulan)) }}</span>
    </div>

    <div class="ringkas">
        <div class="kartu"><span class="redup">Solar keluar ke alat</span><b>{{ $l($kartu['liter'], 0) }} L</b><small>{{ number_format($kartu['isi_semua'], 0, ',', '.') }} kali isi</small></div>
        <div class="kartu"><span class="redup">Jam HM tercatat</span><b>{{ $l($kartu['hm']) }} jam</b></div>
        <div class="kartu"><span class="redup">Rasio rata-rata</span><b>{{ $l($kartu['rasio'], 2) }} L/jam</b><small>dari alat-bulan yang HM-nya tercatat wajar</small></div>
        <div class="kartu"><span class="redup">Solar masuk</span><b>{{ $l($kartu['masuk'], 0) }} L</b><small>stok sekarang {{ $l($kartu['stok'], 0) }} L</small></div>
        <div class="kartu"><span class="redup">Isi solar alat berat tanpa catatan HM</span><b @if ($kartu['isi'] && $kartu['tanpa_hm'] / $kartu['isi'] > .2) style="color: var(--merah);" @endif>{{ number_format($kartu['tanpa_hm'], 0, ',', '.') }}</b>
            <small>sejak {{ \Illuminate\Support\Carbon::parse(\App\Support\LembarBbm::WAJIB_HM_SEJAK)->translatedFormat('j M Y') }} (HM wajib):
                {{ number_format($kartu['tanpa_hm'], 0, ',', '.') }} dari {{ number_format($kartu['isi'], 0, ',', '.') }} kali isi solar excavator/dozer tanpa jam HM.
                @if ($kartu['tanpa_hm_lama']) {{ number_format($kartu['tanpa_hm_lama'], 0, ',', '.') }} isian sebelum tanggal itu tanpa HM — data lama, tidak dihitung.@endif</small></div>
    </div>

    <div class="kartu">
        <h3 style="margin: 0 0 4px;">Tren bulanan — semua alat</h3>
        <div class="legenda"><span><i style="background: #3b6fb6;"></i>Solar keluar (L)</span><span><i style="background: #f0c05a;"></i>Rasio L/jam (sumbu kanan)</span></div>
        <svg class="grafik" id="grafik-total" viewBox="0 0 1000 280"></svg>
    </div>

    <div class="kartu" id="detail-alat" hidden>
        <h3 style="margin: 0 0 4px;">Detail alat: <span id="nama-alat"></span></h3>
        <div class="legenda"><span><i style="background: #3b6fb6;"></i>Solar keluar (L)</span><span><i style="background: #2f7a55;"></i>Jam HM (×10)</span><span><i style="background: #f0c05a;"></i>Rasio L/jam (sumbu kanan)</span>
            <span id="median-alat"></span></div>
        <svg class="grafik" id="grafik-alat" viewBox="0 0 1000 280"></svg>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <div style="padding: 12px 14px 4px;"><h3 style="margin: 0;">Per alat</h3>
            <span class="redup" style="font-size: 13px;">Klik baris untuk melihat grafik alat itu. <b>Rasio periode</b> = total liter ÷ total jam HM dari bulan yang HM-nya tercatat.
                <b>Median bulanan</b> = patokan wajar alat itu (tidak terpengaruh bulan yang janggal).</span></div>
        <table class="kas">
            <thead><tr><th>Alat</th><th>Kategori</th><th class="angka">Solar (L)</th><th class="angka">Jam HM</th><th class="angka">Rasio periode (L/jam)</th>
                <th class="angka">Median bulanan</th><th>Tren rasio per bulan</th><th class="angka" title="Bulan dengan isi solar tetapi tanpa jam HM sama sekali">Bulan HM kosong</th>
                <th class="angka" title="Bulan yang rasionya jauh dari median (> 1,6× / < 1/1,6) — biasanya HM tidak lengkap">Bulan janggal</th></tr></thead>
            <tbody>
                @foreach ($alat as $i => $a)
                    <tr class="pilih-alat" data-i="{{ $i }}">
                        <td><b>{{ $a['unit'] }}</b></td>
                        <td class="redup">{{ $a['kategori'] }}</td>
                        <td class="angka" data-n="{{ $a['liter'] }}">{{ $l($a['liter'], 0) }}</td>
                        <td class="angka" data-n="{{ $a['hm'] }}">{{ $l($a['hm']) }}</td>
                        <td class="angka" data-n="{{ $a['rasio'] }}">{{ $l($a['rasio'], 2) }}</td>
                        <td class="angka" data-n="{{ $a['median'] }}"><b>{{ $l($a['median'], 2) }}</b></td>
                        <td data-tanpa-saring><svg class="mini" data-mini="{{ $i }}" viewBox="0 0 140 28" preserveAspectRatio="none"></svg></td>
                        <td class="angka" data-n="{{ $a['hm_kosong'] }}" @if ($a['hm_kosong']) style="color: var(--merah);" @endif>{{ $a['hm_kosong'] ?: '–' }}</td>
                        <td class="angka" data-n="{{ $a['janggal'] }}" @if ($a['janggal']) style="color: #f0c05a;" @endif>{{ $a['janggal'] ?: '–' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="dua">
        <div class="kartu gulir" style="padding: 0;">
            <div style="padding: 12px 14px 4px;"><h3 style="margin: 0;">Per proyek</h3></div>
            <table class="kas">
                <thead><tr><th>Proyek</th><th class="angka">Tahap</th><th class="angka">Solar (L)</th><th class="angka">Jam HM</th><th class="angka">L/jam</th></tr></thead>
                <tbody>
                    @foreach ($perProyek as $p => $x)
                        <tr><td>{{ $p }}</td><td class="angka">{{ $x['tahap'] }}</td><td class="angka" data-n="{{ $x['l'] }}">{{ $l($x['l'], 0) }}</td>
                            <td class="angka" data-n="{{ $x['h'] }}">{{ $l($x['h']) }}</td><td class="angka">{{ $x['h'] > 0 ? $l($x['l'] / $x['h'], 2) : '–' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="kartu gulir" style="padding: 0;">
            <div style="padding: 12px 14px 4px;"><h3 style="margin: 0;">Per tahap</h3></div>
            <table class="kas">
                <thead><tr><th>Tahap</th><th>Proyek</th><th class="angka">Solar (L)</th><th class="angka">Jam HM</th><th class="angka">L/jam</th></tr></thead>
                <tbody>
                    @foreach ($perTahap as $t)
                        <tr><td>{{ $t['tahap'] }}</td><td class="redup">{{ $t['proyek'] }}</td><td class="angka" data-n="{{ $t['l'] }}">{{ $l($t['l'], 0) }}</td>
                            <td class="angka" data-n="{{ $t['h'] }}">{{ $l($t['h']) }}</td><td class="angka">{{ $t['h'] > 0 ? $l($t['l'] / $t['h'], 2) : '–' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="kartu catatan-metode">
        <b>Cara membaca rasio.</b> Solar diisi sebagian-sebagian (100–200 L), jadi rasio per kali isi tidak bisa dipakai. Rasio di dashboard ini dihitung dari
        <b>jumlah sebulan</b>: total liter ÷ total jam HM. Kelebihan/kekurangan isi di awal dan akhir bulan paling banyak sebesar isi tangki, sehingga makin banyak
        jam kerja dalam sebulan, makin kecil pengaruhnya.
        <ul>
            <li>Bulan yang ada solar tetapi <b>tanpa jam HM</b> tidak ikut dihitung rasionya (ditandai "Bulan HM kosong").</li>
            <li>Bulan yang rasionya jauh dari median alat itu ditandai "Bulan janggal" — biasanya karena HM sebagian hari tidak dicatat. Bulan janggal tidak ikut dalam rasio keseluruhan.</li>
            <li>Patokan wajar tiap alat = <b>median bulanan</b>.</li>
        </ul>
    </div>

    <script>
        (() => {
            const BULAN = @json(array_map($namaBulan, $daftarBulan));
            const TOTAL = @json(array_values($total));
            const ALAT = @json($alat);
            const fmt = (n, d = 0) => n === null || n === undefined ? '–' : Number(n).toLocaleString('id-ID', {maximumFractionDigits: d});
            const ns = 'http://www.w3.org/2000/svg';
            const el = (tag, attr, isi) => { const e = document.createElementNS(ns, tag); for (const k in attr) e.setAttribute(k, attr[k]); if (isi !== undefined) e.textContent = isi; return e; };
            const atas = v => { if (!v) return 1; const p = Math.pow(10, Math.floor(Math.log10(v))); return Math.ceil(v / p * 2) / 2 * p; };

            // Grafik batang (1–2 seri) + garis rasio pada sumbu kanan. Owner 30 Okt 2026: angka liter (seri bertanda `angka`) dan rasio L/jam
            // langsung tertulis di grafik. Lebar viewBox = lebar layar agar tulisan tidak melar; digambar ulang saat ukuran jendela berubah.
            const gambar = (svg, label, batang, rasio) => {
                svg.innerHTML = '';
                svg._isi = [label, batang, rasio];
                const W = Math.max(320, Math.round(svg.clientWidth || 1000)), H = 280, kiri = 56, kanan = 50, atasP = 28, bawah = 34;
                svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
                const lebarTeks = t => t.length * 6.6;
                const tulis = (x, y, t, kelas, putar) => svg.appendChild(el('text', {x, y, 'text-anchor': putar ? 'start' : 'middle', class: kelas + ' halo',
                    ...(putar ? {transform: `rotate(-90 ${x} ${y})`, 'dominant-baseline': 'middle'} : {})}, t));
                const lebar = W - kiri - kanan, tinggi = H - atasP - bawah, n = label.length;
                const maksB = atas(Math.max(1, ...batang.flatMap(s => s.nilai)));
                const r = rasio.filter(v => v !== null);
                const maksR = atas(r.length ? Math.max(...r) : 1);
                for (let g = 0; g <= 4; g++) {
                    const y = atasP + tinggi - tinggi * g / 4;
                    svg.appendChild(el('line', {x1: kiri, x2: W - kanan, y1: y, y2: y, class: 'sumbu', 'stroke-dasharray': g ? '3 4' : ''}));
                    svg.appendChild(el('text', {x: kiri - 6, y: y + 4, 'text-anchor': 'end'}, fmt(maksB * g / 4)));
                    svg.appendChild(el('text', {x: W - kanan + 6, y: y + 4}, fmt(maksR * g / 4, 1)));
                }
                const slot = lebar / n, lb = Math.min(46, slot * 0.7) / batang.length;
                const puncak = []; // y teratas tulisan angka batang per bulan (untuk menghindari tabrakan dengan angka rasio)
                label.forEach((t, i) => {
                    const x0 = kiri + slot * i + (slot - lb * batang.length) / 2;
                    batang.forEach((s, j) => {
                        const h = tinggi * s.nilai[i] / maksB;
                        const rect = el('rect', {x: x0 + lb * j, y: atasP + tinggi - h, width: Math.max(lb - 2, 1), height: h, class: s.kelas});
                        rect.appendChild(el('title', {}, `${t}: ${s.nama} ${fmt(s.asli ? s.asli[i] : s.nilai[i], 1)}`));
                        svg.appendChild(rect);
                        const v = s.asli ? s.asli[i] : s.nilai[i];
                        if (!s.angka || !v) return;
                        const teks = fmt(v, 1), cx = x0 + lb * j + Math.max(lb - 2, 1) / 2, yb = atasP + tinggi - h;
                        if (lebarTeks(teks) <= slot - 4) {
                            tulis(cx, yb - 5, teks, 'nilai');
                            puncak[i] = yb - 16;
                        } else if (h >= lebarTeks(teks) + 8) {
                            tulis(cx, yb + 5, teks, 'nilai', true); // batang sempit: tulisan tegak di dalam batang
                            puncak[i] = yb;
                        } else {
                            tulis(cx, yb - 4, teks, 'nilai', true);
                            puncak[i] = yb - lebarTeks(teks) - 6;
                        }
                    });
                    if (n <= 13 || i % Math.ceil(n / 12) === 0) svg.appendChild(el('text', {x: kiri + slot * (i + .5), y: H - 12, 'text-anchor': 'middle'}, t));
                });
                let d = '';
                rasio.forEach((v, i) => {
                    if (v === null) return;
                    const x = kiri + slot * (i + .5), y = atasP + tinggi - tinggi * v / maksR;
                    d += (d && rasio[i - 1] !== null ? 'L' : 'M') + x + ' ' + y;
                    const c = el('circle', {cx: x, cy: y, r: 4, class: 'titik'});
                    c.appendChild(el('title', {}, `${label[i]}: ${fmt(v, 2)} L/jam`));
                    svg.appendChild(c);
                    // Angka rasio di atas titik; pindah ke bawah titik bila menabrak angka liter atau terlalu dekat tepi atas.
                    const yAtas = y - 9, bentrok = puncak[i] !== undefined && yAtas > puncak[i] - 2 && yAtas - 12 < puncak[i] + 16;
                    tulis(x, bentrok || yAtas < 14 ? y + 17 : yAtas, fmt(v, 2), 'nilai-rasio');
                });
                svg.insertBefore(el('path', {d, class: 'garis-rasio'}), svg.querySelector('circle'));
            };
            let jedaUkur;
            window.addEventListener('resize', () => { clearTimeout(jedaUkur); jedaUkur = setTimeout(() => document.querySelectorAll('svg.grafik').forEach(g => {
                if (g._isi && g.clientWidth) gambar(g, ...g._isi);
            }), 150); });

            gambar(document.getElementById('grafik-total'), BULAN, [{nama: 'Solar keluar (L)', kelas: 'batang', angka: true, nilai: TOTAL.map(t => t.l)}], TOTAL.map(t => t.r));

            // Grafik mini rasio per bulan di tabel per alat.
            document.querySelectorAll('svg[data-mini]').forEach(svg => {
                const a = ALAT[svg.dataset.mini], v = Object.values(a.bulan).map(b => b.r);
                const ada = v.filter(x => x !== null); if (!ada.length) return;
                const maks = Math.max(...ada) || 1, n = v.length;
                let d = '';
                v.forEach((x, i) => { if (x === null) return; const px = 4 + 132 * i / Math.max(n - 1, 1), py = 24 - 20 * x / maks; d += (d && v[i - 1] !== null ? 'L' : 'M') + px + ' ' + py; svg.appendChild(el('circle', {cx: px, cy: py, r: 1.8, fill: '#f0c05a'})); });
                if (a.median) { const y = 24 - 20 * a.median / maks; svg.appendChild(el('line', {x1: 0, x2: 140, y1: y, y2: y, stroke: '#4a5160', 'stroke-dasharray': '2 3'})); }
                svg.appendChild(el('path', {d, fill: 'none', stroke: '#f0c05a', 'stroke-width': 1.5}));
                svg.appendChild(el('title', {}, Object.entries(a.bulan).map(([m, b]) => `${m}: ${b.r === null ? (b.l ? 'HM kosong' : '–') : fmt(b.r, 2) + ' L/jam'}`).join('\n')));
            });

            // Klik baris alat → grafik detail.
            const detail = document.getElementById('detail-alat');
            document.querySelectorAll('tr.pilih-alat').forEach(tr => tr.addEventListener('click', () => {
                document.querySelectorAll('tr.pilih-alat.aktif').forEach(x => x.classList.remove('aktif'));
                tr.classList.add('aktif');
                const a = ALAT[tr.dataset.i], b = Object.values(a.bulan);
                detail.hidden = false;
                document.getElementById('nama-alat').textContent = a.unit;
                document.getElementById('median-alat').textContent = a.median ? `· median ${fmt(a.median, 2)} L/jam` : '';
                gambar(document.getElementById('grafik-alat'), BULAN, [
                    {nama: 'Solar keluar (L)', kelas: 'batang', angka: true, nilai: b.map(x => x.l)},
                    {nama: 'Jam HM', kelas: 'batang2', nilai: b.map(x => x.h * 10), asli: b.map(x => x.h)},
                ], b.map(x => x.r));
                detail.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }));
        })();
    </script>
@endsection
