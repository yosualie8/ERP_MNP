@extends('layouts.app', ['judul' => 'Pengajuan UJ'])

@section('lebar', '1440px')

@section('isi')
    <style>
        /* Ringkasan pengajuan (rit & tujuan) — tampil hanya saat pengajuan dibuka */
        table.kas tbody.grup:not(.buka) tr.ringkas-pj { display: none; }
        table.kas tr.ringkas-pj td { background: rgba(255, 255, 255, .03); padding: 8px 12px; }
        .ringkas-pj table { border-collapse: collapse; font-size: 12.5px; margin-top: 4px; width: auto; min-width: 0; }
        .ringkas-pj table th, .ringkas-pj table td { padding: 3px 10px; border-bottom: 1px solid var(--garis); text-align: left; white-space: nowrap; background: none; }
        .ringkas-pj table th { color: var(--redup); font-weight: 600; }
        .ringkas-pj .angka { text-align: right; }
        table.kas tr.b.terealisasi td { color: var(--sukses); }
        table.kas tr.b.dialihkan td { color: #dcb3ff; }
        table.kas tr.b.batal td { color: var(--redup); text-decoration: line-through; }
        table.kas tr.b.berbeda td { color: #f0c05a; }
        table.kas tr.realisasi td { padding-top: 0; font-size: 12px; color: var(--teks); }
        table.kas tbody.grup:not(.buka) tr.realisasi { display: none; }
        .info-beda { border-left: 3px solid #e0a526; background: #1d1810; border-radius: 6px; padding: 6px 10px; line-height: 1.5; }
        .info-beda.dialihkan { border-left-color: #c77dff; background: #1d1426; }
        .info-beda del { color: var(--redup); }
        .label.ungu { background: #3b2350; color: #dcb3ff; }
        input.pilih-real, input.pilih-semua-pj { width: 17px; height: 17px; cursor: pointer; vertical-align: middle; }
        table.kas tr.b.dipilih td { background: rgba(76, 195, 138, .14); }
        .bar-realisasi { position: sticky; bottom: 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; padding: 12px 16px; margin-top: 12px;
            background: var(--kartu); border: 1px solid var(--aksen); border-radius: 10px; z-index: 2; }
        .bar-realisasi[hidden] { display: none; }
        td.sel-transfer { font-size: 12px; min-width: 220px; }
        td.sel-transfer .tf-baris { margin: 3px 0; white-space: nowrap; }
        td.sel-transfer .lepas-tf { background: none; border: none; color: var(--redup); cursor: pointer; padding: 0 3px; font-size: 12px; }
        td.sel-transfer .lepas-tf:hover { color: var(--aksen); }
        td.sel-transfer .pilih-tf-label { float: left; margin: 1px 6px 0 0; cursor: pointer; }
        input.pilih-tf { width: 17px; height: 17px; cursor: pointer; vertical-align: middle; }
        table.kas tr.t.dipilih-tf td { background: rgba(229, 72, 77, .12); }
        .bar-bawah { position: sticky; bottom: 0; z-index: 2; }
        .bar-bawah .bar-realisasi { position: static; }
        dialog#dialog-transfer { width: min(1100px, 96vw); max-height: 86vh; padding: 0; border: 1px solid var(--garis); border-radius: 12px; background: var(--kartu); color: var(--teks); }
        dialog#dialog-transfer::backdrop { background: rgba(0, 0, 0, .6); }
        #dialog-transfer .kepala-dt { padding: 12px 16px; border-bottom: 1px solid var(--garis); display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        #dialog-transfer .kepala-dt input { flex: 1; min-width: 220px; padding: 6px 9px; border-radius: 6px; border: 2px solid #4a5160; font-size: 13px; }
        /* Dialog = kolom flex: kepala (cari) & kaki (tombol Tautkan) selalu terlihat, hanya daftar yang digulir — sebelumnya di layar pendek kaki terpotong. */
        dialog#dialog-transfer[open] { display: flex; flex-direction: column; overflow: hidden; }
        #dialog-transfer form { display: flex; flex-direction: column; flex: 1; min-height: 0; max-height: 86vh; }
        #dialog-transfer .kepala-dt, #dialog-transfer .kaki-dt { flex: none; }
        #dialog-transfer .isi-dt { flex: 1; min-height: 120px; overflow: auto; }
        #dialog-transfer table { width: 100%; border-collapse: collapse; font-size: 13px; }
        #dialog-transfer th { position: sticky; top: 0; background: var(--kartu-2); text-align: left; padding: 6px 8px; font-size: 12px; color: var(--redup); }
        #dialog-transfer td { padding: 5px 8px; border-top: 1px solid var(--garis); cursor: pointer; }
        #dialog-transfer td.angka, #dialog-transfer th.angka { text-align: right; font-variant-numeric: tabular-nums; }
        #dialog-transfer tr.dipilih td { background: rgba(76, 195, 138, .14); }
        #dialog-transfer tr.terpasang td { color: var(--redup); }
        #dialog-transfer .kaki-dt { padding: 10px 16px; border-top: 1px solid var(--garis); display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .pilih-tampil { display: inline-flex; align-items: center; gap: 6px; margin: 0 0 8px; cursor: pointer; font-size: 13px; }
    </style>
    <div class="ringkas">
        <div class="kartu"><span class="redup">Menunggu realisasi</span><b>{{ rp((int) $menunggu->s) }}</b></div>
        <div class="kartu"><span class="redup">Detail menunggu</span><b>{{ (int) $menunggu->n }}</b></div>
        <div class="kartu"><span class="redup">Pengajuan terbuka</span><b>{{ $semua->whereIn('status', ['diajukan', 'sebagian'])->count() }}</b></div>
        <a class="kartu" href="{{ route('pengajuan-uj.daftar', ['beda' => 1]) }}" style="text-decoration: none; color: inherit;">
            <span class="redup">Realisasi tidak sesuai</span>
            <b>{{ $detailBeda->count() }}</b>
            <span class="redup" style="font-size: 12px;">{{ $detailBeda->where('jenis_realisasi', 'dialihkan')->count() }} dialihkan · {{ $detailBeda->where('jenis_realisasi', 'penyesuaian')->count() }} penyesuaian</span>
        </a>
    </div>

    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="redup">Status:</span>
        <a href="{{ route('pengajuan-uj.daftar') }}" @class(['chip', 'aktif' => ! $status && ! $beda])>Terbuka + terbaru</a>
        @foreach (\App\Models\UjPengajuan::STATUS as $k => $l)
            @if ($n = $semua->where('status', $k)->count())
                <a href="{{ route('pengajuan-uj.daftar', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $l }} · {{ $n }}</a>
            @endif
        @endforeach
        @if ($detailBeda->count())
            <a href="{{ route('pengajuan-uj.daftar', ['beda' => 1]) }}" @class(['chip', 'aktif' => $beda])>⚠ Tidak sesuai / dialihkan · {{ $detailBeda->count() }}</a>
        @endif
        @if ($pengajuan->isNotEmpty())
            <span style="margin-left: auto; display: flex; gap: 6px;">
                <button type="button" class="tombol polos" id="buka-semua-pj" style="padding: 5px 12px; font-size: 13px;" title="Buka rincian semua pengajuan">⊞ Buka semua</button>
                <button type="button" class="tombol polos" id="tutup-semua-pj" style="padding: 5px 12px; font-size: 13px;" title="Tutup rincian semua pengajuan">⊟ Tutup semua</button>
            </span>
        @endif
        @if ($bolehTautkan)
            <button type="button" class="tombol polos" id="buka-tanda-tf" style="padding: 8px 14px;" title="Tautkan transfer yang sudah ditandai ke transaksi Kas Harian bernominal sama">🔗 Transfer (Kas Harian){{ count($tandaKandidat) ? ' · '.count($tandaKandidat).' ditandai' : '' }}</button>
        @endif
        @if ($bolehAjukan)<a href="{{ route('pengajuan-uj.buat') }}" class="tombol" style="{{ $pengajuan->isNotEmpty() ? '' : 'margin-left: auto; ' }}padding: 8px 14px;">+ Ajukan uang jalan</a>@endif
        @if ($bolehRealisasi)<a href="{{ route('uj.input') }}" class="tombol polos" style="padding: 8px 14px;">Realisasikan di Input UJ →</a>@endif
    </div>

    @if ($bolehTautkan && $tandaTerbuka->isNotEmpty())
        {{-- Penanda transfer: owner menandai pengajuan yang dibayar saat mentransfer; ditautkan ke Kas Harian setelah Kas Harian direkap. --}}
        <div class="kartu" style="padding: 10px 14px; border-left: 3px solid #60a5fa;">
            <b>🏷 Transfer ditandai, belum ditautkan ke Kas Harian ({{ $tandaTerbuka->count() }})</b>
            <span class="redup" style="font-size: 12px;">— setelah Kas Harian direkap, klik "Tautkan ke Kas Harian" lalu pilih transaksinya (nominal sudah terisi di pencarian)</span>
            <table class="kas" style="margin-top: 6px; font-size: 13px;" data-tanpa-saring-kolom>
                <thead><tr><th>Penanda</th><th>Waktu transfer</th><th class="angka">Nominal transfer</th><th>Pengajuan dibayar</th><th class="angka">Total pengajuan</th><th>Selisih</th><th>Catatan</th><th></th></tr></thead>
                @foreach ($tandaTerbuka as $t)
                    @php($totPj = (int) $t->pengajuan->sum('nominal'))
                    @php($sel = (int) $t->nominal - $totPj)
                    @php($pjTanda = $t->pengajuan->map(fn ($p) => ['value' => (string) $p->id, 'kode' => $p->kode(), 'nominal' => (int) $p->nominal, 'tf' => $p->transfer->map(fn ($tf) => $tf->kas_no_id.':'.(int) $tf->nominal)->implode(','), 'tanggal' => $p->tanggal->toDateString()])->values())
                    <tr>
                        <td><b>{{ $t->kode() }}</b>@if ($t->reimburse)<div class="redup" style="font-size: 11px;">Reimburse UJ #{{ $t->uj_reimburse_id }}</div>@endif</td>
                        <td style="white-space: nowrap;">{{ $t->waktu->translatedFormat('j M Y H:i') }}</td>
                        <td class="angka"><b>{{ rp($t->nominal) }}</b></td>
                        <td>{{ $t->pengajuan->map(fn ($p) => $p->kode().' ('.rp($p->nominal).')')->implode(', ') }}</td>
                        <td class="angka">{{ rp($totPj) }}</td>
                        <td>@if ($sel === 0)<span class="label hijau">pas</span>@else<span class="label kuning">{{ $sel > 0 ? 'lebih' : 'kurang' }} {{ rp(abs($sel)) }}</span>@endif</td>
                        <td class="redup">{{ $t->catatan }}<div style="font-size: 11px;">{{ $t->user?->name }} · {{ $t->created_at->translatedFormat('j M H:i') }}</div></td>
                        <td style="white-space: nowrap;">
                            <button type="button" class="tombol" style="padding: 3px 10px; font-size: 12px;" data-tautkan-tanda="{{ $t->id }}" data-nominal-tanda="{{ (int) $t->nominal }}"
                                data-pj-tanda="{{ $pjTanda->toJson() }}">🔗 Tautkan ke Kas Harian</button>
                            <form method="POST" action="{{ route('pengajuan-uj.tanda-hapus', $t) }}" style="display: inline;" onsubmit="return confirm('Hapus penanda {{ $t->kode() }}?')">@csrf @method('DELETE')<button class="tombol-hapus" type="submit" title="Hapus penanda">✕</button></form>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    {{-- Saringan memakai saringan kolom bawaan grid (owner 22 Okt 2026: kotak saringan terpisah dihapus karena dobel). --}}
    @if ($pengajuan->isNotEmpty() && $bolehRealisasi)
        <label class="pilih-tampil"><input type="checkbox" id="pilih-tampil"> Centang semua transaksi menunggu yang tampil (hasil saringan kolom)</label>
    @endif
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            {{-- Kolom baris detail (tr.b) HARUS sejajar dengan judul ini: saringan kolom grid mencocokkan detail per indeks kolom.
                 No Mobil / No DO / Driver / Kategori / Status disaring per detail; sel master ber-ringkas-gl hanya ringkasan. --}}
            <thead><tr><th>Kode</th><th>Tanggal pengajuan</th><th>No Mobil</th><th>Driver</th><th>Kategori</th><th>No DO</th><th class="angka">Total</th><th>Status</th><th>Transfer (Kas Harian)</th><th>Diajukan</th><th>Keperluan / Keterangan</th><th></th></tr></thead>
            @forelse ($pengajuan as $p)
                @php($real = $p->detail->whereIn('status', ['terealisasi', 'dialihkan']))
                @php($nBeda = $p->detail->filter(fn ($d) => $d->berbeda())->count())
                <tbody class="grup">{{-- bawaan tertutup semua; buka per pengajuan atau ⊞ Buka semua --}}
                    <tr class="t ada-bon">
                        @php($siap = $p->siapRealisasi())
                        <td>@if ($bolehRealisasi && $siap && $p->detail->where('status', 'menunggu')->isNotEmpty())<input type="checkbox" class="pilih-semua-pj" data-pj="{{ $p->id }}" title="Pilih semua detail {{ $p->kode() }} yang masih menunggu"> @endif<span class="panah">▸</span> <b>{{ $p->kode() }}</b> <span class="jumlah-bon">{{ $p->detail->count() }}</span></td>
                        <td>{{ $p->tanggal->translatedFormat('j M Y') }}</td>
                        {{-- ringkas-gl: ringkasan detail — saringan kolomnya dicek per baris detail, bukan ringkasan ini. --}}
                        <td class="ringkas-gl redup" style="font-size: 12px; white-space: nowrap;">{{ $p->detail->pluck('no_mobil')->filter()->unique()->count() }} mobil</td>
                        <td class="ringkas-gl"><b>{{ $p->detail->pluck('nama')->filter()->unique()->take(4)->implode(', ') }}</b>@if ($p->detail->pluck('nama')->filter()->unique()->count() > 4) …@endif</td>
                        <td class="ringkas-gl">{{ $p->detail->pluck('kategori')->unique()->take(3)->implode(', ') }}</td>
                        <td class="ringkas-gl redup" style="font-size: 12px; white-space: nowrap;">{{ $p->detail->pluck('no_do')->filter()->unique()->count() }} DO</td>
                        <td class="angka"><b>{{ rp($p->nominal) }}</b></td>
                        <td><span @class(['label', 'kuning' => $p->status === 'sebagian', 'hijau' => $p->status === 'selesai', 'merah' => $p->status === 'batal'])>{{ \App\Models\UjPengajuan::STATUS[$p->status] }}</span>
                            @if ($nBeda)<span class="label kuning" title="Realisasi tidak sama dengan pengajuan">⚠ {{ $nBeda }} tidak sesuai</span>@endif
                            @if ($real->count())<span class="redup" style="font-size: 12px;">{{ $real->count() }}/{{ $p->detail->count() }} · {{ rp($real->sum('nominal')) }}</span>@endif</td>
                        @php($kel = $kelompokTf[$p->id] ?? null)
                        @php($totalTf = $kel['total_tf'] ?? 0)
                        @php($totalPj = $kel['total_pj'] ?? (int) $p->nominal)
                        <td class="sel-transfer">
                            {{-- Menautkan / melepas transfer Kas Harian hanya untuk Super Admin. --}}
                            @if ($bolehTautkan && $p->status !== 'batal')
                                <label class="pilih-tf-label" title="Pilih untuk ditautkan ke transfer Kas Harian (beberapa pengajuan bisa satu transfer)">
                                    <input type="checkbox" class="pilih-tf" value="{{ $p->id }}" data-kode="{{ $p->kode() }}" data-nominal="{{ (int) $p->nominal }}"
                                        data-tanggal="{{ $p->tanggal->toDateString() }}" data-tf="{{ $p->transfer->map(fn ($tf) => $tf->kas_no_id.':'.(int) $tf->nominal)->implode(',') }}"></label>
                            @endif
                            @foreach ($p->tanda as $t)
                                <div class="tf-baris" style="color: {{ $t->selesai() ? 'var(--redup)' : '#93c5fd' }};" title="Penanda transfer {{ $t->kode() }}: {{ $t->pengajuan->count() > 1 ? 'bersama '.$t->pengajuan->reject(fn ($x) => $x->id === $p->id)->map->kode()->implode(', ') : 'hanya pengajuan ini' }}{{ $t->catatan ? ' · '.$t->catatan : '' }}">
                                    🏷 {{ $t->waktu->translatedFormat('j M H:i') }} · <b>{{ rp($t->nominal) }}</b> · {{ $t->kode() }}@if ($t->pengajuan->count() > 1) <span class="redup">(+{{ $t->pengajuan->count() - 1 }} PUJ)</span>@endif @if ($t->selesai()) ✓@endif
                                </div>
                            @endforeach
                            @if ($p->transfer->isEmpty())
                                <span class="label">{{ $p->tanda->contains(fn ($t) => ! $t->selesai()) ? 'Ditandai, belum ditautkan' : 'Belum ditransfer' }}</span>
                            @else
                                <span @class(['label', 'hijau' => $totalTf >= $totalPj, 'kuning' => $totalTf < $totalPj])>{{ $totalTf >= $totalPj ? 'Sudah ditransfer' : 'Kurang '.rp($totalPj - $totalTf) }}</span>
                                @if ($totalTf > $totalPj)<span class="redup" style="font-size: 11px;">lebih {{ rp($totalTf - $totalPj) }}</span>@endif
                                @if (count($kel['kode'] ?? []) > 1)
                                    <div class="redup" style="font-size: 11px; white-space: normal;" title="Total transfer {{ rp($totalTf) }} untuk total pengajuan {{ rp($totalPj) }}">Ditransfer bersama {{ implode(', ', array_diff($kel['kode'], [$p->kode()])) }} · total {{ rp($totalPj) }}</div>
                                @endif
                                @foreach ($p->transfer as $tf)
                                    @php($kini = $kasKini[$tf->kas_no_id] ?? null)
                                    @php($berubah = ! $kini || ! $kini->tanggal->isSameDay($tf->kas_tanggal) || (int) $kini->kredit !== (int) $tf->nominal)
                                    <div class="tf-baris" title="{{ $tf->nama_tujuan }} · {{ $tf->keterangan }}{{ $tf->user ? ' · ditautkan '.($tf->user->name ?? $tf->user->email) : '' }}">
                                        {{ $tf->kas_tanggal->translatedFormat('j M') }} · <b>{{ rp($tf->nominal) }}</b> · <span class="redup">{{ $tf->idKas() }}</span>
                                        @if ($berubah)<span class="label merah" title="{{ $kini ? 'Transaksi Kas Harian ini sekarang '.$kini->tanggal->translatedFormat('j M Y').' '.rp((int) $kini->kredit) : 'Transaksi Kas Harian ini tidak ditemukan lagi (dihapus / NO ID berubah)' }}">⚠ {{ $kini ? 'berubah' : 'hilang' }}</span>@endif
                                        @if ($bolehTautkan)<form method="POST" action="{{ route('pengajuan-uj.transfer-lepas', $tf) }}" style="display: inline;" onsubmit="return confirm('Lepas tautan transfer {{ $tf->idKas() }} dari {{ $p->kode() }}?')">
                                            @csrf @method('DELETE')<button type="submit" class="lepas-tf" title="Lepas tautan dari {{ $p->kode() }}">✕</button></form>@endif
                                    </div>
                                @endforeach
                            @endif
                        </td>
                        <td class="redup" style="font-size: 12px;">{{ $p->user?->name ?? $p->user?->email }} · {{ $p->created_at->translatedFormat('j M H:i') }}</td>
                        <td style="font-size: 13px; min-width: 200px; max-width: 320px; white-space: normal;">{{ $p->keterangan ?: '–' }}</td>
                        <td style="white-space: nowrap;">
                            @if ($bolehAjukan && $p->detail->contains('status', 'menunggu'))<a href="{{ route('pengajuan-uj.ubah', $p) }}" class="tombol-edit" title="Ubah detail yang belum direalisasi">Edit</a>@endif
                            @if ($bolehAjukan && in_array($p->status, ['diajukan', 'sebagian'], true))
                                <form method="POST" action="{{ route('pengajuan-uj.batal', $p) }}" style="display: inline;" onsubmit="return confirm('Batalkan detail {{ $p->kode() }} yang masih menunggu? Detail yang sudah terealisasi tidak berubah.')">
                                    @csrf<button class="tombol-hapus" type="submit">Batalkan</button></form>
                            @endif
                        </td>
                    </tr>
                    {{-- Ringkasan (owner 24 Okt 2026): berapa rit & ke mana tujuannya; rit = No DO yang tidak dibatalkan. --}}
                    @php($ringkas = \App\Support\RingkasPengajuan::dari($p->detail))
                    <tr class="ringkas-pj"><td></td><td colspan="11">
                        <b>📋 Ringkasan {{ $p->kode() }}:</b> {{ $ringkas['rit'] }} rit · {{ $ringkas['dt'] }} DT
                        @if ($ringkas['batal'])<span class="redup"> · {{ $ringkas['batal'] }} DO dibatalkan</span>@endif
                        <table data-tanpa-saring-kolom>
                            <thead><tr><th>Galian → Tujuan</th><th class="angka">Rit</th><th class="angka">DT</th><th>Uang jalan</th><th>Uang tanah</th><th>Lainnya</th><th class="angka">Nominal aktif</th></tr></thead>
                            <tbody>
                                @foreach ($ringkas['rute'] as $r)
                                    <tr><td><b>{{ $r['galian'] ? $r['galian'].' → ' : '' }}{{ $r['tujuan'] }}</b></td><td class="angka"><b>{{ $r['rit'] }}</b>@if ($r['batal'])<span class="redup"> (+{{ $r['batal'] }} batal)</span>@endif</td>
                                        <td class="angka">{{ $r['dt'] }}</td><td>{{ \App\Support\RingkasPengajuan::teksStatus($r['uj']) }}</td><td>{{ \App\Support\RingkasPengajuan::teksStatus($r['ut']) }}</td>
                                        <td>{{ $r['lain'] ? \App\Support\RingkasPengajuan::teksStatus($r['lain']) : '' }}</td><td class="angka">{{ rp($r['rp']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td></tr>
                    <tr class="bh"><td>Detail</td><td></td><td>No Mobil</td><td>Driver</td><td>Kategori</td><td>No DO</td><td class="angka">Nominal</td><td>Status</td><td></td><td></td><td>Keterangan</td><td></td></tr>
                    @foreach ($p->detail as $d)
                        @php($teksStatus = $d->jenis_realisasi === 'penyesuaian' ? 'Terealisasi (berbeda)' : ['menunggu' => 'Menunggu', 'terealisasi' => 'Terealisasi', 'dialihkan' => 'Dialihkan', 'batal' => 'Dibatalkan'][$d->status] ?? $d->status)
                        <tr @class(['b', $d->status, 'berbeda' => $d->jenis_realisasi === 'penyesuaian']) data-detail
                            data-kode="{{ $p->kode() }}" data-nama="{{ $d->nama }}" data-ket="{{ $d->keterangan }}" data-kategori="{{ $d->kategori }}"
                            data-mobil="{{ $d->no_mobil }}" data-do="{{ $d->no_do }}" data-nominal="{{ (int) $d->nominal }} {{ number_format((int) $d->nominal, 0, ',', '.') }}"
                            data-status="{{ $teksStatus }}"
                            title="{{ $teksStatus }}{{ $d->id_uj ? ' · '.$d->id_uj : '' }}{{ $d->realisasi_pada ? ' · '.$d->realisasi_pada->translatedFormat('j M') : '' }} · {{ $d->keterangan }}{{ $d->temuan ? ' · FLAG dikonfirmasi: '.$d->konfirmasi : '' }}">
                            <td>@if ($bolehRealisasi && $d->status === 'menunggu' && ! $siap)<span class="redup" title="Pengajuan belum ditransfer dari Kas Harian / belum ditandai transfer — belum bisa direalisasikan">🔒</span>@endif
                                @if ($bolehRealisasi && $siap && $d->status === 'menunggu')<input type="checkbox" class="pilih-real" value="{{ $d->id }}" data-pj="{{ $p->id }}" data-nominal="{{ (int) $d->nominal - (int) $d->saldo }}"
                                data-nama="{{ $d->nama }}" title="Pilih untuk direalisasikan bersama (satu transfer)">@endif</td>
                            <td></td>
                            <td>{{ $d->no_mobil ?: '–' }}</td>
                            <td>{{ $d->nama }}</td>
                            <td>{{ $d->kategori }}</td>
                            <td>{{ $d->no_do ?: '–' }}</td>
                            <td class="angka" data-n="{{ (int) $d->nominal }}" data-nilai="{{ rp($d->nominal) }}">{{ rp($d->nominal) }}@if ($d->saldo)<div style="font-size: 11px; color: #93c5fd;" title="Dibayar dari saldo sopir (UJ yang dialihkan) — tidak ditransfer">💰 saldo {{ rp($d->saldo) }} · transfer {{ rp($d->nominal - $d->saldo) }}</div>@endif</td>
                            <td style="font-size: 12px; white-space: nowrap;" class="redup" data-nilai="{{ $teksStatus }}">{{ $teksStatus }}{{ $d->id_uj ? ' · '.$d->id_uj : '' }}</td>
                            <td></td><td></td>
                            <td style="font-size: 12px; min-width: 180px; white-space: normal;">{{ $d->keterangan }}</td>
                            <td></td>
                        </tr>
                        @if ($d->berbeda() && $d->realisasi)
                            @php($r = $d->realisasi)
                            <tr class="realisasi"><td></td><td colspan="11">
                                <div @class(['info-beda', 'dialihkan' => $d->jenis_realisasi === 'dialihkan'])>
                                    <span @class(['label', 'ungu' => $d->jenis_realisasi === 'dialihkan', 'kuning' => $d->jenis_realisasi !== 'dialihkan'])>{{ $d->jenis_realisasi === 'dialihkan' ? '↪ Dialihkan' : '≠ Penyesuaian' }}</span>
                                    <b>Realisasi {{ $r['id_uj'] ?? $d->id_uj }}:</b> {{ $r['keterangan'] ?? '' }} · {{ $r['kategori'] ?? '' }}@if (! empty($r['no_mobil'])) · {{ $r['no_mobil'] }}@endif @if (! empty($r['no_do'])) · DO {{ $r['no_do'] }}@endif · <b>{{ rp((int) ($r['nominal'] ?? 0)) }}</b>
                                    @if ((int) ($r['nominal'] ?? 0) !== (int) $d->nominal)<span class="redup">(selisih {{ ((int) $r['nominal'] - (int) $d->nominal) > 0 ? '+' : '−' }}{{ rp(abs((int) $r['nominal'] - (int) $d->nominal)) }})</span>@endif
                                    <br>Perbedaan: @foreach ($r['beda'] ?? [] as $b){{ $b['kolom'] }} <del>{{ $b['lama'] }}</del> → <b>{{ $b['baru'] }}</b>@if (! $loop->last); @endif @endforeach
                                    <br>Alasan: <i>{{ $d->alasan }}</i>@if ($d->jenis_realisasi === 'dialihkan' && $d->no_do) <span class="redup">· DO {{ $d->no_do }} tetap dihitung sudah dibiayai</span>@endif
                                </div>
                            </td></tr>
                        @endif
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="12" class="redup" style="padding: 20px 14px;">{{ $beda ? 'Belum ada realisasi yang tidak sesuai dengan pengajuannya.' : 'Belum ada pengajuan uang jalan.' }}</td></tr></tbody>
            @endforelse
        </table>
    </div>
    <div class="bar-bawah">
    {{-- Pilih beberapa pengajuan → satu transfer Kas Harian untuk semuanya (Super Admin). --}}
    @if ($bolehTautkan)
    <div class="bar-realisasi" id="bar-transfer" hidden>
        <b id="jumlah-pilih-tf">0 pengajuan dipilih</b>
        <span class="redup" id="ringkas-pilih-tf"></span>
        <span style="margin-left: auto; display: flex; gap: 8px;">
            <button type="button" class="tombol polos" id="batal-pilih-tf">Batal pilih</button>
            <button type="button" class="tombol polos" id="buka-tanda" title="Tandai pengajuan terpilih sebagai dibayar dengan transfer yang baru Anda kirim (sebelum ada di Kas Harian)">🏷 Tandai transfer</button>
            <button type="button" class="tombol" id="buka-transfer" title="Pilih sendiri transaksi Kas Harian untuk pengajuan terpilih (tanpa penanda)">🔗 Tautkan manual</button>
        </span>
    </div>
    @endif
    @if ($bolehRealisasi)
        {{-- Pilih beberapa detail (boleh lintas pengajuan & driver) → satu transfer: Input UJ terisi semua detail itu, admin tinggal isi rekening penerima. --}}
        <div class="bar-realisasi" id="bar-realisasi" hidden>
            <b id="jumlah-pilih">0 transaksi dipilih</b>
            <span class="redup" id="ringkas-pilih"></span>
            <span style="margin-left: auto; display: flex; gap: 8px;">
                <button type="button" class="tombol polos" id="batal-pilih">Batal pilih</button>
                <a href="#" class="tombol" id="realisasi-pilih">➜ Realisasikan transaksi terpilih</a>
            </span>
        </div>
    @endif
    </div>
    {{-- Pilih transfer Kas Harian yang membiayai pengajuan-pengajuan terpilih. --}}
    @if ($bolehTautkan)
    <dialog id="dialog-tanda" style="width: min(560px, 94vw); padding: 0; border: 1px solid var(--garis); border-radius: 12px; background: var(--kartu); color: var(--teks);">
        <form method="POST" action="{{ route('pengajuan-uj.tanda-simpan') }}" style="padding: 14px 16px; display: flex; flex-direction: column; gap: 10px;">
            @csrf
            <span id="pj-tanda"></span>
            <b>🏷 Tandai transfer</b>
            <div class="redup" style="font-size: 13px;" id="info-tanda"></div>
            <div><b style="font-size: 13px;">Acuan transfer — pilih batch Reimburse UJ</b>
                <div style="max-height: 230px; overflow: auto; border: 1px solid var(--garis); border-radius: 6px; margin-top: 4px;">
                    @foreach ($reimburseUj as $r)
                        <label style="display: flex; gap: 8px; align-items: center; padding: 6px 8px; border-bottom: 1px solid var(--garis); cursor: pointer; margin: 0; font-weight: 400;{{ $r['selesai'] ? ' opacity: .55;' : '' }}">
                            <input type="radio" name="reimburse" value="{{ $r['id'] }}" data-total="{{ $r['total'] }}" data-waktu="{{ $r['waktu'] }}" data-dipakai="{{ $r['dipakai'] }}" data-selesai="{{ $r['selesai'] ? 1 : 0 }}">
                            <span style="flex: 1; font-size: 13px;"><b>Reimburse UJ #{{ $r['id'] }}</b> · {{ $r['tanggal'] }} <span class="redup">· dibuat {{ $r['jam'] }}</span>
                                @if ($r['pj'])<br><span class="redup" style="font-size: 11.5px;">sudah ditandai: {{ implode(', ', $r['pj']) }} ({{ rp($r['dipakai']) }}) · sisa {{ rp($r['total'] - $r['dipakai']) }}{{ $r['selesai'] ? ' · sudah ditautkan ke Kas Harian' : '' }}</span>@endif</span>
                            <b style="white-space: nowrap;">{{ rp($r['total']) }}</b>
                        </label>
                    @endforeach
                    <label style="display: flex; gap: 8px; align-items: center; padding: 6px 8px; cursor: pointer; margin: 0; font-weight: 400; font-size: 13px;">
                        <input type="radio" name="reimburse" value="" data-manual> ✍ Tanpa batch reimburse — isi nominal &amp; jam transfer sendiri</label>
                </div>
            </div>
            <label>Nominal yang ditransfer<input type="text" name="nominal" id="nominal-tanda" inputmode="numeric" autocomplete="off" required style="width: 100%;"></label>
            <label>Tanggal &amp; jam transfer<input type="datetime-local" name="waktu" id="waktu-tanda" required style="width: 100%;"></label>
            <label>Catatan <span class="redup">(opsional)</span><input type="text" name="catatan" maxlength="300" autocomplete="off" style="width: 100%;" placeholder="mis. transfer BCA ke Eddy"></label>
            <div id="selisih-tanda" style="font-size: 13px;"></div>
            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <button type="button" class="tombol polos" id="tutup-tanda">Batal</button>
                <button type="submit" class="tombol">Simpan penanda</button>
            </div>
        </form>
    </dialog>
    {{-- Transfer (Kas Harian) dari penanda: tiap penanda dipasangkan ke transaksi Kas Harian bernominal sama (usulan otomatis, bisa diganti). --}}
    <dialog id="dialog-tanda-tf" style="width: min(1000px, 96vw); max-height: 86vh; padding: 0; border: 1px solid var(--garis); border-radius: 12px; background: var(--kartu); color: var(--teks);">
        <form method="POST" action="{{ route('pengajuan-uj.tanda-tautkan') }}" id="form-tanda-tf" style="display: flex; flex-direction: column; max-height: 86vh;">
            @csrf
            <div style="padding: 12px 16px; border-bottom: 1px solid var(--garis); display: flex; gap: 12px; align-items: center;">
                <b>🔗 Transfer (Kas Harian) untuk transfer yang sudah ditandai</b>
                <button type="button" class="tombol polos" id="tutup-tanda-tf" style="margin-left: auto;">Tutup</button>
            </div>
            <div style="flex: 1; overflow: auto; padding: 8px 16px;">
                @forelse ($tandaKandidat as $x)
                    @php($t = $x['tanda'])
                    <div style="border: 1px solid var(--garis); border-radius: 8px; padding: 8px 10px; margin: 8px 0;">
                        <div><b>{{ $t->kode() }}</b>@if ($t->uj_reimburse_id) <span class="redup">· Reimburse UJ #{{ $t->uj_reimburse_id }}</span>@endif · ditransfer {{ $t->waktu->translatedFormat('j M Y H:i') }} · <b>{{ rp($t->nominal) }}</b>
                            <span class="redup" style="font-size: 12px;">· {{ $t->pengajuan->map->kode()->implode(', ') }} ({{ rp((int) $t->pengajuan->sum('nominal')) }})</span></div>
                        @if (! $x['calon'])
                            <div style="color: #f0c05a; font-size: 13px; margin-top: 4px;">Belum ada transaksi Kas Harian {{ rp($t->nominal) }} di sekitar {{ $t->waktu->translatedFormat('j M') }} — mungkin Kas Harian belum direkap. Penanda ini dilewati.</div>
                        @else
                            <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 4px;">
                                @foreach ($x['calon'] as $c)
                                    <tr style="border-top: 1px solid var(--garis);{{ $c['dipakai'] ? ' opacity: .55;' : '' }}">
                                        <td style="width: 28px; padding: 4px;"><input type="radio" name="pasangan[{{ $t->id }}]" value="{{ $c['no_id'] }}" @checked($x['usul'] === $c['no_id'])></td>
                                        <td style="padding: 4px; white-space: nowrap;">{{ $c['tgl'] }}</td><td style="padding: 4px;">{{ $c['id_kas'] }}</td>
                                        <td style="padding: 4px;">{{ $c['nama'] }}</td><td style="padding: 4px;">{{ $c['keterangan'] }}</td>
                                        <td style="padding: 4px; text-align: right;"><b>{{ rp($c['nominal']) }}</b></td>
                                        <td style="padding: 4px;" class="redup">{{ $c['dipakai'] ? 'sudah tertaut ke '.implode(', ', $c['dipakai']) : '' }}</td>
                                    </tr>
                                @endforeach
                                <tr style="border-top: 1px solid var(--garis);"><td style="padding: 4px;"><input type="radio" name="pasangan[{{ $t->id }}]" value="" @checked(! $x['usul'])></td>
                                    <td colspan="6" style="padding: 4px;" class="redup">Lewati dulu (tautkan nanti)</td></tr>
                            </table>
                        @endif
                    </div>
                @empty
                    <p class="redup">Belum ada transfer yang ditandai. Centang pengajuan yang Anda bayar lalu klik <b>🏷 Tandai transfer</b>; untuk memilih transaksi Kas Harian sendiri, centang pengajuannya lalu <b>🔗 Tautkan manual</b>.</p>
                @endforelse
            </div>
            <div style="padding: 10px 16px; border-top: 1px solid var(--garis); display: flex; gap: 12px; align-items: center;">
                <span class="redup" style="font-size: 12px;">Usulan otomatis: nominal sama, penanda paling awal dipasangkan ke transaksi paling awal. Periksa bila ada beberapa transfer bernominal sama.</span>
                <button type="submit" class="tombol" style="margin-left: auto;" @disabled(! collect($tandaKandidat)->contains(fn ($x) => $x['calon']))>🔗 Tautkan yang dipilih</button>
            </div>
        </form>
    </dialog>
    <dialog id="dialog-transfer">
        <form method="POST" id="form-transfer" action="{{ route('pengajuan-uj.transfer-tautkan') }}">
            @csrf
            <span id="pj-dt"></span>
            <input type="hidden" name="tanda" id="tanda-dt">
            <div class="kepala-dt">
                <b id="judul-dt">Pilih transfer Kas Harian</b>
                <input type="search" id="cari-dt" placeholder="Cari keterangan, tujuan, NO ID atau nominal (kosong = sekitar tanggal pengajuan)" autocomplete="off">
                <button type="button" class="tombol polos" id="tutup-dt">Tutup</button>
            </div>
            <div class="isi-dt"><table data-tanpa-saring-kolom data-tanpa-beku data-tanpa-navigasi>
                <thead><tr><th style="width: 30px;"></th><th>Tanggal</th><th>ID Kas Harian</th><th>Tujuan</th><th>Keterangan</th><th class="angka">Nominal</th><th>Sudah ditautkan ke</th></tr></thead>
                <tbody id="daftar-dt"></tbody>
            </table></div>
            <div class="kaki-dt">
                <span id="info-dt" class="redup"></span>
                <button type="submit" class="tombol" id="simpan-dt" style="margin-left: auto;" disabled>🔗 Tautkan transfer terpilih</button>
            </div>
        </form>
    </dialog>
    @endif
    <script>
        (() => {
            const dlg = document.getElementById('dialog-transfer');
            if (!dlg) return;
            const fmtRp = n => 'Rp ' + new Intl.NumberFormat('id-ID').format(n || 0);
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const daftar = document.getElementById('daftar-dt');
            const cari = document.getElementById('cari-dt');
            const info = document.getElementById('info-dt');
            const simpan = document.getElementById('simpan-dt');
            let aktif = null, data = [], jeda = null;
            const hitung = () => {
                const pilih = [...daftar.querySelectorAll('input:checked')].map(c => data.find(t => t.no_id === +c.value));
                daftar.querySelectorAll('tr').forEach(tr => tr.classList.toggle('dipilih', !!tr.querySelector('input:checked')));
                const total = pilih.reduce((s, t) => s + t.nominal, 0) + aktif.ditransfer;
                info.textContent = `${pilih.length} transfer dipilih · ${fmtRp(pilih.reduce((s, t) => s + t.nominal, 0))} — pengajuan ${fmtRp(aktif.nominal)}`
                    + (aktif.ditransfer ? `, sudah tertaut ${fmtRp(aktif.ditransfer)}` : '')
                    + (pilih.length ? (total === aktif.nominal ? ' · ✓ pas' : total < aktif.nominal ? ` · kurang ${fmtRp(aktif.nominal - total)}` : ` · lebih ${fmtRp(total - aktif.nominal)}`) : '');
                simpan.disabled = !pilih.length;
            };
            const muat = async () => {
                daftar.innerHTML = '<tr><td colspan="7" class="redup">Memuat…</td></tr>';
                const q = cari.value.trim();
                data = (await (await fetch(aktif.url + (q ? '&q=' + encodeURIComponent(q) : ''), {headers: {Accept: 'application/json'}})).json()).transfer;
                daftar.innerHTML = data.length ? data.map(t => `<tr class="${t.terpasang ? 'terpasang' : ''}">
                    <td>${t.terpasang ? '✓' : `<input type="checkbox" name="no_id[]" value="${t.no_id}">`}</td>
                    <td>${esc(t.tgl)}</td><td>${esc(t.id_kas)}</td><td>${esc(t.nama || '')}${t.bank ? ' <span class="redup">· ' + esc(t.bank) + '</span>' : ''}</td>
                    <td>${esc(t.keterangan || '')}</td><td class="angka"><b>${fmtRp(t.nominal).replace('Rp ', '')}</b></td>
                    <td class="redup">${esc(t.dipakai.join(', '))}</td></tr>`).join('')
                    : `<tr><td colspan="7" class="redup">${q ? 'Tidak ada transaksi Kas Harian yang cocok.' : 'Tidak ada transaksi keluar Kas Harian di sekitar tanggal pengajuan — coba cari.'}</td></tr>`;
                daftar.querySelectorAll('tr').forEach(tr => tr.addEventListener('click', e => {
                    const c = tr.querySelector('input'); if (!c) return;
                    if (!e.target.matches('input')) c.checked = !c.checked;
                    hitung();
                }));
                hitung();
            };
            // Pilih beberapa pengajuan (kolom Transfer) → tombol "Transfer (Kas Harian)" → satu/lebih transfer untuk semuanya.
            const pilihTf = [...document.querySelectorAll('input.pilih-tf')];
            const barTf = document.getElementById('bar-transfer');
            const terpilihTf = () => pilihTf.filter(c => c.checked);
            const hitungPilihTf = () => {
                const p = terpilihTf();
                pilihTf.forEach(c => c.closest('tr').classList.toggle('dipilih-tf', c.checked));
                barTf.hidden = !p.length;
                document.getElementById('jumlah-pilih-tf').textContent = `${p.length} pengajuan dipilih · ${fmtRp(p.reduce((s, c) => s + +c.dataset.nominal, 0))}`;
                document.getElementById('ringkas-pilih-tf').textContent = p.map(c => c.dataset.kode).join(', ');
            };
            pilihTf.forEach(c => c.addEventListener('change', hitungPilihTf));
            pilihTf.forEach(c => c.closest('label').addEventListener('click', e => e.stopPropagation()));
            document.getElementById('batal-pilih-tf').addEventListener('click', () => { pilihTf.forEach(c => c.checked = false); hitungPilihTf(); });
            // p = [{value, kode, nominal, tf: "no:nominal,…"}]; tanda = penanda transfer yang sedang ditautkan (opsional)
            const bukaDialog = (p, tanda = null, cariAwal = '') => {
                if (!p.length) return;
                // Transfer yang sudah tertaut ke pengajuan terpilih (tiap NO ID sekali).
                const tertaut = new Map();
                p.forEach(c => (c.tf || '').split(',').filter(Boolean).forEach(x => { const [no, nom] = x.split(':'); tertaut.set(no, +nom); }));
                aktif = {url: @json(route('pengajuan-uj.transfer-kandidat')) + '?pj=' + p.map(c => c.value).join(','),
                    nominal: p.reduce((s, c) => s + +c.nominal, 0), ditransfer: [...tertaut.values()].reduce((s, n) => s + n, 0)};
                document.getElementById('pj-dt').innerHTML = p.map(c => `<input type="hidden" name="pj[]" value="${esc(c.value)}">`).join('');
                document.getElementById('tanda-dt').value = tanda ?? '';
                document.getElementById('judul-dt').textContent = `Transfer Kas Harian untuk ${p.length > 1 ? p.length + ' pengajuan: ' : ''}${p.map(c => c.kode).join(', ')} (${fmtRp(aktif.nominal)})`;
                cari.value = cariAwal;
                dlg.showModal();
                muat();
            };
            const dariCentang = c => ({value: c.value, kode: c.dataset.kode, nominal: +c.dataset.nominal, tf: c.dataset.tf, tanggal: c.dataset.tanggal});
            document.getElementById('buka-transfer').addEventListener('click', () => bukaDialog(terpilihTf().map(dariCentang)));
            // Penanda transfer → tautkan: pengajuan & nominal penanda langsung terisi (transaksi Kas Harian dicari dari nominalnya).
            document.querySelectorAll('[data-tautkan-tanda]').forEach(b => b.addEventListener('click', () =>
                bukaDialog(JSON.parse(b.dataset.pjTanda), b.dataset.tautkanTanda, b.dataset.nominalTanda)));
            // Tandai transfer: pengajuan terpilih + nominal & jam transfer (bawaan: total pengajuan terpilih, sekarang).
            const dlgTanda = document.getElementById('dialog-tanda');
            const nomTanda = document.getElementById('nominal-tanda');
            let pilihTanda = [];
            const waktuTanda = document.getElementById('waktu-tanda');
            const rbTerpilih = () => dlgTanda.querySelector('input[name=reimburse]:checked');
            const hitungTanda = () => {
                const rb = rbTerpilih(), pakaiRb = rb && rb.value ? +rb.dataset.dipakai : 0;
                // Batch Reimburse UJ = acuan: nominal & jam transfer mengikuti batch (tidak diketik)
                [nomTanda, waktuTanda].forEach(el => el.readOnly = !!(rb && rb.value));
                if (rb && rb.value) { nomTanda.value = rb.dataset.total; waktuTanda.value = rb.dataset.waktu; }
                const tot = pilihTanda.reduce((s, c) => s + c.nominal, 0) + pakaiRb, n = parseInt(nomTanda.value.replace(/\D/g, ''), 10) || 0;
                nomTanda.value = n ? new Intl.NumberFormat('id-ID').format(n) : '';
                const sel = n - tot;
                document.getElementById('selisih-tanda').innerHTML = !n ? '' : (pakaiRb ? `<span class="redup">termasuk yang sudah ditandai di batch ini ${fmtRp(pakaiRb)} · </span>` : '')
                    + (sel === 0 ? '<span class="label hijau">✓ pas dengan total pengajuan</span>'
                    : `<span class="label kuning">${sel > 0 ? 'sisa' : 'kurang'} ${fmtRp(Math.abs(sel))}</span> <span class="redup">dari total pengajuan ${fmtRp(tot)}</span>`);
            };
            nomTanda.addEventListener('input', hitungTanda);
            dlgTanda.querySelectorAll('input[name=reimburse]').forEach(r => r.addEventListener('change', hitungTanda));
            document.getElementById('buka-tanda').addEventListener('click', () => {
                pilihTanda = terpilihTf().map(dariCentang).sort((a, b) => +a.value - +b.value);
                if (!pilihTanda.length) return;
                document.getElementById('pj-tanda').innerHTML = pilihTanda.map(c => `<input type="hidden" name="pj[]" value="${esc(c.value)}">`).join('');
                document.getElementById('info-tanda').textContent = `${pilihTanda.length} pengajuan: ${pilihTanda.map(c => c.kode + ' (' + fmtRp(c.nominal) + ')').join(', ')} · total ${fmtRp(pilihTanda.reduce((s, c) => s + c.nominal, 0))}`;
                nomTanda.value = String(pilihTanda.reduce((s, c) => s + c.nominal, 0));
                const k = new Date(); k.setMinutes(k.getMinutes() - k.getTimezoneOffset());
                waktuTanda.value = k.toISOString().slice(0, 16);
                // bawaan: batch Reimburse UJ terbaru yang belum ditautkan ke Kas Harian; bila tidak ada → isi manual
                const bawaan = dlgTanda.querySelector('input[name=reimburse][data-selesai="0"]') ?? dlgTanda.querySelector('input[data-manual]');
                bawaan.checked = true;
                hitungTanda();
                dlgTanda.showModal();
                nomTanda.focus(); nomTanda.select();
            });
            document.getElementById('tutup-tanda').addEventListener('click', () => dlgTanda.close());
            // Transfer (Kas Harian) dari penanda
            const dlgTandaTf = document.getElementById('dialog-tanda-tf');
            document.getElementById('buka-tanda-tf')?.addEventListener('click', () => dlgTandaTf.showModal());
            document.getElementById('tutup-tanda-tf').addEventListener('click', () => dlgTandaTf.close());
            document.getElementById('form-tanda-tf').addEventListener('submit', e => {
                const no = [...e.target.querySelectorAll('input[type=radio]:checked')].map(r => r.value).filter(Boolean);
                if (!no.length) { e.preventDefault(); alert('Belum ada transaksi Kas Harian yang dipilih.'); return; }
                if (new Set(no).size !== no.length) { e.preventDefault(); alert('Satu transaksi Kas Harian dipilih untuk lebih dari satu penanda — pilih transaksi yang berbeda.'); }
            });
            hitungPilihTf();
            cari.addEventListener('input', () => { clearTimeout(jeda); jeda = setTimeout(muat, 350); });
            cari.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(jeda); muat(); } });
            document.getElementById('tutup-dt').addEventListener('click', () => dlg.close());
        })();
    </script>
    <script>
        (() => {
            document.querySelectorAll('tbody.grup tr.t').forEach(tr => tr.addEventListener('click', e => {
                if (e.target.closest('button, a, form, input')) return;
                tr.parentElement.classList.toggle('buka');
            }));
            // Expand / collapse all: semua pengajuan yang sedang tampil (hasil saringan kolom grid).
            const grupTampil = () => [...document.querySelectorAll('tbody.grup')].filter(tb => tb.style.display !== 'none');
            document.getElementById('buka-semua-pj')?.addEventListener('click', () => grupTampil().forEach(tb => tb.classList.add('buka')));
            document.getElementById('tutup-semua-pj')?.addEventListener('click', () => grupTampil().forEach(tb => tb.classList.remove('buka')));
            const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);

            const bar = document.getElementById('bar-realisasi');
            if (!bar) return;
            const semua = [...document.querySelectorAll('input.pilih-real')];
            // Tampil = tidak disembunyikan saringan kolom grid (baris detail & pengajuannya); pengajuan yang tertutup tetap dihitung.
            const terlihat = c => { const tr = c.closest('tr'); return tr.style.display !== 'none' && tr.closest('tbody')?.style.display !== 'none'; };
            const dasar = @json(route('uj.input'));
            const hitung = () => {
                const pilih = semua.filter(c => c.checked);
                semua.forEach(c => c.closest('tr').classList.toggle('dipilih', c.checked));
                document.querySelectorAll('input.pilih-semua-pj').forEach(m => {
                    const anak = semua.filter(c => c.dataset.pj === m.dataset.pj && terlihat(c));
                    m.checked = anak.length > 0 && anak.every(c => c.checked); m.indeterminate = !m.checked && anak.some(c => c.checked);
                });
                bar.hidden = !pilih.length;
                const driver = [...new Set(pilih.map(c => c.dataset.nama).filter(Boolean))];
                document.getElementById('jumlah-pilih').textContent = `${pilih.length} transaksi dipilih · Rp ${fmt(pilih.reduce((s, c) => s + +c.dataset.nominal, 0))}`;
                document.getElementById('ringkas-pilih').textContent = driver.length ? `driver: ${driver.join(', ')}` : '';
                const tombol = document.getElementById('realisasi-pilih');
                tombol.href = dasar + '?pengajuan=' + pilih.map(c => c.value).join(',');
                // Semua transaksi terpilih dibayar dalam satu kali transfer ke satu rekening penerima.
                tombol.textContent = `➜ Realisasikan ${pilih.length} transaksi dalam 1 kali transfer`;
                const tampil = semua.filter(terlihat), semuaTampil = document.getElementById('pilih-tampil');
                if (semuaTampil) { semuaTampil.checked = tampil.length > 0 && tampil.every(c => c.checked); semuaTampil.indeterminate = !semuaTampil.checked && tampil.some(c => c.checked); }
            };
            // Saringan kolom grid berubah (saring-kolom.js mengirim event input dari baris saringan) → hitung ulang centang.
            document.querySelector('table.kas')?.addEventListener('input', e => { if (e.target.closest?.('tr.saring-kolom')) hitung(); });
            // Centang semua transaksi menunggu yang sedang tampil (hasil saringan).
            document.getElementById('pilih-tampil')?.addEventListener('change', e => { semua.filter(terlihat).forEach(c => c.checked = e.target.checked); hitung(); });
            semua.forEach(c => c.addEventListener('change', hitung));
            document.querySelectorAll('input.pilih-semua-pj').forEach(m => m.addEventListener('change', () => {
                semua.filter(c => c.dataset.pj === m.dataset.pj && terlihat(c)).forEach(c => c.checked = m.checked); // hanya yang tampil
                hitung();
            }));
            document.getElementById('batal-pilih').addEventListener('click', () => { semua.forEach(c => c.checked = false); hitung(); });
            hitung();
        })();
    </script>
@endsection
