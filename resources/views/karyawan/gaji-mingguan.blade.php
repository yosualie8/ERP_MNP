@extends('layouts.app', ['judul' => 'Rekap Gaji Mingguan'])

@section('lebar', '1400px')

@section('isi')
    <style>
        .ringkas-gaji { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas-gaji .kartu { margin: 0; padding: 10px 14px; }
        .ringkas-gaji b { display: block; font-size: 22px; }
        .hari-gaji { text-align: center; white-space: nowrap; font-size: 12px; }
        .hari-gaji.ada { color: var(--hijau, #3fb950); }
        .hari-gaji.kurang { color: #f0c05a; }
    </style>

    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('gaji-mingguan.index', ['minggu' => $senin->copy()->subWeek()->toDateString()]) }}">‹</a>
        <input type="text" name="minggu" data-tanggal-id value="{{ $senin->toDateString() }}" style="width: 150px;" onchange="this.form.submit()">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('gaji-mingguan.index', ['minggu' => $senin->copy()->addWeek()->toDateString()]) }}">›</a>
        <b>{{ $senin->translatedFormat('j M') }} – {{ $hari->last()->translatedFormat('j M Y') }}</b>
        <a class="tombol polos" style="padding: 6px 12px; margin-left: auto;" href="{{ route('gaji-mingguan.unduh', ['minggu' => $senin->toDateString()]) }}">⬇ Excel</a>
    </form>

    <div class="ringkas-gaji">
        <div class="kartu"><span class="redup">Karyawan hadir</span><b>{{ $jumlah['hadir'] }}</b></div>
        <div class="kartu"><span class="redup">Total hari hadir</span><b>{{ $jumlah['hari'] }}</b></div>
        <div class="kartu"><span class="redup">Total UM</span><b>{{ rp($jumlah['total']) }}</b></div>
        <div class="kartu"><span class="redup">Hadir tanpa tarif UM</span><b @if ($jumlah['tanpa_tarif']) style="color: var(--merah);" @endif>{{ $jumlah['tanpa_tarif'] }}</b>
            <a class="redup" style="font-size: 12px;" href="{{ route('jabatan.index') }}">isi di Master Jabatan</a></div>
    </div>

    <p class="redup" style="font-size: 12.5px; margin: 0 0 10px;">Versi awal: hari hadir = hari yang ada absen masuk atau pulang (Lapor Lapangan) × tarif UM per hari jabatannya.
        Lembur dan absen yang belum lengkap hanya dicatat, belum mengubah hitungan. Karyawan yang belum terdaftar di Data Karyawan belum ikut.</p>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr>
                <th>Karyawan</th><th>Jabatan</th><th>Site</th>
                @foreach ($hari as $h)<th class="hari-gaji">{{ $h->translatedFormat('D') }}<br>{{ $h->format('j/n') }}</th>@endforeach
                <th class="angka">Hari hadir</th><th class="angka">Tarif / hari</th><th class="angka">Total UM</th><th class="angka">Lembur</th><th>Catatan</th>
            </tr></thead>
            <tbody>
                @forelse ($baris as $b)
                    <tr @if (! $b->hadir) class="redup" @endif>
                        <td><b>{{ $b->k->nama }}</b></td>
                        <td>{{ $b->k->jabatan }}</td>
                        <td>{{ $b->k->site }}</td>
                        @foreach ($b->hari as $x)
                            @if ($x)
                                <td class="hari-gaji {{ $x['masuk'] && $x['pulang'] ? 'ada' : 'kurang' }}" title="masuk {{ $x['masuk'] ?? '–' }} · pulang {{ $x['pulang'] ?? '–' }}{{ $x['lembur'] ? ' · lembur' : '' }}">✓{{ $x['lembur'] ? '+L' : '' }}</td>
                            @else
                                <td class="hari-gaji redup">·</td>
                            @endif
                        @endforeach
                        <td class="angka">{{ $b->hadir }}</td>
                        <td class="angka" data-n="{{ $b->tarif ?? '' }}">{{ $b->tarif ? rp($b->tarif) : '–' }}</td>
                        <td class="angka" data-n="{{ $b->total }}"><b>{{ $b->total ? rp($b->total) : '' }}</b></td>
                        <td class="angka" data-n="{{ $b->lembur }}">{{ $b->lembur ? intdiv($b->lembur, 60).' j '.($b->lembur % 60).' m' : '' }}</td>
                        <td style="font-size: 12px;">{{ implode('; ', $b->catatan) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="15" class="redup" style="padding: 20px 14px;">Belum ada karyawan.</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr>
                <th colspan="10">Total</th><th class="angka">{{ $jumlah['hari'] }}</th><th></th><th class="angka">{{ rp($jumlah['total']) }}</th><th colspan="2"></th>
            </tr></tfoot>
        </table>
    </div>
@endsection
