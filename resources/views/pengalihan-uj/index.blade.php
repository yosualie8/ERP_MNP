@extends('layouts.app', ['judul' => 'Pengalihan UJ'])

@section('lebar', '1440px')

@section('isi')
    @php
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-y') : '';
        $adaSaldo = $saldo->filter(fn ($s) => $s['saldo'] > 0.5);
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
        <a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo']) }}" @class(['aktif' => $tab === 'saldo'])>💰 Utang / saldo sopir @if ($adaSaldo->count())· {{ $adaSaldo->count() }} sopir · {{ rp((int) $adaSaldo->sum('saldo')) }}@endif</a>
        <a href="{{ route('pengalihan-uj.index', ['tab' => 'pilih']) }}" @class(['aktif' => $tab === 'pilih'])>☑ Pilih UJ → jadikan saldo</a>
    </div>

    @if ($tab === 'saldo')
        @php
            $saring = in_array(request('tampil'), ['merah', 'semua'], true) ? request('tampil') : 'ada';
            $daftar = $saldo->filter(fn ($s) => $saring === 'semua' || ($saring === 'merah' ? $s['status'] === 'merah' : $s['saldo'] > 0.5))
                ->sortBy(fn ($s) => [['merah' => 0, 'kuning' => 1, 'baru' => 2, 'kasbon' => 3, 'habis' => 4][$s['status']], -$s['saldo']]);
            $merah = $saldo->where('status', 'merah');
        @endphp
        <p class="redup" style="font-size: 13px; margin: 0 0 12px;">Utang / saldo sopir = <b>uang PT yang masih dipegang sopir</b>, dihitung per sopir (bukan per truk).
            <b>➕ Nambah utang</b>: UJ yang sudah diberikan tetapi tidak bongkar (tab Pilih UJ), atau <b>kasbon</b> dari Kas Harian.
            <b>➖ Potong utang</b>: DO lamanya ternyata bongkar, dipotong untuk <b>uang jalan DO baru</b> (boleh sebagian — sisanya ditransfer di Kas UJ dengan No DO yang sama, tersambung otomatis),
            Uang MOB / lain (biaya proyek tempat dipakai), potong gaji / UM, dikembalikan sopir, atau tidak kembali.
            Umur &gt; {{ \App\Support\SaldoSopir::KUNING }} hari kuning, &gt; {{ \App\Support\SaldoSopir::MERAH }} hari merah — tagih atau putuskan (kasbon yang sedang dicicil tanpa warna umur).</p>

        <form method="GET" action="{{ route('pengalihan-uj.index') }}" class="baris-isian" style="margin-bottom: 12px;">
            <input type="hidden" name="tab" value="saldo">
            <div><label>Buka buku utang sopir (juga sopir yang belum punya catatan, mis. untuk kasbon)</label>
                <input name="sopir" list="daftar-sopir-buka" value="{{ $dipilih['sopir'] ?? '' }}" placeholder="ketik nama sopir" style="width: 260px;" autocomplete="off"></div>
            <button class="tombol" type="submit">Buka</button>
            <datalist id="daftar-sopir-buka">@foreach ($namaSopir ?? [] as $n)<option value="{{ $n }}">@endforeach</datalist>
        </form>

        <div class="ringkas-alih">
            <div class="kartu"><small>Saldo di sopir (belum biaya)</small><b>{{ rp((int) $adaSaldo->sum('saldo')) }}</b><small>{{ $adaSaldo->count() }} sopir</small></div>
            <div class="kartu" @if ($merah->count()) style="border-top-color: var(--merah);" @endif><small>Merah (&gt; {{ \App\Support\SaldoSopir::MERAH }} hari)</small><b @if ($merah->count()) style="color: var(--merah);" @endif>{{ rp((int) $merah->sum('saldo')) }}</b><small>{{ $merah->count() }} sopir</small></div>
            <div class="kartu"><small>Sudah terpakai (jadi biaya proyek)</small><b>{{ rp((int) ($saldo->sum('terpakai_do') + $saldo->sum('dipakai') + $saldo->sum('hangus'))) }}</b><small>DO lama bongkar {{ rp((int) $saldo->sum('terpakai_do')) }} · dipotong / dipakai {{ rp((int) $saldo->sum('dipakai')) }}</small></div>
            <div class="kartu"><small>Dikembalikan / dicicil sopir</small><b>{{ rp((int) $saldo->sum('kembali')) }}</b><small>kasbon {{ rp((int) $saldo->sum('kasbon')) }} · tidak kembali {{ rp((int) $saldo->sum('hangus')) }}</small></div>
        </div>

        <div style="display: flex; gap: 6px; align-items: center; margin-bottom: 10px; flex-wrap: wrap;">
            <span class="redup" style="font-size: 13px;">Tampilkan:</span>
            <a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo']) }}" @class(['chip', 'aktif' => $saring === 'ada']) style="margin: 0;">Masih ada saldo · {{ $adaSaldo->count() }}</a>
            <a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo', 'tampil' => 'merah']) }}" @class(['chip', 'aktif' => $saring === 'merah']) style="margin: 0;">Merah · {{ $merah->count() }}</a>
            <a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo', 'tampil' => 'semua']) }}" @class(['chip', 'aktif' => $saring === 'semua']) style="margin: 0;">Semua (termasuk habis) · {{ $saldo->count() }}</a>
            <span class="redup" style="font-size: 13px; margin-left: auto;">Klik nama sopir untuk rincian & mencatat pemakaian.</span>
        </div>

        <div class="kartu gulir" style="padding: 0;">
            <table class="kas">
                <thead><tr><th>Sopir</th><th>Truk</th><th class="angka">UJ jadi saldo</th><th class="angka">Rp jadi saldo</th><th class="angka">Kasbon</th><th class="angka">Terpakai DO lama</th><th class="angka">Dipotong / dipakai</th>
                    <th class="angka">Dikembalikan / dicicil</th><th class="angka">Tidak kembali</th><th class="angka">Sisa utang</th><th>Umur</th><th>Terakhir</th></tr></thead>
                <tbody>
                    @forelse ($daftar as $s)
                        <tr @class(['t', 'pilih' => $dipilih && $dipilih['sopir'] === $s['sopir']])>
                            <td><a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo', 'tampil' => $saring !== 'ada' ? $saring : null, 'sopir' => $s['sopir']]) }}#rinci"><b>{{ $s['sopir'] }}</b></a></td>
                            <td>{{ implode(', ', $s['mobil']) }}</td>
                            <td class="angka">{{ $s['n_alih'] }}</td>
                            <td class="angka" data-n="{{ (int) $s['alih'] }}">{{ rp((int) $s['alih'], true) }}</td>
                            <td class="angka" data-n="{{ (int) $s['kasbon'] }}">{{ rp((int) $s['kasbon'], true) }}</td>
                            <td class="angka" data-n="{{ (int) $s['terpakai_do'] }}">{{ rp((int) $s['terpakai_do'], true) }}</td>
                            <td class="angka" data-n="{{ (int) $s['dipakai'] }}">{{ rp((int) $s['dipakai'], true) }}</td>
                            <td class="angka" data-n="{{ (int) $s['kembali'] }}">{{ rp((int) $s['kembali'], true) }}</td>
                            <td class="angka" data-n="{{ (int) $s['hangus'] }}">{{ rp((int) $s['hangus'], true) }}</td>
                            <td class="angka" data-n="{{ (int) $s['saldo'] }}"><b>{{ rp((int) $s['saldo']) }}</b></td>
                            <td data-n="{{ $s['umur'] ?? -1 }}">@if ($s['umur'] !== null)<span class="label {{ $warnaUmur[$s['status']] }}">{{ $s['umur'] }} hari</span> <span class="redup" style="font-size: 11px;">sejak {{ $tgl($s['sejak']) }}</span>@elseif ($s['status'] === 'kasbon')<span class="redup">kasbon dicicil</span>@else<span class="redup">habis</span>@endif</td>
                            <td data-n="{{ $s['terakhir']?->toDateString() }}">{{ $tgl($s['terakhir']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="12" class="redup" style="padding: 18px 14px;">Tidak ada saldo sopir{{ $saring === 'merah' ? ' yang merah' : '' }}. Pilih UJ di tab <a href="{{ route('pengalihan-uj.index', ['tab' => 'pilih']) }}">Pilih UJ → jadikan saldo</a>.</td></tr>
                    @endforelse
                </tbody>
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
            </style>
            <div class="kartu" id="rinci" style="padding: 0;">
                <div style="padding: 12px 14px 8px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <b style="font-size: 17px; color: var(--aksen-terang);">Buku utang {{ $s['sopir'] }}</b>
                    <span class="redup" style="font-size: 13px;">Truk: {{ implode(', ', $s['mobil']) ?: '—' }}</span>
                    <span>Sisa utang <b>{{ rp((int) $s['saldo']) }}</b> @if ($s['umur'] !== null)<span class="label {{ $warnaUmur[$s['status']] }}">{{ $s['umur'] }} hari</span>@endif</span>
                    <a href="{{ route('pengalihan-uj.index', ['tab' => 'saldo']) }}" class="redup" style="margin-left: auto; font-size: 13px;">Tutup ✕</a>
                </div>
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

                <div class="arah-utang">
                    <span class="redup" style="font-size: 13px;">Transaksi baru:</span>
                    <label><input type="radio" name="arah-utang" value="nambah" @checked(! $bisaPotong || old('arah') === 'nambah')> ➕ Nambah utang</label>
                    <label @class(['mati' => ! $bisaPotong]) @unless ($bisaPotong) title="Belum ada utang untuk dipotong" @endunless><input type="radio" name="arah-utang" value="potong" @checked($bisaPotong && old('arah') !== 'nambah') @disabled(! $bisaPotong)> ➖ Potong utang</label>
                </div>

                <form method="POST" action="{{ route('pengalihan-uj.kasbon') }}" class="baris-isian" id="form-nambah" style="padding: 8px 14px 12px;">@csrf
                    <input type="hidden" name="sopir" value="{{ $s['sopir'] }}"><input type="hidden" name="arah" value="nambah">
                    <div style="flex: 1; min-width: 360px;"><label>Kasbon dari Kas Harian (bon ber-Kode GL Piutang, sisa yang belum ditautkan)</label>
                        <select name="kas_no_id" id="bon-kasbon" style="width: 100%;"><option value="">— pilih bon —</option>
                            @foreach ($bonKasbon ?? [] as $bk)
                                @php($sebut = str_contains(mb_strtolower($bk->keterangan.' '.$bk->nama_tujuan), mb_strtolower($s['sopir'])))
                                <option value="{{ $bk->no_id }}" data-sisa="{{ (int) $bk->sisa }}" @selected(old('kas_no_id') === $bk->no_id)>{{ $sebut ? '★ ' : '' }}{{ $tgl($bk->tanggal) }} · {{ rp((int) $bk->nominal) }}@if ($bk->tertaut > 0.5) (sisa {{ rp((int) $bk->sisa) }})@endif · {{ mb_strimwidth($bk->keterangan, 0, 70, '…') }} · NO ID {{ $bk->no_id }}</option>
                            @endforeach
                        </select></div>
                    <div><label>Nominal untuk {{ $s['sopir'] }}</label><input name="nominal" id="nominal-kasbon" value="{{ old('nominal') }}" style="width: 120px; text-align: right;" inputmode="numeric"></div>
                    <div style="flex: 1; min-width: 200px;"><label>Catatan (opsional)</label><input name="keterangan" value="{{ old('keterangan') }}" style="width: 100%;" placeholder="mis. bagian Endang dari kasbon 4 sopir"></div>
                    <button class="tombol" type="submit">Catat kasbon</button>
                    <p class="redup" style="font-size: 12px; margin: 4px 0 0; flex-basis: 100%;">★ = bon yang menyebut nama {{ $s['sopir'] }}. Satu bon boleh dibagi ke beberapa sopir (mis. Rp2.000.000 untuk 4 sopir @ Rp500.000).
                        UJ yang sudah diberikan tetapi tidak bongkar → <a href="{{ route('pengalihan-uj.index', ['tab' => 'pilih', 'cari' => $s['sopir']]) }}">tab Pilih UJ → jadikan saldo</a>.</p>
                </form>

                @if ($bisaPotong)
                    <form method="POST" action="{{ route('pengalihan-uj.pakai') }}" class="baris-isian" id="form-potong" style="padding: 8px 14px 12px;">@csrf
                        <input type="hidden" name="sopir" value="{{ $s['sopir'] }}"><input type="hidden" name="arah" value="potong">
                        <div><label>Dipotong untuk</label>
                            <select name="peruntukan" id="peruntukan">@foreach (\App\Models\UjPengalihan::PERUNTUKAN as $k => $l)<option value="{{ $k }}" @selected(old('peruntukan', 'uj') === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div><label>Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', now()->toDateString()) }}"></div>
                        <div><label>Nominal (maks. {{ rp((int) $s['saldo']) }})</label><input name="nominal" value="{{ old('nominal', (int) $s['saldo']) }}" style="width: 120px; text-align: right;" inputmode="numeric"></div>
                        <div class="isian-do"><label>No DO uang jalan</label><input name="no_do" value="{{ old('no_do') }}" style="width: 100px;" inputmode="numeric" autocomplete="off"></div>
                        <div class="isian-do"><label>No Mobil</label><input name="no_mobil" value="{{ old('no_mobil', $s['mobil'][0] ?? '') }}" style="width: 90px;" autocomplete="off"></div>
                        <div class="isian-noid"><label>NO ID mutasi masuk (opsional)</label><input name="kas_no_id" value="{{ old('kas_no_id') }}" style="width: 120px;" autocomplete="off"></div>
                        <div id="isian-proyek"><label id="label-proyek">Ditanggung proyek</label>
                            <select name="tahap" data-asal="{{ $asalTertua }}"><option value="">— pilih —</option>@foreach ($pilihTahap as $t)<option value="{{ $t->id }}" @selected((int) old('tahap') === $t->id)>{{ $t->nama }}</option>@endforeach</select></div>
                        <div style="flex: 1; min-width: 220px;"><label>Keterangan / alasan</label><input name="keterangan" value="{{ old('keterangan') }}" style="width: 100%;" placeholder="mis. cicilan kasbon rit ke-4 / Uang MOB Kendal ke Jogja"></div>
                        <label class="isian-do" style="display: flex; gap: 4px; align-items: center; font-size: 12px;"><input type="checkbox" name="tetap" value="1" @checked(old('tetap'))> Tetap simpan walau DT berbeda</label>
                        <button class="tombol" type="submit">Catat potongan</button>
                        <p class="redup isian-do" style="font-size: 12px; margin: 4px 0 0; flex-basis: 100%;">Uang jalan dipotong <b>sebagian</b>? Catat potongannya di sini, lalu transfer sisanya lewat <b>Input UJ</b> dengan No DO yang sama —
                            keduanya otomatis tersambung lewat No DO (dihitung satu uang jalan di validasi Kas UJ & Monitor Ritasi).</p>
                    </form>
                @endif
                <form method="POST" action="{{ route('pengalihan-uj.sopir') }}" class="baris-isian" style="padding: 0 14px 14px;">@csrf
                    <input type="hidden" name="lama" value="{{ $s['sopir'] }}">
                    <div><label>Ganti / gabungkan nama sopir (ejaan lain di Kas UJ)</label><input name="baru" value="{{ $s['sopir'] }}" list="daftar-sopir" style="width: 220px;"></div>
                    <button class="tombol polos" type="submit" onclick="return confirm('Pindahkan semua catatan utang {{ $s['sopir'] }} ke nama ini?')">Simpan nama</button>
                    <datalist id="daftar-sopir">@foreach ($saldo->keys() as $n)<option value="{{ $n }}">@endforeach</datalist>
                </form>
            </div>
            <script>
                (() => {
                    const nambah = document.getElementById('form-nambah'), potong = document.getElementById('form-potong');
                    const aturArah = () => {
                        const arah = document.querySelector('input[name=arah-utang]:checked')?.value || 'nambah';
                        nambah.hidden = arah !== 'nambah';
                        if (potong) potong.hidden = arah !== 'potong';
                    };
                    document.querySelectorAll('input[name=arah-utang]').forEach(r => r.addEventListener('change', aturArah));
                    aturArah();
                    // Kasbon: nominal awal = sisa bon yang dipilih.
                    const bon = document.getElementById('bon-kasbon'), nom = document.getElementById('nominal-kasbon');
                    bon?.addEventListener('change', () => { const o = bon.selectedOptions[0]; if (o?.dataset.sisa) nom.value = o.dataset.sisa; });

                    const p = document.getElementById('peruntukan'), box = document.getElementById('isian-proyek'), label = document.getElementById('label-proyek'), sel = box?.querySelector('select');
                    const atur = () => {
                        if (!p) return;
                        const v = p.value;
                        box.style.display = ['kembali', 'gaji', 'sebelum'].includes(v) ? 'none' : '';
                        label.textContent = v === 'hangus' ? 'Dibebankan ke proyek' : (v === 'uj' ? 'Ditanggung proyek (opsional — otomatis dari rit DO)' : 'Ditanggung proyek');
                        potong.querySelectorAll('.isian-do').forEach(el => el.style.display = v === 'uj' ? '' : 'none');
                        potong.querySelectorAll('.isian-noid').forEach(el => el.style.display = v === 'kembali' ? '' : 'none');
                        if (v === 'hangus' && !sel.value && sel.dataset.asal) sel.value = sel.dataset.asal;
                    };
                    p?.addEventListener('change', atur); atur();
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
