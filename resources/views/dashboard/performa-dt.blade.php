@extends('layouts.app', ['judul' => 'Performa DT (Laba Rugi)'])

@section('lebar', '1600px')

@section('isi')
    @php
        $namaBulan = fn ($b) => \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M y');
        $pp = $periode->param();
        $persen = fn ($a, $b) => $b ? number_format($a / $b * 100, 1, ',', '.').'%' : '-';
        // Laba/rugi berwarna: hijau untung, merah rugi.
        $lr = fn ($v) => '<span class="'.($v < 0 ? 'rugi' : ($v > 0 ? 'untung' : '')).'">'.($v < 0 ? '−' : '').rp(abs($v)).'</span>';
        $status = fn ($a) => $a ? (\App\Models\AsetTruk::STATUS[$a->status] ?? $a->status).($a->kepemilikan === 'rental' ? ' · rental' : '') : '';
    @endphp
    @include('dashboard.performa-dt-gaya')
    <style>
        .lr tr.master-dt { cursor: pointer; }
        .lr tr.master-dt .panah { display: inline-block; width: 12px; color: var(--aksen-terang); }
        .lr tr.d { display: none; }
        .lr tr.d.buka { display: table-row; }
        .lr tr.d > td { background: rgba(0, 0, 0, .18); padding: 10px 12px 14px 28px; text-align: left; }
    </style>

    @include('partials.periode', ['rute' => 'dashboard.performa-dt'])

    <p class="redup" style="font-size: 13px; margin: -4px 0 12px;">
        <b>Pendapatan</b> = kolom Harga Jual lembar Ritasi (per rit, menurut No Lambung). <b>Biaya</b> = biaya langsung truk dari Monitoring Biaya DT
        (Kas UJ + Kas Harian yang ber-No Mobil) ditambah transfer BCA berstatus Dihitung yang menyebut nomor DT. Biaya bersama yang tidak ber-No Mobil — gaji, solar tangki, sparepart tanpa No Mobil, sewa, overhead — belum dibagi ke truk.
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

    {{-- Per DT: master sederhana; klik baris → detail 1 (pendapatan per proyek, pengeluaran per kategori) & detail 2 (semua transaksi biaya). --}}
    <div class="kartu gulir lr" style="padding: 0;">
        <div style="padding: 12px 14px 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <b class="dash-judul">Laba rugi per DT</b>
            <span class="redup" style="font-size: 13px; margin-left: auto;">Klik baris DT untuk membuka / menutup rinciannya · klik nomor DT untuk membuka rinciannya di halaman baru.</span>
        </div>
        <table class="kas" id="grid-dt">
            <thead><tr><th>DT</th><th>Jenis</th><th>Status</th><th class="angka">Rit</th><th class="angka">m³</th>
                <th class="angka">Total pendapatan</th><th class="angka">Total pengeluaran</th><th class="angka">Laba / rugi</th></tr></thead>
            <tbody>
                @foreach ($truk as $t)
                    <tr class="t master-dt" data-mobil="{{ $t['mobil'] }}">
                        <td data-v="{{ $t['mobil'] }}"><span class="panah">▸</span> <a class="dt" href="{{ route('dashboard.performa-dt.truk', $pp + ['mobil' => $t['mobil']]) }}" target="_blank" title="Buka rincian {{ $t['mobil'] }} di halaman baru">{{ $t['mobil'] }}</a></td>
                        <td>{{ $t['aset']?->jenis }}</td>
                        <td>{{ $status($t['aset']) }}</td>
                        <td class="angka" data-n="{{ $t['rit'] }}">{{ rp($t['rit'], true) }}</td>
                        <td class="angka" data-n="{{ $t['kubik'] }}">{{ $t['kubik'] ? number_format($t['kubik'], 0, ',', '.') : '-' }}</td>
                        <td class="angka" data-n="{{ $t['pendapatan'] }}">
                            @if ($t['tanpa_harga'])<span title="{{ $t['tanpa_harga'] }} rit tanpa Harga Jual (perkiraan Rp {{ rp($t['perkiraan']) }})" style="cursor: help;">⚠</span>@endif
                            {{ rp($t['pendapatan'], true) }}</td>
                        <td class="angka" data-n="{{ $t['biaya'] }}">{{ rp($t['biaya'], true) }}</td>
                        <td class="angka" data-n="{{ $t['laba'] }}"><b>{!! $lr($t['laba']) !!}</b></td>
                    </tr>
                    <tr class="d"><td colspan="8"><div class="isi-rinci redup">Memuat rincian…</div></td></tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="total"><td colspan="3">Total {{ $truk->count() }} DT</td>
                    <td class="angka">{{ rp($ringkas['rit']) }}</td><td class="angka">{{ number_format($ringkas['kubik'], 0, ',', '.') }}</td>
                    <td class="angka">{{ rp($ringkas['pendapatan']) }}</td><td class="angka">{{ rp($ringkas['biaya']) }}</td>
                    <td class="angka">{!! $lr($ringkas['laba']) !!}</td></tr>
            </tfoot>
        </table>
    </div>

    <script>
        (() => {
            const URL_RINCI = @json(route('dashboard.performa-dt.rinci', $pp));
            const grid = document.getElementById('grid-dt');
            grid.addEventListener('click', e => {
                if (e.target.closest('a, button, input')) return;
                // Baris master → buka-tutup baris rincian di bawahnya (dimuat sekali saat pertama dibuka).
                const tr = e.target.closest('tr.master-dt');
                if (!tr) return;
                const d = tr.nextElementSibling, buka = !d.classList.contains('buka');
                d.classList.toggle('buka', buka);
                tr.classList.toggle('pilih', buka);
                tr.querySelector('.panah').textContent = buka ? '▾' : '▸';
                if (buka && !d.dataset.dimuat) {
                    d.dataset.dimuat = '1';
                    fetch(URL_RINCI + '&mobil=' + encodeURIComponent(tr.dataset.mobil), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                        .then(r => r.ok ? r.text() : Promise.reject(r.status))
                        .then(html => { d.querySelector('.isi-rinci').outerHTML = html; window.pasangGridRinci(d); })
                        .catch(err => { d.dataset.dimuat = ''; d.querySelector('.isi-rinci').textContent = 'Gagal memuat rincian (' + err + '). Tutup lalu buka lagi untuk mencoba ulang.'; });
                }
            });
        })();
    </script>
@endsection
