@extends('layouts.app', ['judul' => 'Lapor Lapangan'])

@section('lebar', '600px')

@section('isi')
    <style>
        .lapor-atas { text-align: center; }
        .lapor-atas h2 { margin: 2px 0; }
        .jam { font-size: 30px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .status-hari { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin-top: 8px; }
        .kamera { position: relative; width: 100%; aspect-ratio: 3 / 4; background: #000; border-radius: 12px; overflow: hidden; }
        .kamera video { width: 100%; height: 100%; object-fit: cover; }
        .kamera video.depan { transform: scaleX(-1); }
        .kamera .lokasi { position: absolute; left: 8px; right: 8px; top: 8px; padding: 6px 10px; border-radius: 8px; background: rgba(0, 0, 0, .55); color: #fff; font-size: 13px; z-index: 1; }
        .kamera .kilat { position: absolute; inset: 0; background: #fff; opacity: 0; pointer-events: none; transition: opacity .25s; }
        .kamera .pesan-kamera { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; text-align: center; padding: 20px; color: #ddd; font-size: 14px; }
        .kendali { display: grid; grid-template-columns: 1fr 1.6fr 1fr; gap: 8px; margin-top: 8px; }
        .kendali button { padding: 12px 6px; font-size: 15px; border-radius: 10px; }
        #jepret { font-size: 18px; font-weight: 700; }
        .baki { display: grid; grid-template-columns: repeat(auto-fill, minmax(78px, 1fr)); gap: 6px; margin: 10px 0; }
        .baki .item { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; background: #111; }
        .baki img { width: 100%; height: 100%; object-fit: cover; }
        .baki .hapus { position: absolute; top: 2px; right: 2px; width: 24px; height: 24px; border-radius: 50%; border: none; background: rgba(0, 0, 0, .7); color: #fff; font-size: 14px; cursor: pointer; }
        .baki .tag { position: absolute; left: 2px; bottom: 2px; font-size: 10px; padding: 1px 5px; border-radius: 4px; background: rgba(0, 0, 0, .65); color: #fff; }
        textarea#keterangan { width: 100%; box-sizing: border-box; min-height: 64px; padding: 8px 10px; border-radius: 8px; font-size: 15px; }
        #kirim { width: 100%; padding: 14px; font-size: 17px; border-radius: 10px; margin-top: 8px; }
        .kemajuan { height: 6px; background: var(--garis); border-radius: 3px; overflow: hidden; margin-top: 8px; }
        .kemajuan span { display: block; height: 100%; width: 0; background: var(--sukses); transition: width .2s; }
        .riwayat-item { display: flex; gap: 10px; padding: 10px 14px; border-top: 1px solid var(--garis); }
        .riwayat-item .waktu { min-width: 62px; font-size: 13px; }
        .riwayat-item .foto-kecil { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; }
        .riwayat-item .foto-kecil img { width: 54px; height: 54px; object-fit: cover; border-radius: 6px; }
        .area { font-size: 13px; margin: 8px 0 0; }
    </style>

    @if (! $karyawan)
        <div class="kartu">
            <h3 style="margin-top: 0;">Akun belum terhubung</h3>
            <p>Akun Google <b>{{ auth()->user()->email }}</b> belum terdaftar sebagai karyawan aktif. Minta admin mengisi email ini di Data Karyawan Anda.</p>
        </div>
    @else
        <div class="kartu lapor-atas">
            <div class="redup">{{ now()->translatedFormat('l, j F Y') }}</div>
            <div class="jam" id="jam">{{ now()->format('H:i') }}</div>
            <h2>{{ $karyawan->nama }}</h2>
            <div class="redup">{{ $karyawan->site }}{{ $karyawan->jabatan ? ' · '.$karyawan->jabatan : '' }}</div>
            <div class="status-hari">
                @foreach (['masuk' => 'Masuk', 'pulang' => 'Pulang', 'lembur_selesai' => 'Selesai lembur'] as $j => $label)
                    <span @class(['label', 'hijau' => isset($absenHariIni[$j])])>{{ $label }} {{ isset($absenHariIni[$j]) ? $absenHariIni[$j]->waktu->format('H:i') : '–' }}</span>
                @endforeach
            </div>
        </div>

        <div class="kartu">
            <div class="kamera">
                <div class="lokasi" id="lokasi">📍 Mencari lokasi…</div>
                <video id="video" autoplay playsinline muted></video>
                <div class="pesan-kamera" id="pesan-kamera" hidden></div>
                <div class="kilat" id="kilat"></div>
            </div>
            <div class="kendali">
                <button type="button" class="tombol polos" id="balik" title="Ganti kamera depan / belakang">🔄 <span id="label-kamera">Belakang</span></button>
                <button type="button" class="tombol" id="jepret">📸 Foto</button>
                <button type="button" class="tombol polos" id="galeri-tombol" title="Pilih foto dari galeri HP">🖼 Galeri</button>
            </div>
            <input type="file" id="galeri" accept="image/*" multiple hidden>
            <div class="baki" id="baki"></div>
            <textarea id="keterangan" maxlength="2000" placeholder="Keterangan (opsional), mis. &quot;Absen masuk&quot;, &quot;Pemasangan U-ditch 20 m&quot;, &quot;Hujan, pekerjaan berhenti&quot;"></textarea>
            <button type="button" class="tombol" id="kirim" disabled>Kirim</button>
            <div class="kemajuan" id="kemajuan" hidden><span></span></div>
            <div class="area redup" id="status"></div>
            <p class="redup" style="font-size: 12px; margin: 8px 0 0;">Kirim saja semua foto seperti di grup WhatsApp: absen datang & pulang, pekerjaan, kejadian di lapangan. Waktu & lokasi dicatat otomatis; sistem yang memilah mana absen masuk, pulang, lembur dan laporan kerja.
                Foto dari galeri tetap tersimpan tetapi tidak dihitung sebagai bukti absen.</p>
        </div>

        @if ($riwayat->isNotEmpty())
            <div class="kartu" style="padding: 0;">
                <h4 style="margin: 0; padding: 12px 14px 6px;">Kiriman 7 hari terakhir</h4>
                @foreach ($riwayat as $l)
                    <div class="riwayat-item">
                        <div class="waktu"><b>{{ $l->waktu->format('H:i') }}</b><div class="redup">{{ $l->waktu->translatedFormat('D j M') }}</div></div>
                        <div style="flex: 1; min-width: 0;">
                            <span @class(['label', 'hijau' => in_array($l->jenisAkhir(), ['masuk', 'pulang'], true), 'kuning' => $l->jenisAkhir() === 'lembur'])>{{ \App\Models\Lapor::JENIS_ADMIN[$l->jenisAkhir()] ?? $l->jenisAkhir() }}</span>
                            <span class="redup" style="font-size: 12px;">{{ $l->di_lokasi ? ($l->tahap?->nama ?? $l->lokasi?->nama) :($l->alamat ?? ($l->lat ? 'lokasi tercatat' : 'tanpa GPS')) }}</span>
                            @if ($l->keterangan)<div style="font-size: 13px; margin-top: 2px;">{{ $l->keterangan }}</div>@endif
                            <div class="foto-kecil">@foreach ($l->foto as $f)<a href="{{ route('lapor.foto', $f) }}" target="_blank"><img src="{{ route('lapor.foto', ['foto' => $f, 'kecil' => 1]) }}" alt="foto" loading="lazy"></a>@endforeach</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <script src="{{ asset('js/kecilkan-foto.js') }}?v={{ filemtime(public_path('js/kecilkan-foto.js')) }}"></script>
        <script>
            (() => {
                const MAKS = @json($maks), TOKEN = @json(csrf_token());
                const video = document.getElementById('video'), baki = document.getElementById('baki'), kirim = document.getElementById('kirim');
                const status = document.getElementById('status'), lokasi = document.getElementById('lokasi'), pesanKamera = document.getElementById('pesan-kamera');
                // Absen masuk belum ada hari ini → mulai dari kamera depan (selfie); sesudahnya kamera belakang (foto pekerjaan).
                let arah = @json(isset($absenHariIni['masuk']) ? 'environment' : 'user');
                let stream = null, gps = null;
                const foto = []; // {file, url, sumber, kamera}

                setInterval(() => { document.getElementById('jam').textContent = new Date().toTimeString().slice(0, 5); }, 10000);

                const nyalakan = async () => {
                    stream?.getTracks().forEach(t => t.stop());
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: arah, width: {ideal: 1920}, height: {ideal: 1440}}, audio: false});
                        video.srcObject = stream; pesanKamera.hidden = true;
                        video.classList.toggle('depan', arah === 'user');
                        document.getElementById('label-kamera').textContent = arah === 'user' ? 'Depan' : 'Belakang';
                    } catch (e) {
                        pesanKamera.hidden = false;
                        pesanKamera.textContent = '⚠️ Kamera tidak bisa dibuka — izinkan akses kamera di browser, atau kirim foto dari Galeri.';
                    }
                };
                document.getElementById('balik').addEventListener('click', () => { arah = arah === 'user' ? 'environment' : 'user'; nyalakan(); });

                const gambar = () => {
                    baki.replaceChildren(...foto.map((f, i) => {
                        const d = document.createElement('div'); d.className = 'item';
                        d.innerHTML = `<img src="${f.url}" alt="foto"><span class="tag">${f.sumber === 'galeri' ? 'galeri' : (f.kamera === 'depan' ? 'depan' : 'belakang')}</span><button type="button" class="hapus" title="Hapus">×</button>`;
                        d.querySelector('.hapus').addEventListener('click', () => { URL.revokeObjectURL(f.url); foto.splice(i, 1); gambar(); });
                        return d;
                    }));
                    kirim.disabled = !foto.length;
                    kirim.textContent = foto.length ? `Kirim ${foto.length} foto` : 'Kirim';
                };
                const tambah = (file, sumber, kamera) => {
                    if (foto.length >= MAKS) { alert(`Maksimal ${MAKS} foto sekali kirim — kirim dulu, lalu lanjutkan.`); return; }
                    foto.push({file, url: URL.createObjectURL(file), sumber, kamera}); gambar();
                };

                document.getElementById('jepret').addEventListener('click', () => {
                    if (!video.videoWidth || video.readyState < 2) { status.textContent = '⏳ Kamera belum siap — coba lagi.'; return; }
                    const vw = video.videoWidth, vh = video.videoHeight, skala = Math.min(1, 2000 / Math.max(vw, vh));
                    const c = Object.assign(document.createElement('canvas'), {width: Math.round(vw * skala), height: Math.round(vh * skala)});
                    c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
                    const kilat = document.getElementById('kilat'); kilat.style.opacity = .7; setTimeout(() => kilat.style.opacity = 0, 120);
                    c.toBlob(b => tambah(new File([b], `kamera-${Date.now()}.jpg`, {type: 'image/jpeg'}), 'kamera', arah === 'user' ? 'depan' : 'belakang'), 'image/jpeg', 0.85);
                });
                const galeri = document.getElementById('galeri');
                document.getElementById('galeri-tombol').addEventListener('click', () => galeri.click());
                galeri.addEventListener('change', async () => {
                    for (const f of [...galeri.files]) tambah(await kecilkanFoto(f), 'galeri', null);
                    galeri.value = '';
                });

                // GPS: dicatat bersama kiriman; info lokasi proyek (tidak menghalangi kirim).
                let terakhir = null;
                const cek = async p => {
                    const d = new FormData(); d.append('_token', TOKEN); d.append('lat', p.latitude); d.append('lng', p.longitude); d.append('akurasi', Math.round(p.accuracy));
                    try {
                        const h = await (await fetch(@json(route('lapor.cek')), {method: 'POST', body: d, headers: {Accept: 'application/json'}})).json();
                        lokasi.textContent = (h.di_lokasi ? '✅ ' : '📍 ') + h.pesan + ` · GPS ±${Math.round(p.accuracy)} m`;
                    } catch { lokasi.textContent = `📍 GPS ±${Math.round(p.accuracy)} m`; }
                };
                if (navigator.geolocation) navigator.geolocation.watchPosition(p => {
                    gps = p.coords;
                    const geser = terakhir ? Math.hypot((gps.latitude - terakhir.latitude) * 111320, (gps.longitude - terakhir.longitude) * 111320) : Infinity;
                    if (geser >= 10 || Math.abs(gps.accuracy - terakhir.accuracy) >= 20) { terakhir = gps; cek(gps); }
                }, () => { lokasi.textContent = '⚠️ Lokasi tidak didapat — nyalakan GPS & izinkan lokasi (tetap bisa kirim).'; }, {enableHighAccuracy: true, maximumAge: 0, timeout: 30000});
                else lokasi.textContent = '⚠️ HP ini tidak mendukung GPS.';

                kirim.addEventListener('click', () => {
                    if (!foto.length) return;
                    const d = new FormData();
                    d.append('_token', TOKEN);
                    foto.forEach((f, i) => { d.append('foto[]', f.file, f.file.name || `foto-${i + 1}.jpg`); d.append('sumber[]', f.sumber); d.append('kamera[]', f.kamera || ''); });
                    d.append('keterangan', document.getElementById('keterangan').value);
                    if (gps) { d.append('lat', gps.latitude); d.append('lng', gps.longitude); d.append('akurasi', Math.round(gps.accuracy)); }
                    const xhr = new XMLHttpRequest(), bar = document.getElementById('kemajuan');
                    kirim.disabled = true; kirim.textContent = 'Mengirim…'; bar.hidden = false; status.textContent = '';
                    xhr.upload.onprogress = e => { if (e.lengthComputable) bar.firstElementChild.style.width = Math.round(e.loaded / e.total * 100) + '%'; };
                    xhr.onload = () => {
                        let h = {}; try { h = JSON.parse(xhr.responseText); } catch {}
                        if (xhr.status >= 200 && xhr.status < 300 && h.ok) {
                            status.className = 'area'; status.style.color = 'var(--sukses)'; status.textContent = '✅ ' + h.pesan;
                            foto.splice(0).forEach(f => URL.revokeObjectURL(f.url)); gambar(); document.getElementById('keterangan').value = '';
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            status.style.color = 'var(--merah)';
                            status.textContent = '⚠️ ' + ((h.galat || Object.values(h.errors || {}).flat()).join(' ') || h.message || `Gagal mengirim (kode ${xhr.status}). Coba lagi.`);
                            kirim.disabled = false; kirim.textContent = `Kirim ${foto.length} foto`;
                        }
                        bar.hidden = true; bar.firstElementChild.style.width = 0;
                    };
                    xhr.onerror = () => { status.style.color = 'var(--merah)'; status.textContent = '⚠️ Koneksi terputus — foto masih di sini, coba kirim lagi.'; kirim.disabled = false; kirim.textContent = `Kirim ${foto.length} foto`; bar.hidden = true; };
                    xhr.open('POST', @json(route('lapor.simpan'))); xhr.setRequestHeader('Accept', 'application/json'); xhr.send(d);
                });
                nyalakan();
            })();
        </script>
    @endif
@endsection
