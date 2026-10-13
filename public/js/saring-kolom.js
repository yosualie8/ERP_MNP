/**
 * Saringan per kolom untuk tabel ber-atribut data-saring-kolom: baris kotak saring di bawah judul kolom.
 * - data-saring-kolom=""     : tiap <tr> di tbody satu baris data.
 * - data-saring-kolom="grup" : tiap <tbody> satu grup (baris master tr.t + detail tr.b); grup cocok bila tiap saringan
 *                              cocok di master ATAU salah satu detailnya (yang cocok di detail → grup dibuka).
 * Teks: "mengandung" (huruf besar/kecil diabaikan). Angka: boleh ">100000", "<= 50.000".
 */
(() => {
    const gaya = document.createElement('style');
    gaya.textContent = `
        tr.saring-kolom th { padding: 4px !important; background: var(--kartu, #1e2229); }
        tr.saring-kolom input { width: 100%; min-width: 64px; box-sizing: border-box; padding: 4px 6px; font-size: 12px; border-radius: 5px; font-weight: 400; }
        .saring-info { display: flex; gap: 10px; align-items: center; padding: 8px 12px; font-size: 13px; }
        .saring-info[hidden] { display: none; }`;
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

    document.querySelectorAll('table[data-saring-kolom]').forEach(tabel => {
        const grup = tabel.dataset.saringKolom === 'grup';
        const kepala = tabel.tHead.rows[0];
        const barisSaring = document.createElement('tr');
        barisSaring.className = 'saring-kolom';
        const isian = [];
        [...kepala.cells].forEach((th, i) => {
            const sel = document.createElement('th');
            if (th.textContent.trim()) {
                const x = document.createElement('input');
                x.type = 'search';
                x.placeholder = th.classList.contains('angka') ? 'mis. >100000' : 'Saring…';
                x.dataset.kolom = i;
                x.title = 'Saring kolom ' + th.textContent.trim();
                sel.appendChild(x);
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
        info.querySelector('button').addEventListener('click', () => { isian.forEach(x => x.value = ''); terapkan(); });

        const unit = () => grup
            ? [...tabel.tBodies].filter(tb => tb.querySelector('tr.t'))
            : [...tabel.tBodies].flatMap(tb => [...tb.rows]).filter(tr => tr.cells.length > 1);
        const terapkan = () => {
            const aktif = isian.filter(x => x.value.trim());
            const semua = unit();
            let n = 0;
            semua.forEach(u => {
                const master = grup ? u.querySelector('tr.t') : u;
                // Detail hanya dibandingkan bila kolomnya sejajar dengan judul (tanpa colspan).
                const detail = grup ? [...u.querySelectorAll('tr.b')].filter(r => r.cells.length === kepala.cells.length) : [];
                let lewatDetail = false;
                const ok = aktif.every(x => {
                    const k = +x.dataset.kolom;
                    if (master.cells[k] && cocok(master.cells[k].textContent, x.value)) return true;
                    const d = detail.some(r => r.cells[k] && cocok(r.cells[k].textContent, x.value));
                    lewatDetail ||= d;
                    return d;
                });
                u.style.display = ok ? '' : 'none';
                if (ok && lewatDetail) u.classList.add('buka');
                n += ok;
            });
            info.hidden = !aktif.length;
            info.querySelector('span').textContent = `${n} dari ${semua.length} ${grup ? 'transaksi' : 'baris'} cocok dengan saringan kolom`;
        };
        isian.forEach(x => x.addEventListener('input', terapkan));
    });
})();
