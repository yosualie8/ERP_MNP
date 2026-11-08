{{--
    Gaya bersama dashboard v2 (owner 9 Nov 2026, panduan UI/UX Pro Max — Minimalism/Swiss, padat): dipakai Dashboard BBM & HM, Progres Proyek,
    Biaya DT, Laba Rugi DT, Aktivitas, dan halaman Ritasi. Hanya sekali dimuat per halaman (@once).
    Komponen: .kpi-baris/.kpi (kartu KPI), .ringkas.kpi-gaya (kartu ringkas lama → gaya KPI), .panel, .segmen, .legenda, .tooltip-grafik,
    .bar-isi (bar perbandingan di sel tabel), .lencana, .delta, .metode (catatan dilipat), .saring-kat (tombol kategori).
--}}
@once
<style>
    .dasbor-v2, .kpi-baris, .ringkas.kpi-gaya, .dash-kartu { font-variant-numeric: tabular-nums; }

    /* Kartu KPI */
    .kpi-baris { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 14px; }
    .kpi { background: var(--kartu); border: 1px solid var(--garis); border-radius: 12px; padding: 14px 16px 12px; display: flex; flex-direction: column; min-height: 112px; margin: 0; }
    .kpi .kpi-label { font-size: 12px; letter-spacing: .04em; text-transform: uppercase; color: var(--redup); display: flex; align-items: center; gap: 6px; }
    .kpi .kpi-label i { width: 8px; height: 8px; border-radius: 2px; display: inline-block; flex: none; }
    .kpi .kpi-nilai { font-size: 26px; font-weight: 700; margin: 6px 0 2px; line-height: 1.15; }
    .kpi .kpi-nilai small { font-size: 14px; font-weight: 500; color: var(--redup); margin-left: 3px; }
    .kpi .kpi-sub { font-size: 12.5px; color: var(--redup); display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .kpi .spark { width: 100%; height: 30px; margin-top: auto; display: block; }
    .kpi .meter, .meter-dasbor { height: 8px; border-radius: 4px; background: var(--kartu-2); overflow: hidden; margin: 8px 0 6px; }
    .kpi .meter span, .meter-dasbor span { display: block; height: 100%; border-radius: 4px; }
    .kpi.peringatan { border-color: rgba(240, 192, 90, .45); }
    .kpi.bahaya { border-color: rgba(255, 123, 127, .45); }

    /* Kartu ringkas lama (<div class="ringkas kpi-gaya"><div class="kartu"><span class="redup">label</span><b>nilai</b>…) → gaya KPI */
    .ringkas.kpi-gaya { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 14px; }
    .ringkas.kpi-gaya > .kartu { margin: 0; border-radius: 12px; border: 1px solid var(--garis); padding: 14px 16px 12px; min-height: 92px; display: flex; flex-direction: column; }
    .ringkas.kpi-gaya > .kartu > span.redup:first-child { font-size: 12px; letter-spacing: .04em; text-transform: uppercase; }
    .ringkas.kpi-gaya > .kartu > b { display: block; font-size: clamp(19px, 1.7vw, 26px); font-weight: 700; margin-top: 6px; line-height: 1.2; }
    .ringkas.kpi-gaya > .kartu > small, .ringkas.kpi-gaya > .kartu > span.redup:not(:first-child) { font-size: 12.5px; color: var(--redup); margin-top: 2px; }

    /* Kartu dash lama (dashboard.gaya: .dash-kartu > .kartu > h4 + .angka-besar + .dash-baris) → gaya KPI */
    .dash-kartu { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; margin-bottom: 14px; }
    .dash-kartu > .kartu { margin: 0; border-radius: 12px; border: 1px solid var(--garis); padding: 14px 16px 12px; }
    .dash-kartu h4 { margin: 0 0 6px; padding: 0; border: 0; font-size: 12px; font-weight: 600; color: var(--redup); text-transform: uppercase; letter-spacing: .04em; }
    .dash-kartu .angka-besar, .angka-besar { font-size: 26px; font-weight: 700; line-height: 1.15; margin-bottom: 6px; }
    .dash-baris { display: flex; justify-content: space-between; gap: 10px; padding: 5px 0; border-bottom: 1px solid var(--garis); font-size: 13px; }
    .dash-baris:last-child { border-bottom: 0; }
    .dash-baris > span:first-child { color: var(--redup); }
    .kartu .dash-judul { color: var(--teks); font-size: 16px; }

    /* Panel & kepala tabel */
    .panel { background: var(--kartu); border: 1px solid var(--garis); border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; position: relative; }
    .panel h3, .tabel-kepala h3 { margin: 0; font-size: 16px; }
    .panel .judul { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
    .kartu.gulir.dasbor-v2, .kartu.gulir.bulat { border-radius: 12px; }
    .tabel-kepala { padding: 14px 16px 8px; display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .tabel-kepala p { margin: 4px 0 0; color: var(--redup); font-size: 13px; }

    /* Tombol segmen (pilihan periode / tampilan) */
    .segmen { display: inline-flex; border: 1px solid var(--garis-kuat); border-radius: 9px; overflow: hidden; vertical-align: middle; }
    .segmen > a, .segmen > button { padding: 7px 14px; font-size: 13px; color: var(--teks); text-decoration: none; border: 0; border-right: 1px solid var(--garis-kuat); background: none; cursor: pointer; transition: background .15s; }
    .segmen > :last-child { border-right: 0; }
    .segmen > a:hover, .segmen > button:hover { background: var(--kartu-2); }
    .segmen > .aktif { background: var(--aksen); color: #fff; font-weight: 600; }
    .segmen > a:focus-visible, .segmen > button:focus-visible { outline: 2px solid var(--aksen-terang); outline-offset: -2px; }

    /* Legenda & tooltip grafik */
    .legenda { display: flex; gap: 16px; font-size: 12.5px; color: var(--redup); flex-wrap: wrap; }
    .legenda span { display: inline-flex; align-items: center; gap: 6px; }
    .legenda .kotak { width: 12px; height: 12px; border-radius: 3px; display: inline-block; }
    .tooltip-grafik { position: absolute; pointer-events: none; background: #0b0c0f; border: 1px solid var(--garis-kuat); border-radius: 8px; padding: 8px 10px; font-size: 12.5px;
        line-height: 1.55; box-shadow: 0 6px 20px rgba(0, 0, 0, .45); min-width: 170px; z-index: 5; }

    /* Bar perbandingan di sel tabel, lencana, perubahan */
    .bar-isi { display: flex; align-items: center; gap: 8px; justify-content: flex-end; }
    .bar-isi .jalur { flex: 1; max-width: 90px; min-width: 40px; height: 6px; background: var(--kartu-2); border-radius: 3px; overflow: hidden; }
    .bar-isi .jalur span { display: block; height: 100%; background: #5b8def; border-radius: 3px; }
    .bar-isi .jalur.untung span { background: var(--sukses); }
    .bar-isi .jalur.rugi span { background: var(--merah); }
    .lencana { display: inline-block; font-size: 11px; font-weight: 600; padding: 1px 6px; border-radius: 999px; margin-left: 4px; white-space: nowrap; }
    .lencana.tinggi, .lencana.merah { color: var(--merah); background: var(--merah-muda); }
    .lencana.rendah, .lencana.kuning { color: #f0c05a; background: rgba(240, 192, 90, .14); }
    .lencana.hijau-l { color: var(--sukses); background: var(--sukses-muda); }
    .delta { font-weight: 600; font-size: 12px; padding: 1px 6px; border-radius: 999px; }
    .delta.baik { color: var(--sukses); background: var(--sukses-muda); }
    .delta.buruk { color: var(--merah); background: var(--merah-muda); }
    .delta.datar { color: var(--redup); background: var(--kartu-2); }
    .redup-angka { color: var(--redup); }

    /* Tombol kategori (aria-pressed) */
    .saring-kat { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .saring-kat button { background: var(--kartu-2); color: var(--teks); border: 1px solid var(--garis-kuat); border-radius: 999px; padding: 5px 12px; font-size: 12.5px; cursor: pointer; min-height: 32px; transition: background .15s, border-color .15s; }
    .saring-kat button:hover { border-color: var(--redup); }
    .saring-kat button[aria-pressed="true"] { background: var(--aksen-muda); border-color: var(--aksen); color: #fff; }
    .saring-kat button span { color: var(--redup); margin-left: 4px; }

    /* Catatan cara membaca (dilipat) */
    .metode { margin-bottom: 14px; border-radius: 12px; }
    .metode summary { cursor: pointer; padding: 12px 16px; font-weight: 600; list-style: none; display: flex; align-items: center; gap: 8px; }
    .metode summary::-webkit-details-marker { display: none; }
    .metode summary::before { content: '▸'; color: var(--redup); transition: transform .15s; }
    .metode[open] summary::before { transform: rotate(90deg); }
    .metode .isi { padding: 0 16px 14px; font-size: 13.5px; line-height: 1.6; color: var(--redup); }
    .metode .isi b { color: var(--teks); }

    @media (prefers-reduced-motion: reduce) { .segmen > *, .saring-kat button, .metode summary::before { transition: none !important; } }
</style>
@endonce
