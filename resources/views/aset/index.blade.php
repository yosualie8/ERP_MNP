@extends('layouts.app', ['judul' => 'Data Aset Truk'])

@section('lebar', '1440px')

@section('isi')
    @php($tglPendek = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->translatedFormat('j M') : '–')
    <div class="ringkas">
        <div class="kartu"><span class="redup">Truk MNP</span><b>{{ $semua->where('status', '!=', 'dijual')->count() }}</b></div>
        <div class="kartu"><span class="redup">Aktif</span><b>{{ $semua->where('status', 'aktif')->count() }}</b></div>
        <div class="kartu"><span class="redup">Perbaikan / tidak aktif</span><b>{{ $semua->whereIn('status', ['perbaikan', 'tidak_aktif'])->count() }}</b></div>
        <div class="kartu"><span class="redup">Rit 30 hari terakhir</span><b>{{ number_format(collect($statistik)->only($semua->pluck('no_lambung')->all())->sum('rit_30'), 0, ',', '.') }}</b></div>
        <div class="kartu"><span class="redup">Data janggal (sejak Jan 2026)</span><b><a href="{{ route('aset.temuan') }}">{{ number_format($temuanPerKode->flatten(1)->count(), 0, ',', '.') }} temuan</a></b></div>
    </div>

    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="redup">Status:</span>
        <a href="{{ route('aset.index') }}" @class(['chip', 'aktif' => ! $status])>Semua · {{ $semua->count() }}</a>
        @foreach (\App\Models\AsetTruk::STATUS as $k => $label)
            @if ($n = $semua->where('status', $k)->count())
                <a href="{{ route('aset.index', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $label }} · {{ $n }}</a>
            @endif
        @endforeach
        @if ($bolehUbah)<a href="{{ route('aset.create') }}" class="tombol" style="margin-left: auto; padding: 8px 14px;">+ Tambah truk</a>@endif
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead>
                <tr>
                    <th>No Lambung</th><th>Plat</th><th>Jenis</th><th>Status</th><th>Driver</th>
                    <th class="angka" title="Sejak 1 Jan 2026">Rit</th><th class="angka">Rit 30 hr</th><th>Rit terakhir</th>
                    <th class="angka">Kas UJ 30 hr</th><th>UJ terakhir</th><th>Galian terakhir</th><th class="angka">Temuan</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($truk as $a)
                    @php($st = $statistik[$a->no_lambung] ?? null)
                    @php($n = $temuanPerTruk[$a->no_lambung] ?? 0)
                    <tr class="t">
                        <td><a href="{{ route('aset.show', $a) }}"><b>{{ $a->no_lambung }}</b></a></td>
                        <td>{{ $a->plat ?? '—' }}</td>
                        <td>{{ $a->jenis }}</td>
                        <td>
                            <span @class(['label', 'hijau' => $a->status === 'aktif', 'kuning' => $a->status === 'perbaikan', 'merah' => $a->status === 'dijual'])>{{ $a->labelStatus() }}</span>
                            @if ($a->status === 'aktif' && $st && ! $st['rit_30'] && ! $st['uj_30'])<span class="label merah" title="Tidak ada rit maupun uang jalan 30 hari terakhir">diam</span>@endif
                            @if ($a->status !== 'aktif' && $st && $st['rit_30'])<span class="label kuning" title="Status bukan aktif tapi ada rit 30 hari terakhir">masih jalan</span>@endif
                        </td>
                        <td>{{ $a->driver_tetap ?? $st['driver'] ?? '' }}</td>
                        <td class="angka">{{ $st['rit'] ?? 0 }}</td>
                        <td class="angka"><b>{{ $st['rit_30'] ?? 0 }}</b></td>
                        <td>{{ $tglPendek($st['rit_terakhir'] ?? null) }}</td>
                        <td class="angka">{{ rp($st['uj_30'] ?? 0, true) }}</td>
                        <td>{{ $tglPendek($st['uj_terakhir'] ?? null) }}</td>
                        <td>{{ $st['galian'] ?? '' }}</td>
                        <td class="angka">@if ($n)<a href="{{ route('aset.temuan', ['mobil' => $a->no_lambung]) }}" class="label kuning">{{ $n }}</a>@else<span class="redup">–</span>@endif</td>
                        <td style="white-space: nowrap;">@if ($bolehUbah)<a href="{{ route('aset.edit', $a) }}" class="tombol-edit">Edit</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="redup" style="padding: 20px 14px;">Belum ada truk di Data Aset.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="redup">Rit & Kas UJ dihitung dari data ritasi dan Kas Seabank sejak 1 Jan 2026 (sebelumnya ritasi belum mencatat nomor DT). Klik nomor lambung untuk rincian truk.</p>
@endsection
