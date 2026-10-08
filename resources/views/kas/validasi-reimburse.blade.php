@extends('layouts.app', ['judul' => 'Validasi Reimburse'])

@section('lebar', '1440px')

@section('isi')
    <style>
        .panel-unggah { display: grid; grid-template-columns: minmax(240px, 1.6fr) minmax(180px, 1fr) 90px 90px auto; gap: 12px; align-items: end; }
        .panel-unggah label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .panel-unggah input[type=text] { width: 100%; padding: 9px 10px; border-radius: 7px; font-size: 15px; }
        .panel-unggah input.kolom { text-align: center; text-transform: uppercase; font-weight: 600; }
        .panel-unggah input[type=file] { width: 100%; font-size: 14px; }
        .hasil-valid { border: 1px solid rgba(76, 195, 138, .45); background: var(--sukses-muda); }
        .hasil-gagal { border: 1px solid rgba(229, 72, 77, .5); background: rgba(229, 72, 77, .10); }
        .hasil-valid h3, .hasil-gagal h3 { margin: 0 0 4px; }
        table.kas td.angka, table.kas th.angka { text-align: right; font-variant-numeric: tabular-nums; }
        table.kas tr.total td { font-weight: 700; border-top: 2px solid var(--garis); }
        table.kas tr.merah td { color: #ff8a8f; }
        h4 { margin: 18px 0 8px; }
        @media (max-width: 900px) { .panel-unggah { grid-template-columns: 1fr 1fr; } }
    </style>

    <form method="POST" action="{{ route('kas.validasi-reimburse.periksa') }}" enctype="multipart/form-data" class="kartu">
        @csrf
        <h3 style="margin: 0 0 4px;">Validasi daftar reimburse</h3>
        <p class="redup" style="margin: 0 0 14px; font-size: 14px;">Upload Excel daftar transaksi yang akan direimburse. Aplikasi mengambil semua ID transaksi di <b>kolom A</b>,
            mengecek status reimburse-nya supaya tidak ada double reimburse, lalu bila semua aman menampilkan rekap nominal per Kode GL.</p>
        <div class="panel-unggah">
            <div>
                <label for="file">File Excel (.xlsx)</label>
                <input type="file" id="file" name="file" accept=".xlsx" required>
            </div>
            <div>
                <label for="lembar">Nama sheet</label>
                <input type="text" id="lembar" name="lembar" value="{{ old('lembar', $hasil['lembar'] ?? '') }}" placeholder="mis. 31" required autocomplete="off">
            </div>
            <div>
                <label for="kolom_gl">Kolom Kode GL</label>
                <input type="text" id="kolom_gl" name="kolom_gl" class="kolom" value="{{ old('kolom_gl', request('kolom_gl', 'K')) }}" maxlength="2" required>
            </div>
            <div>
                <label for="kolom_nominal">Kolom nominal</label>
                <input type="text" id="kolom_nominal" name="kolom_nominal" class="kolom" value="{{ old('kolom_nominal', request('kolom_nominal', 'N')) }}" maxlength="2" required>
            </div>
            <div><button class="tombol" type="submit">Validasi</button></div>
        </div>
        @if ($errors->any())
            <div class="pesan galat" style="margin: 12px 0 0;">{{ $errors->first() }}</div>
        @endif
    </form>

    @if ($hasil)
        @php($bermasalah = count($hasil['sudah']) + count($hasil['ganda']) + count($hasil['tak_terbaca']))
        <div @class(['kartu', 'hasil-valid' => $hasil['valid'], 'hasil-gagal' => ! $hasil['valid']])>
            @if ($hasil['valid'])
                <h3>✓ Valid — tidak ada transaksi yang sudah pernah direimburse</h3>
            @else
                <h3>✕ Belum valid — ada {{ $bermasalah }} baris yang harus dibereskan dulu</h3>
            @endif
            <div class="redup">{{ $namaFile }} · sheet "{{ $hasil['lembar'] }}" · {{ count($hasil['baris']) }} transaksi · {{ rp($hasil['total']) }}</div>
        </div>

        @if ($hasil['valid'])
            <div class="kartu gulir" style="padding: 0 0 4px;">
                <h4 style="padding: 0 14px;">Rekap per Kode GL</h4>
                <table class="kas">
                    <thead><tr><th>Kode GL</th><th class="angka">Jumlah transaksi</th><th class="angka">Nominal</th></tr></thead>
                    <tbody>
                        @foreach ($hasil['rekap'] as $r)
                            <tr><td>{{ $r['gl'] }}</td><td class="angka">{{ $r['jumlah'] }}</td><td class="angka">{{ rp($r['nominal']) }}</td></tr>
                        @endforeach
                        <tr class="total"><td>Total</td><td class="angka">{{ count($hasil['baris']) }}</td><td class="angka">{{ rp($hasil['total']) }}</td></tr>
                    </tbody>
                </table>
            </div>
        @endif

        @if ($hasil['sudah'])
            <div class="kartu gulir" style="padding: 0 0 4px;">
                <h4 style="padding: 0 14px;">⛔ Sudah pernah direimburse ({{ count($hasil['sudah']) }}) — keluarkan dari daftar</h4>
                <table class="kas">
                    <thead><tr><th>Baris</th><th>ID Transaksi</th><th>Keterangan</th><th>Kode GL</th><th class="angka">Nominal</th><th>Tgl reimburse</th></tr></thead>
                    <tbody>
                        @foreach ($hasil['sudah'] as $b)
                            <tr class="merah"><td>{{ $b['baris'] }}</td><td><b>{{ $b['id'] }}</b></td><td>{{ $b['ket'] }}</td><td>{{ $b['gl'] }}</td>
                                <td class="angka">{{ rp($b['nominal']) }}</td>
                                <td>{{ $b['tgl_reimburse'] ? \Illuminate\Support\Carbon::parse($b['tgl_reimburse'])->translatedFormat('j M Y') : 'tanggal tidak tercatat' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($hasil['ganda'])
            <div class="kartu gulir" style="padding: 0 0 4px;">
                <h4 style="padding: 0 14px;">⛔ ID muncul lebih dari sekali di sheet ini ({{ count($hasil['ganda']) }} baris)</h4>
                <table class="kas">
                    <thead><tr><th>Baris</th><th>ID Transaksi</th><th>Keterangan</th><th>Kode GL</th><th class="angka">Nominal</th></tr></thead>
                    <tbody>
                        @foreach (collect($hasil['ganda'])->sortBy('id') as $b)
                            <tr class="merah"><td>{{ $b['baris'] }}</td><td><b>{{ $b['id'] }}</b></td><td>{{ $b['ket'] }}</td><td>{{ $b['gl'] }}</td><td class="angka">{{ rp($b['nominal']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($hasil['tak_terbaca'])
            <div class="kartu gulir" style="padding: 0 0 4px;">
                <h4 style="padding: 0 14px;">⛔ Nominal tidak terbaca ({{ count($hasil['tak_terbaca']) }}) — cek kolom nominal</h4>
                <table class="kas">
                    <thead><tr><th>Baris</th><th>ID Transaksi</th><th>Isi kolom nominal</th></tr></thead>
                    <tbody>
                        @foreach ($hasil['tak_terbaca'] as $b)
                            <tr class="merah"><td>{{ $b['baris'] }}</td><td><b>{{ $b['id'] }}</b></td><td>{{ $b['isi'] === '' ? '(kosong)' : $b['isi'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($hasil['tak_dikenal'] || $hasil['beda'])
            <details class="kartu gulir" style="padding: 0 0 4px;">
                <summary style="padding: 14px; cursor: pointer; font-weight: 600;">⚠ Perlu dicek, tidak menghalangi validasi
                    ({{ count($hasil['tak_dikenal']) }} ID tidak ditemukan di Kas Harian aplikasi · {{ count($hasil['beda']) }} nominal berbeda dengan aplikasi)</summary>
                <table class="kas">
                    <thead><tr><th>Baris</th><th>ID Transaksi</th><th>Keterangan</th><th class="angka">Nominal Excel</th><th>Catatan</th></tr></thead>
                    <tbody>
                        @foreach ($hasil['tak_dikenal'] as $b)
                            <tr><td>{{ $b['baris'] }}</td><td><b>{{ $b['id'] }}</b></td><td>{{ $b['ket'] }}</td><td class="angka">{{ rp($b['nominal']) }}</td><td>ID tidak ditemukan di Kas Harian aplikasi</td></tr>
                        @endforeach
                        @foreach ($hasil['beda'] as $b)
                            <tr><td>{{ $b['baris'] }}</td><td><b>{{ $b['id'] }}</b></td><td>{{ $b['ket'] }}</td><td class="angka">{{ rp($b['nominal']) }}</td><td>Nominal di aplikasi {{ rp($b['nominal_app']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif
    @endif
@endsection
