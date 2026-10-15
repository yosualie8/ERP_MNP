@extends('layouts.app', ['judul' => 'Anggaran Proyek'])

@section('lebar', '1500px')

@section('isi')
    @php($persen = fn ($v) => $v === null ? '' : number_format($v, 1, ',', '.').'%')
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <a href="{{ route('anggaran.index') }}" @class(['chip', 'aktif' => ! $semua])>Berjalan & yang sudah beranggaran</a>
        <a href="{{ route('anggaran.index', ['semua' => 1]) }}" @class(['chip', 'aktif' => $semua])>Semua tahap</a>
        <span class="redup" style="font-size: 13px;">Realisasi HPP = biaya langsung (Harga Jual ritasi bongkaran & ngepok, solar & rental dari Data BBM & HM, Kas Harian tanpa akun HPP/BBM/sparepart/DT) + biaya umum yang sudah dialokasikan. Serapan biaya = realisasi ÷ HPP rencana. Progres (cut & fill) = kubikasi bongkaran Ritasi ÷ m³ HPP tanah; tahap tanpa baris tanah m³ memakai serapan biaya (ditandai $).</span>
        <span style="margin-left: auto; display: flex; gap: 6px;">
            @if (auth()->user()->bolehMenu('alokasi-overhead'))<a href="{{ route('alokasi-overhead.index') }}" class="tombol polos" style="padding: 6px 12px;">Alokasi Biaya Umum</a>@endif
            @if (auth()->user()->bolehMenu('komponen-anggaran'))<a href="{{ route('komponen-anggaran.index') }}" class="tombol polos" style="padding: 6px 12px;">Master Komponen</a>@endif
        </span>
    </div>
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Tahap</th><th>Status</th><th class="angka">Omset rencana</th><th class="angka">HPP rencana</th><th class="angka">Margin rencana</th>
                <th class="angka">Biaya langsung</th><th class="angka">Biaya umum</th><th class="angka">Realisasi HPP</th><th class="angka">Serapan biaya</th><th class="angka" title="Cut & fill: kubikasi bongkaran ÷ m³ HPP tanah; tanpa baris tanah m³ = serapan biaya">Progres</th><th>Baseline</th><th data-tanpa-saring></th></tr></thead>
            <tbody>
                @foreach ($baris as $x)
                    <tr class="t">
                        <td data-v="{{ $x->t->nama }} {{ $x->t->proyek->nama }}"><b>{{ $x->t->nama }}</b> <span class="redup" style="font-size: 11px;">· {{ $x->t->proyek->nama }}</span></td>
                        <td>{{ \App\Models\ProyekTahap::STATUS[$x->t->status] }}</td>
                        <td class="angka" data-n="{{ $x->h['omset'] }}">{{ $x->ada ? rp((int) round($x->h['omset'])) : '' }}</td>
                        <td class="angka" data-n="{{ $x->h['hpp'] }}">{{ $x->ada ? rp((int) round($x->h['hpp'])) : '' }}</td>
                        <td class="angka" data-n="{{ $x->h['persen_margin'] }}" @if ($x->ada && $x->h['margin'] < 0) style="color: var(--merah);" @endif>{{ $x->ada ? $persen($x->h['persen_margin']) : '' }}</td>
                        <td class="angka" data-n="{{ $x->langsung }}">{{ $x->langsung ? rp($x->langsung) : '' }}</td>
                        <td class="angka" data-n="{{ $x->overhead }}">{{ $x->overhead ? rp((int) round($x->overhead)) : '' }}</td>
                        <td class="angka" data-n="{{ $x->realisasi }}">{{ $x->realisasi ? rp((int) round($x->realisasi)) : '' }}</td>
                        @php($boros = $x->fisik !== null && $x->serapan !== null && $x->serapan > $x->fisik + 10)
                        <td class="angka" data-n="{{ $x->serapan }}" @if ($x->serapan > 100 || $boros) style="color: var(--merah); font-weight: 700;" title="{{ $boros ? 'Biaya terserap jauh di atas progres fisik' : 'Realisasi melebihi HPP rencana' }}" @endif>{{ $persen($x->serapan) }}</td>
                        <td class="angka" data-n="{{ $x->progres }}" title="{{ $x->fisik !== null ? number_format($x->m3, 0, ',', '.').' m³ bongkaran' : 'serapan biaya (belum ada m³ HPP tanah)' }}">{{ $persen($x->progres) }}@if ($x->fisik === null && $x->progres !== null)<span class="redup" style="font-size: 11px;"> ($)</span>@endif</td>
                        <td>@if ($x->t->anggaran_dikunci_at)<span class="label hijau">{{ $x->t->anggaran_dikunci_at->translatedFormat('j M Y') }}</span>@elseif ($x->ada)<span class="redup">belum dikunci</span>@endif</td>
                        <td><a href="{{ route('anggaran.edit', $x->t) }}" class="tombol-edit">{{ $x->ada ? 'Buka' : 'Isi anggaran' }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
