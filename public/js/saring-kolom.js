/**
 * Saringan per kolom untuk tabel ber-atribut data-saring-kolom: baris kotak saring di bawah judul kolom.
 * - data-saring-kolom=""     : tiap <tr> di tbody satu baris data.
 * - data-saring-kolom="grup" : tiap <tbody> satu grup (baris master tr.t + detail tr.b); grup cocok bila tiap saringan
 *                              cocok di master ATAU salah satu detailnya (yang cocok di detail → grup dibuka).
 * Teks: "mengandung" (huruf besar/kecil diabaikan). Angka: boleh ">100000", "<= 50.000".
 * Tombol ▾ di tiap kolom: pilih nilai seperti filter Excel (daftar nilai unik + jumlahnya, centang, cari, pilih semua).
 */
(() => {
    const gaya = document.createElement('style');
    gaya.textContent = `
        tr.saring-kolom th { padding: 4px !important; background: var(--kartu, #1e2229); }
        tr.saring-kolom .saring-sel { display: flex; gap: 2px; }
        tr.saring-kolom input { width: 100%; min-width: 56px; box-sizing: border-box; padding: 4px 6px; font-size: 12px; border-radius: 5px; font-weight: 400; }
        tr.saring-kolom .saring-nilai { flex: none; padding: 0 6px; font-size: 11px; border-radius: 5px; border: 1px solid var(--garis-kuat, #4a5160);
            background: var(--kartu-2, #262b33); color: var(--teks, #e6e8eb); cursor: pointer; }
        tr.saring-kolom .saring-nilai.aktif { background: var(--aksen, #e5484d); border-color: var(--aksen, #e5484d); color: #fff; }
        .saring-info { display: flex; gap: 10px; align-items: center; padding: 8px 12px; font-size: 13px; }
        .saring-info[hidden] { display: none; }
        .saring-pop { position: absolute; z-index: 1000; width: 280px; background: var(--kartu, #1e2229); color: var(--teks, #e6e8eb);
            border: 1px solid var(--garis-kuat, #4a5160); border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,.45); padding: 8px; font-size: 13px; }
        .saring-pop input[type=search] { width: 100%; box-sizing: border-box; padding: 5px 8px; border-radius: 5px; margin-bottom: 6px; }
        .saring-pop .daftar { max-height: 300px; overflow: auto; border-top: 1px solid var(--garis, #333); border-bottom: 1px solid var(--garis, #333); padding: 4px 0; }
        .saring-pop label { display: flex; gap: 8px; align-items: center; padding: 2px 4px; cursor: pointer; font-weight: 400; margin: 0; color: inherit; }
        .saring-pop label:hover { background: var(--aksen-muda, rgba(229,72,77,.15)); }
        .saring-pop label span.isi { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .saring-pop label span.n { color: var(--redup, #9aa0a6); font-size: 11px; }
        .saring-pop .aksi { display: flex; gap: 6px; justify-content: space-between; margin-top: 6px; }
        .saring-pop .aksi button { padding: 3px 10px; font-size: 12px; }`;
    document.head.appendChild(gaya);

    const angka = s => {
        const m = String(s).replace(/\./g, '').replace(',', '.').match(/-?\d+(\.\d+)?/);
        return m ? parseFloat(m[0]) : NaN;
    };
    const cocok = (teks, q) => {
        q = q.trim().toLowerCase();
        if (!q) return true;
        const m = q.match(/^([<>]=?)\s*(.+)$/);
        if (m) {
            const a = angka(teks), b = angka(m[2]);
            if (isNaN(a) || isNaN(b)) return false;
            return m[1] === '>' ? a > b : m[1] === '<' ? a < b : m[1] === '>=' ? a >= b : a <= b;
        }
        return teks.replace(/\s+/g, ' ').toLowerCase().includes(q);
    };
    // Nilai sel tanpa hiasan (panah buka, jumlah detail, kotak centang/isian).
    const nilaiSel = td => {
        if (!td) return '';
        if (td.dataset.nilai !== undefined) return td.dataset.nilai;
        const c = td.cloneNode(true);
        c.querySelectorAll('.panah, .jumlah-bon, input, button, select').forEach(x => x.remove());
        return c.textContent.replace(/\s+/g, ' ').trim();
    };
    const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));

    let popTerbuka = null;
    const tutupPop = () => { popTerbuka?.remove(); popTerbuka = null; };
    document.addEventListener('mousedown', e => { if (popTerbuka && !popTerbuka.contains(e.target) && !e.target.closest('.saring-nilai')) tutupPop(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') tutupPop(); });

    document.querySelectorAll('table[data-saring-kolom]').forEach(tabel => {
        const grup = tabel.dataset.saringKolom === 'grup';
        const kepala = tabel.tHead.rows[0];
        const barisSaring = document.createElement('tr');
        barisSaring.className = 'saring-kolom';
        const isian = [];
        const pilihan = {}; // kolom → Set nilai terpilih (null = semua)
        const tombolNilai = {};
        [...kepala.cells].forEach((th, i) => {
            const sel = document.createElement('th');
            if (th.textContent.trim() && !th.hasAttribute('data-tanpa-saring')) {
                const wadah = document.createElement('div');
                wadah.className = 'saring-sel';
                const x = document.createElement('input');
                x.type = 'search';
                x.placeholder = th.classList.contains('angka') ? 'mis. >100000' : 'Saring…';
                x.dataset.kolom = i;
                x.title = 'Saring kolom ' + th.textContent.trim();
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'saring-nilai';
                b.textContent = '▾';
                b.title = 'Pilih nilai kolom ' + th.textContent.trim();
                b.addEventListener('click', e => { e.stopPropagation(); bukaPilihan(i, b, th.textContent.trim()); });
                tombolNilai[i] = b;
                wadah.append(x, b);
                sel.appendChild(wadah);
                isian.push(x);
            }
            barisSaring.appendChild(sel);
        });
        tabel.tHead.appendChild(barisSaring);

        const info = document.createElement('div');
        info.className = 'saring-info';
        info.hidden = true;
        info.innerHTML = '<span class="redup"></span><button type="button" class="tombol polos" style="padding: 3px 10px; font-size: 12px;">Hapus saringan kolom</button>';
        tabel.parentElement.insertBefore(info, tabel);
        info.querySelector('button').addEventListener('click', () => {
            isian.forEach(x => x.value = '');
            Object.keys(pilihan).forEach(k => { pilihan[k] = null; tombolNilai[k]?.classList.remove('aktif'); });
            terapkan();
        });

        const unit = () => grup
            ? [...tabel.tBodies].filter(tb => tb.querySelector('tr.t'))
            : [...tabel.tBodies].flatMap(tb => [...tb.rows]).filter(tr => tr.cells.length > 1);
        const bagian = u => {
            const master = grup ? u.querySelector('tr.t') : u;
            // Detail hanya dibandingkan bila kolomnya sejajar dengan judul (tanpa colspan).
            const detail = grup ? [...u.querySelectorAll('tr.b')].filter(r => r.cells.length === kepala.cells.length) : [];
            return [master, detail];
        };
        // Sumber nilai kolom: detail (lebih rinci) bila ada isinya di kolom itu, kalau tidak dari baris master.
        const pakaiDetail = k => grup && unit().some(u => bagian(u)[1].some(r => nilaiSel(r.cells[k])));
        const nilaiUnit = (u, k, detailSaja) => {
            const [master, detail] = bagian(u);
            return detailSaja ? detail.map(r => nilaiSel(r.cells[k])) : [nilaiSel(master.cells[k])];
        };

        const terapkan = () => {
            const aktif = isian.filter(x => x.value.trim());
            const kolomNilai = Object.keys(pilihan).filter(k => pilihan[k]);
            const sumber = Object.fromEntries(kolomNilai.map(k => [k, pakaiDetail(+k)]));
            const semua = unit();
            let n = 0;
            semua.forEach(u => {
                const [master, detail] = bagian(u);
                let lewatDetail = false;
                const okTeks = aktif.every(x => {
                    const k = +x.dataset.kolom;
                    if (master.cells[k] && cocok(master.cells[k].textContent, x.value)) return true;
                    const d = detail.some(r => r.cells[k] && cocok(r.cells[k].textContent, x.value));
                    lewatDetail ||= d;
                    return d;
                });
                const okNilai = okTeks && kolomNilai.every(k => {
                    const ada = nilaiUnit(u, +k, sumber[k]).some(v => pilihan[k].has(v));
                    if (ada && sumber[k]) lewatDetail = true;
                    return ada;
                });
                const ok = okTeks && okNilai;
                u.style.display = ok ? '' : 'none';
                if (ok && lewatDetail) u.classList.add('buka');
                n += ok;
            });
            info.hidden = !aktif.length && !kolomNilai.length;
            info.querySelector('span').textContent = `${n} dari ${semua.length} ${grup ? 'transaksi' : 'baris'} cocok dengan saringan kolom`;
            barisSaring.dispatchEvent(new Event('input', {bubbles: true}));
        };
        isian.forEach(x => x.addEventListener('input', terapkan));

        const bukaPilihan = (k, tombol, judul) => {
            if (popTerbuka?.dataset.kolom === String(k) && popTerbuka.dataset.tabel === tabel.dataset.idSaring) { tutupPop(); return; }
            tutupPop();
            const detailSaja = pakaiDetail(k);
            const hitung = new Map();
            unit().forEach(u => new Set(nilaiUnit(u, k, detailSaja)).forEach(v => hitung.set(v, (hitung.get(v) || 0) + 1)));
            const nilai = [...hitung.keys()].sort((a, b) => {
                const x = angka(a), y = angka(b);
                return !isNaN(x) && !isNaN(y) && /^[\d.,\s-]+$/.test(a) && /^[\d.,\s-]+$/.test(b) ? x - y : a.localeCompare(b, 'id', {numeric: true});
            });
            const terpilih = pilihan[k] ? new Set(pilihan[k]) : new Set(nilai);
            const pop = document.createElement('div');
            pop.className = 'saring-pop';
            pop.dataset.kolom = k;
            pop.innerHTML = `<b style="display: block; margin-bottom: 6px;">${esc(judul)}</b><input type="search" placeholder="Cari nilai…">
                <label><input type="checkbox" class="semua"> <span class="isi"><i>(Pilih semua)</i></span></label>
                <div class="daftar"></div>
                <div class="aksi"><button type="button" class="tombol polos batal">Batal</button><button type="button" class="tombol terapkan">Terapkan</button></div>`;
            const daftar = pop.querySelector('.daftar');
            const cari = pop.querySelector('input[type=search]');
            const semuaCb = pop.querySelector('input.semua');
            const tampilkan = () => {
                const q = cari.value.trim().toLowerCase();
                const cocokCari = nilai.filter(v => !q || v.toLowerCase().includes(q));
                daftar.innerHTML = cocokCari.slice(0, 1000).map(v => `<label><input type="checkbox" value="${esc(v)}"${terpilih.has(v) ? ' checked' : ''}>
                    <span class="isi" title="${esc(v)}">${v === '' ? '<i>(kosong)</i>' : esc(v)}</span><span class="n">${hitung.get(v)}</span></label>`).join('')
                    + (cocokCari.length > 1000 ? `<p class="redup" style="margin: 4px;">${cocokCari.length - 1000} nilai lain — persempit dengan cari.</p>` : '');
                semuaCb.checked = cocokCari.every(v => terpilih.has(v));
                semuaCb.indeterminate = !semuaCb.checked && cocokCari.some(v => terpilih.has(v));
            };
            daftar.addEventListener('change', e => { e.target.checked ? terpilih.add(e.target.value) : terpilih.delete(e.target.value); tampilkan(); });
            semuaCb.addEventListener('change', () => {
                const q = cari.value.trim().toLowerCase();
                nilai.filter(v => !q || v.toLowerCase().includes(q)).forEach(v => semuaCb.checked ? terpilih.add(v) : terpilih.delete(v));
                tampilkan();
            });
            cari.addEventListener('input', tampilkan);
            pop.querySelector('.batal').addEventListener('click', tutupPop);
            pop.querySelector('.terapkan').addEventListener('click', () => {
                // Semua nilai dicentang = tanpa saringan nilai.
                pilihan[k] = terpilih.size === nilai.length ? null : terpilih;
                tombol.classList.toggle('aktif', !!pilihan[k]);
                tutupPop();
                terapkan();
            });
            document.body.appendChild(pop);
            const r = tombol.getBoundingClientRect();
            pop.style.top = (window.scrollY + r.bottom + 4) + 'px';
            pop.style.left = Math.max(8, Math.min(window.scrollX + r.right - 280, window.scrollX + document.documentElement.clientWidth - 290)) + 'px';
            popTerbuka = pop;
            tampilkan();
            cari.focus();
        };
    });
})();
