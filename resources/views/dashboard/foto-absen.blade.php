@extends('layouts.app', ['judul' => 'Dashboard Foto Absensi'])

@section('lebar', '1600px')

@section('isi')
    @include('partials.gaya-dasbor')
    @php
        $C = \App\Http\Controllers\DashboardFotoAbsenController::class;
        $bawa = array_filter(['nama' => $nama, 'jabatan' => $jabatan, 'jenis' => $jenis]);
        $periode = ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString()];
        $q = fn (array $ubah) => route('dashboard.foto-absen', array_filter([...$periode, ...$bawa, ...$ubah], fn ($v) => $v !== null && $v !== ''));
    @endphp
    <style>
        .fa-saring { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 10px; }
        .fa-saring label { display: flex; flex-direction: column; gap: 3px; font-size: 12.5px; color: var(--redup); }
        .fa-saring input[name=nama], .fa-saring input[name=jabatan] { width: 210px; }
        .fa-cepat { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .fa-cepat .chip { margin: 0; }
        table.fa td { vertical-align: top; }
        table.fa td.sel { min-width: 200px; }
        .fa-kirim + .fa-kirim { margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--garis); }
        .fa-kirim .jam { font-weight: 600; font-variant-numeric: tabular-nums; }
        .fa-kirim .ket { font-size: 12px; color: var(--redup); margin-top: 3px; white-space: pre-line; max-width: 320px; }
        .fa-foto { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 4px; }
        .fa-foto button { position: relative; padding: 0; border: 1px solid var(--garis); border-radius: 6px; background: var(--latar); cursor: zoom-in; overflow: hidden; width: 96px; height: 96px; }
        .fa-foto button:hover, .fa-foto button:focus-visible { border-color: var(--aksen); outline: none; }
        .fa-foto img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .fa-foto span { position: absolute; left: 2px; bottom: 2px; font-size: 10px; padding: 0 4px; border-radius: 3px; background: rgba(0, 0, 0, .7); color: #fff; }
        .fa-foto span.galeri { background: #e0a526; color: #111; font-weight: 600; }
        .fa-nama { font-weight: 600; }
        @media (max-width: 700px) { .kpi-baris { grid-template-columns: repeat(2, minmax(0, 1fr)); } .kpi-baris .kpi { min-height: 0; } .fa-foto button { width: 80px; height: 80px; } }
        .fa-kosong { color: var(--redup); }
        #dialog-fa { border: 1px solid var(--garis); border-radius: 12px; background: #0b0c0f; color: var(--teks); padding: 0; width: min(1100px, 96vw); max-height: calc(100dvh - 24px); margin: auto; }
        #dialog-fa::backdrop { background: rgba(0, 0, 0, .8); }
        #dialog-fa .atas { display: flex; gap: 10px; align-items: flex-start; padding: 10px 14px; border-bottom: 1px solid var(--garis); }
        #dialog-fa .atas .info { flex: 1; font-size: 13px; line-height: 1.45; }
        #dialog-fa .atas .info .ket { color: var(--redup); white-space: pre-line; }
        #dialog-fa .atas button { background: none; border: 1px solid var(--garis-kuat); color: var(--teks); border-radius: 7px; padding: 5px 11px; cursor: pointer; font-size: 15px; }
        #dialog-fa .gambar { position: relative; }
        #dialog-fa .penampil { height: calc(100dvh - 150px); min-height: 280px; border: 0; border-radius: 0; }
        #dialog-fa .geser { position: absolute; top: 50%; z-index: 2; transform: translateY(-50%); background: rgba(0, 0, 0, .55); color: #fff; border: 0; font-size: 30px; width: 46px; height: 64px; cursor: pointer; border-radius: 8px; }
        #dialog-fa .geser.kiri { left: 8px; } #dialog-fa .geser.kanan { right: 8px; }
        #dialog-fa .bawah { padding: 6px 14px 10px; font-size: 12px; color: var(--redup); display: flex; justify-content: space-between; gap: 10px; }
    </style>

    <p class="redup" style="margin-top: 0;">Foto kiriman absen (Lapor Lapangan & absen langsung) per orang per hari. Saringan <b>Nama</b> dan <b>Jabatan</b> cocok sebagian — mis. ketik
        <i>Operator</i> untuk semua jabatan operator. Tanda <b style="color: #f0c05a;">galeri</b> = foto diambil dari galeri HP (bukan kamera langsung, bisa foto lama). Klik foto untuk memperbesar; ← → untuk foto sebelum / sesudahnya. Hanya melihat — koreksi jenis kiriman di Data Absensi & Lapor.</p>

    <form method="GET" class="fa-saring" id="form-fa">
        <label>Nama<input name="nama" value="{{ $nama }}" list="fa-daftar-nama" placeholder="semua orang" autocomplete="off"></label>
        <label>Jabatan<input name="jabatan" value="{{ $jabatan }}" list="fa-daftar-jabatan" placeholder="semua jabatan" autocomplete="off"></label>
        <label>Dari<input type="date" name="dari" value="{{ $dari->toDateString() }}"></label>
        <label>Sampai<input type="date" name="sampai" value="{{ $sampai->toDateString() }}"></label>
        <label>Jenis
            <select name="jenis">
                <option value="">Semua</option>
                @foreach ($C::KOLOM as $k => $l)<option value="{{ $k }}" @selected($jenis === $k)>{{ $l }}</option>@endforeach
            </select>
        </label>
        <button type="submit" class="tombol" style="padding: 8px 16px;">Tampilkan</button>
        @if ($nama !== '' || $jabatan !== '' || $jenis)<a class="tombol polos" style="padding: 8px 12px;" href="{{ route('dashboard.foto-absen', $periode) }}">Hapus saringan</a>@endif
        <datalist id="fa-daftar-nama">@foreach ($daftarNama as $n)<option value="{{ $n }}">@endforeach</datalist>
        <datalist id="fa-daftar-jabatan">@foreach ($daftarJabatan as $j)<option value="{{ $j }}">@endforeach</datalist>
    </form>
    <div class="fa-cepat">
        <span class="redup" style="font-size: 13px;">Cepat:</span>
        <a class="chip" href="{{ $q(['dari' => $sampai->copy()->subDay()->toDateString(), 'sampai' => $sampai->copy()->subDay()->toDateString()]) }}" title="Mundur sehari dari tanggal Sampai">‹ hari sebelumnya</a>
        <a @class(['chip', 'aktif' => $periode == $C::rentangCepat(1)]) href="{{ $q($C::rentangCepat(1)) }}">Hari ini</a>
        @foreach ([7, 14, 31] as $h)
            <a @class(['chip', 'aktif' => $periode == $C::rentangCepat($h)]) href="{{ $q($C::rentangCepat($h)) }}">{{ $h }} hari</a>
        @endforeach
        <span style="width: 12px;"></span>
        <a @class(['chip', 'aktif' => $jabatan === 'Operator']) href="{{ $q(['jabatan' => 'Operator', 'nama' => null]) }}">Semua operator</a>
    </div>
    @if ($dipotong)<p class="label kuning" style="font-size: 12.5px; padding: 4px 8px;">Rentang dibatasi {{ $C::MAKS_HARI }} hari — ditampilkan {{ $dari->translatedFormat('j M') }} s.d. {{ $sampai->translatedFormat('j M Y') }}.</p>@endif

    <div class="kpi-baris">
        <div class="kpi"><div class="kpi-label">Orang mengirim</div><div class="kpi-nilai">{{ $ringkas['orang'] }}</div>
            <div class="kpi-sub">{{ $dari->translatedFormat('j M') }} – {{ $sampai->translatedFormat('j M Y') }}</div></div>
        <div class="kpi"><div class="kpi-label">Hari-orang</div><div class="kpi-nilai">{{ $ringkas['hari'] }}</div><div class="kpi-sub">baris di tabel</div></div>
        <div class="kpi"><div class="kpi-label">Kiriman</div><div class="kpi-nilai">{{ $ringkas['kiriman'] }}</div>
            <div class="kpi-sub">{{ $ringkas['tanpaFoto'] ? $ringkas['tanpaFoto'].' tanpa foto' : 'semua berfoto' }}</div></div>
        <div class="kpi"><div class="kpi-label">Foto</div><div class="kpi-nilai">{{ $ringkas['foto'] }}</div>
            <div class="kpi-sub">{{ $ringkas['kiriman'] ? number_format($ringkas['foto'] / $ringkas['kiriman'], 1, ',', '.') : 0 }} per kiriman</div></div>
        @if ($nama !== '' || $jabatan !== '')
            <div @class(['kpi', 'peringatan' => $tanpaKiriman->isNotEmpty()])><div class="kpi-label">Aktif tanpa kiriman</div><div class="kpi-nilai">{{ $tanpaKiriman->count() }}</div>
                <div class="kpi-sub" title="{{ $tanpaKiriman->map(fn ($k) => $k->nama.' ('.$k->jabatan.')')->join(', ') }}">{{ \Illuminate\Support\Str::limit($tanpaKiriman->pluck('nama')->join(', '), 90) ?: 'semua mengirim' }}</div></div>
        @endif
    </div>

    <div class="kartu gulir bulat" style="padding: 0;">
        <table class="kas fa">
            <thead>
                <tr><th>Tanggal</th><th>Nama</th><th>Jabatan</th>
                    @foreach ($C::KOLOM as $k => $l)@if (! $jenis || $jenis === $k)<th>{{ $l }}</th>@endif @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($baris as $b)
                    <tr>
                        <td style="white-space: nowrap;" data-urut="{{ $b->tanggal }}">{{ \Illuminate\Support\Carbon::parse($b->tanggal)->translatedFormat('D, j M') }}</td>
                        <td><span class="fa-nama">{{ $b->k->nama }}</span>@if ($b->k->status !== 'aktif') <span class="label">{{ $b->k->status }}</span>@endif</td>
                        <td>{{ $b->k->jabatan }}</td>
                        @foreach ($C::KOLOM as $k => $l)
                            @continue($jenis && $jenis !== $k)
                            <td class="sel">
                                @forelse ($b->kolom[$k] ?? [] as $i)
                                    @php($info = $b->k->nama.' · '.$l.' '.$i->waktu->translatedFormat('D j M H:i'))
                                    <div class="fa-kirim">
                                        <span class="jam">{{ $i->waktu->format('H:i') }}</span>
                                        @if ($i->label)<span class="label">{{ $i->label }}</span>@endif
                                        @if ($i->luar)<span class="label kuning" title="Dikirim di luar radius lokasi absen">luar lokasi</span>@endif
                                        @if ($i->foto)
                                            <div class="fa-foto">
                                                @foreach ($i->foto as $f)
                                                    <button type="button" class="fa-buka" data-besar="{{ $f['besar'] }}" data-kecil="{{ $f['kecil'] }}" data-info="{{ $info }}" data-ket="{{ $i->keterangan }}" title="Perbesar">
                                                        <img src="{{ $f['kecil'] }}" alt="foto {{ $b->k->nama }}" loading="lazy"><span @class(['galeri' => $f['tanda'] === 'galeri']) @if ($f['tanda'] === 'galeri') title="Dari galeri HP, bukan kamera langsung — bisa foto lama" @endif>{{ $f['tanda'] }}</span></button>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="label merah">tanpa foto</span>
                                        @endif
                                        @if ($i->keterangan)<div class="ket">{{ $i->keterangan }}</div>@endif
                                    </div>
                                @empty
                                    <span class="fa-kosong">–</span>
                                @endforelse
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="7" class="redup" style="padding: 18px;">Tidak ada kiriman foto untuk saringan ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <dialog id="dialog-fa">
        <div class="atas">
            <div class="info"><b id="fa-info"></b><div class="ket" id="fa-ket"></div></div>
            <a id="fa-asli" class="tombol-edit" target="_blank" rel="noopener" style="padding: 6px 10px;">Buka asli ↗</a>
            <button type="button" id="fa-tutup" title="Tutup (Esc)">✕</button>
        </div>
        <div class="gambar">
            <div class="penampil" id="fa-penampil"></div>
            <button type="button" class="geser kiri" id="fa-kiri" title="Sebelumnya (←)">‹</button>
            <button type="button" class="geser kanan" id="fa-kanan" title="Berikutnya (→)">›</button>
        </div>
        <div class="bawah"><span id="fa-urutan"></span><span>← → pindah foto · roda mouse / cubit = zoom · Esc tutup</span></div>
    </dialog>

    <script>
        (() => {
            const dlg = document.getElementById('dialog-fa');
            const penampil = PenampilFoto.pasang(document.getElementById('fa-penampil'));
            let daftar = [], posisi = 0;
            // Urutan foto = urutan di layar (setelah disaring / diurutkan saring-kolom), hanya yang barisnya terlihat.
            const kumpulkan = () => [...document.querySelectorAll('table.fa .fa-buka')].filter(b => b.offsetParent !== null);
            const tampil = (i) => {
                posisi = (i + daftar.length) % daftar.length;
                const b = daftar[posisi];
                penampil.tampilkan(b.dataset.kecil);   // pratinjau kecil dulu supaya cepat, lalu ganti foto penuh
                const penuh = new Image();
                penuh.onload = () => { if (daftar[posisi] === b) penampil.tampilkan(b.dataset.besar); };
                penuh.src = b.dataset.besar;
                document.getElementById('fa-info').textContent = b.dataset.info;
                document.getElementById('fa-ket').textContent = b.dataset.ket || '';
                document.getElementById('fa-asli').href = b.dataset.besar;
                document.getElementById('fa-urutan').textContent = `Foto ${posisi + 1} dari ${daftar.length}`;
            };
            document.addEventListener('click', (e) => {
                const b = e.target.closest('.fa-buka');
                if (!b) return;
                daftar = kumpulkan();
                tampil(Math.max(0, daftar.indexOf(b)));
                if (!dlg.open) dlg.showModal();
            });
            document.getElementById('fa-kiri').onclick = () => tampil(posisi - 1);
            document.getElementById('fa-kanan').onclick = () => tampil(posisi + 1);
            document.getElementById('fa-tutup').onclick = () => dlg.close();
            dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
            dlg.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') { e.preventDefault(); e.stopPropagation(); tampil(posisi - 1); }
                if (e.key === 'ArrowRight') { e.preventDefault(); e.stopPropagation(); tampil(posisi + 1); }
            });
            dlg.addEventListener('close', () => penampil.tampilkan(''));
        })();
    </script>
@endsection
