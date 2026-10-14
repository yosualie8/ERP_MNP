@extends('layouts.app', ['judul' => 'Data Absensi'])

@section('lebar', '1500px')

@section('isi')
    <style>
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 20px; }
        .sel-absen { white-space: nowrap; }
        .sel-absen a { margin-left: 4px; font-size: 12px; }
    </style>
    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('absensi.index', ['tanggal' => $tanggal->copy()->subDay()->toDateString()]) }}">‹</a>
        <input type="text" name="tanggal" data-tanggal-id value="{{ $tanggal->toDateString() }}" style="width: 150px;" onchange="this.form.submit()">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('absensi.index', ['tanggal' => $tanggal->copy()->addDay()->toDateString()]) }}">›</a>
        <b>{{ $tanggal->translatedFormat('l, j F Y') }}</b>
    </form>
    <div class="ringkas">
        <div class="kartu"><span class="redup">Absen masuk</span><b>{{ $hadir }} / {{ $aktif }}</b><span class="redup" style="font-size: 12px;">karyawan aktif</span></div>
        <div class="kartu"><span class="redup">Tidak absen</span><b style="{{ $aktif - $hadir > 0 ? 'color: var(--merah);' : '' }}">{{ max($aktif - $hadir, 0) }}</b><span class="redup" style="font-size: 12px;">dasar potongan UM (Senin–Minggu)</span></div>
        <div class="kartu"><span class="redup">Karyawan aktif tanpa email</span><b @if ($tanpaEmail) style="color: #f0c05a;" @endif>{{ $tanpaEmail }}</b><span class="redup" style="font-size: 12px;">belum bisa login untuk absen</span></div>
    </div>
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Karyawan</th><th>Site</th><th>Masuk</th><th>Pulang</th><th>Mulai lembur</th><th>Selesai lembur</th><th class="angka">Jam kerja</th><th>Proyek · lokasi</th><th>Keterangan</th></tr></thead>
            <tbody>
                @foreach ($baris as $b)
                    @php($a = $b['absen'])
                    @php($kerja = isset($a['masuk'], $a['pulang']) ? $a['masuk']->waktu->diffInMinutes($a['pulang']->waktu) : null)
                    @php($lembur = isset($a['lembur_mulai'], $a['lembur_selesai']) ? $a['lembur_mulai']->waktu->diffInMinutes($a['lembur_selesai']->waktu) : null)
                    <tr class="t">
                        <td><b>{{ $b['k']->nama }}</b></td>
                        <td>{{ $b['k']->site }}</td>
                        @foreach (['masuk', 'pulang', 'lembur_mulai', 'lembur_selesai'] as $j)
                            <td class="sel-absen">@if (isset($a[$j])){{ $a[$j]->waktu->translatedFormat('j M Y H:i') }}<a href="{{ route('absen.foto', $a[$j]) }}" target="_blank" title="Selfie · {{ $a[$j]->jarak_m }} m dari titik · akurasi ±{{ $a[$j]->akurasi_m }} m">📷</a>@endif</td>
                        @endforeach
                        <td class="angka">{{ $kerja !== null ? intdiv($kerja, 60).' j '.($kerja % 60).' m' : '' }}@if ($lembur)<br><span class="redup">+ lembur {{ intdiv($lembur, 60) }} j {{ $lembur % 60 }} m</span>@endif</td>
                        <td>{{ $a->first()?->lokasi?->tahap?->nama }}@if ($a->first()?->lokasi) <span class="redup">· {{ $a->first()->lokasi->nama }}</span>@endif</td>
                        <td>@if ($a->isEmpty())<span class="label merah">Tidak absen</span>@elseif (! isset($a['pulang']))<span class="label kuning">Belum / lupa absen pulang</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <script src="{{ asset('js/tanggal-id.js') }}"></script>
@endsection
