@extends('layouts.app', ['judul' => 'Input Ritasi'])

@section('lebar', '1500px')

@section('isi')
    <style>
        .form-kas label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .form-kas input[type=text], .form-kas input[type=date] { width: 100%; padding: 8px 10px; border-radius: 7px; font-size: 14px; }
        .form-kas input[type=text]:focus, .form-kas input[type=date]:focus { outline: none; border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen); }
        .form-kas input.angka-input { text-align: right; font-variant-numeric: tabular-nums; }
        .form-kas input.otomatis { background: var(--otomatis-latar); border-color: var(--otomatis-garis); }
        .kepala { display: grid; grid-template-columns: 2fr 160px 1.3fr 1.2fr 1fr; gap: 12px; }
        table.bon td { padding: 3px; vertical-align: top; border-bottom: 0; }
        table.bon th { padding: 4px 3px; font-size: 12px; }
        table.bon input[data-nama] { padding: 7px 7px; font-size: 13px; border-color: var(--isian-garis); }
        table.bon input[data-nama]:focus { background: var(--isian-fokus); }
        .hapus { background: none; border: 0; color: var(--merah); cursor: pointer; font-size: 18px; line-height: 1; padding: 7px 4px; }
        .tambah-detail { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 6px; }
        .tambah-detail label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--teks); margin: 0; }
        .tambah-detail #jumlah-detail { width: 56px; text-align: center; padding: 6px; border-radius: 6px; font-size: 14px; }
        .tambah-detail .redup { font-size: 12px; }
        .form-kas input.wajib-kosong { border-color: var(--aksen) !important; box-shadow: 0 0 0 2px var(--aksen-muda); background: var(--isian-fokus); }
        table.bon tr.temuan-baris td, table.bon tr.kurang-baris td { padding: 0 3px 10px; }
        .kurang { border: 1px solid rgba(255, 92, 97, .35); border-left: 4px solid var(--aksen); background: var(--merah-muda); color: var(--merah); border-radius: 8px; padding: 6px 12px; font-size: 13px; }
        .temuan { border: 1px solid #6b4f10; border-left: 4px solid #e0a526; background: #1d1810; border-radius: 8px; padding: 8px 12px; font-size: 13px; }
        .temuan .judul-temuan { color: #f0c05a; font-weight: 600; margin-bottom: 4px; }
        .temuan ul { margin: 0 0 8px; padding-left: 18px; }
        .temuan li { margin: 3px 0; line-height: 1.45; }
        .temuan .prioritas { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 0 6px; border-radius: 4px; }
        .temuan .prioritas.tinggi { background: var(--aksen); color: #fff; }
        .temuan .prioritas.sedang { background: #b8860b; color: #fff; }
        .temuan .prioritas.rendah { background: #3b404b; color: #dfe2e7; }
        .temuan .aturan { color: var(--redup); font-size: 11px; }
        .temuan label { display: block; color: var(--teks); font-size: 12px; margin: 0; }
        .temuan input[data-konfirmasi] { margin-top: 4px; }
        .temuan input[data-konfirmasi].kosong { border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen-muda); }
        .temuan .status-konf { margin-top: 4px; font-size: 12px; color: var(--merah); }
        .temuan .status-konf.ok { color: var(--sukses); }
        table.bon tr.baris-rit.ber-flag input[data-nama] { border-color: #b8860b; }
        table.bon tr.baris-rit.valid td { background: rgba(76, 195, 138, .24); }
        table.bon tr.baris-rit.valid td:first-child { box-shadow: inset 4px 0 0 var(--sukses); }
        table.bon tr.baris-rit.valid input[data-nama] { background: #143523; border-color: #3fa06f; color: #eafff2; }
        table.bon tr.baris-rit.valid + tr.temuan-baris .temuan { border-color: #2f7a55; border-left-color: var(--sukses); background: #10241a; }
        table.bon tr.baris-rit.valid + tr.temuan-baris .judul-temuan { color: var(--sukses); }
        @media (max-width: 900px) { .kepala { grid-template-columns: 1fr 1fr; } }
    </style>

    @php($edit ??= null)
    <form method="POST" action="{{ $edit ? route('ritasi.update', $edit['baris']) : route('ritasi.store') }}" class="form-kas" id="form-ritasi">
        @csrf
        @if ($edit)
            @method('PUT')
            <input type="hidden" name="versi" value="{{ $edit['versi'] }}">
            <input type="hidden" name="baris_edit" value="{{ $edit['baris'] }}">
        @endif
        @if ($errors->any())
            <div class="pesan galat"><b>Periksa lagi isian berikut:</b>
                <ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif

        <div class="kartu">
            <h3 style="margin: 0 0 14px;">
                @if ($edit) Edit Ritasi <span class="redup" style="font-size: 13px; font-weight: normal;">sheet Ritasi baris {{ $edit['baris'] }}</span>
                @else Input Ritasi <span class="redup" style="font-size: 13px; font-weight: normal;">berlaku untuk semua rit di tabel bawah</span>@endif
            </h3>
            <div class="kepala">
                <div><label for="tahap">Tahap / proyek</label><input type="text" name="tahap" id="tahap" list="daftar-tahap" autocomplete="off" value="{{ old('tahap', $edit['tahap'] ?? '') }}" placeholder="mis. ASG Tahap 116"></div>
                <div><label for="tanggal">Tanggal</label><input type="date" name="tanggal" id="tanggal" value="{{ old('tanggal', $edit['tanggal'] ?? now()->toDateString()) }}"></div>
                <div><label for="galian">Galian</label><input type="text" name="galian" id="galian" list="daftar-galian" autocomplete="off" value="{{ old('galian', $edit['galian'] ?? '') }}"></div>
                <div><label for="jenis_tanah">Jenis tanah</label><input type="text" name="jenis_tanah" id="jenis_tanah" list="daftar-tanah" autocomplete="off" value="{{ old('jenis_tanah', $edit['jenis_tanah'] ?? '') }}"></div>
                <div><label for="jenis_buangan">Jenis buangan</label><input type="text" name="jenis_buangan" id="jenis_buangan" list="daftar-buangan" autocomplete="off" value="{{ old('jenis_buangan', $edit['jenis_buangan'] ?? 'Ritasi') }}"></div>
            </div>
        </div>

        <div class="kartu">
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px;">
                <h3 style="margin: 0 0 4px;">Rit</h3>
                <span class="redup"><b id="jumlah-rit">0</b> rit · total harga jual <b id="total-harga">0</b></span>
            </div>
            <p class="redup" style="margin: 0 0 10px;">Satu baris = satu rit. Ketik No Lambung (DT) → Plat, Driver, Jenis & Pemilik terisi otomatis; Harga Jual dari histori tahap + galian + kendaraan; No Seri berikutnya disarankan (ungu = otomatis, boleh diganti).</p>
            <div class="gulir">
            <table class="bon" style="min-width: 1350px;">
                <thead><tr>
                    <th style="width: 92px;">No Seri</th><th style="width: 70px;">Jam</th><th style="width: 86px;">No Lambung</th><th style="width: 120px;">Plat</th>
                    <th style="width: 110px;">Driver</th><th style="width: 92px;">Jenis</th><th style="width: 100px;">Pemilik</th><th style="width: 90px;">No DO</th>
                    <th style="width: 118px;" class="angka">Harga jual</th><th>Keterangan</th><th style="width: 26px;"></th>
                </tr></thead>
                <tbody id="daftar-rit"></tbody>
            </table>
            </div>
            @unless ($edit)
                <div class="tambah-detail">
                    <button type="button" class="tombol polos" id="tambah-rit">+ Tambah rit</button>
                    <label>Jumlah rit <input type="text" id="jumlah-detail" value="1" inputmode="numeric" autocomplete="off" maxlength="3"></label>
                    <span class="redup">Seperti Excel: Enter / ↓ ↑ pindah baris, ← → pindah kolom; bisa tempel blok sel dari Excel (Ctrl+V). Baris baru menyalin DT & harga baris di atasnya, No Seri +1.</span>
                </div>
            @endunless
        </div>

        <datalist id="daftar-tahap">@foreach ($tahap as $x)<option value="{{ $x }}">@endforeach</datalist>
        <datalist id="daftar-galian">@foreach ($galian as $x)<option value="{{ $x }}">@endforeach</datalist>
        <datalist id="daftar-tanah">@foreach ($jenisTanah as $x)<option value="{{ $x }}">@endforeach</datalist>
        <datalist id="daftar-buangan">@foreach ($jenisBuangan as $x)<option value="{{ $x }}">@endforeach</datalist>
        <datalist id="daftar-jenis">@foreach ($jenisKendaraan as $x)<option value="{{ $x }}">@endforeach</datalist>
        <datalist id="daftar-pemilik">@foreach ($pemilik as $x)<option value="{{ $x }}">@endforeach</datalist>
        <datalist id="daftar-dt">@foreach ($dt as $k => $d)<option value="{{ $k }}">{{ trim(($d['plat'] ?? '').' '.($d['driver'] ?? '')) }}</option>@endforeach</datalist>

        <div id="hasil-validasi" hidden></div>
        <div style="display: flex; gap: 12px; align-items: center;">
            <button type="submit" class="tombol" id="simpan">{{ $edit ? 'Simpan perubahan ke sheet' : 'Simpan ke sheet' }}</button>
            @if ($edit)<a href="{{ route('ritasi.index') }}" class="tombol polos">Batal</a>@endif
            <span class="redup">Ditulis ke lembar <i>Ritasi</i> (sheet Proyek ASG - Gsheet), di bawah data terakhir.</span>
        </div>
    </form>

    <template id="templat-rit">
        <tr class="baris-rit">
            <td><input type="text" data-nama="no_seri" autocomplete="off"></td>
            <td><input type="text" data-nama="jam" autocomplete="off" placeholder="hh:mm"></td>
            <td><input type="text" data-nama="no_lambung" list="daftar-dt" autocomplete="off"></td>
            <td><input type="text" data-nama="plat" autocomplete="off"></td>
            <td><input type="text" data-nama="driver" autocomplete="off"></td>
            <td><input type="text" data-nama="jenis_kendaraan" list="daftar-jenis" autocomplete="off"></td>
            <td><input type="text" data-nama="pemilik" list="daftar-pemilik" autocomplete="off"></td>
            <td><input type="text" data-nama="no_do" autocomplete="off"></td>
            <td><input type="text" data-nama="harga_jual" class="angka-input rupiah" inputmode="numeric" autocomplete="off"></td>
            <td><input type="text" data-nama="keterangan" autocomplete="off"></td>
            <td><button type="button" class="hapus" title="Hapus baris rit">×</button></td>
        </tr>
    </template>

    <script>
        (() => {
            const DT = @json((object) $dt);
            const HARGA = @json((object) $harga);
            const SERI = @json((object) $seri);
            const awal = @json(old('rit', $edit['rit'] ?? []));
            const MODE_EDIT = {{ $edit ? 'true' : 'false' }};
            const MIN_KONFIRMASI = 10;
            const form = document.getElementById('form-ritasi');
            form.noValidate = true;
            const daftar = document.getElementById('daftar-rit');
            const templat = document.getElementById('templat-rit');
            const tombolSimpan = document.getElementById('simpan');
            const kotak = document.getElementById('hasil-validasi');
            const $ = id => document.getElementById(id);
            const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);
            const angka = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0;
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const kecil = s => String(s ?? '').trim().toLowerCase();
            const rapikanRupiah = el => { const d = el.value.replace(/\D/g, '').replace(/^0+(?=\d)/, ''); el.value = d ? fmt(+d) : ''; };
            const kunciMobil = v => { const t = String(v || '').toUpperCase().replace(/\s+/g, ' ').trim(); const m = t.match(/^DT\s*([O0-9]{1,3})$/); return m ? 'DT ' + m[1].replace(/O/g, '0').padStart(3, '0') : t; };
            const baris = () => [...daftar.querySelectorAll(':scope > tr.baris-rit')];
            const sel = (tr, f) => tr.querySelector(`[data-nama=${f}]`);
            const tetangga = (tr, arah) => { let x = tr; do { x = arah > 0 ? x.nextElementSibling : x.previousElementSibling; } while (x && !x.classList.contains('baris-rit')); return x; };
            const urutkanNama = () => baris().forEach((tr, i) => {
                tr.querySelectorAll('[data-nama]').forEach(el => el.name = `rit[${i}][${el.dataset.nama}]`);
                const k = tr.temuan?.querySelector('[data-konfirmasi]');
                if (k) k.name = `rit[${i}][konfirmasi]`;
            });
            // Isian otomatis (ungu) boleh ditimpa isian otomatis berikutnya; begitu diketik sendiri, tidak disentuh lagi.
            const isiOtomatis = (el, v) => {
                if (el.value && !el.classList.contains('otomatis')) return;
                el.value = v ?? '';
                if (el.classList.contains('rupiah')) rapikanRupiah(el);
                el.classList.toggle('otomatis', !!v);
            };

            // DT → plat, driver, jenis, pemilik; harga dari tahap|galian|jenis|pemilik.
            const aturDariDt = tr => {
                const d = DT[kunciMobil(sel(tr, 'no_lambung').value)];
                if (!d) return;
                isiOtomatis(sel(tr, 'plat'), d.plat || '');
                isiOtomatis(sel(tr, 'driver'), d.driver || '');
                isiOtomatis(sel(tr, 'jenis_kendaraan'), d.jenis || '');
                isiOtomatis(sel(tr, 'pemilik'), d.pemilik || '');
            };
            const aturHarga = tr => {
                const k = [kecil($('tahap').value), kecil($('galian').value)];
                const h = HARGA[[...k, kecil(sel(tr, 'jenis_kendaraan').value), kecil(sel(tr, 'pemilik').value)].join('|')] ?? HARGA[k.join('|')];
                if (h) isiOtomatis(sel(tr, 'harga_jual'), String(h));
            };
            // No Seri: baris pertama = nomor terakhir tahap ini + 1, baris berikutnya +1 dari baris di atasnya.
            const aturSeri = () => {
                if (MODE_EDIT) return;
                const s = SERI[$('tahap').value.trim()];
                let berikut = s ? s.max + 1 : null, pad = s ? s.pad : 0;
                baris().forEach(tr => {
                    const el = sel(tr, 'no_seri');
                    if (berikut !== null && (!el.value || el.classList.contains('otomatis'))) isiOtomatis(el, String(berikut).padStart(pad, '0'));
                    const n = parseInt(el.value.replace(/\D/g, ''), 10);
                    if (!isNaN(n)) { berikut = n + 1; pad = el.value.length; }
                });
            };
            const hitung = () => {
                $('jumlah-rit').textContent = baris().length;
                $('total-harga').textContent = 'Rp ' + fmt(baris().reduce((s, tr) => s + angka(sel(tr, 'harga_jual').value), 0));
            };

            const tambah = (isi = {}) => {
                const tr = templat.content.firstElementChild.cloneNode(true);
                tr.querySelectorAll('[data-nama]').forEach(el => el.value = isi[el.dataset.nama] ?? '');
                tr.querySelectorAll('input.rupiah').forEach(rapikanRupiah);
                tr.konfirmasi = isi.konfirmasi ?? '';
                tr.querySelector('.hapus').addEventListener('click', () => {
                    if (baris().length > 1) { tr.temuan?.remove(); tr.kurangEl?.remove(); tr.remove(); urutkanNama(); aturSeri(); hitung(); evaluasi(); }
                });
                if (MODE_EDIT) tr.querySelector('.hapus').hidden = true;
                daftar.appendChild(tr);
                urutkanNama();
                return tr;
            };

            daftar.addEventListener('input', e => {
                const el = e.target, tr = el.closest('tr');
                if (!el.dataset.nama) return;
                if (e.isTrusted) el.classList.remove('otomatis');
                if (el.classList.contains('rupiah') && e.isTrusted) rapikanRupiah(el);
                const f = el.dataset.nama;
                if (f === 'no_lambung') { aturDariDt(tr); aturHarga(tr); }
                if (f === 'jenis_kendaraan' || f === 'pemilik') aturHarga(tr);
                if (f === 'no_seri') aturSeri();
                hitung();
            });
            daftar.addEventListener('focusout', e => {
                const el = e.target;
                if (el.dataset?.nama === 'no_lambung' && el.value) { el.value = kunciMobil(el.value); aturDariDt(el.closest('tr')); aturHarga(el.closest('tr')); }
                if (el.dataset?.nama === 'plat' && el.value) el.value = el.value.toUpperCase().replace(/\s+/g, ' ').trim();
            });
            ['tahap', 'galian'].forEach(id => $(id).addEventListener('input', () => { if (id === 'tahap') aturSeri(); baris().forEach(aturHarga); hitung(); }));

            // Tambah rit: salin DT, jenis, pemilik, harga dari baris di atasnya (No Seri +1 otomatis).
            const jumlahDetail = $('jumlah-detail');
            if (jumlahDetail) {
                jumlahDetail.addEventListener('input', () => { jumlahDetail.value = jumlahDetail.value.replace(/\D/g, ''); });
                jumlahDetail.addEventListener('focus', () => jumlahDetail.select());
                jumlahDetail.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('tambah-rit').click(); } });
                $('tambah-rit').addEventListener('click', () => {
                    const n = Math.min(100, Math.max(1, parseInt(jumlahDetail.value, 10) || 1));
                    let pertama = null;
                    for (let k = 0; k < n; k++) {
                        const atas = baris().at(-1);
                        const salin = atas ? Object.fromEntries(['no_lambung', 'plat', 'driver', 'jenis_kendaraan', 'pemilik', 'harga_jual'].map(f => [f, sel(atas, f).value])) : {};
                        const tr = tambah(salin);
                        tr.querySelectorAll('[data-nama]').forEach(el => { if (el.value) el.classList.add('otomatis'); });
                        pertama ??= tr;
                    }
                    aturSeri();
                    jumlahDetail.value = '1';
                    hitung();
                    evaluasi();
                    sel(pertama, 'jam').focus();
                });
            }

            // Seperti Excel: Enter/↓ ↑ pindah baris, ← → pindah kolom di ujung teks.
            daftar.addEventListener('keydown', e => {
                const el = e.target;
                if (!el.dataset?.nama || e.altKey || e.ctrlKey || e.metaKey || e.isComposing) return;
                if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && !e.shiftKey) {
                    const semua = el.selectionStart === 0 && el.selectionEnd === el.value.length;
                    const ujung = e.key === 'ArrowLeft' ? el.selectionStart === 0 && el.selectionEnd === 0 : el.selectionStart === el.value.length && el.selectionEnd === el.value.length;
                    if (!semua && !ujung) return;
                    const kol = [...el.closest('tr').querySelectorAll('[data-nama]')];
                    const tuju = kol[kol.indexOf(el) + (e.key === 'ArrowLeft' ? -1 : 1)];
                    if (tuju) { e.preventDefault(); tuju.focus(); tuju.select(); }
                    return;
                }
                const turun = e.key === 'ArrowDown' || (e.key === 'Enter' && !e.shiftKey);
                const naik = e.key === 'ArrowUp' || (e.key === 'Enter' && e.shiftKey);
                if (!turun && !naik) return;
                e.preventDefault();
                const tuju = tetangga(el.closest('tr'), turun ? 1 : -1)?.querySelector(`[data-nama="${el.dataset.nama}"]`);
                if (tuju) { tuju.focus(); tuju.select(); }
            });

            (awal.length ? awal : [{}]).forEach(r => tambah(r));
            if (!MODE_EDIT) TempelTabel.pasang(daftar, {baris, tambah: () => tambah({}), setelah: rows => rows.forEach(tr => {
                const dt = sel(tr, 'no_lambung'); if (dt.value) { dt.value = kunciMobil(dt.value); aturDariDt(tr); }
                aturHarga(tr);
            })});

            // ===== Validasi: isian wajib (per baris), aturan di server (DO/No Seri dobel, cocok ke Kas UJ), konfirmasi FLAG =====
            const kepala = [['tahap', 'Tahap'], ['tanggal', 'Tanggal'], ['galian', 'Galian'], ['jenis_tanah', 'Jenis tanah'], ['jenis_buangan', 'Jenis buangan']];
            let tandaiSemua = false;
            form.addEventListener('focusout', e => { if (e.target.matches('input')) { e.target.dataset.tersentuh = '1'; evaluasi(); } });
            const tampilMasalah = (judul, masalah) => {
                kotak.className = 'pesan galat'; kotak.hidden = false;
                kotak.innerHTML = `<b>${esc(judul)}</b><ul style="margin: 6px 0 0; padding-left: 18px;">${masalah.map(m => `<li>${esc(m)}</li>`).join('')}</ul>`;
            };
            const aturKurang = (tr, i, kurang, tampil) => {
                tr.kurang = kurang;
                if (!kurang.length || !tampil) { tr.kurangEl?.remove(); tr.kurangEl = null; return; }
                if (!tr.kurangEl) { tr.kurangEl = document.createElement('tr'); tr.kurangEl.className = 'kurang-baris'; tr.kurangEl.innerHTML = '<td colspan="11"><div class="kurang"></div></td>'; tr.after(tr.kurangEl); }
                tr.kurangEl.querySelector('.kurang').textContent = `✎ Rit baris ${i + 1}: ${kurang.join(', ')} belum diisi.`;
            };
            const cekIsian = () => {
                form.querySelectorAll('.wajib-kosong').forEach(el => el.classList.remove('wajib-kosong'));
                const masalah = [];
                const tandai = el => { if (tandaiSemua || el.dataset.tersentuh) el.classList.add('wajib-kosong'); };
                const kurangKepala = kepala.filter(([id]) => !$(id).value.trim()).map(([id, label]) => { tandai($(id)); return label; });
                if (kurangKepala.length) masalah.push('Kepala: ' + kurangKepala.join(', ') + ' belum diisi.');
                const barisKurang = [];
                baris().forEach((tr, i) => {
                    const kurang = [];
                    const cek = (f, label) => { if (!sel(tr, f).value.trim()) { tandai(sel(tr, f)); kurang.push(label); } };
                    cek('no_seri', 'No Seri');
                    if (!sel(tr, 'no_lambung').value.trim() && !sel(tr, 'plat').value.trim()) { tandai(sel(tr, 'no_lambung')); kurang.push('No Lambung atau Plat'); }
                    cek('jenis_kendaraan', 'Jenis'); cek('pemilik', 'Pemilik');
                    aturKurang(tr, i, kurang, tandaiSemua || [...tr.querySelectorAll('[data-nama]')].some(el => el.dataset.tersentuh || (el.value.trim() && !el.classList.contains('otomatis'))));
                    if (kurang.length) barisKurang.push(i + 1);
                });
                if (barisKurang.length) masalah.push(`${barisKurang.length} rit belum lengkap (baris ${barisKurang.join(', ')}) — lihat pesan tepat di bawah barisnya.`);
                return masalah;
            };
            const tampilTemuan = temuan => { pasangTemuan(temuan); urutkanNama(); };
            const pasangTemuan = temuan => baris().forEach((tr, i) => {
                if (tr.temuan) tr.konfirmasi = tr.temuan.querySelector('[data-konfirmasi]').value;
                tr.temuan?.remove(); tr.temuan = null; tr.aturan = []; tr.classList.remove('ber-flag');
                const t = temuan[i];
                if (!t?.length) return;
                tr.aturan = [...new Set(t.map(x => x.kode))];
                const el = document.createElement('tr');
                el.className = 'temuan-baris';
                el.innerHTML = `<td colspan="11"><div class="temuan"><div class="judul-temuan">⚠ Rit baris ${i + 1} kena FLAG validasi (${t.length}) — perbaiki bila salah, atau tulis konfirmasi bila memang valid</div>
                    <ul>${t.map(x => `<li><span class="prioritas ${x.prioritas}">${x.prioritas}</span> <span class="aturan">${esc(x.kode)}</span> ${esc(x.pesan)}</li>`).join('')}</ul>
                    <label>Konfirmasi admin <span class="redup">(wajib, min. ${MIN_KONFIRMASI} karakter)</span><input type="text" data-konfirmasi maxlength="1000" autocomplete="off"></label>
                    <div class="status-konf"></div></div></td>`;
                const k = el.querySelector('[data-konfirmasi]');
                k.value = tr.konfirmasi || '';
                k.addEventListener('input', () => { tr.konfirmasi = k.value; evaluasi(); });
                (tr.kurangEl ?? tr).after(el);
                tr.temuan = el;
                tr.classList.add('ber-flag');
            });
            const cekKonfirmasi = () => {
                const belum = [];
                baris().forEach((tr, i) => {
                    const k = tr.temuan?.querySelector('[data-konfirmasi]');
                    if (!k) return;
                    const isi = k.value.trim(), st = tr.temuan.querySelector('.status-konf');
                    const ok = isi.length >= MIN_KONFIRMASI;
                    k.classList.toggle('kosong', !ok);
                    st.className = 'status-konf' + (ok ? ' ok' : '');
                    st.textContent = ok ? '✓ Sudah dikonfirmasi' : isi ? `✗ Konfirmasi terlalu singkat (${isi.length}/${MIN_KONFIRMASI} karakter).` : '✗ Belum dikonfirmasi — perbaiki isian rit ini atau tulis konfirmasi.';
                    if (!ok) belum.push(i + 1);
                });
                return belum.length ? [`${belum.length} rit kena FLAG validasi dan belum dikonfirmasi (baris ${belum.join(', ')}) — alasan & kotak konfirmasinya ada tepat di bawah barisnya.`] : [];
            };
            const kunciIsi = () => JSON.stringify([...new FormData(form).entries()].filter(([k, v]) => typeof v === 'string' && !/konfirmasi|_token|_method|versi/.test(k)));
            let diperiksa = null, memeriksa = null, jeda = null;
            const periksaServer = async kunci => {
                memeriksa = kunci;
                const data = new FormData(form);
                data.delete('_method');
                try {
                    const res = await fetch(@json(route('ritasi.periksa')), {method: 'POST', body: data, headers: {Accept: 'application/json'}});
                    const hasil = await res.json();
                    if (memeriksa !== kunci) return;
                    if (res.ok) { tampilTemuan(hasil.temuan || {}); diperiksa = kunci; }
                } catch (err) { diperiksa = null; }
                finally { if (memeriksa === kunci) memeriksa = null; evaluasi(false); }
            };
            const teksSimpan = tombolSimpan.textContent;
            const evaluasi = (bolehPeriksa = true) => {
                hitung();
                const isian = cekIsian();
                const kunci = kunciIsi();
                const sudah = diperiksa === kunci;
                if (!sudah && bolehPeriksa && memeriksa !== kunci && $('tahap').value.trim() && baris().some(tr => !tr.kurang?.length)) {
                    clearTimeout(jeda); jeda = setTimeout(() => periksaServer(kunciIsi()), 600);
                }
                const konf = cekKonfirmasi();
                baris().forEach(tr => tr.classList.toggle('valid', sudah && !tr.kurang?.length && !(tr.temuan && tr.temuan.querySelector('[data-konfirmasi]').value.trim().length < MIN_KONFIRMASI)));
                tombolSimpan.textContent = teksSimpan;
                if (isian.length) { tombolSimpan.disabled = true; tampilMasalah('Simpan belum aktif — lengkapi dulu:', [...isian, ...(sudah ? konf : [])]); return; }
                if (!sudah) { tombolSimpan.disabled = true; kotak.className = 'pesan'; kotak.hidden = false; kotak.textContent = 'Memeriksa validasi rit…'; return; }
                if (konf.length) { tombolSimpan.disabled = true; tampilMasalah('Simpan belum aktif:', konf); return; }
                tombolSimpan.disabled = false;
                const flag = baris().filter(tr => tr.temuan).length;
                kotak.className = 'pesan sukses'; kotak.hidden = false;
                kotak.textContent = `✓ Semua ${baris().length} rit tervalidasi` + (flag ? ` (${flag} ber-FLAG sudah dikonfirmasi)` : '') + ' — siap disimpan.';
            };
            form.addEventListener('input', e => { if (!e.target.matches('[data-konfirmasi]')) evaluasi(); });
            form.addEventListener('change', () => evaluasi());
            form.addEventListener('submit', e => {
                if (tombolSimpan.disabled || diperiksa !== kunciIsi() || cekIsian().length || cekKonfirmasi().length) {
                    e.preventDefault(); tandaiSemua = true; evaluasi(); kotak.scrollIntoView({block: 'center'}); return;
                }
                tombolSimpan.disabled = true; tombolSimpan.textContent = 'Menyimpan ke sheet…';
            });
            aturSeri();
            baris().forEach(tr => { if (sel(tr, 'no_lambung').value && !sel(tr, 'plat').value) aturDariDt(tr); });
            evaluasi();
        })();
    </script>
@endsection
