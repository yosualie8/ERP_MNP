@extends('layouts.app', ['judul' => 'Mutasi Rekening PT (BCA)'])

@section('lebar', '1400px')

@section('isi')
    @php
        $nb = fn ($ym) => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $ym.'-01')->translatedFormat('F Y');
        $tgl = fn ($d) => $d ? $d->translatedFormat('d-M-Y') : '';
        $keluar = $baris->where('dbcr', 'DB')->sum('jumlah');
        $masuk = $baris->where('dbcr', 'CR')->sum('jumlah');
        $daftar = $bulanAda->keys()->values();
        $i = $bulan ? $daftar->search($bulan) : false;
    @endphp
    <style>
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 19px; margin-top: 2px; }
        .ringkas small { font-size: 12px; color: var(--redup); }
    </style>

    <div class="kartu" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <form method="GET" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
            @if ($i !== false && $i < $daftar->count() - 1)<a class="tombol polos" style="padding: 6px 12px;" href="{{ route('mutasi-pt.index', ['bulan' => $daftar[$i + 1]]) }}">‹</a>@endif
            <select name="bulan" onchange="this.form.q.value = ''; this.form.submit()" style="min-width: 170px;">
                @foreach ($bulanAda as $ym => $n)<option value="{{ $ym }}" @selected($q === '' && $ym === $bulan)>{{ $nb($ym) }} · {{ $n }}</option>@endforeach
            </select>
            @if ($i !== false && $i > 0)<a class="tombol polos" style="padding: 6px 12px;" href="{{ route('mutasi-pt.index', ['bulan' => $daftar[$i - 1]]) }}">›</a>@endif
            <input type="search" name="q" value="{{ $q }}" placeholder="Cari keterangan di semua bulan (mis. HPP Pm Infra)" style="min-width: 300px;" autocomplete="off">
            <button type="submit" class="tombol polos" style="padding: 6px 12px;">Cari</button>
            @if ($q !== '')<a href="{{ route('mutasi-pt.index', ['bulan' => $bulan]) }}" style="font-size: 13px;">hapus pencarian</a>@endif
        </form>
        <form method="POST" action="{{ route('mutasi-pt.perbarui') }}" style="margin-left: auto;">@csrf<button type="submit" class="tombol polos" style="padding: 6px 12px;">↻ Perbarui dari Drive</button></form>
    </div>

    <div class="ringkas">
        <div class="kartu"><small>{{ $q !== '' ? 'Hasil cari "'.$q.'"' : $nb($bulan ?? now()->format('Y-m')) }}</small><b>{{ number_format($baris->count(), 0, ',', '.') }} transaksi</b>@if ($baris->count() >= 3000)<small>dibatasi 3.000 baris — persempit pencarian</small>@endif</div>
        <div class="kartu"><small>Keluar (DB)</small><b style="color: var(--merah);">{{ rp((int) round($keluar)) }}</b></div>
        <div class="kartu"><small>Masuk (CR)</small><b style="color: var(--sukses);">{{ rp((int) round($masuk)) }}</b></div>
        <div class="kartu"><small>Saldo terakhir</small><b>{{ $saldoAkhir?->saldo !== null ? rp((int) round($saldoAkhir->saldo)) : '–' }}</b><small>{{ $saldoAkhir ? $tgl($saldoAkhir->tanggal) : '' }}</small></div>
    </div>
    <p class="redup" style="font-size: 12px; margin: -4px 0 10px;">Sumber: file Drive "{{ $impor['nama'] ?? 'Mutasi Rek PT Gabungan MNP.xlsx' }}" (sheet {{ \App\Support\ImporMutasiPt::LEMBAR }}) — hanya dilihat.
        @if ($impor) Diimpor {{ \Illuminate\Support\Carbon::parse($impor['waktu'])->translatedFormat('d-M H:i') }} ({{ number_format($impor['baris'], 0, ',', '.') }} baris), file diubah {{ \Illuminate\Support\Carbon::parse($impor['diubah'])->timezone(config('app.timezone'))->translatedFormat('d-M H:i') }}. Diperiksa otomatis tiap 30 menit.@endif</p>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Tanggal</th><th>Keterangan</th><th>DB/CR</th><th class="angka">Keluar</th><th class="angka">Masuk</th><th class="angka">Saldo</th></tr></thead>
            <tbody>
                @forelse ($baris as $m)
                    <tr class="t">
                        <td data-n="{{ $m->tanggal?->toDateString() }}" data-v="{{ $m->tanggal?->toDateString() }} {{ $tgl($m->tanggal) }}" style="white-space: nowrap;">{{ $tgl($m->tanggal) }}</td>
                        <td>{{ $m->keterangan }}</td>
                        <td>@if ($m->dbcr === 'DB')<span class="label merah">DB</span>@elseif ($m->dbcr === 'CR')<span class="label hijau">CR</span>@else{{ $m->dbcr }}@endif</td>
                        <td class="angka" data-n="{{ $m->dbcr === 'DB' ? $m->jumlah : '' }}">{{ $m->dbcr === 'DB' ? rp((int) round($m->jumlah)) : '' }}</td>
                        <td class="angka" data-n="{{ $m->dbcr === 'CR' ? $m->jumlah : '' }}">{{ $m->dbcr === 'CR' ? rp((int) round($m->jumlah)) : '' }}</td>
                        <td class="angka" data-n="{{ $m->saldo }}">{{ $m->saldo !== null ? rp((int) round($m->saldo)) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="redup" style="padding: 16px;">{{ $bulanAda->isEmpty() ? 'Belum ada data — tekan "Perbarui dari Drive".' : 'Tidak ada transaksi.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
