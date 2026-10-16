{{-- Rincian biaya tahap: transaksi Kas Harian yang jelas untuk tahap ini & biaya umum teralokasi per bulan.
     Dipakai Dashboard Progres Proyek & halaman Anggaran tahap. Param: $kas (Collection transaksi), $overheadBulan (array). --}}
@php
    $tglR = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y') : '';
    $persenR = fn ($v) => $v === null ? '–' : number_format($v, 1, ',', '.').'%';
@endphp
@once
    <style>
        .sub-judul { margin: 14px 0 4px; font-size: 13px; font-weight: 700; color: var(--aksen-terang); }
        .sub-judul .redup { font-weight: 400; font-size: 12px; }
        .gulir-kas { max-height: 320px; overflow: auto; border: 1px solid var(--garis); border-radius: 6px; padding: 2px 8px; }
        table.rinci { border-collapse: collapse; font-size: 12.5px; }
        table.rinci th, table.rinci td { padding: 3px 12px 3px 0; border: 0; text-align: left; }
        table.rinci .angka { text-align: right; }
        table.rinci tr.total td { border-top: 1px solid var(--garis); font-weight: 700; }
        .gulir-kas table.rinci th { position: sticky; top: 0; background: var(--kartu, #1e2229); }
    </style>
@endonce
<div class="sub-judul">Pengeluaran Kas Harian untuk tahap ini <span class="redup">({{ $kas->count() }} transaksi · {{ rp((int) round($kas->sum('nominal'))) }} — tanpa akun HPP ritasi, BBM, sparepart & DT)</span></div>
@if ($kas->isEmpty())
    <div class="redup" style="font-size: 12.5px;">Belum ada transaksi Kas Harian yang terpetakan ke tahap ini.</div>
@else
    <div class="gulir-kas">
        <table class="rinci">
            <tr><th>Tanggal</th><th>No ID</th><th>Kode GL</th><th>Akun</th><th>PIC</th><th>Keterangan</th><th class="angka">Nominal</th></tr>
            @foreach ($kas as $k)
                <tr><td style="white-space: nowrap;">{{ $tglR($k->tanggal) }}</td><td>{{ $k->no_id }}</td><td style="white-space: nowrap;">{{ $k->kode_asli }}</td><td>{{ $k->akun }}</td><td>{{ $k->pic }}</td><td>{{ $k->keterangan }}</td><td class="angka">{{ rp((int) round($k->nominal)) }}</td></tr>
            @endforeach
        </table>
    </div>
@endif

<div class="sub-judul">Biaya umum dibagi proporsional — per bulan <span class="redup">(dari alokasi yang sudah disimpan di Alokasi Biaya Umum)</span></div>
@if (! $overheadBulan)
    <div class="redup" style="font-size: 12.5px;">Belum ada biaya umum yang dialokasikan ke tahap ini.</div>
@else
    <table class="rinci">
        <tr><th>Bulan</th><th>Pos biaya umum</th><th>Cara bagi</th><th class="angka">Porsi</th><th class="angka">Bagian (Rp)</th></tr>
        @foreach ($overheadBulan as $o)
            <tr><td>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $o['bulan'].'-01')->translatedFormat('M Y') }}</td><td>{{ $o['pos'] }}</td>
                <td>{{ \App\Models\AlokasiOverhead::METODE[$o['metode']] ?? $o['metode'] }}</td><td class="angka">{{ $persenR($o['porsi']) }}</td><td class="angka">{{ rp((int) round($o['rp'])) }}</td></tr>
        @endforeach
        <tr class="total"><td>Total</td><td></td><td></td><td></td><td class="angka">{{ rp((int) round(array_sum(array_column($overheadBulan, 'rp')))) }}</td></tr>
    </table>
@endif
