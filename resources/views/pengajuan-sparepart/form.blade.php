@extends('layouts.app', ['judul' => $p->exists ? 'Ubah '.$p->nomor : 'Buat Pengajuan Sparepart'])

@section('lebar', '1400px')

@section('isi')
    <style>
        .psp-kepala { display: grid; grid-template-columns: 180px minmax(220px, 1fr) minmax(220px, 1.4fr); gap: 12px; margin-bottom: 14px; }
        .psp-kepala label, .psp-item label { display: block; margin-bottom: 4px; }
        .psp-kepala input, .psp-kepala textarea { width: 100%; box-sizing: border-box; }
        .psp-judul, .psp-item .utama { display: grid; grid-template-columns: 30px minmax(220px, 3fr) minmax(130px, 1.3fr) 90px 80px 140px 140px 36px; gap: 8px; align-items: start; }
        .psp-judul { font-size: 12px; font-weight: 700; color: var(--label, #c3c8d0); padding: 0 10px 6px; }
        .psp-judul .angka, .psp-item .angka { text-align: right; }
        .psp-item { border: 1px solid var(--garis); border-radius: 8px; padding: 10px; margin-bottom: 8px; background: var(--kartu); }
        .psp-item.kosong { opacity: .75; }
        .psp-item input { width: 100%; box-sizing: border-box; }
        .psp-item input.angka { text-align: right; font-variant-numeric: tabular-nums; }
        .psp-item .no { padding-top: 9px; color: var(--redup); font-size: 13px; text-align: right; }
        .psp-item .sub { padding-top: 9px; font-weight: 700; text-align: right; font-variant-numeric: tabular-nums; }
        .psp-item .hapus { padding: 7px 0; width: 36px; }
        .psp-item label { display: none; font-size: 12px; }
        .psp-item .bawah { display: grid; grid-template-columns: 30px 1fr minmax(200px, 340px); gap: 8px; margin-top: 6px; align-items: start; }
        .psp-item .bawah input { font-size: 13px; padding-top: 6px; padding-bottom: 6px; }
        .usulan { font-size: 12.5px; color: var(--redup); line-height: 1.7; }
        .usulan .pilih-harga { border: 1px solid var(--garis); border-radius: 6px; padding: 1px 7px; background: var(--kartu-2); color: var(--teks); cursor: pointer; font: inherit; font-size: 12.5px; }
        .usulan .pilih-harga:hover, .usulan .pilih-harga.dipakai { border-color: var(--aksen); background: var(--aksen-muda); }
        .usulan .naik { color: var(--merah); font-weight: 600; }
        .usulan .turun { color: var(--sukses); font-weight: 600; }
        .usulan .tag { display: inline-block; background: var(--kartu-2); border-radius: 4px; padding: 0 6px; margin-right: 4px; color: var(--teks); }
        .kolom-nama { position: relative; }
        .saran-sp { position: absolute; left: 0; top: calc(100% + 2px); z-index: 30; width: max(100%, 560px); max-width: 92vw; max-height: 340px; overflow-y: auto;
            background: var(--kartu); border: 1px solid var(--garis-kuat); border-radius: 8px; box-shadow: 0 10px 30px rgba(0, 0, 0, .45); }
        .saran-sp[hidden] { display: none; }
        .saran-sp .s { display: flex; gap: 10px; justify-content: space-between; padding: 7px 10px; cursor: pointer; border-bottom: 1px solid var(--garis); }
        .saran-sp .s:last-child { border-bottom: 0; }
        .saran-sp .s.aktif, .saran-sp .s:hover { background: var(--aksen-muda); }
        .saran-sp .s.lain { opacity: .6; }
        .saran-sp .nm { font-weight: 600; }
        .saran-sp .ket { font-size: 11.5px; color: var(--redup); }
        .saran-sp .hg { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .saran-sp .judul-lain { font-size: 11px; color: var(--redup); padding: 5px 10px; background: var(--kartu-2); }
        .psp-total { position: sticky; bottom: 0; z-index: 5; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; background: var(--kartu); border: 1px solid var(--garis-kuat);
            border-radius: 10px; padding: 12px 14px; margin-top: 12px; }
        .psp-total .jml { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; }
        @media (max-width: 860px) {
            .psp-kepala { grid-template-columns: 1fr; }
            .psp-judul { display: none; }
            .psp-item label { display: block; }
            .psp-item .utama { grid-template-columns: 1fr 1fr; }
            .psp-item .no { display: none; }
            .psp-item .kolom-nama { grid-column: 1 / -1; }
            .psp-item .sub { padding-top: 0; text-align: left; }
            .psp-item .sub::before { content: 'Subtotal: '; font-weight: 400; color: var(--redup); }
            .psp-item .hapus { justify-self: end; }
            .psp-item .bawah { grid-template-columns: 1fr; }
            .psp-item .bawah .no { display: none; }
            .saran-sp { width: 100%; }
        }
    </style>

    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi isian berikut:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <p class="redup" style="margin-top: 0;">Ketik <b>nama sparepart</b> (boleh istilah lapangan: vbelt, saringan solar, laher…) dan <b>jenisnya</b> — muncul usulan item dari Database Sparepart beserta
        <b>harga terakhir</b>. Harga boleh diubah; klik harga termurah / rata-rata / per tipe unit untuk memakainya. Sparepart yang belum ada di database tetap boleh diketik, isi harga perkiraannya.</p>

    <form method="POST" action="{{ $p->exists ? route('pengajuan-sparepart.perbarui', $p) : route('pengajuan-sparepart.simpan') }}" id="form-psp" autocomplete="off">
        @csrf
        @if ($p->exists) @method('PUT') @endif
        <div class="kartu">
            <div class="psp-kepala">
                <div><label for="tanggal">Tanggal</label><input type="date" id="tanggal" name="tanggal" value="{{ old('tanggal', $p->tanggal?->toDateString()) }}" required></div>
                <div><label for="keperluan">Keperluan / untuk unit</label><input id="keperluan" name="keperluan" list="daftar-unit" maxlength="200" value="{{ old('keperluan', $p->keperluan) }}" placeholder="mis. DT 026 servis berkala, PC200 ganti filter"></div>
                <div><label for="catatan">Catatan untuk manajemen</label><input id="catatan" name="catatan" maxlength="500" value="{{ old('catatan', $p->catatan) }}" placeholder="opsional"></div>
            </div>

            <div class="psp-judul"><div class="angka">No</div><div>Nama Sparepart</div><div>Jenis</div><div>Satuan</div><div class="angka">Qty</div><div class="angka">Harga Satuan (Rp)</div><div class="angka">Subtotal (Rp)</div><div></div></div>
            <div id="psp-baris"></div>
            <button type="button" class="tombol polos" id="psp-tambah">+ Tambah baris</button>
        </div>

        <div class="psp-total">
            <div><span class="redup" id="psp-jml-item">0 item</span><div class="jml">Rp <span id="psp-total">0</span></div></div>
            <div style="margin-left: auto; display: flex; gap: 8px;">
                <a href="{{ $p->exists ? route('pengajuan-sparepart.lihat', $p) : route('pengajuan-sparepart.index') }}" class="tombol polos">Batal</a>
                <button type="submit" class="tombol">{{ $p->exists ? 'Simpan perubahan' : 'Ajukan ke manajemen' }}</button>
            </div>
        </div>
    </form>

    <datalist id="daftar-jenis">@foreach ($jenis as $j)<option value="{{ $j }}">@endforeach</datalist>
    <datalist id="daftar-satuan">@foreach ($satuan as $s)<option value="{{ $s }}">@endforeach</datalist>
    <datalist id="daftar-unit">@foreach ($unit as $u)<option value="{{ $u }}">@endforeach</datalist>

    <template id="tpl-baris">
        <div class="psp-item">
            <div class="utama">
                <div class="no"></div>
                <div class="kolom-nama"><label>Nama sparepart</label><input data-f="nama" maxlength="200" placeholder="ketik nama sparepart…" role="combobox" aria-autocomplete="list" aria-expanded="false">
                    <div class="saran-sp" role="listbox" hidden></div></div>
                <div><label>Jenis</label><input data-f="jenis" list="daftar-jenis" maxlength="80" placeholder="Alat Berat / Truck…"></div>
                <div><label>Satuan</label><input data-f="satuan" list="daftar-satuan" maxlength="20" placeholder="pcs"></div>
                <div><label>Qty</label><input data-f="qty" class="angka" inputmode="decimal" placeholder="1"></div>
                <div><label>Harga satuan (Rp)</label><input data-f="harga" class="angka" inputmode="numeric" placeholder="0"></div>
                <div class="sub">0</div>
                <button type="button" class="tombol polos hapus" title="Hapus baris">✕</button>
            </div>
            <div class="bawah">
                <div class="no"></div>
                <div class="usulan"></div>
                <div><input data-f="ket" maxlength="300" placeholder="keterangan (opsional)"></div>
            </div>
            <input type="hidden" data-f="part"><input type="hidden" data-f="merk"><input type="hidden" data-f="saran">
        </div>
    </template>

    <script>
        (() => {
            const KATALOG = @json($katalog);
            const AWAL = @json(old('item', $baris));
            const wadah = document.getElementById('psp-baris'), tpl = document.getElementById('tpl-baris');
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const rp = n => Math.round(Number(n) || 0).toLocaleString('id-ID');
            const angkaRp = v => Number(String(v ?? '').replace(/[^\d]/g, '')) || 0;           // "1.250.000" → 1250000
            const angkaQty = v => { const s = String(v ?? '').trim().replace(/\s/g, ''); return Number(/,/.test(s) ? s.replace(/\./g, '').replace(',', '.') : s) || 0; };
            const tulisQty = n => n ? Number(n).toLocaleString('id-ID', {maximumFractionDigits: 2, useGrouping: false}) : '';

            // Pencarian sama dengan Database Sparepart: semua kata harus ada; "DT 26" = dt026; kata juga dicocokkan tanpa spasi/tanda + istilah lapangan.
            const ISTILAH = {vbelt: 'vanbelt', fanbelt: 'vanbelt', laher: 'bearing', saringan: 'filter', accu: 'aki'};
            const rapikan = s => String(s ?? '').toLowerCase().replace(/\b(dt|lv|dz)\s*-?\s*0*(\d{1,3})\b/g, (_, a, n) => a + n.padStart(3, '0')).replace(/\s+/g, ' ');
            const padat = s => s.replace(/[^a-z0-9]/g, '');
            KATALOG.forEach(x => {
                x.nm = rapikan(x.n); x.teks = rapikan([x.n, x.p, x.m, x.k, x.j, x.c].filter(Boolean).join(' ')); x.pd = padat(x.teks);
                x.kunci = x.n.toLowerCase() + '|' + (x.s || '').toLowerCase();
            });
            const cocok = (x, k) => x.teks.includes(k) || (padat(k).length > 2 && x.pd.includes(padat(k))) || (ISTILAH[padat(k)] && x.pd.includes(ISTILAH[padat(k)]));
            const cocokJenis = (x, jenis) => !jenis || (x.j || '').toLowerCase().split(/,\s*/).some(j => j === jenis || j === 'umum');
            const cariItem = (nama, satuan) => {
                const n = nama.trim().toLowerCase(), s = (satuan || '').trim().toLowerCase();
                const sama = KATALOG.filter(x => x.n.toLowerCase() === n);
                return sama.find(x => (x.s || '').toLowerCase() === s) || (sama.length === 1 && !s ? sama[0] : null);
            };

            function saran(nama, jenis) {
                const kata = rapikan(nama).trim().split(' ').filter(Boolean);
                if (!kata.length) return {pas: [], lain: []};
                const j = jenis.trim().toLowerCase();
                const hasil = KATALOG.filter(x => kata.every(k => cocok(x, k))).map(x => ({x,
                    skor: (x.nm.startsWith(kata[0]) ? 30 : 0) + (kata.every(k => x.nm.includes(k)) ? 20 : 0) + Math.min(x.b, 20) / 2}))
                    .sort((a, b) => b.skor - a.skor || a.x.n.localeCompare(b.x.n)).map(h => h.x);
                // jenis diisi → item jenis itu (atau Umum) dulu; lainnya tetap ditampilkan di bawah, diredupkan
                return {pas: hasil.filter(x => cocokJenis(x, j)).slice(0, 15), lain: j ? hasil.filter(x => !cocokJenis(x, j)).slice(0, 6) : []};
            }

            let nomor = 0;
            function tambah(isi = {}) {
                const el = tpl.content.firstElementChild.cloneNode(true);
                const i = nomor++;
                el.querySelectorAll('[data-f]').forEach(inp => { inp.name = `item[${i}][${inp.dataset.f}]`; });
                const f = k => el.querySelector(`[data-f="${k}"]`);
                f('nama').value = isi.nama ?? ''; f('jenis').value = isi.jenis ?? ''; f('satuan').value = isi.satuan ?? '';
                f('qty').value = isi.qty ? tulisQty(isi.qty) : ''; f('harga').value = isi.harga !== undefined && isi.harga !== null && isi.harga !== '' ? rp(isi.harga) : '';
                f('part').value = isi.part ?? ''; f('merk').value = isi.merk ?? ''; f('saran').value = isi.saran ?? ''; f('ket').value = isi.ket ?? '';
                wadah.appendChild(el);
                pasang(el);
                hitung(el);
                return el;
            }

            function pasang(el) {
                const f = k => el.querySelector(`[data-f="${k}"]`);
                const kotak = el.querySelector('.saran-sp'), nama = f('nama');
                let daftar = [], aktif = -1;

                const tutup = () => { kotak.hidden = true; nama.setAttribute('aria-expanded', 'false'); aktif = -1; };
                const tampil = () => {
                    const {pas, lain} = saran(nama.value, f('jenis').value);
                    daftar = [...pas, ...lain];
                    if (!daftar.length) { tutup(); return; }
                    const baris = (x, idx, redup) => `<div class="s ${redup ? 'lain' : ''}" data-i="${idx}" role="option">
                        <div><div class="nm">${esc(x.n)}${x.s ? ` <span class="ket">/ ${esc(x.s)}</span>` : ''}</div>
                        <div class="ket">${[x.k, x.j, x.p, x.m].filter(Boolean).map(esc).join(' · ')}</div></div>
                        <div class="hg"><b>Rp ${rp(x.h)}</b><div class="ket">terakhir ${esc(x.t)}</div></div></div>`;
                    kotak.innerHTML = pas.map((x, k) => baris(x, k, false)).join('')
                        + (lain.length ? `<div class="judul-lain">Jenis lain (bukan "${esc(f('jenis').value.trim())}")</div>` + lain.map((x, k) => baris(x, pas.length + k, true)).join('') : '');
                    aktif = -1; kotak.hidden = false; nama.setAttribute('aria-expanded', 'true');
                };
                const sorot = n => {
                    const opsi = [...kotak.querySelectorAll('.s')];
                    if (!opsi.length) return;
                    aktif = (n + opsi.length) % opsi.length;
                    opsi.forEach((o, k) => o.classList.toggle('aktif', k === aktif));
                    opsi[aktif].scrollIntoView({block: 'nearest'});
                };
                const pilih = x => {
                    nama.value = x.n; f('satuan').value = x.s || '';
                    if (!f('jenis').value.trim() && x.j) f('jenis').value = x.j;
                    f('part').value = x.p || ''; f('merk').value = x.m || '';
                    f('harga').value = rp(x.h); f('saran').value = x.h;
                    if (!angkaQty(f('qty').value)) f('qty').value = '1';
                    tutup(); hitung(el); sisakanKosong(); cadangan();
                    f('qty').focus(); f('qty').select();
                };

                nama.addEventListener('input', () => { tampil(); hitung(el, true); sisakanKosong(); cadangan(); });
                nama.addEventListener('focus', () => { if (nama.value.trim() && !cariItem(nama.value, f('satuan').value)) tampil(); });
                // daftar tetap terbuka selama fokus pindah antara Nama ↔ Jenis (jenis mengurutkan ulang usulan)
                const tutupBilaKeluar = () => setTimeout(() => { if (![nama, f('jenis')].includes(document.activeElement)) tutup(); }, 150);
                nama.addEventListener('blur', tutupBilaKeluar);
                nama.addEventListener('keydown', e => {
                    if (e.key === 'ArrowDown') { e.preventDefault(); if (kotak.hidden) tampil(); sorot(aktif + 1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); sorot(aktif - 1); }
                    else if (e.key === 'Enter') { e.preventDefault(); if (!kotak.hidden && daftar.length) pilih(daftar[Math.max(aktif, 0)]); else f('jenis').focus(); }
                    else if (e.key === 'Escape') tutup();
                });
                kotak.addEventListener('mousedown', e => {
                    const o = e.target.closest('.s');
                    if (o) { e.preventDefault(); pilih(daftar[+o.dataset.i]); }
                });
                // jenis diketik sesudah nama → urutan usulan ikut jenis
                f('jenis').addEventListener('input', () => { if (document.activeElement === f('jenis') && nama.value.trim() && !cariItem(nama.value, f('satuan').value)) { tampil(); } });
                f('jenis').addEventListener('blur', tutupBilaKeluar);
                f('satuan').addEventListener('input', () => hitung(el, true));
                f('qty').addEventListener('input', () => hitung(el));
                f('harga').addEventListener('input', () => {
                    const pos = f('harga').value.length - f('harga').selectionStart;
                    f('harga').value = f('harga').value.replace(/[^\d]/g, '') ? rp(angkaRp(f('harga').value)) : '';
                    const p = Math.max(0, f('harga').value.length - pos);
                    f('harga').setSelectionRange(p, p);
                    hitung(el);
                });
                // Enter di qty / harga / keterangan: pindah ke isian berikutnya, bukan mengirim form
                ['jenis', 'satuan', 'qty', 'harga', 'ket'].forEach((k, n, arr) => f(k).addEventListener('keydown', e => {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    if (k === 'ket' || k === 'harga') {
                        const brs = [...wadah.children], sesudah = brs[brs.indexOf(el) + 1] || tambah();
                        sesudah.querySelector('[data-f="nama"]').focus();
                    } else f(arr[n + 1]).focus();
                }));
                el.querySelector('.usulan').addEventListener('click', e => {
                    const b = e.target.closest('.pilih-harga');
                    if (b) { f('harga').value = rp(b.dataset.h); hitung(el); }
                });
                el.querySelector('.hapus').addEventListener('click', () => {
                    if (wadah.children.length > 1) el.remove(); else { el.querySelectorAll('[data-f]').forEach(x => { x.value = ''; }); hitung(el); }
                    nomori(); total();
                });
            }

            // Subtotal + usulan harga satu baris. namaBerubah = nama/satuan diketik ulang → part/merk/harga saran dicocokkan lagi ke database.
            function hitung(el, namaBerubah = false) {
                const f = k => el.querySelector(`[data-f="${k}"]`);
                const qty = angkaQty(f('qty').value), harga = angkaRp(f('harga').value);
                el.querySelector('.sub').textContent = rp(qty * harga);
                el.classList.toggle('kosong', !f('nama').value.trim());
                const x = f('nama').value.trim() ? cariItem(f('nama').value, f('satuan').value) : null;
                if (namaBerubah) { f('part').value = x?.p || ''; f('merk').value = x?.m || ''; f('saran').value = x ? x.h : ''; }
                const us = el.querySelector('.usulan');
                if (!f('nama').value.trim()) us.innerHTML = '';
                else if (!x) us.innerHTML = '<span>Tidak ada di Database Sparepart — pilih dari usulan atau isi harga perkiraan.</span>';
                else {
                    const sat = x.s ? '/' + esc(x.s) : '';
                    const tombol = (h, label, ket) => `<button type="button" class="pilih-harga ${harga === Math.round(h) ? 'dipakai' : ''}" data-h="${h}" title="Pakai harga ini">${label} <b>Rp ${rp(h)}</b>${sat}</button>${ket ? ` <span>${esc(ket)}</span>` : ''}`;
                    const beda = harga && x.h ? (harga - x.h) / x.h * 100 : 0;
                    us.innerHTML = [
                        (x.p ? `<span class="tag">PN ${esc(x.p)}</span>` : '') + (x.m ? `<span class="tag">${esc(x.m)}</span>` : '') + `💡 ${x.b}× dibeli`,
                        tombol(x.h, 'Terakhir', [x.t, x.sup].filter(Boolean).join(' · ')),
                        x.mu !== x.h ? tombol(x.mu, 'Termurah', x.tm) : '',
                        x.b > 1 ? tombol(x.r, 'Rata-rata', '') : '',
                        ...(x.u || []).filter(u => u[0] !== '(unit tidak disebut)').slice(0, 4).map(u => tombol(u[1], esc(u[0]), '')),
                        Math.abs(beda) >= 1 ? `<span class="${beda > 0 ? 'naik' : 'turun'}">${beda > 0 ? '+' : ''}${Math.round(beda).toLocaleString('id-ID')}% dari harga terakhir</span>` : '',
                    ].filter(Boolean).join(' &nbsp; ');
                }
                total();
            }

            // selalu sisakan satu baris kosong di akhir (dipanggil saat nama diketik / dipilih)
            function sisakanKosong() {
                if (wadah.lastElementChild?.querySelector('[data-f="nama"]').value.trim()) { tambah(); nomori(); }
            }

            function nomori() { [...wadah.children].forEach((el, i) => { el.querySelector('.utama .no').textContent = i + 1; }); }
            function total() {
                let t = 0, n = 0;
                [...wadah.children].forEach(el => {
                    if (!el.querySelector('[data-f="nama"]').value.trim()) return;
                    n++; t += angkaQty(el.querySelector('[data-f="qty"]').value) * angkaRp(el.querySelector('[data-f="harga"]').value);
                });
                document.getElementById('psp-total').textContent = rp(t);
                document.getElementById('psp-jml-item').textContent = `${n} item`;
            }

            // Draf disimpan di perangkat supaya isian tidak hilang bila halaman tertutup (hanya pengajuan baru).
            const KUNCI_DRAF = @json($p->exists ? null : 'psp-draf-'.auth()->id());
            let jedaDraf;
            function cadangan() {
                if (!KUNCI_DRAF) return;
                clearTimeout(jedaDraf);
                jedaDraf = setTimeout(() => {
                    const isi = [...wadah.children].map(el => Object.fromEntries([...el.querySelectorAll('[data-f]')].map(x => [x.dataset.f, x.value]))).filter(b => b.nama.trim());
                    try { isi.length ? localStorage.setItem(KUNCI_DRAF, JSON.stringify(isi)) : localStorage.removeItem(KUNCI_DRAF); } catch (e) {}
                }, 400);
            }
            wadah.addEventListener('input', cadangan);

            let awal = Array.isArray(AWAL) ? AWAL : Object.values(AWAL || {});
            if (!awal.length && KUNCI_DRAF) {
                try {
                    const d = JSON.parse(localStorage.getItem(KUNCI_DRAF) || '[]');
                    if (d.length && confirm(`Ada draf pengajuan yang belum dikirim (${d.length} item). Lanjutkan draf itu?`)) awal = d.map(b => ({...b, qty: angkaQty(b.qty), harga: angkaRp(b.harga)}));
                    else localStorage.removeItem(KUNCI_DRAF);
                } catch (e) {}
            }
            awal.filter(b => b && String(b.nama ?? '').trim()).forEach(b => tambah(b));
            if (!wadah.children.length || wadah.lastElementChild.querySelector('[data-f="nama"]').value.trim()) tambah();
            nomori(); total();

            document.getElementById('psp-tambah').addEventListener('click', () => { const el = tambah(); nomori(); el.querySelector('[data-f="nama"]').focus(); });

            // Kirim: angka dikirim polos (qty "1.5", harga "1250000"); baris kosong dibuang.
            document.getElementById('form-psp').addEventListener('submit', e => {
                const isi = [...wadah.children].filter(el => el.querySelector('[data-f="nama"]').value.trim());
                if (!isi.length) { e.preventDefault(); alert('Isi minimal satu sparepart.'); return; }
                const tanpaQty = isi.find(el => !angkaQty(el.querySelector('[data-f="qty"]').value));
                if (tanpaQty) { e.preventDefault(); alert('Qty belum diisi.'); tanpaQty.querySelector('[data-f="qty"]').focus(); return; }
                const tanpaHarga = isi.filter(el => !angkaRp(el.querySelector('[data-f="harga"]').value));
                if (tanpaHarga.length && !confirm(`${tanpaHarga.length} sparepart belum ada harganya (Rp 0). Tetap ajukan?`)) { e.preventDefault(); return; }
                [...wadah.children].forEach(el => {
                    if (!el.querySelector('[data-f="nama"]').value.trim()) { el.remove(); return; }
                    const q = el.querySelector('[data-f="qty"]'), h = el.querySelector('[data-f="harga"]');
                    q.value = String(angkaQty(q.value)); h.value = String(angkaRp(h.value));
                });
                try { KUNCI_DRAF && localStorage.removeItem(KUNCI_DRAF); } catch (e) {}
            });
        })();
    </script>
@endsection
