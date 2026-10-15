@extends('layouts.app', ['judul' => 'Daftar Proyek'])

@section('lebar', '1400px')

@section('isi')
    @php
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y') : '';
        // Nol di belakang koma dibuang hanya untuk desimal (50 tetap 50, 175,80 jadi 175,8).
        $ang = fn ($v, $d = 0) => ! $v ? '0' : ($d ? rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',') : number_format((float) $v, 0, ',', '.'));
        $warnaStatus = ['belum' => '', 'progress' => 'kuning', 'selesai' => 'hijau', 'batal' => 'merah'];
        $rincian = ['rit' => ['Ritasi', 'rit'], 'ngepok' => ['Ngepok', 'rit'], 'solar' => ['Solar', 'liter'], 'rental' => ['Rental alat', 'jam HM'], 'kas' => ['Operasional Kas Harian', 'transaksi']];
    @endphp
    <style>
        .lepas { border-left: 3px solid #e0a526; }
        .lepas table td { padding: 4px 8px; font-size: 13px; vertical-align: middle; }
        .lepas select { padding: 4px 6px; border-radius: 6px; max-width: 320px; }
        table.grid { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.grid th, table.grid td { border: 1px solid var(--garis); padding: 6px 8px; vertical-align: middle; }
        table.grid thead th { position: sticky; top: 0; background: var(--kartu, #1e2229); z-index: 1; }
        table.grid thead tr.saring th { top: 31px; padding: 4px; }
        table.grid .saring input, table.grid .saring select { width: 100%; box-sizing: border-box; padding: 4px 6px; font-size: 12px; border-radius: 5px; min-width: 60px; }
        table.grid tr.m { cursor: pointer; }
        table.grid tr.m:hover td { background: rgba(255, 255, 255, .03); }
        table.grid tr.m .panah { display: inline-block; width: 12px; transition: transform .15s; color: var(--redup); }
        table.grid tr.m.buka .panah { transform: rotate(90deg); }
        table.grid tr.d { display: none; }
        table.grid tr.d.buka { display: table-row; }
        table.grid tr.d > td { background: rgba(0, 0, 0, .15); padding: 8px 12px 10px 34px; }
        table.rinci { border-collapse: collapse; min-width: 520px; }
        table.rinci td, table.rinci th { border: 1px solid var(--garis); padding: 4px 10px; font-size: 12.5px; }
        table.rinci tr.total td { font-weight: 700; }
        .angka { text-align: right; white-space: nowrap; }
    </style>

    <div class="kartu">
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <h3 class="dash-judul" style="margin: 0; color: var(--aksen-terang);">Daftar Proyek</h3>
            <span class="redup" style="font-size: 13px;">Semua tahap, urut tanggal mulai. Klik baris untuk melihat rincian biaya. Isi kotak di bawah judul kolom untuk menyaring (angka/tanggal bisa pakai &gt; atau &lt;, mis. &gt;100000000).</span>
            <a href="{{ route('proyek.create') }}" class="tombol" style="margin-left: auto; padding: 8px 14px;">+ Tahap baru</a>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; font-size: 13px;">
            @php($tambah = $pakaiAtur ? [] : ['semua' => 1])
            @foreach ($jumlahTahun as $th => $n)
                <a href="{{ route('proyek.index', ['tahun' => $th] + $tambah) }}" @class(['chip', 'aktif' => $tahun === [$th]]) title="Tahap yang berjalan di tahun {{ $th }}">{{ $th }} · {{ $n }}</a>
            @endforeach
            <a href="{{ route('proyek.index', ['tahun' => 'semua'] + $tambah) }}" @class(['chip', 'aktif' => ! $tahun])>Semua tahun · {{ $jumlahSemua }}</a>
            <span class="redup" style="margin-left: auto;">Data solar & jam HM dari lembar Penggunaan BBM{{ $waktuBbm ? ', diambil '.\Illuminate\Support\Carbon::parse($waktuBbm)->translatedFormat('d-M H:i') : ' (sheet belum terhubung)' }}</span>
            <a href="{{ route('proyek.index', ['segarkan' => 1] + request()->only('tahun', 'semua')) }}" class="tombol polos" style="padding: 6px 12px;">↻ Segarkan BBM</a>
        </div>
        <div class="redup" style="font-size: 12.5px; margin-top: 8px;">
            @if ($pakaiAtur)
                Filter bawaan: {{ \App\Support\DaftarProyek::PERIODE[$atur['periode']] }}
                · status {{ count($atur['status']) === count(\App\Models\ProyekTahap::STATUS) ? 'semua' : collect($atur['status'])->map(fn ($s) => \App\Models\ProyekTahap::STATUS[$s])->implode(', ') }}
                · tipe {{ count($atur['tipe']) === count(\App\Models\ProyekTahap::TIPE) ? 'semua' : collect($atur['tipe'])->map(fn ($s) => \App\Models\ProyekTahap::TIPE[$s])->implode(', ') }}
                · cost center {{ $atur['cost_center'] ? implode(', ', array_map(fn ($c) => $c === '-' ? 'tanpa cost center' : $c, $atur['cost_center'])) : 'semua' }}
                · <a href="{{ route('proyek.index', ['semua' => 1, 'tahun' => 'semua']) }}">Tampilkan semua tanpa filter bawaan</a>
            @else
                Menampilkan tanpa filter bawaan · <a href="{{ route('proyek.index') }}">Kembali ke filter bawaan</a>
            @endif
            @if (auth()->user()->bolehMenu('pengaturan-proyek')) · <a href="{{ route('pengaturan-proyek') }}">Ubah pengaturan</a>@endif
        </div>
    </div>

    @if ($lepasRitasi || $lepasKas || $lepasBbm)
        <div class="kartu lepas">
            <h4 style="margin: 0 0 6px; color: #f0c05a;">⚠ Nama yang belum terpetakan ke tahap</h4>
            <p class="redup" style="margin: 0 0 8px; font-size: 13px;">Data dengan nama ini belum ikut terhitung di tahap mana pun. Pilih tahapnya lalu klik Petakan.</p>
            <table>
                <thead><tr><th>Sumber</th><th>Nama</th><th>Data</th><th>Petakan ke tahap</th></tr></thead>
                @foreach ([['ritasi', $lepasRitasi], ['bbm', $lepasBbm], ['kas_cc', $lepasKas]] as [$sumber, $daftar])
                    @foreach ($daftar as $l)
                        <tr>
                            <td><span class="label">{{ ['ritasi' => 'Ritasi', 'bbm' => 'BBM', 'kas_cc' => 'Kas Harian'][$sumber] }}</span></td>
                            <td><b>{{ $l['nama'] }}</b></td>
                            <td class="redup">
                                @if ($sumber === 'ritasi'){{ ($l['rit'] ?? 0) + ($l['ngepok'] ?? 0) }} rit · {{ $tgl($l['mulai']) }} s/d {{ $tgl($l['selesai']) }}
                                @elseif ($sumber === 'bbm'){{ $l['n'] }} baris · {{ $ang($l['liter']) }} liter
                                @else{{ $l['n'] }} detail{{ $super ? ' · '.rp($l['rp']) : '' }}
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('proyek.petakan') }}" style="display: flex; gap: 6px;">
                                    @csrf
                                    <input type="hidden" name="sumber" value="{{ $sumber === 'kas_cc' ? (str_contains($l['nama'], ' ') ? 'kas' : 'kas_cc') : $sumber }}">
                                    <input type="hidden" name="nama" value="{{ $l['nama'] }}">
                                    <select name="tahap_id" required><option value="">— pilih tahap —</option>
                                        @foreach ($semuaTahap as $t)<option value="{{ $t->id }}">{{ $t->proyek->nama }} › {{ $t->nama }}</option>@endforeach</select>
                                    <button class="tombol-edit">Petakan</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </table>
        </div>
    @endif

    <div style="display: flex; gap: 6px; justify-content: flex-end; margin-bottom: 6px;">
        <button type="button" class="tombol polos" id="buka-semua" style="padding: 5px 12px; font-size: 13px;" title="Tampilkan rincian biaya semua tahap">⊞ Buka semua</button>
        <button type="button" class="tombol polos" id="tutup-semua" style="padding: 5px 12px; font-size: 13px;" title="Sembunyikan rincian biaya semua tahap">⊟ Tutup semua</button>
    </div>
    <div class="kartu gulir" style="padding: 0;">
        <table class="grid" id="grid" data-saring-kolom>
            <thead>
                <tr><th>Tahap</th><th>Status</th><th>Surveyor</th><th>Lokasi</th><th>Tanggal mulai</th><th>Tanggal akhir</th>
                    @if ($super)<th class="angka">Total biaya</th>@endif</tr>
            </thead>
            <tbody>
                @foreach ($baris as $x)
                    @php($t = $x->t)
                    <tr class="m">
                        <td data-v="{{ $t->nama }} {{ $t->proyek->nama }} {{ $t->costCenter() }} {{ $t->lokasi }} {{ $t->surveyor }}"><span class="panah">▸</span> <a href="{{ route('proyek.show', $t) }}" title="Lihat transaksi Kas Harian tahap ini"><b>{{ $t->nama }}</b></a> <span class="redup" style="font-size: 11px;">· {{ $t->proyek->nama }}{{ $t->costCenter() ? " · ".$t->costCenter() : "" }}</span>@if ($t->tipe !== 'luas') <span class="label">{{ \App\Models\ProyekTahap::TIPE[$t->tipe] }}</span>@endif<div style="padding-left: 16px;">@include('proyek._sub', ['t' => $t])</div></td>
                        <td data-v="{{ \App\Models\ProyekTahap::STATUS[$t->status] }}"><span class="label {{ $warnaStatus[$t->status] ?? '' }}">{{ \App\Models\ProyekTahap::STATUS[$t->status] }}</span></td>
                        <td>{{ $t->surveyor }}</td>
                        <td>{{ $t->lokasi }}</td>
                        <td data-v="{{ $x->mulai }} {{ $tgl($x->mulai) }}" data-n="{{ $x->mulai }}" style="white-space: nowrap;">{{ $tgl($x->mulai) }}</td>
                        <td data-v="{{ $x->akhir }} {{ $tgl($x->akhir) }}" data-n="{{ $x->akhir }}" style="white-space: nowrap;">{{ $tgl($x->akhir) }}</td>
                        @if ($super)<td class="angka" data-n="{{ $x->total }}"><b>{{ $x->total ? rp($x->total) : '' }}</b></td>@endif
                    </tr>
                    <tr class="d">
                        <td colspan="{{ $super ? 7 : 6 }}">
                            <table class="rinci">
                                <tr><th style="text-align: left;">Biaya</th><th class="angka">Jumlah</th>@if ($super)<th class="angka">Nominal</th><th style="text-align: left;">Dasar hitung</th>@endif</tr>
                                @foreach ($rincian as $k => [$label, $satuan])
                                    @php([$n, $rp] = $x->biaya[$k])
                                    <tr><td>{{ $label }}@if ($k === 'kas' && $n) <a href="{{ route('proyek.show', $t) }}" style="font-size: 12px;">lihat transaksi →</a>@endif</td><td class="angka">{{ $ang($n, $k === 'solar' || $k === 'rental' ? 1 : 0) }} {{ $satuan }}</td>
                                        @if ($super)<td class="angka">{{ rp($rp) }}</td>
                                            <td class="redup">{{ match ($k) {
                                                'rit', 'ngepok' => 'Harga Jual di lembar Ritasi',
                                                'solar' => '× '.rp($t->tarif_bbm ?? \App\Models\ProyekTahap::BAWAAN['tarif_bbm']).'/liter',
                                                'rental' => '× '.rp($t->tarif_rental ?? \App\Models\ProyekTahap::BAWAAN['tarif_rental']).'/jam',
                                                'kas' => 'transaksi detail Kas Harian',
                                            } }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                                @if ($super)<tr class="total"><td>Total biaya</td><td></td><td class="angka">{{ rp($x->total) }}</td><td></td></tr>@endif
                            </table>
                            @if ($t->catatan)<div class="redup" style="font-size: 12px; margin-top: 6px;">Catatan: {{ $t->catatan }}</div>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <script>
        (() => {
            const grid = document.getElementById('grid');
            const master = [...grid.querySelectorAll('tbody tr.m')];
            // Saringan kolom = saring-kolom.js (seragam di semua grid). ?q=… mengisi saringan kolom Tahap.
            const q = new URLSearchParams(location.search).get('q');
            if (q) window.addEventListener('DOMContentLoaded', () => {
                const x = grid.querySelector('tr.saring-kolom input[data-kolom="0"]');
                if (x) { x.value = q; x.dispatchEvent(new Event('input')); }
            });

            const buka = (tr, b) => { tr.classList.toggle('buka', b); tr.nextElementSibling.classList.toggle('buka', b); };
            master.forEach(tr => tr.addEventListener('click', e => {
                if (e.target.closest('a, button, form')) return;
                buka(tr, !tr.classList.contains('buka'));
            }));
            document.getElementById('buka-semua').addEventListener('click', () => master.forEach(m => buka(m, true)));
            document.getElementById('tutup-semua').addEventListener('click', () => master.forEach(m => buka(m, false)));
        })();
    </script>
@endsection
