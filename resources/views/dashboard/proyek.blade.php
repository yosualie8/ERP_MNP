@extends('layouts.app', ['judul' => 'Dashboard Progres Proyek'])

@section('lebar', '1400px')

@section('isi')
    @php
        $persen = fn ($v) => $v === null ? '–' : number_format($v, 1, ',', '.').'%';
        $ang = fn ($v, $d = 2) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',');
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y') : '';
        $bolehAnggaran = auth()->user()->bolehMenu('anggaran-proyek');
        $denganFisik = $baris->whereNotNull('fisik');
    @endphp
    @include('dashboard.gaya')
    <style>
        .bar { position: relative; height: 18px; border-radius: 9px; background: var(--kartu-2, #262b33); overflow: hidden; min-width: 140px; }
        .bar > span { position: absolute; inset: 0 auto 0 0; border-radius: 9px; }
        .bar > b { position: relative; z-index: 1; display: block; text-align: center; font-size: 12px; line-height: 18px; }
        table.grid { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.grid th, table.grid td { border: 1px solid var(--garis); padding: 6px 8px; vertical-align: middle; }
        table.grid .angka { text-align: right; white-space: nowrap; }
        table.grid tr.m { cursor: pointer; }
        table.grid tr.m .panah { display: inline-block; width: 12px; transition: transform .15s; color: var(--redup); }
        table.grid tr.m.buka .panah { transform: rotate(90deg); }
        table.grid tr.d { display: none; }
        table.grid tr.d.buka { display: table-row; }
        table.grid tr.d > td { background: rgba(0, 0, 0, .15); padding: 8px 12px 10px 34px; }
        table.rinci { border-collapse: collapse; font-size: 12.5px; }
        table.rinci th, table.rinci td { padding: 3px 12px 3px 0; border: 0; text-align: left; }
        table.rinci .angka { text-align: right; }
        table.rinci tr.total td { border-top: 1px solid var(--garis); font-weight: 700; }
    </style>

    <div class="dash-kartu">
        <div class="kartu"><h4>Tahap berstatus progress</h4><div class="angka-besar">{{ $baris->count() }}</div>
            <div class="redup" style="font-size: 13px;">{{ $baris->where('ada', true)->count() }} sudah punya anggaran · {{ $denganFisik->count() }} dengan progres fisik</div></div>
        <div class="kartu"><h4>Biaya sudah keluar</h4><div class="angka-besar" style="font-size: 26px;">{{ rp((int) round($baris->sum('realisasi'))) }}</div>
            <div class="redup" style="font-size: 13px;">dari HPP rencana {{ rp((int) round($baris->sum('hpp'))) }}</div></div>
        <div class="kartu"><h4>Perlu perhatian</h4>
            <div class="dash-baris"><span>Biaya terserap > progres fisik + 10%</span><b>{{ $baris->filter(fn ($x) => $x->fisik !== null && $x->serapan !== null && $x->serapan > $x->fisik + 10)->count() }}</b></div>
            <div class="dash-baris"><span>Realisasi melebihi HPP rencana</span><b>{{ $baris->filter(fn ($x) => $x->serapan > 100)->count() }}</b></div>
            <div class="dash-baris"><span>Belum ada anggaran</span><b>{{ $baris->where('ada', false)->count() }}</b></div></div>
    </div>

    <div style="display: flex; gap: 6px; justify-content: flex-end; margin-bottom: 6px;">
        <span class="redup" style="font-size: 12px; margin-right: auto; align-self: center;">Progres = kubikasi bongkaran ÷ m³ HPP tanah (cut & fill); tanpa baris tanah m³ = serapan biaya (realisasi ÷ HPP rencana), ditandai $. Klik baris untuk rincian biaya.</span>
        <button type="button" class="tombol polos" id="buka-semua" style="padding: 5px 12px; font-size: 13px;">⊞ Buka semua</button>
        <button type="button" class="tombol polos" id="tutup-semua" style="padding: 5px 12px; font-size: 13px;">⊟ Tutup semua</button>
    </div>
    <div class="kartu gulir" style="padding: 0;">
        <table class="grid" id="grid" data-saring-kolom>
            <thead><tr><th>Tahap</th><th style="width: 190px;">Progres</th><th class="angka">Biaya keluar</th><th class="angka">HPP rencana</th><th class="angka">Serapan biaya</th><th>Aktivitas awal</th><th>Aktivitas terakhir</th><th data-tanpa-saring style="width: 90px;"></th></tr></thead>
            <tbody>
                @foreach ($baris as $x)
                    @php
                        $p = $x->fisik ?? $x->serapan;
                        $boros = $x->fisik !== null && $x->serapan !== null && $x->serapan > $x->fisik + 10;
                        $warna = $p === null ? 'transparent' : ($p >= 100 ? '#2f7a55' : '#3b82f6');
                    @endphp
                    <tr class="m">
                        <td data-v="{{ $x->t->nama }} {{ $x->t->proyek->nama }} {{ $x->t->lokasi }} {{ $x->t->surveyor }}"><span class="panah">▸</span> <b>{{ $x->t->nama }}</b> <span class="redup" style="font-size: 11px;">· {{ $x->t->proyek->nama }}</span>
                            <div style="padding-left: 16px;">@include('proyek._sub', ['t' => $x->t])</div></td>
                        <td data-n="{{ $p }}" data-v="{{ $persen($p) }}" title="{{ $x->fisik !== null ? $ang($x->m3).' dari '.$ang($x->volTanah).' m³ bongkaran' : ($p !== null ? 'serapan biaya — belum ada baris HPP tanah m³' : 'belum ada anggaran') }}">
                            @if ($p !== null)
                                <div class="bar"><span style="width: {{ min(100, $p) }}%; background: {{ $warna }};"></span><b>{{ $persen($p) }}@if ($x->fisik === null) $@endif</b></div>
                            @else<span class="redup">belum ada anggaran</span>@endif
                        </td>
                        <td class="angka" data-n="{{ $x->realisasi }}"><b>{{ rp((int) round($x->realisasi)) }}</b></td>
                        <td class="angka" data-n="{{ $x->hpp }}">{{ $x->hpp ? rp((int) round($x->hpp)) : '' }}</td>
                        <td class="angka" data-n="{{ $x->serapan }}" @if ($x->serapan > 100 || $boros) style="color: {{ $x->serapan > 100 ? 'var(--merah)' : '#f0c05a' }}; font-weight: 700;" title="{{ $x->serapan > 100 ? 'Realisasi melebihi HPP rencana' : 'Biaya terserap lebih cepat dari progres fisik' }}" @endif>{{ $x->serapan !== null ? $persen($x->serapan) : '' }}</td>
                        <td data-n="{{ $x->awal }}" data-v="{{ $x->awal }} {{ $tgl($x->awal) }}" style="white-space: nowrap;">{{ $tgl($x->awal) }}</td>
                        <td data-n="{{ $x->terakhir }}" data-v="{{ $x->terakhir }} {{ $tgl($x->terakhir) }}" style="white-space: nowrap;">{{ $tgl($x->terakhir) }}</td>
                        <td style="text-align: center;">@if ($bolehAnggaran)<a href="{{ route('anggaran.edit', $x->t) }}" class="tombol-edit">Anggaran</a>@endif</td>
                    </tr>
                    <tr class="d">
                        <td colspan="8">
                            <table class="rinci">
                                <tr><th>Komponen</th><th class="angka">Rencana</th><th class="angka">Realisasi</th><th class="angka">Rencana (Rp)</th><th class="angka">Biaya keluar (Rp)</th><th class="angka">% terpakai</th></tr>
                                @forelse ($x->rincian as $k)
                                    @php($pakai = $k['rp_rencana'] > 0 && ! $k['tunda'] ? $k['rp_real'] / $k['rp_rencana'] * 100 : null)
                                    <tr>
                                        <td>{{ $k['nama'] }}@if ($k['tunda']) <span class="redup">(menunggu realisasi)</span>@endif @if ($k['catatan'] === 'tidak dianggarkan')<span class="redup"> · tidak dianggarkan</span>@endif</td>
                                        <td class="angka">{{ $k['v_rencana'] !== null ? $ang($k['v_rencana']).' '.$k['satuan'] : '' }}</td>
                                        <td class="angka">{{ $k['v_real'] !== null ? $ang($k['v_real']).' '.$k['satuan'] : '' }}</td>
                                        <td class="angka">{{ $k['rp_rencana'] ? rp((int) round($k['rp_rencana'])) : '' }}</td>
                                        <td class="angka">{{ $k['rp_real'] ? rp((int) round($k['rp_real'])) : '–' }}</td>
                                        <td class="angka" @if ($pakai > 100) style="color: var(--merah); font-weight: 700;" @endif>{{ $pakai !== null ? $persen($pakai) : '' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="redup">Belum ada biaya.</td></tr>
                                @endforelse
                                <tr class="total"><td>Total</td><td></td><td></td><td class="angka">{{ $x->hpp ? rp((int) round($x->hpp)) : '' }}</td><td class="angka">{{ rp((int) round($x->realisasi)) }}</td><td class="angka">{{ $x->serapan !== null ? $persen($x->serapan) : '' }}</td></tr>
                            </table>

                            @include('anggaran._rincian-biaya', ['kas' => $x->kas, 'overheadBulan' => $x->overheadBulan])
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <script>
        (() => {
            const master = [...document.querySelectorAll('#grid tbody tr.m')];
            const buka = (tr, b) => { tr.classList.toggle('buka', b); tr.nextElementSibling.classList.toggle('buka', b); };
            master.forEach(tr => tr.addEventListener('click', e => { if (!e.target.closest('a, button')) buka(tr, !tr.classList.contains('buka')); }));
            document.getElementById('buka-semua').addEventListener('click', () => master.forEach(m => buka(m, true)));
            document.getElementById('tutup-semua').addEventListener('click', () => master.forEach(m => buka(m, false)));
        })();
    </script>
@endsection
