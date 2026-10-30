@extends('layouts.app', ['judul' => 'Laba Rugi '.$t['mobil']])

@section('lebar', '1600px')

@section('isi')
    @php
        $namaBulan = fn ($b) => \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M y');
        $pp = $periode->param();
        $persen = fn ($a, $b) => $b ? number_format($a / $b * 100, 1, ',', '.').'%' : '-';
        $lr = fn ($v) => '<span class="'.($v < 0 ? 'rugi' : ($v > 0 ? 'untung' : '')).'">'.($v < 0 ? '−' : '').rp(abs($v)).'</span>';
        $a = $t['aset'];
    @endphp
    @include('dashboard.performa-dt-gaya')

    <div style="display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; margin-bottom: 8px;">
        <a href="{{ route('dashboard.performa-dt', $pp) }}" class="redup">← Dashboard Laba Rugi DT semua truk</a>
        <span class="redup" style="font-size: 13px;">{{ collect([$a?->jenis, $a ? (\App\Models\AsetTruk::STATUS[$a->status] ?? $a->status) : null,
            $a?->kepemilikan === 'rental' ? 'rental' : null, $a?->driver_tetap ? 'driver '.$a->driver_tetap : null])->filter()->implode(' · ') }} · {{ $periode->label() }}</span>
    </div>

    @include('partials.periode', ['rute' => 'dashboard.performa-dt.truk', 'bawa' => ['mobil' => $t['mobil']]])

    <div class="dash-kartu lr">
        <div class="kartu">
            <h4>Total pendapatan</h4>
            <div class="angka-besar">{{ rp($t['pendapatan']) }}</div>
            <div class="dash-baris"><span>Jumlah rit</span><b>{{ rp($t['rit']) }} rit</b></div>
            <div class="dash-baris"><span>Kubikasi</span><span>{{ number_format($t['kubik'], 0, ',', '.') }} m³</span></div>
            @if ($t['tanpa_harga'])
                <div class="dash-baris"><span>⚠ Rit tanpa Harga Jual</span><span>{{ $t['tanpa_harga'] }} rit · perkiraan {{ rp($t['perkiraan']) }}</span></div>
            @endif
        </div>
        <div class="kartu">
            <h4>Total pengeluaran</h4>
            <div class="angka-besar">{{ rp($t['biaya']) }}</div>
            @foreach ($t['kelompok'] as $k => $v)
                <div class="dash-baris"><span>{{ $k }}</span><span>{{ rp($v) }} <span class="redup">· {{ $persen($v, $t['biaya']) }}</span></span></div>
            @endforeach
        </div>
        <div class="kartu">
            <h4>Laba / rugi</h4>
            <div class="angka-besar">{!! $lr($t['laba']) !!}</div>
            <div class="dash-baris"><span>Margin</span><b>{{ $persen($t['laba'], $t['pendapatan']) }}</b></div>
            <div class="dash-baris"><span>Laba per rit</span><span>{!! $t['rit'] ? $lr(round($t['laba'] / $t['rit'])) : '-' !!}</span></div>
        </div>
    </div>

    <div class="kartu gulir lr" style="padding: 0;">
        <div style="padding: 12px 14px 0;"><b class="dash-judul">Laba rugi per bulan — {{ $t['mobil'] }}</b></div>
        <table class="kas" data-tanpa-saring-kolom>
            <thead><tr><th></th>@foreach ($bulan as $b)<th class="angka">{{ $namaBulan($b) }}</th>@endforeach<th class="angka">Total</th></tr></thead>
            <tbody>
                <tr><td class="redup">Jumlah rit</td>@foreach ($bulan as $b)<td class="angka">{{ rp($t['bulan'][$b]['rit'] ?? 0, true) }}</td>@endforeach<td class="angka">{{ rp($t['rit']) }}</td></tr>
                <tr><td>Pendapatan</td>@foreach ($bulan as $b)<td class="angka">{{ rp($t['bulan'][$b]['pendapatan'] ?? 0, true) }}</td>@endforeach<td class="angka"><b>{{ rp($t['pendapatan']) }}</b></td></tr>
                <tr><td>Pengeluaran</td>@foreach ($bulan as $b)<td class="angka">{{ rp($t['bulan'][$b]['biaya'] ?? 0, true) }}</td>@endforeach<td class="angka"><b>{{ rp($t['biaya']) }}</b></td></tr>
                <tr class="total"><td>Laba / rugi</td>@foreach ($bulan as $b)<td class="angka">{!! $lr($t['bulan'][$b]['laba'] ?? 0) !!}</td>@endforeach<td class="angka">{!! $lr($t['laba']) !!}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="kartu lr">
        @include('dashboard.performa-dt-rinci', ['terbuka' => true])
    </div>
@endsection
