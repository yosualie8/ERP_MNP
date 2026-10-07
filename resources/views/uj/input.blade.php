@extends('layouts.app', ['judul' => 'Input UJ'])

@section('lebar', '1440px')

@section('isi')
    <style>
        .form-kas label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .form-kas input[type=text], .form-kas input[type=date] {
            width: 100%; padding: 8px 10px; border: 1px solid var(--garis); border-radius: 7px; font-size: 14px; background: var(--isian); color: var(--teks); }
        .form-kas input.angka-input { text-align: right; font-variant-numeric: tabular-nums; }
        table.bon input[data-nama] { border-color: var(--isian-garis); }
        .form-kas input[type=text]:focus, .form-kas input[type=date]:focus { outline: none; border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen); }
        table.bon input[data-nama]:focus { background: var(--isian-fokus); }
        .form-kas input.otomatis { background: var(--otomatis-latar); border-color: var(--otomatis-garis); }
        .baris2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; }
        table.bon td { padding: 4px; vertical-align: top; border-bottom: 0; }
        table.bon th { padding: 4px; }
        .tambah-detail { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 6px; }
        .tambah-detail label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--teks); margin: 0; }
        .tambah-detail #jumlah-detail { width: 56px; text-align: center; padding: 6px; border: 1px solid var(--garis); border-radius: 6px; font-size: 14px; }
        .tambah-detail .redup { font-size: 12px; }
        .biaya-transfer { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 16px; font-size: 14px; }
        .biaya-transfer label { display: flex; align-items: center; gap: 8px; color: var(--teks); margin: 0; }
        .form-kas .biaya-transfer #nominal_biaya { width: 110px; }
        .biaya-transfer.mati #nominal_biaya { opacity: .45; }
        .hapus { background: none; border: 0; color: var(--merah); cursor: pointer; font-size: 18px; line-height: 1; padding: 8px 6px; }
        .total-bon { font-size: 18px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .galat-isian { color: var(--merah); font-size: 13px; margin: 4px 0 0; }
        /* Hasil validasi (Standar Aturan Validasi Kas Uang Jalan) di bawah baris detail yang kena FLAG. */
        table.bon tr.ber-flag input[data-nama] { border-color: #b8860b; }
        table.bon tr.temuan-baris td { padding: 0 4px 10px; }
        .temuan { border: 1px solid #6b4f10; border-left: 4px solid #e0a526; background: #1d1810; border-radius: 8px; padding: 8px 12px; font-size: 13px; }
        .temuan .judul-temuan { color: #f0c05a; font-weight: 600; margin-bottom: 4px; }
        .temuan ul { margin: 0 0 8px; padding-left: 18px; }
        .temuan li { margin: 3px 0; line-height: 1.45; }
        .temuan .prioritas { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 0 6px; border-radius: 4px; }
        .temuan .prioritas.tinggi { background: var(--aksen); color: #fff; }
        .temuan .prioritas.sedang { background: #b8860b; color: #fff; }
        .temuan .prioritas.rendah { background: #3b404b; color: #dfe2e7; }
        .temuan .aturan { color: var(--redup); font-size: 11px; }
        .temuan label { display: block; color: var(--teks); font-size: 12px; margin: 0; }
        .temuan input[data-konfirmasi] { margin-top: 4px; }
        .temuan input[data-konfirmasi].kosong { border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen-muda); }
        .form-kas input.wajib-kosong { border-color: var(--aksen) !important; box-shadow: 0 0 0 2px var(--aksen-muda); background: var(--isian-fokus); }
        .isian-saran { position: relative; }
        .saran { position: absolute; left: 0; right: 0; top: 100%; z-index: 20; margin-top: 2px; background: var(--kartu-2); border: 1px solid var(--garis-kuat);
            border-radius: 8px; box-shadow: 0 10px 28px rgba(0, 0, 0, .55); max-height: 340px; overflow-y: auto; }
        .saran.menetap { position: static; box-shadow: none; margin-top: 6px; border-color: var(--aksen); }
        .saran .judul-saran { padding: 6px 12px; font-size: 12px; color: var(--redup); background: var(--latar); border-bottom: 1px solid var(--garis); }
        .saran button { display: flex; width: 100%; justify-content: space-between; gap: 12px; align-items: baseline; text-align: left;
            padding: 8px 12px; border: 0; border-bottom: 1px solid var(--garis); background: none; cursor: pointer; font: inherit; color: var(--teks); }
        .saran button:last-child { border-bottom: 0; }
        .saran button.sorot, .saran button:hover { background: var(--hijau-muda); }
        .saran .rek { font-size: 13px; color: var(--redup); }
        .saran .rek b { color: var(--teks); font-weight: 600; font-variant-numeric: tabular-nums; }
        .saran .pakai { font-size: 11px; color: var(--redup); white-space: nowrap; }
        .saran mark { background: var(--tanda); color: inherit; padding: 0; }
        .kartu-foto .penampil { height: 62vh; margin-top: 10px; }
        .gambar-kecil .tambah-foto { width: 68px; height: 68px; border: 2px dashed var(--garis); color: var(--redup); font-size: 26px; }
        .gambar-kecil .tambah-foto:hover { border-color: var(--aksen); color: var(--aksen); }
        .kartu-foto .penampil.kosong { height: 120px; min-height: 0; }
        .kartu-foto .penampil.kosong .penampil-alat, .kartu-foto .penampil.kosong .penampil-petunjuk { display: none; }
        .info-foto { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-size: 12px; color: var(--redup); margin-top: 6px; }
        @media (max-width: 760px) { .kartu-foto .penampil { height: 50vh; } }
    </style>

    @php($edit ??= null)
    <form method="POST" action="{{ $edit ? route('uj.update', $edit['no_uj']) : route('uj.store') }}" class="form-kas" id="form-kas" enctype="multipart/form-data">
        @if ($edit)
            @method('PUT')
            <input type="hidden" name="versi" value="{{ $edit['versi'] }}">
            <input type="hidden" name="no_uj" value="{{ $edit['no_uj'] }}">
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
            <h3 style="margin: 0 0 14px;">
                @if ($edit)
                    Edit Transaksi Master UJ <span class="redup" style="font-size: 13px; font-weight: normal;">UJ-{{ $edit['no_uj'] }} · Kas Seabank baris {{ $edit['baris'] }}</span>
                @else
                    Input Transaksi Master UJ <span class="redup" style="font-size: 13px; font-weight: normal;">rekening Seabank · uang jalan dump truck</span>
                @endif
            </h3>
            <div class="baris2">
                <div>
                    <label for="tanggal">Tanggal</label>
                    <input type="date" name="tanggal" id="tanggal" value="{{ old('tanggal', $edit['tanggal'] ?? now()->toDateString()) }}" required>
                </div>
                <div style="grid-column: span 2;" class="isian-saran">
                    <label for="nama_tujuan">Nama penerima transfer</label>
                    <input type="text" name="nama" id="nama_tujuan" value="{{ old('nama', $edit['nama'] ?? '') }}" autocomplete="off" required placeholder="Ketik nama, pilih rekeningnya dari daftar">
                    <div class="saran" id="saran-nama" hidden></div>
                </div>
            </div>
            <div class="baris2">
                <div class="isian-saran">
                    <label for="no_rek">No. rekening / e-wallet</label>
                    <input type="text" name="rekening" id="no_rek" value="{{ old('rekening', $edit['rekening'] ?? '') }}" inputmode="numeric" autocomplete="off" placeholder="Atau ketik nomornya">
                    <div class="saran" id="saran-norek" hidden></div>
                </div>
                <div style="grid-column: span 2;">
                    <label for="bank">Bank / e-wallet</label>
                    <div class="isian-saran" style="max-width: 360px; margin-bottom: 6px;"><input type="text" name="bank" id="bank" value="{{ old('bank', $edit['bank'] ?? '') }}" autocomplete="off" placeholder="Ketik singkatan / nama bank / e-wallet, mis. BCA, GoPay"></div>
                </div>
            </div>
            <div style="max-width: 260px;">
                <label for="nominal_transfer">Nominal master <span class="redup">(yang ditransfer, tanpa biaya transfer)</span></label>
                <input type="text" name="nominal" id="nominal_transfer" class="angka-input rupiah" inputmode="numeric" autocomplete="off" value="{{ old('nominal', $edit['nominal'] ?? '') }}" required>
            </div>
        </div>

        <div class="kartu kartu-foto">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                <h3 style="margin: 0;">Foto bon</h3>
                <label class="tombol polos" style="display: inline-block; cursor: pointer; color: var(--teks); font-size: 14px; padding: 6px 12px; margin: 0;">
                    <span id="label-foto">📷 Pilih / ambil foto</span>
                    <input type="file" name="foto[]" id="foto-bon" accept="image/*" multiple hidden>
                </label>
            </div>
            <p class="redup" style="margin: 4px 0 0; font-size: 13px;">Satu transaksi bisa beberapa bon (maks. {{ \App\Http\Controllers\KasFotoController::MAKS_FOTO }} foto). Disimpan di Google Drive perusahaan; kolom Bon (Q) di Kas Seabank berisi chip foldernya.</p>
            @if ($errors->has('foto') || $errors->has('foto.*'))
                <p class="galat-isian">Pilih ulang fotonya — foto tidak bisa dipertahankan setelah form ditolak.</p>
            @endif
            <div class="penampil" id="penampil-input"></div>
            <div class="info-foto"><span id="info-foto">Belum ada foto.</span><button type="button" class="tombol-hapus" id="buang-foto" hidden>Buang foto ini</button></div>
            <div class="gambar-kecil" id="daftar-gambar"></div>
        </div>

        <div class="kartu">
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px;">
                <h3 style="margin: 0 0 4px;">Transaksi detail</h3>
                <span class="redup">Jumlah detail <span class="total-bon" id="total-bon">0</span> dari nominal master <b id="nilai-transfer">0</b></span>
            </div>
            <div id="status-cocok" class="pesan" style="margin: 8px 0 10px; padding: 8px 12px;"></div>
            <p class="redup" style="margin: 0 0 10px;">Satu baris = satu ID UJ di sheet (nomornya dilanjutkan otomatis). Nama detail pertama mengikuti nama penerima; Jenis kendaraan terisi otomatis dari No Mobil (ungu = otomatis, boleh diganti).</p>
            <div class="gulir">
            <table class="bon" style="min-width: 1100px;">
                <thead>
                    <tr>
                        <th style="width: 130px;" class="angka">Nominal</th>
                        <th style="width: 150px;">Nama</th>
                        <th>Keterangan</th>
                        <th style="width: 160px;">Kategori</th>
                        <th style="width: 100px;">No Mobil</th>
                        <th style="width: 100px;">Jenis</th>
                        <th style="width: 100px;">No DO</th>
                        <th style="width: 30px;"></th>
                    </tr>
                </thead>
                <tbody id="daftar-bon"></tbody>
            </table>
            </div>
            <div class="tambah-detail">
                <button type="button" class="tombol polos" id="tambah-bon">+ Tambah detail</button>
                <label>Jumlah Transaksi Detail
                    <input type="text" id="jumlah-detail" value="1" inputmode="numeric" autocomplete="off" maxlength="3" title="Berapa baris detail yang ditambahkan sekali klik">
                </label>
                <span class="redup">Seperti Excel: Enter / ↓ baris bawah, ↑ baris atas, ← → pindah kolom (saat kursor di ujung teks). Baris baru menyalin Keterangan, Kategori & Nominal baris di atasnya.</span>
            </div>
            <datalist id="daftar-nama">@foreach ($nama as $p)<option value="{{ $p }}">@endforeach</datalist>
            <datalist id="daftar-kategori">@foreach ($kategori as $k)<option value="{{ $k }}">@endforeach</datalist>
            <datalist id="daftar-mobil">@foreach ($mobil as $m => $j)<option value="{{ $m }}">{{ $j }}</option>@endforeach</datalist>
            <datalist id="daftar-jenis">@foreach ($jenis as $j)<option value="{{ $j }}">@endforeach</datalist>

            <div class="biaya-transfer" id="baris-biaya">
                <input type="hidden" name="biaya_transfer" value="0">
                <label><input type="checkbox" name="biaya_transfer" id="biaya_transfer" value="1" @checked(old('biaya_transfer', $edit['biaya_transfer'] ?? '1') === '1')> Catat biaya transfer</label>
                <input type="text" name="nominal_biaya" id="nominal_biaya" class="angka-input rupiah" inputmode="numeric" autocomplete="off"
                    value="{{ old('nominal_biaya', $edit['nominal_biaya'] ?? \App\Support\TulisUjSheet::BIAYA_TRANSFER) }}">
                <span class="redup">baris "Biaya Transfer" di bawah detail (tanpa ID UJ, tidak dihitung di nominal master)</span>
            </div>
            @error('nominal_biaya')<p class="galat-isian">{{ $message }}</p>@enderror
            <p class="redup" id="catatan-biaya" style="margin: 4px 0 0 24px;"></p>
        </div>

        <div id="hasil-validasi" hidden></div>
        <div style="display: flex; gap: 12px; align-items: center;">
            <button type="submit" class="tombol" id="simpan">{{ $edit ? 'Simpan perubahan ke sheet' : 'Simpan ke sheet' }}</button>
            @if ($edit)
                <a href="{{ route('uj.index') }}" class="tombol polos">Batal</a>
            @endif
            <span class="redup">Ditulis ke lembar <i>Kas Seabank</i> (sheet KAS MMP Uang Jalan dan UM), di bawah data terakhir.</span>
        </div>
    </form>
    @foreach ($edit['foto'] ?? [] as $f)
        <form method="POST" action="{{ $f['hapus'] }}" id="hapus-foto-{{ $f['id'] }}" hidden>@csrf @method('DELETE')</form>
    @endforeach

    <template id="templat-bon">
        <tr class="baris-detail">
            <td><input type="text" class="angka-input rupiah" data-nama="nominal" inputmode="numeric" autocomplete="off" required></td>
            <td><input type="text" data-nama="nama" list="daftar-nama" autocomplete="off"></td>
            <td><input type="text" data-nama="keterangan" autocomplete="off" required></td>
            <td><input type="text" data-nama="kategori" list="daftar-kategori" autocomplete="off" required></td>
            <td><input type="text" data-nama="no_mobil" list="daftar-mobil" autocomplete="off"></td>
            <td><input type="text" data-nama="jenis_kendaraan" list="daftar-jenis" autocomplete="off"></td>
            <td><input type="text" data-nama="no_do" autocomplete="off"></td>
            <td><button type="button" class="hapus" title="Hapus baris detail">×</button></td>
        </tr>
    </template>

    <script>
        (() => {
            const rekening = @json($rekening);
            const jenisMobil = @json($mobil);
            const awal = @json(old('detail', $edit['detail'] ?? []));
            const daftar = document.getElementById('daftar-bon');
            const templat = document.getElementById('templat-bon');
            const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);
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

            // Baris detail (bisa diselingi baris temuan validasi di bawahnya).
            const barisDetail = () => [...daftar.querySelectorAll(':scope > tr.baris-detail')];
            const tetangga = (tr, arah) => { let x = tr; do { x = arah > 0 ? x.nextElementSibling : x.previousElementSibling; } while (x && !x.classList.contains('baris-detail')); return x; };
            const urutkanNama = () => barisDetail().forEach((tr, i) => {
                tr.querySelectorAll('[data-nama]').forEach(el => el.name = `detail[${i}][${el.dataset.nama}]`);
                const k = tr.temuan?.querySelector('[data-konfirmasi]');
                if (k) k.name = `detail[${i}][konfirmasi]`;
            });
            const nominalTransfer = document.getElementById('nominal_transfer');
            const statusCocok = document.getElementById('status-cocok');
            const tombolSimpan = document.getElementById('simpan');
            const hitung = () => {
                const total = [...daftar.querySelectorAll('[data-nama=nominal]')].reduce((s, el) => s + angka(el.value), 0);
                const transfer = angka(nominalTransfer.value);
                const selisih = transfer - total;
                document.getElementById('total-bon').textContent = fmt(total);
                document.getElementById('nilai-transfer').textContent = fmt(transfer);
                const cocok = transfer > 0 && selisih === 0;
                statusCocok.className = 'pesan ' + (cocok ? 'sukses' : 'galat');
                statusCocok.textContent = !transfer ? 'Isi nominal master dulu.'
                    : cocok ? '✓ Jumlah detail sama dengan nominal master.'
                    : selisih > 0 ? `Detail kurang ${fmt(selisih)} — tambah detail atau perbaiki nominalnya.`
                    : `Detail lebih ${fmt(-selisih)} dari nominal master — perbaiki nominalnya.`;
                // Tombol Simpan tetap aktif: saat ditekan, semua kekurangan ditampilkan sekaligus (lihat pengecekan di bawah).
                return cocok;
            };
            nominalTransfer.addEventListener('input', hitung);

            // Isian otomatis (ungu) boleh ditimpa isian otomatis berikutnya; begitu diketik sendiri, tidak disentuh lagi.
            const isiOtomatis = (el, v) => {
                if (el.value && !el.classList.contains('otomatis')) return;
                el.value = v ?? '';
                el.classList.toggle('otomatis', !!v);
            };
            // Sama dengan App\Support\NomorMobil: "dt  o42" / "DT042" → "DT 042".
            const kunciMobil = v => {
                const t = String(v || '').toUpperCase().replace(/\s+/g, ' ').trim();
                const m = t.match(/^DT\s*([O0-9]{1,3})$/);
                return m ? 'DT ' + m[1].replace(/O/g, '0').padStart(3, '0') : t;
            };
            daftar.addEventListener('focusout', e => {
                if (e.target.dataset?.nama === 'no_mobil' && e.target.value) {
                    e.target.value = kunciMobil(e.target.value);
                    aturJenis(e.target.closest('tr'));
                }
            });
            const aturJenis = tr => isiOtomatis(tr.querySelector('[data-nama=jenis_kendaraan]'), jenisMobil[kunciMobil(tr.querySelector('[data-nama=no_mobil]').value)] || '');
            const nama = document.getElementById('nama_tujuan');
            const aturNamaPertama = () => { const tr = barisDetail()[0]; if (tr) isiOtomatis(tr.querySelector('[data-nama=nama]'), nama.value.trim()); };

            const tambah = (isi = {}) => {
                const tr = templat.content.firstElementChild.cloneNode(true);
                tr.querySelectorAll('[data-nama]').forEach(el => el.value = isi[el.dataset.nama] ?? '');
                tr.querySelectorAll('input.rupiah').forEach(rapikanRupiah);
                tr.konfirmasi = isi.konfirmasi ?? '';
                tr.querySelector('.hapus').addEventListener('click', () => {
                    if (barisDetail().length > 1) { tr.temuan?.remove(); tr.remove(); urutkanNama(); hitung(); aturNamaPertama(); }
                });
                daftar.appendChild(tr);
                urutkanNama();
                hitung();
                return tr;
            };
            daftar.addEventListener('input', e => {
                hitung();
                const el = e.target;
                el.classList.remove('otomatis');
                if (el.dataset.nama === 'no_mobil') aturJenis(el.closest('tr'));
            });

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
                    const sebelumnya = barisDetail().at(-1);
                    // Baris berikutnya biasanya sejenis (mis. UM banyak sopir): Keterangan, Kategori & Nominal disalin.
                    const ambil = f => sebelumnya?.querySelector(`[data-nama=${f}]`).value ?? '';
                    const tr = tambah(sebelumnya ? {keterangan: ambil('keterangan'), kategori: ambil('kategori'), nominal: ambil('nominal')} : {});
                    pertama ??= tr;
                }
                jumlahDetail.value = '1';
                pertama.querySelector('[data-nama=nominal]').focus();
                pertama.querySelector('[data-nama=nominal]').select();
            });

            // Seperti Excel: Enter / ↓ baris bawah, Shift+Enter / ↑ baris atas, ← → pindah kolom di ujung teks.
            daftar.addEventListener('keydown', e => {
                const el = e.target;
                if (!el.dataset?.nama || e.altKey || e.ctrlKey || e.metaKey || e.isComposing) return;
                if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && !e.shiftKey) {
                    const semua = el.selectionStart === 0 && el.selectionEnd === el.value.length;
                    const diUjung = e.key === 'ArrowLeft'
                        ? el.selectionStart === 0 && el.selectionEnd === 0
                        : el.selectionStart === el.value.length && el.selectionEnd === el.value.length;
                    if (!semua && !diUjung) return;
                    const kolom = [...el.closest('tr').querySelectorAll('[data-nama]')];
                    const tujuan = kolom[kolom.indexOf(el) + (e.key === 'ArrowLeft' ? -1 : 1)];
                    if (tujuan) { e.preventDefault(); tujuan.focus(); tujuan.select(); }
                    return;
                }
                const turun = e.key === 'ArrowDown' || (e.key === 'Enter' && !e.shiftKey);
                const naik = e.key === 'ArrowUp' || (e.key === 'Enter' && e.shiftKey);
                if (!turun && !naik) return;
                e.preventDefault();
                const tujuan = tetangga(el.closest('tr'), turun ? 1 : -1)?.querySelector(`[data-nama="${el.dataset.nama}"]`);
                if (tujuan) { tujuan.focus(); tujuan.select(); }
            });
            (awal.length ? awal : [{}]).forEach(b => tambah(b));
            barisDetail().forEach(tr => { if (!tr.querySelector('[data-nama=jenis_kendaraan]').value) aturJenis(tr); });
            if (!awal.length) aturNamaPertama();
            nama.addEventListener('input', aturNamaPertama);

            // Rekening tujuan yang pernah dipakai, dua arah: ketik nama → pilih bank & nomor; ketik nomor → nama & bank.
            const noRek = document.getElementById('no_rek');
            const bank = document.getElementById('bank');
            // Bank/e-wallet dari daftar baku (BCA, Mandiri, GoPay, …), dengan saran singkatan & nama lengkap.
            const DAFTAR_BANK = @json(\App\Support\DaftarBank::untukForm());
            PilihBank.pasang(bank, DAFTAR_BANK, {sering: @json($bank), ubah: () => aturBiaya()});
            const saranNama = document.getElementById('saran-nama');
            const saranNorek = document.getElementById('saran-norek');
            const kecil = s => (s || '').toLowerCase().trim();
            const kunciRek = s => (s || '').replace(/\D/g, '').replace(/^0+/, '');
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const tandai = (teks, cari) => {
                const i = cari ? String(teks).toLowerCase().indexOf(cari.toLowerCase()) : -1;
                return i < 0 ? esc(teks) : esc(teks.slice(0, i)) + '<mark>' + esc(teks.slice(i, i + cari.length)) + '</mark>' + esc(teks.slice(i + cari.length));
            };
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
                panel.querySelectorAll('button').forEach(b => b.addEventListener('mousedown', e => { e.preventDefault(); pilih(tampilkan[+b.dataset.i]); }));
            };
            const pilih = r => {
                nama.value = r.nama; noRek.value = r.no_rek; bank.value = r.bank || ''; bank.rapikan();
                [nama, noRek, bank].forEach(el => otomatis.delete(el));
                tutup(saranNama); tutup(saranNorek);
                aturBiaya(); aturNamaPertama();
                nominalTransfer.focus();
            };
            nama.addEventListener('input', () => {
                otomatis.delete(nama);
                const q = nama.value.trim();
                const d = cocokNama(q);
                const persis = d.filter(r => kecil(r.nama) === kecil(q));
                tampil(saranNama, d, persis.length > 1 ? `${persis[0].nama} punya ${persis.length} rekening, pilih salah satu:` : '', q);
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
                    tampil(saranNama, persis, `${persis[0].nama} punya ${persis.length} rekening, pilih salah satu:`, '', '', true);
                }
            });
            noRek.addEventListener('input', () => {
                otomatis.delete(noRek);
                const q = noRek.value.trim();
                const d = cocokNorek(q);
                const persis = d.filter(r => r.kunci === kunciRek(q));
                tampil(saranNorek, d, persis.length > 1 ? `Nomor ini tercatat dengan ${persis.length} nama/bank, pilih salah satu:` : '', '', q.replace(/\D/g, '').replace(/^0+/, ''));
            });
            noRek.addEventListener('focus', () => noRek.value.trim() && noRek.dispatchEvent(new Event('input')));
            noRek.addEventListener('blur', () => {
                tutup(saranNorek);
                const persis = rekening.filter(r => r.kunci === kunciRek(noRek.value));
                if (persis.length === 1) {
                    if (!nama.value || otomatis.has(nama)) { isi(nama, persis[0].nama); aturNamaPertama(); }
                    if (!bank.value || otomatis.has(bank)) { isi(bank, persis[0].bank); bank.rapikan(); }
                    aturBiaya();
                } else if (persis.length > 1 && (!nama.value || otomatis.has(nama))) {
                    tampil(saranNorek, persis, `Nomor ini tercatat dengan ${persis.length} nama/bank, pilih salah satu:`, '', '', true);
                }
            });
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

            // Dikirim dari rekening Seabank: sesama Seabank biasanya tanpa biaya, bank/e-wallet lain 2.500.
            const biaya = document.getElementById('biaya_transfer');
            const catatanBiaya = document.getElementById('catatan-biaya');
            let biayaDiubahManual = {{ old('biaya_transfer') !== null || $edit ? 'true' : 'false' }};
            const nominalBiaya = document.getElementById('nominal_biaya');
            const barisBiaya = document.getElementById('baris-biaya');
            const tandaiBiaya = () => barisBiaya.classList.toggle('mati', !biaya.checked);
            biaya.addEventListener('change', () => { biayaDiubahManual = true; tandaiBiaya(); });
            nominalBiaya.addEventListener('input', () => { biayaDiubahManual = true; biaya.checked = angka(nominalBiaya.value) > 0; tandaiBiaya(); });
            nominalBiaya.addEventListener('focus', () => nominalBiaya.select());
            const aturBiaya = () => {
                const seabank = bank.value.trim().toLowerCase() === 'seabank' || bank.value.trim() === '';
                if (!biayaDiubahManual) biaya.checked = !seabank;
                tandaiBiaya();
                catatanBiaya.textContent = seabank ? 'Sesama Seabank biasanya tanpa biaya transfer.' : 'Transfer ke ' + bank.value + ' biasanya kena biaya transfer.';
            };
            bank.addEventListener('input', aturBiaya);
            aturBiaya();

            // Foto bon: diperkecil di browser, tampil di penampil zoom/geser; beberapa per transaksi.
            const MAKS_FOTO = {{ \App\Http\Controllers\KasFotoController::MAKS_FOTO }};
            const BATAS = @json(\App\Http\Controllers\KasFotoController::batasUnggah());
            const labelFoto = document.getElementById('label-foto');
            const inputFoto = document.getElementById('foto-bon');
            const penampil = PenampilFoto.pasang(document.getElementById('penampil-input'));
            const daftarGambar = document.getElementById('daftar-gambar');
            const infoFoto = document.getElementById('info-foto');
            const tombolBuang = document.getElementById('buang-foto');
            let daftarFoto = [];
            const fotoTersimpan = @json($edit['foto'] ?? []);
            let pilihan = -1;
            const ukuran = b => b > 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.round(b / 1024) + ' KB';
            const semuaFoto = () => [
                ...fotoTersimpan.map(x => ({tersimpan: true, url: x.penuh, kecil: x.kecil, id: x.id})),
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
                if (daftarFoto.length < MAKS_FOTO && semua.length) {
                    const t = document.createElement('button');
                    t.type = 'button'; t.className = 'tambah-foto'; t.title = 'Tambah foto bon'; t.textContent = '＋';
                    t.addEventListener('click', () => inputFoto.click());
                    daftarGambar.appendChild(t);
                }
                labelFoto.textContent = !semua.length ? '📷 Pilih / ambil foto'
                    : daftarFoto.length >= MAKS_FOTO ? `${semua.length} foto (maks.)` : `＋ Tambah foto bon (${semua.length})`;
                const x = semua[pilihan];
                penampil.tampilkan(x ? x.url : '');
                tombolBuang.hidden = !x;
                tombolBuang.textContent = x?.tersimpan ? 'Hapus foto tersimpan ini' : 'Buang foto ini';
                infoFoto.textContent = !x ? 'Belum ada foto.'
                    : x.tersimpan ? `Foto ${pilihan + 1}/${semua.length} · tersimpan`
                    : `Foto ${pilihan + 1}/${semua.length} · baru · ${x.f.ukuranAsli > x.f.file.size ? ukuran(x.f.ukuranAsli) + ' → ' : ''}${ukuran(x.f.file.size)}`;
            };
            tombolBuang.addEventListener('click', () => {
                const x = semuaFoto()[pilihan];
                if (x.tersimpan) {
                    if (confirm('Hapus foto bon tersimpan ini (juga dari Google Drive)? Perubahan lain di form yang belum disimpan akan hilang.')) document.getElementById('hapus-foto-' + x.id).submit();
                    return;
                }
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
                const awalFoto = semuaFoto().length;
                const ditolak = [];
                for (const [i, asli] of baru.entries()) {
                    infoFoto.textContent = `Memperkecil foto ${i + 1}/${baru.length}…`;
                    const kecil = await kecilkanFoto(asli);
                    if (kecil.size > BATAS.file) { ditolak.push(asli.name); continue; }
                    daftarFoto.push({file: kecil, ukuranAsli: asli.size, url: URL.createObjectURL(kecil)});
                }
                pilihan = awalFoto < semuaFoto().length ? awalFoto : semuaFoto().length - 1;
                tampilFoto();
                const catatan = [
                    dipilih.length > baru.length ? `Hanya ${MAKS_FOTO} foto per transaksi; ${dipilih.length - baru.length} foto tidak ditambahkan.` : '',
                    ditolak.length ? `Terlalu besar (maks. ${ukuran(BATAS.file)}), tidak ditambahkan: ${ditolak.join(', ')}.` : '',
                ].filter(Boolean).join(' ');
                if (catatan) alert(catatan);
            });

            // Validasi "Standar Aturan Validasi Kas Uang Jalan" saat Simpan ditekan: setiap detail diperiksa di server (histori 30 hari).
            // Baris ber-FLAG menampilkan alasannya tepat di bawah transaksinya + kotak konfirmasi admin; Simpan baru jalan bila semua
            // baris ber-FLAG sudah dikonfirmasi. Foto yang sudah dipilih tidak hilang (halaman tidak dimuat ulang).
            const form = document.getElementById('form-kas');
            form.noValidate = true; // semua pengecekan ditangani di sini supaya pesannya lengkap per baris
            const esc2 = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const kotakValidasi = document.getElementById('hasil-validasi');
            const MIN_KONFIRMASI = 10;
            const kodeBank = new Set(DAFTAR_BANK.map(b => b.kode));
            const ringkasBaris = (tr, i) => {
                const v = f => tr.querySelector(`[data-nama=${f}]`).value.trim();
                const info = [v('nama') || (i === 0 ? nama.value.trim() : ''), v('nominal') ? 'Rp ' + v('nominal') : '', v('keterangan')].filter(Boolean).join(' · ');
                return `Baris ${i + 1}${info ? ` (${info})` : ''}`;
            };
            const tampilMasalah = (judul, masalah) => {
                kotakValidasi.className = 'pesan galat';
                kotakValidasi.innerHTML = `<b>${esc2(judul)}</b><ul style="margin: 6px 0 0; padding-left: 18px;">${masalah.map(m => `<li>${esc2(m)}</li>`).join('')}</ul>`;
                kotakValidasi.hidden = false;
            };
            // Isian kosong baru ditandai merah setelah sempat disentuh (atau setelah tombol Simpan dicoba), supaya form baru tidak merah semua.
            let tandaiSemua = false;
            form.addEventListener('focusout', e => { if (e.target.matches('input')) { e.target.dataset.tersentuh = '1'; evaluasi(); } });
            // Lapis 1: kelengkapan isian master & setiap baris detail (semua masalah sekaligus, bukan satu per satu).
            const cekIsian = () => {
                form.querySelectorAll('.wajib-kosong').forEach(el => el.classList.remove('wajib-kosong'));
                const masalah = [];
                let pertama = null;
                const tandai = el => { if (tandaiSemua || el.dataset.tersentuh) el.classList.add('wajib-kosong'); pertama ??= el; };
                const master = [];
                [[document.getElementById('tanggal'), 'Tanggal'], [nama, 'Nama penerima'], [nominalTransfer, 'Nominal master']].forEach(([el, label]) => {
                    if (!el.value.trim() || (el === nominalTransfer && !angka(el.value))) { tandai(el); master.push(label + ' belum diisi'); }
                });
                if (bank.value.trim() && !kodeBank.has(bank.value.trim())) { tandai(bank); master.push(`Bank "${bank.value.trim()}" tidak ada di daftar`); }
                if (master.length) masalah.push('Transaksi master: ' + master.join(', ') + '.');
                barisDetail().forEach((tr, i) => {
                    const kurang = [['nominal', 'Nominal'], ['keterangan', 'Keterangan'], ['kategori', 'Kategori']].filter(([f]) => {
                        const el = tr.querySelector(`[data-nama=${f}]`);
                        const kosong = f === 'nominal' ? !angka(el.value) : !el.value.trim();
                        if (kosong) tandai(el);
                        return kosong;
                    }).map(([, label]) => label);
                    if (kurang.length) masalah.push(`${ringkasBaris(tr, i)}: ${kurang.join(', ')} belum diisi.`);
                });
                if (!hitung()) { masalah.push(statusCocok.textContent); pertama ??= nominalTransfer; }
                return {masalah, pertama};
            };
            const tampilTemuan = temuan => {
                let jumlah = 0;
                barisDetail().forEach((tr, i) => {
                    if (tr.temuan) tr.konfirmasi = tr.temuan.querySelector('[data-konfirmasi]').value;
                    tr.temuan?.remove();
                    tr.temuan = null;
                    tr.aturan = [];
                    tr.classList.remove('ber-flag');
                    const daftarT = temuan[i];
                    if (!daftarT?.length) return;
                    jumlah++;
                    tr.aturan = [...new Set(daftarT.map(x => x.kode))];
                    const t = document.createElement('tr');
                    t.className = 'temuan-baris';
                    t.innerHTML = `<td colspan="8"><div class="temuan">
                        <div class="judul-temuan">⚠ Baris ${i + 1} kena FLAG validasi (${daftarT.length}) — periksa, perbaiki bila salah, atau tulis konfirmasi bila memang valid</div>
                        <ul>${daftarT.map(x => `<li><span class="prioritas ${x.prioritas}">${x.prioritas}</span> <span class="aturan">Aturan ${esc2(x.kode)}</span> ${esc2(x.pesan)}</li>`).join('')}</ul>
                        <label>Konfirmasi admin <span class="redup">(wajib, min. ${MIN_KONFIRMASI} karakter — mis. "Kekurangan UJ karena rute dialihkan", "Ganti driver, Sule sakit")</span>
                            <input type="text" data-konfirmasi maxlength="1000" autocomplete="off"></label></div></td>`;
                    const k = t.querySelector('[data-konfirmasi]');
                    k.value = tr.konfirmasi || '';
                    k.addEventListener('input', () => { tr.konfirmasi = k.value; k.classList.toggle('kosong', k.value.trim().length < MIN_KONFIRMASI); evaluasi(); });
                    k.classList.toggle('kosong', k.value.trim().length < MIN_KONFIRMASI);
                    tr.after(t);
                    tr.temuan = t;
                    tr.classList.add('ber-flag');
                });
                urutkanNama();
                return jumlah;
            };
            // Lapis 3: setiap baris ber-FLAG wajib punya konfirmasi yang memadai.
            const cekKonfirmasi = () => {
                const masalah = [];
                let pertama = null;
                barisDetail().forEach((tr, i) => {
                    const k = tr.temuan?.querySelector('[data-konfirmasi]');
                    if (!k) return;
                    const isi = k.value.trim();
                    if (isi.length >= MIN_KONFIRMASI) return;
                    pertama ??= k;
                    masalah.push(`${ringkasBaris(tr, i)}: ${tr.aturan.length} FLAG (Aturan ${tr.aturan.join(', ')}) — `
                        + (isi ? `konfirmasi terlalu singkat (min. ${MIN_KONFIRMASI} karakter).` : 'perbaiki isiannya atau isi konfirmasi admin.'));
                });
                return {masalah, pertama};
            };
            // Status validasi dihitung terus saat admin mengisi. Tombol Simpan hanya menyala bila SEMUA transaksi valid:
            // isian lengkap + jumlah cocok (lapis 1), aturan validasi sudah diperiksa server untuk isi form saat ini (lapis 2),
            // dan setiap baris ber-FLAG sudah dikonfirmasi (lapis 3). Selama mati, daftar transaksi yang harus diperbaiki ditampilkan.
            const teksSimpan = @json($edit ? 'Simpan perubahan ke sheet' : 'Simpan ke sheet');
            const kunciIsi = () => {
                const d = new FormData(form);
                return JSON.stringify([...d.entries()].filter(([k, v]) => typeof v === 'string' && !/konfirmasi|_token|_method|versi/.test(k)));
            };
            let diperiksa = null;   // kunciIsi() yang terakhir lolos pemeriksaan server
            let memeriksa = null;   // kunciIsi() yang sedang diperiksa
            let jedaPeriksa = null;
            let galatServer = [];
            const aturTombol = (aktif, alasan) => {
                tombolSimpan.disabled = !aktif;
                tombolSimpan.textContent = teksSimpan;
                tombolSimpan.title = aktif ? '' : alasan;
            };
            const periksaServer = async kunci => {
                memeriksa = kunci;
                const data = new FormData(form);
                data.delete('foto[]');
                data.delete('_method');
                try {
                    const res = await fetch(@json(route('uj.periksa')), {method: 'POST', body: data, headers: {Accept: 'application/json'}});
                    const hasil = await res.json();
                    if (memeriksa !== kunci) return; // isian sudah berubah lagi; hasil ini usang
                    if (!res.ok) {
                        galatServer = hasil.galat ?? Object.values(hasil.errors ?? {}).flat();
                        diperiksa = null;
                    } else {
                        galatServer = [];
                        tampilTemuan(hasil.temuan || {});
                        diperiksa = kunci;
                    }
                } catch (err) {
                    galatServer = ['Pemeriksaan validasi gagal (' + err.message + '). Ubah isian atau tunggu sebentar untuk mencoba lagi.'];
                    diperiksa = null;
                } finally {
                    if (memeriksa === kunci) memeriksa = null;
                    evaluasi(false);
                }
            };
            const evaluasi = (bolehPeriksa = true) => {
                const isian = cekIsian();
                if (isian.masalah.length) {
                    clearTimeout(jedaPeriksa);
                    memeriksa = null;
                    aturTombol(false, 'Lengkapi isian dulu');
                    tampilMasalah('Simpan belum aktif — lengkapi dulu:', isian.masalah);
                    return;
                }
                const kunci = kunciIsi();
                if (diperiksa !== kunci) {
                    aturTombol(false, 'Menunggu pemeriksaan validasi');
                    if (galatServer.length && !bolehPeriksa) { tampilMasalah('Simpan belum aktif — perbaiki dulu:', galatServer); return; }
                    kotakValidasi.className = 'pesan';
                    kotakValidasi.hidden = false;
                    kotakValidasi.textContent = 'Memeriksa validasi transaksi…';
                    if (bolehPeriksa && memeriksa !== kunci) {
                        clearTimeout(jedaPeriksa);
                        jedaPeriksa = setTimeout(() => periksaServer(kunciIsi()), 600);
                    }
                    return;
                }
                const konf = cekKonfirmasi();
                if (konf.masalah.length) {
                    aturTombol(false, 'Ada transaksi ber-FLAG yang belum dikonfirmasi');
                    tampilMasalah(`Simpan belum aktif — ${konf.masalah.length} transaksi detail kena FLAG validasi dan harus diperbaiki atau dikonfirmasi (alasannya ada di bawah tiap baris):`, konf.masalah);
                    return;
                }
                aturTombol(true);
                const jumlahFlag = barisDetail().filter(tr => tr.temuan).length;
                kotakValidasi.className = 'pesan sukses';
                kotakValidasi.hidden = false;
                kotakValidasi.textContent = '✓ Semua transaksi tervalidasi' + (jumlahFlag ? ` (${jumlahFlag} baris ber-FLAG sudah dikonfirmasi)` : '') + ' — siap disimpan.';
            };
            form.addEventListener('input', e => { if (!e.target.matches('[data-konfirmasi]')) evaluasi(); });
            form.addEventListener('change', () => evaluasi());
            daftar.addEventListener('click', e => { if (e.target.closest('.hapus')) setTimeout(evaluasi); });
            document.getElementById('tambah-bon').addEventListener('click', () => setTimeout(evaluasi));
            form.addEventListener('submit', e => {
                const kunci = kunciIsi();
                if (tombolSimpan.disabled || diperiksa !== kunci || cekKonfirmasi().masalah.length || cekIsian().masalah.length) {
                    e.preventDefault(); // jaga-jaga (mis. Enter di isian): hanya tersimpan bila tombol Simpan menyala
                    tandaiSemua = true;
                    evaluasi();
                    kotakValidasi.scrollIntoView({block: 'center'});
                    return;
                }
                const totalFoto = daftarFoto.reduce((s, f) => s + f.file.size, 0);
                if (totalFoto > BATAS.total - 512 * 1024) {
                    e.preventDefault();
                    alert(`Total foto ${ukuran(totalFoto)} melebihi batas server ${ukuran(BATAS.total)}. Buang sebagian foto, simpan, lalu tambahkan sisanya lewat Edit.`);
                    return;
                }
                tombolSimpan.disabled = true;
                tombolSimpan.textContent = 'Menyimpan ke sheet…';
            });
            evaluasi();
        })();
    </script>
@endsection
