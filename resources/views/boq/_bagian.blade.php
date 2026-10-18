{{-- Asal "Bagian biaya lain" satu item BOQ: biaya tahap yang belum berkode BOQ × bobot Total Harga item (bukan transaksi item itu sendiri). --}}
@php
    $porsi = ($bobot ?? 0) / 100;
@endphp
<table class="rinci-trx" style="margin-top: 6px; max-width: 900px;">
    <thead><tr><th>Bagian biaya lain {{ $kode }} = biaya belum ber-BOQ × bobot {{ number_format($bobot ?? 0, 2, ',', '.') }}%</th><th class="angka">Biaya total</th><th class="angka">Bagian {{ $kode }}</th></tr></thead>
    <tbody>
        @foreach ($lain['sendiri'] as [$nm, $v])
            <tr><td>{{ $nama }} — {{ $nm }}</td><td class="angka">{{ rp((int) round($v)) }}</td><td class="angka">{{ rp((int) round($v * $porsi)) }}</td></tr>
        @endforeach
        @foreach ($lain['umum'] as $u)
            <tr><td>Bagian tahap umum {{ $u['tahap'] }} ({{ number_format($u['porsi'] * 100, 2, ',', '.') }}% dari {{ rp((int) round($u['total'])) }})</td><td class="angka">{{ rp((int) round($u['rp'])) }}</td><td class="angka">{{ rp((int) round($u['rp'] * $porsi)) }}</td></tr>
        @endforeach
    </tbody>
    <tfoot><tr><td>Total</td><td class="angka">{{ rp((int) round($lain['total'])) }}</td><td class="angka">{{ rp((int) round($bag)) }}</td></tr></tfoot>
</table>
<div class="redup" style="font-size: 11.5px; margin-top: 4px;">Ini pembagian sementara, bukan transaksi item {{ $kode }}. Transaksi Kas/BCA yang belum berkode ada di baris "belum diberi kode BOQ" di bawah tabel; begitu diberi kode {{ $kode }} di Pilah Transaksi, biayanya pindah ke kolom "Biaya keluar".</div>
