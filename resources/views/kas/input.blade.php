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
                <div style="grid-column: span 2;">
                    <label for="nama_tujuan"><span data-keluar>Nama rekening tujuan</span><span data-masuk hidden>Dari (nama pengirim)</span></label>
                    <input type="text" name="nama_tujuan" id="nama_tujuan" list="daftar-tujuan" value="{{ old('nama_tujuan') }}" autocomplete="off" required>
                    <datalist id="daftar-tujuan">
                        @foreach ($tujuan as $nama => $_)
                            <option value="{{ $nama }}">
                        @endforeach
                    </datalist>
                </div>
            </div>
            <div class="baris2">
                <div>
                    <label for="no_rek">No. rekening</label>
                    <input type="text" name="no_rek" id="no_rek" value="{{ old('no_rek') }}" inputmode="numeric" autocomplete="off">
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
        </div>

        <div class="kartu" data-keluar>
            <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px;">
                <h3 style="margin: 0 0 4px;">Rincian bon</h3>
                <span class="redup">Nilai transfer = jumlah bon: <span class="total-bon" id="total-bon">0</span></span>
            </div>
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
            const tujuan = @json($tujuan);
            const awal = @json(old('bon', []));
            const daftar = document.getElementById('daftar-bon');
            const templat = document.getElementById('templat-bon');
            const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);

            const urutkanNama = () => daftar.querySelectorAll('tr').forEach((tr, i) =>
                tr.querySelectorAll('[data-nama]').forEach(el => el.name = `bon[${i}][${el.dataset.nama}]`));
            const hitung = () => {
                const total = [...daftar.querySelectorAll('[data-nama=nominal]')].reduce((s, el) => s + (parseInt(el.value) || 0), 0);
                document.getElementById('total-bon').textContent = fmt(total);
            };
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

            // Rekening tujuan yang pernah dipakai: isi No Rek & Bank otomatis.
            const nama = document.getElementById('nama_tujuan');
            const noRek = document.getElementById('no_rek');
            const bank = document.getElementById('bank');
            nama.addEventListener('change', () => {
                const t = tujuan[nama.value];
                if (t) { noRek.value = t.no_rek ?? ''; bank.value = t.bank ?? ''; aturBiaya(); }
            });
            document.querySelectorAll('[data-bank]').forEach(a => a.addEventListener('click', e => {
                e.preventDefault(); bank.value = a.dataset.bank; aturBiaya();
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
                document.getElementById('nominal_masuk').required = masuk;
            };
            document.querySelectorAll('[name=arah]').forEach(r => r.addEventListener('change', aturArah));
            aturArah();

            document.getElementById('form-kas').addEventListener('submit', () => {
                const tombol = document.getElementById('simpan');
                tombol.disabled = true;
                tombol.textContent = 'Menyimpan ke sheet…';
            });
        })();
    </script>
@endsection
