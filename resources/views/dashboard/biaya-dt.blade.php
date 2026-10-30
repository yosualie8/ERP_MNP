@extends('layouts.app', ['judul' => 'Dashboard Biaya DT'])

@section('lebar', '1600px')

@section('isi')
    @php
        $namaBulan = fn ($b) => \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M y');
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-y') : '';
        $pp = $periode->param();
        // Tautan halaman ini dengan parameter yang diganti.
        $url = fn (array $ganti = []) => route('dashboard.biaya-dt', array_filter($ganti + $bawa + $pp, fn ($v) => $v !== null && $v !== ''));
        $persen = fn ($a, $b) => $b ? number_format($a / $b * 100, 1, ',', '.').'%' : '';
        $bolehUj = auth()->user()->bolehMenu('kas-uj');
        $bolehKas = auth()->user()->bolehMenu('kas-harian');
        $warnaKelompok = ['Perjalanan' => '#4ea1ff', 'Perawatan' => '#f5a524', 'Material' => '#a78bfa', 'Lain-lain' => '#8b93a1'];
    @endphp
    @include('dashboard.gaya')
    <style>
        .biaya-dt td.kel { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
        .biaya-dt td.nol { color: var(--redup); }
        .biaya-dt tr.total td { font-weight: 700; border-top: 2px solid var(--garis-kuat); background: var(--kartu-2); }
        .biaya-dt tr.mati td { opacity: .45; }
        .biaya-dt tr.pilih td { background: var(--aksen-muda); }
        .biaya-dt a.dt { font-weight: 700; text-decoration: none; }
        .saring-kat { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px; }
        .saring-kat label { display: inline-flex; gap: 5px; align-items: center; font-size: 13px; padding: 3px 9px; border: 1px solid var(--garis); border-radius: 999px; cursor: pointer; margin: 0; }
        .saring-kat label.on { border-color: var(--aksen); background: var(--aksen-muda); }
        .titik { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; }
    </style>

    @include('partials.periode', ['rute' => 'dashboard.biaya-dt', 'bawa' => $bawa])

    <p class="redup" style="font-size: 13px; margin: -4px 0 12px;">
        Sumber: <b>Kas UJ</b> (per No Mobil) + <b>Kas Harian</b> (detail ber-No Mobil, kategori dari Akun GL). Transfer Kas Harian "Reimburse Uang Jalan" tidak dihitung lagi
        karena isinya sama dengan Kas UJ. Hanya dump truck di Data Aset · {{ $periode->label() }}.
    </p>

    {{-- Saring kategori --}}
    <form method="GET" action="{{ route('dashboard.biaya-dt') }}" class="saring-kat" id="form-kat">
        @foreach ($pp + array_diff_key($bawa, ['kat' => 1]) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <span class="redup" style="font-size: 13px;">Kategori:</span>
        <a href="{{ $url(['kat' => null, 'tanpa' => null]) }}" @class(['chip', 'aktif' => $semua]) style="margin: 0;">Semua</a>
        <a href="{{ route('dashboard.biaya-dt', array_filter(['tanpa' => 'material', 'tampil' => $bawa['tampil'] ?? null, 'mobil' => $bawa['mobil'] ?? null] + $pp)) }}" class="chip" style="margin: 0;">Tanpa material (tanah & material)</a>
        @foreach ($kategori as $k)
            @php($on = $semua || $pilih->contains($k['nama']))
            <label @class(['on' => $on && ! $semua])><input type="checkbox" name="kat[]" value="{{ $k['nama'] }}" @checked($on && ! $semua)>
                <span class="titik" style="background: {{ $warnaKelompok[$k['kelompok']] }}"></span>{{ $k['nama'] }}</label>
        @endforeach
        <button type="submit" class="tombol polos" style="padding: 4px 12px; font-size: 13px;">Terapkan</button>
    </form>

    <div class="dash-kartu">
        <div class="kartu">
            <h4>Total biaya DT{{ $semua ? '' : ' (kategori terpilih)' }}</h4>
            <div class="angka-besar">{{ rp($ringkas['total']) }}</div>
            <div class="dash-baris"><span>Rata-rata per bulan</span><b>{{ rp($ringkas['rata_bulan']) }}</b></div>
            <div class="dash-baris"><span>Dari Kas UJ</span><span>{{ rp($ringkas['sumber']['uj']) }}</span></div>
            <div class="dash-baris"><span>Dari Kas Harian</span><span>{{ rp($ringkas['sumber']['kas']) }}</span></div>
        </div>
        <div class="kartu">
            <h4>Per truk</h4>
            <div class="angka-besar">{{ $ringkas['truk'] }} <span style="font-size: 15px; font-weight: 400;">DT berbiaya</span></div>
            <div class="dash-baris"><span>Rata-rata per DT per bulan</span><b>{{ rp($ringkas['rata_truk_bulan']) }}</b></div>
            <div class="dash-baris"><span>Jumlah rit (lembar Ritasi)</span><span>{{ rp($ringkas['rit']) }} rit</span></div>
            <div class="dash-baris"><span>Biaya per rit</span><b>{{ $ringkas['per_rit'] !== null ? rp($ringkas['per_rit']) : '—' }}</b></div>
        </div>
        <div class="kartu">
            <h4>Per kelompok</h4>
            @foreach ($katTampil->groupBy('kelompok') as $kel => $g)
                <div class="dash-baris"><span><span class="titik" style="background: {{ $warnaKelompok[$kel] }}"></span>{{ $kel }}</span>
                    <span>{{ rp($g->sum('rp')) }} <span class="redup">· {{ $persen($g->sum('rp'), $ringkas['total']) }}</span></span></div>
            @endforeach
        </div>
    </div>

    {{-- Kategori × bulan --}}
    <div class="kartu gulir biaya-dt" style="padding: 0;">
        <div style="padding: 12px 14px 0;"><b class="dash-judul">Biaya per kategori per bulan</b></div>
        <table class="kas" data-tanpa-saring-kolom>
            <thead><tr><th>Kelompok</th><th>Kategori</th><th>Sumber</th>
                @foreach ($bulan as $b)<th class="angka">{{ $namaBulan($b) }}</th>@endforeach
                <th class="angka">Total</th><th class="angka">%</th></tr></thead>
            <tbody>
                @foreach ($kategori as $k)
                    <tr @class(['t', 'mati' => ! $semua && ! $pilih->contains($k['nama'])])>
                        <td class="kel" style="color: {{ $warnaKelompok[$k['kelompok']] }}">{{ $k['kelompok'] }}</td>
                        <td><a href="{{ $url(['kat' => $k['nama']]) }}" title="Lihat kategori ini saja">{{ $k['nama'] }}</a></td>
                        <td class="redup">{{ collect($k['sumber'])->map(fn ($s) => $s === 'uj' ? 'Kas UJ' : 'Kas Harian')->implode(' + ') }}</td>
                        @foreach ($bulan as $b)
                            @php($v = $k['bulan'][$b] ?? 0)
                            <td @class(['angka', 'nol' => ! $v])>{{ rp($v, true) }}</td>
                        @endforeach
                        <td class="angka"><b>{{ rp($k['rp']) }}</b></td>
                        <td class="angka redup">{{ $persen($k['rp'], $kategori->sum('rp')) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="3">Total{{ $semua ? '' : ' kategori terpilih' }}</td>
                    @foreach ($bulan as $b)<td class="angka">{{ rp($perBulan[$b]) }}</td>@endforeach
                    <td class="angka">{{ rp($ringkas['total']) }}</td><td></td></tr>
                <tr><td colspan="3" class="redup">Jumlah rit</td>
                    @foreach ($bulan as $b)<td class="angka redup">{{ rp($ritBulan[$b], true) }}</td>@endforeach
                    <td class="angka redup">{{ rp($ringkas['rit']) }}</td><td></td></tr>
                <tr><td colspan="3" class="redup">Biaya per rit</td>
                    @foreach ($bulan as $b)<td class="angka redup">{{ $ritBulan[$b] ? rp(round($perBulan[$b] / $ritBulan[$b])) : '-' }}</td>@endforeach
                    <td class="angka redup">{{ $ringkas['per_rit'] !== null ? rp($ringkas['per_rit']) : '-' }}</td><td></td></tr>
            </tbody>
        </table>
    </div>

    {{-- Per truk --}}
    <div class="kartu gulir biaya-dt" style="padding: 0;">
        <div style="padding: 12px 14px 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <b class="dash-judul">Biaya per DT</b>
            <span class="redup" style="font-size: 13px;">Kolom:</span>
            <a href="{{ $url(['tampil' => null]) }}" @class(['chip', 'aktif' => $tampil === 'bulan']) style="margin: 0;">Per bulan</a>
            <a href="{{ $url(['tampil' => 'kategori']) }}" @class(['chip', 'aktif' => $tampil === 'kategori']) style="margin: 0;">Per kategori</a>
            <span class="redup" style="font-size: 13px; margin-left: auto;">Klik nomor DT untuk melihat rinciannya.</span>
        </div>
        <table class="kas">
            <thead><tr><th>DT</th><th>Jenis</th><th>Status</th>
                @if ($tampil === 'bulan')
                    @foreach ($bulan as $b)<th class="angka">{{ $namaBulan($b) }}</th>@endforeach
                @else
                    @foreach ($katTampil as $k)<th class="angka">{{ $k['nama'] }}</th>@endforeach
                @endif
                <th class="angka">Total</th><th class="angka">Rit</th><th class="angka">Biaya/rit</th></tr></thead>
            <tbody>
                @foreach ($truk as $t)
                    <tr @class(['t', 'pilih' => $rinci && $rinci['mobil'] === $t['mobil']])>
                        <td data-v="{{ $t['mobil'] }}"><a class="dt" href="{{ $url(['mobil' => $t['mobil']]) }}#rinci">{{ $t['mobil'] }}</a></td>
                        <td>{{ $t['aset']?->jenis }}</td>
                        <td>{{ $t['aset'] ? (\App\Models\AsetTruk::STATUS[$t['aset']->status] ?? $t['aset']->status).($t['aset']->kepemilikan === 'rental' ? ' · rental' : '') : '' }}</td>
                        @if ($tampil === 'bulan')
                            @foreach ($bulan as $b)
                                @php($v = $t['bulan'][$b] ?? 0)
                                <td @class(['angka', 'nol' => ! $v]) data-n="{{ $v }}">{{ rp($v, true) }}</td>
                            @endforeach
                        @else
                            @foreach ($katTampil as $k)
                                @php($v = $t['kategori'][$k['nama']] ?? 0)
                                <td @class(['angka', 'nol' => ! $v]) data-n="{{ $v }}">{{ rp($v, true) }}</td>
                            @endforeach
                        @endif
                        <td class="angka" data-n="{{ $t['rp'] }}"><b>{{ rp($t['rp']) }}</b></td>
                        <td class="angka" data-n="{{ $t['rit'] }}">{{ rp($t['rit'], true) }}</td>
                        <td class="angka" data-n="{{ (int) $t['per_rit'] }}">{{ $t['rit'] ? rp($t['per_rit']) : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="total"><td colspan="3">Total {{ $truk->count() }} DT</td>
                    @if ($tampil === 'bulan')
                        @foreach ($bulan as $b)<td class="angka">{{ rp($perBulan[$b]) }}</td>@endforeach
                    @else
                        @foreach ($katTampil as $k)<td class="angka">{{ rp($k['rp']) }}</td>@endforeach
                    @endif
                    <td class="angka">{{ rp($ringkas['total']) }}</td><td class="angka">{{ rp($ringkas['rit']) }}</td>
                    <td class="angka">{{ $ringkas['per_rit'] !== null ? rp($ringkas['per_rit']) : '-' }}</td></tr>
            </tfoot>
        </table>
    </div>

    {{-- Rincian satu truk --}}
    @if ($rinci)
        @php($a = $rinci['aset'])
        <div class="kartu biaya-dt" id="rinci" style="padding: 0;">
            <div style="padding: 12px 14px 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <b class="dash-judul" style="font-size: 16px;">{{ $rinci['mobil'] }}</b>
                <span class="redup" style="font-size: 13px;">{{ collect([$a->jenis, \App\Models\AsetTruk::STATUS[$a->status] ?? $a->status, \App\Models\AsetTruk::KEPEMILIKAN[$a->kepemilikan] ?? $a->kepemilikan, $a->driver_tetap ? 'driver '.$a->driver_tetap : null])->filter()->implode(' · ') }}</span>
                <a href="{{ $url(['mobil' => null]) }}" class="redup" style="margin-left: auto; font-size: 13px;">Tutup ✕</a>
            </div>
            <div class="gulir">
                <table class="kas" data-tanpa-saring-kolom>
                    <thead><tr><th>Kategori</th>@foreach ($bulan as $b)<th class="angka">{{ $namaBulan($b) }}</th>@endforeach<th class="angka">Total</th></tr></thead>
                    <tbody>
                        @foreach ($rinci['kategori'] as $nama => $k)
                            <tr @class(['t', 'mati' => ! $semua && ! $pilih->contains($nama)])><td>{{ $nama }}</td>
                                @foreach ($bulan as $b)@php($v = $k['bulan'][$b] ?? 0)<td @class(['angka', 'nol' => ! $v])>{{ rp($v, true) }}</td>@endforeach
                                <td class="angka"><b>{{ rp($k['rp']) }}</b></td></tr>
                        @endforeach
                        <tr><td class="redup">Jumlah rit</td>@foreach ($bulan as $b)<td class="angka redup">{{ rp($rinci['rit'][$b] ?? 0, true) }}</td>@endforeach
                            <td class="angka redup">{{ rp(array_sum($rinci['rit'])) }}</td></tr>
                    </tbody>
                </table>
            </div>
            <div style="padding: 14px 14px 6px;"><b class="dash-judul">Transaksi</b> <span class="redup" style="font-size: 13px;">{{ $rinci['transaksi']->count() }} baris · {{ rp($rinci['transaksi']->sum('rp')) }}{{ $semua ? '' : ' · kategori terpilih' }}</span></div>
            <div class="gulir">
                <table class="kas">
                    <thead><tr><th>Tanggal</th><th>Sumber</th><th>ID</th><th>Kategori</th><th>Keterangan</th><th>Nama / PIC</th><th>No DO</th><th class="angka">Nominal</th></tr></thead>
                    <tbody>
                        @foreach ($rinci['transaksi'] as $x)
                            @php($bln = \Illuminate\Support\Carbon::parse($x['tanggal']))
                            @php($cari = ['tahun' => $bln->year, 'dari' => $bln->month, 'sampai' => $bln->month, 'q' => $x['id']])
                            <tr class="t">
                                <td data-n="{{ $x['tanggal'] }}">{{ $tgl($x['tanggal']) }}</td>
                                <td>{{ $x['sumber'] === 'uj' ? 'Kas UJ' : 'Kas Harian' }}</td>
                                <td>@if ($x['id'] && (($x['sumber'] === 'uj' && $bolehUj) || ($x['sumber'] === 'kas' && $bolehKas)))<a href="{{ route($x['sumber'] === 'uj' ? 'uj.index' : 'kas.index', $cari) }}" target="_blank">{{ $x['id'] }}</a>@else{{ $x['id'] }}@endif</td>
                                <td>{{ $x['kategori'] }}@if (! empty($x['kode_gl']))<div class="redup" style="font-size: 11px;">{{ $x['kode_gl'] }}</div>@endif</td>
                                <td>{{ $x['keterangan'] }}</td>
                                <td>{{ $x['nama'] }}</td>
                                <td>{{ $x['no_do'] }}</td>
                                <td class="angka" data-n="{{ $x['rp'] }}">{{ rp($x['rp']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <script>
        // Centang kategori → langsung terapkan.
        document.querySelectorAll('#form-kat input[type=checkbox]').forEach(c => c.addEventListener('change', () => document.getElementById('form-kat').submit()));
    </script>
@endsection
