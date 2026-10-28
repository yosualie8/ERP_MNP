@extends('layouts.app', ['judul' => 'Input UJ'])

@section('lebar', '1440px')

@section('isi')
    <style>
        .kartu-rui { border-color: var(--aksen); }
        .rui-grup { border: 1px solid var(--garis); border-radius: 9px; margin-bottom: 8px; overflow: hidden; }
        .rui-master { display: flex; align-items: center; gap: 12px; padding: 10px 12px; cursor: pointer; background: var(--kartu-2); }
        .rui-master:hover { background: var(--aksen-muda); }
        .rui-master.sudah { cursor: default; opacity: .75; }
        .rui-master.sudah:hover { background: var(--kartu-2); }
        .rui-buka { background: none; border: 1px solid var(--garis-kuat); color: var(--teks); border-radius: 6px; width: 28px; height: 28px; cursor: pointer; flex: none; }
        .rui-buka:hover { border-color: var(--aksen); }
        .rui-grup.terbuka .rui-buka { transform: rotate(90deg); }
        .rui-judul { flex: 1; min-width: 0; }
        .rui-judul b { display: block; }
        .rui-total { font-variant-numeric: tabular-nums; font-weight: 700; white-space: nowrap; }
        .rui-detail { padding: 6px 10px 10px; max-height: 420px; overflow: auto; }
        .rui-detail table { font-size: 12.5px; width: 100%; }
        .rui-detail td, .rui-detail th { padding: 4px 6px; text-align: left; }
        .rui-detail .angka { text-align: right; }
        .form-kas label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .form-kas input[type=text], .form-kas input[type=date] {
            width: 100%; padding: 8px 10px; border: 1px solid var(--garis); border-radius: 7px; font-size: 14px; background: var(--isian); color: var(--teks); }
        .form-kas input.angka-input { text-align: right; font-variant-numeric: tabular-nums; }
        table.bon input[data-nama] { border-color: var(--isian-garis); }
        .form-kas input[type=text]:focus, .form-kas input[type=date]:focus { outline: none; border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen); }
        table.bon input[data-nama]:focus { background: var(--isian-fokus); }
        .form-kas input.otomatis { background: var(--otomatis-latar); border-color: var(--otomatis-garis); }
        /* Saran Kategori dari Keterangan (seperti saran Kode GL di Input Kas). */
        /* Pilihan No DO = DO uang jalan DT itu (aturan 19: transaksi lanjutan memakai DO uang jalannya, tidak diketik ulang). */
        .saran-kode, .saran-do { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
        .saran-kode button, .saran-do button { border: 1px solid var(--garis-kuat); background: var(--kartu-2); border-radius: 999px; padding: 1px 8px; font-size: 11px; color: var(--redup); cursor: pointer; }
        .saran-kode button:hover, .saran-do button:hover { border-color: var(--aksen); color: var(--teks); }
        .saran-kode button.dipilih, .saran-do button.dipilih { background: var(--aksen-muda); border-color: var(--aksen); color: var(--aksen-terang); }
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
        table.bon tr.temuan-baris td, table.bon tr.kurang-baris td { padding: 0 4px 10px; }
        /* Baris detail yang sudah valid: hijau. */
        table.bon tr.baris-detail.valid td { background: rgba(76, 195, 138, .24); }
        table.bon tr.baris-detail.valid td:first-child { box-shadow: inset 4px 0 0 var(--sukses); }
        table.bon tr.baris-detail.valid input[data-nama] { background: #143523; border-color: #3fa06f; color: #eafff2; }
        table.bon tr.baris-detail.valid + tr.temuan-baris .temuan { border-color: #2f7a55; border-left-color: var(--sukses); background: #10241a; }
        table.bon tr.baris-detail.valid + tr.temuan-baris .judul-temuan { color: var(--sukses); }
        table.bon tr.baris-detail.valid .hapus { color: var(--redup); }
        .kurang { border: 1px solid rgba(255, 92, 97, .35); border-left: 4px solid var(--aksen); background: var(--merah-muda); color: var(--merah);
            border-radius: 8px; padding: 6px 12px; font-size: 13px; }
        .temuan .status-konf { margin-top: 4px; font-size: 12px; color: var(--merah); }
        .temuan .status-konf.ok { color: var(--sukses); }
        .temuan { border: 1px solid #6b4f10; border-left: 4px solid #e0a526; background: #1d1810; border-radius: 8px; padding: 8px 12px; font-size: 13px; }
        .temuan .judul-temuan { color: #f0c05a; font-weight: 600; margin-bottom: 4px; }
        .temuan ul { margin: 0 0 8px; padding-left: 18px; }
        .temuan li { margin: 3px 0; line-height: 1.45; }
        .temuan .prioritas { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 0 6px; border-radius: 4px; }
        .temuan .prioritas.tinggi { background: var(--aksen); color: #fff; }
        .temuan .prioritas.sedang { background: #b8860b; color: #fff; }
        .temuan .prioritas.rendah { background: #3b404b; color: #dfe2e7; }
        .temuan .prioritas.info { background: #2f6f9f; color: #fff; }
        .saldo-sopir { margin-top: 4px; font-size: 11.5px; color: #93c5fd; }
        .saldo-sopir[hidden] { display: none; }
        .saldo-sopir input { width: 100%; margin-top: 2px; padding: 3px 6px; font-size: 12px; border-color: #3b6ea5; }
        .saldo-sopir button { padding: 1px 8px; font-size: 11px; margin-left: 4px; }
        .saldo-sopir.belum-diisi input { border-color: #f0c05a; background: rgba(240, 192, 90, .08); }
        .sopir-baru { margin-top: 4px; padding: 4px 6px; font-size: 11.5px; line-height: 1.5; border: 1px solid #f0c05a; border-radius: 6px; background: rgba(240, 192, 90, .08); min-width: 220px; white-space: normal; }
        .sopir-baru.terjawab { border-color: var(--sukses, #3ecf8e); }
        .sopir-baru label { display: block; margin: 0; font-weight: 400; color: var(--teks); cursor: pointer; }
        .sopir-baru select { font-size: 11.5px; padding: 1px 4px; max-width: 150px; }
        .sopir-baru .saran-nama { margin-bottom: 3px; padding-bottom: 3px; border-bottom: 1px dashed #f0c05a; }
        .sopir-baru .saran-sopir { padding: 0 8px; font-size: 11.5px; margin: 1px 0; border-color: #3ecf8e; color: #3ecf8e; }
        .saran-semua { margin: 0 0 8px; padding: 6px 10px; font-size: 13px; border: 1px solid #3ecf8e; border-radius: 8px; background: rgba(62, 207, 142, .08); }
        .temuan .aturan { color: var(--redup); font-size: 11px; }
        .temuan label { display: block; color: var(--teks); font-size: 12px; margin: 0; }
        .aksi-massal-pj { border: 1px solid var(--aksen); background: var(--aksen-muda); border-radius: 8px; padding: 8px 12px; margin-bottom: 10px; }
        .aksi-massal-pj[hidden] { display: none; }
        .pj-info { font-size: 13px; padding: 4px 8px; background: var(--otomatis-latar, #f3eefc); border-left: 4px solid var(--otomatis-garis, #8a63d2); border-radius: 4px; }
        .pj-calon { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; margin-top: 4px; }
        .pj-calon .tombol { padding: 3px 10px; font-size: 12px; text-align: left; }
        .baris-massal-pj { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px; padding: 3px 0; }
        .duluan-tanya { margin: 6px 0 8px; padding: 8px 10px; border: 1px solid #e0a526; border-radius: 8px; background: rgba(224, 165, 38, .08); }
        .duluan-tanya .rinci { font-size: 12px; margin: 4px 0 6px; }
        .duluan-tanya .rinci b { color: var(--teks); }
        .duluan-tanya.ditolak { border-color: var(--aksen); background: rgba(229, 72, 77, .10); }
        tr.duluan-info td { padding: 2px 8px 8px; font-size: 12px; color: var(--sukses); }
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
        .catatan-rek { margin-top: 6px; font-size: 12.5px; color: #f0c05a; }
        .catatan-rek button { margin-left: 6px; font-size: 12px; padding: 2px 8px; border-radius: 6px; border: 1px solid var(--garis-kuat); background: var(--kartu-2); color: var(--teks); cursor: pointer; }
        .saran mark { background: var(--tanda); color: inherit; padding: 0; }
        .kartu-foto .penampil { height: 62vh; margin-top: 10px; }
        .gambar-kecil .tambah-foto { width: 68px; height: 68px; border: 2px dashed var(--garis); color: var(--redup); font-size: 26px; }
        .gambar-kecil .tambah-foto:hover { border-color: var(--aksen); color: var(--aksen); }
        .kartu-foto .penampil.kosong { height: 120px; min-height: 0; }
        .kartu-foto .penampil.kosong .penampil-alat, .kartu-foto .penampil.kosong .penampil-petunjuk { display: none; }
        .info-foto { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-size: 12px; color: var(--redup); margin-top: 6px; }
        @media (max-width: 760px) { .kartu-foto .penampil { height: 50vh; } }
        /* Realisasi pengajuan */
        .kartu-rui { border-color: var(--aksen); }
        .mode-rui { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; border: 1px solid var(--aksen); background: var(--aksen-muda); border-radius: 8px; padding: 8px 12px; margin-bottom: 12px; font-size: 14px; }
        .mode-rui[hidden] { display: none; }
        table.bon tr.dari-pengajuan td:first-child { box-shadow: inset 4px 0 0 var(--otomatis-garis); }
        table.bon tr.beda-baris td { padding: 0 4px 10px; }
        .beda-pj { border: 1px solid #3d4f86; border-left: 4px solid #6c8cff; background: #121a2e; border-radius: 8px; padding: 8px 12px; font-size: 13px; }
        .beda-pj.dialihkan { border-color: #7a4a86; border-left-color: #c77dff; background: #1d1426; }
        .beda-pj .judul-beda { font-weight: 600; margin-bottom: 4px; color: #a9bbff; }
        .beda-pj.dialihkan .judul-beda { color: #dcb3ff; }
        .beda-pj ul { margin: 0 0 8px; padding-left: 18px; }
        .beda-pj li { margin: 2px 0; }
        .beda-pj del { color: var(--redup); }
        .beda-pj label { display: block; color: var(--teks); font-size: 12px; margin: 0; }
        .beda-pj input[data-alasan] { margin-top: 4px; }
        .beda-pj input[data-alasan].kosong { border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen-muda); }
        .beda-pj .status-alasan { margin-top: 4px; font-size: 12px; color: var(--merah); }
        .beda-pj .status-alasan.ok { color: var(--sukses); }
    </style>

    @php($edit ??= null)
    {{-- Mode "pengajuan": form & validasi sama persis, tetapi disimpan sebagai Pengajuan UJ di aplikasi (tanpa foto & biaya transfer). --}}
    @php($ajukan = ($mode ?? null) === 'pengajuan')
    @php($aksi = $ajukan ? ($edit ? route('pengajuan-uj.simpan-ubah', $edit['pengajuan_id']) : route('pengajuan-uj.simpan')) : ($edit ? route('uj.update', $edit['no_uj']) : route('uj.store')))
    <form method="POST" action="{{ $aksi }}" class="form-kas" id="form-kas" enctype="multipart/form-data">
        @if ($edit)
            @method('PUT')
            @if ($ajukan)
                <input type="hidden" name="pengajuan_id" value="{{ $edit['pengajuan_id'] }}">
                <input type="hidden" name="menunggu" value="{{ $edit['menunggu'] ?? '' }}">
            @else
                <input type="hidden" name="versi" value="{{ $edit['versi'] }}">
                <input type="hidden" name="no_uj" value="{{ $edit['no_uj'] }}">
            @endif
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

        @unless ($ajukan)
            @include('mutasi-jago._pilih', ['kantong' => 'uj'])
        @endunless
        @if ($pesanPengajuan ?? null)
            <div class="pesan galat">{{ $pesanPengajuan }}</div>
        @endif
        @if (old('bayar_tanah_id'))<input type="hidden" name="bayar_tanah_id" value="{{ old('bayar_tanah_id') }}">@endif
        @if (! empty($dariBayarTanah) && ! old('detail'))
            <input type="hidden" name="bayar_tanah_id" value="{{ $dariBayarTanah['rekap']['id'] ?? '' }}">
            <div class="pesan sukses" id="petunjuk-bayar-tanah">🏔️ <b>Bayar tanah{{ ($dariBayarTanah['rekap'] ?? null) ? ' '.$dariBayarTanah['rekap']['kode'] : '' }}</b> · {{ count($dariBayarTanah['detail']) }} DO
                (Rp {{ number_format(array_sum(array_map('intval', array_column($dariBayarTanah['detail'], 'nominal'))), 0, ',', '.') }}) sudah terisi:
                keterangan & nominal mengikuti pembayaran uang tanah terakhir galian itu, rekening penerima dari pembayaran terakhir ke galian yang sama.
                Periksa dulu, lalu klik Simpan.
                @if ($dariBayarTanah['catatan'])<ul style="margin: 6px 0 0; padding-left: 18px; color: #f0c05a;">@foreach ($dariBayarTanah['catatan'] as $c)<li>{{ $c }}</li>@endforeach</ul>@endif
            </div>
        @endif
        @if (! empty($dariPengajuan) && ! old('detail'))
            <div class="pesan sukses" id="petunjuk-pengajuan">📝 {{ count($dariPengajuan) }} transaksi dari pengajuan
                ({{ collect($dariPengajuan)->pluck('kode')->unique()->implode(', ') }} · Rp {{ number_format(array_sum(array_column($dariPengajuan, 'nominal')), 0, ',', '.') }})
                sudah terisi persis seperti pengajuannya. Isi <b>nama, rekening & bank penerima transfer</b>, lalu klik Simpan — semuanya tercatat sebagai satu transfer.</div>
        @endif
        @unless ($edit)
            {{-- Input bayar tanah: rekap bayar tanah (RBT) dari menu Bayar Tanah — tampilan sama dengan Input reimburse Kas UJ. --}}
            <div class="kartu kartu-rui" id="panel-bt" hidden>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <h3 style="margin: 0;">🏔️ Bayar Tanah <span class="redup" style="font-size: 13px; font-weight: normal;">per rekap bayar tanah (30 hari terakhir)</span></h3>
                    <button type="button" class="tombol polos" id="tutup-bt">Tutup</button>
                </div>
                <p class="redup" style="margin: 6px 0 12px;">Klik ▸ untuk melihat rinciannya. Klik baris rekapnya untuk memasukkan semua DO itu ke form di bawah, lalu periksa dan tekan <b>Simpan</b>.
                    Rekap dibuat di menu Ritasi → Bayar Tanah.</p>
                <div id="daftar-bt"><p class="redup">Memuat…</p></div>
            </div>
        @endunless
        <div class="kartu">
            <div class="mode-rui" id="mode-pengajuan" hidden>
                <span>📝 <b>Realisasi pengajuan</b> · <span id="mode-pengajuan-isi"></span> — setelah disimpan, detail itu tercatat terealisasi dengan ID UJ-nya.</span>
                <button type="button" class="tombol polos" id="lepas-pengajuan" style="padding: 4px 10px; font-size: 13px;">Lepas tautan</button>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;">
            <h3 style="margin: 0;">
                @if ($ajukan)
                    {{ $edit ? 'Edit Pengajuan Uang Jalan' : 'Pengajuan Uang Jalan' }} <span class="redup" style="font-size: 13px; font-weight: normal;">{{ $edit['kode'] ?? 'belum ditransfer · divalidasi sama seperti Input UJ' }}</span>
                    @unless ($edit)<button type="button" class="tombol polos" id="buka-bt" style="margin-left: 10px; padding: 5px 12px; font-size: 13px;" title="Isi otomatis dari rekap bayar tanah (menu Ritasi → Bayar Tanah)">🏔️ Dari rekap bayar tanah</button>@endunless
                @elseif ($edit)
                    Edit Transaksi Master UJ <span class="redup" style="font-size: 13px; font-weight: normal;">UJ-{{ $edit['no_uj'] }} · Kas Seabank baris {{ $edit['baris'] }}</span>
                @else
                    Input Transaksi Master UJ <span class="redup" style="font-size: 13px; font-weight: normal;">rekening Bank Jago · uang jalan dump truck</span>
                    <button type="button" class="tombol polos" id="buka-bt" style="margin-left: 10px; padding: 5px 12px; font-size: 13px;">🏔️ Input bayar tanah</button>
                @endif
            </h3>
            </div>
            <div class="baris2">
                <div>
                    <label for="tanggal">{{ $ajukan ? 'Tanggal pengajuan' : 'Tanggal' }}</label>
                    <input type="date" name="tanggal" id="tanggal" value="{{ old('tanggal', $edit['tanggal'] ?? now()->toDateString()) }}" required>
                </div>
                {{-- Pengajuan UJ: keterangan / keperluan pengajuan (owner 22 Okt 2026), wajib — tampil di grid Pengajuan UJ. --}}
                @if ($ajukan)
                    <div style="grid-column: span 2;">
                        <label for="keterangan_pengajuan">Keterangan pengajuan <span class="redup" style="font-weight: 400;">(keperluannya untuk apa)</span></label>
                        <input type="text" name="keterangan_pengajuan" id="keterangan_pengajuan" value="{{ old('keterangan_pengajuan', $edit['keterangan_pengajuan'] ?? '') }}"
                            maxlength="300" required autocomplete="off" placeholder="mis. UJ + uang tanah 6 rit Jogja (RKS) Docin & Evi, 22 Okt">
                    </div>
                @endif
                {{-- Pengajuan = daftar transaksi yang diajukan: belum ada penerima transfer, rekening, maupun nominal master. --}}
                <div style="grid-column: span 2;{{ $ajukan ? ' display: none;' : '' }}" class="isian-saran">
                    <label for="nama_tujuan">Nama penerima transfer</label>
                    <input type="text" name="nama" id="nama_tujuan" value="{{ old('nama', $edit['nama'] ?? ($dariBayarTanah['master']['nama'] ?? (count($driverPengajuan = collect($dariPengajuan ?? [])->pluck('nama')->filter()->unique()) === 1 ? $driverPengajuan->first() : ''))) }}" autocomplete="off" required placeholder="Ketik nama, pilih rekeningnya dari daftar">
                    <div class="saran" id="saran-nama" hidden></div>
                    <div class="catatan-rek" id="catatan-rek" hidden></div>
                </div>
            </div>
            <div class="baris2" @if ($ajukan) style="display: none;" @endif>
                <div class="isian-saran">
                    <label for="no_rek">No. rekening / e-wallet</label>
                    <input type="text" name="rekening" id="no_rek" value="{{ old('rekening', $edit['rekening'] ?? ($dariBayarTanah['master']['rekening'] ?? '')) }}" inputmode="numeric" autocomplete="off" placeholder="Atau ketik nomornya">
                    <div class="saran" id="saran-norek" hidden></div>
                </div>
                <div style="grid-column: span 2;">
                    <label for="bank">Bank / e-wallet</label>
                    <div class="isian-saran" style="max-width: 360px; margin-bottom: 6px;"><input type="text" name="bank" id="bank" value="{{ old('bank', $edit['bank'] ?? ($dariBayarTanah['master']['bank'] ?? '')) }}" autocomplete="off" placeholder="Ketik singkatan / nama bank / e-wallet, mis. BCA, GoPay"></div>
                </div>
            </div>
            <div style="max-width: 260px;{{ $ajukan ? ' display: none;' : '' }}">
                <label for="nominal_transfer">Nominal master <span class="redup">(yang ditransfer, tanpa biaya transfer)</span></label>
                <input type="text" name="nominal" id="nominal_transfer" class="angka-input rupiah" inputmode="numeric" autocomplete="off" value="{{ old('nominal', $edit['nominal'] ?? (($dariPengajuan ?? []) ? array_sum(array_column($dariPengajuan, 'nominal')) : ($dariBayarTanah ? (array_sum(array_map('intval', array_column($dariBayarTanah['detail'], 'nominal'))) ?: '') : ''))) }}" required>
            </div>
        </div>

        <div class="kartu kartu-foto" @if ($ajukan) style="display: none;" @endif>
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
                <h3 style="margin: 0 0 4px;">{{ $ajukan ? 'Daftar transaksi yang diajukan' : 'Transaksi detail' }}</h3>
                <span class="redup">{{ $ajukan ? 'Total pengajuan' : 'Jumlah detail' }} <span class="total-bon" id="total-bon">0</span>@unless ($ajukan) dari nominal master <b id="nilai-transfer">0</b>@else<b id="nilai-transfer" hidden>0</b><span id="ringkas-saldo" hidden> · dari saldo sopir <b id="total-saldo">0</b> · <b>ditransfer <span id="total-ditransfer">0</span></b></span>@endunless</span>
            </div>
            <div id="status-cocok" class="pesan" style="margin: 8px 0 10px; padding: 8px 12px;{{ $ajukan ? ' display: none;' : '' }}"></div>
            @if ($ajukan && $edit && count($edit['terkunci'] ?? []))
                @php($labelKunci = ['terealisasi' => 'Terealisasi', 'dialihkan' => 'Dialihkan', 'batal' => 'Dibatalkan'])
                <div class="gulir" style="margin: 8px 0 14px;">
                    <p style="margin: 0 0 6px;">🔒 <b>{{ count($edit['terkunci']) }} detail sudah direalisasi / dibatalkan</b> <span class="redup">— tidak bisa diubah (Rp {{ number_format(collect($edit['terkunci'])->sum('nominal'), 0, ',', '.') }}, tidak termasuk total di bawah)</span></p>
                    <table class="bon" style="min-width: 1100px; opacity: .75;">
                        <thead><tr><th class="angka" style="width: 130px;">Nominal</th><th style="width: 150px;">Nama driver</th><th>Keterangan</th><th style="width: 160px;">Kategori</th><th style="width: 100px;">No Mobil</th><th style="width: 100px;">Jenis</th><th style="width: 100px;">No DO</th><th style="width: 150px;">Status</th></tr></thead>
                        <tbody>
                            @foreach ($edit['terkunci'] as $d)
                                <tr><td class="angka">{{ number_format($d->nominal, 0, ',', '.') }}</td><td>{{ $d->nama }}</td><td>{{ $d->keterangan }}</td><td>{{ $d->kategori }}</td><td>{{ $d->no_mobil }}</td><td>{{ $d->jenis_kendaraan }}</td><td>{{ $d->no_do }}</td>
                                    <td><span class="label {{ $d->status === 'batal' ? '' : 'hijau' }}">{{ $labelKunci[$d->status] ?? $d->status }}</span>@if ($d->id_uj) <span class="redup" style="font-size: 12px;">UJ-{{ $d->id_uj }}</span>@endif</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p style="margin: 10px 0 0;">✏️ <b>Detail yang masih menunggu realisasi</b> <span class="redup">— bisa diubah, ditambah, atau dihapus</span></p>
                </div>
            @endif
            <p class="redup" style="margin: 0 0 10px;">{{ $ajukan ? 'Satu baris = satu uang jalan yang diajukan, nanti direalisasikan per baris. Nama driver wajib diisi di setiap baris.' : 'Satu baris = satu ID UJ di sheet (nomornya dilanjutkan otomatis). Nama detail pertama mengikuti nama penerima.' }} Jenis kendaraan terisi otomatis dari No Mobil (ungu = otomatis, boleh diganti).</p>
            <div class="gulir">
            <table class="bon" style="min-width: 1100px;">
                <thead>
                    <tr>
                        <th style="width: 130px;" class="angka">Nominal</th>
                        <th style="width: 150px;">{{ $ajukan ? 'Nama driver' : 'Nama' }}</th>
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

            <div class="biaya-transfer" id="baris-biaya" @if ($ajukan) style="display: none;" @endif>
                <input type="hidden" name="biaya_transfer" value="0">
                <label><input type="checkbox" name="biaya_transfer" id="biaya_transfer" value="1" @checked(old('biaya_transfer', $edit['biaya_transfer'] ?? ($ajukan ? '0' : '1')) === '1')> Catat biaya transfer</label>
                <input type="text" name="nominal_biaya" id="nominal_biaya" class="angka-input rupiah" inputmode="numeric" autocomplete="off"
                    value="{{ old('nominal_biaya', $edit['nominal_biaya'] ?? \App\Support\TulisUjSheet::BIAYA_TRANSFER) }}">
                <span class="redup">baris "Biaya Transfer" di bawah detail (tanpa ID UJ, tidak dihitung di nominal master)</span>
            </div>
            @error('nominal_biaya')<p class="galat-isian">{{ $message }}</p>@enderror
            <p class="redup" id="catatan-biaya" style="margin: 4px 0 0 24px;" @if ($ajukan) hidden @endif></p>
        </div>

        @php($teksTombol = $ajukan ? ($edit ? 'Simpan perubahan pengajuan' : 'Ajukan') : ($edit ? 'Simpan perubahan ke sheet' : 'Simpan ke sheet'))
        <div id="hasil-validasi" hidden></div>
        <div style="display: flex; gap: 12px; align-items: center;">
            <button type="submit" class="tombol" id="simpan">{{ $teksTombol }}</button>
            @if ($edit)
                <a href="{{ $ajukan ? route('pengajuan-uj.daftar') : route('uj.index') }}" class="tombol polos">Batal</a>
            @endif
            <span class="redup">{!! $ajukan ? 'Disimpan sebagai pengajuan di aplikasi (belum ditulis ke sheet). Admin kantor menginput transaksinya di <i>Input UJ</i>, lalu menautkannya ke pengajuan ini (tombol <i>Tautkan</i> muncul otomatis bila DO-nya cocok).' : 'Ditulis ke lembar <i>Kas Seabank</i> (sheet KAS MMP Uang Jalan dan UM), di bawah data terakhir.' !!}</span>
        </div>
    </form>
    @foreach ($edit['foto'] ?? [] as $f)
        <form method="POST" action="{{ $f['hapus'] }}" id="hapus-foto-{{ $f['id'] }}" hidden>@csrf @method('DELETE')</form>
    @endforeach

    <template id="templat-bon">
        <tr class="baris-detail">
            <td><input type="hidden" class="pengajuan-kunci"><input type="hidden" class="duluan-kunci"><input type="text" class="angka-input rupiah" data-nama="nominal" inputmode="numeric" autocomplete="off" required>
                @if ($ajukan)<div class="saldo-sopir" hidden><span class="info-saldo"></span><button type="button" class="tombol polos pakai-saldo" title="Isi potongan sebesar sisa saldo (maks. nominal baris ini)">Potong penuh</button><button type="button" class="tombol polos tanpa-saldo" title="Tidak dipotong dari saldo — ditransfer penuh">Tidak dipotong</button>
                    <input type="text" class="angka-input rupiah" data-nama="saldo" data-lewati-tempel inputmode="numeric" autocomplete="off" placeholder="potongan saldo (wajib, 0 = tidak)" title="Bagian nominal yang dibayar dari saldo sopir — tidak ditransfer. Wajib diisi bila sopir punya saldo; 0 = tidak dipotong."></div>@endif</td>
            <td><input type="text" data-nama="nama" list="daftar-nama" autocomplete="off"></td>
            <td><input type="text" data-nama="keterangan" autocomplete="off" required></td>
            <td><input type="text" data-nama="kategori" list="daftar-kategori" autocomplete="off" required><div class="saran-kode"></div></td>
            <td><input type="text" data-nama="no_mobil" list="daftar-mobil" autocomplete="off"></td>
            <td><input type="text" data-nama="jenis_kendaraan" list="daftar-jenis" autocomplete="off"></td>
            <td><input type="text" data-nama="no_do" autocomplete="off"><div class="saran-do"></div></td>
            <td><button type="button" class="hapus" title="Hapus baris detail">×</button></td>
        </tr>
    </template>

    <script>
        (() => {
            const rekening = @json($rekening);
            const jenisMobil = @json($mobil);
            // Isian awal: input lama (bila gagal simpan) → data edit → detail pengajuan dari tombol "Input UJ" di menu Pengajuan UJ.
            const awal = @json(old('detail', $edit['detail'] ?? (($dariPengajuan ?? []) ?: ($dariBayarTanah['detail'] ?? []))));
            const AJUKAN = @json($ajukan);
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
                const a = tr.bedaEl?.querySelector('[data-alasan]');
                if (a) a.name = `detail[${i}][alasan_pengajuan]`;
                // Tautan ke detail Pengajuan UJ yang direalisasikan baris ini (hanya bila ditautkan lewat tombol "Tautkan").
                const p = tr.querySelector('.pengajuan-kunci');
                p.name = p.value ? `detail[${i}][pengajuan]` : '';
                const dl = tr.querySelector('.duluan-kunci');
                dl.name = dl.value ? `detail[${i}][duluan]` : '';
            });
            const nominalTransfer = document.getElementById('nominal_transfer');
            const statusCocok = document.getElementById('status-cocok');
            const tombolSimpan = document.getElementById('simpan');
            const hitung = () => {
                const total = [...daftar.querySelectorAll('[data-nama=nominal]')].reduce((s, el) => s + angka(el.value), 0);
                // Pengajuan: tidak ada nominal master — total pengajuan = jumlah transaksinya.
                if (AJUKAN) nominalTransfer.value = total ? fmt(total) : '';
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
            const aturNamaPertama = () => { const tr = barisDetail()[0]; if (tr && !AJUKAN) isiOtomatis(tr.querySelector('[data-nama=nama]'), nama.value.trim()); };
            // Rekening dipilih dari Daftar Rekening: penerima = pemilik rekening, tetapi kolom Nama baris pertama = SOPIR. Isi dengan sopir yang
            // dicari ("dipakai untuk Bambang"), atau pemilik bila rekening itu memang dipakai pemiliknya sendiri / belum pernah dipakai di Kas UJ.
            const isiSopirPertama = r => {
                const tr = barisDetail()[0];
                if (!tr || AJUKAN) return;
                const sopir = r.sopir || [];
                const nm = r.via && sopir.some(s => s.toLowerCase() === r.via.toLowerCase()) ? r.via
                    : (!sopir.length || sopir.some(s => s.toLowerCase() === (r.nama || '').toLowerCase()) ? r.nama : '');
                if (nm) isiOtomatis(tr.querySelector('[data-nama=nama]'), nm);
            };

            const tambah = (isi = {}) => {
                const tr = templat.content.firstElementChild.cloneNode(true);
                tr.querySelectorAll('[data-nama]').forEach(el => el.value = isi[el.dataset.nama] ?? '');
                tr.querySelector('.pengajuan-kunci').value = isi.pengajuan ?? '';
                if (isi.pengajuan) { tr.classList.add('dari-pengajuan'); tr.title = 'Dari pengajuan ' + (isi.kode ?? ''); }
                tr.querySelectorAll('input.rupiah').forEach(rapikanRupiah);
                tr.konfirmasi = isi.konfirmasi ?? '';
                tr.querySelector('.hapus').addEventListener('click', () => {
                    if (barisDetail().length > 1) { tr.temuan?.remove(); tr.kurangEl?.remove(); tr.bedaEl?.remove(); tr.duluanEl?.remove(); tr.pjEl?.remove(); tr.remove(); urutkanNama(); hitung(); aturNamaPertama(); }
                });
                daftar.appendChild(tr);
                urutkanNama();
                hitung();
                setTimeout(aturSaldo);
                return tr;
            };
            // Pengajuan UJ: saldo PER SOPIR (UJ yang dijadikan saldo sopir). Potongan ditentukan admin per baris (owner 21 Okt 2026, tidak otomatis):
            // bila sopir punya saldo, kolom potongan wajib diisi (0 = tidak dipotong; "Potong penuh" = sisa saldo). Bagian itu tidak ditransfer.
            // DO lama milik UJ yang dijadikan saldo tidak dipotong: saldonya terpakai sendiri begitu DO itu bongkar.
            const SALDO = @json($saldoSopir ?? []);
            const DO_LAMA = @json($doLamaSopir ?? []);
            // Master sopir (Data Karyawan, jabatan Supir): peta nama / nama lain → sopir; nama lain dikenali sebagai nama bakunya.
            const SOPIR = {!! json_encode($sopirForm ?? ['peta' => [], 'aktif' => [], 'truk' => []], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
            const kunciNama = v => String(v || '').toUpperCase().replace(/\s+/g, ' ').trim();
            const kunciSopir = v => { const k = kunciNama(v); return SOPIR.peta[k]?.nama || k; };
            const kunciDo = v => { const t = String(v || '').replace(/[`'\s]/g, ''); return t === '' ? '' : (t.replace(/^0+/, '') || '0'); };
            function aturSaldo() {
                if (!AJUKAN) return;
                const dipakai = {};
                let totalSaldo = 0, total = 0;
                barisDetail().forEach(tr => {
                    const box = tr.querySelector('.saldo-sopir');
                    if (!box) return;
                    const s = kunciSopir(tr.querySelector('[data-nama=nama]').value);
                    const inp = box.querySelector('[data-nama=saldo]');
                    const nominal = angka(tr.querySelector('[data-nama=nominal]').value);
                    const ada = s ? (SALDO[s] || 0) : 0;
                    const doLama = s && DO_LAMA[s]?.[kunciDo(tr.querySelector('[data-nama=no_do]').value)];
                    const sebelum = dipakai[s] || 0;
                    // Potongan TIDAK otomatis (owner 21 Okt 2026): admin menentukan jumlahnya per baris; bila sopir punya saldo, wajib diisi (0 = tidak dipotong).
                    if (doLama && inp.value) inp.value = '';
                    const pakai = angka(inp.value);
                    if (s) dipakai[s] = sebelum + pakai;
                    const sisa = ada - dipakai[s];
                    const wajib = ada > 0 && !doLama;
                    box.dataset.wajib = wajib && inp.value.trim() === '' ? '1' : '';
                    box.hidden = !(ada > 0 || pakai > 0);
                    inp.hidden = !!doLama;
                    box.querySelector('.info-saldo').textContent = doLama
                        ? `💰 Saldo ${s}: ${fmt(ada)} · DO ini DO lama ${doLama} — tidak dipotong, saldonya terpakai sendiri saat DO ini bongkar`
                        : ada > 0
                            ? (inp.value.trim() === ''
                                ? `💰 Saldo ${s}: ${fmt(Math.max(0, ada - sebelum))}${sebelum ? ` (setelah baris atas ${fmt(sebelum)})` : ''} — isi potongan untuk transaksi ini (0 = tidak dipotong)`
                                : `💰 Saldo ${s}: ${fmt(ada)} · dipotong ${fmt(pakai)} · sisa ${fmt(Math.max(0, sisa))}` + (sisa < 0 ? ' — MELEBIHI saldo' : ''))
                            : `⚠ ${s || 'Sopir ini'} tidak punya saldo sopir`;
                    box.querySelector('.pakai-saldo').hidden = !wajib || pakai >= Math.min(nominal, ada - sebelum);
                    box.querySelector('.tanpa-saldo').hidden = !wajib || inp.value.trim() === '0';
                    box.classList.toggle('belum-diisi', box.dataset.wajib === '1');
                    inp.style.borderColor = sisa < 0 || pakai > nominal ? 'var(--aksen)' : '';
                    totalSaldo += pakai; total += nominal;
                });
                const rk = document.getElementById('ringkas-saldo');
                if (rk) {
                    rk.hidden = !totalSaldo;
                    document.getElementById('total-saldo').textContent = fmt(totalSaldo);
                    document.getElementById('total-ditransfer').textContent = fmt(total - totalSaldo);
                }
            }
            if (AJUKAN) {
                daftar.addEventListener('input', () => aturSaldo());
                daftar.addEventListener('focusout', () => setTimeout(aturSaldo));
                daftar.addEventListener('click', e => {
                    const btn = e.target.closest('.pakai-saldo, .tanpa-saldo');
                    if (!btn) return;
                    const tr = btn.closest('tr');
                    const inp = tr.querySelector('[data-nama=saldo]');
                    if (btn.classList.contains('tanpa-saldo')) inp.value = '0';
                    else {
                        // Potong penuh = sisa saldo setelah baris lain sopir ini (maks. nominal baris ini)
                        const s = kunciSopir(tr.querySelector('[data-nama=nama]').value);
                        const lain = barisDetail().filter(x => x !== tr && kunciSopir(x.querySelector('[data-nama=nama]').value) === s)
                            .reduce((n, x) => n + angka(x.querySelector('[data-nama=saldo]')?.value), 0);
                        inp.value = fmt(Math.max(0, Math.min(angka(tr.querySelector('[data-nama=nominal]').value), (SALDO[s] || 0) - lain)));
                    }
                    aturSaldo(); evaluasi();
                });
            }
            // Nama sopir BARU pada baris dump truck (owner 20 Okt 2026): admin wajib memilih — ganti sopir (sopir lama truk itu dinonaktifkan),
            // sopir tambahan, nama lain dari sopir terdaftar, atau bukan sopir. Ditanyakan sekali per nama (baris teratas).
            const TRUK_DT = new Set(SOPIR.truk);
            const escSb = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            let uidSb = 0;
            // Sopir yang membawa truk m menurut catatan + jawaban "pindah / aktif lagi / berhenti" di form ini (sebelum disimpan).
            const pemegang = m => {
                const pindah = {};
                document.querySelectorAll('.sopir-pindah').forEach(b => {
                    const v = b.querySelector('[data-nama=sopir_pindah]').value;
                    if (/^(pindah|aktif)/.test(v)) pindah[b.dataset.id] = {ke: b.dataset.ke, berhenti: (v.split('|')[1] || '').split(',').filter(Boolean)};
                });
                const berhenti = new Set(Object.values(pindah).flatMap(p => p.berhenti));
                const daftar = SOPIR.aktif.filter(s => !berhenti.has(String(s.id)) && (pindah[s.id] ? pindah[s.id].ke === m : s.mobil === m));
                Object.entries(pindah).forEach(([id, p]) => {
                    if (p.ke !== m || daftar.some(s => String(s.id) === id)) return;
                    const s = Object.values(SOPIR.peta).find(x => String(x.id) === id);
                    if (s) daftar.push({id: s.id, nama: s.nama, mobil: m});
                });
                return daftar;
            };
            // Sopir TERDAFTAR yang muncul di truk lain dari catatannya / berstatus nonaktif: sekali jalan, atau pindah tetap / aktif lagi
            // (sopir lain truk itu bisa dicentang berhenti). Ditanyakan sekali per sopir.
            const buatKotakPindah = (tr, s, m) => {
                const id = 'sb' + (++uidSb);
                const lain = SOPIR.aktif.filter(x => x.mobil === m && x.id !== s.id);
                const centang = lain.map(x => `<label style="padding-left: 18px;"><input type="checkbox" value="${x.id}"> ${escSb(x.nama)} (${escSb(m)}) berhenti → nonaktifkan</label>`).join('');
                const box = document.createElement('div');
                box.className = 'sopir-baru sopir-pindah';
                Object.assign(box.dataset, {kunci: s.id + '|' + m, id: s.id, ke: m});
                box.innerHTML = s.aktif
                    ? `<b>🚚 Pindah truk?</b> ${escSb(s.nama)} tercatat membawa ${escSb(s.mobil || 'truk lain')} — sekarang ${escSb(m)}.
                        <label><input type="radio" name="${id}" value="sementara"> Sekali jalan / sementara (tetap ${escSb(s.mobil || '-')})</label>
                        <label><input type="radio" name="${id}" value="pindah"> Pindah tetap ke ${escSb(m)}</label>${centang}`
                    : `<b>↩ Kembali bekerja?</b> ${escSb(s.nama)} tercatat NONAKTIF.
                        <label><input type="radio" name="${id}" value="aktif"> Aktifkan lagi membawa ${escSb(m)}</label>${centang}
                        <label><input type="radio" name="${id}" value="sementara"> Sekali jalan saja (tetap nonaktif)</label>`;
                box.insertAdjacentHTML('beforeend', '<input type="hidden" data-nama="sopir_pindah" data-lewati-tempel>');
                const nilai = box.querySelector('[data-nama=sopir_pindah]');
                box.addEventListener('change', e => {
                    if (e.target.type === 'checkbox' && e.target.checked) box.querySelector(`input[value=${s.aktif ? 'pindah' : 'aktif'}]`).checked = true;
                    const r = box.querySelector('input[type=radio]:checked');
                    const ids = [...box.querySelectorAll('input[type=checkbox]:checked')].map(c => c.value);
                    nilai.value = !r ? '' : r.value + (r.value !== 'sementara' && ids.length ? '|' + ids.join(',') : '');
                    box.classList.toggle('terjawab', !!nilai.value);
                    setTimeout(aturSopirBaru); // kotak sopir baru di truk lain ikut menyesuaikan "tercatat dibawa"
                    evaluasi();
                });
                tr.querySelector('[data-nama=nama]').closest('td').appendChild(box);
                return box;
            };
            // Saran koreksi nama (owner 20 Okt 2026, data ditempel dari Excel): nama yang mirip sopir terdaftar / nama lainnya ("Arus" → Aris B).
            // Hanya rekomendasi — admin menekan sarannya, atau menjawab sebagai sopir baru.
            const jarak = (a, b) => {
                const d = Array.from({length: b.length + 1}, (_, j) => j);
                for (let i = 1; i <= a.length; i++) {
                    let kiri = i, atasKiri = i - 1;
                    for (let j = 1; j <= b.length; j++) {
                        const atas = d[j];
                        d[j] = Math.min(atas + 1, kiri + 1, atasKiri + (a[i - 1] === b[j - 1] ? 0 : 1));
                        atasKiri = atas; kiri = d[j];
                    }
                }
                return d[b.length];
            };
            const saranSopir = (k, m) => {
                const p = k.replace(/ /g, '');
                if (p.length < 3) return [];
                const hasil = {};
                for (const [kk, s] of Object.entries(SOPIR.peta)) {
                    if (!s.id) continue;
                    const q = kk.replace(/ /g, '');
                    let d = jarak(p, q);
                    const awalan = Math.min(p.length, q.length) >= 3 && (q.startsWith(p) || p.startsWith(q));
                    // beda 1 huruf / awalan sama; beda 2 huruf hanya bila nama panjang DAN truknya sama ("Faisal" ≠ Wisal)
                    if (d > (Math.max(p.length, q.length) >= 6 && s.mobil === m ? 2 : 1) && !awalan) continue;
                    if (awalan) d = Math.min(d, 1);
                    const skor = d * 10 - (s.mobil === m ? 5 : 0) - (s.aktif ? 2 : 0);
                    if (!hasil[s.id] || skor < hasil[s.id].skor) hasil[s.id] = {s, skor};
                }
                return Object.values(hasil).sort((a, b) => a.skor - b.skor).slice(0, 3).map(x => x.s);
            };
            // Ganti nama di SEMUA baris yang ditulis sama (kunci k) dengan nama baku sopir terpilih.
            const pakaiSaran = (k, nama) => {
                barisDetail().forEach(tr => {
                    const el = tr.querySelector('[data-nama=nama]');
                    if (kunciNama(el.value) === k) { el.title = `Dikoreksi dari "${el.value.trim()}"`; el.value = nama; el.dispatchEvent(new Event('input', {bubbles: true})); }
                });
                setTimeout(aturSopirBaru);
            };
            const buatKotakSopir = (tr, nama, k, m, lama) => {
                const id = 'sb' + (++uidSb);
                const namaLama = escSb(lama.map(s => s.nama).join(', '));
                const saran = saranSopir(k, m);
                const pilihAlias = saran[0]?.id ?? lama[0]?.id;
                const opsi = SOPIR.aktif.map(s => `<option value="alias:${s.id}" ${s.id === pilihAlias ? 'selected' : ''}>${escSb(s.nama)}${s.mobil ? ' · ' + escSb(s.mobil) : ''}</option>`).join('');
                const tombolSaran = saran.map(s => `<button type="button" class="tombol polos saran-sopir" data-ganti="${escSb(s.nama)}">${escSb(s.nama)}${s.mobil ? ' · ' + escSb(s.mobil) : ''}${s.aktif ? '' : ' (nonaktif)'}</button>`).join(' ');
                const box = document.createElement('div');
                box.className = 'sopir-baru sopir-nama-baru';
                box.dataset.kunci = k + '|' + m + '|' + lama.map(s => s.id).join(',');
                // "Terapkan semua" hanya untuk saran tunggal; lebih dari satu kemungkinan dipilih sendiri di barisnya
                if (saran.length === 1) Object.assign(box.dataset, {saran: saran[0].nama, ketik: nama});
                box.innerHTML = (saran.length ? `<div class="saran-nama">✏️ Apakah yang benar: ${tombolSaran} ?</div>` : '') + `<b>🆕 Sopir baru?</b> "${escSb(nama)}" belum terdaftar${lama.length ? ` — ${escSb(m)} tercatat dibawa ${namaLama}` : ` untuk ${escSb(m)}`}.
                    <label><input type="radio" name="${id}" value="ganti"> Ganti sopir${lama.length ? ` (${namaLama} dinonaktifkan)` : ''}</label>
                    <label><input type="radio" name="${id}" value="tambah"> Sopir tambahan${lama.length ? ' (sopir lama tetap aktif)' : ''}</label>
                    <label><input type="radio" name="${id}" value="alias"> Nama lain dari <select>${opsi}</select></label>
                    <label><input type="radio" name="${id}" value="bukan"> Bukan sopir</label>
                    <input type="hidden" data-nama="sopir_baru" data-lewati-tempel>`;
                const nilai = box.querySelector('[data-nama=sopir_baru]');
                box.querySelectorAll('.saran-sopir').forEach(b => b.addEventListener('click', () => pakaiSaran(k, b.dataset.ganti)));
                box.addEventListener('change', e => {
                    if (e.target.tagName === 'SELECT') box.querySelector('input[value=alias]').checked = true;
                    const r = box.querySelector('input[type=radio]:checked');
                    nilai.value = !r ? '' : (r.value === 'alias' ? box.querySelector('select').value : r.value);
                    box.classList.toggle('terjawab', !!nilai.value);
                    evaluasi();
                });
                tr.querySelector('[data-nama=nama]').closest('td').appendChild(box);
                return box;
            };
            const aturSopirBaru = () => {
                const ditanya = {};
                let berubah = false;
                // 0. nama lain / ejaan sopir terdaftar langsung ditulis dengan nama bakunya ("wsal" → Wisal)
                barisDetail().forEach(tr => {
                    const el = tr.querySelector('[data-nama=nama]');
                    const s = SOPIR.peta[kunciNama(el.value)];
                    if (s?.id && el.value !== s.nama && document.activeElement !== el) { el.title = `Dirapikan dari "${el.value.trim()}"`; el.value = s.nama; berubah = true; }
                });
                // 1. sopir terdaftar di truk lain / nonaktif (dulu: jawabannya memengaruhi "tercatat dibawa" kotak sopir baru)
                barisDetail().forEach(tr => {
                    const s = SOPIR.peta[kunciNama(tr.querySelector('[data-nama=nama]').value)];
                    const m = kunciMobil(tr.querySelector('[data-nama=no_mobil]').value);
                    let box = tr.querySelector('.sopir-pindah');
                    const sudah = s?.id && ditanya['id' + s.id];
                    if (s?.id) ditanya['id' + s.id] = true;
                    const perlu = s?.id && TRUK_DT.has(m) && !sudah && !(s.aktif && s.mobil === m);
                    if (!perlu) { if (box) { box.remove(); berubah = true; } return; }
                    if (!box || box.dataset.kunci !== s.id + '|' + m) { box?.remove(); buatKotakPindah(tr, s, m); berubah = true; }
                });
                // 2. nama sopir baru
                barisDetail().forEach(tr => {
                    const nama = tr.querySelector('[data-nama=nama]').value;
                    const k = kunciNama(nama);
                    const m = kunciMobil(tr.querySelector('[data-nama=no_mobil]').value);
                    const perlu = k && TRUK_DT.has(m) && !SOPIR.peta[k] && !ditanya[k];
                    let box = tr.querySelector('.sopir-nama-baru');
                    if (!perlu) { if (box) { box.remove(); berubah = true; } return; }
                    ditanya[k] = true;
                    const lama = pemegang(m);
                    if (!box || box.dataset.kunci !== k + '|' + m + '|' + lama.map(s => s.id).join(',')) { box?.remove(); buatKotakSopir(tr, nama.trim(), k, m, lama); berubah = true; }
                });
                // 3. ringkasan untuk tempelan banyak baris: semua saran koreksi bisa diterapkan sekaligus
                const bersaran = [...document.querySelectorAll('.sopir-nama-baru[data-saran]')];
                let bar = document.getElementById('saran-sopir-semua');
                // Pengajuan UJ: TANPA "Terapkan semua" (owner 20 Okt 2026) — admin wajib memeriksa saran nama satu per satu di barisnya.
                if (!AJUKAN && bersaran.length >= 2) {
                    if (!bar) {
                        bar = document.createElement('div');
                        bar.id = 'saran-sopir-semua';
                        bar.className = 'saran-semua';
                        daftar.closest('table').before(bar);
                    }
                    bar.innerHTML = `✏️ ${bersaran.length} nama sopir mirip sopir terdaftar: ${bersaran.map(b => `"${escSb(b.dataset.ketik)}" → <b>${escSb(b.dataset.saran)}</b>`).join(', ')}
                        <button type="button" class="tombol polos" style="padding: 2px 10px; font-size: 12px; margin-left: 6px;">Terapkan semua saran</button>`;
                    bar.querySelector('button').onclick = () => bersaran.forEach(b => pakaiSaran(b.dataset.kunci.split('|')[0], b.dataset.saran));
                } else bar?.remove();
                if (berubah) { urutkanNama(); evaluasi(); }
            };
            daftar.addEventListener('focusout', e => { if (['nama', 'no_mobil'].includes(e.target.dataset?.nama)) setTimeout(aturSopirBaru); });
            daftar.addEventListener('change', e => { if (['nama', 'no_mobil'].includes(e.target.dataset?.nama)) setTimeout(aturSopirBaru); });
            document.getElementById('tambah-bon').addEventListener('click', () => setTimeout(aturSopirBaru));
            document.getElementById('nama_tujuan')?.addEventListener('change', () => setTimeout(aturSopirBaru, 50));
            setTimeout(aturSopirBaru, 50);
            // Tebak Kategori dari Keterangan, seketika di browser (model dari histori Kas Seabank, tepat ±99%).
            // Kategori otomatis (ungu) boleh ditimpa tebakan berikutnya; begitu admin mengetik/memilih sendiri, tidak disentuh lagi.
            const modelKategori = @json($modelKategori);
            const tebakKategori = tr => {
                const kat = tr.querySelector('[data-nama=kategori]');
                const saran = tr.querySelector('.saran-kode');
                const ket = tr.querySelector('[data-nama=keterangan]').value;
                const hasil = ket.trim().length >= 2 ? TebakKodeGl.tebak(modelKategori, ket, '').filter(([, p]) => p >= 0.01) : [];
                const otomatis = !kat.value || kat.classList.contains('otomatis');
                saran.innerHTML = '';
                if (!otomatis) return;
                kat.value = hasil[0]?.[0] ?? '';
                kat.classList.toggle('otomatis', !!hasil.length);
                kat.title = hasil.length ? `Tebakan otomatis (${Math.round(hasil[0][1] * 100)}%) dari keterangan — periksa, atau pilih saran di bawah` : '';
                if (hasil.length < 2 && (hasil[0]?.[1] ?? 0) > 0.9) return; // sangat yakin → tanpa daftar saran
                hasil.forEach(([k, p]) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = `${k} ${Math.round(p * 100)}%`;
                    b.className = k === kat.value ? 'dipilih' : '';
                    b.addEventListener('click', () => {
                        kat.value = k;
                        kat.classList.remove('otomatis');
                        kat.title = '';
                        saran.innerHTML = '';
                        kat.dispatchEvent(new Event('input', {bubbles: true}));
                    });
                    saran.appendChild(b);
                });
            };
            daftar.addEventListener('input', e => {
                hitung();
                const el = e.target;
                const tr = el.closest('tr');
                // Hanya ketikan admin yang menjadikan isian "pilihan sendiri" (isian dari tempel/kode tidak).
                if (e.isTrusted) el.classList.remove('otomatis', 'salinan');
                if (el.dataset.nama === 'no_mobil') aturJenis(tr);
                if (el.dataset.nama === 'keterangan') tebakKategori(tr);
                if (el.dataset.nama === 'kategori' && e.isTrusted) tr.querySelector('.saran-kode').innerHTML = '';
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
                    // Kategori salinan ditandai otomatis supaya diganti tebakan bila keterangannya diubah ke jenis lain.
                    const kat = tr.querySelector('[data-nama=kategori]');
                    if (kat.value) kat.classList.add('otomatis');
                    // Keterangan & Nominal salinan boleh ditimpa isian dari Pengajuan UJ (No DO + Kategori cocok); begitu diketik, tidak lagi.
                    ['keterangan', 'nominal'].forEach(f => { const el = tr.querySelector(`[data-nama=${f}]`); if (el.value) el.classList.add('salinan'); });
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
                    const kolom = [...el.closest('tr').querySelectorAll('[data-nama]:not([data-lewati-tempel])')];
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
            // ===== Input UJ: No DO + Kategori cocok dengan detail Pengajuan UJ yang menunggu → kolom lain terisi & tertaut otomatis =====
            // Kunci sama dengan tawaran "Tautkan" di server (ValidasiUj): No DO + jenis kategori. Satu calon → langsung diisi & ditautkan;
            // lebih dari satu (mis. UJ awal & kekurangan sama-sama menunggu) → admin memilih. Sparepart & uang makan wajar banyak per DO → hanya ditawarkan.
            // Nominal = bagian yang ditransfer dari Kas UJ; bagian yang dibayar dari saldo sopir sudah tercatat di pengajuannya (satu transaksi, bukan UJ kedua).
            const PJ = @json($pjMenunggu ?? []);
            const EDIT = @json((bool) $edit);
            const kunciDoPj = v => { const t = String(v ?? '').replace(/[\s`']/g, ''); return t === '' ? '' : (t.replace(/^0+/, '') || '0'); };
            const jenisKat = v => { const k = String(v ?? '').toLowerCase();
                return k.includes('transfer') ? 'biaya' : k.includes('jalan') ? 'uj' : k.includes('tanah') ? 'ut' : k.includes('makan') ? 'um'
                    : k.includes('perangsang') ? 'pr' : (k.includes('spare') || k.includes('tambal') || k.includes('ban')) ? 'sp' : 'lain'; };
            const HANYA_TAWAR = ['sp', 'um', 'biaya'];
            const escPj = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const lepasPj = tr => {
                tr.querySelector('.pengajuan-kunci').value = '';
                tr.classList.remove('dari-pengajuan'); tr.title = ''; tr.pjOtomatis = null;
                tr.bedaEl?.remove(); tr.bedaEl = null;
            };
            const segarkanPj = () => { urutkanNama(); hitung(); tampilModePj(); evaluasi(); };
            const pakaiPj = (tr, p, segar = true) => {
                const isi = {nama: p.nama, keterangan: p.keterangan, nominal: fmt(p.nominal), no_mobil: p.no_mobil, jenis_kendaraan: p.jenis_kendaraan};
                // hanya kolom kosong / isian otomatis / salinan baris atas yang diisi — yang diketik admin dipertahankan (bedanya ditanyakan saat Simpan)
                Object.entries(isi).forEach(([k, v]) => { const el = tr.querySelector(`[data-nama=${k}]`);
                    if (!el.value.trim() || el.classList.contains('otomatis') || el.classList.contains('salinan')) { el.value = v ?? ''; el.classList.remove('otomatis', 'salinan'); } });
                tr.querySelector('.pengajuan-kunci').value = p.id;
                tr.classList.add('dari-pengajuan');
                tr.title = 'Dari pengajuan ' + p.kode;
                tr.pjOtomatis = {doK: kunciDoPj(p.no_do), jk: p.jenis_kat, id: String(p.id)};
                tr.pjEl?.remove();
                const t = document.createElement('tr');
                t.className = 'pj-baris';
                t.innerHTML = `<td colspan="8"><div class="pj-info">🔗 Terisi &amp; tertaut ke <b>${escPj(p.kode)}</b>${p.tanggal ? ' (' + escPj(p.tanggal) + ')' : ''} — ${escPj(p.kategori)} DO ${escPj(p.no_do)}`
                    + (p.saldo > 0 ? `: total <b>${fmt(p.penuh)}</b>, <b>${fmt(p.saldo)}</b> sudah dibayar dari saldo sopir, baris ini <b>${fmt(p.nominal)}</b> dari Kas UJ. Satu transaksi — bukan UJ kedua.` : '.')
                    + ` <a href="#" class="lepas-pj">Lepas</a></div></td>`;
                t.querySelector('.lepas-pj').addEventListener('click', e => { e.preventDefault(); tr.pjTolak = tr.pjOtomatis?.doK + '|' + tr.pjOtomatis?.jk; lepasPj(tr); t.remove(); tr.pjEl = null; segarkanPj(); });
                tr.after(t);
                tr.pjEl = t;
                if (segar) segarkanPj();
            };
            const cocokPj = (tr, segar = true) => {
                if (!PJ.length || AJUKAN || EDIT) return;
                const doK = kunciDoPj(tr.querySelector('[data-nama=no_do]').value);
                const kat = tr.querySelector('[data-nama=kategori]').value.trim();
                const jk = kat ? jenisKat(kat) : '';
                const kunci = tr.querySelector('.pengajuan-kunci');
                // tautan otomatis yang No DO / kategorinya diganti dilepas
                if (tr.pjOtomatis && (tr.pjOtomatis.doK !== doK || tr.pjOtomatis.jk !== jk)) lepasPj(tr);
                if (kunci.value) return; // sudah tertaut (otomatis, tombol Tautkan, atau dari menu Pengajuan UJ)
                tr.pjEl?.remove(); tr.pjEl = null;
                if (!doK || !kat || tr.pjTolak === doK + '|' + jk) { if (segar) segarkanPj(); return; }
                const dipakai = new Set(barisDetail().filter(x => x !== tr).map(x => x.querySelector('.pengajuan-kunci').value).filter(Boolean));
                const calon = PJ.filter(p => kunciDoPj(p.no_do) === doK && p.jenis_kat === jk && !dipakai.has(String(p.id)));
                if (!calon.length) { if (segar) segarkanPj(); return; }
                if (calon.length === 1 && !HANYA_TAWAR.includes(jk)) { pakaiPj(tr, calon[0], segar); return; }
                const t = document.createElement('tr');
                t.className = 'pj-baris';
                t.innerHTML = `<td colspan="8"><div class="pj-info">🔗 DO ${escPj(tr.querySelector('[data-nama=no_do]').value)} ${escPj(kat)}: ${calon.length} detail pengajuan menunggu — pilih yang direalisasikan baris ini
                    (abaikan bila transaksi terpisah):<div class="pj-calon">${calon.map(p => `<button type="button" class="tombol polos" data-pj="${p.id}">${escPj(p.kode)} · ${escPj(p.nama)} · ${escPj(p.no_mobil || '')} · ${fmt(p.nominal)}${p.saldo > 0 ? ' (+' + fmt(p.saldo) + ' saldo)' : ''} · ${escPj(p.keterangan)}</button>`).join('')}</div></div></td>`;
                t.querySelectorAll('[data-pj]').forEach(btn => btn.addEventListener('click', () => pakaiPj(tr, calon.find(p => String(p.id) === btn.dataset.pj))));
                tr.after(t);
                tr.pjEl = t;
            };
            daftar.addEventListener('change', e => {
                if (['no_do', 'kategori'].includes(e.target.dataset?.nama)) cocokPj(e.target.closest('tr'));
            });
            (awal.length ? awal : [{}]).forEach(b => tambah(b));
            barisDetail().forEach(tr => { if (!tr.querySelector('[data-nama=jenis_kendaraan]').value) aturJenis(tr); });
            // Tempel blok sel dari Excel (Ctrl+V): isi mulai sel aktif, baris kurang ditambah otomatis.
            TempelTabel.pasang(daftar, {
                baris: barisDetail,
                tambah: () => tambah({}),
                // Baris utuh dari Excel "PENGAJUAN UANG JALAN" (NO | TGL | Nominal | Nama | …) ditempel di kolom Nominal:
                // kolom NO & TGL di depan dibuang supaya Nominal, Nama, Keterangan, dst. masuk ke kolomnya.
                rapikan: (data, kolomAwal) => {
                    const tanggal = /^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}[\/\-. ][A-Za-z0-9]{1,9}[\/\-. ]\d{2,4})/;
                    const utuh = kolomAwal === 0 && data.every(r => r.length >= 4 && /^\d{1,4}$/.test(r[0]) && tanggal.test(r[1]) && /\d/.test(r[2]));
                    return utuh ? data.map(r => r.slice(2)) : data;
                },
                setelah: rows => {
                    rows.forEach(tr => {
                        const mobil = tr.querySelector('[data-nama=no_mobil]');
                        if (mobil.value) { mobil.value = kunciMobil(mobil.value); if (!tr.querySelector('[data-nama=jenis_kendaraan]').value) aturJenis(tr); }
                    });
                    rows.forEach(cocokPj); // No DO + Kategori hasil tempel → isi dari pengajuan yang menunggu
                    setTimeout(aturSopirBaru); // nama sopir hasil tempel langsung dirapikan / ditanyakan
                },
            });
            if (!awal.length) aturNamaPertama();
            // Dibuka dari Pengajuan UJ: kursor langsung ke data penerima transfer.
            if (document.getElementById('petunjuk-pengajuan')) {
                const tuju = nama.value.trim() ? document.getElementById('no_rek') : nama;
                tuju?.focus();
            }
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
            // Nama yang diketik berbeda dengan pemilik rekening di Daftar Rekening → diingatkan (tidak memblokir).
            const catatanRek = document.getElementById('catatan-rek');
            const cekPemilik = () => {
                const r = rekening.find(x => x.kunci && x.kunci === kunciRek(noRek.value));
                const beda = !!(r && !r.belum && nama.value.trim() && kecil(nama.value) !== kecil(r.nama));
                catatanRek.hidden = !beda;
                if (!beda) return;
                catatanRek.innerHTML = `ⓘ Rekening ini terdaftar atas nama <b>${esc(r.nama)}</b> di Daftar Rekening.<button type="button">Pakai nama ini</button>`;
                catatanRek.querySelector('button').addEventListener('click', () => { nama.value = r.nama; otomatis.delete(nama); cekPemilik(); });
            };
            const cocokNama = q => {
                q = kecil(q);
                if (!q) return [];
                const skorTeks = n => n === q ? 0 : n.startsWith(q) ? 1 : n.split(/\s+/).some(k => k.startsWith(q)) ? 2 : n.includes(q) ? 3 : -1;
                return rekening.map(r => {
                    let skor = skorTeks(kecil(r.nama)), via = null;
                    // Daftar Rekening: tulisan lain & nama sopir yang memakai rekening ini ikut dicari (ketik "Bambang" → rekening yang biasa dipakai Bambang).
                    if (skor < 0) (r.alias || []).forEach(a => { const s = skorTeks(kecil(a)); if (s >= 0 && (via === null || s + 4 < skor)) { skor = s + 4; via = a; } });
                    return {r: via ? {...r, via} : r, skor};
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
                        <span><b>${r.nama ? tandai(r.nama, cariNama) : '<i>pemilik belum diisi</i>'}</b>${r.via ? ` <span class="rek">· dipakai untuk ${tandai(r.via, cariNama)}</span>` : ''}<br><span class="rek">${esc(r.bank || 'Bank ?')} · <b>${tandai(r.no_rek, cariNorek)}</b></span></span>
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
                tutup(saranNama); tutup(saranNorek); cekPemilik();
                aturBiaya(); isiSopirPertama(r);
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
                setTimeout(cekPemilik);
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
                setTimeout(cekPemilik);
                const persis = rekening.filter(r => r.kunci === kunciRek(noRek.value));
                if (persis.length === 1) {
                    if (!nama.value || otomatis.has(nama)) { isi(nama, persis[0].nama); isiSopirPertama(persis[0]); }
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

            // Dikirim dari rekening Bank Jago: sesama Jago tanpa biaya, bank/e-wallet lain 2.500 (biaya transfer otomatis dicentang).
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
                const b = bank.value.trim().toLowerCase();
                const sesamaJago = b === 'jago' || b === 'bank jago' || b === '';
                // Mutasi Jago (owner 28 Okt 2026): transfer ke GoPay tidak kena biaya (DANA tetap kena).
                const gopay = b === 'gopay' || b === 'go pay';
                if (!biayaDiubahManual) biaya.checked = !sesamaJago && !gopay;
                tandaiBiaya();
                catatanBiaya.textContent = b === '' ? 'Isi bank tujuan: selain Bank Jago & GoPay, biaya transfer otomatis dicentang.'
                    : sesamaJago ? 'Sesama Bank Jago tanpa biaya transfer.' : gopay ? 'Ke GoPay tanpa biaya transfer (sesuai mutasi Jago).'
                    : 'Transfer dari Bank Jago ke ' + bank.value + ' kena biaya transfer.';
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
                // Pengajuan: tanpa data master (hanya tanggal); nama driver tiap baris wajib.
                (AJUKAN ? [[document.getElementById('tanggal'), 'Tanggal']] : [[document.getElementById('tanggal'), 'Tanggal'], [nama, 'Nama penerima'], [nominalTransfer, 'Nominal master']]).forEach(([el, label]) => {
                    if (!el.value.trim() || (el === nominalTransfer && !angka(el.value))) { tandai(el); master.push(label + ' belum diisi'); }
                });
                if (bank.value.trim() && !kodeBank.has(bank.value.trim())) { tandai(bank); master.push(`Bank "${bank.value.trim()}" tidak ada di daftar`); }
                if (master.length) masalah.push('Transaksi master: ' + master.join(', ') + '.');
                // Kekurangan tiap transaksi detail ditulis tepat di bawah barisnya; kotak di atas tombol Simpan cukup ringkasannya.
                const barisKurang = [];
                barisDetail().forEach((tr, i) => {
                    const kurang = [['nominal', 'Nominal'], ...(AJUKAN ? [['nama', 'Nama driver']] : []), ['keterangan', 'Keterangan'], ['kategori', 'Kategori']].filter(([f]) => {
                        const el = tr.querySelector(`[data-nama=${f}]`);
                        const kosong = f === 'nominal' ? !angka(el.value) : !el.value.trim();
                        if (kosong) tandai(el);
                        return kosong;
                    }).map(([, label]) => label);
                    const inputs = [...tr.querySelectorAll('[data-nama]')];
                    aturKurang(tr, i, kurang, tandaiSemua || inputs.some(el => el.dataset.tersentuh || el.value.trim()));
                    if (kurang.length) barisKurang.push(i + 1);
                });
                if (barisKurang.length) masalah.push(`${barisKurang.length} transaksi detail belum lengkap (baris ${barisKurang.join(', ')}) — lihat pesan tepat di bawah barisnya.`);
                if (!hitung()) { masalah.push(statusCocok.textContent); pertama ??= nominalTransfer; }
                return {masalah, pertama};
            };
            // Pesan "belum diisi" satu baris detail, diletakkan persis di bawah baris itu (di atas pesan FLAG-nya bila ada).
            const aturKurang = (tr, i, kurang, tampil) => {
                tr.kurang = kurang;
                if (!kurang.length || !tampil) { tr.kurangEl?.remove(); tr.kurangEl = null; return; }
                if (!tr.kurangEl) {
                    tr.kurangEl = document.createElement('tr');
                    tr.kurangEl.className = 'kurang-baris';
                    tr.kurangEl.innerHTML = '<td colspan="8"><div class="kurang"></div></td>';
                    tr.after(tr.kurangEl);
                }
                tr.kurangEl.querySelector('.kurang').textContent = `✎ Baris ${i + 1}: ${kurang.join(', ')} belum diisi.`;
            };
            const tampilTemuan = temuan => {
                let jumlah = 0;
                barisDetail().forEach((tr, i) => {
                    if (tr.temuan?.querySelector('[data-konfirmasi]')) tr.konfirmasi = tr.temuan.querySelector('[data-konfirmasi]').value;
                    tr.temuan?.remove();
                    tr.temuan = null;
                    tr.aturan = [];
                    tr.duluanTanya = false;
                    tr.classList.remove('ber-flag');
                    // Sudah dijawab "Ya": tampilkan tautannya (bisa dibatalkan).
                    tr.duluanEl?.remove(); tr.duluanEl = null;
                    const kunciDl = tr.querySelector('.duluan-kunci').value;
                    if (kunciDl) {
                        tr.duluanEl = document.createElement('tr');
                        tr.duluanEl.className = 'duluan-info';
                        tr.duluanEl.innerHTML = `<td colspan="8">✓ Tertaut ke transaksi <b>${esc2(kunciDl)}</b> yang terjadi duluan — setelah disimpan, detail ini langsung tercatat terealisasi. <a href="#" class="batal-duluan">Batalkan</a></td>`;
                        tr.duluanEl.querySelector('.batal-duluan').addEventListener('click', e => { e.preventDefault(); tr.querySelector('.duluan-kunci').value = ''; tr.ditolakDuluan = false; urutkanNama(); evaluasi(); });
                        (tr.kurangEl ?? tr).after(tr.duluanEl);
                    }
                    const semuaT = temuan[i] || [];
                    const tanya = semuaT.filter(x => x.kode === 'D');
                    const daftarT = semuaT.filter(x => x.kode !== 'D');
                    if (tanya.length) {
                        tr.duluanTanya = true;
                        jumlah++;
                        const t = document.createElement('tr');
                        t.className = 'temuan-baris';
                        t.innerHTML = `<td colspan="8"><div class="temuan">${tanya.map(x => `<div class="duluan-tanya${tr.ditolakDuluan ? ' ditolak' : ''}">
                                <b>❓ Baris ${i + 1}: transaksi untuk DO ${esc2(x.duluan.no_do)} sudah diinput di Kas UJ. Apakah transaksi ini sudah terjadi duluan sebelum pengajuan?</b>
                                <div class="rinci"><b>${esc2(x.duluan.id_uj)}</b> · ${esc2(x.duluan.tanggal || '')} · ${esc2(x.duluan.nama || 'tanpa nama')} · ${esc2(x.duluan.kategori)} · <b>Rp ${fmt(x.duluan.nominal)}</b>
                                    · ${esc2(x.duluan.no_mobil || '')} · DO ${esc2(x.duluan.no_do)}<br>"${esc2(x.duluan.keterangan)}"</div>
                                ${tr.ditolakDuluan ? `<div style="color: #ff8a80;">⛔ Ditolak: DO ini sudah ada transaksinya di Kas UJ — hapus baris ini atau perbaiki No DO-nya.</div>` : ''}
                                <button type="button" class="tombol duluan-ya" data-id="${esc2(x.duluan.id_uj)}" style="padding: 3px 12px; font-size: 12px;">Ya, sudah terjadi duluan — tautkan</button>
                                <button type="button" class="tombol polos duluan-tidak" style="padding: 3px 12px; font-size: 12px;">Tidak</button></div>`).join('')}</div></td>`;
                        t.querySelectorAll('.duluan-ya').forEach(btn => btn.addEventListener('click', () => {
                            tr.querySelector('.duluan-kunci').value = btn.dataset.id;
                            tr.ditolakDuluan = false;
                            urutkanNama(); evaluasi();
                        }));
                        t.querySelectorAll('.duluan-tidak').forEach(btn => btn.addEventListener('click', () => { tr.ditolakDuluan = true; tampilTemuan(temuanTerakhir); evaluasi(false); }));
                        (tr.duluanEl ?? tr.kurangEl ?? tr).after(t);
                        tr.temuan = t;
                        tr.classList.add('ber-flag');
                    }
                    if (!daftarT.length) return;
                    if (!tanya.length) jumlah++;
                    tr.aturan = [...new Set(daftarT.map(x => x.kode))];
                    if (tanya.length) return;
                    // FLAG "info" saja (mis. UJ terbuka lewat batas wajar): peringatan, tanpa kotak konfirmasi.
                    if (daftarT.every(x => x.prioritas === 'info')) {
                        const t = document.createElement('tr');
                        t.className = 'temuan-baris';
                        t.innerHTML = `<td colspan="8"><div class="temuan">${daftarT.map(x => `<div><span class="prioritas info">info</span> <span class="aturan">Aturan ${esc2(x.kode)}</span> ${esc2(x.pesan)}</div>`).join('')}</div></td>`;
                        (tr.duluanEl ?? tr.kurangEl ?? tr).after(t);
                        tr.temuan = t;
                        return;
                    }
                    const t = document.createElement('tr');
                    t.className = 'temuan-baris';
                    t.innerHTML = `<td colspan="8"><div class="temuan">
                        <div class="judul-temuan">⚠ Baris ${i + 1} kena FLAG validasi (${daftarT.length}) — periksa, perbaiki bila salah, atau tulis konfirmasi bila memang valid</div>
                        <ul>${daftarT.map(x => `<li><span class="prioritas ${x.prioritas}">${x.prioritas}</span> <span class="aturan">${x.tautan ? 'Pengajuan' : 'Aturan ' + esc2(x.kode)}</span> ${esc2(x.pesan)}
                            ${x.tautan ? `<button type="button" class="tombol tautkan" data-id="${x.tautan.id}" data-kode="${esc2(x.tautan.kode)}" style="padding: 3px 10px; font-size: 12px; margin-left: 6px;">🔗 Tautkan ke ${esc2(x.tautan.kode)}</button>` : ''}
                            ${(x.saran || []).map(s => `<button type="button" class="tombol pakai-do" data-do="${esc2(s.no_do)}" style="padding: 3px 10px; font-size: 12px; margin-left: 6px;">Pakai DO ${esc2(s.no_do)}</button>`).join('')}</li>`).join('')}</ul>
                        <label>Konfirmasi admin <span class="redup">(wajib, min. ${MIN_KONFIRMASI} karakter — mis. "Kekurangan UJ karena rute dialihkan", "Ganti driver, Sule sakit")</span>
                            <input type="text" data-konfirmasi maxlength="1000" autocomplete="off"></label>
                        <div class="status-konf"></div></div></td>`;
                    const k = t.querySelector('[data-konfirmasi]');
                    k.value = tr.konfirmasi || '';
                    k.addEventListener('input', () => { tr.konfirmasi = k.value; k.classList.toggle('kosong', k.value.trim().length < MIN_KONFIRMASI); evaluasi(); });
                    k.classList.toggle('kosong', k.value.trim().length < MIN_KONFIRMASI);
                    // Baris yang diketik manual tetapi cocok dengan pengajuan yang menunggu: tautkan → tercatat sebagai realisasinya.
                    t.querySelectorAll('button.tautkan').forEach(btn => btn.addEventListener('click', () => {
                        const id = btn.dataset.id;
                        const lain = barisDetail().find(x => x !== tr && x.querySelector('.pengajuan-kunci').value === id);
                        if (lain) { alert(`${btn.dataset.kode} itu sudah ditautkan ke baris ${barisDetail().indexOf(lain) + 1}.`); return; }
                        tr.querySelector('.pengajuan-kunci').value = id;
                        tr.classList.add('dari-pengajuan');
                        tr.title = 'Dari pengajuan ' + btn.dataset.kode;
                        urutkanNama(); tampilModePj?.(); evaluasi();
                    }));
                    // Aturan 19: DO tanpa Uang Jalan → ganti No DO baris ini dengan DO uang jalan yang disarankan.
                    t.querySelectorAll('button.pakai-do').forEach(btn => btn.addEventListener('click', () => isiDo(tr, btn.dataset.do)));
                    (tr.duluanEl ?? tr.kurangEl ?? tr).after(t);
                    tr.temuan = t;
                    tr.classList.add('ber-flag');
                });
                urutkanNama();
                return jumlah;
            };
            // Baris dari pengajuan yang isinya berbeda dengan pengajuannya: tampilkan perbandingan + alasan wajib (hasil server).
            const tampilBeda = beda => {
                barisDetail().forEach((tr, i) => {
                    if (tr.bedaEl) tr.alasan = tr.bedaEl.querySelector('[data-alasan]').value;
                    tr.bedaEl?.remove();
                    tr.bedaEl = null;
                    const b = beda[i];
                    if (!b) return;
                    const dialih = b.jenis === 'dialihkan';
                    const t = document.createElement('tr');
                    t.className = 'beda-baris';
                    t.innerHTML = `<td colspan="8"><div class="beda-pj ${dialih ? 'dialihkan' : ''}">
                        <div class="judul-beda">${dialih
                            ? `↪ Baris ${i + 1} DIALIHKAN dari pengajuan ${esc2(b.kode)} — dana dipakai untuk keperluan lain. Pengajuan aslinya selesai (tidak bisa direalisasikan lagi) dan DO-nya tetap terhitung sudah dibiayai.`
                            : `≠ Baris ${i + 1} berbeda dari pengajuan ${esc2(b.kode)} (penyesuaian)`}</div>
                        <ul>${b.beda.map(x => `<li>${esc2(x.kolom)}: <del>${esc2(x.lama)}</del> → <b>${esc2(x.baru)}</b></li>`).join('')}</ul>
                        <label>Alasan ${dialih ? 'pengalihan' : 'perbedaan'} <span class="redup">(wajib, min. ${MIN_KONFIRMASI} karakter — tampil ke owner di menu Pengajuan UJ)</span>
                            <input type="text" data-alasan maxlength="1000" autocomplete="off"></label>
                        <div class="status-alasan"></div></div></td>`;
                    const a = t.querySelector('[data-alasan]');
                    a.value = tr.alasan || '';
                    a.addEventListener('input', () => { tr.alasan = a.value; evaluasi(false); });
                    (tr.temuan ?? tr.kurangEl ?? tr).after(t);
                    tr.bedaEl = t;
                });
                urutkanNama();
            };
            // Aksi massal: tautkan semua baris ke pengajuan (PUJ-xxxx) yang ditawarkan, dan isi alasan perbedaan sekaligus.
            const kotakMassal = document.createElement('div');
            kotakMassal.className = 'aksi-massal-pj';
            kotakMassal.hidden = true;
            daftar.closest('table').before(kotakMassal);
            const aturMassal = () => {
                const dipakai = new Set(barisDetail().map(tr => tr.querySelector('.pengajuan-kunci').value).filter(Boolean));
                const tawaran = {}; // kode → [[tr, id], …]
                barisDetail().forEach((tr, i) => {
                    if (tr.querySelector('.pengajuan-kunci').value) return;
                    (temuanTerakhir[i] || []).filter(x => x.tautan).forEach(x => (tawaran[x.tautan.kode] ??= []).push([tr, String(x.tautan.id)]));
                });
                const tanpaAlasan = barisDetail().filter(tr => { const a = tr.bedaEl?.querySelector('[data-alasan]'); return a && a.value.trim().length < MIN_KONFIRMASI; });
                const kode = Object.keys(tawaran);
                kotakMassal.hidden = !kode.length && tanpaAlasan.length < 2;
                kotakMassal.innerHTML = kode.map(k => {
                    const n = new Set(tawaran[k].map(([tr]) => tr)).size;
                    return `<div class="baris-massal-pj">🔗 <b>${n} baris</b> cocok dengan pengajuan <b>${esc2(k)}</b> yang belum direalisasikan.
                        <button type="button" class="tombol" data-tautkan-semua="${esc2(k)}" style="padding: 4px 12px; font-size: 13px;">Tautkan semua ke ${esc2(k)}</button></div>`;
                }).join('') + (tanpaAlasan.length >= 2 ? `<div class="baris-massal-pj">≠ <b>${tanpaAlasan.length} baris</b> berbeda dari pengajuannya dan belum beralasan.
                        <input type="text" id="alasan-massal" maxlength="1000" placeholder="Alasan untuk semua baris itu (min. ${MIN_KONFIRMASI} karakter)" style="flex: 1; min-width: 260px;">
                        <button type="button" class="tombol polos" id="isi-alasan-massal" style="padding: 4px 12px; font-size: 13px;">Isi ke ${tanpaAlasan.length} baris</button></div>` : '');
                kotakMassal.querySelectorAll('[data-tautkan-semua]').forEach(btn => btn.addEventListener('click', () => {
                    let n = 0;
                    (tawaran[btn.dataset.tautkanSemua] || []).forEach(([tr, id]) => {
                        const kunci = tr.querySelector('.pengajuan-kunci');
                        if (kunci.value || dipakai.has(id)) return; // satu detail pengajuan hanya untuk satu baris
                        kunci.value = id;
                        dipakai.add(id);
                        tr.classList.add('dari-pengajuan');
                        tr.title = 'Dari pengajuan ' + btn.dataset.tautkanSemua;
                        n++;
                    });
                    urutkanNama(); tampilModePj?.(); evaluasi();
                    kotakMassal.innerHTML = `<div class="baris-massal-pj">✓ ${n} baris ditautkan ke ${esc2(btn.dataset.tautkanSemua)} — memeriksa ulang…</div>`;
                }));
                kotakMassal.querySelector('#isi-alasan-massal')?.addEventListener('click', () => {
                    const v = kotakMassal.querySelector('#alasan-massal').value.trim();
                    if (v.length < MIN_KONFIRMASI) { alert(`Alasan minimal ${MIN_KONFIRMASI} karakter.`); return; }
                    tanpaAlasan.forEach(tr => { const a = tr.bedaEl.querySelector('[data-alasan]'); a.value = v; tr.alasan = v; });
                    aturMassal(); evaluasi(false);
                });
            };
            // Lapis 3: setiap baris ber-FLAG wajib punya konfirmasi yang memadai.
            const cekKonfirmasi = () => {
                const masalah = [];
                let pertama = null;
                const baris = [];
                const tanyaBelum = barisDetail().map((tr, i) => tr.duluanTanya ? i + 1 : null).filter(Boolean);
                if (tanyaBelum.length) masalah.push(`Baris ${tanyaBelum.join(', ')}: transaksinya sudah ada di Kas UJ — jawab "Ya, sudah terjadi duluan" untuk menautkan. Bila jawabannya Tidak, data itu tidak bisa diajukan (hapus barisnya atau perbaiki No DO).`);
                barisDetail().forEach((tr, i) => {
                    const k = tr.temuan?.querySelector('[data-konfirmasi]');
                    if (!k) return;
                    const isi = k.value.trim();
                    const status = tr.temuan.querySelector('.status-konf');
                    if (isi.length >= MIN_KONFIRMASI) { status.className = 'status-konf ok'; status.textContent = '✓ Sudah dikonfirmasi'; return; }
                    status.className = 'status-konf';
                    status.textContent = isi ? `✗ Konfirmasi terlalu singkat (${isi.length}/${MIN_KONFIRMASI} karakter).` : '✗ Belum dikonfirmasi — perbaiki isian baris ini atau tulis konfirmasi.';
                    pertama ??= k;
                    baris.push(i + 1);
                });
                if (baris.length) masalah.push(`${baris.length} transaksi detail kena FLAG validasi dan belum dikonfirmasi (baris ${baris.join(', ')}) — alasan & kotak konfirmasinya ada tepat di bawah barisnya.`);
                // Realisasi yang berbeda dari pengajuannya wajib beralasan.
                const tanpaAlasan = [];
                barisDetail().forEach((tr, i) => {
                    const a = tr.bedaEl?.querySelector('[data-alasan]');
                    if (!a) return;
                    const isi = a.value.trim();
                    const status = tr.bedaEl.querySelector('.status-alasan');
                    a.classList.toggle('kosong', isi.length < MIN_KONFIRMASI);
                    if (isi.length >= MIN_KONFIRMASI) { status.className = 'status-alasan ok'; status.textContent = '✓ Alasan terisi'; return; }
                    status.className = 'status-alasan';
                    status.textContent = isi ? `✗ Alasan terlalu singkat (${isi.length}/${MIN_KONFIRMASI} karakter).` : '✗ Alasan belum diisi.';
                    pertama ??= a;
                    tanpaAlasan.push(i + 1);
                });
                if (tanpaAlasan.length) masalah.push(`${tanpaAlasan.length} transaksi berbeda dari pengajuannya dan belum diberi alasan (baris ${tanpaAlasan.join(', ')}) — isi alasannya di kotak tepat di bawah barisnya.`);
                const sopirBelum = barisDetail().map((tr, i) => [...tr.querySelectorAll('.sopir-baru')].some(b => !b.querySelector('[data-nama=sopir_baru], [data-nama=sopir_pindah]').value) ? i + 1 : null).filter(Boolean);
                const saldoBelum = barisDetail().map((tr, i) => { const b = tr.querySelector('.saldo-sopir'); return b && !b.hidden && b.dataset.wajib === '1' ? i + 1 : null; }).filter(Boolean);
                if (saldoBelum.length) masalah.push(`Baris ${saldoBelum.join(', ')}: sopir punya saldo — isi potongan saldo untuk transaksinya (0 bila tidak dipotong).`);
                if (sopirBelum.length) masalah.push(`Baris ${sopirBelum.join(', ')}: data sopir perlu dijawab (sopir baru / pindah truk / kembali bekerja) — pilih di kotak bawah namanya.`);
                return {masalah, pertama};
            };
            // Status validasi dihitung terus saat admin mengisi. Tombol Simpan hanya menyala bila SEMUA transaksi valid:
            // isian lengkap + jumlah cocok (lapis 1), aturan validasi sudah diperiksa server untuk isi form saat ini (lapis 2),
            // dan setiap baris ber-FLAG sudah dikonfirmasi (lapis 3). Selama mati, daftar transaksi yang harus diperbaiki ditampilkan.
            const teksSimpan = @json($teksTombol);
            const kunciIsi = () => {
                const d = new FormData(form);
                return JSON.stringify([...d.entries()].filter(([k, v]) => typeof v === 'string' && !/konfirmasi|alasan_pengajuan|_token|_method|versi|sopir_baru|sopir_pindah|^sb\d/.test(k)));
            };
            let diperiksa = null;   // kunciIsi() yang terakhir lolos pemeriksaan server
            let temuanTerakhir = {}; // hasil pemeriksaan server terakhir (dipakai ulang saat jawaban "Tidak")
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
                    const res = await fetch(@json(route($ajukan ? 'pengajuan-uj.periksa' : 'uj.periksa')), {method: 'POST', body: data, headers: {Accept: 'application/json'}});
                    const hasil = await res.json();
                    if (memeriksa !== kunci) return; // isian sudah berubah lagi; hasil ini usang
                    if (!res.ok) {
                        galatServer = hasil.galat ?? Object.values(hasil.errors ?? {}).flat();
                        diperiksa = null;
                    } else {
                        galatServer = [];
                        temuanTerakhir = hasil.temuan || {}; tampilTemuan(temuanTerakhir);
                        tampilBeda(hasil.pengajuan || {});
                        aturMassal();
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
            // Baris detail yang sudah valid (lengkap + lolos pemeriksaan server untuk isi form saat ini + FLAG-nya, bila ada,
            // sudah dikonfirmasi) berlatar hijau.
            const warnaiBaris = sudahDiperiksa => barisDetail().forEach(tr => {
                const k = tr.temuan?.querySelector('[data-konfirmasi]');
                const a = tr.bedaEl?.querySelector('[data-alasan]');
                const konfOk = (!k || k.value.trim().length >= MIN_KONFIRMASI) && (!a || a.value.trim().length >= MIN_KONFIRMASI);
                tr.classList.toggle('valid', sudahDiperiksa && !tr.kurang?.length && konfOk);
            });
            // No DO transaksi lanjutan (bukan Uang Jalan / Uang MOB): pilih dari DO uang jalan DT itu 14 hari terakhir, tidak perlu diketik ulang.
            const DO_DT = @json($doDt ?? []);
            const isiDo = (tr, nomor) => {
                const inp = tr.querySelector('[data-nama=no_do]');
                inp.value = nomor;
                inp.dispatchEvent(new Event('input', {bubbles: true}));
                evaluasi();
            };
            const aturSaranDo = tr => {
                const kotak = tr.querySelector('.saran-do');
                if (!kotak) return;
                const kat = tr.querySelector('[data-nama=kategori]').value.toLowerCase();
                const daftarDo = /jalan|\bmob\b|mobilisasi/.test(kat) || !kat ? [] : (DO_DT[kunciMobil(tr.querySelector('[data-nama=no_mobil]').value)] || []);
                const kini = kunciDo(tr.querySelector('[data-nama=no_do]').value);
                const kunciKotak = daftarDo.map(d => d.do).join(',') + '|' + kini;
                if (kotak.dataset.kunci === kunciKotak) return;
                kotak.dataset.kunci = kunciKotak;
                kotak.innerHTML = daftarDo.map(d => `<button type="button" data-do="${esc2(d.do)}" class="${kunciDo(d.do) === kini ? 'dipilih' : ''}" title="Uang Jalan ${esc2(d.id || '')} · ${esc2(d.tgl || '')}">${esc2(d.do)} · ${esc2(d.tgl || '')}</button>`).join('');
                kotak.querySelectorAll('button').forEach(b => b.addEventListener('click', () => isiDo(tr, b.dataset.do)));
            };
            const evaluasi = (bolehPeriksa = true) => {
                barisDetail().forEach(aturSaranDo);
                const isian = cekIsian();
                const kunci = kunciIsi();
                const sudahDiperiksa = diperiksa === kunci;
                // Pemeriksaan aturan berjalan begitu ada baris lengkap (baris lain boleh belum), supaya baris valid bisa langsung hijau.
                const adaBarisLengkap = barisDetail().some(tr => !tr.kurang?.length);
                if (!sudahDiperiksa && adaBarisLengkap && bolehPeriksa && memeriksa !== kunci) {
                    clearTimeout(jedaPeriksa);
                    jedaPeriksa = setTimeout(() => periksaServer(kunciIsi()), 600);
                }
                const konf = cekKonfirmasi();
                warnaiBaris(sudahDiperiksa);
                if (isian.masalah.length) {
                    aturTombol(false, 'Lengkapi isian dulu');
                    tampilMasalah('Simpan belum aktif — lengkapi dulu:', [...isian.masalah, ...(sudahDiperiksa ? konf.masalah : [])]);
                    return;
                }
                if (!sudahDiperiksa) {
                    aturTombol(false, 'Menunggu pemeriksaan validasi');
                    if (galatServer.length && !bolehPeriksa) { tampilMasalah('Simpan belum aktif — perbaiki dulu:', galatServer); return; }
                    kotakValidasi.className = 'pesan';
                    kotakValidasi.hidden = false;
                    kotakValidasi.textContent = 'Memeriksa validasi transaksi…';
                    return;
                }
                if (konf.masalah.length) {
                    aturTombol(false, 'Ada transaksi ber-FLAG yang belum dikonfirmasi / realisasi berbeda yang belum diberi alasan');
                    tampilMasalah('Simpan belum aktif:', konf.masalah);
                    return;
                }
                aturTombol(true);
                const jumlahFlag = barisDetail().filter(tr => tr.temuan).length;
                kotakValidasi.className = 'pesan sukses';
                kotakValidasi.hidden = false;
                kotakValidasi.textContent = '✓ Semua transaksi tervalidasi' + (jumlahFlag ? ` (${jumlahFlag} baris ber-FLAG sudah dikonfirmasi)` : '') + ' — siap disimpan.';
            };
            form.addEventListener('input', e => { if (!e.target.matches('[data-konfirmasi], [data-alasan]')) evaluasi(); });
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
                tombolSimpan.textContent = @json($ajukan ? 'Mengajukan…' : 'Menyimpan ke sheet…');
            });

            // ===== Baris yang ditautkan ke Pengajuan UJ (tombol "Tautkan" di pesan validasinya) =====
            const modePj = document.getElementById('mode-pengajuan');
            const tampilModePj = () => {
                const rows = barisDetail().filter(tr => tr.querySelector('.pengajuan-kunci').value);
                modePj.hidden = !rows.length;
                const kode = [...new Set(rows.map(tr => (tr.title || '').replace('Dari pengajuan ', '')))].filter(Boolean);
                document.getElementById('mode-pengajuan-isi').textContent = `${rows.length} detail dari ${kode.join(', ') || 'pengajuan'}`;
            };
            document.getElementById('lepas-pengajuan')?.addEventListener('click', () => {
                if (!confirm('Lepas tautan ke pengajuan? Detail tetap di form, tetapi pengajuannya tidak akan tercatat terealisasi.')) return;
                barisDetail().forEach(tr => { tr.querySelector('.pengajuan-kunci').value = ''; tr.classList.remove('dari-pengajuan'); tr.title = ''; tr.bedaEl?.remove(); tr.bedaEl = null; });
                urutkanNama(); tampilModePj(); evaluasi();
            });
            // Baris awal (mis. dari rekap bayar tanah) yang No DO + Kategorinya sudah diajukan di Pengajuan UJ langsung tertaut ke pengajuannya.
            // Keterangan dari rekap boleh diganti keterangan pengajuannya (supaya tidak dianggap berbeda); nominal rekap dipertahankan.
            const DARI_BT = @json(! old('detail') && empty($edit) && empty($dariPengajuan) && ! empty($dariBayarTanah['detail'] ?? []));
            barisDetail().forEach(tr => {
                if (tr.querySelector('.pengajuan-kunci').value) return;
                if (DARI_BT) tr.querySelector('[data-nama=keterangan]').classList.add('salinan');
                cocokPj(tr, false);
            });
            urutkanNama();
            tampilModePj();
            evaluasi();
        })();
    </script>
    @unless ($edit)
    <script>
        // ===== Input bayar tanah: pilih satu rekap bayar tanah (RBT) → master + semua DO-nya masuk ke form (seperti reimburse UJ di Kas Harian) =====
        (() => {
            const panel = document.getElementById('panel-bt');
            if (!panel) return;
            const daftar = document.getElementById('daftar-bt');
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const fmt = n => (+n || 0).toLocaleString('id-ID');
            const rp = n => 'Rp ' + fmt(n);
            const tabelDetail = r => `<table><thead><tr><th>No DO</th><th>DT</th><th>Driver</th><th>Keterangan UJ</th><th class="angka">Harga tanah</th></tr></thead><tbody>`
                + r.detail.map(x => `<tr><td>${esc(x.do)}</td><td>${esc(x.mobil)}</td><td>${esc(x.driver)}</td><td>${esc(x.keterangan)}</td><td class="angka">${fmt(x.harga)}</td></tr>`).join('')
                + `<tr><td colspan="4"><b>Total ${r.detail.length} DO</b></td><td class="angka"><b>${fmt(r.total)}</b></td></tr></tbody></table>`;
            const masukkan = r => {
                const adaIsi = [...document.querySelectorAll('#daftar-bon [data-nama=keterangan], #daftar-bon [data-nama=nominal]')].some(el => el.value.trim());
                if (adaIsi && !confirm(`Isi form sekarang akan diganti dengan ${r.kode}. Lanjutkan?`)) return;
                window.onbeforeunload = null;
                location.href = @json($ajukan ? route('pengajuan-uj.buat') : route('uj.input')) + '?rbt=' + r.id;
            };
            const AJUKAN_BT = @json($ajukan);
            const muat = async () => {
                daftar.innerHTML = '<p class="redup">Memuat…</p>';
                const {rekap} = await (await fetch(@json(route('uj.input.bayar-tanah')), {headers: {Accept: 'application/json'}})).json();
                if (!rekap.length) { daftar.innerHTML = '<p class="redup">Belum ada rekap bayar tanah dalam 30 hari terakhir. Buat rekapnya di menu Ritasi → Bayar Tanah.</p>'; return; }
                daftar.innerHTML = '';
                rekap.forEach(r => {
                    // Rekap = catatan DO mana saja yang dibayar tanahnya (owner 21 Okt 2026). Sudah direalisasikan (dibayar lewat Input UJ,
                    // atau semua DO-nya sudah punya Uang Tanah di Kas UJ): di Ajukan Uang Jalan tidak bisa dipakai lagi; di Input UJ hanya ditanya.
                    const sudah = r.terealisasi;
                    const kunci = sudah && AJUKAN_BT;
                    const el = document.createElement('div');
                    el.className = 'rui-grup';
                    const status = sudah ? `<span class="label hijau">✓ Sudah direalisasikan${r.no_uj ? ' · UJ-' + r.no_uj : ''}</span>` : '';
                    el.innerHTML = `<div class="rui-master${kunci ? ' sudah' : ''}" title="${kunci ? 'Sudah direalisasikan — tidak bisa dipakai lagi untuk pengajuan' : 'Klik untuk memasukkan semua DO rekap ini ke form'}">
                            <button type="button" class="rui-buka" title="Lihat rincian">▸</button>
                            <span class="rui-judul"><b>${esc(r.judul)} · ${esc(r.kode)}</b><span class="redup">${r.jumlah} DO · dibuat ${esc(r.jam)}${r.oleh ? ' · ' + esc(r.oleh) : ''}${r.catatan ? ' · ' + esc(r.catatan) : ''}</span></span>
                            ${status}<span class="rui-total">${rp(r.total)}</span></div><div class="rui-detail" hidden></div>`;
                    const detail = el.querySelector('.rui-detail');
                    el.querySelector('.rui-buka').addEventListener('click', e => {
                        e.stopPropagation();
                        el.classList.toggle('terbuka');
                        detail.hidden = !el.classList.contains('terbuka');
                        if (!detail.hidden && !detail.innerHTML) detail.innerHTML = tabelDetail(r);
                    });
                    el.querySelector('.rui-master').addEventListener('click', () => {
                        if (kunci) { alert(`${r.kode} sudah direalisasikan${r.no_uj ? ' (UJ-' + r.no_uj + ')' : ''} — tidak bisa dipakai lagi untuk pengajuan uang jalan.`); return; }
                        if (sudah && !confirm(`${r.kode} sudah direalisasikan${r.no_uj ? ' di UJ-' + r.no_uj : ''}. Tetap masukkan DO-nya ke form?`)) return;
                        masukkan(r);
                    });
                    daftar.appendChild(el);
                });
            };
            document.getElementById('buka-bt').addEventListener('click', () => { panel.hidden = false; panel.scrollIntoView({behavior: 'smooth'}); muat(); });
            document.getElementById('tutup-bt').addEventListener('click', () => { panel.hidden = true; });
        })();
    </script>
    @endunless
@endsection
