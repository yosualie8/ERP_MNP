@extends('layouts.app', ['judul' => 'Lokasi Absen'])

@section('lebar', '1200px')

@section('isi')
    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px;">
        <span class="redup">Karyawan hanya bisa absen di dalam radius lokasi yang <b>aktif</b>. Simpan titik dengan berdiri di lokasi proyek lalu tekan "Ambil titik saya".</span>
        <a href="{{ route('lokasi-absen.create') }}" class="tombol" style="margin-left: auto; padding: 8px 14px; white-space: nowrap;">+ Tambah lokasi</a>
    </div>
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Lokasi</th><th>Proyek</th><th>Titik GPS</th><th class="angka">Radius</th><th>Status</th><th class="angka">Absen tercatat</th><th data-tanpa-saring></th></tr></thead>
            <tbody>
                @forelse ($lokasi as $l)
                    <tr class="t">
                        <td><b>{{ $l->nama }}</b></td>
                        <td>{{ $l->proyek?->nama }}</td>
                        <td><a href="{{ $l->urlPeta() }}" target="_blank" rel="noopener">{{ number_format($l->lat, 6, '.', '') }}, {{ number_format($l->lng, 6, '.', '') }}</a></td>
                        <td class="angka">{{ $l->radius_m }} m</td>
                        <td><span @class(['label', 'hijau' => $l->aktif])>{{ $l->aktif ? 'Aktif' : 'Nonaktif' }}</span></td>
                        <td class="angka">{{ number_format($l->absensi_count, 0, ',', '.') }}</td>
                        <td><a href="{{ route('lokasi-absen.edit', $l) }}" class="tombol-edit">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="redup" style="padding: 20px 14px;">Belum ada lokasi absen. Tambahkan minimal satu lokasi supaya karyawan bisa absen.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
