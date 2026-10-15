@extends('layouts.app', ['judul' => 'Anggaran Proyek'])

@section('lebar', '1500px')

@section('isi')
    @php($persen = fn ($v) => $v === null ? '' : number_format($v, 1, ',', '.').'%')
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <a href="{{ route('anggaran.index') }}" @class(['chip', 'aktif' => ! $semua])>Berjalan & yang sudah beranggaran</a>
        <a href="{{ route('anggaran.index', ['semua' => 1]) }}" @class(['chip', 'aktif' => $semua])>Semua tahap</a>
        <span class="redup" style="font-size: 13px;">Biaya aktual = ritasi, ngepok, solar, rental & Kas Harian (sama dengan Daftar Proyek, belum termasuk overhead). Progres fisik = kubikasi Ritasi ÷ volume m³ rencana.</span>
        @if (auth()->user()->bolehMenu('komponen-anggaran'))<a href="{{ route('komponen-anggaran.index') }}" class="tombol polos" style="margin-left: auto; padding: 6px 12px;">Master Komponen</a>@endif
    </div>
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Tahap</th><th>Status</th><th class="angka">Omset rencana</th><th class="angka">HPP rencana</th><th class="angka">Margin rencana</th>
                <th class="angka">Biaya aktual</th><th class="angka">Biaya ÷ HPP</th><th class="angka">Progres fisik</th><th>Baseline</th><th data-tanpa-saring></th></tr></thead>
            <tbody>
                @foreach ($baris as $x)
                    @php($lebih = $x->pakaiHpp !== null && $x->fisik !== null && $x->pakaiHpp > $x->fisik + 10)
                    <tr class="t">
                        <td data-v="{{ $x->t->nama }} {{ $x->t->proyek->nama }}"><b>{{ $x->t->nama }}</b> <span class="redup" style="font-size: 11px;">· {{ $x->t->proyek->nama }}</span></td>
                        <td>{{ \App\Models\ProyekTahap::STATUS[$x->t->status] }}</td>
                        <td class="angka" data-n="{{ $x->h['omset'] }}">{{ $x->ada ? rp((int) round($x->h['omset'])) : '' }}</td>
                        <td class="angka" data-n="{{ $x->h['hpp'] }}">{{ $x->ada ? rp((int) round($x->h['hpp'])) : '' }}</td>
                        <td class="angka" data-n="{{ $x->h['persen_margin'] }}" @if ($x->ada && $x->h['margin'] < 0) style="color: var(--merah);" @endif>{{ $x->ada ? $persen($x->h['persen_margin']) : '' }}</td>
                        <td class="angka" data-n="{{ $x->biaya }}">{{ $x->biaya ? rp($x->biaya) : '' }}</td>
                        <td class="angka" data-n="{{ $x->pakaiHpp }}" @if ($lebih) style="color: var(--merah); font-weight: 700;" title="Biaya terpakai jauh di atas progres fisik" @endif>{{ $persen($x->pakaiHpp) }}</td>
                        <td class="angka" data-n="{{ $x->fisik }}">{{ $persen($x->fisik) }}</td>
                        <td>@if ($x->t->anggaran_dikunci_at)<span class="label hijau">{{ $x->t->anggaran_dikunci_at->translatedFormat('j M Y') }}</span>@elseif ($x->ada)<span class="redup">belum dikunci</span>@endif</td>
                        <td><a href="{{ route('anggaran.edit', $x->t) }}" class="tombol-edit">{{ $x->ada ? 'Buka' : 'Isi anggaran' }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
