@extends('layouts.app', ['judul' => 'Data Lapor Lapangan'])

@section('lebar', '1400px')

@section('isi')
    <style>
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 20px; }
        .orang { padding: 0; margin-bottom: 12px; }
        .orang .kepala { padding: 10px 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border-bottom: 1px solid var(--garis); }
        .kirim { display: grid; grid-template-columns: 70px minmax(0, 1fr) 330px; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--garis); }
        .kirim:last-child { border-bottom: none; }
        .kirim .foto { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 4px; }
        .kirim .foto img { width: 92px; height: 92px; object-fit: cover; border-radius: 6px; }
        .kirim .foto .g { position: relative; }
        .kirim .foto .g span { position: absolute; left: 2px; bottom: 2px; font-size: 10px; padding: 0 4px; border-radius: 3px; background: rgba(0, 0, 0, .7); color: #fff; }
        .kirim form { display: grid; gap: 4px; font-size: 12.5px; }
        .kirim form select, .kirim form input { padding: 4px 6px; font-size: 12.5px; }
        @media (max-width: 900px) { .kirim { grid-template-columns: 60px 1fr; } .kirim form { grid-column: 1 / -1; } }
    </style>
    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('lapor-data.index', ['tanggal' => $tanggal->copy()->subDay()->toDateString(), 'tahap' => $tahapId]) }}">‹</a>
        <input type="text" name="tanggal" data-tanggal-id value="{{ $tanggal->toDateString() }}" style="width: 150px;" onchange="this.form.submit()">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('lapor-data.index', ['tanggal' => $tanggal->copy()->addDay()->toDateString(), 'tahap' => $tahapId]) }}">›</a>
        <b>{{ $tanggal->translatedFormat('l, j F Y') }}</b>
        <span class="redup" style="margin-left: 12px;">Proyek:</span>
        <a href="{{ route('lapor-data.index', ['tanggal' => $tanggal->toDateString()]) }}" @class(['chip', 'aktif' => ! $tahapId])>Semua</a>
        @foreach ($proyek as $p)
            <a href="{{ route('lapor-data.index', ['tanggal' => $tanggal->toDateString(), 'tahap' => $p['t']->id]) }}" @class(['chip', 'aktif' => $tahapId === $p['t']->id])>{{ $p['t']->nama }} · {{ $p['n'] }}</a>
        @endforeach
        @if ($tanpaProyek)<span class="redup" style="font-size: 12px;">· {{ $tanpaProyek }} kiriman di luar lokasi proyek</span>@endif
        <a href="{{ route('absensi.index', ['tanggal' => $tanggal->toDateString()]) }}" class="tombol polos" style="margin-left: auto; padding: 6px 12px;">Data Absensi →</a>
    </form>

    <div class="ringkas">
        <div class="kartu"><span class="redup">Kiriman</span><b>{{ $ringkas['kiriman'] }}</b><span class="redup" style="font-size: 12px;">{{ $ringkas['foto'] }} foto</span></div>
        <div class="kartu"><span class="redup">Karyawan mengirim</span><b>{{ $ringkas['karyawan'] }}</b></div>
        <div class="kartu"><span class="redup">Laporan / progres</span><b>{{ $ringkas['laporan'] }}</b></div>
        <div class="kartu"><span class="redup">Di luar lokasi proyek</span><b @if ($ringkas['luar']) style="color: #f0c05a;" @endif>{{ $ringkas['luar'] }}</b></div>
        <div class="kartu"><span class="redup">Jam kerja standar</span><b>{{ $jam['masuk'] }}–{{ $jam['pulang'] }}</b><span class="redup" style="font-size: 12px;">lembur bila lewat {{ $jam['toleransi'] }} menit</span></div>
    </div>

    <details class="kartu" style="padding: 10px 14px;">
        <summary style="cursor: pointer;"><b>⚙ Jam kerja</b> <span class="redup" style="font-size: 12px;">— dasar pemilahan masuk / pulang / lembur; proyek boleh punya jam sendiri (kosong = standar)</span></summary>
        <form method="POST" action="{{ route('lapor-data.pengaturan') }}" style="margin-top: 10px;">@csrf
            <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: end;">
                <label>Masuk standar<input type="time" name="jam_masuk" value="{{ $jam['masuk'] }}" required></label>
                <label>Pulang standar<input type="time" name="jam_pulang" value="{{ $jam['pulang'] }}" required></label>
                <label>Toleransi sebelum dihitung lembur (menit)<input type="number" name="toleransi" value="{{ $jam['toleransi'] }}" min="0" max="240" required style="width: 90px;"></label>
            </div>
            @if ($tahapLokasi->isNotEmpty())
                <table class="kas" style="margin-top: 10px; max-width: 640px;" data-tanpa-saring-kolom>
                    <thead><tr><th>Proyek (punya lokasi absen)</th><th>Masuk</th><th>Pulang</th></tr></thead>
                    <tbody>@foreach ($tahapLokasi as $t)
                        <tr><td>{{ $t->nama }}</td><td><input type="time" name="tahap[{{ $t->id }}][masuk]" value="{{ $t->jam_masuk ? substr($t->jam_masuk, 0, 5) : '' }}"></td>
                            <td><input type="time" name="tahap[{{ $t->id }}][pulang]" value="{{ $t->jam_pulang ? substr($t->jam_pulang, 0, 5) : '' }}"></td></tr>
                    @endforeach</tbody>
                </table>
            @endif
            <button class="tombol" type="submit" style="margin-top: 10px;">Simpan jam kerja</button>
            <span class="redup" style="font-size: 12px;">Kiriman 31 hari terakhir dipilah ulang dengan jam baru.</span>
        </form>
    </details>

    @forelse ($perKaryawan as $kid => $g)
        @php($k = $g->first()->karyawan)
        @php($a = ($absen[$kid] ?? collect())->keyBy('jenis'))
        <div class="kartu orang">
            <div class="kepala">
                <b>{{ $k?->nama }}</b><span class="redup">{{ $k?->site }}{{ $k?->jabatan ? ' · '.$k->jabatan : '' }}</span>
                @foreach (['masuk' => 'Masuk', 'pulang' => 'Pulang', 'lembur_mulai' => 'Lembur', 'lembur_selesai' => 's/d'] as $j => $label)
                    @if (isset($a[$j]))<span @class(['label', 'hijau' => in_array($j, ['masuk', 'pulang'], true), 'kuning' => str_starts_with($j, 'lembur')])>{{ $label }} {{ $a[$j]->waktu->format('H:i') }}</span>@endif
                @endforeach
                @if (! isset($a['masuk']))<span class="label merah">Belum ada absen masuk (tidak ada foto kamera)</span>@elseif (! isset($a['pulang']))<span class="label kuning">Belum ada pulang</span>@endif
                <span class="redup" style="margin-left: auto; font-size: 12px;">{{ $g->count() }} kiriman</span>
            </div>
            @foreach ($g as $l)
                @php($j = $l->jenisAkhir())
                <div class="kirim">
                    <div><b>{{ $l->waktu->format('H:i') }}</b>
                        <div><span @class(['label', 'hijau' => in_array($j, ['masuk', 'pulang'], true), 'kuning' => $j === 'lembur', 'merah' => $j === 'abaikan'])>{{ \App\Models\Lapor::JENIS_ADMIN[$j] ?? $j }}</span></div>
                        @if ($l->jenis_admin)<div class="redup" style="font-size: 11px;">dikoreksi</div>@endif</div>
                    <div>
                        <div style="font-size: 13px;">@if ($l->di_lokasi)✅ {{ $l->tahap?->nama }}@elseif ($l->lokasi)<span style="color: #f0c05a;">📍 di luar lokasi · terdekat {{ $l->lokasi->label() }} ({{ $l->jarak_m >= 1000 ? number_format($l->jarak_m / 1000, 1, ',', '.').' km' : $l->jarak_m.' m' }})</span>@else<span class="redup">tanpa GPS</span>@endif
                            @if ($l->lat)<a href="https://maps.google.com/?q={{ $l->lat }},{{ $l->lng }}" target="_blank" class="redup" style="font-size: 12px;">peta</a>@endif</div>
                        @if ($l->keterangan)<div style="margin-top: 2px;">{{ $l->keterangan }}</div>@endif
                        <div class="foto">@foreach ($l->foto as $f)<a class="g" href="{{ route('lapor.foto', $f) }}" target="_blank"><img src="{{ route('lapor.foto', ['foto' => $f, 'kecil' => 1]) }}" alt="foto" loading="lazy"><span>{{ $f->sumber === 'galeri' ? 'galeri' : $f->kamera }}</span></a>@endforeach</div>
                    </div>
                    <form method="POST" action="{{ route('lapor-data.ubah', $l) }}">@csrf
                        <select name="jenis_admin"><option value="">Otomatis ({{ \App\Models\Lapor::JENIS[$l->jenis] ?? $l->jenis }})</option>
                            @foreach (\App\Models\Lapor::JENIS_ADMIN as $kj => $lj)<option value="{{ $kj }}" @selected($l->jenis_admin === $kj)>{{ $lj }}</option>@endforeach</select>
                        @if ($l->proyek_tahap_id && ($boq[$l->proyek_tahap_id] ?? collect())->isNotEmpty())
                            <select name="boq_item_id"><option value="">— item BOQ (progres) —</option>
                                @foreach ($boq[$l->proyek_tahap_id] as $b)<option value="{{ $b->id }}" @selected($l->boq_item_id === $b->id)>{{ \Illuminate\Support\Str::limit($b->label(), 60) }}</option>@endforeach</select>
                        @endif
                        <input name="catatan_admin" value="{{ $l->catatan_admin }}" maxlength="300" placeholder="catatan admin">
                        <button class="tombol polos" type="submit" style="padding: 3px 10px; font-size: 12px; justify-self: start;">Simpan</button>
                    </form>
                </div>
            @endforeach
        </div>
    @empty
        <div class="kartu redup">Belum ada kiriman Lapor Lapangan pada tanggal ini{{ $tahapId ? ' untuk proyek ini' : '' }}.</div>
    @endforelse
    <script src="{{ asset('js/tanggal-id.js') }}"></script>
@endsection
