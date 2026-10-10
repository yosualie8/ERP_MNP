@extends('layouts.app', ['judul' => 'Dashboard UJ'])

@section('lebar', '1280px')

@section('isi')
    @php
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y') : '—';
        $waktu = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y H:i') : '—';
        $lalu = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->diffForHumans() : '';
    @endphp
    @include('dashboard.gaya')

    <div class="dash-kartu">
        <div class="kartu">
            <h4>Belum reimburse</h4>
            <div class="angka-besar">{{ number_format($belum['detail'], 0, ',', '.') }} <span style="font-size: 15px; font-weight: 400;">transaksi</span></div>
            <div style="font-size: 18px; margin: 4px 0 8px;"><b>{{ rp($belum['total']) }}</b> <span class="redup" style="font-size: 13px;">dari {{ $belum['transfer'] }} transfer</span></div>
            @if ($belum['tertua'])<div class="redup" style="font-size: 13px; margin-bottom: 8px;">Transaksi tertua yang belum: {{ $tgl($belum['tertua']) }} ({{ $lalu($belum['tertua']) }})</div>@endif
            @foreach ($belum['per_bulan'] as $bln => $x)
                <div class="dash-baris"><span>{{ \Illuminate\Support\Carbon::parse($bln.'-01')->translatedFormat('M Y') }}</span><span>{{ $x['detail'] }} transaksi · <b>{{ rp($x['total']) }}</b></span></div>
            @endforeach
            @if (auth()->user()->bolehMenu('reimburse-uj'))
                <div style="margin-top: 10px;"><a href="{{ route('reimburse.index') }}" class="tombol polos" style="padding: 4px 12px; font-size: 13px;">Reimburse UJ</a></div>
            @endif
        </div>

        <div class="kartu">
            <h4>Kas UJ terakhir diperbarui</h4>
            @if ($inputTerakhir)
                <div style="font-size: 20px; font-weight: 700;">{{ $lalu($inputTerakhir['waktu']) }}</div>
                <div class="redup" style="font-size: 13px; margin-bottom: 8px;">{{ $waktu($inputTerakhir['waktu']) }} · {{ \App\Support\AktivitasAdmin::nama($inputTerakhir['aksi']) }} oleh {{ $inputTerakhir['oleh'] }}<br>{{ \Illuminate\Support\Str::limit($inputTerakhir['ringkasan'], 120) }}</div>
            @endif
            <div class="dash-baris"><span>Transaksi terbaru (tanggal)</span><b>{{ $tgl($transaksiTerbaru) }}</b></div>
            <div class="dash-baris"><span>Sinkron terakhir dari sheet</span><span>{{ $waktu($sinkronTerakhir) }}@if ($sinkronTerakhir) <span class="redup">({{ $lalu($sinkronTerakhir) }})</span>@endif</span></div>
            <div class="dash-baris"><span>Pengajuan UJ menunggu realisasi</span><span>{{ (int) $pengajuanMenunggu->n }} transaksi · <b>{{ rp((int) $pengajuanMenunggu->s) }}</b></span></div>
        </div>

        <div class="kartu">
            <h4>5 reimburse UJ terakhir</h4>
            @forelse ($riwayat as $g)
                <div class="dash-baris" style="align-items: flex-start;">
                    <span>
                        <b>{{ $g['tanggal']->translatedFormat('d-M-Y') }}</b> <span class="redup">· {{ number_format($g['jumlah'], 0, ',', '.') }} baris · {{ $g['transfer'] }} transfer</span>
                        @forelse ($g['batch'] as $b)
                            <br><span class="redup" style="font-size: 12px;">{{ $b['oleh'] }} · {{ $waktu($b['waktu']) }} ({{ $lalu($b['waktu']) }})</span>
                            <br><span style="font-size: 12px;">{{ $b['catatan'] }}</span>
                        @empty
                            <br><span class="redup" style="font-size: 12px;">Diisi langsung di sheet Kas Seabank (kolom Tanggal Reimburse)</span>
                        @endforelse
                    </span>
                    <b style="white-space: nowrap;">{{ rp($g['total']) }}</b>
                </div>
            @empty
                <div class="redup">Belum ada data reimburse UJ.</div>
            @endforelse
        </div>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <div style="display: flex; align-items: center; gap: 10px; padding: 12px 14px 4px;">
            <h3 class="dash-judul" style="margin: 0;">Transaksi UJ terakhir diinput</h3>
            <span class="redup" style="font-size: 13px;">urut No UJ terbaru — termasuk yang diketik langsung di sheet</span>
            @if (auth()->user()->bolehMenu('kas-uj'))<a href="{{ route('uj.index') }}" class="redup" style="margin-left: auto; font-size: 13px;">Buka Kas UJ →</a>@endif
        </div>
        <table style="font-size: 13px;">
            <thead>
                <tr><th style="padding-left: 14px;">No UJ</th><th>Tanggal</th><th>Penerima</th><th>Rincian</th><th class="angka">Nominal</th><th>Reimburse</th><th style="padding-right: 14px;">Diinput</th></tr>
            </thead>
            <tbody>
                @foreach ($terakhir as $t)
                    @php
                        $isi = $t->detail->where('biaya_transfer', false);
                        $biaya = (int) $t->detail->where('biaya_transfer', true)->sum('nominal');
                        $sudah = $t->detail->whereNotNull('tanggal_reimburse')->count();
                        $app = $t->detail->map(fn ($d) => $lewatAplikasi[$d->id_uj] ?? null)->filter()->first();
                        $ids = $t->detail->pluck('id_uj')->filter();
                    @endphp
                    <tr>
                        <td style="padding-left: 14px;" class="redup" title="{{ $ids->implode(', ') }}">{{ $t->no_uj }}</td>
                        <td style="white-space: nowrap;">{{ $tgl($t->tanggal) }}</td>
                        <td>{{ $t->nama ?? '—' }} @if ($t->bank)<span class="redup">· {{ $t->bank }}</span>@endif</td>
                        <td>{{ $isi->count() }} transaksi <span class="redup">· {{ $isi->pluck('kategori')->filter()->unique()->take(3)->implode(', ') }}@if ($isi->pluck('no_mobil')->filter()->unique()->count()) · {{ $isi->pluck('no_mobil')->filter()->unique()->take(3)->implode(', ') }}@endif</span></td>
                        <td class="angka" style="white-space: nowrap;">{{ rp($isi->sum('nominal')) }}@if ($biaya)<br><span class="redup" style="font-size: 11px;">+ biaya {{ rp($biaya) }}</span>@endif</td>
                        <td style="white-space: nowrap;">
                            @if ($t->detail->isEmpty())<span class="redup">—</span>
                            @elseif ($sudah === $t->detail->count())<span class="label hijau">✓ Sudah · {{ $t->detail->max('tanggal_reimburse')?->translatedFormat('j M') }}</span>
                            @elseif ($sudah)<span class="label kuning">Sebagian {{ $sudah }}/{{ $t->detail->count() }}</span>
                            @else<span class="label merah">Belum</span>@endif
                        </td>
                        <td style="padding-right: 14px; white-space: nowrap;">
                            @if ($app){{ $app['oleh'] }} <span class="redup">{{ $waktu($app['waktu']) }}{{ $app['aksi'] === 'uj-ubah' ? ' (edit)' : '' }}</span>
                            @else<span class="redup">di sheet</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
