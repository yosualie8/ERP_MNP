@extends('layouts.app', ['judul' => 'Performa DT (Laba Rugi)'])

@section('lebar', '1600px')

@section('isi')
    @php
        $namaBulan = fn ($b) => \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M y');
        $pp = $periode->param();
        $persen = fn ($a, $b) => $b ? number_format($a / $b * 100, 1, ',', '.').'%' : '-';
        // Laba/rugi berwarna: hijau untung, merah rugi.
        $lr = fn ($v) => '<span class="'.($v < 0 ? 'rugi' : ($v > 0 ? 'untung' : '')).'">'.($v < 0 ? '−' : '').rp(abs($v)).'</span>';
        $kelompok = [...array_keys(\App\Support\BiayaDt::KELOMPOK), 'Lain-lain'];
        $maksLaba = max(1, $truk->max(fn ($t) => abs($t['laba'])));
        $bolehBiaya = auth()->user()->bolehMenu('dashboard-biaya-dt');
        $status = fn ($a) => $a ? (\App\Models\AsetTruk::STATUS[$a->status] ?? $a->status).($a->kepemilikan === 'rental' ? ' · rental' : '') : '';
    @endphp
    @include('dashboard.gaya')
    <style>
        .lr .untung { color: var(--sukses); }
        .lr .rugi { color: var(--merah); }
        .lr tr.total td { font-weight: 700; border-top: 2px solid var(--garis-kuat); background: var(--kartu-2); }
        .lr tr.pilih td { background: var(--aksen-muda); }
        .lr td.nol { color: var(--redup); }
        .lr a.dt { font-weight: 700; text-decoration: none; }
        .lr td.batang-lr { position: relative; min-width: 150px; }
        .lr td.batang-lr i { position: absolute; top: 5px; bottom: 5px; border-radius: 2px; opacity: .22; }
        .lr td.batang-lr b { position: relative; }
        .peringatan-lr { border-left: 3px solid #f5a524; font-size: 13px; }
    </style>

    @include('partials.periode', ['rute' => 'dashboard.performa-dt', 'bawa' => array_filter(['mobil' => $rinci['mobil'] ?? null])])

    <p class="redup" style="font-size: 13px; margin: -4px 0 12px;">
        <b>Pendapatan</b> = kolom Harga Jual lembar Ritasi (per rit, menurut No Lambung). <b>Biaya</b> = biaya langsung truk dari Monitoring Biaya DT
        (Kas UJ + Kas Harian yang ber-No Mobil). Biaya bersama yang tidak ber-No Mobil — gaji, solar tangki, sparepart tanpa No Mobil, sewa, overhead — belum dibagi ke truk.
        Hanya dump truck di Data Aset · {{ $periode->label() }}.
    </p>

    @if ($ringkas['tanpa_harga'])
        <div class="kartu peringatan-lr">
            ⚠ <b>{{ rp($ringkas['tanpa_harga']) }} rit belum ada Harga Jual</b> di lembar Ritasi, jadi pendapatannya dihitung 0 (laba truk-truk itu terlihat lebih kecil).
            Bila diisi harga yang biasa di tahapnya, pendapatannya sekitar <b>Rp {{ rp($ringkas['perkiraan']) }}</b>. Truk terkait ditandai ⚠ di tabel.
        </div>
    @endif

    <div class="dash-kartu lr">
        <div class="kartu">
            <h4>Pendapatan (harga jual rit)</h4>
            <div class="angka-besar">{{ rp($ringkas['pendapatan']) }}</div>
            <div class="dash-baris"><span>Jumlah rit</span><b>{{ rp($ringkas['rit']) }} rit</b></div>
            <div class="dash-baris"><span>Kubikasi</span><span>{{ number_format($ringkas['kubik'], 0, ',', '.') }} m³</span></div>
            <div class="dash-baris"><span>Rata-rata per rit</span><span>{{ $ringkas['per_rit'] ? rp($ringkas['per_rit']['pendapatan']) : '-' }}</span></div>
        </div>
        <div class="kartu">
            <h4>Biaya langsung DT</h4>
            <div class="angka-besar">{{ rp($ringkas['biaya']) }}</div>
            @foreach ($ringkas['kelompok'] as $k => $v)
                <div class="dash-baris"><span>{{ $k }}</span><span>{{ rp($v) }} <span class="redup">· {{ $persen($v, $ringkas['biaya']) }}</span></span></div>
            @endforeach
            <div class="dash-baris"><span>Rata-rata per rit</span><span>{{ $ringkas['per_rit'] ? rp($ringkas['per_rit']['biaya']) : '-' }}</span></div>
        </div>
        <div class="kartu">
            <h4>Laba / rugi</h4>
            <div class="angka-besar">{!! $lr($ringkas['laba']) !!}</div>
            <div class="dash-baris"><span>Margin</span><b>{{ $ringkas['margin'] !== null ? number_format($ringkas['margin'], 1, ',', '.').'%' : '-' }}</b></div>
            <div class="dash-baris"><span>Laba per rit</span><span>{!! $ringkas['per_rit'] ? $lr($ringkas['per_rit']['laba']) : '-' !!}</span></div>
            <div class="dash-baris"><span>DT untung / rugi</span><span><span class="untung">{{ $ringkas['untung'] }} untung</span> · <span class="rugi">{{ $ringkas['rugi'] }} rugi</span></span></div>
        </div>
    </div>

    {{-- Per bulan --}}
    <div class="kartu gulir lr" style="padding: 0;">
        <div style="padding: 12px 14px 0;"><b class="dash-judul">Laba rugi per bulan — semua DT</b></div>
        <table class="kas" data-tanpa-saring-kolom>
            <thead><tr><th></th>@foreach ($bulan as $b)<th class="angka">{{ $namaBulan($b) }}</th>@endforeach<th class="angka">Total</th></tr></thead>
            <tbody>
                <tr><td class="redup">Jumlah rit</td>@foreach ($bulan as $b)<td class="angka">{{ rp($perBulan[$b]['rit'], true) }}</td>@endforeach<td class="angka">{{ rp($ringkas['rit']) }}</td></tr>
                <tr><td>Pendapatan</td>@foreach ($bulan as $b)<td class="angka">{{ rp($perBulan[$b]['pendapatan'], true) }}</td>@endforeach<td class="angka"><b>{{ rp($ringkas['pendapatan']) }}</b></td></tr>
                <tr><td>Biaya</td>@foreach ($bulan as $b)<td class="angka">{{ rp($perBulan[$b]['biaya'], true) }}</td>@endforeach<td class="angka"><b>{{ rp($ringkas['biaya']) }}</b></td></tr>
                <tr class="total"><td>Laba / rugi</td>@foreach ($bulan as $b)<td class="angka">{!! $lr($perBulan[$b]['laba']) !!}</td>@endforeach<td class="angka">{!! $lr($ringkas['laba']) !!}</td></tr>
                <tr><td class="redup">Margin</td>@foreach ($bulan as $b)<td class="angka redup">{{ $perBulan[$b]['pendapatan'] ? $persen($perBulan[$b]['laba'], $perBulan[$b]['pendapatan']) : '-' }}</td>@endforeach
                    <td class="angka redup">{{ $persen($ringkas['laba'], $ringkas['pendapatan']) }}</td></tr>
            </tbody>
        </table>
    </div>

    {{-- Per DT --}}
    <div class="kartu gulir lr" style="padding: 0;">
        <div style="padding: 12px 14px 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <b class="dash-judul">Laba rugi per DT</b>
            <span class="redup" style="font-size: 13px; margin-left: auto;">Klik nomor DT untuk melihat rincian per bulan.</span>
        </div>
        <table class="kas">
            <thead><tr><th>DT</th><th>Jenis</th><th>Status</th><th class="angka">Rit</th><th class="angka">m³</th><th class="angka">Pendapatan</th>
                @foreach ($kelompok as $k)<th class="angka">{{ $k }}</th>@endforeach
                <th class="angka">Total biaya</th><th>Laba / rugi</th><th class="angka">Margin</th><th class="angka">Laba/rit</th></tr></thead>
            <tbody>
                @foreach ($truk as $t)
                    <tr @class(['t', 'pilih' => $rinci && $rinci['mobil'] === $t['mobil']])>
                        <td data-v="{{ $t['mobil'] }}"><a class="dt" href="{{ route('dashboard.performa-dt', $pp + ['mobil' => $t['mobil']]) }}#rinci">{{ $t['mobil'] }}</a></td>
                        <td>{{ $t['aset']?->jenis }}</td>
                        <td>{{ $status($t['aset']) }}</td>
                        <td class="angka" data-n="{{ $t['rit'] }}">{{ rp($t['rit'], true) }}</td>
                        <td class="angka" data-n="{{ $t['kubik'] }}">{{ $t['kubik'] ? number_format($t['kubik'], 0, ',', '.') : '-' }}</td>
                        <td class="angka" data-n="{{ $t['pendapatan'] }}">
                            @if ($t['tanpa_harga'])<span title="{{ $t['tanpa_harga'] }} rit tanpa Harga Jual (perkiraan Rp {{ rp($t['perkiraan']) }})" style="cursor: help;">⚠</span>@endif
                            {{ rp($t['pendapatan'], true) }}</td>
                        @foreach ($kelompok as $k)
                            @php $v = $t['kelompok'][$k] ?? 0; @endphp
                            <td @class(['angka', 'nol' => ! $v]) data-n="{{ $v }}">{{ rp($v, true) }}</td>
                        @endforeach
                        <td class="angka" data-n="{{ $t['biaya'] }}">{{ rp($t['biaya'], true) }}</td>
                        <td class="angka batang-lr" data-n="{{ $t['laba'] }}">
                            <i style="{{ $t['laba'] < 0 ? 'right: 4px; background: var(--merah);' : 'right: 4px; background: var(--sukses);' }} width: {{ round(abs($t['laba']) / $maksLaba * 140) }}px;"></i>
                            <b>{!! $lr($t['laba']) !!}</b></td>
                        <td class="angka" data-n="{{ $t['pendapatan'] ? round($t['laba'] / $t['pendapatan'] * 100, 1) : -999 }}">{{ $persen($t['laba'], $t['pendapatan']) }}</td>
                        <td class="angka" data-n="{{ $t['rit'] ? round($t['laba'] / $t['rit']) : 0 }}">{!! $t['rit'] ? $lr(round($t['laba'] / $t['rit'])) : '-' !!}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="total"><td colspan="3">Total {{ $truk->count() }} DT</td>
                    <td class="angka">{{ rp($ringkas['rit']) }}</td><td class="angka">{{ number_format($ringkas['kubik'], 0, ',', '.') }}</td>
                    <td class="angka">{{ rp($ringkas['pendapatan']) }}</td>
                    @foreach ($kelompok as $k)<td class="angka">{{ rp($ringkas['kelompok'][$k] ?? 0, true) }}</td>@endforeach
                    <td class="angka">{{ rp($ringkas['biaya']) }}</td><td class="angka">{!! $lr($ringkas['laba']) !!}</td>
                    <td class="angka">{{ $persen($ringkas['laba'], $ringkas['pendapatan']) }}</td>
                    <td class="angka">{!! $ringkas['per_rit'] ? $lr(round($ringkas['per_rit']['laba'])) : '-' !!}</td></tr>
            </tfoot>
        </table>
    </div>

    {{-- Rincian satu truk --}}
    @if ($rinci)
        @php $a = $rinci['aset']; @endphp
        <div class="kartu lr" id="rinci" style="padding: 0;">
            <div style="padding: 12px 14px 8px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <b class="dash-judul" style="font-size: 16px;">{{ $rinci['mobil'] }}</b>
                <span class="redup" style="font-size: 13px;">{{ collect([$a?->jenis, $status($a), $a?->driver_tetap ? 'driver '.$a->driver_tetap : null])->filter()->implode(' · ') }}</span>
                @if ($bolehBiaya)
                    <a href="{{ route('dashboard.biaya-dt', $pp + ['mobil' => $rinci['mobil']]) }}#rinci" class="tombol polos" style="margin-left: auto;">Transaksi biaya di Monitoring Biaya DT ↗</a>
                @endif
                <a href="{{ route('dashboard.performa-dt', $pp) }}" class="redup" @style(['font-size: 13px', 'margin-left: auto' => ! $bolehBiaya])>Tutup ✕</a>
            </div>
            <div class="gulir">
                <table class="kas" data-tanpa-saring-kolom>
                    <thead><tr><th></th>@foreach ($bulan as $b)<th class="angka">{{ $namaBulan($b) }}</th>@endforeach<th class="angka">Total</th></tr></thead>
                    <tbody>
                        <tr><td class="redup">Jumlah rit</td>@foreach ($bulan as $b)<td class="angka">{{ rp($rinci['bulan'][$b]['rit'] ?? 0, true) }}</td>@endforeach<td class="angka">{{ rp($rinci['rit']) }}</td></tr>
                        <tr><td>Pendapatan</td>@foreach ($bulan as $b)<td class="angka">{{ rp($rinci['bulan'][$b]['pendapatan'] ?? 0, true) }}</td>@endforeach<td class="angka"><b>{{ rp($rinci['pendapatan']) }}</b></td></tr>
                        <tr><td>Biaya</td>@foreach ($bulan as $b)<td class="angka">{{ rp($rinci['bulan'][$b]['biaya'] ?? 0, true) }}</td>@endforeach<td class="angka"><b>{{ rp($rinci['biaya']) }}</b></td></tr>
                        <tr class="total"><td>Laba / rugi</td>@foreach ($bulan as $b)<td class="angka">{!! $lr($rinci['bulan'][$b]['laba'] ?? 0) !!}</td>@endforeach<td class="angka">{!! $lr($rinci['laba']) !!}</td></tr>
                    </tbody>
                </table>
            </div>
            <div style="padding: 14px 14px 6px;"><b class="dash-judul">Biaya per kategori</b>
                @if ($rinci['tanpa_harga'])<span class="redup" style="font-size: 13px;"> · ⚠ {{ $rinci['tanpa_harga'] }} rit tanpa Harga Jual (perkiraan Rp {{ rp($rinci['perkiraan']) }})</span>@endif</div>
            <div class="gulir">
                <table class="kas" data-tanpa-saring-kolom>
                    <thead><tr><th>Kategori</th><th>Kelompok</th><th class="angka">Biaya</th><th class="angka">% biaya</th><th class="angka">% pendapatan</th></tr></thead>
                    <tbody>
                        @forelse ($rinci['kategori'] as $k => $v)
                            <tr class="t"><td>{{ $k }}</td><td class="redup">{{ \App\Support\BiayaDt::kelompok($k) }}</td><td class="angka">{{ rp($v) }}</td>
                                <td class="angka redup">{{ $persen($v, $rinci['biaya']) }}</td><td class="angka redup">{{ $persen($v, $rinci['pendapatan']) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="redup">Tidak ada biaya ber-No Mobil {{ $rinci['mobil'] }} di periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
