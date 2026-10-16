{{-- Rincian pengeluaran satu item BOQ (atau yang belum berkode BOQ): transaksi Kas & BCA yang dihitung, terbaru dulu. --}}
@php
    $daftar = collect($trx)->sortByDesc('tanggal')->values();
@endphp
<table class="rinci-trx">
    <thead><tr><th>Tanggal</th><th>Sumber</th><th>Keterangan</th><th>Akun / Kode GL</th><th class="angka">Nominal</th></tr></thead>
    <tbody>
        @foreach ($daftar as $x)
            <tr>
                <td style="white-space: nowrap;">{{ \Illuminate\Support\Carbon::parse($x->tanggal)->translatedFormat('d-M-Y') }}</td>
                <td>@if ($x->sumber === 'bca')<span class="label">BCA</span>@else<span class="label hijau">Kas</span>@if ($x->no_id)<span class="redup" style="font-size: 11px;"> #{{ $x->no_id }}</span>@endif @endif</td>
                <td>{{ $x->keterangan }}@if ($x->pic)<span class="redup" style="font-size: 11px;"> · {{ $x->pic }}</span>@endif
                    @if ($x->catatan)<div style="font-size: 12px; font-weight: 600; color: #93c5fd;">{{ $x->catatan }}</div>@endif</td>
                <td style="font-size: 12px;">{{ $x->akun }}@if ($x->kode)<br><span class="redup">{{ $x->kode }}</span>@endif</td>
                <td class="angka">{{ rp((int) round($x->nominal)) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot><tr><td colspan="4">{{ $daftar->count() }} transaksi @if ($bolehPilah ?? false)· <a href="{{ route('pilah.index') }}">ubah di Pilah Transaksi →</a>@endif</td><td class="angka">{{ rp((int) round($daftar->sum('nominal'))) }}</td></tr></tfoot>
</table>
