{{--
    Potongan hutang sopir di Input UJ (owner 4 Nov 2026, App\Support\PotongHutangUj): catatan transfer bank / catatan biaya transfer / keterangan
    yang menyebut "potong" atau "MK" → Valen memilih potongannya masuk ke sopir mana, dari uang jalan DO mana, berapa. Wajib diisi bila ada pemicu,
    atau dicentang "Bukan potongan hutang sopir".
--}}
@php
    $catatanMutasi = collect($calonMutasi ?? [])->mapWithKeys(fn ($c) => [$c['id'] => trim(($c['catatan'] ?? '').' '.($c['catatan_biaya'] ?? ''))])->filter();
    $lamaPotong = array_values(array_filter((array) old('potong', []), 'is_array'));
@endphp
<style>
    #potong-hutang.wajib { border-color: #f0c05a; box-shadow: 0 0 0 1px #f0c05a inset; }
    #potong-hutang table { width: 100%; }
    #potong-hutang td, #potong-hutang th { padding: 4px 6px; }
    #potong-hutang select, #potong-hutang input[type=text] { width: 100%; box-sizing: border-box; }
</style>
<div class="kartu" id="potong-hutang">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
        <h3 style="margin: 0;">✂️ Potongan hutang sopir <span class="redup" style="font-size: 13px; font-weight: normal;">bila uang jalan ini dipotong untuk hutang / muatan kurang</span></h3>
        <button type="button" class="tombol polos" id="tambah-potong" style="padding: 5px 12px; font-size: 13px;">+ Tambah potongan</button>
    </div>
    <div class="pesan" id="pemicu-potong" style="margin: 8px 0 0; padding: 8px 12px;" hidden></div>
    <p class="redup" style="margin: 6px 0 8px; font-size: 13px;">
        <b>Potong hutang</b> = hutang sopir berkurang, jadi bagian uang jalan DO itu. <b>Muatan kurang (MK)</b> = hutang sopir bertambah karena muatan kurang
        dan langsung lunas karena uang jalan DO itu sudah ditransfer kurang. Tercatat di menu Hutang Supir; ikut terhapus bila transaksi ini dihapus.
    </p>
    <div class="gulir">
        <table class="bon" style="min-width: 760px;">
            <thead><tr><th style="width: 260px;">Uang jalan yang dipotong</th><th style="width: 190px;">Jenis</th><th>Sopir</th><th style="width: 140px;" class="angka">Nominal</th><th style="width: 30px;"></th></tr></thead>
            <tbody id="daftar-potong"></tbody>
        </table>
    </div>
    <label style="display: flex; gap: 6px; align-items: center; margin: 8px 0 0; font-size: 13px;" id="label-bukan" hidden>
        <input type="checkbox" name="potong_bukan" value="1" @checked(old('potong_bukan'))> Bukan potongan hutang sopir (kata "potong" di catatan untuk hal lain)
    </label>
</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const CATATAN = @json($catatanMutasi);
        const LAMA = @json($lamaPotong);
        const JENIS = @json(\App\Support\PotongHutangUj::JENIS);
        const PEMICU = /potong|\bMK\b/i;
        const kartu = document.getElementById('potong-hutang'), daftar = document.getElementById('daftar-potong');
        const pemicuEl = document.getElementById('pemicu-potong'), labelBukan = document.getElementById('label-bukan');
        const fmt = n => n ? new Intl.NumberFormat('id-ID').format(+n) : '';
        const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
        let nomor = 0;

        // Baris uang jalan ber-No DO di Transaksi detail saat ini.
        const barisUj = () => [...document.querySelectorAll('#daftar-bon > tr.baris-detail')].map(tr => {
            const v = n => tr.querySelector(`[data-nama=${n}]`)?.value.trim() ?? '';
            return {do: v('no_do').replace(/[`'\s]/g, ''), nama: v('nama'), mobil: v('no_mobil'), nominal: v('nominal').replace(/\D/g, ''), kategori: v('kategori')};
        }).filter(b => b.do && /jalan/i.test(b.kategori));
        const isiPilihan = (sel, nilai) => {
            const pilih = nilai ?? sel.value;
            sel.innerHTML = '<option value="">— pilih DO —</option>' + barisUj().map(b =>
                `<option value="${esc(b.do)}" data-nama="${esc(b.nama)}">DO ${esc(b.do)} · ${esc(b.nama || '?')} · ${esc(b.mobil)} · Rp${fmt(b.nominal)}</option>`).join('');
            if (pilih && ![...sel.options].some(o => o.value === pilih)) sel.insertAdjacentHTML('beforeend', `<option value="${esc(pilih)}">DO ${esc(pilih)} (tidak ada di detail)</option>`);
            sel.value = pilih || '';
        };
        const tambah = (isi = {}) => {
            const i = nomor++;
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><select name="potong[${i}][no_do]"></select></td>
                <td><select name="potong[${i}][jenis]">${Object.entries(JENIS).map(([k, l]) => `<option value="${k}">${esc(l)}</option>`).join('')}</select></td>
                <td><input type="text" name="potong[${i}][sopir]" list="daftar-nama" autocomplete="off" placeholder="nama sopir (bawaan: nama di baris DO)"></td>
                <td><input type="text" name="potong[${i}][nominal]" class="angka-input rupiah" inputmode="numeric" autocomplete="off"></td>
                <td><button type="button" class="hapus" title="Hapus potongan">×</button></td>`;
            daftar.appendChild(tr);
            const sel = tr.querySelector('select'), sopir = tr.querySelector('[name$="[sopir]"]'), nominal = tr.querySelector('[name$="[nominal]"]');
            isiPilihan(sel, isi.no_do);
            tr.querySelector('[name$="[jenis]"]').value = isi.jenis || 'hutang';
            sopir.value = isi.sopir || '';
            nominal.value = fmt(String(isi.nominal ?? '').replace(/\D/g, ''));
            sel.addEventListener('focus', () => isiPilihan(sel));
            sel.addEventListener('change', () => { if (!sopir.value) sopir.value = sel.selectedOptions[0]?.dataset.nama || ''; });
            nominal.addEventListener('input', () => { const d = nominal.value.replace(/\D/g, ''); nominal.value = fmt(d); });
            tr.querySelector('.hapus').addEventListener('click', () => { tr.remove(); periksa(); });
            return tr;
        };
        document.getElementById('tambah-potong').addEventListener('click', () => tambah().querySelector('select').focus());

        // Pemicu: catatan transfer bank (+ biaya transfer) yang dipilih + keterangan detail.
        let saranDipakai = false;
        const periksa = () => {
            const idM = document.getElementById('jago_mutasi_id')?.value;
            const teks = [CATATAN[idM] || '', ...[...document.querySelectorAll('#daftar-bon [data-nama=keterangan]')].map(x => x.value)].filter(t => PEMICU.test(t));
            const ada = teks.length > 0;
            kartu.classList.toggle('wajib', ada && !daftar.children.length);
            pemicuEl.hidden = !ada;
            labelBukan.hidden = !ada;
            if (ada) pemicuEl.innerHTML = `⚠ Catatan / keterangan menyebut potongan: <b>${esc(teks.join(' · '))}</b> — pilih potongannya ke sopir mana (wajib), atau centang "Bukan potongan hutang sopir".`;
            // Saran sekali: satu baris potongan dengan jenis & nominal dari catatan (mis. "potong MK 25000").
            if (ada && !saranDipakai && !daftar.children.length) {
                const t = teks.join(' ');
                const angka = (t.match(/potong[^0-9]{0,40}?(\d[\d.]{2,})/i) || [])[1];
                const uj = barisUj();
                tambah({jenis: /\bMK\b|muatan/i.test(t) ? 'muatan' : 'hutang', nominal: angka ? angka.replace(/\D/g, '') : '', no_do: uj.length === 1 ? uj[0].do : '', sopir: uj.length === 1 ? uj[0].nama : ''});
                saranDipakai = true;
                kartu.classList.remove('wajib');
            }
        };
        LAMA.forEach(p => tambah(p));
        saranDipakai = LAMA.length > 0;
        document.getElementById('jago_mutasi_id')?.addEventListener('change', periksa);
        document.addEventListener('input', e => { if (e.target.closest('#daftar-bon')) periksa(); });
        document.addEventListener('click', e => { if (e.target.closest('[data-pakai]')) setTimeout(periksa, 50); });
        setTimeout(periksa, 100);
    });
</script>
