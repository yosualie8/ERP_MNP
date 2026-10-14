@extends('layouts.app', ['judul' => $lokasi->exists ? 'Edit lokasi '.$lokasi->nama : 'Tambah lokasi absen'])

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
            <div><label for="nama">Nama lokasi</label><input id="nama" name="nama" value="{{ old('nama', $lokasi->nama) }}" required placeholder="mis. ASG Tahap 116 / Pool ASG" autocomplete="off"></div>
            <div><label for="proyek_id">Proyek</label>
                <select id="proyek_id" name="proyek_id"><option value="">— tanpa proyek —</option>
                    @foreach ($proyek as $p)<option value="{{ $p->id }}" @selected((string) old('proyek_id', $lokasi->proyek_id) === (string) $p->id)>{{ $p->nama }}</option>@endforeach</select></div>
        </div>
        <div class="baris">
            <div><label for="lat">Latitude</label><input id="lat" name="lat" value="{{ old('lat', $lokasi->lat) }}" inputmode="decimal" autocomplete="off" placeholder="-6.1234567"></div>
            <div><label for="lng">Longitude</label><input id="lng" name="lng" value="{{ old('lng', $lokasi->lng) }}" inputmode="decimal" autocomplete="off" placeholder="106.1234567"></div>
            <div><label for="radius_m">Radius (meter)</label><input id="radius_m" name="radius_m" value="{{ old('radius_m', $lokasi->radius_m) }}" inputmode="numeric"></div>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
            <button type="button" class="tombol polos" id="ambil-titik">📍 Ambil titik saya</button>
            <span class="redup" id="info-titik" style="font-size: 13px;">Tekan saat berada di lokasi proyek. Bisa juga menempel koordinat dari Google Maps ("-6.12, 106.45") ke kolom Latitude.</span>
            <a id="lihat-peta" href="#" target="_blank" rel="noopener" style="font-size: 13px;">Lihat di Google Maps</a>
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
            const segar = () => { peta.href = lat.value && lng.value ? `https://www.google.com/maps?q=${lat.value},${lng.value}` : '#'; };
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
