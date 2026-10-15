@extends('layouts.app', ['judul' => $lokasi->exists ? 'Edit lokasi '.$lokasi->label() : 'Tambah lokasi absen'])

@section('lebar', '760px')

@section('isi')
    <style>
        .form-l label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .form-l input, .form-l select { width: 100%; padding: 8px 10px; border-radius: 7px; font-size: 14px; box-sizing: border-box; }
        .form-l .baris { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 12px; }
    </style>
    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ $lokasi->exists ? route('lokasi-absen.update', $lokasi) : route('lokasi-absen.store') }}" class="kartu form-l">
        @csrf
        @if ($lokasi->exists) @method('PUT') @endif
        <h3 style="margin: 0 0 14px;">{{ $lokasi->exists ? 'Edit lokasi absen' : 'Tambah lokasi absen' }}</h3>
        <div class="baris">
            <div><label for="proyek">Proyek (tahap di Daftar Proyek)</label>
                <input id="proyek" name="proyek" list="daftar-tahap" value="{{ old('proyek', $lokasi->tahap?->nama) }}" autocomplete="off" placeholder="Ketik / pilih, mis. ASG Tahap 116" required>
                <datalist id="daftar-tahap">@foreach ($tahapSaran as $t)<option value="{{ $t }}">@endforeach</datalist>
                <div id="galat-proyek" style="color: var(--merah); font-size: 12px; margin-top: 4px;" hidden></div></div>
            <div><label for="lokasi-proyek">Lokasi proyek <span style="font-size: 11px;">(otomatis dari Daftar Proyek)</span></label>
                <input id="lokasi-proyek" value="{{ $lokasi->tahap?->lokasi }}" readonly tabindex="-1" placeholder="— pilih proyek dulu —" style="opacity: .8;">
                <div id="info-lokasi" class="redup" style="font-size: 12px; margin-top: 4px;" hidden>Lokasi tahap ini belum diisi di Daftar Proyek — sementara ditampilkan nama tahapnya. Lengkapi kolom Lokasi di Daftar Proyek.</div></div>
        </div>
        <div class="baris">
            <div><label for="lat">Latitude</label><input id="lat" name="lat" value="{{ old('lat', $lokasi->lat) }}" inputmode="decimal" autocomplete="off" placeholder="-6.1234567"></div>
            <div><label for="lng">Longitude</label><input id="lng" name="lng" value="{{ old('lng', $lokasi->lng) }}" inputmode="decimal" autocomplete="off" placeholder="106.1234567"></div>
            <div><label for="radius_m">Radius (meter)</label><input id="radius_m" name="radius_m" value="{{ old('radius_m', $lokasi->radius_m) }}" inputmode="numeric"></div>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
            <button type="button" class="tombol polos" id="ambil-titik">📍 Ambil titik saya</button>
            <span class="redup" id="info-titik" style="font-size: 13px;">Tekan saat berada di lokasi proyek. Bisa juga menempel koordinat dari Google Maps ("-6.12, 106.45") ke kolom Latitude.</span>
            <a id="lihat-peta" href="#" target="_blank" rel="noopener" style="font-size: 13px;" hidden>Buka di Google Maps ↗</a>
        </div>
        <div id="peta-wadah" style="margin-bottom: 14px;" hidden>
            <iframe id="peta" title="Peta lokasi absen" style="width: 100%; height: 320px; border: 0; border-radius: 10px;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            <div class="redup" style="font-size: 12px; margin-top: 4px;">Pratinjau titik. Karyawan bisa absen dalam radius <b id="info-radius"></b> m dari titik ini.</div>
        </div>
        <label style="display: flex; gap: 8px; align-items: center; margin-bottom: 14px; color: var(--teks);"><input type="checkbox" name="aktif" value="1" style="width: auto;" @checked(old('aktif', $lokasi->aktif))> Lokasi aktif (karyawan bisa absen di sini)</label>
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="tombol">Simpan</button>
            <a href="{{ route('lokasi-absen.index') }}" class="tombol polos">Batal</a>
        </div>
    </form>
    <script>
        (() => {
            const lat = document.getElementById('lat'), lng = document.getElementById('lng'), info = document.getElementById('info-titik'), peta = document.getElementById('lihat-peta');
            // Peta tampil langsung di form begitu titik valid (tidak perlu membuka tab baru).
            const segar = () => {
                const a = parseFloat(lat.value), b = parseFloat(lng.value), ok = !isNaN(a) && !isNaN(b) && Math.abs(a) <= 90 && Math.abs(b) <= 180;
                peta.hidden = !ok; document.getElementById('peta-wadah').hidden = !ok;
                document.getElementById('info-radius').textContent = document.getElementById('radius_m').value || 100;
                if (!ok) return;
                peta.href = `https://www.google.com/maps?q=${a},${b}`;
                const src = `https://maps.google.com/maps?q=${a},${b}&z=17&output=embed`;
                const f = document.getElementById('peta'); if (f.src !== src) f.src = src;
            };
            document.getElementById('radius_m').addEventListener('input', segar);
            // Proyek harus persis nama tahap yang terdaftar.
            const TAHAP = @json($tahapSemua), proyek = document.getElementById('proyek'), galat = document.getElementById('galat-proyek'), simpan = document.querySelector('.form-l button[type=submit]');
            const cekProyek = () => {
                const v = proyek.value, ada = Object.hasOwn(TAHAP, v);
                // Lokasi diisi otomatis dari Daftar Proyek (tidak bisa diketik).
                document.getElementById('lokasi-proyek').value = ada ? (TAHAP[v] || v) : '';
                document.getElementById('info-lokasi').hidden = !ada || !!TAHAP[v];
                const mirip = !ada && v ? Object.keys(TAHAP).find(t => t.replace(/\s+/g, '').toLowerCase() === v.replace(/\s+/g, '').toLowerCase()) : null;
                galat.hidden = !v || ada;
                galat.textContent = mirip ? `Harus ditulis persis "${mirip}".` : 'Proyek tidak terdaftar di Daftar Proyek — pilih dari daftar.';
                proyek.style.borderColor = v && !ada ? 'var(--aksen)' : '';
                simpan.disabled = !ada;
            };
            proyek.addEventListener('input', cekProyek); cekProyek();
            // Tempel "lat, lng" dari Google Maps ke kolom Latitude → dipecah otomatis.
            lat.addEventListener('input', () => { const m = lat.value.match(/^\s*(-?\d+[.,]\d+)\s*[,;\s]\s*(-?\d+[.,]\d+)\s*$/); if (m) { lat.value = m[1].replace(',', '.'); lng.value = m[2].replace(',', '.'); } segar(); });
            lng.addEventListener('input', segar); segar();
            document.getElementById('ambil-titik').addEventListener('click', () => {
                info.textContent = 'Mencari lokasi GPS… (tunggu sampai akurasi bagus)';
                navigator.geolocation.getCurrentPosition(p => {
                    lat.value = p.coords.latitude.toFixed(7); lng.value = p.coords.longitude.toFixed(7); segar();
                    info.textContent = `Titik didapat (akurasi ±${Math.round(p.coords.accuracy)} m)` + (p.coords.accuracy > 30 ? ' — kurang akurat, coba lagi di tempat terbuka.' : '.');
                }, () => { info.textContent = 'Lokasi tidak bisa didapat — izinkan akses lokasi & nyalakan GPS.'; }, {enableHighAccuracy: true, maximumAge: 0, timeout: 30000});
            });
        })();
    </script>
@endsection
