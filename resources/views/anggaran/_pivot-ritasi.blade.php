{{-- Pivot ritasi tahap: pemilik × jenis kendaraan → jumlah rit, lalu rit × Harga Jual (owner 19 Nov 2026). Butuh $pv = PivotRitasi::hitung(). --}}
@if ($pv['n_total'])
    @php
        $f = fn ($v) => number_format((float) $v, 0, ',', '.');
    @endphp
    <style>
        table.pivot-rit { font-size: 13px; }
        table.pivot-rit td.angka, table.pivot-rit th.angka { text-align: right; white-space: nowrap; }
        table.pivot-rit .jml { font-weight: 700; background: rgba(255, 255, 255, .03); }
        table.pivot-rit tfoot td { font-weight: 700; background: rgba(255, 255, 255, .03); }
    </style>
    @foreach (['n' => ['Pivot ritasi — jumlah rit per pemilik & jenis truck', 'rit'], 'rp' => ['Ritasi × harga jual (Rp)', 'Rp']] as $isi => [$judul, $sat])
        <div class="kartu gulir" style="padding: 0;">
            <div style="padding: 12px 14px 6px; display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap;">
                <h4 style="margin: 0; color: var(--aksen-terang);">{{ $judul }}</h4>
                <span class="redup" style="font-size: 12px;">
                    @if ($isi === 'n')
                        {{ $f($pv['n_total']) }} rit{{ $pv['ngepok'] ? ', termasuk '.$f($pv['ngepok']).' rit ngepok' : '' }} · baris = pemilik, kolom = jenis truck
                    @else
                        jumlah "Harga Jual" per rit di data Ritasi · rata-rata {{ rp((int) round($pv['rp_total'] / $pv['n_total'])) }}/rit
                    @endif
                </span>
            </div>
            <table class="kas pivot-rit" data-tanpa-beku>
                <thead><tr><th>Pemilik</th>@foreach ($pv['jenis'] as $j)<th class="angka">{{ $j }}</th>@endforeach<th class="angka">Total</th></tr></thead>
                <tbody>
                    @foreach ($pv['pemilik'] as $p)
                        <tr><td>{{ $p }}</td>
                            @foreach ($pv['jenis'] as $j)
                                @php($v = $pv[$isi][$p][$j] ?? null)
                                <td class="angka" data-n="{{ $v ?? 0 }}">{{ $v ? $f($v) : '' }}</td>
                            @endforeach
                            <td class="angka jml" data-n="{{ $pv[$isi.'_pemilik'][$p] }}">{{ $f($pv[$isi.'_pemilik'][$p]) }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td>Total</td>@foreach ($pv['jenis'] as $j)<td class="angka">{{ $f($pv[$isi.'_jenis'][$j]) }}</td>@endforeach<td class="angka">{{ $f($pv[$isi.'_total']) }}</td></tr></tfoot>
            </table>
        </div>
    @endforeach
@endif
