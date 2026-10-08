@extends('layouts.app', ['judul' => 'Monitor Ritasi'])

@section('lebar', '1440px')

@section('isi')
    <style>
        .umur { display: inline-block; min-width: 54px; text-align: center; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 10px; }
        .umur.baru { background: rgba(76, 195, 138, .18); color: var(--sukses); }
        .umur.sedang { background: rgba(224, 165, 38, .18); color: #f0c05a; }
        .umur.lama { background: rgba(229, 72, 77, .18); color: #ff8a8f; }
        table.kas td.angka, table.kas th.angka { text-align: right; font-variant-numeric: tabular-nums; }
        .cari-monitor { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
        .cari-monitor input[type=search] { min-width: 280px; padding: 8px 10px; border-radius: 7px; }
    </style>

    <div class="ringkas">
        <div class="kartu"><span class="redup">DO belum ada di Ritasi</span><b>{{ $semua->count() }}</b></div>
        <div class="kartu"><span class="redup">Uang jalan DO tersebut</span><b>{{ rp($semua->sum('total')) }}</b></div>
        <div class="kartu"><span class="redup">Lebih dari 30 hari</span><b>{{ $jumlahUmur['lama'] }}</b></div>
        <div class="kartu"><span class="redup">Data Ritasi terakhir</span><b>{{ $ritasiTerakhir ? \Illuminate\Support\Carbon::parse($ritasiTerakhir)->translatedFormat('j M Y') : '–' }}</b></div>
    </div>

    <p class="redup" style="margin: 0 0 12px; font-size: 13px;">Daftar No DO yang sudah punya transaksi di Kas UJ tetapi nomornya belum ada di data Ritasi — truk belum bongkar, atau ritasinya belum diinput.
        Umur dihitung dari tanggal uang jalan pertama DO itu. Klik baris untuk melihat transaksinya.</p>

    <form method="GET" action="{{ route('ritasi.monitor') }}" class="cari-monitor">
        <span class="redup">Umur:</span>
        <a href="{{ route('ritasi.monitor', array_filter(['q' => $q])) }}" @class(['chip', 'aktif' => ! $umur])>Semua · {{ $semua->count() }}</a>
        <a href="{{ route('ritasi.monitor', array_filter(['umur' => '7', 'q' => $q])) }}" @class(['chip', 'aktif' => $umur === '7'])>≤ 7 hari · {{ $jumlahUmur['7'] }}</a>
        <a href="{{ route('ritasi.monitor', array_filter(['umur' => '30', 'q' => $q])) }}" @class(['chip', 'aktif' => $umur === '30'])>8–30 hari · {{ $jumlahUmur['30'] }}</a>
        <a href="{{ route('ritasi.monitor', array_filter(['umur' => 'lama', 'q' => $q])) }}" @class(['chip', 'aktif' => $umur === 'lama'])>&gt; 30 hari · {{ $jumlahUmur['lama'] }}</a>
        @if ($umur)<input type="hidden" name="umur" value="{{ $umur }}">@endif
        <input type="search" name="q" value="{{ $q }}" placeholder="Cari No DO, DT, driver, tujuan, keterangan…" style="margin-left: auto;">
        <button class="tombol polos" type="submit">Cari</button>
        @if ($q)<a href="{{ route('ritasi.monitor', array_filter(['umur' => $umur])) }}" class="redup">Hapus pencarian</a>@endif
    </form>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>No DO</th><th>UJ pertama</th><th>Umur</th><th>No Mobil</th><th>Driver</th><th>Tujuan</th><th>Kategori</th><th class="angka">Transaksi</th><th class="angka">Total UJ</th></tr></thead>
            @forelse ($daftar as $d)
                <tbody class="grup">
                    <tr class="t ada-bon">
                        <td><span class="panah">▸</span> <b>{{ $d['do'] }}</b></td>
                        <td>{{ $d['pertama']?->translatedFormat('j M Y') ?? '–' }}@if ($d['terakhir'] && $d['pertama'] && ! $d['terakhir']->isSameDay($d['pertama']))<span class="redup" style="font-size: 12px;"> s/d {{ $d['terakhir']->translatedFormat('j M') }}</span>@endif</td>
                        <td>@if ($d['umur'] !== null)<span @class(['umur', 'baru' => $d['umur'] <= 7, 'sedang' => $d['umur'] > 7 && $d['umur'] <= 30, 'lama' => $d['umur'] > 30])>{{ $d['umur'] }} hari</span>@endif</td>
                        <td>{{ implode(', ', $d['mobil']) ?: '–' }}</td>
                        <td>{{ implode(', ', $d['driver']) ?: '–' }}</td>
                        <td>{{ $d['tujuan'] ? ucwords($d['tujuan']) : '–' }}</td>
                        <td class="ringkas-gl">{{ implode(', ', $d['kategori']) }}</td>
                        <td class="angka">{{ count($d['detail']) }}</td>
                        <td class="angka"><b>{{ rp($d['total']) }}</b></td>
                    </tr>
                    <tr class="bh"><td>ID UJ</td><td>Tanggal</td><td>Status</td><td>No Mobil · Jenis</td><td>Driver</td><td colspan="2">Keterangan</td><td>Kategori</td><td class="angka">Nominal</td></tr>
                    @foreach ($d['detail'] as $x)
                        <tr class="b">
                            <td class="i">{{ $x->id_uj ?: 'baris '.$x->baris }}</td>
                            <td>{{ $x->tanggal?->translatedFormat('j M Y') }}</td>
                            <td class="redup" style="font-size: 12px;">{{ $x->status }}</td>
                            <td>{{ $x->no_mobil }}@if ($x->jenis_kendaraan) <span class="redup">· {{ $x->jenis_kendaraan }}</span>@endif</td>
                            <td class="p">{{ $x->nama }}</td>
                            <td colspan="2">{{ $x->keterangan }} <span class="redup">· DO {{ $x->no_do }}</span></td>
                            <td>{{ $x->kategori }}</td>
                            <td class="angka">{{ rp((int) $x->nominal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="9" class="redup" style="padding: 20px 14px;">{{ $q || $umur ? 'Tidak ada DO yang cocok dengan saringan ini.' : 'Semua DO di Kas UJ sudah ada di data Ritasi.' }}</td></tr></tbody>
            @endforelse
        </table>
    </div>

    @if ($bukanAngka->isNotEmpty())
        <p class="redup" style="font-size: 12px; margin-top: 10px;">Tidak ikut dimonitor: {{ $bukanAngka->count() }} isian "No DO" di Kas UJ yang bukan nomor DO
            ({{ $bukanAngka->take(12)->implode(', ') }}{{ $bukanAngka->count() > 12 ? ', …' : '' }}).</p>
    @endif

    <script>
        document.querySelectorAll('tbody.grup tr.t').forEach(tr => tr.addEventListener('click', () => tr.parentElement.classList.toggle('buka')));
    </script>
@endsection
