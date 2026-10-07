/*
 * Tempel (Ctrl+V) blok sel dari Excel/Google Sheets ke tabel transaksi detail: mulai dari sel yang sedang aktif,
 * isi ke kanan (urutan kolom tabel) dan ke bawah; baris yang kurang ditambahkan otomatis. Tempel satu nilai biasa
 * (tanpa tab/baris baru) tetap berjalan seperti biasa.
 *
 * TempelTabel.pasang(tbody, {baris: () => [tr…], tambah: () => tr, setelah: (trDiisi) => …})
 */
window.TempelTabel = {
    /** Teks TSV dari clipboard Excel → array baris × sel (memahami sel berkutip yang berisi tab/baris baru). */
    urai(teks) {
        const baris = [];
        let sel = '', isi = [], kutip = false;
        teks = teks.replace(/\r\n?/g, '\n');
        for (let i = 0; i < teks.length; i++) {
            const c = teks[i];
            if (kutip) {
                if (c === '"' && teks[i + 1] === '"') { sel += '"'; i++; }
                else if (c === '"') kutip = false;
                else sel += c;
            } else if (c === '"' && sel === '') kutip = true;
            else if (c === '\t') { isi.push(sel); sel = ''; }
            else if (c === '\n') { isi.push(sel); baris.push(isi); isi = []; sel = ''; }
            else sel += c;
        }
        if (sel !== '' || isi.length) { isi.push(sel); baris.push(isi); }
        // Baris kosong di ujung (Excel selalu menambah baris baru di akhir salinan) dibuang.
        while (baris.length && baris[baris.length - 1].every(s => s.trim() === '')) baris.pop();
        return baris.map(r => r.map(s => s.trim()));
    },

    /** "Rp 1.300.000" / "1,300,000" / "1300000.00" → "1300000". */
    angka(teks) {
        let t = String(teks).replace(/[^\d.,-]/g, '');
        t = t.replace(/[.,]\d{1,2}$/, ''); // pecahan desimal (bukan pemisah ribuan) dibuang
        return t.replace(/\D/g, '');
    },

    pasang(tbody, opsi) {
        tbody.addEventListener('paste', e => {
            const el = e.target;
            if (!el.dataset?.nama) return;
            const teks = e.clipboardData?.getData('text/plain') ?? '';
            if (!/[\t\n]/.test(teks.replace(/\r?\n$/, ''))) return; // satu nilai → tempel biasa
            const data = TempelTabel.urai(teks);
            if (!data.length) return;
            e.preventDefault();

            const semua = opsi.baris();
            const trAwal = el.closest('tr');
            let idx = semua.indexOf(trAwal);
            const kolomAwal = [...trAwal.querySelectorAll('[data-nama]')].indexOf(el);
            const diisi = [];
            data.forEach(sel => {
                let tr = opsi.baris()[idx] ?? opsi.tambah();
                const kolom = [...tr.querySelectorAll('[data-nama]')];
                sel.forEach((nilai, j) => {
                    const input = kolom[kolomAwal + j];
                    if (!input) return; // kolom Excel lebih banyak dari kolom tabel → sisanya diabaikan
                    input.value = input.classList.contains('rupiah') ? TempelTabel.angka(nilai) : nilai;
                    input.classList.remove('otomatis', 'tebakan');
                    if (input.dataset.nama === 'kode_gl') input.dataset.otomatis = nilai ? '0' : '1';
                });
                diisi.push(tr);
                idx++;
            });
            // Format rupiah, jumlah, dan isian otomatis lain dihitung ulang lewat event input biasa.
            diisi.forEach(tr => tr.querySelectorAll('[data-nama]').forEach(input => input.dispatchEvent(new Event('input', {bubbles: true}))));
            opsi.setelah?.(diisi);
            const terakhir = diisi[diisi.length - 1]?.querySelectorAll('[data-nama]')[kolomAwal];
            terakhir?.focus();
        });
    },
};
