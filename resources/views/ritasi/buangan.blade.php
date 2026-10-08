@extends('layouts.app', ['judul' => 'Buangan Truck'])

@section('lebar', '1440px')

@section('isi')
    <style>
        table.kas td.angka, table.kas th.angka { text-align: right; font-variant-numeric: tabular-nums; }
        table.kas input.tujuan { width: 100%; min-width: 220px; padding: 7px 9px; border-radius: 6px; font-size: 14px; }
        table.kas input.tujuan.berubah { border-color: #e0a526; box-shadow: 0 0 0 2px rgba(224, 165, 38, .25); }
        .sumber { font-size: 11px; padding: 1px 7px; border-radius: 8px; white-space: nowrap; }
        .sumber.admin { background: rgba(108, 140, 255, .18); color: #a9bbff; }
        .sumber.ritasi { background: rgba(255, 255, 255, .06); color: var(--redup); }
        .simpan-buangan { position: sticky; bottom: 0; display: flex; gap: 12px; align-items: center; padding: 12px 16px; margin-top: 12px;
            background: var(--kartu); border: 1px solid var(--garis); border-radius: 10px; }
        .ringkas-tujuan { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    </style>

    @php($perTujuan = $truk->groupBy(fn ($t) => $t['tujuan'] ?? '(belum ada)')->map->count()->sortDesc())
    <div class="ringkas">
        <div class="kartu"><span class="redup">Truk aktif {{ \App\Support\BuanganTruk::HARI }} hari terakhir</span><b>{{ $truk->count() }}</b></div>
        <div class="kartu"><span class="redup">Tujuan buangan</span><b>{{ $perTujuan->count() }}</b></div>
        <div class="kartu"><span class="redup">Diatur admin</span><b>{{ $truk->where('sumber', 'admin')->count() }}</b></div>
    </div>
    <div class="ringkas-tujuan">
        @foreach ($perTujuan as $tujuan => $n)
            <a class="chip" href="{{ route('ritasi.monitor', ['tujuan' => $tujuan]) }}" title="Lihat DO belum bongkar truk-truk ini di Monitor Ritasi">{{ $tujuan }} · {{ $n }} truk</a>
        @endforeach
    </div>
    <p class="redup" style="margin: 0 0 12px; font-size: 13px;">Truk yang punya rit atau transaksi Kas UJ dalam {{ \App\Support\BuanganTruk::HARI }} hari terakhir.
        Tujuan buangan bawaannya tahap rit terakhir truk itu; ubah bila truk sudah dipindah ke tujuan lain — berlaku sampai diubah lagi
        (kosongkan untuk kembali mengikuti ritasi). Dipakai untuk menyaring DO di Monitor Ritasi.</p>

    <form method="POST" action="{{ route('ritasi.buangan.simpan') }}" id="form-buangan">
        @csrf
        <div class="kartu gulir" style="padding: 0;">
            <table class="kas">
                <thead><tr><th>Truk</th><th>Jenis</th><th>Driver terakhir</th><th class="angka">Rit</th><th>Rit terakhir</th><th class="angka">UJ</th><th>Galian terakhir</th><th style="min-width: 260px;">Tujuan buangan</th><th>Keterangan</th></tr></thead>
                <tbody>
                    @forelse ($truk as $t)
                        <tr>
                            <td><b>{{ $t['no_lambung'] }}</b>@unless ($t['di_aset'])<span class="label kuning" title="Tidak terdaftar di Data Aset" style="margin-left: 4px;">!</span>@endunless</td>
                            <td>{{ $t['jenis'] ?? '–' }}</td>
                            <td>{{ $t['driver'] ?? '–' }}</td>
                            <td class="angka">{{ $t['rit'] }}</td>
                            <td>{{ $t['rit_terakhir']?->translatedFormat('j M') ?? '–' }}</td>
                            <td class="angka">{{ $t['uj'] }}</td>
                            <td>{{ $t['galian'] ?? '–' }}</td>
                            <td><input type="text" class="tujuan" name="tujuan[{{ $t['no_lambung'] }}]" value="{{ $t['tujuan'] }}" data-awal="{{ $t['tujuan'] }}"
                                    list="saran-tujuan" autocomplete="off" placeholder="mis. ASG Tahap 116"></td>
                            <td style="font-size: 12px;">
                                @if ($t['sumber'] === 'admin')
                                    <span class="sumber admin">diatur admin</span> <span class="redup">{{ $t['diatur'] }}</span>
                                    @if ($t['tahap_rit'] && $t['tahap_rit'] !== $t['tujuan'])<br><span class="redup">rit terakhir: {{ $t['tahap_rit'] }} ({{ $t['tanggal_tahap']?->translatedFormat('j M') }})</span>@endif
                                @elseif ($t['sumber'] === 'ritasi')
                                    <span class="sumber ritasi">dari rit terakhir {{ $t['tanggal_tahap']?->translatedFormat('j M') }}</span>
                                @else
                                    <span class="redup">belum pernah ada rit</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="redup" style="padding: 20px 14px;">Tidak ada truk yang aktif dalam {{ \App\Support\BuanganTruk::HARI }} hari terakhir.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <datalist id="saran-tujuan">@foreach ($saran as $s)<option value="{{ $s }}">@endforeach</datalist>
        <div class="simpan-buangan">
            <button class="tombol" type="submit" id="simpan-buangan" disabled>Simpan perubahan</button>
            <span class="redup" id="info-buangan">Belum ada perubahan.</span>
        </div>
    </form>
    <script>
        (() => {
            const isian = [...document.querySelectorAll('input.tujuan')];
            const tombol = document.getElementById('simpan-buangan');
            const info = document.getElementById('info-buangan');
            const hitung = () => {
                const ubah = isian.filter(i => i.value.trim() !== i.dataset.awal);
                isian.forEach(i => i.classList.toggle('berubah', i.value.trim() !== i.dataset.awal));
                tombol.disabled = !ubah.length;
                info.textContent = ubah.length ? `${ubah.length} truk diubah: ` + ubah.map(i => i.name.slice(7, -1) + ' → ' + (i.value.trim() || '(ikut ritasi)')).join(', ') : 'Belum ada perubahan.';
            };
            isian.forEach(i => i.addEventListener('input', hitung));
            window.addEventListener('beforeunload', e => { if (!tombol.disabled && !tombol.dataset.kirim) e.preventDefault(); });
            document.getElementById('form-buangan').addEventListener('submit', () => { tombol.dataset.kirim = '1'; });
        })();
    </script>
@endsection
