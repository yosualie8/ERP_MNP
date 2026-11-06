{{--
    Tombol #unduh-excel + progress bar Download Excel yang dibuat di latar belakang (owner 6 Nov 2026; pola sama dengan Kas Harian).
    Variabel: $aksi (URL POST mulai, berisi saringan halaman di query), $status (URL status dengan "__T__" untuk token),
    $judul (nama di kotak progres), $baris (selector baris grid ber-data-id; yang tampil saja yang dikirim sebagai "ids"), $kata (mis. "rit").
--}}
<form method="POST" action="{{ $aksi }}" id="form-unduh" hidden>@csrf</form>
<div id="kemajuan-unduh" class="kartu" hidden style="position: fixed; right: 20px; bottom: 20px; z-index: 50; width: 340px; padding: 12px 14px; box-shadow: 0 4px 18px rgba(0,0,0,.25);">
    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;"><b>⬇ Download Excel {{ $judul }}</b><b class="angka-persen">0%</b></div>
    <div style="height: 10px; border-radius: 5px; background: var(--garis, #ddd); overflow: hidden;"><div class="isi" style="height: 100%; width: 0; background: var(--biru, #2f6fd1); transition: width .4s;"></div></div>
    <div class="pesan-unduh redup" style="font-size: 12px; margin-top: 6px;"></div>
</div>
<script>
    (() => {
        const tombol = document.getElementById('unduh-excel'), kotak = document.getElementById('kemajuan-unduh');
        if (!tombol) return;
        const isi = kotak.querySelector('.isi'), angka = kotak.querySelector('.angka-persen'), pesan = kotak.querySelector('.pesan-unduh');
        const tampilkan = (persen, teks, galat = false) => {
            kotak.hidden = false;
            isi.style.width = persen + '%';
            isi.style.background = galat ? 'var(--merah)' : '';
            angka.textContent = galat ? '' : persen + '%';
            pesan.textContent = teks;
        };
        tombol.addEventListener('click', async () => {
            const form = document.getElementById('form-unduh');
            // Baris yang tampil = lolos saringan kolom (baris tersaring disembunyikan dengan display:none).
            const tampil = [...document.querySelectorAll(@json($baris))].filter(tr => tr.style.display !== 'none');
            if (!tampil.length) { alert('Tidak ada baris yang tampil di grid.'); return; }
            const data = new FormData(form);
            data.set('ids', tampil.map(tr => tr.dataset.id).join(','));
            tombol.disabled = true;
            tampilkan(0, `Menyiapkan ${tampil.length.toLocaleString('id-ID')} {{ $kata }}…`);
            try {
                const r = await fetch(form.action, {method: 'POST', body: data, headers: {Accept: 'application/json'}});
                if (!r.ok) throw new Error('server menolak (' + r.status + ')');
                const {token} = await r.json();
                const url = @json($status).replace('__T__', token);
                for (;;) {
                    await new Promise(ok => setTimeout(ok, 800));
                    const x = await (await fetch(url, {headers: {Accept: 'application/json'}})).json();
                    if (x.galat) { tampilkan(100, x.galat, true); break; }
                    tampilkan(x.persen ?? 0, x.pesan ?? '');
                    if (x.siap) {
                        tampilkan(100, 'Selesai — file diunduh.');
                        window.location = x.url;
                        setTimeout(() => { kotak.hidden = true; }, 4000);
                        break;
                    }
                }
            } catch (e) {
                tampilkan(100, 'Gagal: ' + e.message, true);
            } finally {
                tombol.disabled = false;
            }
        });
    })();
</script>
