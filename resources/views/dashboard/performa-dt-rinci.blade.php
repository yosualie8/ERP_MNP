{{-- Rincian satu DT di dashboard Performa DT (dimuat saat baris master dibuka). Detail 1: pendapatan per proyek & pengeluaran per kategori;
     detail 2: semua transaksi biaya (Kas Harian, Kas UJ, BCA). Baris bertanda ▸ bisa dibuka / ditutup. --}}
@php
    $lr = fn ($v) => '<span class="'.($v < 0 ? 'rugi' : ($v > 0 ? 'untung' : '')).'">'.($v < 0 ? '−' : '').rp(abs($v)).'</span>';
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-y') : '';
    $sumber = ['uj' => 'Kas UJ', 'kas' => 'Kas Harian', 'bca' => 'BCA'];
    $perSumber = $transaksi->groupBy('sumber')->map(fn ($g) => $g->sum('rp'));
    $nKategori = $transaksi->groupBy('kategori')->map->count();
    $bolehUj = auth()->user()->bolehMenu('kas-uj');
    $bolehKas = auth()->user()->bolehMenu('kas-harian');
    $id = 'r'.preg_replace('/\W/', '', $t['mobil']);
@endphp
<div class="rinci-dt">
    {{-- Detail 1 --}}
    <div class="detail-1">
        <h5>Detail 1 · Pendapatan &amp; pengeluaran {{ $t['mobil'] }}</h5>
        <table class="kas" data-tanpa-saring-kolom>
            <thead><tr><th>Uraian</th><th class="angka">Rit / trx</th><th class="angka">m³</th><th class="angka">Nominal</th></tr></thead>
            <tbody>
                <tr class="buka-tutup" data-grup="{{ $id }}p"><td>Total pendapatan</td><td class="angka">{{ rp($t['rit'], true) }}</td>
                    <td class="angka">{{ $t['kubik'] ? number_format($t['kubik'], 0, ',', '.') : '-' }}</td><td class="angka">{{ rp($t['pendapatan']) }}</td></tr>
                @forelse ($tahap as $x)
                    <tr data-anak="{{ $id }}p" hidden>
                        <td>{{ $x->tahap }}
                            <div class="redup" style="font-size: 11px;">{{ $tgl($x->awal) }}{{ $x->awal !== $x->akhir ? ' – '.$tgl($x->akhir) : '' }}@if ($x->tanpa_harga) · ⚠ {{ $x->tanpa_harga }} rit tanpa Harga Jual @endif</div></td>
                        <td class="angka">{{ rp($x->rit) }}</td><td class="angka">{{ number_format((float) $x->kubik, 0, ',', '.') }}</td><td class="angka">{{ rp($x->rp, true) }}</td></tr>
                @empty
                    <tr data-anak="{{ $id }}p" hidden><td colspan="4" class="redup">Tidak ada rit di periode ini.</td></tr>
                @endforelse
                <tr class="buka-tutup" data-grup="{{ $id }}b"><td>Total pengeluaran</td><td class="angka">{{ rp($transaksi->count(), true) }}</td><td></td>
                    <td class="angka">{{ rp($t['biaya']) }}</td></tr>
                @forelse ($t['kategori'] as $k => $v)
                    <tr data-anak="{{ $id }}b" hidden><td>{{ $k }} <span class="redup" style="font-size: 11px;">· {{ \App\Support\BiayaDt::kelompok($k) }}</span></td>
                        <td class="angka">{{ rp($nKategori[$k] ?? 0, true) }}</td><td></td><td class="angka">{{ rp($v) }}</td></tr>
                @empty
                    <tr data-anak="{{ $id }}b" hidden><td colspan="4" class="redup">Tidak ada biaya di periode ini.</td></tr>
                @endforelse
                <tr class="total"><td>Laba / rugi</td><td></td><td></td><td class="angka">{!! $lr($t['laba']) !!}</td></tr>
            </tbody>
        </table>
    </div>

    {{-- Detail 2 --}}
    <div>
        <h5>Detail 2 · Seluruh biaya {{ $t['mobil'] }}</h5>
        <table class="kas" data-tanpa-saring-kolom>
            <tbody>
                <tr class="buka-tutup" data-grup="{{ $id }}t"><td>{{ rp($transaksi->count()) }} transaksi
                    <span class="redup" style="font-weight: 400; font-size: 12px;">· {{ collect($sumber)->filter(fn ($n, $k) => isset($perSumber[$k]))->map(fn ($n, $k) => $n.' '.rp($perSumber[$k]))->implode(' · ') }}</span></td>
                    <td class="angka">{{ rp($transaksi->sum('rp')) }}</td></tr>
            </tbody>
        </table>
        <div class="gulir-tx" data-anak="{{ $id }}t" hidden>
            <table class="kas" data-tanpa-saring-kolom>
                <thead><tr><th>Tanggal</th><th>Sumber</th><th>ID</th><th>Kategori</th><th>Keterangan</th><th>Nama / PIC</th><th>No DO</th><th class="angka">Nominal</th></tr></thead>
                <tbody>
                    @forelse ($transaksi as $x)
                        @php $bln = \Illuminate\Support\Carbon::parse($x['tanggal']); $cari = ['tahun' => $bln->year, 'dari' => $bln->month, 'sampai' => $bln->month, 'q' => $x['id']]; @endphp
                        <tr>
                            <td style="white-space: nowrap;">{{ $tgl($x['tanggal']) }}</td>
                            <td>{{ $sumber[$x['sumber']] ?? $x['sumber'] }}</td>
                            <td>@if ($x['id'] && (($x['sumber'] === 'uj' && $bolehUj) || ($x['sumber'] === 'kas' && $bolehKas)))<a href="{{ route($x['sumber'] === 'uj' ? 'uj.index' : 'kas.index', $cari) }}" target="_blank">{{ $x['id'] }}</a>@else{{ $x['id'] }}@endif</td>
                            <td>{{ $x['kategori'] }}</td>
                            <td>{{ $x['keterangan'] }}</td>
                            <td>{{ $x['nama'] }}</td>
                            <td>{{ $x['no_do'] }}</td>
                            <td class="angka">{{ rp($x['rp']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="redup">Tidak ada transaksi biaya di periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
