@extends('layouts.app', ['judul' => 'Input BBM & HM'])

@section('lebar', '1900px')

@section('isi')
    @php
        $edit ??= null;
        $awal = old('baris', $edit['isi'] ?? array_fill(0, 3, []));
        $jenisLabel = ['pakai' => 'Pemakaian alat', 'masuk' => 'Solar masuk', 'pindah' => 'Pindah lokasi', 'susut' => 'Susut', 'operasional' => 'Kendaraan operasional'];
    @endphp
    <style>
        .form-bbm input[type=text] { width: 100%; padding: 7px 7px; border-radius: 6px; font-size: 13px; box-sizing: border-box; }
        .form-bbm input.angka-input { text-align: right; font-variant-numeric: tabular-nums; }
        .form-bbm input.otomatis { background: var(--otomatis-latar); border-color: var(--otomatis-garis); }
        .form-bbm input.salah { border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen-muda); }
        .form-bbm input:disabled { opacity: .35; }
        table.isian { border-collapse: collapse; min-width: 1820px; }
        table.isian th { font-size: 12px; padding: 4px 3px; text-align: left; color: var(--redup); font-weight: 600; }
        table.isian td { padding: 3px; vertical-align: top; }
        table.isian select { width: 100%; padding: 6px 4px; border-radius: 6px; font-size: 13px; }
        table.isian select.salah { border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen-muda); }
        table.isian td.jam-kerja { text-align: right; padding-top: 10px; font-variant-numeric: tabular-nums; white-space: nowrap; color: var(--teks); }
        table.isian td.total { text-align: right; padding-top: 10px; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .hapus { background: none; border: 0; color: var(--merah); cursor: pointer; font-size: 18px; line-height: 1; padding: 6px 4px; }
        .catatan { padding: 0 3px 10px; }
        .kurang { border: 1px solid rgba(255, 92, 97, .35); border-left: 4px solid var(--aksen); background: var(--merah-muda); color: var(--merah);
            border-radius: 8px; padding: 6px 12px; font-size: 13px; }
        .kurang ul { margin: 2px 0 0; padding-left: 18px; }
        .info-baris { border: 1px solid #6b4f10; border-left: 4px solid #e0a526; background: #1d1810; color: #f0c05a; border-radius: 8px; padding: 5px 12px; font-size: 12px; margin-top: 4px; }
        tr.baris.valid td:first-child { box-shadow: inset 4px 0 0 var(--sukses); }
        #hasil-validasi ul { margin: 6px 0 0; padding-left: 18px; }
    </style>

    <form method="POST" action="{{ $edit ? route('bbm.update', $edit['baris']) : route('bbm.store') }}" class="form-bbm" id="form-bbm">
        @csrf
        @if ($edit)
            @method('PUT')
            <input type="hidden" name="versi" value="{{ $edit['versi'] }}">
        @endif
        @if ($errors->any())
            <div class="pesan galat"><b>Periksa lagi isian berikut:</b>
                <ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif

        <div class="kartu">
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px;">
                <h3 style="margin: 0 0 4px;">@if ($edit) Edit BBM & HM <span class="redup" style="font-size: 13px; font-weight: normal;">sheet Penggunaan BBM baris {{ $edit['baris'] }}</span> @else Input BBM & HM @endif</h3>
                <span class="redup">keluar <b id="jml-keluar">0</b> L · masuk <b id="jml-masuk">0</b> L · <b id="jml-baris">0</b> baris</span>
            </div>
            <p class="redup" style="margin: 0 0 12px; font-size: 13px;">Pilih <b style="color: var(--teks);">Jenis</b> dulu di tiap baris. <b style="color: var(--teks);">Setiap baris harus berisi transaksi</b> (liter Keluar/Masuk, atau HM untuk alat yang bekerja tanpa isi solar).
                <b>Pemakaian alat</b>: Unit = nama di Data Aset, isi HM & Keluar (liter) + Proyek — <b>alat berat yang diisi solar wajib HM awal & akhir</b>. <b>Solar masuk</b>: Unit bebas diketik (sumber solarnya, mis. "Isi Solar dari Luar", "Isi Solar dari DT 035"); tulis No DO di Keterangan bila ada.
                Unit (selain solar masuk), Proyek & Status harus persis sama dengan data master — beda huruf besar/kecil atau spasi pun ditolak. Total jam kerja dihitung otomatis dari jam Mulai–Selesai.
                HM awal & proyek terisi otomatis dari catatan terakhir unit itu (ungu = otomatis, boleh diganti). Stok dihitung otomatis di sheet.</p>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
                <label style="font-size: 13px; color: var(--redup);">Tanggal</label>
                <input type="text" name="tanggal" data-tanggal-id data-maks="{{ now()->toDateString() }}" value="{{ old('tanggal', $edit['tanggal'] ?? now()->toDateString()) }}" style="width: 150px;">
                @if (! $edit && $unitTerakhir->isNotEmpty())
                    <button type="button" class="tombol polos" id="muat-terakhir" style="padding: 6px 12px;"
                        title="{{ $unitTerakhir->implode(', ') }}">↻ Muat {{ $unitTerakhir->count() }} unit hari terakhir ({{ \Illuminate\Support\Carbon::parse($hariTerakhir)->translatedFormat('j M Y') }})</button>
                @endif
            </div>

            <div class="gulir">
                <table class="isian" id="tabel">
                    <thead><tr>
                        <th style="width: 150px;">Jenis</th><th style="width: 210px;">Unit</th><th style="width: 80px;">HM awal</th><th style="width: 80px;">HM akhir</th><th style="width: 64px;">Total HM</th>
                        <th style="width: 76px;">Masuk (L)</th><th style="width: 76px;">Keluar (L)</th><th style="width: 62px;">Mulai</th><th style="width: 62px;">Selesai</th>
                        <th style="width: 92px;" title="Selesai − Mulai (otomatis)">Total jam kerja</th>
                        <th style="width: 104px;">Status</th><th style="width: 230px;">Proyek</th><th style="width: 200px;">Keterangan / No DO</th>@if (! $edit)<th></th>@endif
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            @if (! $edit)
                <div style="margin-top: 8px; display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="tombol polos" id="tambah" style="padding: 6px 12px;">+ Tambah baris</button>
                    <span class="redup" style="font-size: 12px;">Baris yang dibiarkan kosong — atau hanya berisi Jenis, Unit & isian otomatis (ungu) — tidak ikut disimpan.</span>
                </div>
            @endif
            <div id="hasil-validasi" class="pesan" style="margin: 14px 0 0;" hidden></div>
            <div style="margin-top: 14px; display: flex; gap: 10px;">
                <button type="submit" class="tombol" id="simpan">{{ $edit ? 'Simpan perubahan' : 'Simpan ke sheet' }}</button>
                <a href="{{ route('bbm.index') }}" class="tombol polos">Batal</a>
            </div>
        </div>
    </form>

    @foreach (array_keys($jenisLabel) as $j)
        <datalist id="daftar-unit-{{ $j }}">@foreach ($unit as $n => $u)@if ($u['jenis'] === $j)<option value="{{ $n }}"></option>@endif @endforeach</datalist>
    @endforeach
    <datalist id="daftar-tahap">@foreach ($tahap as $t)<option value="{{ $t }}"></option>@endforeach</datalist>
    <datalist id="daftar-status">@foreach ($status as $s)<option value="{{ $s }}"></option>@endforeach</datalist>

    <script>
        (() => {
            const UNIT = @json($unit);
            const JENIS = @json($jenisLabel);
            const WAJIB_HM_SEJAK = @json(\App\Support\LembarBbm::WAJIB_HM_SEJAK);
            // Isian ber-master harus PERSIS sama (huruf besar/kecil & spasi) — beda sedikit pun ditandai dan tidak bisa disimpan.
            // Unit dicocokkan ke master JENIS yang dipilih; jenis Solar masuk boleh diketik bebas.
            const unitJenis = j => Object.keys(UNIT).filter(n => UNIT[n].jenis === j);
            const MASTER = {proyek: @json($tahapSemua), status: @json($status)};
            const LABEL = {unit: 'Unit', proyek: 'Proyek', status: 'Status'};
            const kk = x => String(x).replace(/\s+/g, '').toLowerCase();
            const bedaMaster = (k, v, daftar = MASTER[k]) => {
                if (v === '' || daftar.includes(v)) return null;
                const mirip = daftar.find(d => kk(d) === kk(v));
                return mirip !== undefined ? `${LABEL[k]} "${v}" harus ditulis persis "${mirip}" (beda huruf besar/kecil atau spasi).`
                    : `${LABEL[k]} "${v}" tidak ada di data master — pilih dari daftar.`;
            };
            // Total jam kerja = Selesai − Mulai − Rest (lewat tengah malam = hari berikutnya). Hasil dalam menit, null bila tidak lengkap.
            const menit = v => { const m = String(v || '').trim().match(/^(\d{1,2})[:.](\d{2})$/); return m ? (+m[1]) * 60 + (+m[2]) : null; };
            const jamKerja = (mulai, rest, selesai) => {
                const a = menit(mulai), b = menit(selesai);
                if (a === null || b === null) return null;
                let t = b - a; if (t < 0) t += 1440;
                const r = menit(rest); if (r !== null) t -= r;
                return t;
            };
            const teksJam = t => t === null ? '' : `${Math.floor(Math.max(t, 0) / 60)} jam${t % 60 ? ' ' + (t % 60) + ' mnt' : ''}`;
            const TERAKHIR = @json($unitTerakhir);
            const AWAL = @json(array_values($awal));
            const EDIT = {{ $edit ? 'true' : 'false' }};
            const KOLOM = ['unit', 'hm_awal', 'hm_akhir', 'total_hm', 'masuk', 'keluar', 'jam_mulai', 'jam_selesai', 'status', 'keterangan', 'proyek'];
            const tbody = document.querySelector('#tabel tbody');
            const kunci = v => String(v || '').trim().replace(/\s+/g, ' ').toLowerCase();
            const PETA = Object.fromEntries(Object.keys(UNIT).map(n => [kunci(n), n]));
            const angka = v => { v = String(v || '').trim().replace(/\s/g, ''); if (!v) return null; v = v.replace(',', '.'); return isNaN(+v) ? NaN : +v; };
            const fmt = n => (Math.round(n * 100) / 100).toLocaleString('id-ID');
            let urut = 0;

            const isian = (nama, i, nilai, attr = '') => `<input type="text" data-k="${nama}" name="baris[${i}][${nama}]" value="${String(nilai ?? '').replace(/"/g, '&quot;')}" autocomplete="off" ${attr}>`;

            const tambah = (isi = {}) => {
                const i = urut++;
                const tr = document.createElement('tr');
                tr.className = 'baris';
                tr.innerHTML = `
                    <td><select data-k="jenis" name="baris[${i}][jenis]"><option value="">— pilih jenis —</option>${Object.entries(JENIS).map(([k, l]) => `<option value="${k}"${isi.jenis === k ? ' selected' : ''}>${l}</option>`).join('')}</select></td>
                    <td>${isian('unit', i, isi.unit, 'placeholder="Unit…"')}</td>
                    <td>${isian('hm_awal', i, isi.hm_awal, 'class="angka-input" inputmode="decimal"')}</td>
                    <td>${isian('hm_akhir', i, isi.hm_akhir, 'class="angka-input" inputmode="decimal"')}</td>
                    <td class="total">${isian('total_hm', i, isi.total_hm, 'class="angka-input" inputmode="decimal" title="Diisi bila HM awal/akhir tidak ada"')}</td>
                    <td>${isian('masuk', i, isi.masuk, 'class="angka-input" inputmode="decimal"')}</td>
                    <td>${isian('keluar', i, isi.keluar, 'class="angka-input" inputmode="decimal"')}</td>
                    <td>${isian('jam_mulai', i, isi.jam_mulai, 'placeholder="8:00"')}</td>
                    <td>${isian('jam_selesai', i, isi.jam_selesai, 'placeholder="17:00"')}</td>
                    <td class="jam-kerja"></td>
                    <td>${isian('status', i, isi.status, 'list="daftar-status"')}</td>
                    <td>${isian('proyek', i, isi.proyek, 'list="daftar-tahap" placeholder="Tahap / Pool"')}</td>
                    <td>${isian('keterangan', i, isi.keterangan, 'maxlength="300" placeholder="mis. Nomor DO 15704"')}</td>
                    ${EDIT ? '' : '<td><button type="button" class="hapus" title="Hapus baris">×</button></td>'}`;
                tbody.appendChild(tr);
                const cat = document.createElement('tr');
                cat.className = 'catatan-baris';
                cat.innerHTML = `<td colspan="16" class="catatan" hidden></td>`;
                tbody.appendChild(cat);
                perbarui(tr, false);
                return tr;
            };

            const sel = (tr, k) => tr.querySelector(`[data-k="${k}"]`);
            const perbarui = (tr, isiOtomatis = true) => {
                // Jenis dipilih admin; daftar saran Unit mengikuti jenisnya. Unit hanya dikenali bila persis sama (tidak dibetulkan otomatis).
                const jenis = sel(tr, 'jenis').value;
                sel(tr, 'unit').setAttribute('list', jenis ? 'daftar-unit-' + jenis : '');
                const u = jenis && UNIT[sel(tr, 'unit').value]?.jenis === jenis ? UNIT[sel(tr, 'unit').value] : null;
                // Kolom yang tidak berlaku untuk jenisnya dimatikan (solar masuk hanya di baris sumber, bukan di baris alat).
                const alat = jenis === 'pakai';
                // Yang sudah terisi tetap bisa disunting (supaya isian yang salah bisa dihapus).
                const matikan = (k, mati) => { const x = sel(tr, k); x.disabled = mati && !x.value.trim(); };
                matikan('masuk', !!jenis && jenis !== 'masuk');
                matikan('keluar', jenis === 'masuk');
                ['hm_awal', 'hm_akhir', 'total_hm', 'jam_mulai', 'jam_selesai'].forEach(k => matikan(k, !!jenis && !alat && jenis !== 'operasional'));
                if (isiOtomatis && alat && u) {
                    const hm = sel(tr, 'hm_awal');
                    if (!hm.value && u.hm != null) { hm.value = String(u.hm).replace('.', ','); hm.classList.add('otomatis'); }
                    const p = sel(tr, 'proyek');
                    if (!p.value && u.proyek) { p.value = u.proyek; p.classList.add('otomatis'); }
                }
                hitung(tr);
            };

            // Validasi per baris — aturannya sama dengan pemeriksaan server (BbmController::rapikan). Galat (merah) memblokir
            // tombol Simpan; info (kuning) hanya pengingat. Baris yang kosong seluruhnya diabaikan.
            const ANGKA = {hm_awal: 'HM awal', hm_akhir: 'HM akhir', total_hm: 'Total HM', masuk: 'Masuk', keluar: 'Keluar'};
            const JAM = {jam_mulai: 'Jam mulai', jam_selesai: 'Jam selesai'};
            const pola = /^\d{1,2}[:.]\d{2}$/;
            const periksaBaris = tr => {
                const galat = [], info = [], salahDi = new Set();
                const v = k => sel(tr, k).value;
                // Baris kosong = tidak ada isian selain Unit & isian otomatis (ungu: HM awal / proyek dari catatan terakhir) —
                // mis. unit dari "Muat unit hari terakhir" yang hari itu tidak dipakai. Diabaikan & tidak ikut terkirim.
                if (!KOLOM.some(k => k !== 'unit' && v(k).trim() && !sel(tr, k).classList.contains('otomatis'))) return {kosong: true, galat, info, salahDi};
                const unit = v('unit'), jenis = v('jenis');
                const u = UNIT[unit] && UNIT[unit].jenis === jenis ? UNIT[unit] : null;
                if (!jenis) { galat.push('Pilih Jenis dulu.'); salahDi.add('jenis'); }
                if (!unit.trim()) { galat.push('Unit belum diisi.'); salahDi.add('unit'); }
                else if (kk(unit) === kk('Stock Opname')) { galat.push('Stock opname dicatat lewat tombol Stock Opname di menu Data BBM.'); salahDi.add('unit'); }
                else if (jenis === 'masuk') {
                    // Solar masuk: nama sumber bebas, tetapi tidak boleh nama aset / unit jenis lain (supaya stok & biaya tidak tertukar).
                    const lain = Object.keys(UNIT).find(n => kk(n) === kk(unit) && UNIT[n].jenis !== 'masuk');
                    if (lain) { galat.push(`"${unit}" adalah ${JENIS[UNIT[lain].jenis] || UNIT[lain].jenis}, bukan sumber solar — tulis sumbernya, mis. "Isi Solar dari ${lain}".`); salahDi.add('unit'); }
                    else if (unit !== unit.trim() || /\s{2,}/.test(unit)) { galat.push('Unit tidak boleh diawali/diakhiri spasi atau berisi spasi ganda.'); salahDi.add('unit'); }
                } else if (jenis) {
                    const p = bedaMaster('unit', unit, unitJenis(jenis));
                    if (p) {
                        const jenisLain = UNIT[unit]?.jenis;
                        galat.push(jenisLain && jenisLain !== jenis ? `Unit "${unit}" berjenis ${JENIS[jenisLain]}, bukan ${JENIS[jenis]} — ganti Jenis-nya.` : p);
                        salahDi.add(jenisLain && jenisLain !== jenis ? 'jenis' : 'unit');
                    }
                }
                ['proyek', 'status'].forEach(k => { const p = bedaMaster(k, v(k)); if (p) { galat.push(p); salahDi.add(k); } });
                const n = {};
                Object.entries(ANGKA).forEach(([k, label]) => {
                    n[k] = angka(v(k));
                    if (Number.isNaN(n[k])) { galat.push(`${label} harus angka (mis. 8786,9) — sekarang "${v(k).trim()}".`); salahDi.add(k); n[k] = null; }
                    else if (n[k] !== null && n[k] < 0) { galat.push(`${label} tidak boleh minus.`); salahDi.add(k); }
                });
                Object.entries(JAM).forEach(([k, label]) => {
                    if (v(k).trim() && !pola.test(v(k).trim())) { galat.push(`${label} harus format jam, mis. 8:00 — sekarang "${v(k).trim()}".`); salahDi.add(k); }
                });
                if (n.hm_awal !== null && n.hm_akhir !== null) {
                    if (n.hm_akhir < n.hm_awal) { galat.push('HM akhir lebih kecil dari HM awal.'); salahDi.add('hm_akhir'); }
                    else if (n.hm_akhir - n.hm_awal > 24) { galat.push('Total HM lebih dari 24 jam — periksa HM awal/akhir.'); salahDi.add('hm_akhir'); }
                }
                if (jenis === 'pakai') {
                    if (n.masuk) { galat.push('Solar masuk tidak dicatat di baris alat — kosongkan Masuk, buat baris sendiri dengan unit sumber solar (mis. "Isi Solar dari Luar").'); salahDi.add('masuk'); }
                    // Baris alat harus berisi transaksi: liter Keluar atau jam HM (> 0).
                    const hm = n.hm_awal !== null && n.hm_akhir !== null ? n.hm_akhir - n.hm_awal : n.total_hm;
                    if (!n.keluar && !(hm > 0)) { galat.push('Isi liter Keluar — atau HM awal & akhir bila alat bekerja tanpa isi solar. Baris tanpa transaksi tidak bisa disimpan.'); salahDi.add('keluar'); }
                    else if (!v('proyek').trim()) { galat.push('Pemakaian solar / jam kerja alat wajib diisi Proyek.'); salahDi.add('proyek'); }
                    // Alat berat yang diisi solar wajib disertai HM awal & akhir (sejak WAJIB_HM_SEJAK; baris lama yang diedit tidak terkena).
                    const tgl = window.TanggalId ? TanggalId.urai(document.querySelector('[name=tanggal]').value) : null;
                    const iso = tgl ? `${tgl.getFullYear()}-${String(tgl.getMonth() + 1).padStart(2, '0')}-${String(tgl.getDate()).padStart(2, '0')}` : '9999';
                    if (u?.berat && n.keluar > 0 && (n.hm_awal === null || n.hm_akhir === null) && iso >= WAJIB_HM_SEJAK) {
                        galat.push('Alat berat yang diisi solar wajib diisi HM awal & HM akhir hari itu (dasar rasio liter/jam).');
                        if (n.hm_awal === null) salahDi.add('hm_awal'); if (n.hm_akhir === null) salahDi.add('hm_akhir');
                    }
                } else if (jenis === 'masuk') {
                    if (!n.masuk) { galat.push('Isi jumlah liter di kolom Masuk.'); salahDi.add('masuk'); }
                    if (n.keluar) { galat.push('Baris solar masuk tidak boleh berisi Keluar — kosongkan Keluar.'); salahDi.add('keluar'); }
                } else if (['pindah', 'susut', 'operasional'].includes(jenis)) {
                    if (!n.keluar) { galat.push('Isi jumlah liter di kolom Keluar.'); salahDi.add('keluar'); }
                    if (n.masuk) { galat.push('Baris ini tidak boleh berisi Masuk — kosongkan Masuk.'); salahDi.add('masuk'); }
                }
                const tj = jamKerja(v('jam_mulai'), '', v('jam_selesai'));
                if (tj !== null && tj <= 0) { galat.push('Total jam kerja 0 — periksa jam Mulai & Selesai.'); salahDi.add('jam_selesai'); }
                if (jenis === 'pakai' && u && u.hm != null && n.hm_awal !== null && Math.abs(n.hm_awal - u.hm) > 0.05)
                    info.push(`HM awal berbeda dari HM akhir terakhir unit ini (${String(u.hm).replace('.', ',')}).`);
                return {kosong: false, galat, info, salahDi};
            };

            const hitung = tr => {
                const a = angka(sel(tr, 'hm_awal').value), b = angka(sel(tr, 'hm_akhir').value);
                const t = sel(tr, 'total_hm');
                if (a != null && b != null && !isNaN(a) && !isNaN(b)) {
                    t.value = String(Math.round((b - a) * 100) / 100).replace('.', ',');
                    t.readOnly = true; t.classList.add('otomatis');
                } else if (t.readOnly) { t.value = ''; t.readOnly = false; t.classList.remove('otomatis'); }
                tr.querySelector('td.jam-kerja').textContent = teksJam(jamKerja(sel(tr, 'jam_mulai').value, '', sel(tr, 'jam_selesai').value));
                const h = periksaBaris(tr);
                tr.hasil = h;
                [...KOLOM, 'jenis'].forEach(k => sel(tr, k).classList.toggle('salah', h.salahDi.has(k)));
                tr.classList.toggle('ada-salah', h.galat.length > 0);
                tr.classList.toggle('valid', !h.kosong && !h.galat.length);
                const no = [...tbody.querySelectorAll('tr.baris')].indexOf(tr) + 1;
                const c = tr.nextElementSibling.querySelector('.catatan');
                c.hidden = !h.galat.length && !h.info.length;
                c.innerHTML = (h.galat.length ? `<div class="kurang">✎ Baris ${no}${sel(tr, 'unit').value.trim() ? ' (' + esc(sel(tr, 'unit').value) + ')' : ''}:<ul>${h.galat.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>` : '')
                    + h.info.map(x => `<div class="info-baris">ⓘ ${esc(x)}</div>`).join('');
                ringkas();
                evaluasi();
            };
            const esc = s => String(s).replace(/[&<>"]/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[ch]);

            // Tombol Simpan hanya menyala bila tanggal benar, ada minimal satu baris terisi, dan TIDAK ada baris bergalat.
            const tombol = document.getElementById('simpan');
            const kotak = document.getElementById('hasil-validasi');
            const teksSimpan = tombol.textContent;
            const evaluasi = () => {
                if (!kotak) return;
                const masalah = [];
                const tgl = document.querySelector('[name=tanggal]').value.trim();
                const d = window.TanggalId ? TanggalId.urai(tgl) : (tgl ? new Date() : null);
                if (!d) masalah.push(tgl ? `Tanggal "${tgl}" tidak dikenali (mis. 14-Okt-2026).` : 'Tanggal belum diisi.');
                else if (d > new Date()) masalah.push('Tanggal tidak boleh lewat dari hari ini.');
                const semua = [...tbody.querySelectorAll('tr.baris')];
                const terisi = semua.filter(tr => tr.hasil && !tr.hasil.kosong);
                semua.forEach((tr, i) => { if (tr.hasil?.galat.length) masalah.push(`Baris ${i + 1}${sel(tr, 'unit').value.trim() ? ' (' + sel(tr, 'unit').value + ')' : ''}: ${tr.hasil.galat.join(' ')}`); });
                if (!terisi.length) masalah.push('Isi minimal satu baris.');
                tombol.disabled = masalah.length > 0;
                tombol.title = masalah.length ? 'Perbaiki dulu isian yang ditandai merah' : '';
                kotak.hidden = false;
                if (masalah.length) {
                    kotak.className = 'pesan galat';
                    kotak.innerHTML = `<b>Simpan belum aktif — perbaiki dulu:</b><ul>${masalah.slice(0, 12).map(x => `<li>${esc(x)}</li>`).join('')}${masalah.length > 12 ? `<li>… dan ${masalah.length - 12} lagi</li>` : ''}</ul>`;
                } else {
                    kotak.className = 'pesan sukses';
                    kotak.textContent = `✓ ${terisi.length} baris valid — siap disimpan.`;
                }
            };

            const ringkas = () => {
                let k = 0, m = 0, n = 0;
                tbody.querySelectorAll('tr.baris').forEach(tr => {
                    const kel = angka(sel(tr, 'keluar').value) || 0, mas = angka(sel(tr, 'masuk').value) || 0;
                    k += kel; m += mas; if (sel(tr, 'unit').value.trim()) n++;
                });
                document.getElementById('jml-keluar').textContent = fmt(k);
                document.getElementById('jml-masuk').textContent = fmt(m);
                document.getElementById('jml-baris').textContent = n;
            };

            tbody.addEventListener('input', e => {
                const tr = e.target.closest('tr.baris');
                if (!tr) return;
                e.target.classList.remove('otomatis');
                if (e.target.dataset.k === 'unit') perbarui(tr, false); else hitung(tr);
            });
            tbody.addEventListener('change', e => {
                const tr = e.target.closest('tr.baris');
                if (tr && (e.target.dataset.k === 'unit' || e.target.dataset.k === 'jenis')) perbarui(tr, true);
            });
            tbody.addEventListener('click', e => {
                if (!e.target.classList.contains('hapus')) return;
                const tr = e.target.closest('tr');
                tr.nextElementSibling.remove(); tr.remove(); tbody.querySelectorAll('tr.baris').forEach(hitung); ringkas(); evaluasi();
            });
            document.getElementById('tambah')?.addEventListener('click', () => sel(tambah(), 'unit').focus());
            document.getElementById('muat-terakhir')?.addEventListener('click', () => {
                // Baris kosong dibuang, lalu satu baris per unit yang tercatat di hari terakhir (HM awal & proyek otomatis).
                tbody.querySelectorAll('tr.baris').forEach(tr => { if (!KOLOM.some(k => sel(tr, k).value.trim())) { tr.nextElementSibling.remove(); tr.remove(); } });
                const ada = new Set([...tbody.querySelectorAll('[data-k="unit"]')].map(x => kunci(x.value)));
                TERAKHIR.forEach(n => { if (!ada.has(kunci(n))) { const tr = tambah({jenis: 'pakai', unit: PETA[kunci(n)] || n}); perbarui(tr, true); } });
            });
            // Tanggal berubah → semua baris diperiksa ulang (aturan wajib HM alat berat bergantung tanggal).
            const cekSemua = () => { tbody.querySelectorAll('tr.baris').forEach(hitung); evaluasi(); };
            document.querySelector('[name=tanggal]').addEventListener('input', cekSemua);
            document.querySelector('[name=tanggal]').addEventListener('change', cekSemua);
            document.querySelector('[name=tanggal]').addEventListener('blur', () => setTimeout(cekSemua));
            window.addEventListener('load', evaluasi); // tanggal-id.js termuat sesudah skrip ini
            document.getElementById('form-bbm').addEventListener('submit', e => {
                tbody.querySelectorAll('tr.baris').forEach(tr => hitung(tr));
                if (tombol.disabled) {
                    e.preventDefault();
                    (tbody.querySelector('input.salah') || kotak).scrollIntoView({block: 'center'});
                    tbody.querySelector('input.salah')?.focus();
                    return;
                }
                tbody.querySelectorAll('input:disabled').forEach(x => { x.disabled = false; });
                // Baris kosong (hanya unit / isian otomatis) tidak dikirim.
                tbody.querySelectorAll('tr.baris').forEach(tr => { if (tr.hasil?.kosong) tr.querySelectorAll('input, select').forEach(x => { x.disabled = true; }); });
                const b = document.getElementById('simpan');
                b.disabled = true; b.textContent = 'Menyimpan…';
            });

            AWAL.forEach(isi => tambah(isi));
            if (!AWAL.length) tambah();
        })();
    </script>
    <script src="{{ asset('js/tanggal-id.js') }}"></script>
@endsection
