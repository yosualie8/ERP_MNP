/*
 * Penampil foto bon bergaya Photoshop: roda mouse = zoom di titik kursor, seret = geser,
 * cubit dua jari = zoom (HP), klik ganda = Pas ↔ 100%, tombol − + Pas 100% Putar Layar penuh.
 *
 * Pemakaian: <div class="penampil" data-src="url-foto"></div> lalu PenampilFoto.pasang(div)
 * (otomatis untuk semua .penampil saat halaman dimuat). Ganti foto: div.penampil.tampilkan(url).
 */
(function () {
    const MIN = 0.05, MAKS = 8;

    function pasang(wadah) {
        if (wadah.tampilkan) return wadah;
        wadah.innerHTML = `
            <div class="penampil-alat">
                <button type="button" data-aksi="kecil" title="Perkecil (−)">−</button>
                <span class="penampil-persen">–</span>
                <button type="button" data-aksi="besar" title="Perbesar (+)">+</button>
                <button type="button" data-aksi="pas" title="Pas di layar (0)">Pas</button>
                <button type="button" data-aksi="asli" title="Ukuran asli (1)">100%</button>
                <button type="button" data-aksi="putar" title="Putar 90° (R)">⟳</button>
                <button type="button" data-aksi="layar" title="Layar penuh (F)">⛶</button>
            </div>
            <div class="penampil-kanvas" tabindex="0"><img alt="Foto bon" draggable="false"></div>
            <div class="penampil-petunjuk">Roda mouse / cubit = zoom · seret = geser · klik ganda = Pas ↔ 100%</div>`;
        const kanvas = wadah.querySelector('.penampil-kanvas');
        const img = kanvas.querySelector('img');
        const persen = wadah.querySelector('.penampil-persen');
        let s = 1, x = 0, y = 0, r = 0, w = 0, h = 0, sudahPas = true;

        const terapkan = () => {
            img.style.transform = `translate(${x}px, ${y}px) scale(${s}) translate(${w / 2}px, ${h / 2}px) rotate(${r}deg) translate(${-w / 2}px, ${-h / 2}px)`;
            persen.textContent = Math.round(s * 100) + '%';
        };
        // Ukuran gambar setelah diputar, dan posisi kotak itu relatif terhadap pojok gambar asli.
        const kotak = () => {
            const tegak = r % 180 !== 0;
            const ew = tegak ? h : w, eh = tegak ? w : h;
            return {ew, eh, bx: w / 2 - ew / 2, by: h / 2 - eh / 2};
        };
        const pas = () => {
            if (!w) return;
            const {ew, eh, bx, by} = kotak();
            const cw = kanvas.clientWidth, ch = kanvas.clientHeight;
            s = Math.min(cw / ew, ch / eh, 4);
            x = (cw - ew * s) / 2 - bx * s;
            y = (ch - eh * s) / 2 - by * s;
            sudahPas = true;
            terapkan();
        };
        const zoomDi = (skalaBaru, px, py) => {
            skalaBaru = Math.min(MAKS, Math.max(MIN, skalaBaru));
            x = px - (px - x) * (skalaBaru / s);
            y = py - (py - y) * (skalaBaru / s);
            s = skalaBaru;
            sudahPas = false;
            terapkan();
        };
        const tengah = () => [kanvas.clientWidth / 2, kanvas.clientHeight / 2];

        img.addEventListener('load', () => { w = img.naturalWidth; h = img.naturalHeight; r = 0; pas(); });

        kanvas.addEventListener('wheel', e => {
            e.preventDefault();
            const b = kanvas.getBoundingClientRect();
            zoomDi(s * Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.0015)), e.clientX - b.left, e.clientY - b.top);
        }, {passive: false});

        // Seret satu jari/mouse = geser; dua jari = cubit untuk zoom.
        const titik = new Map();
        let cubitAwal = null;
        kanvas.addEventListener('pointerdown', e => {
            kanvas.setPointerCapture(e.pointerId);
            titik.set(e.pointerId, {x: e.clientX, y: e.clientY});
            kanvas.classList.add('menyeret');
            if (titik.size === 2) {
                const [a, b] = [...titik.values()];
                cubitAwal = {jarak: Math.hypot(a.x - b.x, a.y - b.y), s};
            }
        });
        kanvas.addEventListener('pointermove', e => {
            const lama = titik.get(e.pointerId);
            if (!lama) return;
            titik.set(e.pointerId, {x: e.clientX, y: e.clientY});
            if (titik.size === 1) {
                x += e.clientX - lama.x;
                y += e.clientY - lama.y;
                sudahPas = false;
                terapkan();
            } else if (titik.size === 2 && cubitAwal) {
                const [a, b] = [...titik.values()];
                const bx = kanvas.getBoundingClientRect();
                zoomDi(cubitAwal.s * Math.hypot(a.x - b.x, a.y - b.y) / cubitAwal.jarak, (a.x + b.x) / 2 - bx.left, (a.y + b.y) / 2 - bx.top);
            }
        });
        const lepas = e => {
            titik.delete(e.pointerId);
            if (titik.size < 2) cubitAwal = null;
            if (!titik.size) kanvas.classList.remove('menyeret');
        };
        kanvas.addEventListener('pointerup', lepas);
        kanvas.addEventListener('pointercancel', lepas);
        kanvas.addEventListener('dblclick', e => {
            const b = kanvas.getBoundingClientRect();
            sudahPas ? zoomDi(1, e.clientX - b.left, e.clientY - b.top) : pas();
        });

        const aksi = {
            kecil: () => zoomDi(s / 1.25, ...tengah()),
            besar: () => zoomDi(s * 1.25, ...tengah()),
            pas,
            asli: () => zoomDi(1, ...tengah()),
            putar: () => { r = (r + 90) % 360; pas(); },
            layar: () => document.fullscreenElement === wadah ? document.exitFullscreen() : wadah.requestFullscreen?.(),
        };
        wadah.querySelector('.penampil-alat').addEventListener('click', e => {
            const t = e.target.closest('[data-aksi]');
            if (t) aksi[t.dataset.aksi]();
        });
        kanvas.addEventListener('keydown', e => {
            const k = {'+': 'besar', '=': 'besar', '-': 'kecil', '0': 'pas', '1': 'asli', r: 'putar', R: 'putar', f: 'layar', F: 'layar'}[e.key];
            if (k) { e.preventDefault(); aksi[k](); }
        });
        // Ukuran wadah berubah (layar penuh, jendela diubah): tetap pas bila sebelumnya pas.
        new ResizeObserver(() => sudahPas && pas()).observe(kanvas);

        wadah.tampilkan = src => {
            wadah.classList.toggle('kosong', !src);
            if (src) img.src = src; else img.removeAttribute('src');
        };
        wadah.tampilkan(wadah.dataset.src || '');
        return wadah;
    }

    window.PenampilFoto = {pasang};
    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.penampil').forEach(pasang));
})();
