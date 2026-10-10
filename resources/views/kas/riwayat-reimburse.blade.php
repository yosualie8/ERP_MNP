@extends('layouts.app', ['judul' => 'History Reimburse'])

@section('lebar', '1280px')

@section('isi')
    @php
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y') : '—';
        $namaBulan = fn ($b) => \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M Y');
    @endphp
    <div class="kartu">
        <h3 style="margin: 0 0 4px;">History Reimburse</h3>
        <p class="redup" style="margin: 0 0 12px;">
            Transaksi Kas Harian yang sudah direimburse, direkap per tanggal reimburse. Klik satu baris untuk membuka/menutup daftar transaksinya.
        </p>
        <div style="margin-bottom: 10px;">
            @foreach ($daftarBulan as $b => $x)
                <a href="{{ route('kas.riwayat-reimburse', ['bulan' => $b]) }}" @class(['chip', 'aktif' => $cari === '' && $b === $bulan])
                    title="{{ $x->hari }} tanggal reimburse · {{ number_format($x->n, 0, ',', '.') }} transaksi">{{ $namaBulan($b) }} <span class="redup">· {{ number_format($x->n, 0, ',', '.') }}</span></a>
            @endforeach
        </div>
        <form method="GET" action="{{ route('kas.riwayat-reimburse') }}" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <input type="search" name="q" value="{{ $cari }}" placeholder="Cari di semua bulan: ID transaksi, PIC, keterangan, Kode GL, tujuan…" style="max-width: 460px;">
            <button class="tombol" type="submit" style="padding: 8px 14px;">Cari</button>
            @if ($cari !== '')
                <a href="{{ route('kas.riwayat-reimburse', ['bulan' => $bulan]) }}" class="redup">Hapus pencarian</a>
            @endif
            <span class="redup">
                {{ $cari !== '' ? 'Hasil pencarian "'.$cari.'"' : $namaBulan($bulan) }}:
                @php($asli = $grup->reject(fn ($g) => $g['penyesuaian']))
                @php($sesuai = $grup->filter(fn ($g) => $g['penyesuaian']))
                {{ $asli->count() }} pencatatan reimburse · {{ number_format($asli->sum('jumlah'), 0, ',', '.') }} transaksi · <b>{{ rp($asli->sum('total')) }}</b>
                @if ($sesuai->isNotEmpty())<span title="Penandaan data lama, bukan reimburse baru — tidak ikut dijumlah"> · penyesuaian data lama {{ number_format($sesuai->sum('jumlah'), 0, ',', '.') }} transaksi ({{ rp($sesuai->sum('total')) }}) tidak dijumlah</span>@endif
            </span>
            <button type="button" class="tombol polos" id="buka-semua" style="margin-left: auto;">Buka semua</button>
        </form>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead>
                <tr>
                    <th>Tanggal reimburse</th>
                    <th>Transaksi</th>
                    <th>Akun terbesar</th>
                    <th>Dicatat</th>
                    <th class="angka">Total</th>
                    <th></th>
                </tr>
            </thead>
            @forelse ($grup as $g)
                <tbody @class(['grup', 'buka' => $cari !== ''])>
                    <tr class="t ada-bon" @if ($g['penyesuaian']) style="opacity: .7;" @endif>
                        <td><span class="panah" aria-hidden="true">▸</span> <b>{{ $g['tanggal'] ? $g['tanggal']->translatedFormat('d-M-Y') : 'Tanpa tanggal' }}</b>
                            @if ($g['tanggal'])<span class="redup" style="font-size: 12px;">{{ $g['tanggal']->translatedFormat('l') }}</span>@endif
                            @if ($g['penyesuaian'])<br><span class="label kuning" title="Penandaan data lama yang dicatat pada tanggal ini — bukan reimburse baru; tidak ikut dijumlah di atas">Penyesuaian data lama</span>@endif</td>
                        <td>{{ number_format($g['jumlah'], 0, ',', '.') }} transaksi
                            @if ($g['tanpa_data'])<span class="label" title="ID ini tidak ada di Kas Harian aplikasi (lembar 2025 belum diimpor), jadi nominalnya tidak ikut dijumlah">{{ $g['tanpa_data'] }} tanpa data</span>@endif</td>
                        <td class="ringkas-gl">{{ $g['per_akun']->keys()->take(3)->implode(', ') }}@if ($g['per_akun']->count() > 3) +{{ $g['per_akun']->count() - 3 }}@endif</td>
                        <td style="font-size: 12px;">
                            @if ($g['oleh'])
                                Aplikasi · {{ implode(', ', $g['oleh']) }}@if ($g['dicatat']) <span class="redup">{{ $tgl($g['dicatat']) }} {{ \Illuminate\Support\Carbon::parse($g['dicatat'])->format('H:i') }}</span>@endif
                                @if ($g['catatan'])<br><span class="redup">{{ $g['catatan'] }}</span>@endif
                            @endif
                            @if ($g['dari_sheet'])
                                @if ($g['oleh'])<br>@endif<span class="redup">{{ $g['oleh'] ? $g['dari_sheet'].' dari' : 'Dari' }} lembar Sudah Reimburse (data awal)</span>
                            @endif
                        </td>
                        <td class="angka"><b>{{ rp($g['total']) }}</b></td>
                        <td style="white-space: nowrap;">@if ($g['menunggu_sheet'])<span class="redup" title="Sudah tersimpan di aplikasi; sedang ditulis ke lembar Sudah Reimburse">⏳ {{ $g['menunggu_sheet'] }} ke sheet</span>@endif</td>
                    </tr>
                    <tr class="bh"><td colspan="6" style="white-space: normal; line-height: 1.8;">Per akun:
                        @foreach ($g['per_akun'] as $akun => $nilai)
                            <span style="text-transform: none; font-weight: 400; margin-right: 12px;">{{ $akun }} <b>{{ rp($nilai) }}</b></span>
                        @endforeach
                    </td></tr>
                    <tr class="bh"><td>Tanggal transaksi</td><td>PIC / tujuan</td><td>Keterangan</td><td>Kode GL</td><td class="angka">Nominal</td><td>ID transaksi</td></tr>
                    @foreach ($g['rinci'] as $r)
                        <tr class="b">
                            <td>{{ $tgl($r['tanggal'] ?? null) }}</td>
                            <td class="p">{{ $r['pic'] ?? '' }}@if (! empty($r['tujuan']) && ($r['pic'] ?? null) !== $r['tujuan']) <span class="redup">· {{ $r['tujuan'] }}</span>@endif</td>
                            <td>@if (isset($r['nominal'])){{ $r['ket'] }}@if (! empty($r['no_mobil'])) <span class="label" title="No Mobil (truk)">🚛 {{ $r['no_mobil'] }}</span>@endif
                                @else<i class="x">tidak ada di Kas Harian aplikasi</i>@endif</td>
                            <td>{{ $r['kode_gl'] ?? '' }}</td>
                            <td class="angka">{{ isset($r['nominal']) ? rp($r['nominal']) : '—' }}</td>
                            <td class="i">{{ $r['id'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="6" class="redup" style="padding: 20px 14px;">{{ $cari !== '' ? 'Tidak ada transaksi reimburse yang cocok.' : 'Belum ada data reimburse.' }}</td></tr></tbody>
            @endforelse
        </table>
    </div>

    <script>
        // Rincian tertutup di awal; klik baris tanggal untuk membuka/menutup.
        document.querySelectorAll('tbody.grup tr.t').forEach(tr => tr.addEventListener('click', e => {
            if (e.target.closest('button, a') || getSelection().toString()) return;
            tr.parentElement.classList.toggle('buka');
            aturTombolSemua();
        }));
        const tombolSemua = document.getElementById('buka-semua');
        const grup = () => [...document.querySelectorAll('tbody.grup')];
        const aturTombolSemua = () => tombolSemua.textContent = grup().length && grup().every(g => g.classList.contains('buka')) ? 'Tutup semua' : 'Buka semua';
        tombolSemua.addEventListener('click', () => {
            const buka = !grup().every(g => g.classList.contains('buka'));
            grup().forEach(g => g.classList.toggle('buka', buka));
            aturTombolSemua();
        });
        aturTombolSemua();
    </script>
@endsection
