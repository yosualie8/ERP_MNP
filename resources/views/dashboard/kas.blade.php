@extends('layouts.app', ['judul' => 'Dashboard Kas Harian'])

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
            <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                @if (auth()->user()->bolehMenu('kas-belum-reimburse'))<a href="{{ route('kas.belum-reimburse') }}" class="tombol polos" style="padding: 4px 12px; font-size: 13px;">⬇ Excel belum reimburse</a>@endif
                @if (auth()->user()->bolehMenu('validasi-reimburse'))<a href="{{ route('kas.validasi-reimburse') }}" class="tombol polos" style="padding: 4px 12px; font-size: 13px;">Validasi Reimburse</a>@endif
            </div>
        </div>

        <div class="kartu">
            <h4>Kas Harian terakhir diperbarui</h4>
            @if ($inputTerakhir)
                <div style="font-size: 20px; font-weight: 700;">{{ $lalu($inputTerakhir['waktu']) }}</div>
                <div class="redup" style="font-size: 13px; margin-bottom: 8px;">{{ $waktu($inputTerakhir['waktu']) }} · {{ ['tambah' => 'Input', 'ubah' => 'Edit', 'hapus' => 'Hapus'][$inputTerakhir['aksi']] ?? $inputTerakhir['aksi'] }} oleh {{ $inputTerakhir['oleh'] }}<br>{{ \Illuminate\Support\Str::limit($inputTerakhir['ringkasan'], 120) }}</div>
            @endif
            <div class="dash-baris"><span>Transaksi terbaru (tanggal)</span><b>{{ $tgl($transaksiTerbaru) }}</b></div>
            <div class="dash-baris"><span>Sinkron terakhir dari sheet</span><span>{{ $waktu($sinkronTerakhir) }}@if ($sinkronTerakhir) <span class="redup">({{ $lalu($sinkronTerakhir) }})</span>@endif</span></div>
        </div>

        <div class="kartu">
            <h4>5 reimburse terakhir</h4>
            @forelse ($riwayat as $g)
                <div class="dash-baris" style="align-items: flex-start;">
                    <span>
                        <b>{{ $g['tanggal'] ? $g['tanggal']->translatedFormat('d-M-Y') : 'Tanpa tanggal' }}</b> <span class="redup">· {{ number_format($g['jumlah'], 0, ',', '.') }} transaksi</span>
                        <br><span class="redup" style="font-size: 12px;">@if ($g['oleh']){{ implode(', ', $g['oleh']) }} · {{ $waktu($g['dicatat']) }} ({{ $lalu($g['dicatat']) }})@else Dari lembar Sudah Reimburse (data awal)@endif</span>
                        @if ($g['catatan'])<br><span style="font-size: 12px;">{{ $g['catatan'] }}</span>@endif
                    </span>
                    <b style="white-space: nowrap;">{{ rp($g['total']) }}</b>
                </div>
            @empty
                <div class="redup">Belum ada data reimburse.</div>
            @endforelse
            @if (auth()->user()->bolehMenu('riwayat-reimburse'))<div style="margin: 6px 0 4px;"><a href="{{ route('kas.riwayat-reimburse') }}" class="redup" style="font-size: 13px;">Buka History Reimburse →</a></div>@endif
            <div class="dash-baris"><span>Status menunggu ditulis ke sheet</span><span>{{ $antreanSheet ? '⏳ '.$antreanSheet : '✓ semua sudah tertulis' }}</span></div>
        </div>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <div style="display: flex; align-items: center; gap: 10px; padding: 12px 14px 4px;">
            <h3 class="dash-judul" style="margin: 0;">Transaksi Kas Harian terakhir diinput</h3>
            <span class="redup" style="font-size: 13px;">urut NO ID terbaru — termasuk yang diketik langsung di sheet</span>
            @if (auth()->user()->bolehMenu('kas-harian'))<a href="{{ route('kas.index') }}" class="redup" style="margin-left: auto; font-size: 13px;">Buka Kas Harian →</a>@endif
        </div>
        <table style="font-size: 13px;">
            <thead>
                <tr><th style="padding-left: 14px;">NO ID</th><th>Tanggal</th><th>Tujuan / dari</th><th>Keterangan</th><th class="angka">Nominal</th><th>Reimburse</th><th style="padding-right: 14px;">Diinput</th></tr>
            </thead>
            <tbody>
                @foreach ($terakhir as $t)
                    @php
                        $ids = $t->bon->map(fn ($b) => \App\Support\StatusReimburse::idBon($b, $t));
                        $sudah = $ids->filter(fn ($id) => array_key_exists($id, $status))->count();
                        $app = $lewatAplikasi[$t->no_id] ?? null;
                    @endphp
                    <tr>
                        <td style="padding-left: 14px;" class="redup">{{ $t->no_id }}</td>
                        <td style="white-space: nowrap;">{{ $tgl($t->tanggal) }}</td>
                        <td>{{ $t->nama_tujuan ?? '—' }}</td>
                        <td>{{ $t->keterangan }}</td>
                        <td class="angka" style="white-space: nowrap;">@if ($t->debet)<span style="color: var(--hijau, #4ade80);">+{{ rp($t->debet) }}</span>@else{{ rp($t->kredit) }}@if ($t->biaya_transfer)<br><span class="redup" style="font-size: 11px;">+ biaya {{ rp($t->biaya_transfer) }}</span>@endif @endif</td>
                        <td style="white-space: nowrap;">
                            @if ($t->debet)<span class="redup">Saldo masuk</span>
                            @elseif ($ids->isEmpty())<span class="redup">—</span>
                            @elseif ($sudah === $ids->count())<span class="label hijau">✓ Sudah</span>
                            @elseif ($sudah)<span class="label kuning">Sebagian {{ $sudah }}/{{ $ids->count() }}</span>
                            @else<span class="label merah">Belum</span>@endif
                        </td>
                        <td style="padding-right: 14px; white-space: nowrap;">
                            @if ($app){{ $app['oleh'] }} <span class="redup">{{ $waktu($app['waktu']) }}{{ $app['aksi'] === 'ubah' ? ' (edit)' : '' }}</span>
                            @else<span class="redup">di sheet</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
