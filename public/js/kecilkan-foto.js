/*
 * Perkecil foto bon di browser sebelum diunggah: sisi terpanjang 2000 px, JPEG mutu 0,85.
 * Foto HP 3–6 MB biasanya jadi 250–500 KB, tulisan nota tetap terbaca saat di-zoom.
 * Bila browser tidak bisa membaca formatnya (mis. HEIC), file asli dipakai apa adanya.
 */
window.kecilkanFoto = async function (file, maks = 2000, mutu = 0.85) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif') return file;
    let gambar;
    try {
        gambar = await createImageBitmap(file, {imageOrientation: 'from-image'});
    } catch {
        try {
            gambar = await new Promise((ok, gagal) => {
                const img = new Image();
                img.onload = () => ok(img);
                img.onerror = gagal;
                img.src = URL.createObjectURL(file);
            });
        } catch {
            return file;
        }
    }
    const lebar = gambar.width, tinggi = gambar.height;
    const skala = Math.min(1, maks / Math.max(lebar, tinggi));
    if (skala === 1 && file.type === 'image/jpeg' && file.size < 700 * 1024) return file;

    const kanvas = document.createElement('canvas');
    kanvas.width = Math.round(lebar * skala);
    kanvas.height = Math.round(tinggi * skala);
    const ctx = kanvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, kanvas.width, kanvas.height);
    ctx.drawImage(gambar, 0, 0, kanvas.width, kanvas.height);
    const blob = await new Promise(ok => kanvas.toBlob(ok, 'image/jpeg', mutu));
    if (!blob || blob.size >= file.size) return file;

    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', {type: 'image/jpeg', lastModified: Date.now()});
};
