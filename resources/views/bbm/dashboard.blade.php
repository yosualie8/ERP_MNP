@extends('layouts.app', ['judul' => 'Dashboard BBM & HM'])

@section('lebar', '1500px')

@section('isi')
    @php
        // Angka Indonesia; nol di belakang koma dibuang (hanya bagian desimal — 78.070 tetap 78.070).
        $l = function ($v, $d = 1) { if ($v === null) return '–'; $t = number_format((float) $v, $d, ',', '.'); return $d > 0 ? rtrim(rtrim($t, '0'), ',') : $t; };
        $namaBulan = fn ($m) => \Illuminate\Support\Carbon::parse($m.'-01')->translatedFormat('M y');
        $namaPanjang = fn ($m) => \Illuminate\Support\Carbon::parse($m.'-01')->translatedFormat('F Y');
        // Tampilan v2 (owner 9 Nov 2026, panduan UI/UX Pro Max): kartu KPI seragam + tren, bulan berjalan ditandai, rasio di baris sendiri
        // (tidak bertabrakan dengan angka liter), tabel per alat bisa disaring per kategori + bar perbandingan, catatan metode dilipat.
        $seri = array_values($total);
        $bulanIni = now()->format('Y-m');
        $berjalan = end($daftarBulan) === $bulanIni;
        // Perbandingan: bulan lengkap terakhir vs bulan sebelumnya (bulan berjalan tidak dibandingkan karena belum lengkap).
        $iA = count($seri) - ($berjalan ? 2 : 1);
        $iB = $iA - 1;
        $beda = function (string $k) use ($seri, $iA, $iB) {
            if ($iB < 0 || ! ($seri[$iB][$k] ?? null) || ($seri[$iA][$k] ?? null) === null) return null;
            return ($seri[$iA][$k] - $seri[$iB][$k]) / $seri[$iB][$k] * 100;
        };
        $bulanPendek = fn ($m) => \Illuminate\Support\Carbon::parse($m.'-01')->translatedFormat('M');
        $labelBanding = $iB >= 0 ? $bulanPendek($daftarBulan[$iA]).' vs '.$bulanPendek($daftarBulan[$iB]) : '';
        $panah = fn ($p, $naikBuruk = false) => $p === null ? '' : '<span class="delta '.(abs($p) < 0.5 ? 'datar' : (($p > 0) === $naikBuruk ? 'buruk' : 'baik')).'">'
            .($p > 0 ? '▲' : ($p < 0 ? '▼' : '■')).' '.number_format(abs($p), 0, ',', '.').'%</span>';
        $tercatat = max(0, $kartu['isi'] - $kartu['tanpa_hm']);
        $persenHm = $kartu['isi'] > 0 ? $tercatat / $kartu['isi'] * 100 : null;
        $kategoriAda = collect($alat)->groupBy(fn ($a) => $a['kategori'] ?: 'Lainnya')->map->count()->sortDesc();
        $maksAlat = max(1, collect($alat)->max('liter'));
        $maksProyek = max(1, collect($perProyek)->max('l'));
        $maksTahap = max(1, collect($perTahap)->max('l'));
    @endphp
    <style>
        .bbm { --b-solar: #5b8def; --b-hm: #3fae7a; --b-rasio: #f0c05a; --b-masuk: #a78bfa; font-variant-numeric: tabular-nums; }
        .bbm .kepala { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
        .bbm .kepala .rentang { color: var(--redup); font-size: 13px; margin-left: 4px; }
        .bbm .kepala .segmen { display: inline-flex; border: 1px solid var(--garis-kuat); border-radius: 9px; overflow: hidden; }
        .bbm .kepala .segmen a { padding: 7px 14px; font-size: 13px; color: var(--teks); text-decoration: none; border-right: 1px solid var(--garis-kuat); min-height: 20px; transition: background .15s; }
        .bbm .kepala .segmen a:last-child { border-right: 0; }
        .bbm .kepala .segmen a:hover { background: var(--kartu-2); }
        .bbm .kepala .segmen a.aktif { background: var(--aksen); color: #fff; font-weight: 600; }
        .bbm .kepala .segmen a:focus-visible { outline: 2px solid var(--aksen-terang); outline-offset: -2px; }

        /* Kartu KPI: tinggi seragam, label kecil, nilai besar, satu baris konteks + tren 12 bulan */
        .kpi-baris { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 14px; }
        .kpi { background: var(--kartu); border: 1px solid var(--garis); border-radius: 12px; padding: 14px 16px 10px; display: flex; flex-direction: column; min-height: 132px; }
        .kpi .kpi-label { font-size: 12px; letter-spacing: .04em; text-transform: uppercase; color: var(--redup); display: flex; align-items: center; gap: 6px; }
        .kpi .kpi-label i { width: 8px; height: 8px; border-radius: 2px; display: inline-block; }
        .kpi .kpi-nilai { font-size: 26px; font-weight: 700; margin: 6px 0 2px; line-height: 1.15; }
        .kpi .kpi-nilai small { font-size: 14px; font-weight: 500; color: var(--redup); margin-left: 3px; }
        .kpi .kpi-sub { font-size: 12.5px; color: var(--redup); display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .kpi .spark { width: 100%; height: 30px; margin-top: auto; display: block; }
        .delta { font-weight: 600; font-size: 12px; padding: 1px 6px; border-radius: 999px; }
        .delta.baik { color: var(--sukses); background: var(--sukses-muda); }
        .delta.buruk { color: var(--merah); background: var(--merah-muda); }
        .delta.datar { color: var(--redup); background: var(--kartu-2); }
        .kpi .meter { height: 8px; border-radius: 4px; background: var(--kartu-2); overflow: hidden; margin: 8px 0 6px; }
        .kpi .meter span { display: block; height: 100%; border-radius: 4px; }

        /* Grafik */
        .panel { background: var(--kartu); border: 1px solid var(--garis); border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; position: relative; }
        .panel h3 { margin: 0; font-size: 16px; }
        .panel .judul { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
        .legenda { display: flex; gap: 16px; font-size: 12.5px; color: var(--redup); flex-wrap: wrap; }
        .legenda span { display: inline-flex; align-items: center; gap: 6px; }
        .legenda .kotak { width: 12px; height: 12px; border-radius: 3px; }
        .legenda .garis { width: 18px; height: 0; border-top: 2.5px solid var(--b-rasio); position: relative; }
        .legenda .garis::after { content: ''; position: absolute; left: 6px; top: -5.5px; width: 7px; height: 7px; background: var(--b-rasio); transform: rotate(45deg); }
        .legenda .arsir { width: 12px; height: 12px; border-radius: 3px; background: repeating-linear-gradient(135deg, var(--b-solar) 0 3px, transparent 3px 6px); border: 1px solid var(--b-solar); }
        .grafik { width: 100%; height: 300px; display: block; }
        .grafik text { fill: var(--redup); font-size: 11.5px; }
        .grafik .sumbu { stroke: var(--garis); }
        .grafik .batang { fill: var(--b-solar); }
        .grafik .batang2 { fill: var(--b-hm); }
        .grafik .garis-rasio { fill: none; stroke: var(--b-rasio); stroke-width: 2.5; }
        .grafik .titik { fill: var(--b-rasio); stroke: var(--kartu); stroke-width: 1.5; }
        .grafik text.nilai { fill: var(--teks); font-size: 11.5px; font-weight: 600; }
        .grafik text.nilai-rasio { fill: var(--b-rasio); font-size: 12px; font-weight: 700; }
        .grafik text.ket-baris { fill: var(--redup); font-size: 11px; font-weight: 600; }
        .grafik text.halo { stroke: var(--kartu); stroke-width: 3px; stroke-linejoin: round; paint-order: stroke; }
        .grafik .zona { fill: transparent; cursor: pointer; outline: none; }
        .grafik .zona:hover, .grafik .zona:focus { fill: rgba(255, 255, 255, .045); }
        .tooltip { position: absolute; pointer-events: none; background: #0b0c0f; border: 1px solid var(--garis-kuat); border-radius: 8px; padding: 8px 10px; font-size: 12.5px;
            line-height: 1.55; box-shadow: 0 6px 20px rgba(0, 0, 0, .45); min-width: 170px; z-index: 5; }
        .tooltip b { display: block; margin-bottom: 2px; }
        .tooltip .bar { display: flex; justify-content: space-between; gap: 14px; }
        .tooltip .bar span:first-child { color: var(--redup); }

        /* Tabel */
        .tabel-kepala { padding: 14px 16px 8px; display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .tabel-kepala h3 { margin: 0; font-size: 16px; }
        .tabel-kepala p { margin: 4px 0 0; color: var(--redup); font-size: 13px; }
        .saring-kat { display: flex; gap: 6px; flex-wrap: wrap; }
        .saring-kat button { background: var(--kartu-2); color: var(--teks); border: 1px solid var(--garis-kuat); border-radius: 999px; padding: 5px 12px; font-size: 12.5px; cursor: pointer; min-height: 32px; transition: background .15s, border-color .15s; }
        .saring-kat button:hover { border-color: var(--redup); }
        .saring-kat button[aria-pressed="true"] { background: var(--aksen-muda); border-color: var(--aksen); color: #fff; }
        .saring-kat button span { color: var(--redup); margin-left: 4px; }
        tr.sembunyi-kat { display: none !important; }
        tr.pilih-alat { cursor: pointer; transition: background .12s; }
        tr.pilih-alat:hover td { background: var(--baris-detail-sorot); }
        tr.pilih-alat:focus-visible { outline: 2px solid var(--aksen-terang); outline-offset: -2px; }
        tr.pilih-alat.aktif td { background: rgba(229, 72, 77, .12); }
        .bar-isi { display: flex; align-items: center; gap: 8px; justify-content: flex-end; }
        .bar-isi .jalur { flex: 1; max-width: 90px; height: 6px; background: var(--kartu-2); border-radius: 3px; overflow: hidden; }
        .bar-isi .jalur span { display: block; height: 100%; background: var(--b-solar); border-radius: 3px; }
        .lencana { display: inline-block; font-size: 11px; font-weight: 600; padding: 1px 6px; border-radius: 999px; margin-left: 4px; }
        .lencana.tinggi { color: var(--merah); background: var(--merah-muda); }
        .lencana.rendah { color: var(--b-rasio); background: rgba(240, 192, 90, .14); }
        .redup-angka { color: var(--redup); }
        .mini { width: 140px; height: 28px; display: block; }
        .dua { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 1000px) { .dua { grid-template-columns: 1fr; } }
        .metode { margin-bottom: 14px; }
        .metode summary { cursor: pointer; padding: 12px 16px; font-weight: 600; list-style: none; display: flex; align-items: center; gap: 8px; }
        .metode summary::before { content: '▸'; color: var(--redup); transition: transform .15s; }
        .metode[open] summary::before { transform: rotate(90deg); }
        .metode .isi { padding: 0 16px 14px; font-size: 13.5px; line-height: 1.6; color: var(--redup); }
        .metode .isi b { color: var(--teks); }
        .tutup-detail { background: none; border: 1px solid var(--garis-kuat); color: var(--teks); border-radius: 8px; padding: 5px 12px; cursor: pointer; font-size: 13px; min-height: 32px; }
        @media (prefers-reduced-motion: reduce) { .bbm * { transition: none !important; } }
    </style>

    <div class="bbm">
        <div class="kepala">
            <span class="redup">Periode</span>
            <nav class="segmen" aria-label="Periode">
                @foreach ([3, 6, 12, 24] as $b)
                    <a href="{{ route('bbm.dashboard', ['bulan' => $b]) }}" @class(['aktif' => $b === $bulan]) @if ($b === $bulan) aria-current="page" @endif>{{ $b }} bulan</a>
                @endforeach
            </nav>
            <span class="rentang">{{ $namaPanjang($daftarBulan[0]) }} – {{ $namaPanjang(end($daftarBulan)) }}@if ($berjalan) · bulan ini masih berjalan @endif</span>
        </div>

        {{-- KPI --}}
        <div class="kpi-baris">
            <div class="kpi">
                <div class="kpi-label"><i style="background: var(--b-solar);"></i>Solar keluar ke alat</div>
                <div class="kpi-nilai">{{ $l($kartu['liter'], 0) }}<small>liter</small></div>
                <div class="kpi-sub">{{ number_format($kartu['isi_semua'], 0, ',', '.') }} kali isi @if ($labelBanding){!! $panah($beda('l'), true) !!} <span title="Bulan lengkap terakhir dibanding sebelumnya">{{ $labelBanding }}</span>@endif</div>
                <svg class="spark" data-spark="l" data-warna="var(--b-solar)" viewBox="0 0 200 30" preserveAspectRatio="none" aria-hidden="true"></svg>
            </div>
            <div class="kpi">
                <div class="kpi-label"><i style="background: var(--b-hm);"></i>Jam HM tercatat</div>
                <div class="kpi-nilai">{{ $l($kartu['hm']) }}<small>jam</small></div>
                <div class="kpi-sub">@if ($labelBanding){!! $panah($beda('h')) !!} <span>{{ $labelBanding }}</span>@else&nbsp;@endif</div>
                <svg class="spark" data-spark="h" data-warna="var(--b-hm)" viewBox="0 0 200 30" preserveAspectRatio="none" aria-hidden="true"></svg>
            </div>
            <div class="kpi">
                <div class="kpi-label"><i style="background: var(--b-rasio);"></i>Rasio rata-rata</div>
                <div class="kpi-nilai">{{ $l($kartu['rasio'], 2) }}<small>L/jam</small></div>
                <div class="kpi-sub" title="Dari alat-bulan yang HM-nya tercatat wajar">@if ($labelBanding){!! $panah($beda('r'), true) !!} <span>{{ $labelBanding }}</span>@else dari HM tercatat wajar @endif</div>
                <svg class="spark" data-spark="r" data-warna="var(--b-rasio)" viewBox="0 0 200 30" preserveAspectRatio="none" aria-hidden="true"></svg>
            </div>
            <div class="kpi">
                <div class="kpi-label"><i style="background: var(--b-masuk);"></i>Solar masuk</div>
                <div class="kpi-nilai">{{ $l($kartu['masuk'], 0) }}<small>liter</small></div>
                <div class="kpi-sub">Stok sekarang <b style="color: var(--teks);">{{ $l($kartu['stok'], 0) }} L</b></div>
                <svg class="spark" data-spark="masuk" data-warna="var(--b-masuk)" viewBox="0 0 200 30" preserveAspectRatio="none" aria-hidden="true"></svg>
            </div>
            <div class="kpi" title="Sejak {{ \Illuminate\Support\Carbon::parse(\App\Support\LembarBbm::WAJIB_HM_SEJAK)->translatedFormat('j M Y') }} jam HM wajib diisi untuk alat berat (excavator/dozer).@if ($kartu['tanpa_hm_lama']) {{ number_format($kartu['tanpa_hm_lama'], 0, ',', '.') }} isian sebelum tanggal itu tanpa HM — data lama, tidak dihitung.@endif">
                @php($warnaHm = $persenHm === null ? 'var(--redup)' : ($persenHm >= 95 ? 'var(--sukses)' : ($persenHm >= 80 ? 'var(--b-rasio)' : 'var(--merah)')))
                <div class="kpi-label"><i style="background: {{ $warnaHm }};"></i>Kelengkapan HM alat berat</div>
                <div class="kpi-nilai">{{ $persenHm === null ? '–' : number_format($persenHm, 0, ',', '.') }}<small>%</small></div>
                <div class="meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($persenHm ?? 0) }}" aria-label="Kelengkapan HM"><span style="width: {{ min(100, $persenHm ?? 0) }}%; background: {{ $warnaHm }};"></span></div>
                <div class="kpi-sub">{{ number_format($tercatat, 0, ',', '.') }} dari {{ number_format($kartu['isi'], 0, ',', '.') }} isi solar ber-HM @if ($kartu['tanpa_hm'])· <b style="color: var(--merah);">{{ number_format($kartu['tanpa_hm'], 0, ',', '.') }} tanpa HM</b>@endif</div>
            </div>
        </div>

        {{-- Tren bulanan --}}
        <div class="panel" id="panel-total">
            <div class="judul">
                <h3>Tren bulanan — semua alat</h3>
                <div class="legenda">
                    <span><i class="kotak" style="background: var(--b-solar);"></i>Solar keluar (L)</span>
                    <span><i class="garis"></i>Rasio L/jam</span>
                    @if ($berjalan)<span><i class="arsir"></i>Bulan berjalan (belum lengkap)</span>@endif
                </div>
            </div>
            <svg class="grafik" id="grafik-total" viewBox="0 0 1000 300" role="img" aria-label="Grafik solar keluar dan rasio liter per jam per bulan"></svg>
            <div class="tooltip" hidden></div>
        </div>

        {{-- Detail satu alat (dibuka dari tabel) --}}
        <div class="panel" id="detail-alat" hidden>
            <div class="judul">
                <h3>Detail alat: <span id="nama-alat"></span> <span id="median-alat" class="redup" style="font-size: 13px; font-weight: 400;"></span></h3>
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <div class="legenda"><span><i class="kotak" style="background: var(--b-solar);"></i>Solar keluar (L)</span><span><i class="kotak" style="background: var(--b-hm);"></i>Jam HM (×10)</span><span><i class="garis"></i>Rasio L/jam</span></div>
                    <button type="button" class="tutup-detail" id="tutup-detail">Tutup</button>
                </div>
            </div>
            <svg class="grafik" id="grafik-alat" viewBox="0 0 1000 300" role="img" aria-label="Grafik detail alat"></svg>
            <div class="tooltip" hidden></div>
        </div>

        {{-- Per alat --}}
        <div class="kartu gulir" style="padding: 0; border-radius: 12px;">
            <div class="tabel-kepala">
                <div>
                    <h3>Per alat</h3>
                    <p>Klik baris (atau Enter) untuk melihat grafik alat itu. Rasio dibandingkan dengan <b>median bulanan</b> alat itu sendiri.</p>
                </div>
                <div class="saring-kat" role="group" aria-label="Saring kategori alat">
                    <button type="button" data-kat="" aria-pressed="false">Semua<span>{{ count($alat) }}</span></button>
                    @foreach ($kategoriAda as $k => $n)
                        <button type="button" data-kat="{{ $k }}" aria-pressed="false">{{ $k }}<span>{{ $n }}</span></button>
                    @endforeach
                </div>
            </div>
            <table class="kas" id="tabel-alat">
                <thead><tr><th>Alat</th><th>Kategori</th><th class="angka">Solar (L)</th><th class="angka">Jam HM</th><th class="angka" title="Total liter ÷ total jam HM dari bulan yang HM-nya tercatat">Rasio periode (L/jam)</th>
                    <th class="angka" title="Patokan wajar alat itu — tidak terpengaruh bulan yang janggal">Median bulanan</th><th>Tren rasio per bulan</th><th class="angka" title="Bulan dengan isi solar tetapi tanpa jam HM sama sekali (hanya alat berat — dump truck tidak punya HM meter)">Bulan HM kosong</th>
                    <th class="angka" title="Bulan yang rasionya jauh dari median (> 1,6× / < 1/1,6) — biasanya HM tidak lengkap">Bulan janggal</th></tr></thead>
                <tbody>
                    @foreach ($alat as $i => $a)
                        @php($berat = $a['kategori'] === 'Alat Berat')
                        @php($sel = $a['rasio'] && $a['median'] ? ($a['rasio'] - $a['median']) / $a['median'] * 100 : null)
                        <tr class="pilih-alat" data-i="{{ $i }}" data-kat="{{ $a['kategori'] ?: 'Lainnya' }}" tabindex="0" role="button" aria-label="Lihat grafik {{ $a['unit'] }}">
                            <td><b>{{ $a['unit'] }}</b></td>
                            <td class="redup">{{ $a['kategori'] }}</td>
                            <td class="angka" data-n="{{ $a['liter'] }}"><div class="bar-isi"><span class="jalur" aria-hidden="true"><span style="width: {{ round($a['liter'] / $maksAlat * 100, 1) }}%;"></span></span>{{ $l($a['liter'], 0) }}</div></td>
                            <td class="angka" data-n="{{ $a['hm'] }}">@if ($a['hm'] > 0){{ $l($a['hm']) }}@else<span class="redup-angka">–</span>@endif</td>
                            <td class="angka" data-n="{{ $a['rasio'] }}">{{ $l($a['rasio'], 2) }}@if ($sel !== null && abs($sel) >= 15)<span class="lencana {{ $sel > 0 ? 'tinggi' : 'rendah' }}" title="Rasio periode {{ $sel > 0 ? 'di atas' : 'di bawah' }} median {{ number_format(abs($sel), 0, ',', '.') }}%">{{ $sel > 0 ? '+' : '−' }}{{ number_format(abs($sel), 0, ',', '.') }}%</span>@endif</td>
                            <td class="angka" data-n="{{ $a['median'] }}"><b>{{ $l($a['median'], 2) }}</b></td>
                            <td data-tanpa-saring><svg class="mini" data-mini="{{ $i }}" viewBox="0 0 140 28" preserveAspectRatio="none" aria-hidden="true"></svg></td>
                            <td class="angka" data-n="{{ $a['hm_kosong'] }}">@if ($a['hm_kosong'] && $berat)<span style="color: var(--merah); font-weight: 600;">{{ $a['hm_kosong'] }}</span>@elseif ($a['hm_kosong'])<span class="redup-angka" title="Bukan alat berat — tidak wajib HM">{{ $a['hm_kosong'] }}</span>@else<span class="redup-angka">–</span>@endif</td>
                            <td class="angka" data-n="{{ $a['janggal'] }}">@if ($a['janggal'])<span style="color: var(--b-rasio); font-weight: 600;">{{ $a['janggal'] }}</span>@else<span class="redup-angka">–</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="dua" style="margin-top: 14px;">
            <div class="kartu gulir" style="padding: 0; border-radius: 12px;">
                <div class="tabel-kepala"><h3>Per proyek</h3></div>
                <table class="kas">
                    <thead><tr><th>Proyek</th><th class="angka">Tahap</th><th class="angka">Solar (L)</th><th class="angka">Jam HM</th><th class="angka">L/jam</th></tr></thead>
                    <tbody>
                        @foreach ($perProyek as $p => $x)
                            <tr><td>{{ $p }}</td><td class="angka">{{ $x['tahap'] }}</td>
                                <td class="angka" data-n="{{ $x['l'] }}"><div class="bar-isi"><span class="jalur" aria-hidden="true"><span style="width: {{ round($x['l'] / $maksProyek * 100, 1) }}%;"></span></span>{{ $l($x['l'], 0) }}</div></td>
                                <td class="angka" data-n="{{ $x['h'] }}">{{ $l($x['h']) }}</td><td class="angka">{{ $x['h'] > 0 ? $l($x['l'] / $x['h'], 2) : '–' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="kartu gulir" style="padding: 0; border-radius: 12px;">
                <div class="tabel-kepala"><h3>Per tahap</h3></div>
                <table class="kas">
                    <thead><tr><th>Tahap</th><th>Proyek</th><th class="angka">Solar (L)</th><th class="angka">Jam HM</th><th class="angka">L/jam</th></tr></thead>
                    <tbody>
                        @foreach ($perTahap as $t)
                            <tr><td>{{ $t['tahap'] }}</td><td class="redup">{{ $t['proyek'] }}</td>
                                <td class="angka" data-n="{{ $t['l'] }}"><div class="bar-isi"><span class="jalur" aria-hidden="true"><span style="width: {{ round($t['l'] / $maksTahap * 100, 1) }}%;"></span></span>{{ $l($t['l'], 0) }}</div></td>
                                <td class="angka" data-n="{{ $t['h'] }}">{{ $l($t['h']) }}</td><td class="angka">{{ $t['h'] > 0 ? $l($t['l'] / $t['h'], 2) : '–' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <details class="kartu metode" style="padding: 0; margin-top: 14px; border-radius: 12px;">
            <summary>Cara membaca rasio L/jam</summary>
            <div class="isi">
                Solar diisi sebagian-sebagian (100–200 L), jadi rasio per kali isi tidak bisa dipakai. Rasio di dashboard ini dihitung dari
                <b>jumlah sebulan</b>: total liter ÷ total jam HM. Kelebihan/kekurangan isi di awal dan akhir bulan paling banyak sebesar isi tangki, sehingga makin banyak
                jam kerja dalam sebulan, makin kecil pengaruhnya.
                <ul style="margin: 6px 0 0; padding-left: 18px;">
                    <li>Bulan yang ada solar tetapi <b>tanpa jam HM</b> tidak ikut dihitung rasionya ("Bulan HM kosong" — merah hanya untuk alat berat; dump truck tidak punya HM meter).</li>
                    <li>Bulan yang rasionya jauh dari median alat itu ditandai "Bulan janggal" — biasanya karena HM sebagian hari tidak dicatat. Bulan janggal tidak ikut dalam rasio keseluruhan.</li>
                    <li>Patokan wajar tiap alat = <b>median bulanan</b>. Lencana <span class="lencana tinggi">+20%</span> / <span class="lencana rendah">−20%</span> = rasio periode menyimpang ≥ 15% dari median.</li>
                    <li>Perbandingan di kartu atas memakai bulan lengkap terakhir dibanding bulan sebelumnya; bulan berjalan tidak dibandingkan.</li>
                </ul>
            </div>
        </details>
    </div>

    <script>
        (() => {
            const BULAN = @json(array_map($namaBulan, $daftarBulan));
            const BULAN_PANJANG = @json(array_map($namaPanjang, $daftarBulan));
            const TOTAL = @json($seri);
            const ALAT = @json($alat);
            const BERJALAN = @json($berjalan);
            const fmt = (n, d = 0) => n === null || n === undefined ? '–' : Number(n).toLocaleString('id-ID', {maximumFractionDigits: d});
            const ns = 'http://www.w3.org/2000/svg';
            const el = (tag, attr, isi) => { const e = document.createElementNS(ns, tag); for (const k in attr) e.setAttribute(k, attr[k]); if (isi !== undefined) e.textContent = isi; return e; };
            const atas = v => { if (!v) return 1; const p = Math.pow(10, Math.floor(Math.log10(v))); return Math.ceil(v / p * 2) / 2 * p; };

            // Sparkline kartu KPI (12 titik); bulan berjalan digambar putus-putus karena belum lengkap.
            document.querySelectorAll('svg[data-spark]').forEach(svg => {
                const v = TOTAL.map(t => t[svg.dataset.spark]);
                const ada = v.filter(x => x !== null && x !== undefined);
                if (ada.length < 2) return;
                const maks = Math.max(...ada) || 1, min = Math.min(0, ...ada), n = v.length, warna = svg.dataset.warna;
                const xy = i => [4 + 192 * i / Math.max(n - 1, 1), 27 - 23 * (v[i] - min) / (maks - min || 1)];
                let d = '', dAkhir = '';
                v.forEach((x, i) => {
                    if (x === null || x === undefined) return;
                    const [px, py] = xy(i);
                    if (BERJALAN && i === n - 1 && v[i - 1] !== null) { const [ax, ay] = xy(i - 1); dAkhir = `M${ax} ${ay}L${px} ${py}`; return; }
                    d += (d && v[i - 1] !== null ? 'L' : 'M') + px + ' ' + py;
                });
                svg.appendChild(el('path', {d, fill: 'none', stroke: warna, 'stroke-width': 2, 'stroke-linejoin': 'round', 'vector-effect': 'non-scaling-stroke'}));
                if (dAkhir) svg.appendChild(el('path', {d: dAkhir, fill: 'none', stroke: warna, 'stroke-width': 2, 'stroke-dasharray': '3 3', 'vector-effect': 'non-scaling-stroke', opacity: .7}));
            });

            // Grafik batang + garis rasio. Angka liter di atas batang; angka rasio di BARIS SENDIRI di bawah nama bulan (tidak bertabrakan).
            // Bulan berjalan diarsir. Arahkan kursor / fokus (Tab) ke kolom bulan → rincian bulan itu.
            const gambar = (svg, label, batang, rasio, info) => {
                svg.innerHTML = '';
                svg._isi = [label, batang, rasio, info];
                const W = Math.max(320, Math.round(svg.clientWidth || 1000)), H = 300, kiri = 56, kanan = 46, atasP = 22, bawah = 58;
                svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
                const defs = el('defs', {});
                batang.forEach((s, j) => {
                    const pola = el('pattern', {id: `arsir-${svg.id}-${j}`, width: 6, height: 6, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)'});
                    pola.appendChild(el('rect', {width: 3, height: 6, class: s.kelas}));
                    defs.appendChild(pola);
                });
                svg.appendChild(defs);
                const lebarTeks = t => t.length * 6.8;
                const tulis = (x, y, t, kelas) => svg.appendChild(el('text', {x, y, 'text-anchor': 'middle', class: kelas + ' halo'}, t));
                const lebar = W - kiri - kanan, tinggi = H - atasP - bawah, n = label.length;
                const maksB = atas(Math.max(1, ...batang.flatMap(s => s.nilai)));
                const r = rasio.filter(v => v !== null);
                const maksR = atas(r.length ? Math.max(...r) * 1.15 : 1);
                for (let g = 0; g <= 4; g++) {
                    const y = atasP + tinggi - tinggi * g / 4;
                    svg.appendChild(el('line', {x1: kiri, x2: W - kanan, y1: y, y2: y, class: 'sumbu', 'stroke-dasharray': g ? '3 4' : ''}));
                    svg.appendChild(el('text', {x: kiri - 6, y: y + 4, 'text-anchor': 'end'}, fmt(maksB * g / 4)));
                    svg.appendChild(el('text', {x: W - kanan + 6, y: y + 4}, fmt(maksR * g / 4, 1)));
                }
                svg.appendChild(el('text', {x: kiri - 6, y: H - 10, 'text-anchor': 'end', class: 'ket-baris'}, 'L/jam'));
                const slot = lebar / n, lb = Math.min(46, slot * 0.66) / batang.length;
                const yRasio = i => rasio[i] === null ? null : atasP + tinggi - tinggi * rasio[i] / maksR;
                const tampilLabel = i => n <= 13 || i % Math.ceil(n / 12) === 0;
                label.forEach((t, i) => {
                    const x0 = kiri + slot * i + (slot - lb * batang.length) / 2, akhir = BERJALAN && i === n - 1;
                    batang.forEach((s, j) => {
                        const h = tinggi * s.nilai[i] / maksB;
                        svg.appendChild(el('rect', {x: x0 + lb * j, y: atasP + tinggi - h, width: Math.max(lb - 2, 1), height: h, rx: Math.min(3, h / 2),
                            ...(akhir ? {fill: `url(#arsir-${svg.id}-${j})`, stroke: getComputedStyle(svg).getPropertyValue(j ? '--b-hm' : '--b-solar') || '#5b8def', 'stroke-width': 1} : {class: s.kelas})}));
                        const v = s.asli ? s.asli[i] : s.nilai[i];
                        if (s.angka && v && lebarTeks(fmt(v, 1)) <= slot - 2) {
                            // di atas batang; bila menabrak titik rasio → di dalam ujung batang (bila cukup tinggi) atau di atas titik
                            let y = atasP + tinggi - h - 6;
                            const yr = yRasio(i);
                            if (yr !== null && Math.abs(y - 4 - yr) < 14) {
                                y = h > 34 ? atasP + tinggi - h + 16 : Math.min(y, yr - 12);
                                if (h > 56 && Math.abs(y - 4 - yr) < 12) y = Math.max(y, yr + 22); // masih dekat titik → turun di bawah titik
                            }
                            tulis(x0 + lb * j + Math.max(lb - 2, 1) / 2, y, fmt(v, 0), 'nilai');
                        }
                    });
                    if (tampilLabel(i)) svg.appendChild(el('text', {x: kiri + slot * (i + .5), y: H - 34, 'text-anchor': 'middle'}, t + (akhir ? '*' : '')));
                    if (rasio[i] !== null && tampilLabel(i)) svg.appendChild(el('text', {x: kiri + slot * (i + .5), y: H - 12, 'text-anchor': 'middle', class: 'nilai-rasio'}, fmt(rasio[i], 2)));
                });
                let d = '';
                rasio.forEach((v, i) => {
                    if (v === null) return;
                    const x = kiri + slot * (i + .5), y = atasP + tinggi - tinggi * v / maksR;
                    d += (d && rasio[i - 1] !== null ? 'L' : 'M') + x + ' ' + y;
                });
                svg.appendChild(el('path', {d, class: 'garis-rasio', ...(BERJALAN ? {} : {})}));
                rasio.forEach((v, i) => {
                    if (v === null) return;
                    const x = kiri + slot * (i + .5), y = atasP + tinggi - tinggi * v / maksR;
                    svg.appendChild(el('rect', {x: x - 4.5, y: y - 4.5, width: 9, height: 9, class: 'titik', transform: `rotate(45 ${x} ${y})`}));
                });
                // Zona per bulan untuk tooltip (mouse & keyboard).
                const tip = svg.parentElement.querySelector('.tooltip');
                label.forEach((t, i) => {
                    const z = el('rect', {x: kiri + slot * i, y: atasP, width: slot, height: tinggi, class: 'zona', tabindex: 0, role: 'img', 'aria-label': info(i).map(([a, b]) => `${a} ${b}`).join(', ')});
                    const tampil = () => {
                        tip.innerHTML = `<b>${BULAN_PANJANG[i]}${BERJALAN && i === n - 1 ? ' (berjalan)' : ''}</b>` + info(i).map(([a, b]) => `<div class="bar"><span>${a}</span><span>${b}</span></div>`).join('');
                        tip.hidden = false;
                        const kotak = svg.getBoundingClientRect(), panel = svg.parentElement.getBoundingClientRect();
                        const px = (kiri + slot * (i + .5)) / W * kotak.width + kotak.left - panel.left;
                        tip.style.left = Math.min(panel.width - tip.offsetWidth - 8, Math.max(8, px - tip.offsetWidth / 2)) + 'px';
                        tip.style.top = (kotak.top - panel.top + 6) + 'px';
                    };
                    z.addEventListener('mouseenter', tampil); z.addEventListener('focus', tampil);
                    z.addEventListener('mouseleave', () => { tip.hidden = true; }); z.addEventListener('blur', () => { tip.hidden = true; });
                    svg.appendChild(z);
                });
            };
            let jedaUkur;
            window.addEventListener('resize', () => { clearTimeout(jedaUkur); jedaUkur = setTimeout(() => document.querySelectorAll('svg.grafik').forEach(g => {
                if (g._isi && g.clientWidth) gambar(g, ...g._isi);
            }), 150); });

            gambar(document.getElementById('grafik-total'), BULAN, [{nama: 'Solar keluar (L)', kelas: 'batang', angka: true, nilai: TOTAL.map(t => t.l)}], TOTAL.map(t => t.r),
                i => [['Solar keluar', fmt(TOTAL[i].l) + ' L'], ['Jam HM', fmt(TOTAL[i].h, 1) + ' jam'], ['Rasio', TOTAL[i].r === null ? '–' : fmt(TOTAL[i].r, 2) + ' L/jam'], ['Solar masuk', fmt(TOTAL[i].masuk) + ' L']]);

            // Grafik mini rasio per bulan di tabel per alat (garis putus-putus = median alat).
            document.querySelectorAll('svg[data-mini]').forEach(svg => {
                const a = ALAT[svg.dataset.mini], v = Object.values(a.bulan).map(b => b.r);
                const ada = v.filter(x => x !== null); if (!ada.length) return;
                const maks = Math.max(...ada) || 1, n = v.length;
                if (a.median) { const y = 24 - 20 * a.median / maks; svg.appendChild(el('line', {x1: 0, x2: 140, y1: y, y2: y, stroke: '#4a5160', 'stroke-dasharray': '2 3'})); }
                let d = '';
                v.forEach((x, i) => { if (x === null) return; const px = 4 + 132 * i / Math.max(n - 1, 1), py = 24 - 20 * x / maks; d += (d && v[i - 1] !== null ? 'L' : 'M') + px + ' ' + py; svg.appendChild(el('circle', {cx: px, cy: py, r: 1.8, fill: '#f0c05a'})); });
                svg.appendChild(el('path', {d, fill: 'none', stroke: '#f0c05a', 'stroke-width': 1.5}));
                svg.appendChild(el('title', {}, Object.entries(a.bulan).map(([m, b]) => `${m}: ${b.r === null ? (b.l ? 'HM kosong' : '–') : fmt(b.r, 2) + ' L/jam'}`).join('\n')));
            });

            // Saring kategori alat (tombol): disimpan di URL hash supaya tetap saat muat ulang. Bawaan: Alat Berat bila ada.
            const tombolKat = [...document.querySelectorAll('.saring-kat button')];
            const pilihKat = k => {
                tombolKat.forEach(b => b.setAttribute('aria-pressed', String(b.dataset.kat === k)));
                document.querySelectorAll('#tabel-alat tr.pilih-alat').forEach(tr => tr.classList.toggle('sembunyi-kat', k !== '' && tr.dataset.kat !== k));
                try { history.replaceState(null, '', k ? '#kategori=' + encodeURIComponent(k) : location.pathname + location.search); } catch (e) {}
            };
            tombolKat.forEach(b => b.addEventListener('click', () => pilihKat(b.dataset.kat)));
            const dariHash = decodeURIComponent((location.hash.match(/kategori=([^&]+)/) || [])[1] || '');
            pilihKat(dariHash && tombolKat.some(b => b.dataset.kat === dariHash) ? dariHash : (tombolKat.some(b => b.dataset.kat === 'Alat Berat') ? 'Alat Berat' : ''));

            // Klik / Enter pada baris alat → grafik detail.
            const detail = document.getElementById('detail-alat');
            const bukaAlat = tr => {
                document.querySelectorAll('tr.pilih-alat.aktif').forEach(x => x.classList.remove('aktif'));
                tr.classList.add('aktif');
                const a = ALAT[tr.dataset.i], b = Object.values(a.bulan);
                detail.hidden = false;
                document.getElementById('nama-alat').textContent = a.unit;
                document.getElementById('median-alat').textContent = a.median ? `· median ${fmt(a.median, 2)} L/jam` : '';
                gambar(document.getElementById('grafik-alat'), BULAN, [
                    {nama: 'Solar keluar (L)', kelas: 'batang', angka: true, nilai: b.map(x => x.l)},
                    {nama: 'Jam HM', kelas: 'batang2', nilai: b.map(x => x.h * 10), asli: b.map(x => x.h)},
                ], b.map(x => x.r), i => [['Solar keluar', fmt(b[i].l) + ' L'], ['Jam HM', fmt(b[i].h, 1) + ' jam'], ['Rasio', b[i].r === null ? (b[i].l ? 'HM kosong' : '–') : fmt(b[i].r, 2) + ' L/jam']]);
                detail.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest'});
            };
            document.querySelectorAll('tr.pilih-alat').forEach(tr => {
                tr.addEventListener('click', () => bukaAlat(tr));
                tr.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); bukaAlat(tr); } });
            });
            document.getElementById('tutup-detail').addEventListener('click', () => {
                detail.hidden = true;
                document.querySelectorAll('tr.pilih-alat.aktif').forEach(x => { x.classList.remove('aktif'); x.focus(); });
            });
        })();
    </script>
@endsection
