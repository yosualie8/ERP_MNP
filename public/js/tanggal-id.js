/*
 * Isian tanggal berformat tetap "dd-MMM-yyyy" (mis. 09-Okt-2026) di semua komputer — <input type="date"> bawaan browser
 * selalu mengikuti bahasa/wilayah komputer sehingga tampilannya berbeda-beda.
 * Pakai: <input type="text" data-tanggal-id [data-maks="2026-10-09"]>. Di sampingnya dipasang tombol 📅 yang membuka
 * kalender bawaan (tersembunyi); ketikan bebas (9/10/26, 2026-10-09, 9 okt 2026) dirapikan saat kursor keluar.
 * Dimuat di layout: SEMUA <input type="date"> (juga yang dibuat skrip belakangan, mis. baris grid) otomatis diubah menjadi isian
 * dd-MMM-yyyy yang bisa diketik angka saja (12112026 = 12-Nov-2026), tetapi .value & data yang dikirim tetap yyyy-mm-dd sehingga
 * server dan skrip halaman tidak perlu diubah. Pengecualian: atribut data-tanggal-bawaan.
 */
window.TanggalId ??= (() => {
    const BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    const KENAL = {jan: 1, feb: 2, mar: 3, apr: 4, mei: 5, may: 5, jun: 6, jul: 7, agu: 8, agt: 8, aug: 8, sep: 9, okt: 10, oct: 10, nov: 11, des: 12, dec: 12};
    const dua = n => String(n).padStart(2, '0');

    /** Date → "09-Okt-2026". */
    const format = d => `${dua(d.getDate())}-${BULAN[d.getMonth()]}-${d.getFullYear()}`;

    /** Teks bebas → Date (null bila tidak dikenali). */
    const urai = v => {
        v = String(v || '').trim();
        let m, d, b, t;
        // Angka saja: ddmmyyyy (08102026), ddmmyy (081026), ddmm (0810 = tahun ini).
        if ((m = v.match(/^(\d{2})(\d{2})(\d{4}|\d{2})?$/))) [d, b, t] = [+m[1], +m[2], m[3] ? +m[3] : new Date().getFullYear()];
        else if ((m = v.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/))) [t, b, d] = [+m[1], +m[2], +m[3]];
        else if ((m = v.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})$/))) [d, b, t] = [+m[1], +m[2], +m[3]];
        else if ((m = v.match(/^(\d{1,2})[\s.\/-]*([a-z]{3})[a-z]*[\s.\/-]*(\d{2}|\d{4})$/i)) && KENAL[m[2].toLowerCase()]) [d, b, t] = [+m[1], KENAL[m[2].toLowerCase()], +m[3]];
        else return null;
        if (t < 100) t += 2000;
        const x = new Date(t, b - 1, d);
        return x.getFullYear() === t && x.getMonth() === b - 1 && x.getDate() === d ? x : null;
    };

    /** "yyyy-mm-dd" untuk kalender bawaan. */
    const iso = d => `${d.getFullYear()}-${dua(d.getMonth() + 1)}-${dua(d.getDate())}`;

    const pasang = el => {
        if (el.dataset.tanggalIdTerpasang) return;
        el.dataset.tanggalIdTerpasang = '1';
        el.type = 'text';
        el.autocomplete = 'off';
        el.placeholder ||= 'dd-MMM-yyyy';
        const maks = el.dataset.maks ? urai(el.dataset.maks) : null;
        const rapikan = () => {
            if (!el.value.trim()) { el.classList.remove('tanggal-salah'); el.title = ''; return; }
            const d = urai(el.value);
            const salah = !d || (maks && d > maks);
            if (d && !salah) el.value = format(d);
            el.classList.toggle('tanggal-salah', !!salah);
            el.title = !d ? 'Tanggal tidak dikenali — format dd-MMM-yyyy, mis. 09-Okt-2026' : salah ? 'Tanggal tidak boleh sesudah hari ini' : '';
        };
        el.addEventListener('blur', rapikan);
        rapikan();

        // Tombol kalender: membuka <input type="date"> tersembunyi, hasilnya ditulis dalam format dd-MMM-yyyy.
        const kal = document.createElement('input');
        kal.type = 'date';
        kal.dataset.tanggalBawaan = ''; // kalender tersembunyi jangan ikut diubah
        kal.tabIndex = -1;
        kal.setAttribute('aria-hidden', 'true');
        kal.style.cssText = 'position:absolute;opacity:0;width:1px;height:1px;pointer-events:none;';
        if (maks) kal.max = iso(maks);
        const tombol = document.createElement('button');
        tombol.type = 'button';
        tombol.className = 'tombol-kalender';
        tombol.textContent = '📅';
        tombol.title = 'Pilih dari kalender';
        tombol.addEventListener('click', () => {
            const d = urai(el.value);
            kal.value = d ? iso(d) : '';
            try { kal.showPicker(); } catch { kal.click(); }
        });
        kal.addEventListener('change', () => {
            const d = urai(kal.value);
            if (!d) return;
            el.value = format(d);
            el.classList.remove('tanggal-salah');
            el.dispatchEvent(new Event('input', {bubbles: true}));
        });
        const bungkus = document.createElement('span');
        bungkus.className = 'tanggal-id';
        el.replaceWith(bungkus);
        bungkus.append(el, tombol, kal);
    };

    /** Jam 24 jam "HH:mm": "7" → 07:00, "7.30" / "730" / "7:30" → 07:30. Null bila tidak dikenali. */
    const uraiJam = v => {
        v = String(v || '').trim();
        const m = v.match(/^(\d{1,2})(?:[:.\s]?(\d{2}))?$/);
        if (!m) return null;
        const j = +m[1], n = m[2] === undefined ? 0 : +m[2];
        return j <= 23 && n <= 59 ? `${dua(j)}:${dua(n)}` : null;
    };
    const pasangJam = el => {
        if (el.dataset.jamIdTerpasang) return;
        el.dataset.jamIdTerpasang = '1';
        el.type = 'text';
        el.autocomplete = 'off';
        el.inputMode = 'numeric';
        el.placeholder ||= 'HH:mm';
        const rapikan = () => {
            if (!el.value.trim()) { el.classList.remove('tanggal-salah'); el.title = ''; return; }
            const j = uraiJam(el.value);
            if (j) el.value = j;
            el.classList.toggle('tanggal-salah', !j);
            el.title = j ? '' : 'Jam tidak dikenali — format 24 jam HH:mm, mis. 07:30';
        };
        el.addEventListener('blur', rapikan);
        rapikan();
    };

    // ── <input type="date"> bawaan → isian dd-MMM-yyyy, .value tetap yyyy-mm-dd ─────────────────────────────────────────
    const ASLI = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
    const pasangBawaan = el => {
        if (el.dataset.tanggalIdTerpasang || el.hasAttribute('data-tanggal-bawaan')) return;
        el.dataset.tanggalIdTerpasang = '1';
        // Isian selebar wadahnya (mis. width: 100%) → bungkusnya juga selebar wadah.
        const cs = getComputedStyle(el), induk = el.parentElement;
        const penuh = el.offsetWidth ? !!induk && el.offsetWidth >= induk.clientWidth - parseFloat(getComputedStyle(induk).paddingLeft) - parseFloat(getComputedStyle(induk).paddingRight) - 2
            : cs.width === '100%';
        const awal = el.value;
        el.type = 'text';
        el.dataset.tanggalAsli = '1';
        el.autocomplete = 'off';
        el.inputMode = 'numeric';
        el.placeholder ||= 'dd-MMM-yyyy';
        const batas = a => (el.getAttribute(a) ? urai(el.getAttribute(a)) : null);
        const periksa = () => {
            const t = ASLI.get.call(el).trim();
            const d = t ? urai(t) : null, min = batas('min'), maks = batas('max');
            const pesan = !t ? '' : !d ? 'Tanggal tidak dikenali — ketik mis. 12112026 atau 12-Nov-2026'
                : maks && d > maks ? `Tanggal tidak boleh sesudah ${format(maks)}` : min && d < min ? `Tanggal tidak boleh sebelum ${format(min)}` : '';
            el.setCustomValidity(pesan);
            el.classList.toggle('tanggal-salah', !!pesan);
            el.title = pesan || 'Ketik angka saja, mis. 12112026 = 12-Nov-2026';
            return d;
        };
        Object.defineProperty(el, 'value', {
            configurable: true,
            get() { const d = urai(ASLI.get.call(this)); return d ? iso(d) : ''; },
            set(v) { const d = urai(v); ASLI.set.call(this, d ? format(d) : String(v ?? '')); periksa(); },
        });
        el.value = awal;
        el.addEventListener('input', periksa);
        el.addEventListener('blur', () => { const d = periksa(); if (d) ASLI.set.call(el, format(d)); });

        const kal = document.createElement('input');
        kal.type = 'date';
        kal.dataset.tanggalBawaan = ''; // kalender tersembunyi jangan ikut diubah
        kal.tabIndex = -1;
        kal.setAttribute('aria-hidden', 'true');
        kal.style.cssText = 'position:absolute;left:0;bottom:0;opacity:0;width:1px;height:1px;pointer-events:none;';
        const tombol = document.createElement('button');
        tombol.type = 'button';
        tombol.className = 'tombol-kalender';
        tombol.textContent = '📅';
        tombol.title = 'Pilih dari kalender';
        tombol.tabIndex = -1;
        tombol.addEventListener('click', () => {
            if (el.disabled || el.readOnly) return;
            kal.min = el.getAttribute('min') || '';
            kal.max = el.getAttribute('max') || '';
            kal.value = el.value;
            try { kal.showPicker(); } catch { kal.click(); }
        });
        kal.addEventListener('change', () => {
            if (!kal.value) return;
            el.value = kal.value;
            el.dispatchEvent(new Event('input', {bubbles: true}));
            el.dispatchEvent(new Event('change', {bubbles: true}));
        });
        const bungkus = document.createElement('span');
        bungkus.className = 'tanggal-id' + (penuh ? ' penuh' : '');
        el.replaceWith(bungkus);
        bungkus.append(el, tombol, kal);
    };
    // Data form (kirim biasa, form.submit(), new FormData(form)) memakai yyyy-mm-dd, bukan teks yang tampil — juga untuk isian
    // data-tanggal-id, supaya validasi 'date' Laravel tidak gagal pada nama bulan Indonesia (Okt, Agu, Des).
    document.addEventListener('formdata', e => {
        const nama = new Set([...(e.target.elements || [])].filter(x => x.dataset?.tanggalIdTerpasang && x.name).map(x => x.name));
        nama.forEach(n => {
            const isi = e.formData.getAll(n);
            e.formData.delete(n);
            isi.forEach(v => { const d = typeof v === 'string' ? urai(v) : null; e.formData.append(n, d ? iso(d) : v); });
        });
    }, true);

    const pasangSemua = (akar = document) => {
        akar.querySelectorAll('input[data-tanggal-id]').forEach(pasang);
        akar.querySelectorAll('input[data-jam-id]').forEach(pasangJam);
        akar.querySelectorAll('input[type=date]').forEach(pasangBawaan);
    };

    // Gaya bawaan (spesifisitas nol → gaya halaman tetap menang).
    const gaya = document.createElement('style');
    gaya.textContent = `
        :where(.tanggal-id) { display: inline-flex; align-items: center; gap: 4px; position: relative; max-width: 100%; vertical-align: middle; }
        :where(.tanggal-id.penuh) { display: flex; width: 100%; }
        :where(.tanggal-id) > :where(input) { flex: 1 1 auto; min-width: 0; }
        :where(.tanggal-id .tombol-kalender) { flex: none; background: none; border: 1px solid var(--garis, #4a5160); border-radius: 6px; padding: 3px 6px;
            cursor: pointer; font-size: 13px; line-height: 1; }
        :where(input.tanggal-salah) { border-color: #e5484d !important; }`;
    document.head.prepend(gaya);

    const mulai = () => {
        pasangSemua();
        new MutationObserver(daftar => daftar.forEach(m => m.addedNodes.forEach(x => {
            if (x.nodeType !== 1) return;
            if (x.matches('input[type=date]')) pasangBawaan(x);
            else if (x.querySelector('input[type=date]')) x.querySelectorAll('input[type=date]').forEach(pasangBawaan);
        }))).observe(document.body, {childList: true, subtree: true});
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mulai); else mulai();

    return {format, urai, uraiJam, pasang, pasangJam, pasangBawaan, pasangSemua, hariIni: () => format(new Date())};
})();
