/*
 * Tebak Kode GL dari keterangan transaksi detail (+ PIC), langsung di browser (< 1 ms).
 * Model dari server (App\Support\ModelKodeGl::latih); rumus sama persis dengan ModelKodeGl::tebak().
 */
window.TebakKodeGl = (function () {
    const kata = teks => [...new Set(teks.toLowerCase().replace(/[^a-z0-9]+/g, ' ').split(' ')
        .filter(k => k.length >= 2 && !/^\d+$/.test(k)))];

    const fitur = (keterangan, pic) => {
        const f = kata(keterangan || '');
        const p = (pic || '').trim().toLowerCase();
        if (p) f.push('pic:' + p.replace(/\s+/g, '_'));
        return f;
    };

    // Keterangan menyebut tahap ("… ASG T117") dan kode tebakan bertahap → pakai tahap dari keterangan.
    const sesuaikanTahap = (kode, keterangan) => {
        const t = (keterangan || '').match(/\bT(\d{1,3})\b/i);
        return t && / T\d+$/.test(kode) ? kode.replace(/ T\d+$/, ' T' + t[1]) : kode;
    };

    function tebak(m, keterangan, pic, jumlah = 3) {
        const f = fitur(keterangan, pic).filter(x => m.kata[x]);
        if (!f.length) return [];
        const a = 0.5, n = m.kode.length;
        const semua = m.prior.reduce((s, x) => s + x, 0);
        const skor = m.prior.map(p => Math.log((p + 1) / (semua + n)));
        for (const x of f) {
            const c = new Map(m.kata[x]);
            for (let i = 0; i < n; i++) skor[i] += Math.log(((c.get(i) || 0) + a) / (m.total[i] + a * m.v));
        }
        const maks = Math.max(...skor);
        const p = skor.map(s => Math.exp(s - maks));
        const jml = p.reduce((s, x) => s + x, 0);
        return p.map((v, i) => [i, v / jml]).sort((x, y) => y[1] - x[1]).slice(0, jumlah)
            .map(([i, v]) => [sesuaikanTahap(m.kode[i], keterangan), v]);
    }

    return {tebak, kata};
})();
