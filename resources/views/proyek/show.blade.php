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
        table.grid .saring th { padding: 4px; }
        table.grid .saring input { width: 100%; box-sizing: border-box; padding: 4px 6px; font-size: 12px; border-radius: 5px; min-width: 60px; }
        table.grid tfoot td { font-weight: 700; }
        .angka { text-align: right !important; white-space: nowrap; }
    </style>

    <div class="kartu">
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="{{ route('proyek.index') }}" class="tombol polos" style="padding: 6px 12px;">← Daftar Proyek</a>
            <h3 class="dash-judul" style="margin: 0; color: var(--aksen-terang);">{{ $tahap->nama }}</h3>
            <span class="redup">{{ $tahap->proyek->nama }}</span>
            <span class="label {{ $warnaStatus[$tahap->status] ?? '' }}">{{ \App\Models\ProyekTahap::STATUS[$tahap->status] }}</span>
            @if ($tahap->lokasi)<span class="redup">· Surveyor: {{ $tahap->lokasi }}</span>@endif
            <a href="{{ route('proyek.edit', $tahap) }}" class="tombol-edit" style="margin-left: auto;">Edit tahap</a>
        </div>
    </div>

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
            <button type="button" class="tombol polos" id="hapus-saring" style="padding: 5px 10px; margin-left: auto;">Hapus saringan</button>
        </div>
        <table class="grid" id="grid">
            <thead>
                <tr><th>Tanggal</th><th>No ID</th><th>Kode GL</th><th>Akun</th><th>PIC</th><th>Keterangan</th>@if ($super)<th class="angka">Nominal</th>@endif</tr>
                <tr class="saring">
                    @foreach (range(0, $super ? 6 : 5) as $i)<th><input data-kolom="{{ $i }}" placeholder="{{ $i === 6 ? 'mis. >1000000' : 'Saring…' }}"></th>@endforeach
                </tr>
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
            const saring = [...document.querySelectorAll('#grid [data-kolom]')];
            const total = document.getElementById('total-tampil');
            const rp = n => n.toLocaleString('id-ID');
            const cocok = (td, q) => {
                q = q.trim().toLowerCase();
                if (!q) return true;
                const m = q.match(/^([<>]=?)\s*(.+)$/);
                if (m && td.dataset.n !== undefined) {
                    const a = td.dataset.n, b = m[2].replace(/\./g, '');
                    const [x, y] = /^\d{4}-/.test(b) ? [a, b] : [parseFloat(a), parseFloat(b)];
                    return m[1] === '>' ? x > y : m[1] === '<' ? x < y : m[1] === '>=' ? x >= y : x <= y;
                }
                return (td.dataset.v ?? td.textContent).toLowerCase().includes(q);
            };
            const terapkan = () => {
                let n = 0, s = 0;
                baris.forEach(tr => {
                    const tampil = saring.every(f => cocok(tr.cells[f.dataset.kolom], f.value));
                    tr.style.display = tampil ? '' : 'none';
                    if (tampil) { n++; if (total) s += parseFloat(tr.cells[6].dataset.n) || 0; }
                });
                document.getElementById('jumlah-tampil').textContent = `${n} dari ${baris.length} transaksi`;
                if (total) total.textContent = rp(s);
            };
            saring.forEach(f => f.addEventListener('input', terapkan));
            document.getElementById('hapus-saring').addEventListener('click', () => { saring.forEach(f => f.value = ''); terapkan(); });
            terapkan();
        })();
    </script>
@endsection
