@extends('layouts.app', ['judul' => 'Input Transfer BCA'])

@section('lebar', '1360px')

@section('isi')
    <style>
        .ib-kartu { margin-bottom: 14px; }
        table.tbca { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.tbca th { text-align: left; font-size: 12px; color: var(--redup); padding: 6px; }
        table.tbca td { padding: 4px 6px; vertical-align: top; position: relative; }
        table.tbca input, table.tbca select { width: 100%; box-sizing: border-box; padding: 6px 8px; border-radius: 6px; border: 2px solid #4a5160; font-size: 14px; }
        table.tbca input:focus { border-color: var(--aksen); outline: none; }
        table.tbca td.no { color: var(--redup); width: 30px; padding-top: 10px; }
        table.tbca input.rupiah { text-align: right; font-variant-numeric: tabular-nums; }
        table.tbca .hapus { background: none; border: none; color: var(--redup); font-size: 18px; cursor: pointer; padding-top: 6px; }
        .ib-kaki { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-top: 10px; }
        .saran { position: absolute; left: 0; right: 0; top: 100%; z-index: 20; margin-top: 2px; background: var(--kartu-2); border: 1px solid var(--garis-kuat);
            border-radius: 8px; box-shadow: 0 10px 28px rgba(0, 0, 0, .55); max-height: 300px; overflow-y: auto; min-width: 240px; }
        .saran .judul-saran { padding: 6px 12px; font-size: 12px; color: var(--redup); background: var(--latar); border-bottom: 1px solid var(--garis); }
        .saran button { display: flex; width: 100%; justify-content: space-between; gap: 12px; align-items: baseline; text-align: left;
            padding: 7px 12px; border: 0; border-bottom: 1px solid var(--garis); background: none; cursor: pointer; font: inherit; font-size: 13px; color: var(--teks); }
        .saran button.sorot, .saran button:hover { background: var(--hijau-muda); }
        .panduan ol { margin: 6px 0 6px 18px; padding: 0; line-height: 1.7; }
        .panduan code { background: var(--kartu-2); padding: 1px 5px; border-radius: 4px; }
        .label.biru { background: #16304d; color: #9cc7ff; }
    </style>

    <div class="ringkas">
        @foreach (['menunggu' => '', 'proses' => 'biru', 'terinput' => 'hijau', 'dilewati' => 'kuning', 'gagal' => 'merah'] as $k => $w)
            <a class="kartu" href="{{ route('kas.input-bca', ['status' => $k]) }}" style="text-decoration: none; color: inherit;">
                <span class="redup">{{ \App\Models\InputBca::STATUS[$k] }}</span>
                <b>{{ (int) ($jumlah[$k]->n ?? 0) }}</b>
                <span class="redup" style="font-size: 12px;">{{ rp((int) ($jumlah[$k]->s ?? 0)) }}</span>
            </a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('kas.input-bca.simpan') }}" class="kartu ib-kartu" id="form-ib">
        @csrf
        <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
            <h3 style="margin: 0;">Tambah transfer ke antrean</h3>
            <span class="redup" style="font-size: 13px;">Dari rekening BCA PT MNP {{ $rekening ?? '(belum diisi di Transfer Massal BCA)' }} · rekening tujuan harus sudah ada di <b>Daftar Transfer</b> KlikBCA Bisnis · masuk <b>Otorisasi Transaksi</b> (uang keluar setelah disetujui approver).</span>
        </div>
        @if ($errors->any())
            <div class="pesan galat"><b>Periksa lagi:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
        @endif
        <table class="tbca">
            <thead><tr><th>#</th><th style="width: 24%;">Nama penerima</th><th style="width: 14%;">Bank</th><th style="width: 15%;">No rekening</th><th style="width: 13%;">Nominal</th>
                <th>Berita <span class="redup">(maks. 60)</span></th><th style="width: 11%;">Layanan</th><th></th></tr></thead>
            <tbody id="daftar-ib"></tbody>
        </table>
        <datalist id="penerima-dikenal">@foreach ($penerima as $p)<option value="{{ $p['nama'] }}">{{ $p['bank'] }} · {{ $p['rekening'] }}</option>@endforeach</datalist>
        <div class="ib-kaki">
            <button type="button" class="tombol polos" id="tambah-ib">+ Tambah baris</button>
            <span class="redup" style="font-size: 12px;">Bisa tempel dari Excel (nama, bank, rekening, nominal, berita). Layanan bank lain otomatis: BI-FAST ≤ Rp 250 jt, RTGS di atasnya.</span>
            <span style="margin-left: auto; font-size: 15px;"><span id="jumlah-ib">0</span> transfer · <b id="total-ib" style="font-size: 18px;">Rp 0</b></span>
            <button type="submit" class="tombol">Masukkan ke antrean</button>
        </div>
    </form>

    <div class="kartu gulir ib-kartu" style="padding: 0;">
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 12px 14px 6px;">
            <h3 class="dash-judul" style="margin: 0; color: var(--aksen-terang);">Antrean & riwayat</h3>
            <a href="{{ route('kas.input-bca') }}" @class(['chip', 'aktif' => ! $status])>Semua</a>
            @foreach (\App\Models\InputBca::STATUS as $k => $l)
                <a href="{{ route('kas.input-bca', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $l }}</a>
            @endforeach
        </div>
        <table class="kas" style="font-size: 13px;">
            <thead><tr><th>#</th><th>Penerima</th><th>Bank · rekening</th><th class="angka">Nominal</th><th>Berita</th><th>Status</th><th>No. Referensi / keterangan</th><th>Dibuat</th><th></th></tr></thead>
            <tbody>
                @forelse ($daftar as $d)
                    @php($cek = $d->perluCek())
                    <tr>
                        <td class="redup">{{ $d->id }}</td>
                        <td><b>{{ $d->nama }}</b></td>
                        <td>{{ $d->bank }} · {{ $d->rekening }}@if ($d->layanan) <span class="redup">· {{ \App\Models\InputBca::LAYANAN[$d->layanan] }}</span>@endif</td>
                        <td class="angka"><b>{{ rp($d->nominal) }}</b></td>
                        <td>{{ $d->berita }}</td>
                        <td style="white-space: nowrap;">
                            @if ($cek)<span class="label merah" title="Skrip berhenti di tengah proses. Cek di KlikBCA Bisnis → Otorisasi Transaksi apakah transfer ini sudah masuk.">⚠ Perlu dicek</span>
                            @else<span @class(['label', 'biru' => $d->status === 'proses', 'hijau' => $d->status === 'terinput', 'kuning' => $d->status === 'dilewati', 'merah' => $d->status === 'gagal'])>{{ \App\Models\InputBca::STATUS[$d->status] }}</span>@endif
                        </td>
                        <td style="font-size: 12px;">@if ($d->no_referensi)<b>{{ $d->no_referensi }}</b><br>@endif<span class="redup">{{ $d->pesan }}</span>
                            @if ($d->selesai_pada)<br><span class="redup">{{ $d->selesai_pada->translatedFormat('d-M-Y H:i') }}</span>@endif</td>
                        <td class="redup" style="font-size: 12px; white-space: nowrap;">{{ $d->user?->name }}<br>{{ $d->created_at->translatedFormat('d-M H:i') }}</td>
                        <td style="white-space: nowrap;">
                            @if ($d->status === 'menunggu')
                                <form method="POST" action="{{ route('kas.input-bca.status', $d) }}" style="display: inline;" onsubmit="return confirm('Batalkan transfer ke {{ $d->nama }}?')">@csrf<input type="hidden" name="aksi" value="batal"><button class="tombol-hapus">Batalkan</button></form>
                            @endif
                            @if (in_array($d->status, ['gagal', 'dilewati', 'batal'], true) || $cek)
                                <form method="POST" action="{{ route('kas.input-bca.status', $d) }}" style="display: inline;" @if ($cek) onsubmit="return confirm('Pastikan transfer ini TIDAK ada di Otorisasi Transaksi KlikBCA Bisnis, supaya tidak terinput dua kali. Ulangi?')" @endif>@csrf<input type="hidden" name="aksi" value="ulangi"><button class="tombol-edit">↻ Ulangi</button></form>
                            @endif
                            @if ($cek)
                                <form method="POST" action="{{ route('kas.input-bca.status', $d) }}" style="display: inline;" onsubmit="this.no_referensi.value = prompt('No. Referensi dari KlikBCA Bisnis (boleh kosong):') ?? ''; return true;">@csrf<input type="hidden" name="aksi" value="terinput"><input type="hidden" name="no_referensi"><button class="tombol-edit">✓ Sudah masuk</button></form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="redup" style="padding: 14px;">Belum ada transfer.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <details class="kartu ib-kartu panduan" @if (session('token_baru')) open @endif>
        <summary style="cursor: pointer; font-weight: 700;">📘 Cara menjalankan skrip di laptop</summary>
        <ol>
            <li>Buka terminal (PowerShell) di folder <code>C:\Laravel\otomasi-bank</code>.</li>
            <li>Jalankan <code>node input-bca.mjs</code> (atau <code>node input-bca.mjs --coba</code> untuk uji: berhenti di halaman konfirmasi, tidak menekan Kirim).</li>
            <li>Edge terbuka → buka KlikBCA Bisnis & <b>login sendiri</b> (KeyBCA). Skrip menunggu sampai menu utama tampil, lalu menginput semua transfer berstatus <b>Menunggu</b>.</li>
            <li>Setelah selesai, approver menyetujui di <b>Transfer Dana → Otorisasi Transaksi</b> dengan KeyBCA.</li>
        </ol>
        @if (auth()->user()->isSuperAdmin())
            <div style="margin-top: 8px;">
                <b>Token skrip</b> (disimpan di file <code>.env</code> skrip, baris <code>ERP_TOKEN=…</code>):
                {{ $adaToken ? 'sudah dibuat.' : 'belum dibuat.' }}
                <form method="POST" action="{{ route('kas.input-bca.token') }}" style="display: inline;" onsubmit="return confirm('Buat token baru? Token lama langsung tidak berlaku.')">@csrf<button class="tombol polos" style="padding: 4px 10px; font-size: 13px;">{{ $adaToken ? 'Ganti token' : 'Buat token' }}</button></form>
                @if (session('token_baru'))
                    <div class="pesan" style="margin-top: 8px;">Token baru (tampil sekali, salin sekarang): <code style="user-select: all;">{{ session('token_baru') }}</code></div>
                @endif
            </div>
        @endif
    </details>

    <template id="templat-ib">
        <tr>
            <td class="no"></td>
            <td><input type="text" data-nama="nama" list="penerima-dikenal" autocomplete="off" maxlength="100"></td>
            <td><input type="text" data-nama="bank" autocomplete="off"></td>
            <td><input type="text" data-nama="rekening" inputmode="numeric" autocomplete="off"></td>
            <td><input type="text" data-nama="nominal" class="rupiah" inputmode="numeric" autocomplete="off"></td>
            <td><input type="text" data-nama="berita" maxlength="60" autocomplete="off" placeholder="mis. UJ 9 Okt"></td>
            <td><select data-nama="layanan"><option value="">Otomatis</option>@foreach (\App\Models\InputBca::LAYANAN as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></td>
            <td><button type="button" class="hapus" title="Hapus baris">×</button></td>
        </tr>
    </template>

    <script src="{{ asset('js/pilih-bank.js') }}"></script>
    <script src="{{ asset('js/tempel-tabel.js') }}"></script>
    <script>
        (() => {
            const BANK = @json($bank);
            const PENERIMA = @json($penerima);
            const awal = @json(old('penerima', []));
            const form = document.getElementById('form-ib');
            const daftar = document.getElementById('daftar-ib');
            const templat = document.getElementById('templat-ib');
            const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);
            const angka = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0;
            const baris = () => [...daftar.querySelectorAll(':scope > tr')];
            const sel = (tr, f) => tr.querySelector(`[data-nama=${f}]`);
            const urutkan = () => baris().forEach((tr, i) => {
                tr.querySelector('.no').textContent = i + 1;
                tr.querySelectorAll('[data-nama]').forEach(el => el.name = `penerima[${i}][${el.dataset.nama}]`);
            });
            const hitung = () => {
                const isi = baris().filter(tr => sel(tr, 'nama').value.trim() || angka(sel(tr, 'nominal').value));
                document.getElementById('jumlah-ib').textContent = isi.length;
                document.getElementById('total-ib').textContent = 'Rp ' + fmt(isi.reduce((s, tr) => s + angka(sel(tr, 'nominal').value), 0));
                // Sesama BCA tidak memakai layanan transfer.
                baris().forEach(tr => { const bca = /^bca$/i.test(sel(tr, 'bank').value.trim()); sel(tr, 'layanan').disabled = bca; if (bca) sel(tr, 'layanan').value = ''; });
            };
            const isiDariNama = tr => {
                const p = PENERIMA.find(x => x.nama.toLowerCase() === sel(tr, 'nama').value.trim().toLowerCase());
                if (!p) return;
                if (!sel(tr, 'bank').value.trim()) { sel(tr, 'bank').value = p.bank; sel(tr, 'bank').rapikan?.(); }
                if (!sel(tr, 'rekening').value.trim()) sel(tr, 'rekening').value = p.rekening;
                hitung();
            };
            const tambah = (isi = {}) => {
                const tr = templat.content.firstElementChild.cloneNode(true);
                tr.querySelectorAll('[data-nama]').forEach(el => el.value = isi[el.dataset.nama] ?? '');
                daftar.appendChild(tr);
                PilihBank.pasang(sel(tr, 'bank'), BANK, {sering: ['BCA', 'BRI', 'Mandiri', 'BNI', 'BSI', 'Seabank', 'Jago']});
                if (sel(tr, 'nominal').value) sel(tr, 'nominal').value = fmt(angka(sel(tr, 'nominal').value));
                tr.querySelector('.hapus').addEventListener('click', () => { if (baris().length > 1) { tr.remove(); urutkan(); hitung(); } });
                sel(tr, 'nama').addEventListener('change', () => isiDariNama(tr));
                sel(tr, 'bank').addEventListener('change', hitung);
                sel(tr, 'bank').addEventListener('blur', () => setTimeout(hitung, 200));
                urutkan();
                return tr;
            };
            daftar.addEventListener('input', e => {
                if (e.target.dataset.nama === 'nominal') { const d = angka(e.target.value); e.target.value = d ? fmt(d) : ''; }
                if (e.target.dataset.nama === 'rekening') e.target.value = e.target.value.replace(/[^\d]/g, '');
                hitung();
            });
            document.getElementById('tambah-ib').addEventListener('click', () => { sel(tambah(), 'nama').focus(); hitung(); });
            (awal.length ? awal : [{}, {}, {}]).forEach(r => tambah(r));
            TempelTabel.pasang(daftar, {baris, tambah: () => tambah({}), setelah: rows => { rows.forEach(tr => {
                sel(tr, 'nominal').value = angka(sel(tr, 'nominal').value) ? fmt(angka(sel(tr, 'nominal').value)) : '';
                sel(tr, 'rekening').value = sel(tr, 'rekening').value.replace(/[^\d]/g, '');
                sel(tr, 'bank').rapikan?.();
                isiDariNama(tr);
            }); urutkan(); hitung(); }});
            hitung();
            form.addEventListener('submit', e => {
                const isi = baris().filter(tr => [...tr.querySelectorAll('input')].some(el => el.value.trim()));
                if (!isi.length) { e.preventDefault(); alert('Isi minimal satu transfer.'); return; }
                baris().filter(tr => !isi.includes(tr)).forEach(tr => tr.querySelectorAll('input,select').forEach(i => i.disabled = true));
            });
        })();
    </script>
@endsection
