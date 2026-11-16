@extends('layouts.app', ['judul' => $p->exists ? 'Ubah '.$p->nomor : 'Buat Pengajuan Sparepart'])

@section('lebar', '1400px')

@section('isi')
    <style>
        .psp-kepala { display: grid; grid-template-columns: 170px minmax(0, 1.3fr) minmax(0, 1fr); gap: 12px 16px; margin-bottom: 6px; }
        .psp-kepala label { display: block; margin-bottom: 4px; }
        .psp-kepala input { width: 100%; box-sizing: border-box; }
        .psp-unit { font-size: 12.5px; color: var(--redup); margin-top: 5px; min-height: 18px; }
        .psp-unit b { color: var(--teks); }

        /* Baris item: grid ber-area supaya selalu muat di kartu (desktop 1 baris, tablet 2 baris, HP bertumpuk) */
        .psp-tabel { border-top: 1px solid var(--garis); margin-top: 14px; }
        .psp-judul, .psp-item { display: grid; gap: 6px 10px; align-items: start;
            grid-template-columns: 26px minmax(0, 1.15fr) minmax(0, 2.6fr) minmax(0, .7fr) minmax(0, .6fr) minmax(0, 1.05fr) minmax(0, 1fr) 34px;
            grid-template-areas: "no jenis nama satuan qty harga sub hapus" ". info info info info ket ket ket"; }
        .psp-judul { grid-template-areas: "no jenis nama satuan qty harga sub hapus"; font-size: 12px; font-weight: 700; color: var(--label, #c3c8d0);
            padding: 10px 0 8px; border-bottom: 1px solid var(--garis); text-transform: uppercase; letter-spacing: .03em; }
        .psp-item { padding: 12px 0; border-bottom: 1px solid var(--garis); }
        .psp-item:focus-within { background: linear-gradient(90deg, transparent, var(--kartu-2) 8%, var(--kartu-2) 92%, transparent); }
        .a-no { grid-area: no; } .a-jenis { grid-area: jenis; } .a-nama { grid-area: nama; position: relative; } .a-satuan { grid-area: satuan; } .a-qty { grid-area: qty; }
        .a-harga { grid-area: harga; } .a-sub { grid-area: sub; } .a-hapus { grid-area: hapus; } .a-info { grid-area: info; } .a-ket { grid-area: ket; }
        .psp-judul .kanan, .psp-item .kanan { text-align: right; }
        .psp-item label { display: none; font-size: 12px; margin-bottom: 3px; }
        .psp-item input { width: 100%; box-sizing: border-box; min-width: 0; }
        .psp-item input.angka { text-align: right; font-variant-numeric: tabular-nums; }
        .psp-item .no { padding-top: 10px; color: var(--redup); font-size: 13px; text-align: right; }
        .psp-item .sub { padding-top: 9px; font-weight: 700; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .psp-item .hapus { width: 34px; height: 38px; padding: 0; display: grid; place-items: center; color: var(--redup); }
        .psp-item .hapus:hover { color: var(--merah); }
        .psp-item .a-ket input { font-size: 13px; padding-top: 6px; padding-bottom: 6px; }
        .psp-item.kosong .a-info, .psp-item.kosong .a-ket { display: none; }

        .usulan { font-size: 12.5px; color: var(--redup); line-height: 1.55; display: flex; flex-direction: column; gap: 5px; }
        .usulan .brs { display: flex; flex-wrap: wrap; gap: 4px 8px; align-items: center; }
        .usulan .dasar { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--teks); background: var(--kartu-2); border-radius: 4px; padding: 1px 7px; }
        .usulan .harga { border: 1px solid var(--garis); border-radius: 6px; padding: 1px 8px; background: transparent; color: var(--teks); cursor: pointer; font: inherit; font-size: 12.5px; white-space: nowrap; }
        .usulan .harga:hover { border-color: var(--garis-kuat); background: var(--kartu-2); }
        .usulan .harga.dipakai { border-color: var(--sukses); color: var(--sukses); background: var(--sukses-muda); }
        .usulan .harga.dipakai::before { content: '✓ '; }
        .usulan .sep { color: var(--garis-kuat); }
        .usulan .naik { color: var(--merah); font-weight: 600; }
        .usulan .turun { color: var(--sukses); font-weight: 600; }
        .usulan .peringatan { color: #e0b252; }
        .usulan .tag { background: var(--kartu-2); border-radius: 4px; padding: 0 6px; color: var(--teks); font-size: 11.5px; }

        .saran-sp { position: absolute; left: 0; top: calc(100% + 2px); z-index: 30; width: 100%; max-height: 360px; overflow-y: auto;
            background: var(--kartu); border: 1px solid var(--garis-kuat); border-radius: 8px; box-shadow: 0 10px 30px rgba(0, 0, 0, .45); }
        .saran-sp[hidden] { display: none; }
        .saran-sp .s { display: flex; gap: 12px; justify-content: space-between; padding: 7px 10px; cursor: pointer; border-bottom: 1px solid var(--garis); }
        .saran-sp .s:last-child { border-bottom: 0; }
        .saran-sp .s.aktif, .saran-sp .s:hover { background: var(--aksen-muda); }
        .saran-sp .s.lain { opacity: .62; }
        .saran-sp .nm { font-weight: 600; }
        .saran-sp .ket { font-size: 11.5px; color: var(--redup); }
        .saran-sp .hg { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .saran-sp .grup { font-size: 11px; font-weight: 700; color: var(--redup); padding: 6px 10px; background: var(--kartu-2); text-transform: uppercase; letter-spacing: .03em; position: sticky; top: 0; }

        @media (min-width: 1100px) { .saran-sp { width: max(100%, 520px); } }
        .psp-total { position: sticky; bottom: 0; z-index: 5; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; background: var(--kartu); border: 1px solid var(--garis-kuat);
            border-radius: 10px; padding: 12px 16px; margin-top: 12px; }
        .psp-total .jml { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; }

        @media (max-width: 1099px) {
            .psp-judul { display: none; }
            .psp-item label { display: block; }
            .psp-item { grid-template-columns: 26px repeat(4, minmax(0, 1fr)) 34px;
                grid-template-areas: "no jenis nama nama nama hapus" ". satuan qty harga sub sub" ". info info info info info" ". ket ket ket ket ket"; }
            .psp-item .no { padding-top: 30px; }
            .psp-item .sub { padding-top: 9px; }
            .psp-item .hapus { margin-top: 20px; }
        }
        @media (max-width: 699px) {
            .psp-kepala { grid-template-columns: 1fr; }
            .psp-item { grid-template-columns: repeat(2, minmax(0, 1fr));
                grid-template-areas: "no hapus" "nama nama" "jenis satuan" "qty harga" "sub sub" "info info" "ket ket"; }
            .psp-item .no { padding-top: 6px; text-align: left; font-weight: 700; color: var(--teks); }
            .psp-item .no::before { content: 'Item '; }
            .psp-item .hapus { margin-top: 0; justify-self: end; }
            .psp-item .sub { padding-top: 0; text-align: left; }
            .psp-item .a-sub label { display: inline; }
            .psp-item .a-sub { display: flex; gap: 8px; align-items: baseline; }
            .saran-sp { width: 100%; }
        }
    </style>

    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi isian berikut:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <p class="redup" style="margin-top: 0;">Isi <b>jenis alat</b> lalu ketik <b>nama sparepart</b> (boleh istilah lapangan: vbelt, saringan solar, laher…). Harga usulan diambil dari
        Database Sparepart <b>hanya dari pembelian jenis alat itu</b> — filter truck = harga truck, alat berat = harga alat berat. Bila unit di Keperluan dikenali
        (mis. DT 049), harga tipe unit itu diutamakan. Harga boleh diubah.</p>

    <form method="POST" action="{{ $p->exists ? route('pengajuan-sparepart.perbarui', $p) : route('pengajuan-sparepart.simpan') }}" id="form-psp" autocomplete="off">
        @csrf
        @if ($p->exists) @method('PUT') @endif
        <div class="kartu">
            <div class="psp-kepala">
                <div><label for="tanggal">Tanggal</label><input type="date" id="tanggal" name="tanggal" value="{{ old('tanggal', $p->tanggal?->toDateString()) }}" required></div>
                <div><label for="keperluan">Keperluan / untuk unit</label><input id="keperluan" name="keperluan" list="daftar-unit" maxlength="200" value="{{ old('keperluan', $p->keperluan) }}" placeholder="mis. Servis berkala DT 049, PC200 ganti filter">
                    <div class="psp-unit" id="psp-unit"></div></div>
                <div><label for="catatan">Catatan untuk manajemen</label><input id="catatan" name="catatan" maxlength="500" value="{{ old('catatan', $p->catatan) }}" placeholder="opsional"></div>
            </div>

            <div class="psp-tabel">
                <div class="psp-judul"><div class="a-no kanan">No</div><div class="a-jenis">Jenis Alat</div><div class="a-nama">Nama Sparepart</div><div class="a-satuan">Satuan</div>
                    <div class="a-qty kanan">Qty</div><div class="a-harga kanan">Harga Satuan</div><div class="a-sub kanan">Subtotal</div><div class="a-hapus"></div></div>
                <div id="psp-baris"></div>
            </div>
            <button type="button" class="tombol polos" id="psp-tambah" style="margin-top: 12px;">+ Tambah baris</button>
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
            <div class="a-no no"></div>
            <div class="a-jenis"><label>Jenis alat</label><input data-f="jenis" list="daftar-jenis" maxlength="80" placeholder="Truck / Alat Berat"></div>
            <div class="a-nama"><label>Nama sparepart</label><input data-f="nama" maxlength="200" placeholder="ketik nama sparepart…" role="combobox" aria-autocomplete="list" aria-expanded="false">
                <div class="saran-sp" role="listbox" hidden></div></div>
            <div class="a-satuan"><label>Satuan</label><input data-f="satuan" list="daftar-satuan" maxlength="20" placeholder="pcs"></div>
            <div class="a-qty"><label>Qty</label><input data-f="qty" class="angka" inputmode="decimal" placeholder="0"></div>
            <div class="a-harga"><label>Harga satuan (Rp)</label><input data-f="harga" class="angka" inputmode="numeric" placeholder="0"></div>
            <div class="a-sub"><label>Subtotal</label><div class="sub">0</div></div>
            <button type="button" class="tombol polos hapus a-hapus" title="Hapus baris" aria-label="Hapus baris">✕</button>
            <div class="a-info usulan"></div>
            <div class="a-ket"><input data-f="ket" maxlength="300" placeholder="keterangan (opsional)"></div>
            <input type="hidden" data-f="part"><input type="hidden" data-f="merk"><input type="hidden" data-f="saran"><input type="hidden" data-f="dasar">
        </div>
    </template>

    <script>
        (() => {
            const KATALOG = @json($katalog);
            const UNIT = @json($unitPeta);      // "DT 049" → [tipe unit, jenis]
            const TIPE = @json($tipePeta);      // "DT Isuzu Giga" → jenis
            const JENIS = @json($jenis);
            const AWAL = @json(old('item', $baris));
            const wadah = document.getElementById('psp-baris'), tpl = document.getElementById('tpl-baris'), keperluan = document.getElementById('keperluan');
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const rp = n => Math.round(Number(n) || 0).toLocaleString('id-ID');
            const angkaRp = v => Number(String(v ?? '').replace(/[^\d]/g, '')) || 0;           // "1.250.000" → 1250000
            const angkaQty = v => { const s = String(v ?? '').trim().replace(/\s/g, ''); return Number(/,/.test(s) ? s.replace(/\./g, '').replace(',', '.') : s) || 0; };
            const tulisQty = n => n ? Number(n).toLocaleString('id-ID', {maximumFractionDigits: 2, useGrouping: false}) : '';

            // ---- Jenis alat: hanya 6 pilihan; ketikan bebas dibakukan (truk → Truck, excavator → Alat Berat, lv → Kendaraan Ringan …)
            const ALIAS = [[/^(tr|truk|truck|dt|dump|engkel|selfloader|water ?tank|lube)/, 'Truck'], [/^(al|alat|ab|excav|exca|beko|dozer|bulldozer|pc|sk)/, 'Alat Berat'],
                [/^(k|lv|mobil|kendaraan|hilux|panther|pick)/, 'Kendaraan Ringan'], [/^(mo|motor|crf|verza)/, 'Motor'], [/^(me|mesin|genset|kompresor|pompa|alkon|las)/, 'Mesin'], [/^(u|umum)/, 'Umum']];
            const bakuJenis = v => {
                const t = String(v ?? '').trim().toLowerCase();
                if (!t) return '';
                return JENIS.find(j => j.toLowerCase() === t) || (ALIAS.find(([re]) => re.test(t)) || [])[1] || '';
            };

            // ---- Unit di Keperluan: "DT 49" / "dt049" → DT 049 (tipe & jenis dari pembelian), atau nama tipe (PC200, SK200, Giga, Mercy, FAW, Hilux …)
            const UMUM_KATA = ['excavator', 'dump', 'truck', 'dt', 'lv', 'lainnya', 'motor', 'unit'];
            function unitAktif() {
                const v = keperluan.value;
                const m = v.match(/\b(dt|lv|dz)\s*-?\s*0*(\d{1,3})\b/i);
                if (m) {
                    const no = m[1].toUpperCase() + ' ' + m[2].padStart(3, '0');
                    if (UNIT[no]) return {no, tipe: UNIT[no][0], jenis: UNIT[no][1]};
                }
                const kecil = ' ' + v.toLowerCase().replace(/[^a-z0-9]+/g, ' ') + ' ';
                for (const [tipe, jenis] of Object.entries(TIPE)) {
                    const kata = tipe.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim().split(' ').filter(k => k.length >= 3 && !UMUM_KATA.includes(k));
                    if (kata.length && kata.some(k => kecil.includes(' ' + k + ' '))) return {no: '', tipe, jenis};
                }
                return null;
            }
            function tampilUnit() {
                const u = unitAktif(), el = document.getElementById('psp-unit');
                el.innerHTML = u ? `Unit dikenali: <b>${esc([u.no, u.tipe].filter(Boolean).join(' · '))}</b> (${esc(u.jenis)}) — harga tipe unit ini diutamakan`
                    : (keperluan.value.trim() ? 'Unit tidak dikenali — harga memakai pembelian terakhir per jenis alat' : '');
                return u;
            }

            // ---- Pencarian sama dengan Database Sparepart: semua kata harus ada; "DT 26" = dt026; kata dicocokkan juga tanpa spasi/tanda + istilah lapangan.
            const ISTILAH = {vbelt: 'vanbelt', fanbelt: 'vanbelt', laher: 'bearing', saringan: 'filter', accu: 'aki'};
            const rapikan = s => String(s ?? '').toLowerCase().replace(/\b(dt|lv|dz)\s*-?\s*0*(\d{1,3})\b/g, (_, a, n) => a + n.padStart(3, '0')).replace(/\s+/g, ' ');
            const padat = s => s.replace(/[^a-z0-9]/g, '');
            KATALOG.forEach(x => {
                x.nm = rapikan(x.n); x.teks = rapikan([x.n, x.p, x.m, x.k, x.c].filter(Boolean).join(' ')); x.pd = padat(x.teks);
                x.jenisAda = Object.keys(x.J).filter(j => j !== 'Umum');
            });
            const cocok = (x, k) => x.teks.includes(k) || (padat(k).length > 2 && x.pd.includes(padat(k))) || (ISTILAH[padat(k)] && x.pd.includes(ISTILAH[padat(k)]));
            const cariItem = (nama, satuan) => {
                const n = nama.trim().toLowerCase(), s = (satuan || '').trim().toLowerCase();
                const sama = KATALOG.filter(x => x.n.toLowerCase() === n);
                return sama.find(x => (x.s || '').toLowerCase() === s) || (sama.length === 1 && !s ? sama[0] : null);
            };

            /** Harga usulan item untuk jenis alat ini: tipe unit di Keperluan (bila pernah dibeli untuk tipe itu), selain itu pembelian terakhir jenis itu. */
            function usulan(x, jenis) {
                const d = x?.J[jenis];
                if (!d) return null;
                const u = unitAktif();
                const t = u && u.jenis === jenis ? d.u.find(r => r[0] === u.tipe) : null;
                return t ? {h: t[1], dasar: `${jenis} · ${t[0]}`, d} : {h: d.h, dasar: jenis === 'Umum' ? 'Umum · semua pembelian' : `${jenis} · terakhir`, d};
            }

            function saran(nama, jenis) {
                const kata = rapikan(nama).trim().split(' ').filter(Boolean);
                if (!kata.length) return {pas: [], lain: []};
                const hasil = KATALOG.filter(x => kata.every(k => cocok(x, k))).map(x => ({x,
                    skor: (x.nm.startsWith(kata[0]) ? 30 : 0) + (kata.every(k => x.nm.includes(k)) ? 20 : 0) + Math.min(x.J.Umum.b, 20) / 2}))
                    .sort((a, b) => b.skor - a.skor || a.x.n.localeCompare(b.x.n)).map(h => h.x);
                if (!jenis) return {pas: hasil.slice(0, 15), lain: []};
                return {pas: hasil.filter(x => x.J[jenis]).slice(0, 15), lain: hasil.filter(x => !x.J[jenis]).slice(0, 6)};
            }

            let nomor = 0;
            function tambah(isi = {}) {
                const el = tpl.content.firstElementChild.cloneNode(true);
                const i = nomor++;
                el.querySelectorAll('[data-f]').forEach(inp => { inp.name = `item[${i}][${inp.dataset.f}]`; });
                const f = k => el.querySelector(`[data-f="${k}"]`);
                for (const k of ['nama', 'jenis', 'satuan', 'part', 'merk', 'saran', 'dasar', 'ket']) f(k).value = isi[k] ?? '';
                f('qty').value = isi.qty ? tulisQty(isi.qty) : '';
                f('harga').value = isi.harga !== undefined && isi.harga !== null && isi.harga !== '' ? rp(isi.harga) : '';
                // baris baru: jenis alat mengikuti unit di Keperluan, kalau tidak ada mengikuti baris sebelumnya
                if (!isi.nama && !f('jenis').value) {
                    const sebelum = wadah.lastElementChild?.querySelector('[data-f="jenis"]').value;
                    f('jenis').value = unitAktif()?.jenis || sebelum || '';
                }
                wadah.appendChild(el);
                pasang(el);
                hitung(el);
                return el;
            }

            /** Isi harga usulan baris ini. paksa = sesudah memilih item; selain itu harga hanya diganti bila belum diubah manual (sama dengan usulan lama / kosong). */
            function terapkanUsulan(el, paksa = false) {
                const f = k => el.querySelector(`[data-f="${k}"]`);
                const x = f('nama').value.trim() ? cariItem(f('nama').value, f('satuan').value) : null;
                const u = usulan(x, f('jenis').value);
                const otomatis = paksa || !f('harga').value || angkaRp(f('harga').value) === Math.round(Number(f('saran').value) || -1);
                f('part').value = x?.p || ''; f('merk').value = x?.m || '';
                if (otomatis) f('harga').value = u ? rp(u.h) : (paksa || f('saran').value ? '' : f('harga').value);
                f('saran').value = u ? u.h : ''; f('dasar').value = u ? u.dasar : '';
                hitung(el);
            }

            function pasang(el) {
                const f = k => el.querySelector(`[data-f="${k}"]`);
                const kotak = el.querySelector('.saran-sp'), nama = f('nama'), jenis = f('jenis');
                let daftar = [], aktif = -1;

                const tutup = () => { kotak.hidden = true; nama.setAttribute('aria-expanded', 'false'); aktif = -1; };
                const tampil = () => {
                    const j = bakuJenis(jenis.value);
                    const {pas, lain} = saran(nama.value, j);
                    daftar = [...pas, ...lain];
                    if (!daftar.length) { tutup(); return; }
                    const baris = (x, idx, redup) => {
                        const u = j ? usulan(x, j) : null;
                        const kanan = u ? `<b>Rp ${rp(u.h)}</b><div class="ket">${esc(u.dasar.split(' · ')[1] === 'terakhir' ? 'terakhir ' + u.d.t : u.dasar.split(' · ')[1])}</div>`
                            : `<div class="ket">${x.jenisAda.length ? 'dibeli untuk: ' + esc(x.jenisAda.join(', ')) : 'unit tidak disebut'}</div>`;
                        return `<div class="s ${redup ? 'lain' : ''}" data-i="${idx}" role="option">
                            <div><div class="nm">${esc(x.n)}${x.s ? ` <span class="ket">/ ${esc(x.s)}</span>` : ''}</div>
                            <div class="ket">${[x.k, x.p, x.m].filter(Boolean).map(esc).join(' · ')}</div></div><div class="hg">${kanan}</div></div>`;
                    };
                    kotak.innerHTML = (j ? `<div class="grup">Pernah dibeli untuk ${esc(j)}</div>` : '<div class="grup">Isi jenis alat supaya harga sesuai</div>')
                        + (pas.length ? pas.map((x, k) => baris(x, k, false)).join('') : '<div class="s lain"><div class="ket">— tidak ada —</div></div>')
                        + (lain.length ? `<div class="grup">Belum pernah dibeli untuk ${esc(j)} (tanpa harga usulan)</div>` + lain.map((x, k) => baris(x, pas.length + k, true)).join('') : '');
                    aktif = -1; kotak.hidden = false; nama.setAttribute('aria-expanded', 'true');
                };
                const sorot = n => {
                    const opsi = [...kotak.querySelectorAll('.s[data-i]')];
                    if (!opsi.length) return;
                    aktif = (n + opsi.length) % opsi.length;
                    opsi.forEach((o, k) => o.classList.toggle('aktif', k === aktif));
                    opsi[aktif].scrollIntoView({block: 'nearest'});
                };
                const pilih = x => {
                    nama.value = x.n; f('satuan').value = x.s || '';
                    // jenis kosong: pakai jenis unit di Keperluan bila item pernah dibeli untuk itu, atau satu-satunya jenis item ini
                    if (!bakuJenis(jenis.value)) {
                        const u = unitAktif();
                        jenis.value = u && x.J[u.jenis] ? u.jenis : (x.jenisAda.length === 1 ? x.jenisAda[0] : '');
                    }
                    if (!angkaQty(f('qty').value)) f('qty').value = '1';
                    tutup(); terapkanUsulan(el, true); sisakanKosong(); cadangan();
                    if (!jenis.value) jenis.focus(); else { f('qty').focus(); f('qty').select(); }
                };

                // daftar tetap terbuka selama fokus pindah antara Nama ↔ Jenis (jenis mengubah harga di daftar)
                const tutupBilaKeluar = () => setTimeout(() => { if (![nama, jenis].includes(document.activeElement)) tutup(); }, 150);
                nama.addEventListener('input', () => { tampil(); terapkanUsulan(el); sisakanKosong(); cadangan(); });
                nama.addEventListener('focus', () => { if (nama.value.trim() && !cariItem(nama.value, f('satuan').value)) tampil(); });
                nama.addEventListener('blur', tutupBilaKeluar);
                nama.addEventListener('keydown', e => {
                    if (e.key === 'ArrowDown') { e.preventDefault(); if (kotak.hidden) tampil(); sorot(aktif + 1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); sorot(aktif - 1); }
                    else if (e.key === 'Enter') { e.preventDefault(); if (!kotak.hidden && daftar.length) pilih(daftar[Math.max(aktif, 0)]); else f('satuan').focus(); }
                    else if (e.key === 'Escape') tutup();
                });
                kotak.addEventListener('mousedown', e => {
                    const o = e.target.closest('.s[data-i]');
                    if (o) { e.preventDefault(); pilih(daftar[+o.dataset.i]); } else e.preventDefault();
                });
                jenis.addEventListener('input', () => {
                    if (nama.value.trim() && !cariItem(nama.value, f('satuan').value) && document.activeElement === jenis) tampil();
                    if (bakuJenis(jenis.value) === jenis.value) terapkanUsulan(el);
                });
                jenis.addEventListener('change', () => { jenis.value = bakuJenis(jenis.value); terapkanUsulan(el); cadangan(); });
                jenis.addEventListener('blur', tutupBilaKeluar);
                f('satuan').addEventListener('input', () => terapkanUsulan(el));
                f('qty').addEventListener('input', () => hitung(el));
                f('harga').addEventListener('input', () => {
                    const pos = f('harga').value.length - f('harga').selectionStart;
                    f('harga').value = f('harga').value.replace(/[^\d]/g, '') ? rp(angkaRp(f('harga').value)) : '';
                    const p = Math.max(0, f('harga').value.length - pos);
                    f('harga').setSelectionRange(p, p);
                    hitung(el);
                });
                // Enter: pindah ke isian berikutnya, bukan mengirim form; dari harga / keterangan ke baris berikutnya
                const urutan = ['jenis', 'nama', 'satuan', 'qty', 'harga'];
                ['jenis', 'satuan', 'qty', 'harga', 'ket'].forEach(k => f(k).addEventListener('keydown', e => {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    if (k === 'jenis') { jenis.value = bakuJenis(jenis.value); terapkanUsulan(el); }
                    if (k === 'ket' || k === 'harga') {
                        const brs = [...wadah.children], sesudah = brs[brs.indexOf(el) + 1] || tambah();
                        nomori(); sesudah.querySelector('[data-f="nama"]').focus();
                    } else f(urutan[urutan.indexOf(k) + 1]).focus();
                }));
                el.querySelector('.usulan').addEventListener('click', e => {
                    const b = e.target.closest('.harga');
                    if (b) { f('harga').value = rp(b.dataset.h); f('saran').value = b.dataset.h; f('dasar').value = b.dataset.dasar; hitung(el); cadangan(); }
                });
                el.querySelector('.hapus').addEventListener('click', () => {
                    if (wadah.children.length > 1) el.remove(); else { el.querySelectorAll('[data-f]').forEach(x => { x.value = ''; }); hitung(el); }
                    nomori(); total(); cadangan();
                });
            }

            // Subtotal + keterangan harga satu baris (hanya harga dari jenis alat baris itu).
            function hitung(el) {
                const f = k => el.querySelector(`[data-f="${k}"]`);
                const qty = angkaQty(f('qty').value), harga = angkaRp(f('harga').value);
                el.querySelector('.sub').textContent = rp(qty * harga);
                el.classList.toggle('kosong', !f('nama').value.trim());
                const us = el.querySelector('.usulan');
                const x = f('nama').value.trim() ? cariItem(f('nama').value, f('satuan').value) : null;
                const j = bakuJenis(f('jenis').value);
                if (!f('nama').value.trim()) us.innerHTML = '';
                else if (!x) us.innerHTML = '<div class="brs">Tidak ada di Database Sparepart — pilih dari usulan atau isi harga perkiraan.</div>';
                else if (!j) us.innerHTML = `<div class="brs peringatan">Isi jenis alat${x.jenisAda.length ? ' (item ini pernah dibeli untuk: ' + esc(x.jenisAda.join(', ')) + ')' : ''} supaya harga usulan sesuai.</div>`;
                else if (!x.J[j]) us.innerHTML = `<div class="brs peringatan">Belum pernah dibeli untuk ${esc(j)}${x.jenisAda.length ? ' (pernah untuk: ' + esc(x.jenisAda.join(', ')) + ')' : ''} — harga jenis lain tidak dipakai, isi harga perkiraan.</div>`;
                else {
                    const d = x.J[j], sat = x.s ? '/' + esc(x.s) : '', unit = unitAktif();
                    const dasarNow = f('dasar').value;
                    const tombol = (h, dasar, label) => `<button type="button" class="harga ${harga === Math.round(h) && dasarNow === dasar ? 'dipakai' : ''}" data-h="${h}" data-dasar="${esc(dasar)}" title="Pakai harga ini">${label} <b>Rp ${rp(h)}</b>${sat}</button>`;
                    const brs1 = [`<span class="dasar">Harga ${esc(j)}</span>`,
                        tombol(d.h, j === 'Umum' ? 'Umum · semua pembelian' : `${j} · terakhir`, 'Terakhir') + ` <span>${esc([d.t, d.sup].filter(Boolean).join(' · '))}</span>`,
                        d.b > 1 && d.mu !== d.h ? tombol(d.mu, `${j} · termurah`, 'Termurah') + ` <span>${esc(d.tm)}</span>` : '',
                        d.b > 1 ? tombol(d.r, `${j} · rata-rata`, 'Rata-rata') : '',
                        `<span>${d.b}× beli</span>`].filter(Boolean).join('<span class="sep">|</span>');
                    const perTipe = d.u.length > 1 || (d.u.length === 1 && d.u[0][1] !== d.h)
                        ? `<div class="brs"><span>Per tipe unit:</span>${d.u.slice(0, 6).map(t => tombol(t[1], `${j} · ${t[0]}`, (unit && unit.tipe === t[0] ? '★ ' : '') + esc(t[0])) + `<span>${t[2]}×</span>`).join(' ')}</div>` : '';
                    const saranN = Number(f('saran').value) || 0, beda = harga && saranN ? (harga - saranN) / saranN * 100 : 0;
                    const info = [x.p ? `<span class="tag">PN ${esc(x.p)}</span>` : '', x.m ? `<span class="tag">${esc(x.m)}</span>` : '',
                        unit && unit.jenis === j && !d.u.some(t => t[0] === unit.tipe) ? `<span class="peringatan">Belum pernah dibeli untuk ${esc(unit.tipe)} — memakai harga ${esc(j)} terakhir</span>` : '',
                        Math.abs(beda) >= 1 ? `<span class="${beda > 0 ? 'naik' : 'turun'}">${beda > 0 ? '+' : ''}${Math.round(beda).toLocaleString('id-ID')}% dari usulan</span>` : ''].filter(Boolean).join(' ');
                    us.innerHTML = `<div class="brs">${brs1}</div>${perTipe}${info ? `<div class="brs">${info}</div>` : ''}`;
                }
                total();
            }

            // selalu sisakan satu baris kosong di akhir (dipanggil saat nama diketik / dipilih)
            function sisakanKosong() {
                if (wadah.lastElementChild?.querySelector('[data-f="nama"]').value.trim()) { tambah(); nomori(); }
            }
            function nomori() { [...wadah.children].forEach((el, i) => { el.querySelector('.no').textContent = i + 1; }); }
            function total() {
                let t = 0, n = 0;
                [...wadah.children].forEach(el => {
                    if (!el.querySelector('[data-f="nama"]').value.trim()) return;
                    n++; t += angkaQty(el.querySelector('[data-f="qty"]').value) * angkaRp(el.querySelector('[data-f="harga"]').value);
                });
                document.getElementById('psp-total').textContent = rp(t);
                document.getElementById('psp-jml-item').textContent = `${n} item`;
            }

            // Keperluan berubah → unit dikenali ulang; baris tanpa jenis ikut jenis unit; harga yang belum diubah manual ikut tipe unit.
            keperluan.addEventListener('input', () => {
                const u = tampilUnit();
                [...wadah.children].forEach(el => {
                    const j = el.querySelector('[data-f="jenis"]');
                    if (u && !j.value) j.value = u.jenis;
                    terapkanUsulan(el);
                });
            });

            // Draf disimpan di perangkat supaya isian tidak hilang bila halaman tertutup (hanya pengajuan baru).
            const KUNCI_DRAF = @json($p->exists ? null : 'psp-draf-'.auth()->id());
            let jedaDraf;
            function cadangan() {
                if (!KUNCI_DRAF) return;
                clearTimeout(jedaDraf);
                jedaDraf = setTimeout(() => {
                    const isi = [...wadah.children].map(el => Object.fromEntries([...el.querySelectorAll('[data-f]')].map(x => [x.dataset.f, x.value]))).filter(b => b.nama.trim());
                    try { isi.length ? localStorage.setItem(KUNCI_DRAF, JSON.stringify({keperluan: keperluan.value, isi})) : localStorage.removeItem(KUNCI_DRAF); } catch (e) {}
                }, 400);
            }
            wadah.addEventListener('input', cadangan);

            tampilUnit();
            let awal = Array.isArray(AWAL) ? AWAL : Object.values(AWAL || {});
            if (!awal.length && KUNCI_DRAF) {
                try {
                    const d = JSON.parse(localStorage.getItem(KUNCI_DRAF) || 'null');
                    const isi = Array.isArray(d) ? d : d?.isi || [];
                    if (isi.length && confirm(`Ada draf pengajuan yang belum dikirim (${isi.length} item). Lanjutkan draf itu?`)) {
                        if (d.keperluan && !keperluan.value) { keperluan.value = d.keperluan; tampilUnit(); }
                        awal = isi.map(b => ({...b, qty: angkaQty(b.qty), harga: angkaRp(b.harga)}));
                    } else localStorage.removeItem(KUNCI_DRAF);
                } catch (e) {}
            }
            awal.filter(b => b && String(b.nama ?? '').trim()).forEach(b => tambah(b));
            if (!wadah.children.length || wadah.lastElementChild.querySelector('[data-f="nama"]').value.trim()) tambah();
            nomori(); total();

            document.getElementById('psp-tambah').addEventListener('click', () => { const el = tambah(); nomori(); el.querySelector('[data-f="nama"]').focus(); });

            // Kirim: jenis wajib salah satu dari daftar; angka dikirim polos (qty "1.5", harga "1250000"); baris kosong dibuang.
            document.getElementById('form-psp').addEventListener('submit', e => {
                const isi = [...wadah.children].filter(el => el.querySelector('[data-f="nama"]').value.trim());
                const gagal = (el, k, pesan) => { e.preventDefault(); alert(pesan); el.querySelector(`[data-f="${k}"]`).focus(); };
                if (!isi.length) { e.preventDefault(); alert('Isi minimal satu sparepart.'); return; }
                for (const el of isi) {
                    const j = el.querySelector('[data-f="jenis"]');
                    j.value = bakuJenis(j.value);
                    if (!j.value) return gagal(el, 'jenis', `Jenis alat "${el.querySelector('[data-f="nama"]').value}" belum diisi (pilih: ${JENIS.join(', ')}).`);
                    if (!angkaQty(el.querySelector('[data-f="qty"]').value)) return gagal(el, 'qty', 'Qty belum diisi.');
                }
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
