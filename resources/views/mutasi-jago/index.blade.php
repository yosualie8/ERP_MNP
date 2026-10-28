@extends('layouts.app', ['judul' => 'Mutasi Jago'])

@section('lebar', '1600px')

@section('isi')
    @php
        $tgl = fn ($d) => $d ? $d->translatedFormat('d-M-y') : '';
        $info = \App\Support\MutasiJago::KANTONG[$kantong];
        $label = ['cocok' => ['✓ cocok', 'hijau'], 'catatan' => ['⚠ ada catatan', 'kuning'], 'belum' => ['❗ belum diinput', 'merah'], 'tidak-di-bank' => ['❗ tidak ada di bank', 'merah'],
            'menunggu' => ['menunggu mutasi', ''], 'biaya' => ['biaya tanpa transfer', 'kuning'], 'kembali' => ['↩ transfer kembali', ''], 'bunga' => ['bunga kantong', '']];
        $n = $hasil['baris']->countBy('status');
        [$awal, $akhir] = $hasil['cakupan'];
        $rupiah = fn ($v) => $v === null ? '' : (($v < 0 ? '−' : '').number_format(abs($v), abs($v - round($v)) >= 0.005 ? 2 : 0, ',', '.'));
    @endphp
    <style>
        .ringkas-jago { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas-jago .kartu { margin: 0; padding: 10px 14px; }
        .ringkas-jago b { display: block; font-size: 19px; margin-top: 2px; }
        .ringkas-jago small { font-size: 12px; color: var(--redup); }
        .baris-isian { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .baris-isian label { display: block; font-size: 12px; color: var(--redup); margin-bottom: 3px; }
        table.kas td.angka, table.kas th.angka { white-space: nowrap; }
        table.kas .kecil { font-size: 11.5px; color: var(--redup); }
        table.kas ul.selisih { margin: 3px 0 0; padding-left: 16px; font-size: 12px; color: #f0c05a; }
        table.kas tr.st-belum td, table.kas tr.st-tidak-di-bank td { background: rgba(255, 92, 97, .08); }
        table.kas tr.st-catatan td { background: rgba(224, 165, 38, .07); }
        .label.kuning { background: rgba(224, 165, 38, .16); color: #f0c05a; }
    </style>

    <div class="kartu">
        <form method="POST" action="{{ route('mutasi-jago.unggah') }}" enctype="multipart/form-data" class="baris-isian">@csrf
            <div style="flex: 1; min-width: 280px;"><label>Unggah PDF riwayat transaksi kantong Jago (Kas MNP / Uang Jalan DT) — boleh tumpang-tindih, transaksi yang sudah ada dilewati</label>
                <input type="file" name="file" accept="application/pdf,.pdf" required style="width: 100%;"></div>
            <button class="tombol" type="submit">⬆ Unggah</button>
        </form>
        <p class="redup" style="font-size: 12px; margin: 8px 0 0;">Di aplikasi Jago: buka kantong → Riwayat transaksi → unduh PDF (tanpa filter / pencarian). File yang saldonya tidak bersambung ditolak.
            Unggah pagi & malam, rentang <b>kemarin s/d hari ini</b> supaya tidak ada yang terlewat.</p>
    </div>

    <div class="kartu" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        @foreach ($kantongBoleh as $k)
            <a href="{{ route('mutasi-jago.index', ['kantong' => $k, 'dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString()]) }}" @class(['chip', 'aktif' => $k === $kantong]) style="margin: 0;">
                {{ \App\Support\MutasiJago::KANTONG[$k]['nama'] }} ↔ {{ \App\Support\MutasiJago::KANTONG[$k]['buku'] }}</a>
        @endforeach
        <form method="GET" style="display: flex; gap: 6px; align-items: center; margin-left: auto; flex-wrap: wrap;">
            <input type="hidden" name="kantong" value="{{ $kantong }}">
            <input type="date" name="dari" value="{{ $dari->toDateString() }}" min="{{ \App\Support\MutasiJago::MULAI }}">
            <span class="redup">s/d</span>
            <input type="date" name="sampai" value="{{ $sampai->toDateString() }}" min="{{ \App\Support\MutasiJago::MULAI }}">
            <button class="tombol polos" type="submit" style="padding: 6px 12px;">Tampilkan</button>
        </form>
    </div>

    @php($v = $hasil['validitas'])
    {{-- Validitas dulu: pengecekan hanya untuk mutasi yang saldonya bersambung (owner 28 Okt 2026). --}}
    @if (! $v['urut'])
        <div class="pesan">Belum ada mutasi kantong {{ $info['nama'] }} sejak {{ \Illuminate\Support\Carbon::parse(\App\Support\MutasiJago::MULAI)->translatedFormat('j M Y') }}. Unggah PDF riwayat transaksinya.</div>
    @elseif ($v['valid'])
        <div class="pesan sukses">✓ <b>Mutasi valid</b> — saldo bersambung dari {{ $tgl($v['awal']) }} {{ $v['awal']->format('H:i') }} s/d {{ $tgl($v['akhir']) }} {{ $v['akhir']->format('H:i') }}
            ({{ count($v['urut']) }} transaksi, saldo akhir {{ $rupiah(end($v['urut'])->saldo) }}). Pengecekan ke {{ $info['buku'] }} dijalankan.</div>
    @else
        <div class="pesan galat">✗ <b>Mutasi belum valid — saldo tidak balance.</b> Pengecekan hanya sampai <b>{{ $tgl($v['valid_sampai']) }} {{ $v['valid_sampai']->format('H:i') }}</b>;
            {{ $hasil['belum_dicek'] }} transaksi bank sesudahnya di rentang ini belum dicek.
            <ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($v['putus'] as $p)<li>{{ \App\Support\MutasiJago::pesanPutus($p) }}</li>@endforeach</ul></div>
    @endif

    <p class="redup" style="font-size: 13px; margin: 0 0 10px;">Kantong <b>{{ $info['nama'] }} {{ $info['nomor'] }}</b> dicocokkan dengan <b>{{ $info['buku'] }}</b> (input admin tidak diubah), mulai
        {{ \Illuminate\Support\Carbon::parse(\App\Support\MutasiJago::MULAI)->translatedFormat('j M Y') }}.
        Hari terakhir yang tercakup mutasi masih <i>menunggu</i> (transaksi sore / malam mungkin belum ada di PDF) — baru dinilai sesudah unggahan berikutnya.</p>

    <div class="ringkas-jago">
        <div class="kartu"><small>Transaksi bank</small><b>{{ $hasil['baris']->where('jenis', 'bank')->count() }}</b></div>
        <div class="kartu"><small>Cocok</small><b style="color: var(--sukses);">{{ $n['cocok'] ?? 0 }}</b></div>
        <div class="kartu"><small>Cocok, ada catatan</small><b style="color: #f0c05a;">{{ $n['catatan'] ?? 0 }}</b><small>tanggal / rekening / biaya / DO beda</small></div>
        <div class="kartu"><small>Di bank, belum diinput</small><b style="color: var(--merah);">{{ $n['belum'] ?? 0 }}</b></div>
        <div class="kartu"><small>Di {{ $info['buku'] }}, tidak ada di bank</small><b style="color: var(--merah);">{{ $n['tidak-di-bank'] ?? 0 }}</b><small>menunggu mutasi {{ $n['menunggu'] ?? 0 }}</small></div>
    </div>

    <h3 style="margin: 4px 0 8px;">Per hari</h3>
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Tanggal</th><th class="angka">Transaksi bank</th><th class="angka">Cocok</th><th class="angka">Ada catatan</th><th class="angka">Belum diinput</th><th class="angka">Tidak di bank</th>
                <th class="angka">Saldo bank akhir</th>@if ($kantong === 'kas')<th class="angka">Saldo Kas Harian</th>@endif<th>Status</th></tr></thead>
            <tbody>
                @forelse ($hasil['hari'] as $h)
                    <tr class="t"><td data-n="{{ $h['tanggal']->toDateString() }}">{{ $h['tanggal']->translatedFormat('D, d-M-y') }}</td><td class="angka">{{ $h['bank'] }}</td>
                        <td class="angka">{{ $h['n']['cocok'] ?? 0 }}</td><td class="angka">{{ $h['n']['catatan'] ?? '' }}</td><td class="angka">{{ $h['n']['belum'] ?? '' }}</td><td class="angka">{{ $h['n']['tidak-di-bank'] ?? '' }}</td>
                        <td class="angka">{{ $rupiah($h['saldo_bank']) }}</td>@if ($kantong === 'kas')<td class="angka">{{ $rupiah($h['saldo_buku']) }}</td>@endif
                        <td>@if ($h['cocok100'])<span class="label hijau">Cocok 100%</span>@elseif (! $h['penuh'])<span class="label">menunggu mutasi berikutnya</span>@elseif ($h['bank'])<span class="label kuning">perlu dicek</span>@else<span class="label">tidak ada mutasi</span>@endif</td></tr>
                @empty
                    <tr><td colspan="9" class="redup" style="padding: 16px;">Belum ada mutasi untuk rentang ini. Unggah PDF riwayat transaksi kantong {{ $info['nama'] }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h3 style="margin: 14px 0 8px;">Rincian</h3>
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Waktu bank</th><th>Sumber / tujuan (bank)</th><th>Catatan bank</th><th class="angka">Jumlah</th>@if ($kantong === 'uj')<th class="angka">Biaya</th>@endif<th class="angka">Saldo</th>
                <th>Status</th><th>{{ $info['buku'] }}</th><th>Keterangan</th></tr></thead>
            <tbody>
                @forelse ($hasil['baris'] as $r)
                    @php($m = $r['m'])
                    @php($b = $r['b'])
                    <tr class="t st-{{ $r['status'] }}">
                        <td data-n="{{ $r['waktu']->format('YmdHi') }}">@if ($m){{ $tgl($m->waktu) }} <b>{{ $m->waktu->format('H:i') }}</b>@else<span class="redup">{{ $tgl($r['tanggal']) }} (buku)</span>@endif</td>
                        <td>@if ($m){{ $m->nama }}<div class="kecil">{{ trim($m->bank.' '.$m->rekening) }} · {{ $m->jenis }}</div>@endif</td>
                        <td style="font-size: 12.5px; max-width: 320px;">{{ $m?->catatan }}</td>
                        <td class="angka" data-n="{{ $m ? (int) $m->jumlah : (int) $b['nominal'] }}">{{ $rupiah($m ? $m->jumlah : $b['nominal']) }}</td>
                        @if ($kantong === 'uj')<td class="angka">{{ $r['fee'] ? $rupiah($r['fee']->jumlah) : '' }}</td>@endif
                        <td class="angka">{{ $rupiah($r['saldo']) }}</td>
                        <td><span class="label {{ $label[$r['status']][1] }}">{{ $label[$r['status']][0] }}</span></td>
                        <td>@if ($b)
                                @if ($b['url'])<a href="{{ $b['url'] }}">{{ $b['id'] }}</a>@else{{ $b['id'] }}@endif
                                <div class="kecil">{{ $tgl($b['tanggal']) }} · {{ $b['nama'] }}@if ($b['rek']) · {{ trim($b['bank'].' '.$b['rek']) }}@endif · {{ $rupiah($b['nominal']) }}</div>
                                <div class="kecil">{{ mb_strimwidth($b['ket'], 0, 60, '…') }}@if ($b['n'] > 1) ({{ $b['n'] }} detail)@endif</div>
                            @endif</td>
                        <td style="font-size: 12.5px;">
                            @if ($r['catatan'])<ul class="selisih">@foreach ($r['catatan'] as $c)<li>{{ $c }}</li>@endforeach</ul>@endif
                            @if ($r['status'] === 'belum')Ada di bank, belum ada di {{ $info['buku'] }}.@endif
                            @if ($r['status'] === 'tidak-di-bank')Tidak ada transfer bank yang cocok (nominal & tanggal ±3 hari).@endif
                            @if ($r['status'] === 'biaya')Biaya transfer tanpa transfer di menit yang sama.@endif
                            @if ($r['status'] === 'bunga')Bunga / pajak bunga kantong — tidak dicatat di Kas UJ.@endif
                            @if ($r['status'] === 'kembali')Keluar lalu masuk kembali dengan jumlah & penerima sama — saling meniadakan.@endif
                            @if ($r['saran'])<div class="kecil" style="color: #93c5fd;">{{ $r['saran'] }}</div>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="redup" style="padding: 16px;">Tidak ada transaksi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h3 style="margin: 14px 0 8px;">Unggahan terakhir</h3>
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Diunggah</th><th>Oleh</th><th>Kantong</th><th>Periode</th><th class="angka">Baris</th><th class="angka">Baru</th><th>Peringatan</th><th data-tanpa-saring>PDF</th></tr></thead>
            <tbody>
                @forelse ($unggahan as $u)
                    <tr class="t"><td>{{ $u->created_at->translatedFormat('d-M-y H:i') }}</td><td>{{ $u->user?->name }}</td><td>{{ \App\Support\MutasiJago::KANTONG[$u->kantong]['nama'] ?? $u->kantong }}</td>
                        <td>{{ $tgl($u->dari) }} – {{ $tgl($u->sampai) }}</td><td class="angka">{{ $u->jumlah_baris }}</td><td class="angka">{{ $u->baris_baru }}</td>
                        <td style="font-size: 12px; color: #f0c05a;">{!! nl2br(e($u->peringatan)) !!}</td>
                        <td><a href="{{ route('mutasi-jago.pdf', $u) }}" target="_blank">lihat</a></td></tr>
                @empty
                    <tr><td colspan="8" class="redup" style="padding: 16px;">Belum ada unggahan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
