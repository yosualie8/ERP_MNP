@extends('layouts.app', ['judul' => 'Rekap Absen & Gaji Mingguan'])

@section('lebar', '1500px')

@section('isi')
    @php
        $minggu = $hari->last();
        $pendek = fn (?string $t) => $t ? mb_strimwidth(preg_replace(['/^ASG Tahap /i', '/^Pantai Mutiara 2 - /i', '/^Proyek Industri /i'], ['T', 'PM2 ', ''], $t), 0, 14, '…') : null;
        $pilihan = fn ($v) => $v === null ? 'auto' : ($v ? '1' : '0');
    @endphp
    <style>
        .ringkas-gaji { display: grid; grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas-gaji .kartu { margin: 0; padding: 10px 14px; }
        .ringkas-gaji b { display: block; font-size: 21px; }
        .grid-absen td.hr { text-align: center; white-space: nowrap; font-size: 12px; cursor: pointer; min-width: 70px; }
        .grid-absen td.hr:hover { outline: 1px solid var(--aksen); outline-offset: -1px; }
        .grid-absen td.hr .s { font-weight: 700; font-size: 13px; }
        .grid-absen td.hr .t { display: block; font-size: 10.5px; color: var(--redup); }
        .grid-absen td.hr .hm { display: block; font-size: 10.5px; color: #8ab4f8; }
        .grid-absen td.hr .ub { display: block; font-size: 10.5px; color: #f0a35a; font-weight: 600; }
        .grid-absen td.hr.bayar .s { color: var(--sukses); }
        .grid-absen td.hr.tunggu { background: rgba(240, 192, 90, .16); }
        .grid-absen td.hr.tunggu .s { color: #f0c05a; }
        .grid-absen td.hr.tolak .s { color: var(--merah); }
        .grid-absen td.hr.kosong .s { color: var(--redup); font-weight: 400; }
        .grid-absen td.um { cursor: pointer; }
        .grid-absen td.um:hover { outline: 1px solid var(--aksen); outline-offset: -1px; }
        .grid-absen td.um.sendiri, .grid-absen td.tj.sendiri { color: #8ab4f8; }
        .grid-absen td.tj { cursor: pointer; }
        .grid-absen td.tj:hover { outline: 1px solid var(--aksen); outline-offset: -1px; }
        .grid-absen tr.tanpa td { opacity: .5; }
        .legenda-absen { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12.5px; color: var(--redup); margin: 0 0 10px; }
        .legenda-absen b { font-size: 13px; }
        dialog.atur-hari { border: 1px solid var(--garis-kuat); border-radius: 10px; background: var(--kartu); color: var(--teks); width: min(440px, 94vw); padding: 16px; }
        dialog.atur-hari::backdrop { background: rgba(0, 0, 0, .55); }
        dialog.atur-hari label.baris { display: block; margin: 10px 0 4px; font-weight: 600; font-size: 13px; }
        dialog.atur-hari .pilih3 { display: flex; gap: 6px; flex-wrap: wrap; }
        dialog.atur-hari .pilih3 label { display: inline-flex; gap: 5px; align-items: center; padding: 4px 10px; border: 1px solid var(--garis); border-radius: 999px; font-size: 13px; cursor: pointer; margin: 0; }
        dialog.atur-hari select, dialog.atur-hari input[type=text] { width: 100%; box-sizing: border-box; }
        dialog.atur-hari .blok-jam { margin-top: 10px; padding: 8px 10px 10px; border: 1px solid var(--garis); border-radius: 8px; }
        dialog.atur-hari .blok-jam > label.baris { margin-top: 0; }
        dialog.atur-hari .kisi-jam { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
        dialog.atur-hari .kisi-jam label { display: flex; flex-direction: column; gap: 3px; font-size: 12.5px; color: var(--redup); }
        dialog.atur-hari .kisi-jam input { width: 100%; box-sizing: border-box; }
        dialog.atur-hari .kisi-jam input.diedit { border-color: #f0a35a; }
        dialog.atur-hari .kisi-jam small { font-size: 11.5px; min-height: 14px; }
        dialog.atur-hari .kisi-jam small.diedit { color: #f0a35a; }
    </style>

    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('gaji-mingguan.index', ['minggu' => $senin->copy()->subWeek()->toDateString()]) }}">‹</a>
        <input type="text" name="minggu" data-tanggal-id value="{{ $senin->toDateString() }}" style="width: 150px;" onchange="this.form.submit()">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('gaji-mingguan.index', ['minggu' => $senin->copy()->addWeek()->toDateString()]) }}">›</a>
        <b>{{ $senin->translatedFormat('j M') }} – {{ $minggu->translatedFormat('j M Y') }}</b>
        @if ($ringkas['tunggu'])
            <button type="submit" form="form-setujui" class="tombol polos" style="margin-left: auto;"
                onclick="return confirm('Setujui (bayar) semua {{ $ringkas['tunggu'] }} absen / lembur yang belum lengkap minggu ini? Pastikan sudah diperiksa.')">✔ Setujui semua yang belum lengkap ({{ $ringkas['tunggu'] }})</button>
        @endif
        <a class="tombol polos" style="padding: 6px 12px; {{ $ringkas['tunggu'] ? '' : 'margin-left: auto;' }}" href="{{ route('gaji-mingguan.unduh', ['minggu' => $senin->toDateString()]) }}">⬇ Excel</a>
    </form>
    <form method="POST" action="{{ route('gaji-mingguan.setujui') }}" id="form-setujui" hidden>@csrf<input type="hidden" name="minggu" value="{{ $senin->toDateString() }}"></form>

    <div class="ringkas-gaji">
        <div class="kartu"><span class="redup">Karyawan dibayar</span><b>{{ $ringkas['orang'] }}</b></div>
        <div class="kartu"><span class="redup">Hari kerja dibayar</span><b>{{ $ringkas['hari'] }}</b></div>
        <div class="kartu"><span class="redup">UM</span><b>{{ rp($ringkas['total_um']) }}</b>
            @if ($ringkas['bayar_diubah'])<span style="font-size: 12px; color: #f0a35a;">{{ $ringkas['bayar_diubah'] }} hari UM + lembur diubah admin</span>@endif</div>
        <div class="kartu"><span class="redup">Lembur ({{ $ringkas['lembur'] }} × ½ UM)</span><b>{{ rp($ringkas['total_lembur']) }}</b></div>
        @if ($ringkas['operator'])
            <div class="kartu"><span class="redup">Operator: basic + transport + kesehatan</span><b>{{ rp($ringkas['total_mingguan']) }}</b>
                <span class="redup" style="font-size: 12px;">{{ $ringkas['operator'] }} operator</span></div>
            <div class="kartu"><span class="redup">Uang HM ({{ str_replace('.', ',', $ringkas['jam_hm']) }} jam)</span><b>{{ rp($ringkas['total_hm']) }}</b>
                @if ($ringkas['hm_tak_dibayar'])<span style="font-size: 12px; color: var(--merah);">{{ $ringkas['hm_tak_dibayar'] }} hari ber-HM tidak dibayar</span>@endif</div>
        @endif
        <div class="kartu"><span class="redup">Total dibayar</span><b>{{ rp($ringkas['total']) }}</b></div>
        <div class="kartu"><span class="redup">Menunggu persetujuan</span><b @if ($ringkas['tunggu']) style="color: #f0c05a;" @endif>{{ $ringkas['tunggu'] }}</b>
            <span class="redup" style="font-size: 12px;">absen / lembur belum lengkap</span></div>
        <div class="kartu"><span class="redup">Hadir tanpa UM</span><b @if ($ringkas['tanpa_um']) style="color: var(--merah);" @endif>{{ $ringkas['tanpa_um'] }}</b>
            <span class="redup" style="font-size: 12px;">klik kolom UM / isi Master Jabatan</span></div>
    </div>

    <div class="legenda-absen">
        <span><b style="color: var(--sukses);">✓</b> absen lengkap · dibayar</span>
        <span><b style="color: var(--sukses);">A</b> disetujui admin</span>
        <span><b style="color: var(--sukses);">M</b> diisi mandor</span>
        <span><b style="color: #f0c05a;">?</b> absen belum lengkap — menunggu persetujuan</span>
        <span><b style="color: var(--merah);">✗</b> tidak dibayar</span>
        <span><b>+L</b> lembur ½ UM (<b>+L?</b> menunggu)</span>
        <span><b style="color: #8ab4f8;">7 HM</b> jam kerja alat operator (× tarif HM jabatan)</span>
        <span><b style="color: #f0a35a;">Rp75.000</b> pembayaran UM + lembur hari itu diubah admin (arahkan kursor untuk melihat alasan)</span>
        <span><b class="tanda-edit" style="margin: 0;">✎</b> jam absen dikoreksi admin (arahkan kursor / klik sel untuk melihat jam asli)</span>
        <span>Klik sel hari untuk menyetujui / menolak / mengubah pembayaran hari itu / mengisi tahap / HM · klik UM untuk mengubah UM orang itu · klik Tunjangan mingguan operator untuk mengubahnya minggu ini (biru = diubah admin).</span>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas grid-absen">
            <thead><tr>
                <th>Karyawan</th><th>Jabatan</th><th>Site</th><th class="angka">UM / hari</th>
                @foreach ($hari as $h)<th style="text-align: center;">{{ $h->translatedFormat('D') }} {{ $h->format('j/n') }}</th>@endforeach
                <th class="angka">Hari</th><th class="angka">Lembur</th>
                <th class="angka" title="Operator: basic + transport + uang kesehatan per minggu (Master Jabatan)">Tunjangan mingguan</th><th class="angka">HM</th><th class="angka">Total</th>
            </tr></thead>
            <tbody>
                @forelse ($baris as $b)
                    <tr @class(['tanpa' => ! $b->ada])>
                        <td data-v="{{ $b->k->nama }}"><b>{{ $b->k->nama }}</b></td>
                        <td>{{ $b->k->jabatan }}</td>
                        <td>{{ $b->k->site }}</td>
                        <td @class(['angka', 'um', 'sendiri' => $b->um_sendiri]) data-n="{{ (int) $b->um }}" data-k="{{ $b->k->id }}" data-nama="{{ $b->k->nama }}"
                            data-um="{{ $b->k->um_harian }}" title="{{ $b->um_sendiri ? 'UM khusus orang ini' : 'UM dari jabatan' }} — klik untuk mengubah">{{ $b->um ? rp($b->um) : '–' }}</td>
                        @foreach ($b->hari as $d => $x)
                            @php
                                $kelas = $x->tunggu ? 'tunggu' : ($x->hadir ? 'bayar' : ($x->putus_hadir === false && ($x->absen || $x->sumber) ? 'tolak' : 'kosong'));
                                $simbol = match ($kelas) {
                                    'tunggu' => '?', 'tolak' => '✗', 'kosong' => '·',
                                    default => $x->sumber === 'mandor' && $x->putus_hadir ? 'M' : ($x->absen === 'lengkap' && $x->putus_hadir === null ? '✓' : 'A'),
                                };
                                // Jam yang dikoreksi admin ditandai ✎ (asli …) — owner 9 Nov 2026.
                                $tj = fn ($j) => ($y = $x->jam[$j] ?? null) ? $y['jam'].($y['diedit'] ? ' ✎(asli '.($y['asli'] ?? 'tidak ada').')' : '') : '–';
                                $info = collect([$x->masuk || $x->pulang ? 'masuk '.$tj('masuk').' · pulang '.$tj('pulang') : null,
                                    $x->lembur_mulai || $x->lembur_selesai ? 'lembur '.$tj('lembur_mulai').'–'.$tj('lembur_selesai') : null,
                                    $x->diedit ? 'jam dikoreksi admin' : null,
                                    $x->sumber === 'mandor' ? 'diisi mandor' : null,
                                    // Tukang tanpa absen HP: jam ikut mandor / pengawas tahapnya (owner 9 Nov 2026).
                                    $x->jam_mandor ? 'jam ikut '.$x->jam_mandor['nama'].': masuk '.($x->jam_mandor['masuk'] ?? '–').' · pulang '.($x->jam_mandor['pulang'] ?? '–') : null,
                                    // UM + lembur hari itu diubah admin + alasannya (owner 11 Nov 2026).
                                    $x->bayar_ubah !== null ? 'UM + lembur hari ini diubah '.rp($x->bayar_ubah).' (biasa '.rp($x->bayar_biasa).'): '.$x->bayar_alasan : null,
                                    $x->catatan])->filter()->implode(' · ');
                                $rincian = $x->hadir ? collect([($x->bayar_ubah !== null ? 'UM + lembur (diubah) ' : 'UM ').rp($x->bayar), $x->bayar_lembur ? 'lembur '.rp($x->bayar_lembur) : null, $x->bayar_hm ? 'HM '.rp($x->bayar_hm) : null])
                                    ->filter()->implode(' + ').($x->bayar_lembur || $x->bayar_hm ? ' = '.rp($x->bayar + $x->bayar_lembur + $x->bayar_hm) : '') : 'tidak dibayar';
                            @endphp
                            <td class="hr {{ $kelas }}" data-v="{{ $simbol }}" data-k="{{ $b->k->id }}" data-nama="{{ $b->k->nama }}" data-d="{{ $d }}"
                                data-label="{{ $x->tanggal->translatedFormat('l, j M') }}" data-hadir="{{ $pilihan($x->putus_hadir) }}" data-lembur="{{ $pilihan($x->putus_lembur) }}"
                                data-tahap="{{ $x->tahap_diisi ? $x->tahap_id : '' }}" data-tahap-tebak="{{ $x->tahap }}" data-catatan="{{ $x->catatan }}" data-info="{{ $info }}"
                                data-hm="{{ $x->hm }}" data-tarif-hm="{{ $b->tarif_hm }}" data-bayar="{{ $x->bayar_ubah }}" data-alasan="{{ $x->bayar_alasan }}"
                                data-biasa="{{ $x->bayar_biasa }}" data-rincian="{{ $rincian }}" data-jam="{{ json_encode($x->jam) }}" title="{{ $info }}{{ $x->tahap ? ' · '.$x->tahap : '' }}">
                                <span class="s">{{ $simbol }}{{ $x->lembur ? '+L' : ($x->tunggu_lembur ? '+L?' : '') }}@if ($x->diedit)<span class="tanda-edit" style="font-size: 11px;">✎</span>@endif</span>
                                @if ($x->hadir || $x->tunggu)<span class="t">{{ $pendek($x->tahap) ?? 'tahap ?' }}</span>@endif
                                @if ($x->hadir && $x->bayar_ubah !== null)<span class="ub">{{ rp($x->bayar_ubah) }}</span>@endif
                                @if ($x->hm)<span class="hm" @if (! $x->hadir) style="color: var(--merah);" title="HM tidak dibayar karena hari ini tidak dibayar" @endif>{{ str_replace('.', ',', $x->hm) }} HM</span>@endif
                            </td>
                        @endforeach
                        <td class="angka" data-n="{{ $b->hadir }}">{{ $b->hadir ?: '' }}</td>
                        <td class="angka" data-n="{{ $b->lembur }}">{{ $b->lembur ?: '' }}</td>
                        @if ($b->operator)
                            @php $tu = $b->tunjangan_ubah; @endphp
                            <td @class(['angka', 'tj', 'sendiri' => $tu]) data-n="{{ $b->total_mingguan }}" data-k="{{ $b->k->id }}" data-nama="{{ $b->k->nama }}"
                                @foreach (\App\Models\JabatanMenu::MINGGUAN as $kol => $l) data-{{ str_replace('_', '-', $kol) }}="{{ $tu?->{$kol} }}" data-bawaan-{{ str_replace('_', '-', $kol) }}="{{ $b->tunjangan_bawaan[$kol] }}" @endforeach
                                data-catatan="{{ $tu?->catatan }}"
                                title="{{ collect(\App\Models\JabatanMenu::MINGGUAN)->map(fn ($l, $kol) => $l.' '.rp($b->mingguan[$kol] ?? 0))->implode(' · ') }}{{ $tu ? ' — diubah admin'.($tu->catatan ? ': '.$tu->catatan : '') : '' }} — klik untuk mengubah">
                                {{ $b->total_mingguan ? rp($b->total_mingguan) : ($b->hadir ? '–' : '') }}</td>
                        @else
                            <td class="angka" data-n="0"></td>
                        @endif
                        <td class="angka" data-n="{{ $b->total_hm }}" @if ($b->tarif_hm) title="{{ str_replace('.', ',', $b->jam_hm) }} jam × {{ rp($b->tarif_hm) }}" @endif>
                            @if ($b->total_hm){{ rp($b->total_hm) }}<span class="redup" style="display: block; font-size: 11px;">{{ str_replace('.', ',', $b->jam_hm) }} jam</span>@endif</td>
                        <td class="angka" data-n="{{ $b->total }}"><b>{{ $b->total ? rp($b->total) : '' }}</b></td>
                    </tr>
                @empty
                    <tr><td colspan="16" class="redup" style="padding: 20px 14px;">Belum ada karyawan.</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr>
                <th colspan="11">Total</th><th class="angka">{{ $ringkas['hari'] }}</th><th class="angka">{{ $ringkas['lembur'] }}</th>
                <th class="angka">{{ $ringkas['total_mingguan'] ? rp($ringkas['total_mingguan']) : '' }}</th><th class="angka">{{ $ringkas['total_hm'] ? rp($ringkas['total_hm']) : '' }}</th><th class="angka">{{ rp($ringkas['total']) }}</th>
            </tr></tfoot>
        </table>
    </div>

    <div class="kartu gulir" style="padding: 0; max-width: 560px;">
        <div style="padding: 12px 14px 0;"><b>Gaji mingguan per tahap / proyek</b> <span class="redup" style="font-size: 12.5px;">— UM, lembur & HM ke tahap tempat bekerja hari itu; tunjangan mingguan operator dibagi rata ke hari kerjanya</span></div>
        <table class="kas">
            <thead><tr><th>Tahap / Proyek</th><th class="angka">Total</th></tr></thead>
            <tbody>
                @forelse ($perTahap as $t => $v)
                    <tr><td @if ($t === \App\Support\RekapAbsenMingguan::BELUM_TAHAP) style="color: var(--merah);" @endif>{{ $t }}</td><td class="angka" data-n="{{ $v }}">{{ rp($v) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="redup">Belum ada yang dibayar minggu ini.</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr><th>Total</th><th class="angka">{{ rp($perTahap->sum()) }}</th></tr></tfoot>
        </table>
    </div>

    <dialog class="atur-hari" id="atur-hari">
        <form method="dialog" id="form-hari">
            <b id="ah-judul"></b>
            <div class="redup" id="ah-info" style="font-size: 12.5px; margin-top: 4px;"></div>
            @if ($bolehJam)
                {{-- Koreksi jam absen (owner 9 Nov 2026): absen asli tetap tersimpan; jam yang diubah ditandai ✎. Kosongkan = kembali ke absen asli. --}}
                <div class="blok-jam">
                    <label class="baris">Jam absen <span class="redup" style="font-weight: 400;">— isi yang bolong / salah; kosongkan = kembali ke jam asli</span></label>
                    <div class="kisi-jam">
                        @foreach (\App\Models\Absensi::JENIS as $j => $l)
                            <label>{{ $l }}<input type="time" name="jam_{{ $j }}" data-jenis="{{ $j }}"><small id="ah-asli-{{ $j }}"></small></label>
                        @endforeach
                    </div>
                    <input type="text" name="jam_catatan" maxlength="200" placeholder="Alasan koreksi jam, mis. lupa absen pulang — konfirmasi mandor" style="margin-top: 6px;">
                </div>
            @else
                <div class="redup" id="ah-jam-info" style="font-size: 12.5px; margin-top: 6px;"></div>
            @endif
            <label class="baris">Hari ini dibayar?</label>
            <div class="pilih3">
                <label><input type="radio" name="hadir" value="auto"> Ikut absen</label>
                <label><input type="radio" name="hadir" value="1"> Bayar</label>
                <label><input type="radio" name="hadir" value="0"> Tidak dibayar</label>
            </div>
            {{-- Pembayaran hari ini (owner 11 Nov 2026): total UM + lembur khusus hari ini, wajib alasan; HM / tunjangan mingguan tetap ikut aturannya. --}}
            <div class="blok-jam">
                <label class="baris" for="ah-bayar">Pembayaran hari ini (UM + lembur) <span class="redup" id="ah-bayar-biasa" style="font-weight: 400;"></span></label>
                <input type="text" id="ah-bayar" name="bayar" inputmode="numeric" autocomplete="off" style="width: 100%; box-sizing: border-box;">
                <input type="text" id="ah-alasan" name="bayar_alasan" maxlength="300" placeholder="Alasan ubah pembayaran, mis. pulang siang — dibayar setengah" style="width: 100%; box-sizing: border-box; margin-top: 6px;">
                <div class="redup" id="ah-rincian" style="font-size: 12px; margin-top: 4px;"></div>
            </div>
            <label class="baris">Lembur (½ UM)?</label>
            <div class="pilih3">
                <label><input type="radio" name="lembur" value="auto"> Ikut absen</label>
                <label><input type="radio" name="lembur" value="1"> Bayar lembur</label>
                <label><input type="radio" name="lembur" value="0"> Tanpa lembur</label>
            </div>
            <div id="ah-blok-hm">
                <label class="baris" for="ah-hm">HM alat (jam kerja)</label>
                <input type="text" id="ah-hm" name="hm" inputmode="decimal" autocomplete="off" placeholder="mis. 7,5 — kosongkan bila tidak ada" style="width: 100%; box-sizing: border-box;">
                <div class="redup" id="ah-hm-info" style="font-size: 12px; margin-top: 3px;"></div>
            </div>
            <label class="baris" for="ah-tahap">Tahap kerja</label>
            <select id="ah-tahap" name="tahap">
                <option value="" id="ah-tahap-tebak">(otomatis)</option>
                @foreach ($tahap as $id => $nama)<option value="{{ $id }}">{{ $nama }}</option>@endforeach
            </select>
            <label style="display: flex; gap: 6px; align-items: center; font-size: 13px; margin-top: 6px;"><input type="checkbox" name="semua_minggu" value="1"> Pakai tahap ini untuk semua hari kerja orang ini minggu ini</label>
            <label class="baris" for="ah-catatan">Catatan</label>
            <input type="text" id="ah-catatan" name="catatan" maxlength="300" placeholder="mis. lupa absen pulang, sudah dicek mandor">
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px;">
                <button type="button" class="tombol polos" onclick="this.closest('dialog').close()">Batal</button>
                <button type="submit" class="tombol" id="ah-simpan">Simpan</button>
            </div>
        </form>
    </dialog>

    <dialog class="atur-hari" id="atur-tunjangan">
        <form method="dialog" id="form-tunjangan">
            <b id="tj-judul"></b>
            <div class="redup" style="font-size: 12.5px; margin-top: 4px;">Minggu {{ $senin->translatedFormat('j M') }} – {{ $minggu->translatedFormat('j M Y') }} · dibayar bila minggu ini ada hari kerja dibayar.
                Kosongkan isian = ikut tarif Master Jabatan; isi 0 = tidak dibayar.</div>
            @foreach (\App\Models\JabatanMenu::MINGGUAN as $kol => $l)
                <label class="baris" for="tj-{{ $kol }}">{{ $l }} <span class="redup" id="tj-bawaan-{{ $kol }}" style="font-weight: 400;"></span></label>
                <input type="text" id="tj-{{ $kol }}" name="{{ $kol }}" inputmode="numeric" autocomplete="off" class="rupiah-tj" style="width: 100%; box-sizing: border-box;">
            @endforeach
            <label class="baris" for="tj-catatan">Catatan</label>
            <input type="text" id="tj-catatan" name="catatan" maxlength="300" placeholder="mis. masuk 3 hari, dibayar setengah" style="width: 100%; box-sizing: border-box;">
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px;">
                <button type="button" class="tombol polos" onclick="this.closest('dialog').close()">Batal</button>
                <button type="submit" class="tombol" id="tj-simpan">Simpan</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const token = document.querySelector('meta[name=csrf-token]').content;
            const BOLEH_JAM = @json($bolehJam);
            const kirim = (url, data) => fetch(url, {method: 'POST', headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}, body: data})
                .then(async r => { if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || r.status); return r.json(); });
            // Muat ulang di posisi gulir yang sama.
            const muatUlang = () => { try { sessionStorage.setItem('gulir-gaji', String(scrollY)); } catch (e) {} location.reload(); };
            try { const y = sessionStorage.getItem('gulir-gaji'); if (y) { sessionStorage.removeItem('gulir-gaji'); addEventListener('load', () => scrollTo(0, +y)); } } catch (e) {}

            const dlg = document.getElementById('atur-hari'), form = document.getElementById('form-hari');
            let sel = null;
            // Tunjangan mingguan operator (basic / transport / kesehatan) per orang minggu ini.
            const dlgTj = document.getElementById('atur-tunjangan'), formTj = document.getElementById('form-tunjangan');
            const kolomTj = @json(array_keys(\App\Models\JabatanMenu::MINGGUAN));
            const kamel = s => s.replace(/[-_]([a-z])/g, (m, c) => c.toUpperCase());
            const rupiah = v => new Intl.NumberFormat('id-ID').format(+v);
            let selTj = null;
            formTj.querySelectorAll('.rupiah-tj').forEach(x => x.addEventListener('input', () => {
                const d = x.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
                x.value = d === '' ? '' : rupiah(d);
            }));
            formTj.addEventListener('submit', e => {
                e.preventDefault();
                const data = new FormData();
                data.append('karyawan_id', selTj.dataset.k);
                data.append('minggu', @json($senin->toDateString()));
                for (const kol of kolomTj) {
                    const d = formTj[kol].value.replace(/\D/g, '');
                    if (d !== '') data.append(kol, d);
                }
                data.append('catatan', formTj.catatan.value);
                document.getElementById('tj-simpan').disabled = true;
                kirim(@json(route('gaji-mingguan.tunjangan')), data).then(muatUlang)
                    .catch(err => { alert('Gagal menyimpan: ' + err.message); document.getElementById('tj-simpan').disabled = false; });
            });
            document.querySelector('.grid-absen').addEventListener('click', e => {
                const um = e.target.closest('td.um');
                if (um) {
                    const v = prompt(`UM per hari untuk ${um.dataset.nama} (kosongkan = ikut tarif jabatan):`, um.dataset.um || '');
                    if (v === null) return;
                    const data = new FormData();
                    data.append('karyawan_id', um.dataset.k);
                    data.append('um', v.replace(/\D/g, ''));
                    kirim(@json(route('gaji-mingguan.um')), data).then(muatUlang).catch(err => alert('Gagal menyimpan: ' + err.message));
                    return;
                }
                const tj = e.target.closest('td.tj');
                if (tj) {
                    selTj = tj;
                    document.getElementById('tj-judul').textContent = `Tunjangan mingguan — ${tj.dataset.nama}`;
                    for (const kol of kolomTj) {
                        const v = tj.dataset[kamel(kol)], bawaan = tj.dataset[kamel('bawaan-' + kol)];
                        formTj[kol].value = v === '' || v === undefined ? '' : rupiah(v);
                        formTj[kol].placeholder = bawaan ? rupiah(bawaan) + ' (tarif jabatan)' : '– (tarif jabatan kosong)';
                        document.getElementById('tj-bawaan-' + kol).textContent = bawaan ? `· tarif jabatan Rp${rupiah(bawaan)}` : '';
                    }
                    formTj.catatan.value = tj.dataset.catatan || '';
                    document.getElementById('tj-simpan').disabled = false;
                    dlgTj.showModal();
                    return;
                }
                const td = e.target.closest('td.hr');
                if (!td) return;
                sel = td;
                document.getElementById('ah-judul').textContent = `${td.dataset.nama} — ${td.dataset.label}`;
                document.getElementById('ah-info').textContent = td.dataset.info || 'Tidak ada absen hari ini.';
                form.hadir.value = td.dataset.hadir;
                form.lembur.value = td.dataset.lembur;
                document.getElementById('ah-tahap-tebak').textContent = '(otomatis' + (td.dataset.tahapTebak ? ': ' + td.dataset.tahapTebak : '') + ')';
                form.tahap.value = td.dataset.tahap;
                form.semua_minggu.checked = false;
                form.catatan.value = td.dataset.catatan || '';
                // HM hanya untuk jabatan bertarif HM (operator alat berat).
                const tarifHm = +td.dataset.tarifHm;
                document.getElementById('ah-blok-hm').hidden = !tarifHm && !td.dataset.hm;
                form.hm.value = td.dataset.hm ? td.dataset.hm.replace('.', ',') : '';
                // Total UM + lembur khusus hari ini + alasan.
                const biasa = +td.dataset.biasa;
                form.bayar.value = td.dataset.bayar === '' ? '' : rupiah(td.dataset.bayar);
                form.bayar.placeholder = biasa ? `Rp${rupiah(biasa)} (hitungan biasa) — kosongkan = hitungan biasa` : 'UM biasa kosong — isi nominal hari ini';
                form.bayar_alasan.value = td.dataset.alasan || '';
                form.bayar_alasan.required = td.dataset.bayar !== '';
                document.getElementById('ah-bayar-biasa').textContent = `— biasanya ${biasa ? 'Rp' + rupiah(biasa) : 'kosong'}; HM & tunjangan tetap terpisah`;
                document.getElementById('ah-rincian').textContent = 'Dibayar saat ini: ' + td.dataset.rincian;
                document.getElementById('ah-hm-info').textContent = tarifHm
                    ? `× Rp${new Intl.NumberFormat('id-ID').format(tarifHm)} per HM — dibayar bila hari ini dibayar.` : 'Jabatan ini tidak bertarif HM (Master Jabatan).';
                // Jam absen per jenis: jam berlaku + asli + penanda koreksi.
                const jam = JSON.parse(td.dataset.jam || '{}');
                if (BOLEH_JAM) {
                    form.querySelectorAll('.kisi-jam input').forEach(inp => {
                        const y = jam[inp.dataset.jenis], ket = document.getElementById('ah-asli-' + inp.dataset.jenis);
                        inp.value = y ? y.jam : '';
                        inp.dataset.awal = inp.value;
                        inp.classList.toggle('diedit', !!(y && y.diedit));
                        ket.className = y && y.diedit ? 'diedit' : '';
                        ket.textContent = !y ? 'tidak ada absen' : (y.diedit ? '✎ ' + (y.ket || ('asli ' + (y.asli || 'tidak ada'))) : 'asli');
                        ket.title = ket.textContent;
                    });
                    const catatanJam = Object.values(jam).find(y => y && y.diedit && y.catatan);
                    form.jam_catatan.value = catatanJam ? catatanJam.catatan : '';
                    form.jam_catatan.dataset.awal = form.jam_catatan.value;
                } else {
                    const edit = Object.values(jam).filter(y => y && y.diedit).map(y => '✎ ' + y.jam + ' — ' + y.ket);
                    document.getElementById('ah-jam-info').textContent = edit.join(' | ');
                }
                document.getElementById('ah-simpan').disabled = false;
                dlg.showModal();
            });
            // Isi nominal → hari ini dibayar (pilihan "Tidak dibayar" dipindah ke "Bayar").
            form.bayar.addEventListener('input', () => {
                const d = form.bayar.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
                form.bayar.value = d === '' ? '' : rupiah(d);
                if (d !== '' && form.hadir.value === '0') form.hadir.value = '1';
                form.bayar_alasan.required = d !== '';
            });
            form.addEventListener('submit', e => {
                e.preventDefault();
                const data = new FormData(form);
                data.set('hm', (data.get('hm') || '').trim().replace(',', '.'));
                data.set('bayar', (data.get('bayar') || '').replace(/\D/g, ''));
                if (data.get('bayar') === '') data.delete('bayar_alasan');
                data.append('karyawan_id', sel.dataset.k);
                data.append('tanggal', sel.dataset.d);
                ['jam_masuk', 'jam_pulang', 'jam_lembur_mulai', 'jam_lembur_selesai', 'jam_catatan'].forEach(n => data.delete(n));
                // Jam absen yang berubah dikirim dulu (koreksi), baru keputusan hari.
                let jamDulu = Promise.resolve();
                if (BOLEH_JAM) {
                    const semua = [...form.querySelectorAll('.kisi-jam input')];
                    const ubah = semua.filter(i => i.value !== i.dataset.awal);
                    const catatanUbah = form.jam_catatan.value !== form.jam_catatan.dataset.awal && semua.some(i => i.classList.contains('diedit'));
                    if (ubah.length || catatanUbah) {
                        const dj = new FormData();
                        dj.append('karyawan_id', sel.dataset.k);
                        dj.append('tanggal', sel.dataset.d);
                        (ubah.length ? ubah : semua.filter(i => i.classList.contains('diedit'))).forEach(i => dj.append('jam[' + i.dataset.jenis + ']', i.value));
                        dj.append('catatan', form.jam_catatan.value);
                        jamDulu = kirim(@json(route('absen-jam.simpan')), dj);
                    }
                }
                document.getElementById('ah-simpan').disabled = true;
                jamDulu.then(() => kirim(@json(route('gaji-mingguan.hari')), data)).then(muatUlang)
                    .catch(err => { alert('Gagal menyimpan: ' + err.message); document.getElementById('ah-simpan').disabled = false; });
            });
        })();
    </script>
@endsection
