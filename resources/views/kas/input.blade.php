@extends('layouts.app', ['judul' => 'Input Kas'])

@section('lebar', '1280px')

@section('isi')
    <style>
        .form-kas label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .form-kas input[type=text], .form-kas input[type=date], .form-kas input[type=number] {
            width: 100%; padding: 8px 10px; border: 1px solid var(--garis); border-radius: 7px; font-size: 14px; background: #fff; }
        .form-kas input.angka-input { text-align: right; font-variant-numeric: tabular-nums; }
        /* Sel transaksi detail: garis lebih gelap, dan sel yang sedang aktif ditandai tegas (garis hijau tebal + latar terang) seperti Excel. */
        table.bon input[data-nama] { border-color: #9aa5b1; }
        .form-kas input[type=text]:focus, .form-kas input[type=date]:focus { outline: none; border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen); }
        table.bon input[data-nama]:focus { background: #f1faf5; }
        .baris2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .pilihan { display: inline-flex; border: 1px solid var(--garis); border-radius: 8px; overflow: hidden; }
        .pilihan input { display: none; }
        .pilihan label { margin: 0; padding: 8px 16px; cursor: pointer; color: var(--teks); font-size: 14px; }
        .pilihan input:checked + label { background: var(--aksen); color: #fff; }
        table.bon td { padding: 4px; vertical-align: top; border-bottom: 0; }
        table.bon th { padding: 4px; }
        .tambah-detail { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 6px; }
        .tambah-detail label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--teks); }
        .tambah-detail #jumlah-detail { width: 56px; text-align: center; padding: 6px; border: 1px solid var(--garis); border-radius: 6px; font-size: 14px; }
        .tambah-detail .redup { font-size: 12px; }
        .biaya-transfer { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 16px; font-size: 14px; }
        .biaya-transfer label { display: flex; align-items: center; gap: 8px; color: var(--teks); }
        .form-kas .biaya-transfer #nominal_biaya { width: 110px; }
        .biaya-transfer.mati #nominal_biaya { opacity: .45; }
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
        /* Foto bon di antara transaksi master & detail, lebar penuh supaya leluasa di-zoom. */
        .kartu-foto .penampil { height: 62vh; margin-top: 10px; }
        .gambar-kecil .tambah-foto { width: 68px; height: 68px; border: 2px dashed var(--garis); color: var(--redup); font-size: 26px; }
        .gambar-kecil .tambah-foto:hover { border-color: var(--aksen); color: var(--aksen); }
        /* Belum ada foto: ringkas supaya Transaksi detail tidak terdorong jauh ke bawah. */
        .kartu-foto .penampil.kosong { height: 120px; min-height: 0; }
        .kartu-foto .penampil.kosong .penampil-alat, .kartu-foto .penampil.kosong .penampil-petunjuk { display: none; }
        /* Kode GL tebakan otomatis (ungu muda) + saran yang bisa diklik. */
        .form-kas input.tebakan { background: #f3efff; border-color: #c9bdf2; }
        .saran-kode { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
        .saran-kode button { border: 1px solid var(--garis); background: #fff; border-radius: 999px; padding: 1px 8px; font-size: 11px; color: var(--redup); cursor: pointer; }
        .saran-kode button:hover { border-color: #9a87e0; color: var(--teks); }
        .saran-kode button.dipilih { background: #ece6ff; border-color: #9a87e0; color: #3f2f8a; }
        .info-foto { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-size: 12px; color: var(--redup); margin-top: 6px; }
        @media (max-width: 760px) { .kartu-foto .penampil { height: 50vh; } }
    </style>

    @php($edit ??= null)
    <form method="POST" action="{{ $edit ? route('kas.update', $edit['no_id']) : route('kas.input.store') }}" class="form-kas" id="form-kas" enctype="multipart/form-data">
        @if ($edit)
            @method('PUT')
            <input type="hidden" name="versi" value="{{ $edit['versi'] }}">
        @endif
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
                <h3 style="margin: 0;">
                    @if ($edit)
                        Edit Transaksi Master <span class="redup" style="font-size: 13px; font-weight: normal;">NO ID {{ $edit['no_id'] }} · lembar {{ $edit['lembar'] }} baris {{ $edit['baris'] }}</span>
                    @else
                        Input Transaksi Master
                    @endif
                </h3>
                <div class="pilihan">
                    <input type="radio" name="arah" id="arah-keluar" value="keluar" @checked(old('arah', $edit['arah'] ?? 'keluar') === 'keluar')>
                    <label for="arah-keluar">Transfer keluar</label>
                    <input type="radio" name="arah" id="arah-masuk" value="masuk" @checked(old('arah', $edit['arah'] ?? 'keluar') === 'masuk')>
                    <label for="arah-masuk">Uang masuk</label>
                </div>
            </div>

            <div class="baris2">
                <div>
                    <label for="tanggal">Tanggal</label>
                    <input type="date" name="tanggal" id="tanggal" value="{{ old('tanggal', $edit['tanggal'] ?? now()->toDateString()) }}" required>
                </div>
                <div style="grid-column: span 2;" class="isian-saran">
                    <label for="nama_tujuan"><span data-keluar>Nama rekening tujuan</span><span data-masuk hidden>Dari (nama pengirim)</span></label>
                    <input type="text" name="nama_tujuan" id="nama_tujuan" value="{{ old('nama_tujuan', $edit['nama_tujuan'] ?? '') }}" autocomplete="off" required
                           placeholder="Ketik nama, pilih rekeningnya dari daftar">
                    <div class="saran" id="saran-nama" hidden></div>
                </div>
            </div>
            <div class="baris2">
                <div class="isian-saran">
                    <label for="no_rek">No. rekening</label>
                    <input type="text" name="no_rek" id="no_rek" value="{{ old('no_rek', $edit['no_rek'] ?? '') }}" inputmode="numeric" autocomplete="off"
                           placeholder="Atau ketik nomornya">
                    <div class="saran" id="saran-norek" hidden></div>
                </div>
                <div style="grid-column: span 2;">
                    <label for="bank">Bank</label>
                    <div class="isian-saran" style="max-width: 360px; margin-bottom: 6px;"><input type="text" name="bank" id="bank" value="{{ old('bank', $edit['bank'] ?? '') }}" autocomplete="off" placeholder="Ketik singkatan / nama bank / e-wallet, mis. BCA, GoPay"></div>
                    <div>
                        @foreach ($bank as $b)
                            <a href="#" class="chip" data-bank="{{ $b }}">{{ $b }}</a>
                        @endforeach
                    </div>
                </div>
            </div>
            <div>
                <label for="keterangan">Keterangan transfer <span class="redup">(seperti kolom Keterangan di sheet)</span></label>
                <input type="text" name="keterangan" id="keterangan" value="{{ old('keterangan', $edit['keterangan'] ?? '') }}" autocomplete="off">
            </div>

            <div data-masuk hidden style="margin-top: 12px; max-width: 260px;">
                <label for="nominal_masuk">Nominal masuk</label>
                <input type="text" name="nominal_masuk" id="nominal_masuk" class="angka-input rupiah" inputmode="numeric" autocomplete="off" value="{{ old('nominal_masuk', $edit['nominal_masuk'] ?? '') }}">
            </div>
            <div data-keluar style="margin-top: 12px; max-width: 260px;">
                <label for="nominal_transfer">Nominal transfer <span class="redup">(sesuai mutasi bank)</span></label>
                <input type="text" name="nominal_transfer" id="nominal_transfer" class="angka-input rupiah" inputmode="numeric" autocomplete="off" value="{{ old('nominal_transfer', $edit['nominal_transfer'] ?? '') }}" required>
            </div>
        </div>

        <div class="kartu kartu-foto">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                <h3 style="margin: 0;">Foto bon</h3>
                <label class="tombol polos" style="display: inline-block; cursor: pointer; color: var(--teks); font-size: 14px; padding: 6px 12px;">
                    <span id="label-foto">📷 Pilih / ambil foto</span>
                    <input type="file" name="foto[]" id="foto-bon" accept="image/*" multiple hidden>
                </label>
            </div>
            <p class="redup" style="margin: 4px 0 0; font-size: 13px;">Satu transaksi bisa beberapa bon (maks. {{ \App\Http\Controllers\KasFotoController::MAKS_FOTO }} foto): pilih sekaligus, atau tambah satu per satu. Diperkecil otomatis sebelum diunggah, lalu disimpan di Google Drive perusahaan.</p>
            @if ($errors->has('foto') || $errors->has('foto.*'))
                <p class="galat-isian">Pilih ulang fotonya — foto tidak bisa dipertahankan setelah form ditolak.</p>
            @endif
            <div class="penampil" id="penampil-input"></div>
            <div class="info-foto"><span id="info-foto">Belum ada foto.</span><button type="button" class="tombol-hapus" id="buang-foto" hidden>Buang foto ini</button></div>
            <div class="gambar-kecil" id="daftar-gambar"></div>
        </div>

        <div class="kartu" data-keluar>
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px;">
                <h3 style="margin: 0 0 4px;">Transaksi detail</h3>
                <span class="redup">Jumlah detail <span class="total-bon" id="total-bon">0</span> dari transfer <b id="nilai-transfer">0</b></span>
            </div>
            <div id="status-cocok" class="pesan" style="margin: 8px 0 10px; padding: 8px 12px;"></div>
            <p class="redup" style="margin: 0 0 10px;">Satu transaksi master (bisa satu atau beberapa bon/nota) berisi satu atau beberapa transaksi detail di bawahnya. Kode GL ditulis seperti di sheet, mis. <i>Biaya BBM ASG</i> atau <i>Gaji Karyawan ASG T116</i>.</p>
            <table class="bon">
                <thead>
                    <tr>
                        <th style="width: 150px;" class="angka">Nominal</th>
                        <th style="width: 130px;">PIC</th>
                        <th>Keterangan</th>
                        <th style="width: 270px;">Kode GL</th>
                        <th style="width: 30px;"></th>
                    </tr>
                </thead>
                <tbody id="daftar-bon"></tbody>
            </table>
            <div class="tambah-detail">
                <button type="button" class="tombol polos" id="tambah-bon">+ Tambah detail</button>
                <label>Jumlah Transaksi Detail
                    <input type="text" id="jumlah-detail" value="1" inputmode="numeric" autocomplete="off" maxlength="3" title="Berapa baris detail yang ditambahkan sekali klik">
                </label>
                <span class="redup">Seperti Excel: Enter / ↓ baris bawah, ↑ baris atas, ← → pindah kolom (saat kursor di ujung teks).</span>
            </div>
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

            <div class="biaya-transfer" id="baris-biaya">
                <input type="hidden" name="biaya_transfer" value="0">
                <label><input type="checkbox" name="biaya_transfer" id="biaya_transfer" value="1" @checked(old('biaya_transfer', $edit['biaya_transfer'] ?? '1') === '1')> Catat biaya transfer</label>
                <input type="text" name="nominal_biaya" id="nominal_biaya" class="angka-input rupiah" inputmode="numeric" autocomplete="off"
                    value="{{ old('nominal_biaya', $edit['nominal_biaya'] ?? \App\Support\TulisKasSheet::BIAYA_TRANSFER) }}" title="Nominal biaya transfer (default {{ rp(\App\Support\TulisKasSheet::BIAYA_TRANSFER) }})">
                <span class="redup">baris "Biaya Transfer Keluar", Kode GL Biaya Transfer Antar Bank</span>
            </div>
            @error('nominal_biaya')<p class="galat-isian">{{ $message }}</p>@enderror
            <p class="redup" id="catatan-biaya" style="margin: 4px 0 0 24px;"></p>
        </div>

        <div style="display: flex; gap: 12px; align-items: center;">
            <button type="submit" class="tombol" id="simpan">{{ $edit ? 'Simpan perubahan ke sheet' : 'Simpan ke sheet' }}</button>
            @if ($edit)
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('kas.index') }}" class="tombol polos">Batal</a>
            @endif
            <span class="redup">Data ditulis ke lembar bulan sesuai tanggal di sheet <i>Kas Harian MNP</i>, lalu langsung muncul di Kas Harian.</span>
        </div>
    </form>

    <template id="templat-bon">
        <tr>
            <td><input type="text" class="angka-input rupiah" data-nama="nominal" inputmode="numeric" autocomplete="off" required></td>
            <td><input type="text" data-nama="pic" list="daftar-pic" autocomplete="off"></td>
            <td><input type="text" data-nama="keterangan" autocomplete="off" required></td>
            <td><input type="text" data-nama="kode_gl" list="daftar-kode" autocomplete="off" required><div class="saran-kode"></div></td>
            <td><button type="button" class="hapus" title="Hapus baris detail">×</button></td>
        </tr>
    </template>

    <script>
        (() => {
            const rekening = @json($rekening);
            const awal = @json(old('bon', $edit['bon'] ?? []));
            const daftar = document.getElementById('daftar-bon');
            const templat = document.getElementById('templat-bon');
            const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);
            // Nominal ditampilkan bertitik ribuan saat diketik (50000 → 50.000); server membuang titiknya lagi.
            const angka = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0;
            const rapikanRupiah = el => {
                const digitSebelumKursor = el.value.slice(0, el.selectionStart ?? el.value.length).replace(/\D/g, '').length;
                const digit = el.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
                el.value = digit ? fmt(+digit) : '';
                if (document.activeElement === el) {
                    let pos = 0, hitungDigit = 0;
                    while (pos < el.value.length && hitungDigit < digitSebelumKursor) {
                        if (/\d/.test(el.value[pos])) hitungDigit++;
                        pos++;
                    }
                    el.setSelectionRange(pos, pos);
                }
            };
            document.getElementById('form-kas').addEventListener('input', e => {
                if (e.target.classList.contains('rupiah')) rapikanRupiah(e.target);
            }, true);
            document.querySelectorAll('input.rupiah').forEach(rapikanRupiah);

            const urutkanNama = () => daftar.querySelectorAll('tr').forEach((tr, i) =>
                tr.querySelectorAll('[data-nama]').forEach(el => el.name = `bon[${i}][${el.dataset.nama}]`));
            // Jumlah bon wajib sama persis dengan nominal transfer; selama belum sama, tombol Simpan dikunci.
            const nominalTransfer = document.getElementById('nominal_transfer');
            const statusCocok = document.getElementById('status-cocok');
            const tombolSimpan = document.getElementById('simpan');
            const arahMasuk = () => document.getElementById('arah-masuk').checked;
            const hitung = () => {
                const total = [...daftar.querySelectorAll('[data-nama=nominal]')].reduce((s, el) => s + angka(el.value), 0);
                const transfer = angka(nominalTransfer.value);
                const selisih = transfer - total;
                document.getElementById('total-bon').textContent = fmt(total);
                document.getElementById('nilai-transfer').textContent = fmt(transfer);
                const cocok = transfer > 0 && selisih === 0;
                statusCocok.className = 'pesan ' + (cocok ? 'sukses' : 'galat');
                statusCocok.textContent = !transfer ? 'Isi nominal transfer dulu.'
                    : cocok ? '✓ Jumlah detail sama dengan nominal transfer.'
                    : selisih > 0 ? `Detail kurang ${fmt(selisih)} — tambah detail atau perbaiki nominalnya.`
                    : `Detail lebih ${fmt(-selisih)} dari nominal transfer — perbaiki nominalnya.`;
                tombolSimpan.disabled = !arahMasuk() && !cocok;
                tombolSimpan.title = tombolSimpan.disabled ? 'Jumlah detail harus sama dengan nominal transfer' : '';
                return cocok;
            };
            nominalTransfer.addEventListener('input', hitung);
            const tambah = (isi = {}) => {
                const tr = templat.content.firstElementChild.cloneNode(true);
                tr.querySelectorAll('[data-nama]').forEach(el => el.value = isi[el.dataset.nama] ?? '');
                tr.querySelectorAll('input.rupiah').forEach(rapikanRupiah);
                tr.querySelector('.hapus').addEventListener('click', () => {
                    if (daftar.children.length > 1) { tr.remove(); urutkanNama(); hitung(); }
                });
                daftar.appendChild(tr);
                urutkanNama();
                hitung();
                return tr;
            };
            daftar.addEventListener('input', hitung);

            // Tebak Kode GL dari keterangan + PIC, seketika di browser. Kode yang diisi otomatis (ungu) boleh ditimpa
            // tebakan berikutnya; begitu admin mengetik/memilih sendiri, tidak disentuh lagi.
            const modelKode = @json($modelKode);
            const tebakBaris = tr => {
                const kode = tr.querySelector('[data-nama=kode_gl]');
                const saran = tr.querySelector('.saran-kode');
                const ket = tr.querySelector('[data-nama=keterangan]').value;
                const hasil = ket.trim().length >= 3 ? TebakKodeGl.tebak(modelKode, ket, tr.querySelector('[data-nama=pic]').value) : [];
                const otomatis = !kode.value || kode.dataset.otomatis === '1';
                if (otomatis) {
                    kode.value = hasil[0]?.[0] ?? '';
                    kode.dataset.otomatis = '1';
                    kode.classList.toggle('tebakan', !!hasil.length);
                    kode.title = hasil.length ? `Tebakan otomatis (${Math.round(hasil[0][1] * 100)}%) — periksa, atau pilih saran di bawah` : '';
                }
                // Saran hanya tampil selama Kode GL masih tebakan; begitu admin menentukan pilihan, saran hilang.
                saran.innerHTML = '';
                if (!otomatis) return;
                hasil.forEach(([k, p]) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = `${k} ${Math.round(p * 100)}%`;
                    b.className = k === kode.value ? 'dipilih' : '';
                    b.addEventListener('click', () => {
                        kode.value = k;
                        kode.dataset.otomatis = '0';
                        kode.classList.remove('tebakan');
                        kode.title = '';
                        saran.innerHTML = '';
                    });
                    saran.appendChild(b);
                });
            };
            daftar.addEventListener('input', e => {
                const tr = e.target.closest('tr');
                const nama = e.target.dataset.nama;
                if (nama === 'kode_gl') {
                    // Diketik sendiri = pilihan admin (saran hilang); dikosongkan = boleh ditebak lagi.
                    e.target.dataset.otomatis = e.target.value ? '0' : '1';
                    e.target.classList.remove('tebakan');
                    e.target.title = '';
                    tr.querySelector('.saran-kode').innerHTML = '';
                } else if (nama === 'keterangan' || nama === 'pic') {
                    tebakBaris(tr);
                }
            });

            // "Jumlah Transaksi Detail": hanya angka (1–100); sekali klik Tambah detail menambah sebanyak itu, lalu kembali ke 1.
            const jumlahDetail = document.getElementById('jumlah-detail');
            jumlahDetail.addEventListener('input', () => { jumlahDetail.value = jumlahDetail.value.replace(/\D/g, ''); });
            jumlahDetail.addEventListener('focus', () => jumlahDetail.select());
            jumlahDetail.addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); document.getElementById('tambah-bon').click(); }
            });
            document.getElementById('tambah-bon').addEventListener('click', () => {
                const n = Math.min(100, Math.max(1, parseInt(jumlahDetail.value, 10) || 1));
                let pertama = null;
                for (let k = 0; k < n; k++) {
                    const sebelumnya = daftar.lastElementChild;
                    // Baris berikutnya biasanya PIC & Kode GL yang sama (mis. reimburse satu orang);
                    // Kode GL salinan ditandai otomatis supaya diganti tebakan bila keterangannya beda jenis.
                    const tr = tambah(sebelumnya ? {
                        pic: sebelumnya.querySelector('[data-nama=pic]').value,
                        kode_gl: sebelumnya.querySelector('[data-nama=kode_gl]').value,
                    } : {});
                    const kode = tr.querySelector('[data-nama=kode_gl]');
                    if (kode.value) {
                        kode.dataset.otomatis = '1';
                        kode.classList.add('tebakan');
                    }
                    pertama ??= tr;
                }
                jumlahDetail.value = '1';
                pertama.querySelector('[data-nama=nominal]').focus();
            });

            // Seperti Excel: Enter / ↓ ke baris bawah, Shift+Enter / ↑ ke baris atas, di kolom yang sama (isi sel terpilih,
            // jadi langsung mengetik = menimpa). Enter di tabel detail tidak menyimpan form. Alt+↓ tetap membuka daftar PIC/Kode GL.
            daftar.addEventListener('keydown', e => {
                const el = e.target;
                if (!el.dataset?.nama || e.altKey || e.ctrlKey || e.metaKey || e.isComposing) return;
                // ← / →: pindah kolom bila kursor sudah di ujung teks (atau seluruh isi terpilih, mis. baru tiba di sel);
                // selain itu tetap menggeser kursor di dalam teks supaya isi masih bisa diedit.
                if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && !e.shiftKey) {
                    const semua = el.selectionStart === 0 && el.selectionEnd === el.value.length;
                    const diUjung = e.key === 'ArrowLeft'
                        ? el.selectionStart === 0 && el.selectionEnd === 0
                        : el.selectionStart === el.value.length && el.selectionEnd === el.value.length;
                    if (!semua && !diUjung) return;
                    const kolom = [...el.closest('tr').querySelectorAll('[data-nama]')];
                    const tujuan = kolom[kolom.indexOf(el) + (e.key === 'ArrowLeft' ? -1 : 1)];
                    if (tujuan) {
                        e.preventDefault();
                        tujuan.focus();
                        tujuan.select();
                    }
                    return;
                }
                const turun = e.key === 'ArrowDown' || (e.key === 'Enter' && !e.shiftKey);
                const naik = e.key === 'ArrowUp' || (e.key === 'Enter' && e.shiftKey);
                if (!turun && !naik) return;
                e.preventDefault();
                const tujuan = (turun ? el.closest('tr').nextElementSibling : el.closest('tr').previousElementSibling)
                    ?.querySelector(`[data-nama="${el.dataset.nama}"]`);
                if (tujuan) {
                    tujuan.focus();
                    tujuan.select();
                }
            });
            (awal.length ? awal : [{}]).forEach(b => tambah(b));

            // Rekening tujuan yang pernah dipakai, dua arah: ketik nama → pilih bank & nomor; ketik nomor → nama & bank.
            const nama = document.getElementById('nama_tujuan');
            const noRek = document.getElementById('no_rek');
            const bank = document.getElementById('bank');
            // Bank/e-wallet dari daftar baku (BCA, Mandiri, GoPay, …), dengan saran singkatan & nama lengkap.
            PilihBank.pasang(bank, @json(\App\Support\DaftarBank::untukForm()), {sering: @json($bank), ubah: () => aturBiaya()});
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
                nama.value = r.nama; noRek.value = r.no_rek; bank.value = r.bank || ''; bank.rapikan();
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
                    if (!bank.value || otomatis.has(bank)) { isi(bank, persis[0].bank); bank.rapikan(); }
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
                    if (!bank.value || otomatis.has(bank)) { isi(bank, persis[0].bank); bank.rapikan(); }
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
            // Saat edit, centang biaya transfer mengikuti data yang ada (tidak diatur ulang otomatis dari bank).
            let biayaDiubahManual = {{ old('biaya_transfer') !== null || $edit ? 'true' : 'false' }};
            // Nominal biaya default 2.500, bisa diganti; mengetik nominal = berarti ada biaya (centang otomatis).
            const nominalBiaya = document.getElementById('nominal_biaya');
            const barisBiaya = document.getElementById('baris-biaya');
            const tandaiBiaya = () => barisBiaya.classList.toggle('mati', !biaya.checked);
            biaya.addEventListener('change', () => { biayaDiubahManual = true; tandaiBiaya(); });
            nominalBiaya.addEventListener('input', () => {
                biayaDiubahManual = true;
                biaya.checked = angka(nominalBiaya.value) > 0;
                tandaiBiaya();
            });
            nominalBiaya.addEventListener('focus', () => nominalBiaya.select());
            const aturBiaya = () => {
                const jago = bank.value.trim().toLowerCase() === 'jago' || bank.value.trim() === '';
                if (!biayaDiubahManual) biaya.checked = !jago;
                tandaiBiaya();
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

            // Foto bon: diperkecil di browser (cepat diunggah), tampil di penampil zoom/geser; beberapa per transaksi, bisa dibuang.
            const MAKS_FOTO = {{ \App\Http\Controllers\KasFotoController::MAKS_FOTO }};
            const BATAS = @json(\App\Http\Controllers\KasFotoController::batasUnggah());
            const labelFoto = document.getElementById('label-foto');
            const inputFoto = document.getElementById('foto-bon');
            const penampil = PenampilFoto.pasang(document.getElementById('penampil-input'));
            const daftarGambar = document.getElementById('daftar-gambar');
            const infoFoto = document.getElementById('info-foto');
            const tombolBuang = document.getElementById('buang-foto');
            let daftarFoto = []; // {file, ukuranAsli, url} — foto baru
            const fotoTersimpan = @json($edit['foto'] ?? []); // {penuh, kecil}
            let pilihan = -1;
            const ukuran = b => b > 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.round(b / 1024) + ' KB';
            // Daftar yang tampil = foto tersimpan (saat edit, hanya dilihat) + foto baru (ikut diunggah, bisa dibuang).
            const semuaFoto = () => [
                ...fotoTersimpan.map(x => ({tersimpan: true, url: x.penuh, kecil: x.kecil})),
                ...daftarFoto.map(f => ({tersimpan: false, url: f.url, kecil: f.url, f})),
            ];
            const tampilFoto = () => {
                const dt = new DataTransfer();
                daftarFoto.forEach(f => dt.items.add(f.file));
                inputFoto.files = dt.files;
                daftarGambar.innerHTML = '';
                const semua = semuaFoto();
                semua.forEach((x, i) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.title = x.tersimpan ? 'Foto tersimpan' : x.f.file.name;
                    b.className = i === pilihan ? 'aktif' : '';
                    b.innerHTML = '<img alt="">';
                    b.querySelector('img').src = x.kecil;
                    b.addEventListener('click', () => { pilihan = i; tampilFoto(); });
                    daftarGambar.appendChild(b);
                });
                if (daftarFoto.length < MAKS_FOTO) {
                    const tambah = document.createElement('button');
                    tambah.type = 'button';
                    tambah.className = 'tambah-foto';
                    tambah.title = 'Tambah foto bon';
                    tambah.textContent = '＋';
                    tambah.addEventListener('click', () => inputFoto.click());
                    if (semua.length) daftarGambar.appendChild(tambah);
                }
                labelFoto.textContent = !semua.length ? '📷 Pilih / ambil foto'
                    : daftarFoto.length >= MAKS_FOTO ? `${semua.length} foto (maks.)` : `＋ Tambah foto bon (${semua.length})`;
                const x = semua[pilihan];
                penampil.tampilkan(x ? x.url : '');
                tombolBuang.hidden = !x || x.tersimpan;
                infoFoto.textContent = !x ? 'Belum ada foto.'
                    : x.tersimpan ? `Foto ${pilihan + 1}/${semua.length} · tersimpan (hapus lewat halaman bon 📎)`
                    : `Foto ${pilihan + 1}/${semua.length} · baru · ${x.f.ukuranAsli > x.f.file.size ? ukuran(x.f.ukuranAsli) + ' → ' : ''}${ukuran(x.f.file.size)}`;
            };
            tombolBuang.addEventListener('click', () => {
                const i = pilihan - fotoTersimpan.length;
                URL.revokeObjectURL(daftarFoto[i].url);
                daftarFoto.splice(i, 1);
                pilihan = Math.min(pilihan, semuaFoto().length - 1);
                tampilFoto();
            });
            if (fotoTersimpan.length) { pilihan = 0; tampilFoto(); }
            inputFoto.addEventListener('change', async () => {
                const dipilih = [...inputFoto.files].filter(f => f.type.startsWith('image/'));
                const baru = dipilih.slice(0, MAKS_FOTO - daftarFoto.length);
                inputFoto.files = new DataTransfer().files;
                if (!baru.length) { tampilFoto(); return; }
                const awal = semuaFoto().length;
                const ditolak = [];
                for (const [i, asli] of baru.entries()) {
                    infoFoto.textContent = `Memperkecil foto ${i + 1}/${baru.length}…`;
                    const kecil = await kecilkanFoto(asli);
                    if (kecil.size > BATAS.file) { ditolak.push(asli.name); continue; }
                    daftarFoto.push({file: kecil, ukuranAsli: asli.size, url: URL.createObjectURL(kecil)});
                }
                pilihan = Math.max(awal, 0) < semuaFoto().length ? awal : semuaFoto().length - 1;
                tampilFoto();
                const catatan = [
                    dipilih.length > baru.length ? `Hanya ${MAKS_FOTO} foto per transaksi; ${dipilih.length - baru.length} foto tidak ditambahkan.` : '',
                    ditolak.length ? `Terlalu besar (maks. ${ukuran(BATAS.file)}), tidak ditambahkan: ${ditolak.join(', ')}.` : '',
                ].filter(Boolean).join(' ');
                if (catatan) alert(catatan);
            });

            document.getElementById('form-kas').addEventListener('submit', e => {
                if (!arahMasuk() && !hitung()) {
                    e.preventDefault();
                    statusCocok.scrollIntoView({block: 'center'});
                    return;
                }
                // Kiriman melebihi post_max_size dibuang PHP tanpa pesan; tolak di sini dengan pesan yang jelas.
                const totalFoto = daftarFoto.reduce((s, f) => s + f.file.size, 0);
                if (totalFoto > BATAS.total - 512 * 1024) {
                    e.preventDefault();
                    alert(`Total foto ${ukuran(totalFoto)} melebihi batas server ${ukuran(BATAS.total)}. Buang sebagian foto, lalu tambahkan sisanya lewat tombol 📎 setelah transaksi tersimpan.`);
                    return;
                }
                tombolSimpan.disabled = true;
                tombolSimpan.textContent = 'Menyimpan ke sheet…';
            });
        })();
    </script>
@endsection
