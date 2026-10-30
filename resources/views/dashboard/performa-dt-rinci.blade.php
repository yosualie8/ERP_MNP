{{-- Rincian satu DT di Performa DT: dimuat saat baris master dibuka, juga dipakai halaman rincian DT ($terbuka = semua bagian terbuka).
     Detail 1: pendapatan per proyek & pengeluaran per kategori; detail 2: semua transaksi biaya (Kas Harian, Kas UJ, BCA).
     Tiap bagian ▸ bisa dibuka / ditutup; grid-nya bisa disaring & diurutkan per kolom. --}}
@php
    $terbuka ??= false;
    $lr = fn ($v) => '<span class="'.($v < 0 ? 'rugi' : ($v > 0 ? 'untung' : '')).'">'.($v < 0 ? '−' : '').rp(abs($v)).'</span>';
    $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-y') : '';
    $persen = fn ($a, $b) => $b ? number_format($a / $b * 100, 1, ',', '.').'%' : '-';
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

        <div @class(['buka-tutup', 'buka' => $terbuka]) data-grup="{{ $id }}p">Total pendapatan
            <span class="ket">{{ rp($t['rit']) }} rit · {{ number_format($t['kubik'], 0, ',', '.') }} m³ · {{ $tahap->count() }} proyek
                @if ($t['tanpa_harga'])· ⚠ {{ $t['tanpa_harga'] }} rit tanpa Harga Jual (perkiraan Rp {{ rp($t['perkiraan']) }})@endif</span>
            <span class="nilai">{{ rp($t['pendapatan']) }}</span></div>
        <div class="anak" data-anak="{{ $id }}p" @if (! $terbuka) hidden @endif>
            <table class="kas" data-saring-kolom>
                <thead><tr><th>Proyek</th><th>Rit pertama</th><th>Rit terakhir</th><th class="angka">Rit</th><th class="angka">m³</th>
                    <th class="angka">Rit tanpa Harga Jual</th><th class="angka">Pendapatan</th></tr></thead>
                <tbody>
                    @forelse ($tahap as $x)
                        <tr>
                            <td>{{ $x->tahap }}</td>
                            <td data-n="{{ $x->awal }}">{{ $tgl($x->awal) }}</td>
                            <td data-n="{{ $x->akhir }}">{{ $tgl($x->akhir) }}</td>
                            <td class="angka" data-n="{{ $x->rit }}">{{ rp($x->rit) }}</td>
                            <td class="angka" data-n="{{ $x->kubik }}">{{ number_format((float) $x->kubik, 0, ',', '.') }}</td>
                            <td class="angka" data-n="{{ $x->tanpa_harga }}">{{ $x->tanpa_harga ? '⚠ '.$x->tanpa_harga : '-' }}</td>
                            <td class="angka" data-n="{{ $x->rp }}">{{ rp($x->rp, true) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="redup">Tidak ada rit di periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div @class(['buka-tutup', 'buka' => $terbuka]) data-grup="{{ $id }}b">Total pengeluaran
            <span class="ket">{{ rp($transaksi->count()) }} transaksi · {{ count($t['kategori']) }} kategori</span>
            <span class="nilai">{{ rp($t['biaya']) }}</span></div>
        <div class="anak" data-anak="{{ $id }}b" @if (! $terbuka) hidden @endif>
            <table class="kas" data-saring-kolom>
                <thead><tr><th>Kategori</th><th>Kelompok</th><th class="angka">Transaksi</th><th class="angka">Nominal</th><th class="angka">% pengeluaran</th>
                    <th class="angka">% pendapatan</th></tr></thead>
                <tbody>
                    @forelse ($t['kategori'] as $k => $v)
                        <tr>
                            <td>{{ $k }}</td>
                            <td>{{ \App\Support\BiayaDt::kelompok($k) }}</td>
                            <td class="angka" data-n="{{ $nKategori[$k] ?? 0 }}">{{ rp($nKategori[$k] ?? 0, true) }}</td>
                            <td class="angka" data-n="{{ $v }}">{{ rp($v) }}</td>
                            <td class="angka">{{ $persen($v, $t['biaya']) }}</td>
                            <td class="angka">{{ $persen($v, $t['pendapatan']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="redup">Tidak ada biaya di periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="laba-dt">Laba / rugi <span class="redup" style="font-weight: 400; font-size: 12px;">margin {{ $persen($t['laba'], $t['pendapatan']) }}</span>
            <span class="nilai">{!! $lr($t['laba']) !!}</span></div>
    </div>

    {{-- Detail 2 --}}
    <div class="detail-2">
        <h5>Detail 2 · Seluruh biaya {{ $t['mobil'] }}</h5>
        <div @class(['buka-tutup', 'buka' => $terbuka]) data-grup="{{ $id }}t">{{ rp($transaksi->count()) }} transaksi
            <span class="ket">{{ collect($sumber)->filter(fn ($n, $k) => isset($perSumber[$k]))->map(fn ($n, $k) => $n.' '.rp($perSumber[$k]))->implode(' · ') }}</span>
            <span class="nilai">{{ rp($transaksi->sum('rp')) }}</span></div>
        <div class="anak" data-anak="{{ $id }}t" @if (! $terbuka) hidden @endif>
            <table class="kas" data-saring-kolom>
                <thead><tr><th>Tanggal</th><th>Sumber</th><th>ID</th><th>Kategori</th><th>Keterangan</th><th>Nama / PIC</th><th>No DO</th><th class="angka">Nominal</th></tr></thead>
                <tbody>
                    @forelse ($transaksi as $x)
                        @php $bln = \Illuminate\Support\Carbon::parse($x['tanggal']); $cari = ['tahun' => $bln->year, 'dari' => $bln->month, 'sampai' => $bln->month, 'q' => $x['id']]; @endphp
                        <tr>
                            <td data-n="{{ $x['tanggal'] }}" style="white-space: nowrap;">{{ $tgl($x['tanggal']) }}</td>
                            <td>{{ $sumber[$x['sumber']] ?? $x['sumber'] }}</td>
                            <td>@if ($x['id'] && (($x['sumber'] === 'uj' && $bolehUj) || ($x['sumber'] === 'kas' && $bolehKas)))<a href="{{ route($x['sumber'] === 'uj' ? 'uj.index' : 'kas.index', $cari) }}" target="_blank">{{ $x['id'] }}</a>@else{{ $x['id'] }}@endif</td>
                            <td>{{ $x['kategori'] }}</td>
                            <td>{{ $x['keterangan'] }}</td>
                            <td>{{ $x['nama'] }}</td>
                            <td>{{ $x['no_do'] }}</td>
                            <td class="angka" data-n="{{ $x['rp'] }}">{{ rp($x['rp']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="redup">Tidak ada transaksi biaya di periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
