@extends('layouts.app', ['judul' => 'Kebutuhan Material'])

@section('lebar', '1400px')

@section('isi')
    <style>
        .km-atas { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px; }
        .km-atas label { display: block; font-size: 12px; color: var(--redup); margin-bottom: 4px; }
        .km-atas #pilih-tahap { min-width: 320px; }
        .km-sinyal { font-size: 12.5px; padding: 5px 10px; border-radius: 999px; border: 1px solid var(--garis-kuat); white-space: nowrap; }
        .km-sinyal.mati { border-color: #f0a43a; color: #f0a43a; }
        .km-rekam { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
        #mik { width: 76px; height: 76px; border-radius: 50%; font-size: 32px; padding: 0; flex: none; }
        #mik.rekam { animation: denyut 1.2s infinite; background: #c62f34; }
        @keyframes denyut { 0%, 100% { box-shadow: 0 0 0 0 rgba(229, 72, 77, .6); } 50% { box-shadow: 0 0 0 12px rgba(229, 72, 77, 0); } }
        #status-mik { font-size: 14px; }
        .km-ketik { display: flex; gap: 8px; margin-top: 12px; }
        .km-ketik textarea { flex: 1; min-height: 42px; resize: vertical; }
        .km-antri { margin-top: 12px; display: none; }
        .km-antri.ada { display: block; }
        .km-antri .baris, .km-gagal .baris { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 6px 0; border-top: 1px solid var(--garis); font-size: 13px; }
        .km-antri audio, .km-gagal audio { height: 32px; max-width: 220px; }
        .km-gagal { margin-top: 12px; display: none; }
        .km-gagal.ada { display: block; }
        .km-gagal .galat { color: #ffb3b5; }
        .km-tabel-bungkus { overflow-x: auto; }
        table.km { width: 100%; min-width: 1100px; border-collapse: collapse; font-size: 13px; }
        table.km th { text-align: left; font-size: 12px; color: var(--redup); font-weight: 600; padding: 6px; border-bottom: 1px solid var(--garis-kuat); white-space: nowrap; }
        table.km td { padding: 4px 6px; border-bottom: 1px solid var(--garis); vertical-align: top; }
        table.km td.angka, table.km th.angka { text-align: right; white-space: nowrap; }
        table.km input { width: 100%; box-sizing: border-box; padding: 5px 7px; font-size: 13px; }
        table.km input.qty { width: 70px; text-align: right; }
        table.km input.sat { width: 78px; }
        table.km input.harga { width: 110px; text-align: right; }
        table.km tr.dicek input.harga { border-color: #4cc38a; }
        table.km tr.baru td { animation: sorot 2.5s ease-out; }
        @keyframes sorot { from { background: var(--aksen-muda); } to { background: transparent; } }
        .saran { white-space: nowrap; }
        .saran .rp { font-weight: 600; }
        .saran .mirip { color: #f0a43a; }
        .saran .dasar { font-size: 11px; color: var(--redup); white-space: normal; max-width: 260px; }
        .saran button, .tombol-kecil { font-size: 11.5px; padding: 2px 7px; border-radius: 6px; border: 1px solid var(--garis-kuat); background: none; color: var(--teks); cursor: pointer; }
        .saran button:hover, .tombol-kecil:hover { border-color: var(--aksen); color: var(--aksen-terang); }
        .transkrip { font-size: 11px; color: var(--redup); font-style: italic; max-width: 220px; }
        .km-total { display: flex; gap: 22px; justify-content: flex-end; flex-wrap: wrap; padding: 10px 6px 2px; font-size: 14px; }
        .km-total b { font-size: 15px; }
        tr.tambah td { background: var(--kartu-2); }
        #dialog-bukti { max-width: 980px; width: 96vw; }
        #dialog-bukti table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        #dialog-bukti th, #dialog-bukti td { padding: 5px 6px; border-bottom: 1px solid var(--garis); text-align: left; vertical-align: top; }
        #dialog-bukti td.angka { text-align: right; white-space: nowrap; }
        .kosong { padding: 18px; text-align: center; color: var(--redup); }
        @media (max-width: 700px) {
            .km-atas #pilih-tahap { min-width: 0; width: 100%; }
            .km-mik-tetap { position: fixed; right: 16px; bottom: 16px; z-index: 50; }
            #mik { width: 68px; height: 68px; box-shadow: 0 6px 18px rgba(0, 0, 0, .5); }
        }
    </style>

    <p class="redup" style="margin-top: 0;">Saat diskusi dengan orang lapangan: begitu satu material sudah fix, tekan <b>🎤</b>, ucapkan kebutuhannya
        (mis. <i>"semen tiga puluh sak untuk pondasi pos jaga"</i>), lalu tekan <b>⏹</b>. Sistem mengubah suara menjadi item + qty + satuan dan
        menyarankan harga & satuan dari riwayat transaksi (Kas Harian, Kas UJ, Database Sparepart, dan harga yang sudah Bapak pastikan di sini).
        <b>Tanpa sinyal tetap bisa merekam</b> — rekaman disimpan di perangkat dan dikirim otomatis saat sinyal ada. Mengisi kolom Harga = harga
        dipastikan (✓) dan menjadi riwayat untuk saran berikutnya.</p>

    <div class="km-atas">
        <div>
            <label for="pilih-tahap">Proyek / tahap</label>
            <input id="pilih-tahap" list="daftar-tahap" placeholder="Ketik nama proyek, mis. Pantai Mutiara…" autocomplete="off">
            <datalist id="daftar-tahap">@foreach ($tahap as $t)<option value="{{ $t['nama'] }}"></option>@endforeach</datalist>
        </div>
        <span class="km-sinyal" id="sinyal">📶 Online</span>
    </div>

    <div class="kartu">
        <div class="km-rekam">
            <span class="km-mik-tetap"><button type="button" class="tombol" id="mik" title="Rekam satu material">🎤</button></span>
            <div style="flex: 1; min-width: 220px;">
                <div id="status-mik">Pilih proyek / tahap dulu, lalu tekan 🎤.</div>
                <div class="redup" style="font-size: 12px; margin-top: 4px;">Satu rekaman pendek per material (boleh beberapa material sekaligus). Maks. 3 menit.</div>
            </div>
        </div>
        <div class="km-ketik">
            <textarea id="ketik" placeholder="…atau ketik: besi beton 13 mm 40 batang untuk kolom, pasir 2 rit" rows="1"></textarea>
            <button type="button" class="tombol" id="kirim-ketik">Tambah</button>
        </div>

        <div class="km-antri" id="antri">
            <h3 style="margin: 4px 0;">⏳ Menunggu dikirim <span class="redup" id="antri-info" style="font-size: 12px; font-weight: 400;"></span></h3>
            <div id="antri-isi"></div>
        </div>
        <div class="km-gagal" id="gagal">
            <h3 style="margin: 4px 0;">⚠️ Rekaman yang belum jadi item</h3>
            <div id="gagal-isi"></div>
        </div>
    </div>

    <div class="kartu" style="padding: 0;">
        <div class="km-tabel-bungkus">
            <table class="km" data-tanpa-saring-kolom>
                <thead>
                    <tr>
                        <th style="width: 32px;">#</th>
                        <th>Tgl</th>
                        <th style="min-width: 200px;">Material</th>
                        <th class="angka">Qty</th>
                        <th>Satuan</th>
                        <th>Saran harga / satuan</th>
                        <th class="angka">Harga / satuan</th>
                        <th class="angka">Subtotal</th>
                        <th style="min-width: 170px;">Keterangan</th>
                        <th>Suara</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="isi"><tr><td colspan="11" class="kosong">Pilih proyek / tahap.</td></tr></tbody>
                <tbody>
                    <tr class="tambah">
                        <td></td><td></td>
                        <td><input id="t-nama" placeholder="+ tambah manual: nama material"></td>
                        <td><input id="t-qty" class="qty" inputmode="decimal"></td>
                        <td><input id="t-sat" class="sat" list="daftar-satuan"></td>
                        <td colspan="5"></td>
                        <td><button type="button" class="tombol-kecil" id="t-tambah">Tambah</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="km-total" id="total"></div>
    </div>

    <datalist id="daftar-satuan">@foreach (array_unique(\App\Support\SaranMaterial::SATUAN) as $s)<option value="{{ $s }}"></option>@endforeach</datalist>

    <dialog id="dialog-bukti">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
            <h3 style="margin: 0;" id="bukti-judul">Riwayat harga</h3>
            <button type="button" class="tombol-kecil" onclick="this.closest('dialog').close()">Tutup ✕</button>
        </div>
        <p class="redup" style="font-size: 12px;">Transaksi yang cocok dengan nama material, urut paling cocok. Harga/satuan dari Kas = nominal ÷ qty di keterangan;
            "—" = keterangan tidak menyebut qty (hanya harga total). Klik <b>Pakai</b> untuk memakai harga itu.</p>
        <div id="bukti-isi"></div>
    </dialog>

    <script src="{{ asset('js/idb-keyval.js') }}?v={{ filemtime(public_path('js/idb-keyval.js')) }}"></script>
    <script>
        (() => {
            const TAHAP = @json($tahap);
            const URL_ = {
                data: @json(route('kebutuhan-material.data')), rekaman: @json(route('kebutuhan-material.rekaman')), token: @json(route('kebutuhan-material.token')),
                saran: @json(route('kebutuhan-material.saran')), tambah: @json(route('kebutuhan-material.tambah')),
                item: @json(url('/kebutuhan-material/item')), rek: @json(url('/kebutuhan-material/rekaman')),
            };
            let csrf = document.querySelector('meta[name=csrf-token]').content;
            let tahap = @json($pilih) || +localStorage.getItem('km-tahap') || null;
            if (tahap && !TAHAP.some(t => t.id === tahap)) tahap = null;
            let data = @json($data);
            const $ = id => document.getElementById(id);
            const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            const rp = n => n === null || n === undefined || n === '' ? '' : Math.round(n).toLocaleString('id-ID');
            const angka = s => { s = String(s ?? '').trim(); if (!s) return null; s = s.replace(/\s/g, ''); if (/^\d{1,3}(\.\d{3})+(,\d+)?$/.test(s)) s = s.replace(/\./g, ''); s = s.replace(',', '.'); const n = parseFloat(s); return isNaN(n) ? null : n; };
            const tglPendek = t => t ? new Date(t + 'T00:00:00').toLocaleDateString('id-ID', {day: 'numeric', month: 'short'}) : '';
            const db = idbKeyval.createStore('mnp-kebutuhan-material', 'antrian');
            navigator.storage?.persist?.(); // minta browser tidak menghapus rekaman yang belum terkirim

            // ===== Pilih tahap =====
            const pilihTahap = $('pilih-tahap');
            const namaTahap = id => TAHAP.find(t => t.id === id)?.nama || '';
            pilihTahap.value = namaTahap(tahap);
            pilihTahap.addEventListener('change', () => {
                const t = TAHAP.find(t => t.nama.toLowerCase() === pilihTahap.value.trim().toLowerCase());
                if (!t) { pilihTahap.setCustomValidity('Pilih dari daftar'); pilihTahap.reportValidity(); return; }
                pilihTahap.setCustomValidity('');
                tahap = t.id; localStorage.setItem('km-tahap', tahap);
                history.replaceState(null, '', '?tahap=' + tahap);
                data = null; render(); muat(); statusAwal();
            });

            // ===== Jaringan =====
            const minta = async (url, opsi = {}, ulang = true) => {
                const ctl = new AbortController(); const batas = setTimeout(() => ctl.abort(), opsi.batas || 90000);
                try {
                    const r = await fetch(url, {...opsi, signal: ctl.signal, headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...(opsi.headers || {})}});
                    if (r.status === 419 && ulang) { await tokenBaru(); return minta(url, opsi, false); }
                    if (r.status === 401 || (r.redirected && /\/login/.test(r.url))) throw Object.assign(new Error('Sesi login habis — muat ulang halaman lalu login.'), {login: true});
                    const j = await r.json().catch(() => ({}));
                    if (!r.ok) throw Object.assign(new Error(j.galat || j.message || ('HTTP ' + r.status)), {status: r.status});
                    return j;
                } finally { clearTimeout(batas); }
            };
            const tokenBaru = async () => {
                const r = await fetch(URL_.token, {headers: {Accept: 'application/json'}});
                if (r.status === 401 || r.redirected || !(r.headers.get('content-type') || '').includes('json')) throw Object.assign(new Error('Sesi login habis — muat ulang halaman lalu login.'), {login: true});
                if (r.ok) csrf = (await r.json()).token;
            };
            const sinyal = $('sinyal');
            const tampilSinyal = () => { const on = navigator.onLine; sinyal.textContent = on ? '📶 Online' : '📵 Tanpa sinyal — rekaman disimpan di perangkat'; sinyal.classList.toggle('mati', !on); };
            addEventListener('online', () => { tampilSinyal(); kirimAntrian(); if (tahap) muat(); });
            addEventListener('offline', tampilSinyal);
            tampilSinyal();

            // ===== Data & tabel =====
            const muat = async () => {
                if (!tahap) return;
                const id = tahap;
                try { const j = await minta(URL_.data + '?tahap=' + id, {batas: 30000}); if (id === tahap) { data = j; render(); } }
                catch (e) { if (!data) $('isi').innerHTML = `<tr><td colspan="11" class="kosong">Belum bisa memuat daftar (${esc(e.message)}). Rekaman tetap bisa dibuat.</td></tr>`; }
            };
            let sorot = new Set();
            const render = () => {
                const isi = $('isi');
                if (!tahap) { isi.innerHTML = '<tr><td colspan="11" class="kosong">Pilih proyek / tahap.</td></tr>'; $('total').innerHTML = ''; renderGagal(); return; }
                if (!data) { isi.innerHTML = '<tr><td colspan="11" class="kosong">Memuat…</td></tr>'; return; }
                isi.innerHTML = data.item.length ? data.item.map((k, i) => barisHtml(k, i)).join('') : '<tr><td colspan="11" class="kosong">Belum ada material untuk tahap ini. Tekan 🎤 untuk mulai.</td></tr>';
                sorot.clear();
                renderTotal(); renderGagal();
            };
            const barisHtml = (k, i) => {
                const sub = k.qty && k.harga ? k.qty * k.harga : null;
                const mirip = (k.dasar || '').startsWith('≈');
                const saran = k.saran !== null && k.saran !== undefined
                    ? `<span class="rp ${mirip ? 'mirip' : ''}">${mirip ? '≈ ' : ''}Rp${rp(k.saran)}</span>${k.satuan ? ' /' + esc(k.satuan) : ''}
                       ${k.harga === null ? `<button type="button" data-pakai="${k.saran}">Pakai</button>` : ''} <button type="button" data-bukti>Riwayat</button>
                       <div class="dasar">${esc((k.dasar || '').replace(/^≈ mirip — /, 'barang mirip — '))}</div>`
                    : `<span class="redup">belum ada riwayat</span> <button type="button" data-bukti>Cari</button>`;
                return `<tr data-id="${k.id}" class="${k.dicek ? 'dicek' : ''} ${sorot.has(k.id) ? 'baru' : ''}">
                    <td class="redup">${i + 1}</td>
                    <td style="white-space: nowrap;">${tglPendek(k.tanggal)}</td>
                    <td><input data-f="nama_item" value="${esc(k.nama)}"></td>
                    <td class="angka"><input data-f="qty" class="qty" inputmode="decimal" value="${k.qty ?? ''}"></td>
                    <td><input data-f="satuan" class="sat" list="daftar-satuan" value="${esc(k.satuan)}"></td>
                    <td class="saran">${saran}</td>
                    <td class="angka"><input data-f="harga" class="harga" inputmode="numeric" value="${rp(k.harga)}" placeholder="${k.saran ? rp(k.saran) : ''}" title="${k.dicek ? 'Harga sudah dipastikan' : 'Isi untuk memastikan harga'}">${k.dicek ? ' <span title="Harga dipastikan">✓</span>' : ''}</td>
                    <td class="angka">${sub !== null ? 'Rp' + rp(sub) : (k.qty && k.saran ? `<span class="redup" title="perkiraan dari saran">≈ Rp${rp(k.qty * k.saran)}</span>` : '')}</td>
                    <td><input data-f="keterangan" value="${esc(k.ket)}"></td>
                    <td>${k.audio ? `<button type="button" class="tombol-kecil" data-putar="${k.rekaman}" title="Putar rekaman">▶</button>` : ''}
                        ${k.transkrip ? `<div class="transkrip" title="Hasil dengar sistem">"${esc(k.transkrip)}"</div>` : ''}</td>
                    <td><button type="button" class="tombol-hapus" data-hapus title="Hapus">🗑</button></td>
                </tr>`;
            };
            const renderTotal = () => {
                let pasti = 0, kira = 0, belum = 0;
                data.item.forEach(k => { if (k.qty && k.harga) { pasti += k.qty * k.harga; kira += k.qty * k.harga; } else if (k.qty && k.saran) { kira += k.qty * k.saran; belum++; } else belum++; });
                $('total').innerHTML = `<span>${data.item.length} material</span><span>Total harga dipastikan: <b>Rp${rp(pasti)}</b></span>
                    <span>Perkiraan total (pakai saran untuk yang belum): <b>Rp${rp(kira)}</b></span>${belum ? `<span class="redup">${belum} belum dicek harganya</span>` : ''}`;
            };
            const gantiItem = item => {
                const i = data.item.findIndex(k => k.id === item.id);
                if (i >= 0) data.item[i] = item; else data.item.push(item);
                const tr = document.querySelector(`#isi tr[data-id="${item.id}"]`);
                if (tr && i >= 0) { tr.outerHTML = barisHtml(item, i); renderTotal(); } else render();
            };
            $('isi').addEventListener('change', async e => {
                const inp = e.target.closest('input[data-f]'); if (!inp) return;
                const id = +inp.closest('tr').dataset.id, f = inp.dataset.f;
                let v = inp.value.trim();
                if (f === 'qty' || f === 'harga') { v = angka(v); if (inp.value.trim() && v === null) { alert('Angka tidak valid'); return; } }
                if (f === 'nama_item' && !v) { alert('Nama material tidak boleh kosong'); return; }
                inp.disabled = true;
                try { gantiItem((await minta(URL_.item + '/' + id, {method: 'PATCH', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({[f]: v || (v === 0 ? 0 : null)})})).item); }
                catch (err) { alert('Gagal menyimpan: ' + err.message); inp.disabled = false; }
            });
            $('isi').addEventListener('click', async e => {
                const tr = e.target.closest('tr[data-id]'); if (!tr) return;
                const id = +tr.dataset.id, k = data.item.find(x => x.id === id);
                if (e.target.dataset.pakai !== undefined) {
                    e.target.disabled = true;
                    try { gantiItem((await minta(URL_.item + '/' + id, {method: 'PATCH', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({harga: +e.target.dataset.pakai})})).item); }
                    catch (err) { alert('Gagal: ' + err.message); e.target.disabled = false; }
                } else if (e.target.dataset.bukti !== undefined) bukaBukti(k);
                else if (e.target.dataset.putar) putar(e.target, e.target.dataset.putar);
                else if (e.target.dataset.hapus !== undefined) {
                    if (!confirm(`Hapus "${k.nama}"?`)) return;
                    try { await minta(URL_.item + '/' + id, {method: 'DELETE'}); data.item = data.item.filter(x => x.id !== id); render(); }
                    catch (err) { alert('Gagal menghapus: ' + err.message); }
                }
            });
            let pemutar = null;
            const putar = (tombol, rekId) => {
                if (pemutar && pemutar.dataset.rek === String(rekId) && !pemutar.paused) { pemutar.pause(); tombol.textContent = '▶'; return; }
                pemutar?.pause();
                pemutar = new Audio(URL_.rek + '/' + rekId + '/audio'); pemutar.dataset.rek = rekId;
                pemutar.onended = pemutar.onpause = () => { tombol.textContent = '▶'; };
                pemutar.play().then(() => { tombol.textContent = '⏸'; }).catch(err => alert('Tidak bisa memutar: ' + err.message));
            };

            // ===== Dialog riwayat harga =====
            const dialog = $('dialog-bukti');
            const bukaBukti = async k => {
                $('bukti-judul').textContent = 'Riwayat harga: ' + k.nama + (k.satuan ? ' (/' + k.satuan + ')' : '');
                $('bukti-isi').innerHTML = '<div class="kosong">Mencari…</div>';
                dialog.showModal();
                try {
                    const j = await minta(URL_.saran + '?' + new URLSearchParams({nama: k.nama, satuan: k.satuan || '', kecuali: k.id}), {batas: 30000});
                    $('bukti-isi').innerHTML = j.bukti.length ? `<table><thead><tr><th>Tgl</th><th>Sumber</th><th>Keterangan</th><th class="angka">Qty</th><th>Satuan</th>
                        <th class="angka">Harga / sat</th><th class="angka">Total</th><th>Penjual / ref</th><th></th></tr></thead><tbody>${j.bukti.map(b => `<tr>
                        <td style="white-space: nowrap;">${esc(b.tanggal)}</td><td>${esc(b.sumber)}</td><td>${esc(b.ket)}</td>
                        <td class="angka">${b.qty ?? ''}</td><td>${esc(b.satuan)}</td><td class="angka">${b.harga !== null ? 'Rp' + rp(b.harga) : '—'}</td>
                        <td class="angka">${b.total !== null ? 'Rp' + rp(b.total) : ''}</td><td class="redup">${esc([b.pihak, b.ref].filter(Boolean).join(' · '))}</td>
                        <td>${b.harga !== null ? `<button type="button" class="tombol-kecil" data-pakai-bukti="${b.harga}" data-sat="${esc(b.satuan || '')}">Pakai</button>` : ''}</td>
                        </tr>`).join('')}</tbody></table>` : '<div class="kosong">Belum ada transaksi yang cocok di Kas Harian, Kas UJ, Database Sparepart, maupun harga yang sudah dipastikan. Cek harga di luar lalu isi kolom Harga.</div>';
                    dialog.dataset.id = k.id;
                } catch (err) { $('bukti-isi').innerHTML = `<div class="kosong">Gagal memuat: ${esc(err.message)}</div>`; }
            };
            dialog.addEventListener('click', async e => {
                if (e.target === dialog) { dialog.close(); return; }
                const b = e.target.closest('[data-pakai-bukti]'); if (!b) return;
                const id = +dialog.dataset.id, k = data.item.find(x => x.id === id);
                const isi = {harga: +b.dataset.pakaiBukti};
                if (b.dataset.sat && !k.satuan) isi.satuan = b.dataset.sat;
                try { gantiItem((await minta(URL_.item + '/' + id, {method: 'PATCH', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(isi)})).item); dialog.close(); }
                catch (err) { alert('Gagal: ' + err.message); }
            });

            // ===== Tambah manual =====
            $('t-tambah').addEventListener('click', async () => {
                if (!tahap) { alert('Pilih proyek / tahap dulu.'); return; }
                const nama = $('t-nama').value.trim(); if (!nama) { $('t-nama').focus(); return; }
                try {
                    const j = await minta(URL_.tambah, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({tahap, nama_item: nama, qty: angka($('t-qty').value), satuan: $('t-sat').value.trim() || null})});
                    $('t-nama').value = $('t-qty').value = $('t-sat').value = '';
                    if (data) { sorot.add(j.item.id); data.item.push(j.item); render(); }
                } catch (err) { alert('Gagal menambah: ' + err.message); }
            });

            // ===== Rekaman gagal di server =====
            const renderGagal = () => {
                const g = (data && tahap) ? data.gagal : [];
                $('gagal').classList.toggle('ada', g.length > 0);
                $('gagal-isi').innerHTML = g.map(r => `<div class="baris" data-rek="${r.id}">
                    <span class="redup">${esc(r.direkam)}</span>
                    ${r.audio ? `<button type="button" class="tombol-kecil" data-putar-gagal="${r.id}">▶</button>` : ''}
                    <span>${r.transkrip ? '"' + esc(r.transkrip) + '"' : '<i class="redup">(belum jadi teks)</i>'}</span>
                    <span class="galat">${esc(r.status === 'baru' ? 'sedang diproses…' : r.galat)}</span>
                    <span style="flex: 1;"></span>
                    <button type="button" class="tombol-kecil" data-ulang="${r.id}">Proses ulang</button>
                    <button type="button" class="tombol-hapus" data-hapus-rek="${r.id}">🗑</button></div>`).join('');
            };
            $('gagal-isi').addEventListener('click', async e => {
                const t = e.target;
                if (t.dataset.putarGagal) putar(t, t.dataset.putarGagal);
                else if (t.dataset.ulang) {
                    t.disabled = true; t.textContent = '⏳';
                    try { const j = await minta(URL_.rek + '/' + t.dataset.ulang + '/ulang', {method: 'POST'}); (j.rekaman.item || []).forEach(id => sorot.add(id)); }
                    catch (err) { alert('Gagal: ' + err.message); }
                    muat();
                } else if (t.dataset.hapusRek) {
                    if (!confirm('Hapus rekaman ini?')) return;
                    try { await minta(URL_.rek + '/' + t.dataset.hapusRek, {method: 'DELETE'}); muat(); } catch (err) { alert('Gagal: ' + err.message); }
                }
            });

            // ===== Antrian di perangkat (IndexedDB) → kirim saat ada sinyal =====
            const uid = () => (crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2)).replace(/[^A-Za-z0-9_-]/g, '');
            const simpan = async rec => { await idbKeyval.set(rec.uid, rec, db); await renderAntri(); kirimAntrian(); };
            const renderAntri = async () => {
                const semua = (await idbKeyval.values(db)).sort((a, b) => a.waktu - b.waktu);
                $('antri').classList.toggle('ada', semua.length > 0);
                $('antri-info').textContent = semua.length ? `${semua.length} rekaman — ${navigator.onLine ? 'mengirim otomatis…' : 'dikirim otomatis saat ada sinyal'}` : '';
                document.querySelectorAll('#antri-isi audio').forEach(a => URL.revokeObjectURL(a.src));
                $('antri-isi').innerHTML = semua.map(r => `<div class="baris" data-uid="${r.uid}">
                    <span class="redup">${new Date(r.waktu).toLocaleString('id-ID', {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'})}</span>
                    <span>${esc(namaTahap(r.tahap))}</span>
                    ${r.audio ? '<audio controls preload="none"></audio>' : `<span>"${esc(r.teks)}"</span>`}
                    <span class="${r.galat ? 'galat' : 'redup'}" style="color: ${r.galat ? '#ffb3b5' : ''}">${esc(r.galat || (r.coba ? `dicoba ${r.coba}×` : 'menunggu'))}</span>
                    <span style="flex: 1;"></span>
                    <button type="button" class="tombol-kecil" data-kirim>Kirim sekarang</button>
                    <button type="button" class="tombol-hapus" data-buang title="Buang rekaman ini">🗑</button></div>`).join('');
                semua.forEach(r => { if (r.audio) $('antri-isi').querySelector(`[data-uid="${r.uid}"] audio`).src = URL.createObjectURL(new Blob([r.audio], {type: r.mime})); });
            };
            $('antri-isi').addEventListener('click', async e => {
                const u = e.target.closest('[data-uid]')?.dataset.uid; if (!u) return;
                if (e.target.dataset.kirim !== undefined) { jedaSampai = 0; kirimAntrian(); }
                else if (e.target.dataset.buang !== undefined && confirm('Buang rekaman yang belum terkirim ini? Tidak bisa dikembalikan.')) { await idbKeyval.del(u, db); renderAntri(); }
            });
            let mengirim = false, jedaSampai = 0, gagalBeruntun = 0;
            const kirimAntrian = async () => {
                if (mengirim || Date.now() < jedaSampai) return;
                mengirim = true;
                try {
                    const semua = (await idbKeyval.values(db)).sort((a, b) => a.waktu - b.waktu);
                    for (const r of semua) {
                        const fd = new FormData();
                        fd.append('uid', r.uid); fd.append('tahap', r.tahap); fd.append('direkam_pada', new Date(r.waktu).toISOString());
                        if (r.audio) { fd.append('audio', new Blob([r.audio], {type: r.mime}), 'suara.' + r.ext); fd.append('durasi', r.durasi || 0); }
                        else fd.append('teks', r.teks);
                        if (r.tahap === tahap) $('status-mik').textContent = '⏳ Mengirim & mengubah suara jadi item…';
                        try {
                            const j = await minta(URL_.rekaman, {method: 'POST', body: fd, batas: 150000});
                            await idbKeyval.del(r.uid, db);
                            gagalBeruntun = 0;
                            if (r.tahap === tahap) {
                                (j.rekaman.item || []).forEach(id => sorot.add(id));
                                $('status-mik').textContent = j.rekaman.status === 'selesai'
                                    ? `✓ ${j.rekaman.item.length} material ditambahkan${j.rekaman.transkrip ? ': "' + j.rekaman.transkrip + '"' : ''}`
                                    : '⚠️ ' + (j.rekaman.galat || 'Belum jadi item') + ' — lihat "Rekaman yang belum jadi item".';
                                await muat();
                            }
                        } catch (err) {
                            if (err.status === 422) { await idbKeyval.set(r.uid, {...r, galat: err.message}, db); continue; } // data ditolak: biarkan untuk dicek / dibuang
                            if (err.login) { $('status-mik').textContent = '⚠️ ' + err.message + ' Rekaman aman di perangkat.'; break; }
                            gagalBeruntun++;
                            await idbKeyval.set(r.uid, {...r, coba: (r.coba || 0) + 1, galat: null}, db);
                            jedaSampai = Date.now() + Math.min(120000, 10000 * 2 ** Math.min(gagalBeruntun - 1, 4)); // 10s, 20s, 40s, 80s, 120s
                            if (r.tahap === tahap) $('status-mik').textContent = `📵 Belum terkirim (sinyal lemah) — rekaman aman di perangkat, dicoba lagi otomatis.`;
                            break;
                        }
                    }
                } finally { mengirim = false; await renderAntri(); }
            };
            setInterval(() => { if (navigator.onLine) kirimAntrian(); }, 15000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) { jedaSampai = 0; kirimAntrian(); } });
            addEventListener('beforeunload', e => { if (perekam && perekam.state === 'recording') { e.preventDefault(); e.returnValue = ''; } });

            // ===== Perekam =====
            const mik = $('mik'), status = $('status-mik');
            const statusAwal = () => { status.textContent = tahap ? `Siap merekam untuk ${namaTahap(tahap)}. Tekan 🎤.` : 'Pilih proyek / tahap dulu, lalu tekan 🎤.'; };
            let perekam = null, potongan = [], jam = null, detik = 0, mulai = 0;
            const jenis = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].find(t => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';
            const berhenti = () => { if (perekam && perekam.state === 'recording') perekam.stop(); };
            mik.addEventListener('click', async () => {
                if (perekam && perekam.state === 'recording') { berhenti(); return; }
                if (!tahap) { alert('Pilih proyek / tahap dulu.'); pilihTahap.focus(); return; }
                if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { status.textContent = 'Browser ini tidak mendukung rekam suara — pakai kotak ketik di bawah.'; return; }
                let aliran;
                try { aliran = await navigator.mediaDevices.getUserMedia({audio: {echoCancellation: true, noiseSuppression: true}}); }
                catch (e) { status.textContent = 'Izin mikrofon ditolak. Izinkan mikrofon untuk situs ini di pengaturan browser.'; return; }
                potongan = []; detik = 0; mulai = Date.now();
                const tahapRekam = tahap;
                perekam = new MediaRecorder(aliran, jenis ? {mimeType: jenis} : undefined);
                perekam.ondataavailable = e => e.data.size && potongan.push(e.data);
                perekam.onstop = async () => {
                    clearInterval(jam); aliran.getTracks().forEach(t => t.stop()); mik.classList.remove('rekam'); mik.textContent = '🎤';
                    const mime = perekam.mimeType || jenis || 'audio/webm';
                    const blob = new Blob(potongan, {type: mime});
                    if (blob.size < 1500 || Date.now() - mulai < 800) { status.textContent = 'Rekaman terlalu pendek — tekan 🎤, bicara, lalu ⏹.'; return; }
                    const ext = mime.includes('mp4') ? 'mp4' : mime.includes('ogg') ? 'ogg' : 'webm';
                    // simpan sebagai ArrayBuffer (Safari lama kurang andal menyimpan Blob di IndexedDB)
                    await simpan({uid: uid(), tahap: tahapRekam, audio: await blob.arrayBuffer(), mime, ext, durasi: Math.round((Date.now() - mulai) / 1000), waktu: mulai, coba: 0});
                    status.textContent = navigator.onLine ? '⏳ Tersimpan, mengirim…' : '💾 Tersimpan di perangkat — dikirim otomatis saat ada sinyal. Lanjut rekam material berikutnya.';
                };
                perekam.start(1000);
                mik.classList.add('rekam'); mik.textContent = '⏹';
                status.textContent = '🔴 Merekam 0:00 — tekan ⏹ bila selesai';
                jam = setInterval(() => { detik++; status.textContent = `🔴 Merekam ${Math.floor(detik / 60)}:${String(detik % 60).padStart(2, '0')} — tekan ⏹ bila selesai`; if (detik >= 180) berhenti(); }, 1000);
            });

            // ===== Ketik (lewat antrian yang sama: aman tanpa sinyal) =====
            $('kirim-ketik').addEventListener('click', async () => {
                const teks = $('ketik').value.trim();
                if (!teks) { $('ketik').focus(); return; }
                if (!tahap) { alert('Pilih proyek / tahap dulu.'); return; }
                await simpan({uid: uid(), tahap, teks, waktu: Date.now(), coba: 0});
                $('ketik').value = '';
                status.textContent = navigator.onLine ? '⏳ Memproses…' : '💾 Tersimpan di perangkat — dikirim saat ada sinyal.';
            });
            $('ketik').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('kirim-ketik').click(); } });

            // ===== Mulai =====
            if (tahap && !@json($pilih)) history.replaceState(null, '', '?tahap=' + tahap);
            statusAwal(); render(); renderAntri().then(kirimAntrian);
            if (tahap && !data) muat();
            // Halaman bisa dibuka ulang tanpa sinyal (disimpan service worker)
            if ('serviceWorker' in navigator) navigator.serviceWorker.register(@json(asset('kebutuhan-material-sw.js')), {scope: @json(url('/kebutuhan-material'))}).catch(() => {});
        })();
    </script>
@endsection
