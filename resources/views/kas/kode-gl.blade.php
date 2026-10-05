@extends('layouts.app', ['judul' => 'Kode GL'])

@section('lebar', '1180px')

@section('isi')
    <div class="kartu">
        <h3 style="margin: 0 0 4px;">Kode GL dari sheet</h3>
        <p class="redup" style="margin: 0 0 12px;">
            Setiap Kode GL yang pernah ditulis di sheet diurai menjadi akun baku, cost center, dan nomor T.
            {{ $kode->count() }} tulisan berbeda → {{ $kode->pluck('akun_gl_id')->filter()->unique()->count() }} akun.
            @if ($tanpaKode->jumlah)
                <br><span class="label merah">{{ $tanpaKode->jumlah }} transaksi detail tanpa Kode GL, total {{ rp($tanpaKode->total) }}</span>
            @endif
        </p>
        <a href="{{ route('kas.kode-gl') }}" @class(['chip', 'aktif' => ! $saring])>Semua</a>
        <a href="{{ route('kas.kode-gl', ['saring' => 'tanpa-cc']) }}" @class(['chip', 'aktif' => $saring === 'tanpa-cc'])>Tanpa cost center</a>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table style="font-size: 13px;">
            <thead>
                <tr>
                    <th style="padding-left: 14px;">Kelompok</th>
                    <th>Akun baku</th>
                    <th>Cost center</th>
                    <th>Tahap</th>
                    <th>Tulisan di sheet</th>
                    <th class="angka">Detail</th>
                    <th class="angka" style="padding-right: 14px;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($kode as $k)
                    <tr>
                        <td style="padding-left: 14px;" class="redup">{{ $k->akun?->kelompok ?? '—' }}</td>
                        <td><b>{{ $k->akun?->nama ?? '—' }}</b></td>
                        <td>
                            @if ($k->costCenter)
                                <span class="label" title="{{ $k->costCenter->nama }}">{{ $k->costCenter->kode }}</span>
                            @else
                                <span class="redup">—</span>
                            @endif
                        </td>
                        <td>{{ $k->ref }}</td>
                        <td>
                            {{ $k->kode_asli }}
                            @if ($k->akun && $k->kode_asli !== trim($k->akun->nama.' '.$k->costCenter?->kode.' '.$k->ref))
                                <span class="label" title="Tulisan di sheet berbeda dari akun baku">dirapikan</span>
                            @endif
                        </td>
                        <td class="angka">{{ rp($k->bon_count) }}</td>
                        <td class="angka" style="padding-right: 14px;">{{ rp($k->bon_sum_nominal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
