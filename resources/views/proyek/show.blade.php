@extends('layouts.app', ['judul' => $tahap->nama])

@section('lebar', '1400px')

@section('isi')
    @php
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y') : '';
        $warnaStatus = ['belum' => '', 'progress' => 'kuning', 'selesai' => 'hijau', 'batal' => 'merah'];
    @endphp
    <style>
        table.grid { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.grid th, table.grid td { border: 1px solid var(--garis); padding: 6px 8px; vertical-align: top; }
        table.grid thead th { position: sticky; top: 0; background: var(--kartu, #1e2229); z-index: 1; text-align: left; }
        table.grid tfoot td { font-weight: 700; }
        .angka { text-align: right !important; white-space: nowrap; }
    </style>

    <div class="kartu">
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="{{ route('proyek.index') }}" class="tombol polos" style="padding: 6px 12px;">← Daftar Proyek</a>
            <h3 class="dash-judul" style="margin: 0; color: var(--aksen-terang);">{{ $tahap->nama }}</h3>
            <span class="redup">{{ $tahap->proyek->nama }}</span>
            <span class="label {{ $warnaStatus[$tahap->status] ?? '' }}">{{ \App\Models\ProyekTahap::STATUS[$tahap->status] }}</span>
            <span class="redup">· Cost center: <b>{{ $tahap->costCenter() ?? '–' }}</b></span>
            @if ($tahap->surveyor)<span class="redup">· Surveyor: {{ $tahap->surveyor }}</span>@endif @if ($tahap->lokasi)<span class="redup">· Lokasi: {{ $tahap->lokasi }}</span>@endif
        </div>
    </div>

    @if (auth()->user()->bolehMenu('anggaran-proyek'))
        @php($ag = \App\Support\Anggaran::hitung($tahap->anggaran()->with('komponen')->get(), $tahap))
        <div class="kartu" style="display: flex; gap: 18px; align-items: center; flex-wrap: wrap;">
            <h4 style="margin: 0; color: var(--aksen-terang);">Anggaran</h4>
            @if ($ag['omset'] || $ag['hpp'])
                <span>Omset <b>{{ rp((int) round($ag['omset'])) }}</b></span>
                <span>HPP <b>{{ rp((int) round($ag['hpp'])) }}</b></span>
                <span>Margin <b @if ($ag['margin'] < 0) style="color: var(--merah);" @endif>{{ $ag['persen_margin'] !== null ? number_format($ag['persen_margin'], 1, ',', '.').'%' : '–' }}</b></span>
                @if ($tahap->anggaran_dikunci_at)<span class="label hijau">baseline {{ $tahap->anggaran_dikunci_at->translatedFormat('j M Y') }}</span>@endif
            @else
                <span class="redup">Belum ada anggaran omset & HPP untuk tahap ini.</span>
            @endif
            <a href="{{ route('anggaran.edit', $tahap) }}" class="tombol polos" style="margin-left: auto; padding: 6px 12px;">{{ $ag['omset'] || $ag['hpp'] ? 'Buka anggaran & progres' : 'Isi anggaran' }} →</a>
        </div>
    @endif

    @include('proyek._form')

    <div class="kartu">
        <h4 style="margin: 0 0 8px; color: var(--aksen-terang);">Kas Harian per akun</h4>
        @if ($perAkun->isEmpty())
            <p class="redup" style="margin: 0;">Belum ada transaksi Kas Harian yang terpetakan ke tahap ini.</p>
        @else
            <table class="grid" style="width: auto; min-width: 420px;">
                <thead><tr><th>Akun</th><th class="angka">Transaksi</th>@if ($super)<th class="angka">Nominal</th>@endif</tr></thead>
                @foreach ($perAkun as $akun => $a)
                    <tr><td>{{ $akun }}</td><td class="angka">{{ number_format($a['n'], 0, ',', '.') }}</td>@if ($super)<td class="angka">{{ rp((int) $a['rp']) }}</td>@endif</tr>
                @endforeach
                <tfoot><tr><td>Total</td><td class="angka">{{ number_format($transaksi->count(), 0, ',', '.') }}</td>@if ($super)<td class="angka">{{ rp((int) $transaksi->sum('nominal')) }}</td>@endif</tr></tfoot>
            </table>
        @endif
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <div style="padding: 12px 14px; display: flex; gap: 10px; align-items: center;">
            <h4 style="margin: 0; color: var(--aksen-terang);">Transaksi Kas Harian</h4>
            <span id="jumlah-tampil" class="redup" style="font-size: 13px;"></span>
        </div>
        <table class="grid" id="grid" data-saring-kolom>
            <thead>
                <tr><th>Tanggal</th><th>No ID</th><th>Kode GL</th><th>Akun</th><th>PIC</th><th>Keterangan</th>@if ($super)<th class="angka">Nominal</th>@endif</tr>
            </thead>
            <tbody>
                @foreach ($transaksi as $r)
                    <tr>
                        <td data-v="{{ substr($r->tanggal, 0, 10) }} {{ $tgl($r->tanggal) }}" data-n="{{ substr($r->tanggal, 0, 10) }}" style="white-space: nowrap;">{{ $tgl($r->tanggal) }}</td>
                        <td>{{ $r->no_id }}</td>
                        <td style="white-space: nowrap;">{{ $r->kode_asli }}</td>
                        <td>{{ $r->akun }}</td>
                        <td>{{ $r->pic }}</td>
                        <td>{{ $r->keterangan }}</td>
                        @if ($super)<td class="angka" data-n="{{ $r->nominal }}">{{ rp((int) $r->nominal) }}</td>@endif
                    </tr>
                @endforeach
            </tbody>
            @if ($super)
                <tfoot><tr><td colspan="6">Total yang tampil</td><td class="angka" id="total-tampil"></td></tr></tfoot>
            @endif
        </table>
    </div>

    <script>
        (() => {
            const baris = [...document.querySelectorAll('#grid tbody tr')];
            const total = document.getElementById('total-tampil');
            const rp = n => n.toLocaleString('id-ID');
            // Saringan kolom = saring-kolom.js (seragam di semua grid); jumlah & total ikut baris yang tampil.
            const hitung = () => {
                let n = 0, s = 0;
                baris.forEach(tr => {
                    if (tr.style.display === 'none') return;
                    n++; if (total) s += parseFloat(tr.cells[6]?.dataset.n) || 0;
                });
                document.getElementById('jumlah-tampil').textContent = `${n} dari ${baris.length} transaksi`;
                if (total) total.textContent = rp(s);
            };
            document.querySelector('#grid thead').addEventListener('input', () => setTimeout(hitung));
            hitung();
        })();
    </script>
@endsection
