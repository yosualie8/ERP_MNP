@extends('layouts.app', ['judul' => 'Input Kas'])

@section('lebar', '1100px')

@section('isi')
    <style>
        .form-kas label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .form-kas input[type=text], .form-kas input[type=date], .form-kas input[type=number] {
            width: 100%; padding: 8px 10px; border: 1px solid var(--garis); border-radius: 7px; font-size: 14px; background: #fff; }
        .form-kas input.angka-input { text-align: right; font-variant-numeric: tabular-nums; }
        .baris2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .pilihan { display: inline-flex; border: 1px solid var(--garis); border-radius: 8px; overflow: hidden; }
        .pilihan input { display: none; }
        .pilihan label { margin: 0; padding: 8px 16px; cursor: pointer; color: var(--teks); font-size: 14px; }
        .pilihan input:checked + label { background: var(--aksen); color: #fff; }
        table.bon td { padding: 4px; vertical-align: top; border-bottom: 0; }
        table.bon th { padding: 4px; }
        .hapus { background: none; border: 0; color: var(--merah); cursor: pointer; font-size: 18px; line-height: 1; padding: 8px 6px; }
        .total-bon { font-size: 18px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .galat-isian { color: var(--merah); font-size: 13px; margin: 4px 0 0; }
        .isian-saran { position: relative; }
        .saran { position: absolute; left: 0; right: 0; top: 100%; z-index: 20; margin-top: 2px; background: #fff; border: 1px solid var(--garis);
            border-radius: 8px; box-shadow: 0 8px 24px rgba(16, 42, 67, .12); max-height: 340px; overflow-y: auto; }
        .saran.menetap { position: static; box-shadow: none; margin-top: 6px; border-color: var(--aksen); }
        .saran .judul-saran { padding: 6px 12px; font-size: 12px; color: var(--redup); background: var(--latar); border-bottom: 1px solid var(--garis); }
        .saran button { display: flex; width: 100%; justify-content: space-between; gap: 12px; align-items: baseline; text-align: left;
            padding: 8px 12px; border: 0; border-bottom: 1px solid #f1f3f5; background: none; cursor: pointer; font: inherit; color: var(--teks); }
        .saran button:last-child { border-bottom: 0; }
        .saran button.sorot, .saran button:hover { background: var(--hijau-muda); }
        .saran .rek { font-size: 13px; color: var(--redup); }
        .saran .rek b { color: var(--teks); font-weight: 600; font-variant-numeric: tabular-nums; }
        .saran .pakai { font-size: 11px; color: var(--redup); white-space: nowrap; }
        .saran mark { background: #fff3bf; color: inherit; padding: 0; }
    </style>

    <form method="POST" action="{{ route('kas.input.store') }}" class="form-kas" id="form-kas">
        @csrf
        @if ($errors->any())
            <div class="pesan galat">
                <b>Periksa lagi isian berikut:</b>
                <ul style="margin: 6px 0 0; padding-left: 18px;">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="kartu">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;">
                <h3 style="margin: 0;">Input kas Bank Jago</h3>
                <div class="pilihan">
                    <input type="radio" name="arah" id="arah-keluar" value="keluar" @checked(old('arah', 'keluar') === 'keluar')>
                    <label for="arah-keluar">Transfer keluar</label>
                    <input type="radio" name="arah" id="arah-masuk" value="masuk" @checked(old('arah') === 'masuk')>
                    <label for="arah-masuk">Uang masuk</label>
                </div>
            </div>

            <div class="baris2">
                <div>
                    <label for="tanggal">Tanggal</label>
                    <input type="date" name="tanggal" id="tanggal" value="{{ old('tanggal', now()->toDateString()) }}" required>
                </div>
                <div style="grid-column: span 2;" class="isian-saran">
                    <label for="nama_tujuan"><span data-keluar>Nama rekening tujuan</span><span data-masuk hidden>Dari (nama pengirim)</span></label>
                    <input type="text" name="nama_tujuan" id="nama_tujuan" value="{{ old('nama_tujuan') }}" autocomplete="off" required
                           placeholder="Ketik nama, pilih rekeningnya dari daftar">
                    <div class="saran" id="saran-nama" hidden></div>
                </div>
            </div>
            <div class="baris2">
                <div class="isian-saran">
                    <label for="no_rek">No. rekening</label>
                    <input type="text" name="no_rek" id="no_rek" value="{{ old('no_rek') }}" inputmode="numeric" autocomplete="off"
                           placeholder="Atau ketik nomornya">
                    <div class="saran" id="saran-norek" hidden></div>
                </div>
                <div style="grid-column: span 2;">
                    <label for="bank">Bank</label>
                    <input type="text" name="bank" id="bank" value="{{ old('bank') }}" autocomplete="off" style="max-width: 200px; margin-bottom: 6px;">
                    <div>
                        @foreach ($bank as $b)
                            <a href="#" class="chip" data-bank="{{ $b }}">{{ $b }}</a>
                        @endforeach
                    </div>
                </div>
            </div>
            <div>
                <label for="keterangan">Keterangan transfer <span class="redup">(seperti kolom Keterangan di sheet)</span></label>
                <input type="text" name="keterangan" id="keterangan" value="{{ old('keterangan') }}" autocomplete="off">
            </div>

            <div data-masuk hidden style="margin-top: 12px; max-width: 260px;">
                <label for="nominal_masuk">Nominal masuk</label>
                <input type="number" name="nominal_masuk" id="nominal_masuk" class="angka-input" min="1" value="{{ old('nominal_masuk') }}">
            </div>
            <div data-keluar style="margin-top: 12px; max-width: 260px;">
                <label for="nominal_transfer">Nominal transfer <span class="redup">(sesuai mutasi bank)</span></label>
                <input type="number" name="nominal_transfer" id="nominal_transfer" class="angka-input" min="1" value="{{ old('nominal_transfer') }}" required>
            </div>
        </div>

        <div class="kartu" data-keluar>
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px;">
                <h3 style="margin: 0 0 4px;">Rincian bon</h3>
                <span class="redup">Jumlah bon <span class="total-bon" id="total-bon">0</span> dari transfer <b id="nilai-transfer">0</b></span>
            </div>
            <div id="status-cocok" class="pesan" style="margin: 8px 0 10px; padding: 8px 12px;"></div>
            <p class="redup" style="margin: 0 0 10px;">Satu transfer bisa berisi beberapa bon (mis. reimburse). Kode GL ditulis seperti di sheet, mis. <i>Biaya BBM ASG</i> atau <i>Gaji Karyawan ASG T116</i>.</p>
            <table class="bon">
                <thead>
                    <tr>
                        <th style="width: 150px;" class="angka">Nominal</th>
                        <th style="width: 130px;">PIC</th>
                        <th>Keterangan bon</th>
                        <th style="width: 270px;">Kode GL</th>
                        <th style="width: 30px;"></th>
                    </tr>
                </thead>
                <tbody id="daftar-bon"></tbody>
            </table>
            <button type="button" class="tombol polos" id="tambah-bon" style="margin-top: 6px;">+ Tambah bon</button>
            <datalist id="daftar-pic">
                @foreach ($pic as $p)
                    <option value="{{ $p }}">
                @endforeach
            </datalist>
            <datalist id="daftar-kode">
                @foreach ($kodeGl as $k)
                    <option value="{{ $k }}">
                @endforeach
            </datalist>

            <label style="display: flex; align-items: center; gap: 8px; margin-top: 16px; color: var(--teks); font-size: 14px;">
                <input type="hidden" name="biaya_transfer" value="0">
                <input type="checkbox" name="biaya_transfer" id="biaya_transfer" value="1" @checked(old('biaya_transfer', '1') === '1')>
                Catat biaya transfer {{ rp(\App\Support\TulisKasSheet::BIAYA_TRANSFER) }} (baris "Biaya Transfer Keluar", Kode GL Biaya Transfer Antar Bank)
            </label>
            <p class="redup" id="catatan-biaya" style="margin: 4px 0 0 24px;"></p>
        </div>

        <div style="display: flex; gap: 12px; align-items: center;">
            <button type="submit" class="tombol" id="simpan">Simpan ke sheet</button>
            <span class="redup">Data ditulis ke lembar bulan sesuai tanggal di sheet <i>Kas Harian MNP</i>, lalu langsung muncul di Kas Harian.</span>
        </div>
    </form>

    <template id="templat-bon">
        <tr>
            <td><input type="number" class="angka-input" data-nama="nominal" min="1" required></td>
            <td><input type="text" data-nama="pic" list="daftar-pic" autocomplete="off"></td>
            <td><input type="text" data-nama="keterangan" autocomplete="off" required></td>
            <td><input type="text" data-nama="kode_gl" list="daftar-kode" autocomplete="off" required></td>
            <td><button type="button" class="hapus" title="Hapus bon">×</button></td>
        </tr>
    </template>

    <script>
        (() => {
            const rekening = @json($rekening);
            const awal = @json(old('bon', []));
            const daftar = document.getElementById('daftar-bon');
            const templat = document.getElementById('templat-bon');
            const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);

            const urutkanNama = () => daftar.querySelectorAll('tr').forEach((tr, i) =>
                tr.querySelectorAll('[data-nama]').forEach(el => el.name = `bon[${i}][${el.dataset.nama}]`));
            // Jumlah bon wajib sama persis dengan nominal transfer; selama belum sama, tombol Simpan dikunci.
            const nominalTransfer = document.getElementById('nominal_transfer');
            const statusCocok = document.getElementById('status-cocok');
            const tombolSimpan = document.getElementById('simpan');
            const arahMasuk = () => document.getElementById('arah-masuk').checked;
            const hitung = () => {
                const total = [...daftar.querySelectorAll('[data-nama=nominal]')].reduce((s, el) => s + (parseInt(el.value) || 0), 0);
                const transfer = parseInt(nominalTransfer.value) || 0;
                const selisih = transfer - total;
                document.getElementById('total-bon').textContent = fmt(total);
                document.getElementById('nilai-transfer').textContent = fmt(transfer);
                const cocok = transfer > 0 && selisih === 0;
                statusCocok.className = 'pesan ' + (cocok ? 'sukses' : 'galat');
                statusCocok.textContent = !transfer ? 'Isi nominal transfer dulu.'
                    : cocok ? '✓ Jumlah bon sama dengan nominal transfer.'
                    : selisih > 0 ? `Bon kurang ${fmt(selisih)} — tambah bon atau perbaiki nominalnya.`
                    : `Bon lebih ${fmt(-selisih)} dari nominal transfer — perbaiki nominalnya.`;
                tombolSimpan.disabled = !arahMasuk() && !cocok;
                tombolSimpan.title = tombolSimpan.disabled ? 'Jumlah bon harus sama dengan nominal transfer' : '';
                return cocok;
            };
            nominalTransfer.addEventListener('input', hitung);
            const tambah = (isi = {}) => {
                const tr = templat.content.firstElementChild.cloneNode(true);
                tr.querySelectorAll('[data-nama]').forEach(el => el.value = isi[el.dataset.nama] ?? '');
                tr.querySelector('.hapus').addEventListener('click', () => {
                    if (daftar.children.length > 1) { tr.remove(); urutkanNama(); hitung(); }
                });
                daftar.appendChild(tr);
                urutkanNama();
                hitung();
                return tr;
            };
            daftar.addEventListener('input', hitung);

            document.getElementById('tambah-bon').addEventListener('click', () => {
                const sebelumnya = daftar.lastElementChild;
                // Bon berikutnya biasanya PIC & Kode GL yang sama (mis. reimburse satu orang).
                const tr = tambah(sebelumnya ? {
                    pic: sebelumnya.querySelector('[data-nama=pic]').value,
                    kode_gl: sebelumnya.querySelector('[data-nama=kode_gl]').value,
                } : {});
                tr.querySelector('[data-nama=nominal]').focus();
            });
            (awal.length ? awal : [{}]).forEach(b => tambah(b));

            // Rekening tujuan yang pernah dipakai, dua arah: ketik nama → pilih bank & nomor; ketik nomor → nama & bank.
            const nama = document.getElementById('nama_tujuan');
            const noRek = document.getElementById('no_rek');
            const bank = document.getElementById('bank');
            const saranNama = document.getElementById('saran-nama');
            const saranNorek = document.getElementById('saran-norek');
            const kecil = s => (s || '').toLowerCase().trim();
            const kunciRek = s => (s || '').replace(/\D/g, '').replace(/^0+/, '');
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const tandai = (teks, cari) => {
                const i = cari ? String(teks).toLowerCase().indexOf(cari.toLowerCase()) : -1;
                return i < 0 ? esc(teks) : esc(teks.slice(0, i)) + '<mark>' + esc(teks.slice(i, i + cari.length)) + '</mark>' + esc(teks.slice(i + cari.length));
            };
            // Isian yang diisi otomatis boleh ditimpa saran berikutnya; yang diketik sendiri tidak.
            const otomatis = new Set();
            const isi = (el, v) => { el.value = v ?? ''; otomatis.add(el); };

            const cocokNama = q => {
                q = kecil(q);
                if (!q) return [];
                return rekening.map(r => {
                    const n = kecil(r.nama);
                    const skor = n === q ? 0 : n.startsWith(q) ? 1 : n.split(/\s+/).some(k => k.startsWith(q)) ? 2 : n.includes(q) ? 3 : -1;
                    return {r, skor};
                }).filter(x => x.skor >= 0).sort((a, b) => a.skor - b.skor || b.r.dipakai - a.r.dipakai).map(x => x.r);
            };
            const cocokNorek = q => {
                const k = kunciRek(q);
                if (k.length < 3) return [];
                return rekening.map(r => ({r, skor: r.kunci === k ? 0 : r.kunci.startsWith(k) ? 1 : r.kunci.includes(k) ? 2 : -1}))
                    .filter(x => x.skor >= 0).sort((a, b) => a.skor - b.skor || b.r.dipakai - a.r.dipakai).map(x => x.r);
            };

            let aktif = {panel: null, daftar: [], sorot: -1};
            const tutup = panel => { panel.hidden = true; panel.classList.remove('menetap'); if (aktif.panel === panel) aktif = {panel: null, daftar: [], sorot: -1}; };
            const tampil = (panel, daftar, judul, cariNama = '', cariNorek = '', menetap = false) => {
                if (!daftar.length) { tutup(panel); return; }
                const tampilkan = daftar.slice(0, 8);
                panel.innerHTML = (judul ? `<div class="judul-saran">${esc(judul)}</div>` : '') + tampilkan.map((r, i) => `
                    <button type="button" data-i="${i}">
                        <span><b>${tandai(r.nama, cariNama)}</b><br><span class="rek">${esc(r.bank || 'Bank ?')} · <b>${tandai(r.no_rek, cariNorek)}</b></span></span>
                        <span class="pakai">${r.dipakai}× · terakhir ${esc(r.terakhir)}</span>
                    </button>`).join('') + (daftar.length > 8 ? `<div class="judul-saran">${daftar.length - 8} lainnya, ketik lebih lengkap</div>` : '');
                panel.classList.toggle('menetap', menetap);
                panel.hidden = false;
                aktif = {panel, daftar: tampilkan, sorot: -1};
                panel.querySelectorAll('button').forEach(b => b.addEventListener('mousedown', e => {
                    e.preventDefault(); // jangan sampai isian kehilangan fokus sebelum pilihan dipakai
                    pilih(tampilkan[+b.dataset.i]);
                }));
            };
            const pilih = r => {
                nama.value = r.nama; noRek.value = r.no_rek; bank.value = r.bank || '';
                [nama, noRek, bank].forEach(el => otomatis.delete(el));
                tutup(saranNama); tutup(saranNorek);
                aturBiaya();
                document.getElementById('keterangan').focus();
            };

            nama.addEventListener('input', () => {
                otomatis.delete(nama);
                const q = nama.value.trim();
                const daftar = cocokNama(q);
                const persis = daftar.filter(r => kecil(r.nama) === kecil(q));
                tampil(saranNama, daftar, persis.length > 1 ? `${persis[0].nama} punya ${persis.length} rekening, pilih salah satu:` : '', q);
            });
            nama.addEventListener('focus', () => nama.value.trim() && nama.dispatchEvent(new Event('input')));
            nama.addEventListener('blur', () => {
                tutup(saranNama);
                const persis = rekening.filter(r => kecil(r.nama) === kecil(nama.value));
                const norekBebas = !noRek.value || otomatis.has(noRek);
                if (persis.length === 1 && norekBebas) {
                    isi(noRek, persis[0].no_rek);
                    if (!bank.value || otomatis.has(bank)) isi(bank, persis[0].bank);
                    aturBiaya();
                } else if (persis.length > 1 && norekBebas) {
                    // Nama sama persis tetapi rekeningnya lebih dari satu: biarkan daftar terbuka sampai admin memilih.
                    tampil(saranNama, persis, `${persis[0].nama} punya ${persis.length} rekening, pilih salah satu:`, '', '', true);
                }
            });

            noRek.addEventListener('input', () => {
                otomatis.delete(noRek);
                const q = noRek.value.trim();
                const daftar = cocokNorek(q);
                const persis = daftar.filter(r => r.kunci === kunciRek(q));
                tampil(saranNorek, daftar, persis.length > 1 ? `Nomor ini tercatat dengan ${persis.length} nama/bank, pilih salah satu:` : '', '', q.replace(/\D/g, '').replace(/^0+/, ''));
            });
            noRek.addEventListener('focus', () => noRek.value.trim() && noRek.dispatchEvent(new Event('input')));
            noRek.addEventListener('blur', () => {
                tutup(saranNorek);
                const persis = rekening.filter(r => r.kunci === kunciRek(noRek.value));
                if (persis.length === 1) {
                    if (!nama.value || otomatis.has(nama)) isi(nama, persis[0].nama);
                    if (!bank.value || otomatis.has(bank)) isi(bank, persis[0].bank);
                    aturBiaya();
                } else if (persis.length > 1 && (!nama.value || otomatis.has(nama))) {
                    tampil(saranNorek, persis, `Nomor ini tercatat dengan ${persis.length} nama/bank, pilih salah satu:`, '', '', true);
                }
            });

            // Panah atas/bawah + Enter untuk memilih, Esc untuk menutup.
            [nama, noRek].forEach(el => el.addEventListener('keydown', e => {
                if (!aktif.panel || aktif.panel.hidden) return;
                const tombol = aktif.panel.querySelectorAll('button');
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    aktif.sorot = (aktif.sorot + (e.key === 'ArrowDown' ? 1 : -1) + tombol.length) % tombol.length;
                    tombol.forEach((b, i) => b.classList.toggle('sorot', i === aktif.sorot));
                    tombol[aktif.sorot].scrollIntoView({block: 'nearest'});
                } else if (e.key === 'Enter' && aktif.sorot >= 0) {
                    e.preventDefault();
                    pilih(aktif.daftar[aktif.sorot]);
                } else if (e.key === 'Escape') {
                    tutup(aktif.panel);
                }
            }));

            document.querySelectorAll('[data-bank]').forEach(a => a.addEventListener('click', e => {
                e.preventDefault(); bank.value = a.dataset.bank; otomatis.delete(bank); aturBiaya();
            }));

            // Transfer ke bank selain Jago biasanya kena biaya 2.500.
            const biaya = document.getElementById('biaya_transfer');
            const catatanBiaya = document.getElementById('catatan-biaya');
            let biayaDiubahManual = {{ old('biaya_transfer') !== null ? 'true' : 'false' }};
            biaya.addEventListener('change', () => biayaDiubahManual = true);
            const aturBiaya = () => {
                const jago = bank.value.trim().toLowerCase() === 'jago' || bank.value.trim() === '';
                if (!biayaDiubahManual) biaya.checked = !jago;
                catatanBiaya.textContent = jago ? 'Sesama Bank Jago biasanya tanpa biaya transfer.' : 'Transfer ke ' + bank.value + ' biasanya kena biaya transfer.';
            };
            bank.addEventListener('input', aturBiaya);
            aturBiaya();

            // Tampilkan bagian sesuai arah (keluar = bon, masuk = nominal).
            const aturArah = () => {
                const masuk = document.getElementById('arah-masuk').checked;
                document.querySelectorAll('[data-masuk]').forEach(el => el.hidden = !masuk);
                document.querySelectorAll('[data-keluar]').forEach(el => el.hidden = masuk);
                daftar.querySelectorAll('input').forEach(el => el.disabled = masuk);
                biaya.disabled = masuk;
                nominalTransfer.disabled = masuk;
                document.getElementById('nominal_masuk').required = masuk;
                hitung();
            };
            document.querySelectorAll('[name=arah]').forEach(r => r.addEventListener('change', aturArah));
            aturArah();

            document.getElementById('form-kas').addEventListener('submit', e => {
                if (!arahMasuk() && !hitung()) {
                    e.preventDefault();
                    statusCocok.scrollIntoView({block: 'center'});
                    return;
                }
                tombolSimpan.disabled = true;
                tombolSimpan.textContent = 'Menyimpan ke sheet…';
            });
        })();
    </script>
@endsection
