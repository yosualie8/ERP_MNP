@extends('layouts.app', ['judul' => 'Dashboard Penggunaan BBM'])

@section('lebar', '1500px')

@section('isi')
    @include('partials.gaya-dasbor')
    @php
        // Angka Indonesia; nol di belakang koma dibuang.
        $l = function ($v, $d = 1) { if ($v === null) return '–'; $t = number_format((float) $v, $d, ',', '.'); return $d > 0 ? rtrim(rtrim($t, '0'), ',') : $t; };
        $tgl = fn ($t, $f = 'j M Y') => $t ? \Illuminate\Support\Carbon::parse($t)->translatedFormat($f) : '–';
        $persen = fn ($v) => $total > 0 ? number_format($v / $total * 100, 1, ',', '.').'%' : '–';
        $warna = ['#5b8def', '#3fae7a', '#f0c05a', '#a78bfa', '#ef7d5b', '#4fc1c9', '#d16ba5', '#8d99ae'];
        $maksUnit = max(1, $perUnit->max('liter'));
        $maksProyek = max(1, $perProyek->max('liter'));
        $jenisWarna = ['masuk' => 'hijau', 'pindah' => 'kuning', 'susut' => 'merah', 'opname' => 'kuning', 'operasional' => ''];
        $jenisLabel = ['pakai' => 'Pakai', 'pindah' => 'Pindah', 'susut' => 'Susut', 'opname' => 'Opname', 'operasional' => 'Operasional'];
    @endphp
    <style>
        .pbbm { font-variant-numeric: tabular-nums; }
        .pbbm .form-liter { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 14px; }
        .pbbm .form-liter label { display: flex; flex-direction: column; gap: 4px; }
        .pbbm .form-liter input { width: 160px; font-size: 18px; font-weight: 600; }
        .pbbm .cepat { display: flex; gap: 6px; flex-wrap: wrap; }
        .pbbm .komposisi { display: flex; height: 26px; border-radius: 7px; overflow: hidden; margin: 10px 0 8px; background: var(--kartu-2); }
        .pbbm .komposisi span { display: block; height: 100%; min-width: 2px; }
        .pbbm .legenda-k { display: flex; gap: 6px 18px; flex-wrap: wrap; font-size: 13px; }
        .pbbm .legenda-k span { display: inline-flex; align-items: center; gap: 6px; }
        .pbbm .legenda-k i { width: 11px; height: 11px; border-radius: 3px; display: inline-block; }
        .pbbm .legenda-k b { font-weight: 600; }
        .pbbm .dua { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        @media (max-width: 1600px) { .pbbm .dua { grid-template-columns: 1fr; } }
        .pbbm .tabel-kepala { padding: 14px 16px 8px; }
        .pbbm .tabel-kepala h3 { margin: 0; font-size: 16px; }
        .pbbm .tabel-kepala p { margin: 4px 0 0; color: var(--redup); font-size: 13px; }
        .pbbm .bar-isi { display: flex; align-items: center; gap: 8px; justify-content: flex-end; }
        .pbbm .bar-isi .jalur { flex: 1; max-width: 90px; height: 6px; background: var(--kartu-2); border-radius: 3px; overflow: hidden; }
        .pbbm .bar-isi .jalur span { display: block; height: 100%; background: #5b8def; border-radius: 3px; }
        .pbbm .kecil { font-size: 12.5px; color: var(--redup); }
        .pbbm tr.sebagian td { background: rgba(240, 192, 90, .07); }
    </style>

    <div class="pbbm">
        <form method="GET" action="{{ route('bbm.penggunaan') }}" class="form-liter">
            <label>Jumlah liter terakhir
                <input type="text" name="liter" inputmode="decimal" value="{{ $l($target) }}" autocomplete="off" required>
            </label>
            <button type="submit" class="tombol" style="padding: 9px 18px;">Tampilkan</button>
            <div class="cepat" aria-label="Pilihan cepat">
                @foreach ([500, 1000, 2000, 5000, 10000] as $n)
                    <a href="{{ route('bbm.penggunaan', ['liter' => $n]) }}" @class(['chip', 'aktif' => (float) $n === (float) $target])>{{ $l($n, 0) }} L</a>
                @endforeach
            </div>
        </form>

        @if (! $rinci)
            <div class="kartu">Belum ada data solar keluar.</div>
        @else
            @if ($kurang)
                <div class="pesan galat" style="margin-bottom: 12px;">Seluruh data solar keluar yang tercatat hanya {{ $l($total) }} L — kurang dari {{ $l($target) }} L yang diminta.</div>
            @endif

            <div class="kpi-baris">
                <div class="kpi">
                    <div class="kpi-label"><i style="background: #5b8def;"></i>Solar keluar ditelusuri</div>
                    <div class="kpi-nilai">{{ $l($total) }}<small>liter</small></div>
                    <div class="kpi-sub">{{ number_format(count($rinci), 0, ',', '.') }} kali isi · {{ $perUnit->count() }} unit / tujuan</div>
                </div>
                <div class="kpi">
                    <div class="kpi-label"><i style="background: #a78bfa;"></i>Periode</div>
                    <div class="kpi-nilai" style="font-size: 21px;">{{ $tgl($awal, 'j M') }} – {{ $tgl($akhir, 'j M Y') }}</div>
                    <div class="kpi-sub">{{ $hari }} hari · rata-rata {{ $l($hari ? $total / $hari : null, 0) }} L/hari</div>
                </div>
                <div class="kpi">
                    <div class="kpi-label"><i style="background: #3fae7a;"></i>Solar masuk di periode yang sama</div>
                    <div class="kpi-nilai">{{ $l($masuk, 0) }}<small>liter</small></div>
                    <div class="kpi-sub">Stok sekarang <b style="color: var(--teks);">{{ $l($stok, 0) }} L</b></div>
                </div>
            </div>

            {{-- Komposisi per jenis --}}
            <div class="panel">
                <div class="judul"><h3>Habis ke mana</h3><span class="kecil">dari {{ $l($total) }} L terakhir</span></div>
                <div class="komposisi" role="img" aria-label="{{ $perKelompok->map(fn ($k) => $k['nama'].' '.$persen($k['liter']))->join(', ') }}">
                    @foreach ($perKelompok as $i => $k)
                        <span style="width: {{ $k['liter'] / max($total, 1) * 100 }}%; background: {{ $warna[$i % count($warna)] }};" title="{{ $k['nama'] }}: {{ $l($k['liter']) }} L ({{ $persen($k['liter']) }})"></span>
                    @endforeach
                </div>
                <div class="legenda-k">
                    @foreach ($perKelompok as $i => $k)
                        <span><i style="background: {{ $warna[$i % count($warna)] }};"></i>{{ $k['nama'] }} <b>{{ $l($k['liter']) }} L</b> <span class="redup">{{ $persen($k['liter']) }} · {{ $k['kali'] }}×</span></span>
                    @endforeach
                </div>
            </div>

            <div class="dua">
                <div class="kartu gulir" style="padding: 0; border-radius: 12px;">
                    <div class="tabel-kepala"><h3>Per unit</h3><p>Aset yang diisi solar, atau tujuan lain (susut, pindah lokasi, opname).</p></div>
                    <table class="kas">
                        <thead><tr><th>Unit</th><th>Kategori</th><th class="angka">Solar (L)</th><th class="angka">%</th><th class="angka">Kali isi</th><th class="angka">Jam HM</th><th>Proyek</th></tr></thead>
                        <tbody>
                            @foreach ($perUnit as $u)
                                <tr>
                                    <td><b>{{ $u['nama'] }}</b></td>
                                    <td class="redup">{{ $u['kelompok'] }}</td>
                                    <td class="angka" data-n="{{ $u['liter'] }}"><div class="bar-isi"><span class="jalur" aria-hidden="true"><span style="width: {{ round($u['liter'] / $maksUnit * 100, 1) }}%;"></span></span>{{ $l($u['liter']) }}</div></td>
                                    <td class="angka" data-n="{{ $u['liter'] }}">{{ $persen($u['liter']) }}</td>
                                    <td class="angka">{{ $u['kali'] }}</td>
                                    <td class="angka" data-n="{{ $u['hm'] }}">{{ $u['hm'] > 0 ? $l($u['hm']) : '–' }}</td>
                                    <td class="kecil">{{ implode(', ', $u['proyek']) ?: '–' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="kartu gulir" style="padding: 0; border-radius: 12px;">
                    <div class="tabel-kepala"><h3>Per proyek</h3><p>Isian untuk beberapa proyek sekaligus dibagi rata.</p></div>
                    <table class="kas">
                        <thead><tr><th>Proyek / tahap</th><th class="angka">Solar (L)</th><th class="angka">%</th><th class="angka">Kali isi</th><th>Unit</th></tr></thead>
                        <tbody>
                            @foreach ($perProyek as $p)
                                <tr>
                                    <td>{{ $p['nama'] }}</td>
                                    <td class="angka" data-n="{{ $p['liter'] }}"><div class="bar-isi"><span class="jalur" aria-hidden="true"><span style="width: {{ round($p['liter'] / $maksProyek * 100, 1) }}%;"></span></span>{{ $l($p['liter']) }}</div></td>
                                    <td class="angka" data-n="{{ $p['liter'] }}">{{ $persen($p['liter']) }}</td>
                                    <td class="angka">{{ $p['kali'] }}</td>
                                    <td class="kecil">{{ implode(', ', $p['unit']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="kartu gulir" style="padding: 0; border-radius: 12px;">
                <div class="tabel-kepala"><h3>Riwayat solar keluar</h3><p>Terbaru di atas. Kumulatif berhenti di {{ $l($total) }} L; isian paling lama yang melewati batas hanya dihitung sebagian (ditandai).</p></div>
                <table class="kas">
                    <thead><tr><th>Tanggal</th><th>Unit</th><th>Jenis</th><th>Proyek</th><th class="angka">Liter isi</th><th class="angka">Dihitung</th><th class="angka">Kumulatif</th><th class="angka">Jam HM</th><th>Keterangan</th></tr></thead>
                    <tbody>
                        @foreach ($rinci as $r)
                            @php($sebagian = $r['dihitung'] < $r['liter'])
                            <tr @class(['sebagian' => $sebagian])>
                                <td data-n="{{ $r['tanggal'] }}">
                                    @if ($bolehData)<a href="{{ route('bbm.index', ['bulan' => substr($r['tanggal'], 0, 7)]) }}">{{ $tgl($r['tanggal'], 'D, j M Y') }}</a>@else{{ $tgl($r['tanggal'], 'D, j M Y') }}@endif
                                </td>
                                <td><b>{{ $r['unit'] }}</b>@if ($r['kategori'])<div class="kecil">{{ $r['kategori'] }}</div>@endif</td>
                                <td>@if ($r['jenis'] === 'pakai')<span class="redup">Pakai</span>@else<span class="label {{ $jenisWarna[$r['jenis']] ?? '' }}">{{ $jenisLabel[$r['jenis']] ?? $r['jenis'] }}</span>@endif</td>
                                <td>{{ implode(' + ', $r['proyek']) ?: '–' }}</td>
                                <td class="angka" data-n="{{ $r['liter'] }}">{{ $l($r['liter']) }}</td>
                                <td class="angka" data-n="{{ $r['dihitung'] }}">@if ($sebagian)<b style="color: #f0c05a;" title="Hanya {{ $l($r['dihitung']) }} dari {{ $l($r['liter']) }} L yang masuk hitungan">{{ $l($r['dihitung']) }}</b>@else{{ $l($r['dihitung']) }}@endif</td>
                                <td class="angka" data-n="{{ $r['kumulatif'] }}">{{ $l($r['kumulatif']) }}</td>
                                <td class="angka" data-n="{{ $r['hm'] }}">{{ $r['hm'] ? $l($r['hm']) : '–' }}</td>
                                <td class="kecil">{{ $r['keterangan'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
