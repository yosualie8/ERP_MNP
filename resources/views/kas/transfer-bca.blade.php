@extends('layouts.app', ['judul' => 'Transfer Massal BCA'])

@section('lebar', '1300px')

@section('isi')
    <style>
        .tbca-kartu { margin-bottom: 14px; }
        .tbca-rek { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .tbca-rek input { padding: 6px 9px; border-radius: 6px; border: 2px solid #4a5160; font-size: 14px; width: 170px; }
        table.tbca { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.tbca th { text-align: left; font-size: 12px; color: var(--redup); padding: 6px; }
        table.tbca td { padding: 4px 6px; vertical-align: top; }
        table.tbca input { width: 100%; box-sizing: border-box; padding: 6px 8px; border-radius: 6px; border: 2px solid #4a5160; font-size: 14px; }
        table.tbca input:focus { border-color: var(--aksen); outline: none; }
        table.tbca td.no { color: var(--redup); width: 30px; padding-top: 10px; }
        table.tbca input.rupiah { text-align: right; font-variant-numeric: tabular-nums; }
        table.tbca .hapus { background: none; border: none; color: var(--redup); font-size: 18px; cursor: pointer; padding-top: 6px; }
        table.tbca tr.salah input { border-color: #e0a526; }
        .tbca-kaki { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
        .tbca-total { font-size: 15px; }
        .tbca-total b { font-size: 18px; }
        .panduan ol { margin: 6px 0 6px 18px; padding: 0; line-height: 1.6; }
        .panduan h4 { margin: 12px 0 4px; }
        .panduan code { background: var(--kartu-2); padding: 1px 5px; border-radius: 4px; }
        .tanggal-id { display: inline-flex; align-items: center; gap: 4px; }
        .tanggal-id input { padding: 6px 9px; border-radius: 6px; border: 2px solid #4a5160; font-size: 14px; width: 130px; }
        .tanggal-id .tombol-kalender { background: none; border: 2px solid #4a5160; border-radius: 6px; padding: 4px 6px; cursor: pointer; }
        input.tanggal-salah { border-color: var(--aksen) !important; }
        /* Saran bank (pilih-bank.js) — sama dengan Input UJ / Input Kas. */
        table.tbca td { position: relative; }
        .saran { position: absolute; left: 0; right: 0; top: 100%; z-index: 20; margin-top: 2px; background: var(--kartu-2); border: 1px solid var(--garis-kuat);
            border-radius: 8px; box-shadow: 0 10px 28px rgba(0, 0, 0, .55); max-height: 300px; overflow-y: auto; min-width: 240px; }
        .saran .judul-saran { padding: 6px 12px; font-size: 12px; color: var(--redup); background: var(--latar); border-bottom: 1px solid var(--garis); }
        .saran button { display: flex; width: 100%; justify-content: space-between; gap: 12px; align-items: baseline; text-align: left;
            padding: 7px 12px; border: 0; border-bottom: 1px solid var(--garis); background: none; cursor: pointer; font: inherit; font-size: 13px; color: var(--teks); }
        .saran button:last-child { border-bottom: 0; }
        .saran button.sorot, .saran button:hover { background: var(--hijau-muda); }
        .galat-isian { color: var(--merah); font-size: 12px; margin: 3px 0 0; }
    </style>

    {{-- Rekening debet BCA PT (sekali isi). --}}
    <div class="kartu tbca-kartu">
        <form method="POST" action="{{ route('kas.transfer-bca.rekening') }}" class="tbca-rek">
            @csrf
            <b>Rekening debet BCA PT MNP:</b>
            @if ($rekening)
                <span style="font-size: 16px; letter-spacing: 1px;">{{ $rekening }}</span>
                <details style="display: inline;"><summary class="redup" style="cursor: pointer; font-size: 13px;">ubah</summary>
                    <input type="text" name="rekening" inputmode="numeric" maxlength="12" placeholder="10 digit"> <button class="tombol polos" type="submit">Simpan</button></details>
            @else
                <input type="text" name="rekening" inputmode="numeric" maxlength="12" placeholder="10 digit rekening BCA" required>
                <button class="tombol" type="submit">Simpan</button>
                <span class="redup" style="font-size: 13px;">Cukup sekali — dipakai sebagai rekening sumber dana & rekening biaya transfer.</span>
            @endif
        </form>
    </div>

    <form method="POST" action="{{ route('kas.transfer-bca.buat') }}" class="kartu tbca-kartu" id="form-tbca">
        @csrf
        <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
            <h3 style="margin: 0;">Daftar transfer</h3>
            <label style="display: flex; gap: 8px; align-items: center; margin: 0;">Tanggal transfer
                <input type="text" name="tanggal" data-tanggal-id value="{{ old('tanggal', now()->toDateString()) }}"></label>
            <span class="redup" style="font-size: 13px;">BI-FAST ke bank lain (maks. Rp 250 juta per transfer); sesama BCA otomatis transfer BCA. Biaya ditanggung rekening PT.</span>
        </div>
        @if ($errors->any())
            <div class="pesan galat"><b>Periksa lagi:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif
        <table class="tbca">
            <thead><tr><th>#</th><th style="width: 30%;">Nama penerima <span class="redup">(sesuai rekening)</span></th><th style="width: 18%;">Bank</th><th style="width: 18%;">No rekening</th><th style="width: 14%;">Nominal</th><th>Keterangan <span class="redup">(opsional, maks. 18)</span></th><th></th></tr></thead>
            <tbody id="daftar-tbca"></tbody>
        </table>
        <datalist id="penerima-dikenal">@foreach ($penerima as $p)<option value="{{ $p['nama'] }}">{{ $p['bank'] }} · {{ $p['rekening'] }}</option>@endforeach</datalist>
        <div class="tbca-kaki">
            <button type="button" class="tombol polos" id="tambah-tbca">+ Tambah penerima</button>
            <span class="redup" style="font-size: 12px;">Bisa juga tempel dari Excel (kolom: nama, bank, rekening, nominal, keterangan). Ketik nama yang pernah dipakai → bank & rekening terisi otomatis.</span>
            <span class="tbca-total" style="margin-left: auto;"><span id="jumlah-tbca">0</span> penerima · <b id="total-tbca">Rp 0</b></span>
            <button type="submit" class="tombol" id="buat-tbca" @disabled(! $rekening)>⬇ Buat file Excel untuk converter BCA</button>
        </div>
    </form>

    <details class="kartu tbca-kartu panduan">
        <summary style="cursor: pointer; font-weight: 700;">📘 Cara mengubah file Excel ini jadi file upload KlikBCA Bisnis (ringkas)</summary>
        <h4>Pengaturan sekali saja di aplikasi converter (MultiAutoTransaksi.Net)</h4>
        <ol>
            <li><b>Transaction Type</b>: pilih <i>Multi Auto Transfer BCA dan Bank Lain Dalam Negeri</i>.</li>
            <li>Tab <b>Debited Account</b>: <i>Fund Account</i> → klik + → isi <code>{{ $rekening ?? 'rekening BCA PT' }}</code> → beri centang. <i>Charge Account</i> → klik + → isi rekening yang sama → beri centang.</li>
            <li>Tab <b>Transaction Info</b>: isi <i>Corporate ID</i> KlikBCA Bisnis; <i>Statement Type</i> = Multi Debet; <i>Approval Type</i> = Bulk; <i>Currency</i> = IDR; <i>Charges Type</i> = OUR; centang <i>Effective Date</i>.</li>
            <li>Tab <b>Columns Mapping</b>: centang <i>Has Column Headers</i>, lalu pasangkan kolom yang namanya sama: Transaction ID, Transfer Type, Credited Account, Amount, Remarks 1, Rcv Bank Code, Rcv Bank Name, Rcv Name, Customer Type, Customer Residence, Transaction Code. (Debited Account & Charges Account <u>tidak</u> perlu dipasangkan karena sudah dicentang di tab Debited Account.)</li>
            <li>Klik <b>Save Settings</b>. Selesai — langkah 1–4 tidak perlu diulang.</li>
        </ol>
        <h4>Setiap kali transfer</h4>
        <ol>
            <li>Isi daftar di atas → klik <b>⬇ Buat file Excel</b>.</li>
            <li>Di converter: <i>Input File</i> = file Excel tadi, <i>Sheet Name</i> = <code>Data</code>, <i>Output File</i> = nama file .txt, <i>Effective Date</i> = tanggal transfer → klik <b>Export</b>.</li>
            <li>Klik <b>buat file checksum</b> (pilih type transfer).</li>
            <li>Login KlikBCA Bisnis → <b>Multi Transaksi → Multi Auto-Transfer</b> → upload file .txt + file checksum → otorisasi.</li>
        </ol>
        <p class="redup" style="font-size: 12px; margin: 6px 0 0;">Kode SWIFT bank tujuan diambil dari Tabel Sandi Bank KlikBCA Bisnis (update 31 Maret 2026). Untuk pertama kali, coba dulu dengan 1 transfer kecil. E-wallet (GoPay, OVO, DANA, dst.) tidak bisa lewat Multi Auto-Transfer.</p>
    </details>

    <div class="kartu gulir tbca-kartu" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>#</th><th>Dibuat</th><th>Tanggal transfer</th><th class="angka">Penerima</th><th class="angka">Total</th><th>Oleh</th><th></th></tr></thead>
            <tbody>
                @forelse ($riwayat as $r)
                    <tr title="{{ collect($r->isi)->map(fn ($p) => $p['nama'].' '.$p['bank'].' '.rp($p['nominal']))->implode(' · ') }}">
                        <td>{{ $r->id }}</td><td>{{ $r->created_at->translatedFormat('j M H:i') }}</td><td>{{ $r->tanggal_efektif->translatedFormat('j M Y') }}</td>
                        <td class="angka">{{ $r->jumlah }}</td><td class="angka">{{ rp($r->total) }}</td><td class="redup">{{ $r->user?->name ?? $r->user?->email }}</td>
                        <td><a href="{{ route('kas.transfer-bca.unduh', $r) }}" class="tombol-edit">⬇ Excel</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="redup" style="padding: 14px;">Belum ada file yang dibuat.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <template id="templat-tbca">
        <tr>
            <td class="no"></td>
            <td><input type="text" data-nama="nama" list="penerima-dikenal" autocomplete="off" maxlength="70"></td>
            <td><input type="text" data-nama="bank" autocomplete="off"></td>
            <td><input type="text" data-nama="rekening" inputmode="numeric" autocomplete="off"></td>
            <td><input type="text" data-nama="nominal" class="rupiah" inputmode="numeric" autocomplete="off"></td>
            <td><input type="text" data-nama="keterangan" maxlength="18" autocomplete="off" placeholder="mis. UJ 9 Okt"></td>
            <td><button type="button" class="hapus" title="Hapus baris">×</button></td>
        </tr>
    </template>

    <script src="{{ asset('js/pilih-bank.js') }}"></script>
    <script src="{{ asset('js/tempel-tabel.js') }}"></script>
    <script src="{{ asset('js/tanggal-id.js') }}"></script>
    <script>
        (() => {
            const BANK = @json($bank);
            const PENERIMA = @json($penerima);
            const awal = @json(old('penerima', []));
            const form = document.getElementById('form-tbca');
            const daftar = document.getElementById('daftar-tbca');
            const templat = document.getElementById('templat-tbca');
            const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);
            const angka = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0;
            const baris = () => [...daftar.querySelectorAll(':scope > tr')];
            const sel = (tr, f) => tr.querySelector(`[data-nama=${f}]`);
            TanggalId.pasangSemua(form);

            const urutkan = () => baris().forEach((tr, i) => {
                tr.querySelector('.no').textContent = i + 1;
                tr.querySelectorAll('[data-nama]').forEach(el => el.name = `penerima[${i}][${el.dataset.nama}]`);
            });
            const hitung = () => {
                const isi = baris().filter(tr => sel(tr, 'nama').value.trim() || angka(sel(tr, 'nominal').value));
                document.getElementById('jumlah-tbca').textContent = isi.length;
                document.getElementById('total-tbca').textContent = 'Rp ' + fmt(isi.reduce((s, tr) => s + angka(sel(tr, 'nominal').value), 0));
            };
            // Nama yang pernah dipakai → bank & rekening terakhir (hanya mengisi yang masih kosong).
            const isiDariNama = tr => {
                const n = sel(tr, 'nama').value.trim().toLowerCase();
                const p = PENERIMA.find(x => x.nama.toLowerCase() === n);
                if (!p) return;
                if (!sel(tr, 'bank').value.trim()) { sel(tr, 'bank').value = p.bank; sel(tr, 'bank').rapikan?.(); } // tanpa membuka daftar saran
                if (!sel(tr, 'rekening').value.trim()) sel(tr, 'rekening').value = p.rekening;
            };
            const tambah = (isi = {}) => {
                const tr = templat.content.firstElementChild.cloneNode(true);
                tr.querySelectorAll('[data-nama]').forEach(el => el.value = isi[el.dataset.nama] ?? '');
                daftar.appendChild(tr);
                PilihBank.pasang(sel(tr, 'bank'), BANK, {sering: ['BRI', 'Mandiri', 'BNI', 'BCA', 'BSI', 'Seabank', 'Jago']});
                const nom = sel(tr, 'nominal');
                if (nom.value) nom.value = fmt(angka(nom.value));
                tr.querySelector('.hapus').addEventListener('click', () => { if (baris().length > 1) { tr.remove(); urutkan(); hitung(); } });
                sel(tr, 'nama').addEventListener('change', () => isiDariNama(tr));
                urutkan();
                return tr;
            };
            daftar.addEventListener('input', e => {
                if (e.target.dataset.nama === 'nominal') { const d = angka(e.target.value); e.target.value = d ? fmt(d) : ''; }
                if (e.target.dataset.nama === 'rekening') e.target.value = e.target.value.replace(/[^\d]/g, '');
                hitung();
            });
            document.getElementById('tambah-tbca').addEventListener('click', () => { sel(tambah(), 'nama').focus(); hitung(); });
            (awal.length ? awal : [{}, {}, {}]).forEach(r => tambah(r));
            TempelTabel.pasang(daftar, {baris, tambah: () => tambah({}), setelah: rows => { rows.forEach(tr => {
                const nom = sel(tr, 'nominal'); nom.value = angka(nom.value) ? fmt(angka(nom.value)) : '';
                sel(tr, 'rekening').value = sel(tr, 'rekening').value.replace(/[^\d]/g, '');
                sel(tr, 'bank').rapikan?.();
                isiDariNama(tr);
            }); urutkan(); hitung(); }});
            hitung();
            // Baris yang benar-benar kosong tidak dikirim.
            form.addEventListener('submit', e => {
                const isi = baris().filter(tr => [...tr.querySelectorAll('[data-nama]')].some(el => el.value.trim()));
                if (!isi.length) { e.preventDefault(); alert('Isi minimal satu penerima.'); return; }
                baris().filter(tr => !isi.includes(tr)).forEach(tr => tr.querySelectorAll('input').forEach(i => i.disabled = true));
                setTimeout(() => baris().forEach(tr => tr.querySelectorAll('input').forEach(i => i.disabled = false)), 1500);
            });
        })();
    </script>
@endsection
