/**
 * Saringan per kolom — SEMUA grid di aplikasi (dimuat di layout). Dipasang otomatis pada setiap tabel data yang punya judul
 * kolom (thead) dan minimal 5 baris, kecuali tabel isian form (berisi kotak isian) atau ber-atribut data-tanpa-saring-kolom.
 * Tabel ber-atribut data-saring-kolom selalu dipasang. Grid baru cukup memakai <table> ber-thead seperti biasa.
 * - Baris biasa: tiap <tr> di tbody satu baris data (baris rincian ber-colspan di bawahnya ikut tersembunyi).
 * - Grup (otomatis bila ada tbody.grup, atau data-saring-kolom="grup"): tiap <tbody> satu grup (master tr.t + detail tr.b);
 *   kolom yang ada di detail disaring per baris detail, yang tidak cocok disembunyikan.
 * Sel boleh membawa data-v (teks untuk dicari) & data-n (nilai angka/tanggal yyyy-mm-dd untuk > <).
 * Teks: "mengandung" (huruf besar/kecil diabaikan). Angka: boleh ">100000", "<= 50.000".
 * Tombol ▾ di tiap kolom: pilih nilai seperti filter Excel (daftar nilai unik + jumlahnya, centang, cari, pilih semua).
 */
(() => {
    if (window.SaringKolom) { window.SaringKolom.pindai(); return; } // skrip termuat dua kali (layout + halaman)
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
        .saring-pop .aksi button { padding: 3px 10px; font-size: 12px; }
        /* Semua grid: garis pemisah antar kolom & judul kolom (+ baris saringan) dibekukan saat digulir. Garis judul memakai
           box-shadow karena border pada sel sticky (border-collapse) ikut tergulir. */
        table.grid-beku > thead { position: sticky; top: var(--beku-atas, 0px); z-index: 5; background: var(--kartu, #1e2229); }
        table.grid-beku > thead th { background: var(--kartu, #1e2229); box-shadow: inset 1px 0 0 var(--garis, #333), inset 0 -1px 0 var(--garis-kuat, #4a5160); }
        table.grid-beku > tbody > tr > td + td { border-left: 1px solid var(--garis, #333); }
        .grid-gulir { overflow: auto !important; }
        /* Urut per kolom: klik judul. */
        th.bisa-urut { cursor: pointer; user-select: none; }
        th.bisa-urut:hover { color: var(--aksen, #e5484d); }
        th .urut-ikon { margin-left: 4px; font-size: 10px; opacity: .35; font-weight: 400; }
        th .urut-ikon.aktif { opacity: 1; color: var(--aksen, #e5484d); }
        /* Sel yang dipilih untuk navigasi panah. */
        table.grid-beku td.sel-aktif { outline: 2px solid var(--aksen, #e5484d); outline-offset: -2px; background: rgba(229, 72, 77, .10); }`;
    document.head.appendChild(gaya);

    const angka = s => {
        const m = String(s).replace(/\./g, '').replace(',', '.').match(/-?\d+(\.\d+)?/);
        return m ? parseFloat(m[0]) : NaN;
    };
    // Sel berisi kotak isian (mis. grid form Monitor Ritasi) ikut disaring berdasarkan isiannya.
    const isianSel = td => td.querySelector('input[type=text], input[type=search], input:not([type]), textarea, select');
    const teksSel = td => td.dataset.v ?? (td.textContent + (isianSel(td) ? ' ' + isianSel(td).value : ''));
    const cocok = (td, q) => {
        q = q.trim().toLowerCase();
        if (!q) return true;
        if (!td) return false;
        const m = q.match(/^([<>]=?)\s*(.+)$/);
        if (m) {
            // Tanggal yyyy-mm-dd dibandingkan sebagai teks (data-n), selain itu angka.
            const tgl = /^\d{4}-\d{2}(-\d{2})?$/.test(m[2].trim());
            const a = tgl ? (td.dataset.n ?? '') : (td.dataset.n !== undefined ? parseFloat(td.dataset.n) : angka(td.textContent));
            const b = tgl ? m[2].trim() : angka(m[2]);
            if (tgl ? a === '' : (isNaN(a) || isNaN(b))) return false;
            return m[1] === '>' ? a > b : m[1] === '<' ? a < b : m[1] === '>=' ? a >= b : a <= b;
        }
        return teksSel(td).replace(/\s+/g, ' ').toLowerCase().includes(q);
    };
    // Nilai sel tanpa hiasan (panah buka, jumlah detail, kotak centang/isian).
    const nilaiSel = td => {
        if (!td) return '';
        if (td.dataset.nilai !== undefined) return td.dataset.nilai;
        const c = td.cloneNode(true);
        c.querySelectorAll('.panah, .jumlah-bon, input, button, select').forEach(x => x.remove());
        const t = c.textContent.replace(/\s+/g, ' ').trim();
        return t || (isianSel(td)?.value ?? '').trim();
    };
    const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));

    let popTerbuka = null;
    const tutupPop = () => { popTerbuka?.remove(); popTerbuka = null; };
    document.addEventListener('mousedown', e => { if (popTerbuka && !popTerbuka.contains(e.target) && !e.target.closest('.saring-nilai')) tutupPop(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') tutupPop(); });

    // Judul kolom tetap terlihat saat digulir: bila grid dibungkus kotak bergulir (.gulir), kotak itu diberi tinggi setinggi layar
    // dan digulir sendiri (judul menempel di atas kotak); bila tidak, judul menempel di bawah header aplikasi.
    const bekukan = tabel => {
        tabel.classList.add('grid-beku');
        const atasApp = document.querySelector('body > header, header')?.offsetHeight || 0;
        let kotak = tabel.parentElement;
        for (let i = 0; kotak && i < 3 && kotak !== document.body; i++, kotak = kotak.parentElement) {
            const o = getComputedStyle(kotak).overflowX;
            if (o === 'auto' || o === 'scroll') break;
        }
        if (kotak && kotak !== document.body && ['auto', 'scroll'].includes(getComputedStyle(kotak).overflowX)) {
            kotak.classList.add('grid-gulir');
            kotak.style.maxHeight = `calc(100vh - ${atasApp + 16}px)`;
        } else {
            tabel.style.setProperty('--beku-atas', atasApp + 'px');
        }
    };

    const pasang = tabel => {
        if (tabel.dataset.saringSiap) return;
        tabel.dataset.saringSiap = '1';
        bekukan(tabel);
        const grup = (tabel.dataset.saringKolom || (tabel.querySelector(':scope > tbody.grup') ? 'grup' : '')) === 'grup';
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
                // Kolom sempit (kode, satuan): hanya tombol pilih nilai ▾, tanpa kotak ketik, supaya kolom bisa selebar isinya.
                if (th.hasAttribute('data-saring-sempit')) x.hidden = true;
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

        // Grup: kolom yang punya nilai di baris detail disaring PER BARIS DETAIL (detail yang tidak cocok disembunyikan; semua
        // saringan detail harus cocok di baris yang sama); kolom yang hanya ada di master (mis. Tanggal) disaring di master.
        const terapkan = () => {
            const aktif = isian.filter(x => x.value.trim()).map(x => ({k: +x.dataset.kolom, cek: td => cocok(td, x.value)}));
            const kolomNilai = Object.keys(pilihan).filter(k => pilihan[k]).map(k => ({k: +k, cek: td => pilihan[k].has(nilaiSel(td))}));
            const saringan = [...aktif, ...kolomNilai];
            const diDetail = grup ? Object.fromEntries([...new Set(saringan.map(f => f.k))].map(k => [k, pakaiDetail(k)])) : {};
            const semua = unit();
            let n = 0;
            semua.forEach(u => {
                const [master, detail] = bagian(u);
                const fMaster = saringan.filter(f => !diDetail[f.k]);
                const fDetail = saringan.filter(f => diDetail[f.k]);
                const okMaster = fMaster.every(f => f.cek(master.cells[f.k]));
                let ok = okMaster;
                if (grup) {
                    // Sel master berupa ringkasan detail (class ringkas-gl, mis. daftar DO) tidak dipakai; sel master biasa (mis. penerima)
                    // yang cocok berlaku untuk semua detailnya.
                    const masterCocok = Object.fromEntries(fDetail.map((f, i) => [i, !!master.cells[f.k] && !master.cells[f.k].classList.contains('ringkas-gl')
                        && f.cek(master.cells[f.k])]));
                    const cocokDetail = detail.map(r => fDetail.every((f, i) => masterCocok[i] || f.cek(r.cells[f.k])));
                    if (fDetail.length) ok = okMaster && (detail.length ? cocokDetail.some(Boolean) : fDetail.every((f, i) => masterCocok[i]));
                    // Hanya detail yang cocok yang tampil (judul kolom detail tetap tampil).
                    detail.forEach((r, i) => { r.style.display = fDetail.length && !cocokDetail[i] ? 'none' : ''; });
                    if (ok && fDetail.length) u.classList.add('buka');
                }
                u.style.display = ok ? '' : 'none';
                // Baris biasa: baris rincian ber-colspan tepat di bawahnya (mis. tr.d Daftar Proyek) ikut tersembunyi.
                const rinci = !grup ? u.nextElementSibling : null;
                if (rinci && rinci.cells.length === 1) rinci.style.display = ok ? '' : 'none';
                n += ok;
            });
            info.hidden = !saringan.length;
            info.querySelector('span').textContent = `${n} dari ${semua.length} ${grup ? 'transaksi' : 'baris'} cocok dengan saringan kolom`
                + (grup && saringan.some(f => diDetail[f.k]) ? ' · hanya detail yang cocok yang ditampilkan' : '');
            barisSaring.dispatchEvent(new Event('input', {bubbles: true}));
        };
        isian.forEach(x => x.addEventListener('input', terapkan));

        // ── Urut per kolom: klik judul kolom → naik ▲ → turun ▼ → urutan asli. Angka (rupiah, m³, %) sebagai angka, tanggal
        // ("21 Okt", "21 Okt 2026", 21/10/2026, data-n yyyy-mm-dd) sebagai tanggal, selain itu teks. Nilai kosong selalu di bawah.
        // Baris biasa: baris rincian ber-colspan di bawahnya ikut pindah; baris Total/Jumlah tetap di bawah. Grup: tiap tbody.grup
        // diurutkan menurut baris master-nya. Judul ber-atribut data-tanpa-urut tidak bisa diurutkan.
        const blokUrut = () => grup
            ? [{wadah: tabel, isi: [...tabel.tBodies].filter(tb => tb.querySelector('tr.t'))}]
            : [...tabel.tBodies].map(tb => {
                const isi = [];
                [...tb.rows].forEach(tr => {
                    if (tr.cells.length > 1 || !isi.length) isi.push({baris: [tr]});
                    else isi[isi.length - 1].baris.push(tr); // rincian ber-colspan ikut baris di atasnya
                });
                return {wadah: tb, isi};
            });
        const elUrut = b => grup ? b : b.baris[0];
        const tetap = b => !grup && b.baris[0].cells.length > 1 && /^(total|jumlah|sub ?total|grand total)\b/i.test(nilaiSel(b.baris[0].cells[0]));
        const BULAN = {jan: 1, feb: 2, mar: 3, apr: 4, mei: 5, may: 5, jun: 6, jul: 7, agu: 8, agt: 8, ags: 8, aug: 8, sep: 9, okt: 10, oct: 10, nov: 11, des: 12, dec: 12};
        const kunciUrut = td => {
            if (!td) return null;
            if (td.dataset.n !== undefined && td.dataset.n !== '') return /^-?\d+(\.\d+)?$/.test(td.dataset.n) ? +td.dataset.n : td.dataset.n;
            const t = nilaiSel(td);
            if (t === '' || t === '-' || t === '—') return null;
            // Tanggal (+ jam bila ada di sel yang sama, mis. "21 Okt 9:14") → "yyyy-mm-dd hh:mm" supaya bisa dibandingkan sebagai teks.
            const jam = s => { const j = s.match(/\b(\d{1,2})[:.](\d{2})\b/); return j ? ` ${j[1].padStart(2, '0')}:${j[2]}` : ''; };
            let m = t.match(/^(\d{1,2})\s+([A-Za-z]{3})[a-z]*\.?(?:\s+(\d{4}))?/);
            if (m && BULAN[m[2].toLowerCase()]) return `${m[3] || new Date().getFullYear()}-${String(BULAN[m[2].toLowerCase()]).padStart(2, '0')}-${m[1].padStart(2, '0')}${jam(t.slice(m[0].length))}`;
            if ((m = t.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/))) return `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}${jam(t.slice(m[0].length))}`;
            if (/^\d{4}-\d{2}-\d{2}/.test(t)) return t.slice(0, 10) + jam(t.slice(10));
            // Angka: rupiah, satuan, No DO bertanda petik di depan (`0018).
            if (/^[`']?[-+]?\s*(rp\.?\s*)?[\d.,]+\s*(%|m³|m3|l|liter|jam|rit|kg|hari|x)?$/i.test(t)) { const a = angka(t); if (!isNaN(a)) return a; }
            return t.toLowerCase();
        };
        let urutKolom = null, urutArah = 0; // 1 naik, -1 turun, 0 = urutan asli
        const ikonUrut = {};
        const urutkan = k => {
            urutArah = urutKolom === k ? (urutArah === 1 ? -1 : 0) : 1;
            urutKolom = urutArah ? k : null;
            Object.entries(ikonUrut).forEach(([i, s]) => { s.textContent = +i === urutKolom ? (urutArah === 1 ? '▲' : '▼') : '⇅'; s.classList.toggle('aktif', +i === urutKolom); });
            blokUrut().forEach(({wadah, isi}) => {
                isi.forEach((b, i) => { elUrut(b).dataset.urutAsal ??= i; }); // urutan asli dicatat sekali
                const asal = b => +elUrut(b).dataset.urutAsal;
                const kunci = new Map(isi.map(b => [b, urutKolom === null ? null : kunciUrut((grup ? b.querySelector('tr.t') : b.baris[0]).cells[urutKolom])]));
                const nilai = [...kunci.values()].filter(v => v !== null);
                const semuaAngka = nilai.every(v => typeof v === 'number');
                const banding = (a, b) => {
                    const x = kunci.get(a), y = kunci.get(b);
                    if (urutKolom === null || (x === null && y === null)) return asal(a) - asal(b);
                    if (x === null || y === null) return x === null ? 1 : -1;
                    const h = semuaAngka ? x - y : String(x).localeCompare(String(y), 'id', {numeric: true});
                    return h ? h * urutArah : asal(a) - asal(b);
                };
                // Baris judul ber-colspan di awal tbody tetap di atas; baris Total/Jumlah tetap di bawah.
                const atas = isi.filter(b => !grup && b.baris[0].cells.length <= 1);
                const bawah = isi.filter(tetap);
                const biasa = isi.filter(b => !atas.includes(b) && !bawah.includes(b)).sort(banding);
                [...atas, ...biasa, ...bawah].forEach(b => (grup ? [b] : b.baris).forEach(el => wadah.appendChild(el)));
            });
        };
        [...kepala.cells].forEach((th, i) => {
            if (!th.textContent.trim() || th.hasAttribute('data-tanpa-urut') || tabel.hasAttribute('data-tanpa-urut') || (th.colSpan || 1) > 1) return;
            th.classList.add('bisa-urut');
            th.title = (th.title ? th.title + ' — ' : '') + 'Klik untuk mengurutkan';
            const s = document.createElement('span');
            s.className = 'urut-ikon';
            s.textContent = '⇅';
            th.appendChild(s);
            ikonUrut[i] = s;
            th.addEventListener('click', e => {
                if (e.target.closest('a, button, input, select, textarea, label')) return;
                urutkan(i);
            });
        });

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
    };

    // Tabel yang dipasang otomatis: ber-thead (≥ 2 kolom), ≥ 5 baris data, bukan tabel isian form.
    const layak = t => {
        if (t.dataset.saringSiap || t.hasAttribute('data-tanpa-saring-kolom') || t.closest('template, .saring-pop') || t.parentElement?.closest('table')) return false;
        if (t.hasAttribute('data-saring-kolom')) return true;
        if (!t.tHead?.rows[0] || t.tHead.rows[0].cells.length < 2) return false;
        if (t.querySelector('tbody input:not([type=checkbox]):not([type=hidden]), tbody select, tbody textarea')) return false;
        const baris = t.querySelector(':scope > tbody.grup')
            ? t.querySelectorAll(':scope > tbody.grup > tr.t').length
            : [...t.tBodies].reduce((s, tb) => s + [...tb.rows].filter(r => r.cells.length > 1).length, 0);
        return baris >= 5;
    };
    // Judul beku & garis kolom: SEMUA tabel ber-judul (thead), termasuk form input & tabel kecil yang tidak diberi saringan.
    // Kecuali tabel di dalam tabel lain / template / popup saringan, atau ber-atribut data-tanpa-beku.
    const bisaBeku = t => !t.classList.contains('grid-beku') && !t.hasAttribute('data-tanpa-beku') && t.tHead?.rows[0]?.cells.length > 1
        && !t.closest('template, .saring-pop') && !t.parentElement?.closest('table');
    const pindai = () => document.querySelectorAll('main table, .isi table, table[data-saring-kolom]').forEach(t => {
        if (layak(t)) pasang(t);
        else if (bisaBeku(t)) bekukan(t);
    });
    // Tabel yang muncul belakangan (dibuat JavaScript, mis. panel rincian) ikut dibekukan.
    let tunda = null;
    new MutationObserver(daftar => {
        if (tunda || !daftar.some(m => [...m.addedNodes].some(x => x.nodeType === 1 && (x.tagName === 'TABLE' || x.querySelector?.('table'))))) return;
        tunda = setTimeout(() => { tunda = null; pindai(); }, 50);
    }).observe(document.documentElement, {childList: true, subtree: true});
    // ── Navigasi panah di SEMUA grid ─────────────────────────────────────────────────────────────────────────────────
    // Grid berisi isian: ↑↓ pindah ke isian kolom yang sama di baris atas/bawah; ←→ ke isian sebelah bila kursor di ujung teks.
    // Grid data: klik sel → sel terpilih; ←↑→↓ menggeser pilihan (baris tersembunyi/tersaring dilewati), Enter = klik isi sel,
    // Ctrl+C = salin isi sel, Esc = lepas. Halaman yang punya navigasi sendiri (preventDefault) didahulukan.
    // Pengecualian: tabel ber-atribut data-tanpa-navigasi.
    const gridNav = el => { const t = el?.closest?.('table.grid-beku'); return t && !t.hasAttribute('data-tanpa-navigasi') ? t : null; };
    // Baris judul rincian (tr.bh) & baris yang tersembunyi/tersaring dilewati.
    const barisTampil = t => [...t.tBodies].flatMap(tb => [...tb.rows]).filter(tr => tr.offsetParent !== null && tr.cells.length && !tr.classList.contains('bh'));
    // Posisi kolom visual (memperhitungkan colspan) ↔ sel.
    const kolomSel = td => { let k = 0; for (const c of td.parentElement.cells) { if (c === td) return k; k += c.colSpan || 1; } return k; };
    const selDiKolom = (tr, k) => { let x = 0; for (const c of tr.cells) { x += c.colSpan || 1; if (x > k) return c; } return tr.cells[tr.cells.length - 1]; };
    const ISIAN = 'input:not([type=hidden]):not(:disabled), select:not(:disabled), textarea:not(:disabled)';
    const isianDi = td => td?.querySelector(ISIAN);
    const tampilkan = (td, t) => {
        const atas = (t.tHead?.offsetHeight || 0) + (t.closest('.grid-gulir') ? 0 : (document.querySelector('header')?.offsetHeight || 0));
        td.style.scrollMarginTop = (atas + 4) + 'px';
        td.scrollIntoView({block: 'nearest', inline: 'nearest'});
    };
    const fokusIsian = (el, t) => { tampilkan(el.closest('td'), t); el.focus(); try { el.select(); } catch { /* bukan teks */ } };
    // Cari sel tujuan: baris atas/bawah pada kolom yang sama, atau sel kiri/kanan di baris yang sama.
    const tujuan = (td, t, arah, perluIsian) => {
        const tr = td.parentElement;
        if (arah === 'ArrowLeft' || arah === 'ArrowRight') {
            const sel = [...tr.cells];
            for (let i = sel.indexOf(td) + (arah === 'ArrowLeft' ? -1 : 1); i >= 0 && i < sel.length; i += arah === 'ArrowLeft' ? -1 : 1) {
                if (!perluIsian || isianDi(sel[i])) return sel[i];
            }
            return null;
        }
        const semua = barisTampil(t), k = kolomSel(td);
        for (let i = semua.indexOf(tr) + (arah === 'ArrowUp' ? -1 : 1); i >= 0 && i < semua.length; i += arah === 'ArrowUp' ? -1 : 1) {
            const c = selDiKolom(semua[i], k);
            if (!perluIsian || isianDi(c)) return c;
        }
        return null;
    };
    let aktif = null;
    const pilih = (td, t) => {
        aktif?.classList.remove('sel-aktif');
        aktif = td;
        if (!td) return;
        const isian = isianDi(td);
        if (isian) { aktif = null; fokusIsian(isian, t); return; }
        td.classList.add('sel-aktif');
        tampilkan(td, t);
    };
    document.addEventListener('click', e => {
        const td = e.target.closest?.('td');
        const t = gridNav(td);
        if (!t || td.closest('thead')) { if (!e.target.closest?.('.saring-pop')) pilih(null); return; }
        if (e.target.closest('a, button, input, select, textarea, label')) { pilih(null); return; }
        pilih(td, t);
    });
    document.addEventListener('keydown', e => {
        if (e.defaultPrevented || e.altKey || e.metaKey || e.isComposing) return;
        const el = e.target;
        const panah = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key);
        // Mode isian: kursor di dalam isian sel grid.
        if (el.matches?.(ISIAN) && el.closest('td')) {
            const t = gridNav(el);
            if (!t || !panah || e.ctrlKey || e.shiftKey) return;
            const td = el.closest('td');
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                if (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && !['checkbox', 'radio'].includes(el.type))) {
                    let a, b; try { [a, b] = [el.selectionStart, el.selectionEnd]; } catch { a = null; }
                    if (a !== null && a !== undefined) {
                        const semua = a === 0 && b === el.value.length;
                        const ujung = e.key === 'ArrowLeft' ? a === 0 && b === 0 : a === el.value.length && b === el.value.length;
                        if (!semua && !ujung) return;
                    }
                }
                // isian lain di sel yang sama dulu, baru sel sebelah
                const dlmSel = [...td.querySelectorAll(ISIAN)];
                const lain = dlmSel[dlmSel.indexOf(el) + (e.key === 'ArrowLeft' ? -1 : 1)];
                const sebelah = lain ? null : tujuan(td, t, e.key, true);
                const ke = lain || (sebelah && [...sebelah.querySelectorAll(ISIAN)].at(e.key === 'ArrowLeft' ? -1 : 0));
                if (ke) { e.preventDefault(); fokusIsian(ke, t); }
                return;
            }
            if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') return; // ↑↓ bawaan (ganti pilihan / pindah baris teks)
            const c = tujuan(td, t, e.key, true);
            e.preventDefault(); // ↑↓ tidak membuka daftar saran
            if (c) { el.dispatchEvent(new Event('blur')); fokusIsian(isianDi(c), t); }
            return;
        }
        // Mode sel: ada sel terpilih & kursor tidak sedang di isian lain.
        if (!aktif || !aktif.isConnected || aktif.offsetParent === null) { aktif = null; return; }
        if (el.matches?.('input, select, textarea, [contenteditable]')) return;
        const t = gridNav(aktif);
        if (!t) return;
        if (e.key === 'Escape') { pilih(null); return; }
        if (e.key === 'c' && e.ctrlKey && !String(getSelection())) {
            navigator.clipboard?.writeText(aktif.innerText.trim());
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            (aktif.querySelector('a, button') || aktif).click();
            return;
        }
        if (!panah || e.ctrlKey || e.shiftKey) return;
        const c = tujuan(aktif, t, e.key, false);
        e.preventDefault();
        if (c) pilih(c, t);
    });

    window.SaringKolom = {pindai, pasang};
    pindai();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', pindai);
})();
