{{-- Gaya & buka-tutup bersama halaman Performa DT (ringkasan semua DT dan rincian satu DT). --}}
@include('dashboard.gaya')
<style>
    .lr .untung { color: var(--sukses); }
    .lr .rugi { color: var(--merah); }
    /* Owner 30 Okt 2026: semua grid di halaman ini rata tengah. */
    .lr table.kas th, .lr table.kas td, .lr table.kas td.angka, .lr table.kas th.angka { text-align: center; }
    .lr tr.total td { font-weight: 700; border-top: 2px solid var(--garis-kuat); background: var(--kartu-2); }
    .lr tr.pilih > td { background: var(--aksen-muda); }
    .lr td.nol { color: var(--redup); }
    .lr a.dt { font-weight: 700; }
    .peringatan-lr { border-left: 3px solid #f5a524; font-size: 13px; }
    /* Rincian satu DT: detail 1 di atas, detail 2 di bawahnya. Di dalam grid master: width 0 + min-width 100% → tidak melebarkan grid. */
    .rinci-dt { display: flex; flex-direction: column; gap: 18px; }
    tr.d .rinci-dt { width: 0; min-width: 100%; }
    .rinci-dt > .detail-1 { max-width: 900px; }
    .rinci-dt h5 { margin: 0 0 6px; font-size: 12px; color: var(--aksen-terang); text-transform: uppercase; letter-spacing: .04em; }
    .rinci-dt table { width: 100%; }
    .rinci-dt .buka-tutup { display: flex; gap: 14px; align-items: baseline; padding: 8px 12px; margin-top: 6px; border-radius: 6px; background: var(--kartu-2);
        border: 1px solid var(--garis); cursor: pointer; font-weight: 700; user-select: none; }
    .rinci-dt .buka-tutup:hover { border-color: var(--aksen); }
    .rinci-dt .buka-tutup::before { content: '▸'; color: var(--aksen-terang); }
    .rinci-dt .buka-tutup.buka::before { content: '▾'; }
    .rinci-dt .buka-tutup .ket { font-weight: 400; font-size: 12px; color: var(--redup); }
    .rinci-dt .buka-tutup .nilai { margin-left: auto; font-variant-numeric: tabular-nums; }
    .rinci-dt .laba-dt { display: flex; padding: 8px 12px; margin-top: 6px; font-weight: 700; border-top: 2px solid var(--garis-kuat); }
    .rinci-dt .laba-dt .nilai { margin-left: auto; }
    .rinci-dt .anak { margin-top: 4px; overflow: auto; max-height: 520px; }
    .rinci-dt .anak[hidden] { display: none; }
</style>
<script>
    // Baris ▸ di rincian DT → buka / tutup grid di bawahnya. Grid rincian diberi saringan & urut per kolom (juga yang dimuat belakangan
    // di dalam grid master, yang tidak dipasang otomatis oleh saring-kolom.js karena berada di dalam tabel lain).
    document.addEventListener('click', e => {
        const k = e.target.closest('.rinci-dt .buka-tutup');
        if (!k) return;
        const buka = k.classList.toggle('buka');
        k.closest('.rinci-dt').querySelectorAll(`[data-anak="${k.dataset.grup}"]`).forEach(x => { x.hidden = !buka; });
    });
    window.pasangGridRinci = wadah => wadah.querySelectorAll('table[data-saring-kolom]').forEach(t => window.SaringKolom?.pasang(t));
</script>
