/*
 * Isian Bank / e-wallet dengan saran dari daftar baku (App\Support\DaftarBank): ketik singkatan, nama lengkap, atau nama
 * e-wallet ("bca", "central asia", "gopay") → pilih → isian berisi kode baku (BCA, GoPay, …) supaya data rapi.
 * Teks yang tidak ada di daftar ditandai merah (server juga menolaknya).
 *
 * PilihBank.pasang(input, daftar, {sering: ['BCA', …], ubah: kode => …})
 */
window.PilihBank = {
    kunci(teks) {
        let t = String(teks || '').toLowerCase().trim()
            .replace(/\b(pt|tbk|persero)\b/g, ' ').replace(/[^a-z0-9]+/g, '');
        const tanpaBank = t.replace(/^bank/, '');
        return tanpaBank || t;
    },

    pasang(input, daftar, opsi = {}) {
        const sering = opsi.sering || [];
        const ubah = opsi.ubah || (() => {});
        const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
        const panel = document.createElement('div');
        panel.className = 'saran';
        panel.hidden = true;
        input.insertAdjacentElement('afterend', panel);
        input.parentElement.style.position = 'relative';
        const pesan = document.createElement('div');
        pesan.className = 'galat-isian';
        pesan.hidden = true;
        panel.insertAdjacentElement('afterend', pesan);
        let tampil = [], sorot = -1;

        const cari = q => {
            const k = PilihBank.kunci(q);
            if (!k) return daftar.filter(b => sering.includes(b.kode)).sort((a, b) => sering.indexOf(a.kode) - sering.indexOf(b.kode));
            return daftar.map(b => {
                const skor = b.kunci[0] === k ? 0 : b.kunci.includes(k) ? 1 : b.kunci.some(x => x.startsWith(k)) ? 2
                    : PilihBank.kunci(b.nama).includes(k) || b.kunci.some(x => x.includes(k)) ? 3 : -1;
                return {b, skor};
            }).filter(x => x.skor >= 0)
                .sort((x, y) => x.skor - y.skor || (sering.includes(y.b.kode) - sering.includes(x.b.kode)) || x.b.kode.localeCompare(y.b.kode))
                .map(x => x.b);
        };
        const tutup = () => { panel.hidden = true; sorot = -1; };
        const buka = () => {
            tampil = cari(input.value).slice(0, 8);
            if (!tampil.length) { tutup(); return; }
            panel.innerHTML = (input.value.trim() ? '' : '<div class="judul-saran">Sering dipakai</div>') + tampil.map((b, i) => `
                <button type="button" data-i="${i}"><span><b>${esc(b.kode)}</b> <span class="rek">— ${esc(b.nama)}</span></span>
                <span class="pakai">${b.jenis}</span></button>`).join('');
            panel.hidden = false;
            sorot = -1;
            panel.querySelectorAll('button').forEach(t => t.addEventListener('mousedown', e => { e.preventDefault(); pilih(tampil[+t.dataset.i]); }));
        };
        const tandai = () => {
            const ada = !input.value.trim() || daftar.some(b => b.kode === input.value);
            input.style.borderColor = ada ? '' : 'var(--merah)';
            pesan.hidden = ada;
            pesan.textContent = ada ? '' : `"${input.value}" tidak ada di daftar bank/e-wallet — pilih dari saran.`;
        };
        const pilih = b => {
            input.value = b.kode;
            tutup();
            tandai();
            ubah(b.kode);
        };
        // Dipanggil dari luar (mis. memilih rekening tersimpan): teks apa pun dirapikan ke kode baku bila dikenali.
        input.rapikan = () => {
            const k = PilihBank.kunci(input.value);
            const b = k && daftar.find(x => x.kunci.includes(k));
            if (b) input.value = b.kode;
            tandai();
        };

        input.setAttribute('autocomplete', 'off');
        input.addEventListener('input', () => { buka(); input.style.borderColor = ''; pesan.hidden = true; });
        input.addEventListener('focus', () => { buka(); input.select(); });
        input.addEventListener('blur', () => { tutup(); input.rapikan(); ubah(input.value); });
        input.addEventListener('keydown', e => {
            if (panel.hidden) return;
            const tombol = panel.querySelectorAll('button');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                sorot = (sorot + (e.key === 'ArrowDown' ? 1 : -1) + tombol.length) % tombol.length;
                tombol.forEach((t, i) => t.classList.toggle('sorot', i === sorot));
            } else if ((e.key === 'Enter' || e.key === 'Tab') && (sorot >= 0 || tampil.length === 1)) {
                if (e.key === 'Enter') e.preventDefault();
                pilih(tampil[Math.max(sorot, 0)]);
            } else if (e.key === 'Escape') {
                tutup();
            }
        });
        if (input.value) input.rapikan();
        return input;
    },
};
