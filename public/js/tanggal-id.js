/*
 * Isian tanggal berformat tetap "dd-MMM-yyyy" (mis. 09-Okt-2026) di semua komputer — <input type="date"> bawaan browser
 * selalu mengikuti bahasa/wilayah komputer sehingga tampilannya berbeda-beda.
 * Pakai: <input type="text" data-tanggal-id [data-maks="2026-10-09"]>. Di sampingnya dipasang tombol 📅 yang membuka
 * kalender bawaan (tersembunyi); ketikan bebas (9/10/26, 2026-10-09, 9 okt 2026) dirapikan saat kursor keluar.
 */
window.TanggalId = (() => {
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

    const pasangSemua = (akar = document) => {
        akar.querySelectorAll('input[data-tanggal-id]').forEach(pasang);
        akar.querySelectorAll('input[data-jam-id]').forEach(pasangJam);
    };

    return {format, urai, uraiJam, pasang, pasangJam, pasangSemua, hariIni: () => format(new Date())};
})();
