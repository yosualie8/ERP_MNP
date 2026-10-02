@extends('layouts.app', ['judul' => 'Rekap Biaya'])

@section('lebar', '1440px')

@section('isi')
    <div class="kartu">
        <h3 style="margin: 0 0 4px;">Rekap pengeluaran per akun</h3>
        <p class="redup" style="margin: 0 0 12px;">Jumlah bon per akun baku dan bulan. Kode GL di sheet sudah dirapikan (salah ketik digabung), lihat menu Kode GL.</p>
        <div>
            <span class="redup" style="margin-right: 6px;">Cost center:</span>
            <a href="{{ route('kas.rekap') }}" @class(['chip', 'aktif' => ! $ccDipilih])>Semua</a>
            @foreach ($costCenter as $c)
                <a href="{{ route('kas.rekap', ['cc' => $c->kode]) }}" @class(['chip', 'aktif' => $ccDipilih === $c->kode])>{{ $c->kode }}</a>
            @endforeach
            <a href="{{ route('kas.rekap', ['cc' => '-']) }}" @class(['chip', 'aktif' => $ccDipilih === '-'])>Tanpa cost center</a>
        </div>
    </div>

    @php($totalBulan = [])
    <div class="kartu gulir" style="padding: 0;">
        <table style="font-size: 13px;">
            <thead>
                <tr>
                    <th style="padding-left: 14px;">Akun</th>
                    @foreach ($daftarBulan as $b)
                        <th class="angka">{{ $b->bulan->translatedFormat('M') }}</th>
                    @endforeach
                    <th class="angka" style="padding-right: 14px;">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($matriks as $kelompok => $akun)
                    @php($subtotal = [])
                    <tr><td colspan="{{ $daftarBulan->count() + 2 }}" style="padding-left: 14px; background: var(--latar);"><b>{{ $kelompok }}</b></td></tr>
                    @foreach ($akun as $nama => $perBulan)
                        <tr>
                            <td style="padding-left: 24px;">{{ $nama }}</td>
                            @foreach ($daftarBulan as $b)
                                @php($n = $perBulan[$b->id] ?? 0)
                                @php($subtotal[$b->id] = ($subtotal[$b->id] ?? 0) + $n)
                                @php($totalBulan[$b->id] = ($totalBulan[$b->id] ?? 0) + $n)
                                <td class="angka">{{ rp($n, true) }}</td>
                            @endforeach
                            <td class="angka" style="padding-right: 14px;"><b>{{ rp(array_sum($perBulan)) }}</b></td>
                        </tr>
                    @endforeach
                    <tr>
                        <td style="padding-left: 24px;" class="redup">Subtotal {{ $kelompok }}</td>
                        @foreach ($daftarBulan as $b)
                            <td class="angka redup">{{ rp($subtotal[$b->id] ?? 0, true) }}</td>
                        @endforeach
                        <td class="angka redup" style="padding-right: 14px;">{{ rp(array_sum($subtotal)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $daftarBulan->count() + 2 }}" class="redup" style="padding: 20px 14px;">Belum ada data.</td></tr>
                @endforelse
            </tbody>
            @if ($matriks)
                <tfoot>
                    <tr style="background: var(--hijau-muda);">
                        <td style="padding-left: 14px;"><b>Total pengeluaran</b></td>
                        @foreach ($daftarBulan as $b)
                            <td class="angka"><b>{{ rp($totalBulan[$b->id] ?? 0, true) }}</b></td>
                        @endforeach
                        <td class="angka" style="padding-right: 14px;"><b>{{ rp(array_sum($totalBulan)) }}</b></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@endsection
