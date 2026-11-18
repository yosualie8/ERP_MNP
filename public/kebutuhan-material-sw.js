/*
 * Service worker menu Kebutuhan Material (owner 18 Nov 2026): sinyal di proyek jelek → halaman tetap bisa dibuka ulang tanpa sinyal.
 * Cakupan hanya /kebutuhan-material (didaftarkan dari halaman itu). Halaman & data: jaringan dulu (batas 6 detik), bila gagal pakai salinan
 * terakhir (skrip /js/… juga, supaya versi skrip selalu sama dengan halaman). POST (kirim rekaman) tidak disentuh — antrian ada di IndexedDB halaman.
 */
const CACHE = 'mnp-kebutuhan-material-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil((async () => {
    for (const k of await caches.keys()) if (k.startsWith('mnp-kebutuhan-material-') && k !== CACHE) await caches.delete(k);
    await self.clients.claim();
})()));

const jaringanDulu = async (req, kunciCadangan) => {
    const cache = await caches.open(CACHE);
    const ambil = fetch(req).then(r => {
        // jangan simpan halaman login (sesi habis) / galat
        if (r.ok && !r.redirected && r.type === 'basic') cache.put(req, r.clone());
        return r;
    });
    const batas = new Promise(res => setTimeout(res, 6000));
    try {
        const r = await Promise.race([ambil, batas]);
        if (r) return r;
    } catch (e) { /* jaringan gagal → pakai salinan */ }
    const salinan = await cache.match(req) || (kunciCadangan && await cache.match(kunciCadangan, {ignoreSearch: true}));
    if (salinan) return salinan;
    return ambil; // belum pernah tersimpan: tunggu jaringan (atau galat jaringan biasa)
};

self.addEventListener('fetch', e => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;
    if (/\/audio$/.test(url.pathname) || url.pathname.endsWith('/token')) return; // audio & token selalu langsung
    if (req.mode === 'navigate' && url.pathname.startsWith('/kebutuhan-material')) {
        e.respondWith(jaringanDulu(req, new URL('/kebutuhan-material', location.origin).href));
    } else if (url.pathname.startsWith('/kebutuhan-material/data')) {
        e.respondWith(jaringanDulu(req));
    } else if (/^\/(js|css|build|favicon)/.test(url.pathname)) {
        e.respondWith(jaringanDulu(req, req.url)); // ?v= berubah tiap rilis → cadangan cocokkan tanpa query
    }
});
