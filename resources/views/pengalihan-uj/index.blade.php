@extends('layouts.app', ['judul' => 'Hutang Supir'])

@section('lebar', '1440px')

@section('isi')
    @php
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-y') : '';
        $adaSaldo = $saldo->filter(fn ($s) => $s['saldo'] > 0.5);
        $adaHutang = $hutang->filter(fn ($h) => $h['total'] > 0.5);
        $tglSettle = \Illuminate\Support\Carbon::parse(\App\Support\SaldoSopir::SETTLE)->translatedFormat('j M Y');
        $warnaUmur = ['merah' => 'merah', 'kuning' => 'kuning', 'baru' => 'hijau', 'kasbon' => '', 'habis' => ''];
        // Proyek untuk pilihan "ditanggung proyek": yang berjalan dulu
        $pilihTahap = $tahap->reject(fn ($t) => str_contains($t->nama, '(biaya'))->sortBy(fn ($t) => [in_array($t->status, ['progress', 'belum'], true) ? 0 : 1, $t->nama]);
    @endphp
    <style>
        .tab-alih { display: flex; gap: 6px; margin-bottom: 12px; }
        .tab-alih a { padding: 8px 16px; border: 1px solid var(--garis); border-radius: 8px 8px 0 0; text-decoration: none; color: var(--teks); background: var(--kartu); }
        .tab-alih a.aktif { border-color: var(--aksen); color: var(--aksen-terang); font-weight: 700; }
        .ringkas-alih { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas-alih .kartu { margin: 0; padding: 10px 14px; }
        .ringkas-alih b { display: block; font-size: 19px; margin-top: 2px; }
        .ringkas-alih small { font-size: 12px; color: var(--redup); }
        .baris-isian { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .baris-isian label { display: block; font-size: 12px; color: var(--redup); margin-bottom: 3px; }
        table.kas td.angka, table.kas th.angka { white-space: nowrap; }
        tr.pilih td { background: var(--aksen-muda); }
    </style>

    <div class="tab-alih">
        <a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo']) }}" @class(['aktif' => $tab === 'saldo'])>💰 Utang sopir @if ($adaHutang->count())· {{ $adaHutang->count() }} sopir · {{ rp((int) $adaHutang->sum('total')) }}@endif</a>
        <a href="{{ route('pengalihan-uj.index', ['tab' => 'pilih']) }}" @class(['aktif' => $tab === 'pilih'])>☑ Pilih UJ → jadikan saldo</a>
    </div>

    @if ($tab === 'saldo')
        @php
            // Ringkasan sederhana (owner 30 Okt 2026): hanya nama sopir, mobil yang dibawa sekarang, saldo hutang.
            $semua = request('tampil') === 'semua';
            $daftar = $hutang->filter(fn ($h) => $semua || $h['total'] > 0.5)->sortByDesc('total');
            $mobilKini = fn ($h) => \App\Support\DaftarSopir::cari($h['sopir'])['mobil'] ?? ($h['belum']->last()?->mobil ?: ($h['saldo']['mobil'][0] ?? ''));
        @endphp
        <p class="redup" style="font-size: 13px; margin: 0 0 12px;">Hutang sopir = uang jalan yang sudah diberikan tetapi ritnya belum bongkar, ditambah sisa uang PT lain yang dipegang sopir.
            Saldo awal = settle per {{ $tglSettle }}. Klik nama sopir untuk melihat bukunya.</p>

        <form method="GET" action="{{ route('pengalihan-uj.index') }}" class="baris-isian" style="margin-bottom: 12px;">
            <input type="hidden" name="tab" value="saldo">
            <div><label>Buka buku sopir lain (mis. untuk mencatat kasbon)</label>
                <input name="sopir" list="daftar-sopir-buka" value="{{ $dipilih['sopir'] ?? '' }}" placeholder="ketik nama sopir" style="width: 260px;" autocomplete="off"></div>
            <button class="tombol" type="submit">Buka</button>
            <datalist id="daftar-sopir-buka">@foreach ($namaSopir ?? [] as $n)<option value="{{ $n }}">@endforeach</datalist>
            <a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo', 'tampil' => $semua ? null : 'semua']) }}" class="chip" style="margin: 0 0 0 auto;">{{ $semua ? 'Hanya yang masih berhutang' : 'Tampilkan juga yang lunas' }}</a>
        </form>

        <div class="kartu gulir" style="padding: 0; max-width: 640px;">
            <table class="kas">
                <thead><tr><th>Nama sopir</th><th>Mobil</th><th class="angka">Saldo hutang</th></tr></thead>
                <tbody>
                    @forelse ($daftar as $h)
                        <tr @class(['t', 'pilih' => $dipilih && $dipilih['sopir'] === $h['sopir']])>
                            <td><a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo', 'tampil' => $semua ? 'semua' : null, 'sopir' => $h['sopir']]) }}#rinci"><b>{{ $h['sopir'] }}</b></a></td>
                            <td>{{ $mobilKini($h) }}</td>
                            <td class="angka" data-n="{{ (int) $h['total'] }}"><b>{{ rp((int) $h['total']) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="redup" style="padding: 18px 14px;">Tidak ada sopir yang berhutang.</td></tr>
                    @endforelse
                </tbody>
                <tfoot><tr><th>Total</th><th>{{ $daftar->count() }} sopir</th><th class="angka">{{ rp((int) $daftar->sum('total')) }}</th></tr></tfoot>
            </table>
        </div>

        @if ($dipilih)
            @php
                $s = $dipilih;
                $kasUjDo ??= collect();
                // Buku utang: ➕ nambah (UJ jadi saldo, kasbon) / ➖ potong (DO lama bongkar, pemakaian), urut tanggal, dengan sisa berjalan.
                $buku = $s['uj']->map(fn ($x) => ['tgl' => $x->p->tanggal, 'jenis' => 'UJ tidak bongkar → saldo', 'id' => $x->p->id_uj, 'do' => $x->no_do, 'ket' => $x->u?->keterangan.($x->p->keterangan ? ' · '.$x->p->keterangan : ''),
                        'proyek' => 'dari '.($x->p->dari?->nama ?? '?'), 'masuk' => $x->p->nominal, 'keluar' => $x->bongkar ? $x->p->nominal : null,
                        'status' => $x->bongkar ? 'DO lama bongkar '.$tgl($x->bongkar->tanggal).' di '.($tahap[$x->tahap_bongkar]->nama ?? $x->bongkar->tahap).' → biaya proyek itu' : 'DO lama belum bongkar', 'p' => $x->p])
                    ->concat($s['kasbon_rows']->map(fn ($p) => ['tgl' => $p->tanggal, 'jenis' => 'Kasbon (Kas Harian)', 'id' => 'NO ID '.$p->kas_no_id, 'do' => null, 'ket' => $p->keterangan,
                        'proyek' => 'bukan biaya (piutang)', 'masuk' => $p->nominal, 'keluar' => null, 'status' => null, 'p' => $p]))
                    ->concat($s['settle_rows']->map(fn ($p) => ['tgl' => $p->tanggal, 'jenis' => 'Penyesuaian settle', 'id' => null, 'do' => null, 'ket' => $p->keterangan,
                        'proyek' => '—', 'masuk' => $p->nominal > 0 ? $p->nominal : null, 'keluar' => $p->nominal < 0 ? -$p->nominal : null, 'status' => null, 'p' => $p]))
                    ->concat($s['pakai_rows']->map(function ($p) use ($tahap, $kasUjDo) {
                        $sambung = $p->peruntukan === 'uj' ? ($kasUjDo[\App\Support\SaldoSopir::kunciDo($p->no_do)] ?? collect()) : collect();

                        return ['tgl' => $p->tanggal, 'jenis' => \App\Models\UjPengalihan::PERUNTUKAN[$p->peruntukan] ?? 'Dipakai',
                            'id' => $p->uj_pengajuan_detail_id ? \Illuminate\Support\Str::before((string) $p->keterangan, ' — ') : ($p->kas_no_id ? 'NO ID '.$p->kas_no_id : null),
                            'do' => $p->no_do, 'ket' => $p->uj_pengajuan_detail_id ? \Illuminate\Support\Str::after((string) $p->keterangan, ' — ') : $p->keterangan,
                            'proyek' => in_array($p->peruntukan, \App\Models\UjPengalihan::BUKAN_BIAYA, true) ? 'bukan biaya' : (($t = \App\Support\SaldoSopir::tahapPakai($p)) ? $tahap[$t]->nama ?? '?' : 'belum diketahui'),
                            'masuk' => null, 'keluar' => $p->nominal,
                            'status' => $sambung->isNotEmpty() ? 'Tersambung: '.$sambung->map(fn ($u) => $u->id_uj.' '.rp((int) $u->nominal))->implode(', ').' (Kas UJ) · total DO '.rp((int) ($sambung->sum('nominal') + $p->nominal)) : null,
                            'p' => $p];
                    }))
                    ->sortBy(fn ($b) => $b['tgl']->format('Ymd').sprintf('%08d', $b['p']->id))->values();
                $berjalan = 0;
                $buku = $buku->map(function ($b) use (&$berjalan) {
                    $berjalan += ($b['masuk'] ?? 0) - ($b['keluar'] ?? 0);

                    return [...$b, 'sisa' => $berjalan];
                });
                $asalTertua = $s['uj']->first()?->p->dari_tahap_id;
                $bisaPotong = $s['saldo'] > 0.5;
            @endphp
            <style>
                .arah-utang { display: flex; gap: 8px; padding: 12px 14px 4px; border-top: 1px solid var(--garis); align-items: center; flex-wrap: wrap; }
                .arah-utang label { display: flex; gap: 6px; align-items: center; padding: 6px 12px; border: 1px solid var(--garis); border-radius: 8px; cursor: pointer; }
                .arah-utang label:has(input:checked) { border-color: var(--aksen); background: var(--aksen-muda); font-weight: 700; }
                .arah-utang label.mati { opacity: .5; cursor: not-allowed; }
                td.nambah { color: var(--sukses); } td.potong { color: var(--merah); }
                /* Grid isian seperti Input UJ: satu baris = satu transaksi. */
                .grid-utang { padding: 6px 14px 14px; }
                .grid-utang .petunjuk { font-size: 12px; color: var(--redup); margin: 0 0 6px; }
                .grid-utang table { width: 100%; border-collapse: collapse; }
                .grid-utang th { font-size: 12px; color: var(--redup); text-align: left; padding: 4px; font-weight: 600; white-space: nowrap; }
                .grid-utang th.angka { text-align: right; }
                .grid-utang td { padding: 4px; vertical-align: top; border-bottom: 0; }
                .grid-utang input[type=text], .grid-utang input[type=date], .grid-utang select {
                    width: 100%; box-sizing: border-box; padding: 7px 8px; border: 1px solid var(--isian-garis, var(--garis)); border-radius: 7px; font-size: 13.5px; background: var(--isian); color: var(--teks); }
                .grid-utang input:focus, .grid-utang select:focus { outline: none; border-color: var(--aksen); box-shadow: 0 0 0 2px var(--aksen); background: var(--isian-fokus, var(--isian)); }
                .grid-utang input.angka-input { text-align: right; font-variant-numeric: tabular-nums; }
                .grid-utang input[readonly] { background: transparent; border-color: transparent; color: var(--redup); }
                .grid-utang :disabled { opacity: .3; }
                .grid-utang .hapus { background: none; border: 0; color: var(--merah); cursor: pointer; font-size: 18px; line-height: 1; padding: 8px 6px; }
                .grid-utang .kaki { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-top: 8px; }
                .grid-utang .kaki .jumlah { margin-left: auto; font-size: 14px; }
                .grid-utang .kaki .jumlah b { font-variant-numeric: tabular-nums; }
                .grid-utang .minus { color: var(--merah); }
            </style>
            <div class="kartu" id="rinci" style="padding: 0;">
                <div style="padding: 12px 14px 8px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <b style="font-size: 17px; color: var(--aksen-terang);">Buku utang {{ $s['sopir'] }}</b>
                    <span class="redup" style="font-size: 13px;">Truk: {{ implode(', ', $s['mobil']) ?: '—' }}</span>
                    <span>Saldo akhir <b>{{ rp((int) $riwayat['akhir']) }}</b></span>
                    <a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo']) }}" class="redup" style="margin-left: auto; font-size: 13px;">Tutup ✕</a>
                </div>
                @php
                    // Buku sederhana (owner 30 Okt 2026): hanya Tanggal, Keterangan, Debit (utang bertambah), Kredit (utang berkurang), Saldo.
                    // Owner 30 Okt 2026: sebut juga galian → tempat buangnya.
                    $doTeks = fn ($b) => ($b['do'] ? ' DO '.$b['do'] : '').(! empty($b['rute']) ? ' · '.$b['rute'] : '');
                    $ketBuku = fn ($b) => match ($b['jenis']) {
                        'Uang jalan diberikan' => 'Uang jalan'.$doTeks($b),
                        'Rit bongkar' => 'Bongkar'.$doTeks($b),
                        'Penyesuaian settle' => 'Penyesuaian',
                        default => $b['jenis'].$doTeks($b),
                    };
                @endphp
                @php
                    // Owner 30 Okt 2026: tanggal terbaru di atas, hanya 20 transaksi terakhir.
                    $bukuTampil = $riwayat['baris']->reverse()->take(20);
                @endphp
                <div class="gulir">
                    <table class="kas" style="min-width: 0;">
                        <thead><tr><th>Tanggal</th><th>Keterangan</th><th class="angka">Debit</th><th class="angka">Kredit</th><th class="angka">Saldo</th></tr></thead>
                        <tbody>
                            @foreach ($bukuTampil as $b)
                                <tr class="t"><td data-n="{{ $b['tgl']->toDateString() }}">{{ $tgl($b['tgl']) }}</td><td>{{ $ketBuku($b) }}</td>
                                    <td class="angka">{{ $b['masuk'] ? rp((int) $b['masuk']) : '' }}</td><td class="angka">{{ $b['keluar'] ? rp((int) $b['keluar']) : '' }}</td>
                                    <td class="angka" data-n="{{ (int) $b['sisa'] }}"><b>{{ rp((int) $b['sisa']) }}</b></td></tr>
                            @endforeach
                            @if ($riwayat['baris']->count() <= 20)
                                <tr class="t"><td>{{ $tgl(\App\Support\SaldoSopir::SETTLE) }}</td><td>Saldo awal</td><td></td><td></td><td class="angka"><b>{{ rp((int) $riwayat['awal']) }}</b></td></tr>
                            @else
                                <tr><td colspan="5" class="redup" style="font-size: 12px;">Menampilkan 20 transaksi terakhir dari {{ $riwayat['baris']->count() }}.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>

                <details style="border-top: 1px solid var(--garis);">
                    <summary style="padding: 10px 14px; cursor: pointer; font-size: 13px;" class="redup">Rincian untuk admin — UJ belum bongkar {{ rp((int) $belum->sum('rp')) }} · saldo di luar DO {{ rp((int) $s['saldo']) }}</summary>
                <div style="padding: 12px 14px 6px; border-top: 1px solid var(--garis);"><b>UJ belum bongkar · {{ $belum->count() }} baris · {{ rp((int) $belum->sum('rp')) }}</b>
                    <span class="redup" style="font-size: 12px;">— berkurang otomatis saat ritnya bongkar (DO tercatat di Ritasi). Truk pindah sebelum bongkar → <a href="{{ route('pengalihan-uj.index', ['tab' => 'pilih', 'cari' => $s['sopir']]) }}">jadikan saldo</a>.</span></div>
                <div class="gulir">
                    <table class="kas">
                        <thead><tr><th>Tanggal UJ</th><th>ID UJ</th><th>No DO</th><th>DT</th><th>Keterangan</th><th>Proyek</th><th class="angka">Nominal</th><th>Umur</th></tr></thead>
                        <tbody>
                            @forelse ($belum as $u)
                                <tr class="t"><td data-n="{{ $u->tgl?->toDateString() }}">{{ $tgl($u->tgl) }}</td><td>{{ $u->id_uj }}</td><td>{{ $u->do }}</td><td>{{ $u->mobil }}</td>
                                    <td style="font-size: 12px;">{{ $u->ket }}</td><td>{{ $u->tahap }}</td><td class="angka">{{ rp((int) $u->rp) }}</td>
                                    <td data-n="{{ $u->umur ?? -1 }}"><span class="label {{ ['merah' => 'merah', 'lewat' => 'kuning', 'wajar' => 'hijau'][$u->status] ?? '' }}">{{ $u->umur }} hari</span></td></tr>
                            @empty
                                <tr><td colspan="8" class="redup" style="padding: 12px 14px;">Tidak ada uang jalan {{ $s['sopir'] }} yang belum bongkar.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div style="padding: 12px 14px 6px; border-top: 1px solid var(--garis);"><b>Saldo di luar DO · {{ rp((int) $s['saldo']) }}</b>
                    <span class="redup" style="font-size: 12px;">— UJ yang tidak bongkar dijadikan saldo, kasbon, penyesuaian settle, dan potongannya.</span></div>
                <div class="gulir">
                    <table class="kas">
                        <thead><tr><th>Tanggal</th><th>Jenis</th><th>ID UJ / Pengajuan / NO ID</th><th>No DO</th><th>Keterangan</th><th>Proyek</th><th class="angka">➕ Nambah utang</th><th class="angka">➖ Potong utang</th>
                            <th class="angka">Sisa</th><th>Status</th><th data-tanpa-saring></th></tr></thead>
                        <tbody>
                            @forelse ($buku as $b)
                                <tr class="t"><td data-n="{{ $b['tgl']->toDateString() }}">{{ $tgl($b['tgl']) }}</td><td>{{ $b['jenis'] }}</td><td>{{ $b['id'] }}</td><td>{{ $b['do'] }}</td>
                                    <td style="font-size: 12px;">{{ $b['ket'] }}</td><td>{{ $b['proyek'] }}</td>
                                    <td class="angka nambah">{{ $b['masuk'] ? rp((int) $b['masuk']) : '' }}</td><td class="angka potong">{{ $b['keluar'] ? rp((int) $b['keluar']) : '' }}</td>
                                    <td class="angka" data-n="{{ (int) $b['sisa'] }}"><b>{{ rp((int) $b['sisa']) }}</b></td>
                                    <td style="font-size: 12px;">{{ $b['status'] }}</td>
                                    <td>@unless ($b['p']->uj_pengajuan_detail_id)<form method="POST" action="{{ route('pengalihan-uj.hapus', $b['p']) }}" onsubmit="return confirm('Hapus catatan ini? Sisa utang dihitung ulang.')">@csrf @method('DELETE')<button class="tombol polos" style="padding: 2px 8px; font-size: 12px;">Hapus</button></form>@endunless</td></tr>
                            @empty
                                <tr><td colspan="11" class="redup" style="padding: 14px;">Belum ada catatan untuk {{ $s['sopir'] }}. Catat kasbonnya di bawah (➕ Nambah utang).</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                </details>

                <div class="arah-utang">
                    <span class="redup" style="font-size: 13px;">Transaksi baru:</span>
                    <label><input type="radio" name="arah-utang" value="nambah" @checked(! $bisaPotong || old('arah') === 'nambah')> ➕ Nambah utang</label>
                    <label @class(['mati' => ! $bisaPotong]) @unless ($bisaPotong) title="Belum ada utang untuk dipotong" @endunless><input type="radio" name="arah-utang" value="potong" @checked($bisaPotong && old('arah') !== 'nambah') @disabled(! $bisaPotong)> ➖ Potong utang</label>
                </div>

                {{-- ➕ Nambah utang: kasbon dari Kas Harian (satu baris = satu bon). --}}
                <form method="POST" action="{{ route('pengalihan-uj.kasbon') }}" class="grid-utang" id="form-nambah" data-arah="nambah">@csrf
                    <input type="hidden" name="sopir" value="{{ $s['sopir'] }}"><input type="hidden" name="arah" value="nambah">
                    <p class="petunjuk">Kasbon keluar dari <b>Kas Harian</b> (Kode GL Piutang Karyawan): pilih bonnya, isi bagian untuk {{ $s['sopir'] }}. ★ = bon yang menyebut nama {{ $s['sopir'] }}.
                        Satu bon boleh dibagi ke beberapa sopir (mis. Rp2.000.000 untuk 4 sopir @ Rp500.000). UJ yang tidak bongkar → <a href="{{ route('pengalihan-uj.index', ['tab' => 'pilih', 'cari' => $s['sopir']]) }}">tab Pilih UJ → jadikan saldo</a>.</p>
                    <div class="gulir">
                        <table style="min-width: 900px;">
                            <thead><tr><th>Bon kasbon di Kas Harian</th><th class="angka" style="width: 130px;">Sisa bon</th><th class="angka" style="width: 140px;">Nominal</th><th style="width: 260px;">Catatan</th><th style="width: 30px;"></th></tr></thead>
                            <tbody data-baris></tbody>
                        </table>
                    </div>
                    <div class="kaki">
                        <button type="button" class="tombol polos" data-tambah>+ Tambah baris</button>
                        <span class="jumlah">Total kasbon <b data-total>0</b> · sisa utang sesudahnya <b data-sesudah>{{ number_format((int) $s['saldo'], 0, ',', '.') }}</b></span>
                        <button class="tombol" type="submit">Simpan kasbon</button>
                    </div>
                </form>

                {{-- ➖ Potong utang (satu baris = satu potongan). --}}
                @if ($bisaPotong)
                    <form method="POST" action="{{ route('pengalihan-uj.pakai') }}" class="grid-utang" id="form-potong" data-arah="potong">@csrf
                        <input type="hidden" name="sopir" value="{{ $s['sopir'] }}"><input type="hidden" name="arah" value="potong">
                        <p class="petunjuk">Uang jalan dipotong <b>sebagian</b>? Catat potongannya di sini, lalu transfer sisanya lewat <b>Input UJ</b> dengan No DO yang sama — keduanya otomatis tersambung lewat No DO.
                            Kolom yang tidak berlaku untuk jenis potongan otomatis nonaktif.</p>
                        <div class="gulir">
                            <table style="min-width: 1150px;">
                                <thead><tr><th style="width: 185px;">Dipotong untuk</th><th style="width: 135px;">Tanggal</th><th class="angka" style="width: 115px;">Nominal</th><th style="width: 85px;">No DO</th>
                                    <th style="width: 80px;">No Mobil</th><th style="width: 190px;">Proyek</th><th style="width: 100px;">NO ID mutasi</th><th>Keterangan</th>
                                    <th style="width: 45px;" title="Tetap simpan walau DO di Kas UJ tercatat untuk DT lain">Tetap</th><th style="width: 30px;"></th></tr></thead>
                                <tbody data-baris></tbody>
                            </table>
                        </div>
                        <div class="kaki">
                            <button type="button" class="tombol polos" data-tambah>+ Tambah baris</button>
                            <span class="jumlah">Total potongan <b data-total>0</b> · sisa utang sesudahnya <b data-sesudah>{{ number_format((int) $s['saldo'], 0, ',', '.') }}</b></span>
                            <button class="tombol" type="submit">Simpan potongan</button>
                        </div>
                    </form>
                @endif

                <form method="POST" action="{{ route('pengalihan-uj.sopir') }}" class="baris-isian" style="padding: 0 14px 14px;">@csrf
                    <input type="hidden" name="lama" value="{{ $s['sopir'] }}">
                    <div><label>Ganti / gabungkan nama sopir (ejaan lain di Kas UJ)</label><input name="baru" value="{{ $s['sopir'] }}" list="daftar-sopir" style="width: 220px;"></div>
                    <button class="tombol polos" type="submit" onclick="return confirm('Pindahkan semua catatan utang {{ $s['sopir'] }} ke nama ini?')">Simpan nama</button>
                    <datalist id="daftar-sopir">@foreach ($saldo->keys() as $n)<option value="{{ $n }}">@endforeach</datalist>
                </form>
            </div>
            @php
                // (@json memecah isinya di setiap koma — data disiapkan dulu di sini.)
                $jsBon = collect($bonKasbon ?? [])->map(fn ($b) => ['no_id' => $b->no_id, 'sisa' => (int) $b->sisa,
                    'label' => (str_contains(mb_strtolower($b->keterangan.' '.$b->nama_tujuan), mb_strtolower($s['sopir'])) ? '★ ' : '').$tgl($b->tanggal).' · '.rp((int) $b->nominal)
                        .($b->tertaut > 0.5 ? ' (sisa '.rp((int) $b->sisa).')' : '').' · '.mb_strimwidth($b->keterangan, 0, 70, '…').' · NO ID '.$b->no_id])->values();
                $jsTahap = $pilihTahap->map(fn ($t) => ['id' => $t->id, 'nama' => $t->nama])->values();
                $jsLama = ['arah' => old('arah'), 'baris' => array_values(old('baris', []))];
                $jsMobil = $s['mobil'][0] ?? '';
            @endphp
            <script>
                (() => {
                    const BON = @json($jsBon);
                    const PERUNTUKAN = @json(\App\Models\UjPengalihan::PERUNTUKAN);
                    const BUKAN_BIAYA = @json(\App\Models\UjPengalihan::BUKAN_BIAYA);
                    const TAHAP = @json($jsTahap);
                    const MOBIL = @json($jsMobil);
                    const ASAL = @json($asalTertua);
                    const SISA_UTANG = @json((int) $s['saldo']);
                    const HARI_INI = @json(now()->toDateString());
                    const SOPIR = @json($s['sopir']);
                    const LAMA = @json($jsLama);

                    const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);
                    const angka = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0;
                    const esc = v => String(v ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
                    const rapikanRupiah = el => {
                        const digitSebelumKursor = el.value.slice(0, el.selectionStart ?? el.value.length).replace(/\D/g, '').length;
                        const digit = el.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
                        el.value = digit ? fmt(+digit) : '';
                        if (document.activeElement === el) {
                            let pos = 0, hitung = 0;
                            while (pos < el.value.length && hitung < digitSebelumKursor) { if (/\d/.test(el.value[pos])) hitung++; pos++; }
                            el.setSelectionRange(pos, pos);
                        }
                    };
                    const opsi = (daftar, pilih, kosong) => (kosong !== undefined ? `<option value="">${esc(kosong)}</option>` : '')
                        + daftar.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(pilih ?? '') ? 'selected' : ''}>${esc(l)}</option>`).join('');

                    const BARIS = {
                        nambah: (b = {}) => `<td><select data-f="kas_no_id">${opsi(BON.map(x => [x.no_id, x.label]), b.kas_no_id, '— pilih bon —')}</select></td>
                            <td><input type="text" class="angka-input" data-sisa readonly tabindex="-1"></td>
                            <td><input type="text" class="angka-input rupiah" data-f="nominal" inputmode="numeric" autocomplete="off" value="${esc(b.nominal)}"></td>
                            <td><input type="text" data-f="keterangan" autocomplete="off" value="${esc(b.keterangan)}" placeholder="mis. bagian ${esc(SOPIR)} dari kasbon 4 sopir"></td>
                            <td><button type="button" class="hapus" title="Hapus baris">×</button></td>`,
                        potong: (b = {}) => `<td><select data-f="peruntukan">${opsi(Object.entries(PERUNTUKAN), b.peruntukan || 'uj')}</select></td>
                            <td><input type="date" data-f="tanggal" value="${esc(b.tanggal || HARI_INI)}"></td>
                            <td><input type="text" class="angka-input rupiah" data-f="nominal" inputmode="numeric" autocomplete="off" value="${esc(b.nominal)}"></td>
                            <td><input type="text" data-f="no_do" inputmode="numeric" autocomplete="off" value="${esc(b.no_do)}"></td>
                            <td><input type="text" data-f="no_mobil" autocomplete="off" value="${esc(b.no_mobil ?? MOBIL)}"></td>
                            <td><select data-f="tahap">${opsi(TAHAP.map(t => [t.id, t.nama]), b.tahap, '— pilih proyek —')}</select></td>
                            <td><input type="text" data-f="kas_no_id" autocomplete="off" value="${esc(b.kas_no_id)}" placeholder="opsional"></td>
                            <td><input type="text" data-f="keterangan" autocomplete="off" value="${esc(b.keterangan)}" placeholder="mis. cicilan kasbon rit ke-4"></td>
                            <td style="text-align: center;"><input type="checkbox" data-f="tetap" value="1" ${b.tetap ? 'checked' : ''}></td>
                            <td><button type="button" class="hapus" title="Hapus baris">×</button></td>`,
                    };

                    // Kolom yang berlaku per jenis potongan; kasbon: sisa bon terpilih.
                    const aturBaris = (form, tr) => {
                        if (form.dataset.arah === 'nambah') {
                            const bon = BON.find(x => x.no_id === tr.querySelector('[data-f=kas_no_id]').value);
                            tr.querySelector('[data-sisa]').value = bon ? fmt(bon.sisa) : '';
                            return;
                        }
                        const v = tr.querySelector('[data-f=peruntukan]').value;
                        const pakai = (f, aktif) => { const el = tr.querySelector(`[data-f=${f}]`); el.disabled = !aktif; };
                        pakai('no_do', v === 'uj'); pakai('no_mobil', v === 'uj'); pakai('tetap', v === 'uj');
                        pakai('kas_no_id', v === 'kembali');
                        const proyek = tr.querySelector('[data-f=tahap]');
                        proyek.disabled = BUKAN_BIAYA.includes(v);
                        proyek.options[0].textContent = v === 'uj' ? '— otomatis dari rit DO —' : (v === 'hangus' ? '— dibebankan ke proyek —' : '— pilih proyek —');
                        if (v === 'hangus' && !proyek.value && ASAL) proyek.value = ASAL;
                    };
                    const hitung = form => {
                        const total = [...form.querySelectorAll('[data-f=nominal]')].reduce((n, el) => n + angka(el.value), 0);
                        const sesudah = SISA_UTANG + (form.dataset.arah === 'nambah' ? total : -total);
                        form.querySelector('[data-total]').textContent = fmt(total);
                        const s = form.querySelector('[data-sesudah]');
                        s.textContent = (sesudah < 0 ? '−' : '') + fmt(Math.abs(sesudah));
                        s.classList.toggle('minus', sesudah < 0);
                    };
                    const tambah = (form, b) => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = BARIS[form.dataset.arah](b);
                        form.querySelector('[data-baris]').append(tr);
                        tr.querySelectorAll('input.rupiah').forEach(rapikanRupiah);
                        aturBaris(form, tr);
                        hitung(form);
                        return tr;
                    };
                    document.querySelectorAll('form.grid-utang').forEach(form => {
                        const awal = LAMA.arah === form.dataset.arah && LAMA.baris.length ? LAMA.baris : [{}];
                        awal.forEach(b => tambah(form, b));
                        form.querySelector('[data-tambah]').addEventListener('click', () => tambah(form).querySelector('select, input')?.focus());
                        form.addEventListener('input', e => { if (e.target.classList.contains('rupiah')) rapikanRupiah(e.target); hitung(form); });
                        form.addEventListener('change', e => {
                            const tr = e.target.closest('tr');
                            if (!tr) return;
                            aturBaris(form, tr);
                            // Kasbon: nominal awal = sisa bon yang dipilih.
                            if (e.target.dataset.f === 'kas_no_id' && form.dataset.arah === 'nambah') {
                                const bon = BON.find(x => x.no_id === e.target.value), nom = tr.querySelector('[data-f=nominal]');
                                if (bon && !angka(nom.value)) nom.value = fmt(bon.sisa);
                            }
                            hitung(form);
                        });
                        form.addEventListener('click', e => {
                            if (!e.target.classList.contains('hapus')) return;
                            const semua = form.querySelectorAll('[data-baris] tr');
                            if (semua.length > 1) e.target.closest('tr').remove(); else e.target.closest('tr').querySelectorAll('input[type=text]:not([readonly])').forEach(el => el.value = '');
                            hitung(form);
                        });
                        // Nama isian baris[i][...] diberikan saat simpan (baris kosong dilewati).
                        form.addEventListener('submit', () => {
                            let i = 0;
                            form.querySelectorAll('[data-baris] tr').forEach(tr => {
                                const isi = angka(tr.querySelector('[data-f=nominal]').value) || (tr.querySelector('[data-f=kas_no_id]')?.value && form.dataset.arah === 'nambah');
                                tr.querySelectorAll('[data-f]').forEach(el => { el.name = isi ? `baris[${i}][${el.dataset.f}]` : ''; });
                                if (isi) i++;
                            });
                        });
                    });

                    const aturArah = () => {
                        const arah = document.querySelector('input[name=arah-utang]:checked')?.value || 'nambah';
                        document.querySelectorAll('form.grid-utang').forEach(f => { f.style.display = f.dataset.arah === arah ? '' : 'none'; });
                    };
                    document.querySelectorAll('input[name=arah-utang]').forEach(r => r.addEventListener('change', aturArah));
                    aturArah();
                })();
            </script>
        @endif
    @else
        <p class="redup" style="font-size: 13px; margin: 0 0 12px;">Pilih UJ (Uang Jalan / Uang MOB) yang sudah diberikan tetapi <b>belum bongkar</b> dan uangnya masih dipegang sopir (mis. truk pindah lokasi).
            UJ itu keluar dari biaya proyek asal dan menjadi <b>saldo sopir</b> — proyek tujuannya <b>tidak perlu dipilih</b>. Transaksi Kas UJ aslinya tidak diubah.</p>

        <form method="GET" class="kartu baris-isian" style="padding: 12px 14px;">
            <input type="hidden" name="tab" value="pilih">
            @if ($mobil->isNotEmpty())<input type="hidden" name="mobil" value="{{ $mobil->implode(',') }}">@endif
            <div><label>Proyek asal</label>
                <select name="asal" onchange="this.form.submit()"><option value="">Semua proyek ({{ $daftarAsal->sum('n') }})</option>
                    @foreach ($daftarAsal->sortBy('nama') as $id => $a)<option value="{{ $id }}" @selected($asal === (int) $id)>{{ $a['nama'] }} ({{ $a['n'] }})</option>@endforeach</select></div>
            <div style="flex: 1; min-width: 240px;"><label>Cari sopir / truk / ID UJ / DO / keterangan</label><input name="cari" value="{{ $cari }}" style="width: 100%;"></div>
            <div><label>Status</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="semua">Semua ({{ array_sum($jumlahStatus) }})</option>
                    <option value="belum" @selected($status === 'belum')>Belum ({{ $jumlahStatus['belum'] }})</option>
                    <option value="sudah" @selected($status === 'sudah')>Sudah jadi saldo sopir ({{ $jumlahStatus['sudah'] }})</option>
                </select></div>
            <label style="display: flex; gap: 6px; align-items: center; margin: 0 0 8px; color: var(--teks); font-size: 13px;"><input type="checkbox" name="lama" value="1" @checked($lama) onchange="this.form.submit()"> termasuk data lama sebelum Mei 2026 ({{ $jumlahLama }})</label>
            <button class="tombol polos" type="submit">Tampilkan</button>
            @if ($mobil->isNotEmpty())<span class="redup" style="font-size: 12px;">Truk: {{ $mobil->implode(', ') }} · <a href="{{ route('pengalihan-uj.index', ['tab' => 'pilih']) }}">semua truk</a></span>@endif
        </form>

        <form method="POST" action="{{ route('pengalihan-uj.simpan') }}" class="kartu gulir" style="padding: 0;">@csrf
            <div style="padding: 12px 14px 6px;"><b style="color: var(--aksen-terang);">UJ belum bongkar ({{ $calon->count() }}{{ ($n = $calon->filter(fn ($c) => isset($c->p))->count()) ? ', '.$n.' sudah jadi saldo sopir' : '' }})</b>
                <span class="redup" style="font-size: 12px;">⚠ = mungkin sudah jalan tetapi belum diinput di Ritasi (arahkan kursor untuk alasannya) — cek dulu sebelum dijadikan saldo.</span></div>
            <table class="kas">
                <thead><tr><th data-tanpa-saring style="width: 30px;"><input type="checkbox" id="semua" title="Centang semua yang tampil"></th><th>Sopir</th><th>Truk</th><th>Tanggal UJ</th><th class="angka">Umur</th><th>ID UJ</th><th>No DO</th><th>Kategori</th><th>Keterangan</th><th>Proyek asal</th><th class="angka">Nominal</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($calon as $c)
                        @php($sudah = isset($c->p))
                        <tr class="t" @if ($sudah) style="opacity: .8;" @endif>
                            <td>@if ($sudah)<span title="Sudah diproses — batalkan dulu bila ingin diubah">✓</span>@else<input type="checkbox" name="uj[]" value="{{ $c->u->id_uj }}" data-rp="{{ (int) $c->u->nominal }}" @checked(collect(old('uj'))->contains($c->u->id_uj) || (! old('uj') && $c->usul))>@endif</td>
                            <td><b>{{ \App\Support\SaldoSopir::kunci($c->u->nama) }}</b></td><td>{{ $c->u->no_mobil }}</td><td data-n="{{ $c->tgl }}">{{ $tgl($c->tgl) }}</td>
                            <td class="angka" data-n="{{ $c->umur }}">@if ($sudah)<span class="redup">{{ $c->umur }} hari</span>@else<span class="label {{ ['merah' => 'merah', 'lewat' => 'kuning', 'wajar' => 'hijau'][$c->status] ?? '' }}">{{ $c->umur }} hari</span>@endif</td>
                            <td>{{ $c->u->id_uj }}</td><td>{{ $c->u->no_do }}</td><td>{{ $c->u->kategori }}</td><td style="font-size: 12px;">{{ $c->u->keterangan }}</td>
                            <td>{{ $c->tahap ?? '—' }}</td><td class="angka" data-n="{{ (int) $c->u->nominal }}">{{ rp((int) $c->u->nominal) }}</td>
                            {{-- Status ringkas (2 nilai untuk saringan kolom); rinciannya di tooltip --}}
                            @if ($sudah)
                                <td data-v="Sudah jadi saldo sopir" style="white-space: nowrap;"><span class="label hijau" title="{{ $c->info }}">Sudah jadi saldo sopir</span>
                                    <button type="submit" form="batal-{{ $c->p->id }}" class="tombol polos" style="padding: 1px 8px; font-size: 11px; margin-left: 4px;">Batalkan</button></td>
                            @else
                                <td data-v="Belum" style="white-space: nowrap;"><span class="label">Belum</span>@if ($c->catatan) <span title="{{ $c->catatan }}" style="cursor: help; color: #f0c05a;">⚠</span>@endif</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="12" class="redup" style="padding: 18px 14px;">Tidak ada UJ belum bongkar{{ $asal || $cari !== '' ? ' yang cocok' : '' }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($calon->isNotEmpty())
                <div class="baris-isian" style="padding: 12px 14px;">
                    <div><label>Tindakan</label><select name="jenis"><option value="alih">Jadikan saldo sopir</option><option value="kembali">Dikembalikan sopir (refund)</option></select></div>
                    <div><label>Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', now()->toDateString()) }}"></div>
                    <div style="flex: 1; min-width: 260px;"><label>Alasan</label><input name="keterangan" value="{{ old('keterangan', 'Truk pindah lokasi sebelum bongkar') }}" style="width: 100%;" required minlength="5"></div>
                    <button class="tombol" type="submit">Simpan <span id="jml"></span></button>
                </div>
            @endif
        </form>

        {{-- Form batal untuk UJ yang sudah diproses (di luar form utama; tombolnya memakai atribut form=) --}}
        @foreach ($calon->filter(fn ($c) => isset($c->p)) as $c)
            <form method="POST" action="{{ route('pengalihan-uj.hapus', $c->p) }}" id="batal-{{ $c->p->id }}" hidden
                onsubmit="return confirm('Batalkan {{ $c->u->id_uj }} ({{ \App\Models\UjPengalihan::JENIS[$c->p->jenis] }})? UJ-nya kembali dihitung belum bongkar.')">@csrf @method('DELETE')</form>
        @endforeach

        <script>
            (() => {
                const kotak = [...document.querySelectorAll('input[name="uj[]"]')], jml = document.getElementById('jml');
                const tampil = k => k.closest('tr').offsetParent !== null; // yang tersaring (saring kolom) tidak ikut dicentang
                const hitung = () => { const c = kotak.filter(k => k.checked); if (jml) jml.textContent = c.length ? `(${c.length} UJ · Rp ${c.reduce((a, k) => a + +k.dataset.rp, 0).toLocaleString('id-ID')})` : ''; };
                kotak.forEach(k => k.addEventListener('change', hitung)); hitung();
                document.getElementById('semua')?.addEventListener('change', e => { kotak.forEach(k => { if (tampil(k)) k.checked = e.target.checked; }); hitung(); });
            })();
        </script>
    @endif
@endsection
