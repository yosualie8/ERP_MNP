@extends('layouts.app', ['judul' => 'Absen'])

@section('lebar', '560px')

@section('isi')
    <style>
        .absen-kartu { text-align: center; }
        .absen-kartu h2 { margin: 4px 0 2px; }
        .kamera { position: relative; width: 100%; max-width: 420px; aspect-ratio: 3 / 4; margin: 10px auto; background: #000; border-radius: 12px; overflow: hidden; }
        .kamera video, .kamera img { width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
        .kamera img { transform: none; }
        .tombol-besar { width: 100%; padding: 14px; font-size: 17px; border-radius: 10px; margin-top: 8px; }
        .status-gps { font-size: 13px; margin: 8px 0; }
        .status-gps.ok { color: var(--sukses); }
        .status-gps.galat { color: var(--merah); }
        .area { margin: 8px 0; padding: 10px 12px; border-radius: 10px; font-size: 14px; font-weight: 600; text-align: left; }
        .area.ok { background: rgba(76, 195, 138, .15); border: 1px solid #2f7a55; color: var(--sukses); }
        .area.luar { background: var(--merah-muda); border: 1px solid rgba(255, 92, 97, .4); color: var(--merah); }
        .area.tunggu { background: var(--kartu-2, #262b33); border: 1px solid var(--garis); color: var(--redup); font-weight: 400; }
        .area .baris-info { display: flex; gap: 6px; margin-top: 4px; font-size: 15px; color: var(--teks); }
        .area .baris-info span:first-child { color: var(--redup); font-weight: 400; min-width: 58px; }
        .area .kecil { font-size: 12px; font-weight: 400; margin-top: 4px; }
        .kamera .info-proyek { position: absolute; left: 8px; right: 8px; top: 8px; padding: 6px 10px; border-radius: 8px; background: rgba(0, 0, 0, .55);
            color: #fff; font-size: 13px; font-weight: 600; text-align: left; z-index: 1; }
        .riwayat td { padding: 6px 8px; font-size: 13px; }
        .jam { font-size: 32px; font-weight: 700; font-variant-numeric: tabular-nums; }
    </style>

    @if (! $karyawan)
        <div class="kartu">
            <h3 style="margin-top: 0;">Akun belum terhubung</h3>
            <p>Akun Google <b>{{ auth()->user()->email }}</b> belum terdaftar sebagai karyawan aktif. Minta admin mengisi email ini di Data Karyawan Anda.</p>
        </div>
    @else
        <div class="kartu absen-kartu">
            <div class="redup">{{ now()->translatedFormat('l, j F Y') }}</div>
            <div class="jam" id="jam">{{ now()->format('H:i') }}</div>
            <h2>{{ $karyawan->nama }}</h2>
            <div class="redup">{{ $karyawan->site }}{{ $karyawan->jabatan ? ' · '.$karyawan->jabatan : '' }}</div>

            @if ($berikut['sesi']->isNotEmpty())
                <div style="margin-top: 10px; font-size: 14px;">
                    @foreach ($berikut['sesi'] as $a)
                        <span class="label {{ $a->jenis === 'masuk' ? 'hijau' : '' }}" style="margin: 2px;">{{ \App\Models\Absensi::JENIS[$a->jenis] }} {{ $a->waktu->format('H:i') }}</span>
                    @endforeach
                </div>
            @endif

            @if (! $adaLokasi)
                <div class="pesan galat" style="margin-top: 12px;">Belum ada lokasi absen. Hubungi admin.</div>
            @elseif (! $berikut['jenis'])
                <div class="pesan sukses" style="margin-top: 12px;">Absen hari ini sudah lengkap (termasuk lembur). Sampai jumpa besok.</div>
            @else
                <form method="POST" action="{{ route('absen.simpan') }}" enctype="multipart/form-data" id="form-absen">
                    @csrf
                    <input type="hidden" name="jenis" value="{{ $berikut['jenis'] }}">
                    <input type="hidden" name="lat" id="lat"><input type="hidden" name="lng" id="lng"><input type="hidden" name="akurasi" id="akurasi">
                    <input type="file" name="foto" id="foto" accept="image/jpeg" hidden>
                    <div class="kamera"><div class="info-proyek" id="info-proyek" hidden></div><video id="video" autoplay playsinline muted></video><img id="hasil" hidden alt="selfie"></div>
                    <div class="area tunggu" id="area">📍 Mencari lokasi GPS…</div>
                    <div class="status-gps" id="status-gps"></div>
                    <button type="button" class="tombol polos tombol-besar" id="ambil" disabled title="Masuk ke area lokasi absen dulu">📸 Ambil selfie</button>
                    <button type="submit" class="tombol tombol-besar" id="kirim" disabled>Absen {{ \App\Models\Absensi::JENIS[$berikut['jenis']] }}</button>
                    <p class="redup" style="font-size: 12px; margin: 10px 0 0;">Waktu absen dicatat dari server. Absen hanya bisa di area lokasi proyek (radius ±100 m).</p>
                </form>
            @endif
        </div>

        @if ($riwayat->isNotEmpty())
            <div class="kartu" style="padding: 0;">
                <h4 style="margin: 0; padding: 12px 14px 6px;">Riwayat 7 hari</h4>
                <table class="riwayat" style="width: 100%;" data-tanpa-saring-kolom data-tanpa-beku>
                    <tbody>
                        @foreach ($riwayat as $a)
                            <tr><td>{{ $a->waktu->translatedFormat('D j M') }}</td><td><b>{{ $a->waktu->format('H:i') }}</b></td><td>{{ \App\Models\Absensi::JENIS[$a->jenis] }}</td>
                                <td class="redup">{{ $a->lokasi?->label() }}</td><td><a href="{{ route('absen.foto', $a) }}" target="_blank">foto</a></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($berikut['jenis'] && $adaLokasi)
            <script>
                (() => {
                    const video = document.getElementById('video'), hasil = document.getElementById('hasil'), kirim = document.getElementById('kirim');
                    const ambil = document.getElementById('ambil'), status = document.getElementById('status-gps');
                    let adaFoto = false, adaGps = false, stream = null, kameraGagal = false;
                    const area = document.getElementById('area');
                    // Tombol absen hanya aktif bila selfie ada DAN posisi di dalam area lokasi absen (dicek server realtime).
                    const siap = () => {
                        kirim.disabled = !(adaFoto && adaGps);
                        // Selfie juga hanya bisa diambil bila sudah di dalam area lokasi absen.
                        if (!kameraGagal) {
                            ambil.disabled = !adaGps;
                            ambil.title = adaGps ? '' : 'Masuk ke area lokasi absen dulu';
                        }
                        kirim.title = !adaGps ? 'Anda belum berada di area lokasi absen' : (!adaFoto ? 'Ambil selfie dulu' : '');
                    };
                    let terakhir = null, cekJalan = false, cekLagi = false;
                    const cekArea = async () => {
                        if (cekJalan) { cekLagi = true; return; }
                        cekJalan = true;
                        try {
                            const data = new FormData();
                            ['lat', 'lng', 'akurasi'].forEach(k => data.append(k, document.getElementById(k).value));
                            data.append('_token', document.querySelector('input[name=_token]').value);
                            const res = await fetch(@json(route('absen.cek')), {method: 'POST', body: data, headers: {Accept: 'application/json'}});
                            const h = await res.json();
                            area.className = 'area ' + (h.boleh ? 'ok' : 'luar');
                            const infoProyek = document.getElementById('info-proyek');
                            if (h.boleh) {
                                // Di dalam area: tampilkan tahap & lokasi proyek (juga di atas kamera selfie).
                                const baris = (label, isi) => { const d = document.createElement('div'); d.className = 'baris-info'; d.append(Object.assign(document.createElement('span'), {textContent: label}), Object.assign(document.createElement('b'), {textContent: isi || '-'})); return d; };
                                area.replaceChildren('✅ Anda di area absen', baris('Proyek', h.proyek), baris('Lokasi', h.lokasi), Object.assign(document.createElement('div'), {className: 'kecil', textContent: h.pesan}));
                                infoProyek.textContent = '📍 ' + h.proyek + (h.lokasi && h.lokasi !== h.proyek ? ' · ' + h.lokasi : '');
                                infoProyek.hidden = false;
                            } else {
                                area.textContent = '⛔ ' + (h.pesan || 'Lokasi tidak bisa dicek.');
                                infoProyek.hidden = true;
                            }
                            adaGps = !!h.boleh;
                        } catch (e) {
                            area.className = 'area tunggu'; area.textContent = '⏳ Memeriksa lokasi… (koneksi lambat)'; adaGps = false;
                        } finally {
                            cekJalan = false; siap();
                            if (cekLagi) { cekLagi = false; cekArea(); }
                        }
                    };
                    setInterval(() => { const d = new Date(); document.getElementById('jam').textContent = d.toTimeString().slice(0, 5); }, 10000);

                    // Kamera depan langsung (tidak bisa pilih foto dari galeri).
                    const nyalakan = async () => {
                        try {
                            stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 960}, height: {ideal: 1280}}, audio: false});
                            video.srcObject = stream; video.hidden = false; hasil.hidden = true;
                        } catch (e) { kameraGagal = true; ambil.disabled = true; ambil.textContent = '⚠️ Kamera tidak bisa dibuka — izinkan akses kamera'; }
                    };
                    ambil.addEventListener('click', () => {
                        if (adaFoto) { adaFoto = false; ambil.textContent = '📸 Ambil selfie'; siap(); nyalakan(); return; }
                        // Kamera baru menyala & belum mengirim gambar → tunggu sebentar.
                        if (!video.videoWidth || video.readyState < 2) { ambil.textContent = '⏳ Kamera belum siap — coba lagi'; setTimeout(() => { if (!adaFoto) ambil.textContent = '📸 Ambil selfie'; }, 1500); return; }
                        // Selalu PORTRAIT 3:4 (720×960), dipotong di tengah sama seperti pratinjau kamera —
                        // kamera laptop / sebagian HP mengirim gambar landscape.
                        const w = 720, h = 960, vw = video.videoWidth, vh = video.videoHeight;
                        const sw = Math.min(vw, vh * w / h), sh = Math.min(vh, vw * h / w);
                        const c = Object.assign(document.createElement('canvas'), {width: w, height: h});
                        const g = c.getContext('2d'); g.translate(w, 0); g.scale(-1, 1); g.drawImage(video, (vw - sw) / 2, (vh - sh) / 2, sw, sh, 0, 0, w, h);
                        c.toBlob(b => {
                            const dt = new DataTransfer(); dt.items.add(new File([b], 'selfie.jpg', {type: 'image/jpeg'}));
                            document.getElementById('foto').files = dt.files;
                            hasil.src = URL.createObjectURL(b); hasil.hidden = false; video.hidden = true;
                            stream?.getTracks().forEach(t => t.stop());
                            adaFoto = true; ambil.textContent = '↺ Ulangi selfie'; siap();
                        }, 'image/jpeg', 0.8);
                    });

                    // GPS akurasi tinggi; diperbarui terus sampai dikirim.
                    if (!navigator.geolocation) { status.textContent = '⚠️ HP ini tidak mendukung GPS.'; status.className = 'status-gps galat'; }
                    else navigator.geolocation.watchPosition(p => {
                        document.getElementById('lat').value = p.coords.latitude; document.getElementById('lng').value = p.coords.longitude;
                        document.getElementById('akurasi').value = Math.round(p.coords.accuracy);
                        status.textContent = `GPS ±${Math.round(p.coords.accuracy)} m · diperbarui ${new Date().toTimeString().slice(0, 8)}`;
                        status.className = 'status-gps redup';
                        // Cek ke server bila posisi pertama / bergeser ≥ 5 m / akurasi berubah banyak (hemat kuota).
                        const k = [p.coords.latitude, p.coords.longitude, p.coords.accuracy];
                        const geser = terakhir ? Math.hypot((k[0] - terakhir[0]) * 111320, (k[1] - terakhir[1]) * 111320 * Math.cos(k[0] * Math.PI / 180)) : Infinity;
                        if (geser >= 5 || Math.abs(k[2] - terakhir[2]) >= 15) { terakhir = k; cekArea(); }
                    }, e => { area.className = 'area luar'; area.textContent = '⚠️ Lokasi tidak bisa didapat — izinkan akses lokasi & nyalakan GPS.'; status.textContent = ''; adaGps = false; siap(); },
                    {enableHighAccuracy: true, maximumAge: 0, timeout: 30000});

                    document.getElementById('form-absen').addEventListener('submit', () => { kirim.disabled = true; kirim.textContent = 'Mengirim…'; });
                    nyalakan();
                })();
            </script>
        @endif
    @endif
@endsection
