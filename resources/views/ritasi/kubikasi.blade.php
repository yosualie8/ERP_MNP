@extends('layouts.app', ['judul' => 'Kubikasi Kendaraan'])

@section('lebar', '760px')

@section('isi')
    @php($des = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ','))
    <style>
        table.grid { width: 100%; border-collapse: collapse; font-size: 14px; }
        table.grid th, table.grid td { border: 1px solid var(--garis); padding: 6px 8px; }
        table.grid th { text-align: left; }
        table.grid input[type=text] { width: 100%; box-sizing: border-box; padding: 6px 8px; border-radius: 6px; }
        table.grid .angka { text-align: right; width: 120px; }
    </style>
    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ route('kubikasi.simpan') }}" class="kartu">
        @csrf
        <h3 style="margin: 0 0 4px;">Kubikasi standar per jenis kendaraan</h3>
        <p class="redup" style="font-size: 13px; margin: 0 0 12px;">Saat input ritasi, kolom Kubikasi otomatis terisi angka ini sesuai jenis kendaraannya (rit tanpa jenis = 0). Admin tetap bisa mengetik angka lain.
            Mengubah angka di sini hanya berlaku untuk input berikutnya, rit yang sudah tercatat tidak ikut berubah.</p>
        @if ($belum->isNotEmpty())
            <div class="pesan" style="border-left: 3px solid #e0a526;">Jenis kendaraan di data Ritasi yang belum punya kubikasi standar: <b>{{ $belum->implode(', ') }}</b>. Tambahkan di baris kosong di bawah.</div>
        @endif
        <table class="grid">
            <thead><tr><th>Jenis kendaraan</th><th class="angka">Kubikasi (m³)</th><th class="angka">Rit di data Ritasi</th><th style="width: 60px;">Hapus</th></tr></thead>
            <tbody>
                @foreach (old('baris', $master->map(fn ($m) => ['jenis' => $m->jenis, 'kubikasi' => $des($m->kubikasi)])->all()) as $i => $b)
                    <tr>
                        <td><input type="text" name="baris[{{ $i }}][jenis]" value="{{ $b['jenis'] }}" autocomplete="off"></td>
                        <td><input type="text" name="baris[{{ $i }}][kubikasi]" value="{{ $b['kubikasi'] }}" inputmode="decimal" style="text-align: right;" autocomplete="off"></td>
                        <td class="angka redup">{{ number_format($pakai[mb_strtolower($b['jenis'])] ?? 0, 0, ',', '.') }}</td>
                        <td style="text-align: center;"><input type="checkbox" name="baris[{{ $i }}][hapus]" value="1" title="Hapus jenis ini dari master"></td>
                    </tr>
                @endforeach
                @foreach (range(1, 3) as $j)
                    @php($i = count(old('baris', $master)) + $j)
                    <tr>
                        <td><input type="text" name="baris[{{ $i }}][jenis]" placeholder="Jenis baru…" autocomplete="off"></td>
                        <td><input type="text" name="baris[{{ $i }}][kubikasi]" inputmode="decimal" style="text-align: right;" autocomplete="off"></td>
                        <td></td><td></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div style="margin-top: 14px;"><button type="submit" class="tombol">Simpan</button></div>
    </form>
@endsection
