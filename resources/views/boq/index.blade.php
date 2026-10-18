@extends('layouts.app', ['judul' => 'BOQ & Progres Proyek'])

@section('lebar', '1800px')

@section('isi')
    @php
        $f = fn ($v, $d = 0) => $v === null ? '' : (rtrim(rtrim(number_format($v, $d, ',', '.'), '0'), ',') ?: '0');
        $pr = fn ($v) => $v === null ? '–' : number_format($v, 1, ',', '.').'%';
        $warna = fn ($v) => $v === null ? '' : ($v > 100 ? 'color: var(--merah);' : '');
        $bolehPilah = auth()->user()->bolehMenu('pilah-transaksi');
        // biaya belum ber-BOQ (tahap sendiri + bagian tahap umum) dibagi ke item per bobot Total Harga — sementara, sebelum dirapikan
        $lainTot = ($bagi ?? false) && $lain ? $lain['total'] : 0;
        $kol = $lainTot ? 15 : 14;
    @endphp
    <style>
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 19px; margin-top: 2px; }
        .ringkas small { font-size: 12px; color: var(--redup); }
        table.kas input { padding: 3px 6px; font-size: 12.5px; width: 100%; box-sizing: border-box; }
        table.kas input.angka { text-align: right; }
        table.kas td.p { white-space: nowrap; font-size: 12.5px; }
        .bar { height: 5px; background: var(--garis); border-radius: 3px; margin-top: 3px; overflow: hidden; }
        .bar > span.isi { display: block; height: 100%; background: var(--sukses); }
        .bar.modal > span.isi { background: #f0c05a; }
        .bar.tagih > span.isi { background: #60a5fa; }
        table.kas th.sempit, table.kas td.sempit { width: 1%; white-space: nowrap; }
        table.kas td.kosong { color: var(--redup); font-size: 11.5px; }
        table.kas tr.ok-simpan td { transition: background .6s; background: rgba(76, 195, 138, .12); }
        tr.sub td { background: rgba(255, 255, 255, .03); font-weight: 600; }
        /* klik baris item → rincian pengeluaran di bawahnya */
        table.kas tr.ada-trx { cursor: pointer; }
        table.kas tr.ada-trx .panah { display: inline-block; width: 12px; color: var(--redup); transition: transform .15s; }
        table.kas tr.ada-trx.buka .panah { transform: rotate(90deg); }
        table.kas .jml-trx { font-size: 10.5px; color: var(--redup); font-weight: 400; }
        table.kas tr.d { display: none; }
        table.kas tr.d.buka { display: table-row; }
        table.kas tr.d > td { background: rgba(0, 0, 0, .18); padding: 8px 14px 10px 34px; }
        table.rinci-trx { border-collapse: collapse; font-size: 12.5px; width: 100%; max-width: 1200px; }
        table.rinci-trx th, table.rinci-trx td { padding: 3px 10px 3px 0; border-bottom: 1px solid var(--garis); text-align: left; vertical-align: top; }
        table.rinci-trx .angka { text-align: right; white-space: nowrap; }
        table.rinci-trx tfoot td { font-weight: 700; border-bottom: 0; }
    </style>

    <form method="GET" class="kartu" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <label class="redup" style="font-size: 13px;">Tahap</label>
        <select name="tahap" onchange="this.form.submit()" style="min-width: 320px;">
            @foreach ($daftar as $t)<option value="{{ $t->id }}" @selected($tahap?->id === $t->id)>{{ $t->nama }} · {{ \App\Models\ProyekTahap::STATUS[$t->status] }}</option>@endforeach
        </select>
        @if ($tahap)
            <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('boq.index', ['tahap' => $tahap->id, 'ubah' => $ubah ? 0 : 1]) }}">{{ $ubah ? '← Selesai ubah' : '✎ Ubah item BOQ' }}</a>
            <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('pilah.index', ['saring' => 'tanpa-boq']) }}">Pilah transaksi →</a>
            @if (Route::has('anggaran.edit'))<a class="tombol polos" style="padding: 6px 12px;" href="{{ route('anggaran.edit', $tahap) }}">Anggaran →</a>@endif
        @endif
        <span class="redup" style="font-size: 12px; margin-left: auto;">Daftar: tahap tipe Infra & tahap yang sudah punya BOQ</span>
    </form>

    @if (! $tahap)
        <div class="kartu redup">Belum ada tahap tipe Infra. Ubah tipe tahap di Daftar Proyek menjadi "Infra".</div>
    @else
        @if ($p['baris']->isEmpty() || $ubah)
            <details class="kartu" @if ($p['baris']->isEmpty()) open @endif>
                <summary style="cursor: pointer;"><b>Impor item dari sheet penawaran</b> <span class="redup" style="font-size: 12px;">(format lembar "Monitoring": kolom A kode, B uraian, C sat, D volume, E harga, K modal material, N jasa borongan)</span></summary>
                <form method="POST" action="{{ route('boq.impor', $tahap) }}" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;">@csrf
                    <input name="link" value="{{ old('link', str_contains($tahap->nama, 'Regatta') ? 'https://docs.google.com/spreadsheets/d/'.config('mnp.sheet_infra') : '') }}" placeholder="Link / ID Google Sheet" style="min-width: 420px;" required>
                    <input name="lembar" value="{{ old('lembar', 'Monitoring') }}" placeholder="Nama lembar" style="width: 160px;" required>
                    <button class="tombol" type="submit">Impor</button>
                    <span class="redup" style="font-size: 12px;">Kode yang sudah ada diperbarui, kode baru ditambah.</span>
                </form>
            </details>
        @endif

        <div class="ringkas">
            <div class="kartu"><small>Nilai kontrak (BOQ)</small><b>{{ rp((int) round($p['nilai'])) }}</b><small>{{ $p['baris']->count() }} item</small></div>
            <div class="kartu"><small>Modal (material + jasa)</small><b>{{ rp((int) round($p['modal'])) }}</b><small>rencana GP {{ $p['nilai'] > 0 ? $pr(($p['nilai'] - $p['modal']) / $p['nilai'] * 100) : '–' }}</small></div>
            <div class="kartu"><small>Biaya keluar (dihitung)</small><b>{{ rp((int) round($p['biaya'])) }}</b><small>tanpa kode BOQ: {{ rp((int) round($p['biaya_tanpa'])) }} ({{ $p['n_tanpa'] }} transaksi)</small></div>
            @php($pmTot = $lainTot && $p['modal'] > 0 ? ($p['biaya_boq'] + $lainTot) / $p['modal'] * 100 : $p['p_modal'])
            <div class="kartu"><small>Progres modal ({{ $lainTot ? 'semua biaya termasuk bagian biaya lain' : 'biaya' }} ÷ modal)</small><b style="{{ $warna($pmTot) }}">{{ $pr($pmTot) }}</b><div class="bar modal"><span class="isi" style="width: {{ min(100, $pmTot ?? 0) }}%"></span></div>@if ($lainTot)<small>{{ rp((int) round($p['biaya_boq'] + $lainTot)) }}</small>@endif</div>
            <div class="kartu"><small>Progres terpasang (volume, bobot nilai)</small><b>{{ $pr($p['p_pasang']) }}</b><div class="bar"><span class="isi" style="width: {{ min(100, $p['p_pasang'] ?? 0) }}%"></span></div></div>
            <div class="kartu"><small>Progres tertagih</small><b>{{ $pr($p['p_tagih']) }}</b><div class="bar tagih"><span class="isi" style="width: {{ min(100, $p['p_tagih'] ?? 0) }}%"></span></div><small>{{ rp((int) round($p['rp_tagih'])) }}</small></div>
        </div>

        @if ($lain && $lain['total'] > 0 && ! $ubah)
            <details class="kartu" @if ($lainTot) open @endif>
                <summary style="cursor: pointer;"><b>Biaya belum masuk item BOQ: {{ rp((int) round($lain['total'])) }}</b>
                    <span class="redup" style="font-size: 12px;">{{ $lainTot ? '— sementara DIBAGI ke item menurut bobot Total Harga (kolom "Bagian biaya lain")' : '— tidak dibagi' }}</span>
                    <a href="{{ route('boq.index', ['tahap' => $tahap->id, 'bagi' => $lainTot ? 0 : 1]) }}" class="tombol polos" style="padding: 3px 10px; font-size: 12px; margin-left: 8px;">{{ $lainTot ? 'Tampilkan hanya biaya ber-BOQ' : 'Bagi ke item per bobot' }}</a></summary>
                <table class="kas" style="margin-top: 8px; max-width: 900px;" data-tanpa-saring-kolom>
                    <tbody>
                        @foreach ($lain['sendiri'] as [$nm, $v])
                            <tr><td>{{ $tahap->nama }} — {{ $nm }}</td><td class="angka">{{ rp((int) round($v)) }}</td></tr>
                        @endforeach
                        @foreach ($lain['umum'] as $u)
                            <tr><td>Bagian dari tahap umum <b>{{ $u['tahap'] }}</b>: {{ rp((int) round($u['total'])) }} × {{ number_format($u['porsi'] * 100, 2, ',', '.') }}% <span class="redup">(porsi nilai kontrak di proyek induk)</span></td><td class="angka">{{ rp((int) round($u['rp'])) }}</td></tr>
                        @endforeach
                        <tr class="sub"><td>Total</td><td class="angka">{{ rp((int) round($lain['total'])) }}</td></tr>
                    </tbody>
                </table>
                <p class="redup" style="font-size: 12px; margin: 6px 0 0;">Sementara sebelum biaya dirapikan per kode BOQ di Pilah Transaksi. Begitu transaksinya diberi kode BOQ, biayanya pindah ke kolom "Biaya keluar" item itu.</p>
            </details>
        @endif

        @if ($ubah)
            <form method="POST" action="{{ route('boq.simpan', $tahap) }}" class="kartu gulir" style="padding: 0;">@csrf
                <table class="kas" id="tabel-ubah" data-tanpa-saring-kolom>
                    <thead><tr><th style="width: 70px;">Kode</th><th>Uraian</th><th style="width: 110px;">Volume</th><th style="width: 70px;">Satuan</th><th style="width: 130px;">Harga Satuan</th>
                        <th style="width: 140px;">Modal material (total)</th><th style="width: 140px;">Modal jasa (total)</th><th style="width: 90px;">Transaksi</th><th style="width: 40px;"></th></tr></thead>
                    <tbody>
                        @foreach ($p['baris'] as $i => $b)
                            @php($it = $b->item)
                            <tr>
                                <td><input type="hidden" name="item[{{ $i }}][id]" value="{{ $it->id }}"><input name="item[{{ $i }}][kode]" value="{{ $it->kode }}" required></td>
                                <td><input name="item[{{ $i }}][uraian]" value="{{ $it->uraian }}" required></td>
                                <td><input class="angka" name="item[{{ $i }}][volume]" value="{{ $it->volume }}" inputmode="decimal"></td>
                                <td><input name="item[{{ $i }}][satuan]" value="{{ $it->satuan }}"></td>
                                @foreach (['harga', 'modal_material', 'modal_jasa'] as $k)<td><input class="angka" name="item[{{ $i }}][{{ $k }}]" value="{{ $it->$k }}" inputmode="decimal"></td>@endforeach
                                <td class="redup" style="font-size: 12px;">{{ $nTag[$it->id] ?? 0 }} bertag</td>
                                <td><button type="button" class="tombol polos hapus" style="padding: 2px 8px;" title="Hapus item (kode BOQ transaksinya ikut lepas)">✕</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div style="display: flex; gap: 8px; padding: 10px;">
                    <button type="button" class="tombol polos" id="tambah">+ Tambah item</button>
                    <button type="submit" class="tombol">Simpan BOQ</button>
                    <span class="redup" style="font-size: 12px;">Modal = total rupiah item (bukan per satuan). Angka pakai titik desimal (mis. 1075.7).</span>
                </div>
            </form>
            <script>
                (() => {
                    const tb = document.querySelector('#tabel-ubah tbody');
                    let n = tb.rows.length;
                    tb.addEventListener('click', e => { if (e.target.classList.contains('hapus') && confirm('Hapus item ini?')) e.target.closest('tr').remove(); });
                    document.getElementById('tambah').addEventListener('click', () => {
                        const i = n++, tr = document.createElement('tr');
                        tr.innerHTML = `<td><input name="item[${i}][kode]" required></td><td><input name="item[${i}][uraian]" required></td><td><input class="angka" name="item[${i}][volume]" inputmode="decimal"></td><td><input name="item[${i}][satuan]"></td>`
                            + ['harga', 'modal_material', 'modal_jasa'].map(k => `<td><input class="angka" name="item[${i}][${k}]" inputmode="decimal"></td>`).join('')
                            + '<td></td><td><button type="button" class="tombol polos hapus" style="padding: 2px 8px;">✕</button></td>';
                        tb.appendChild(tr); tr.querySelector('input').focus();
                    });
                })();
            </script>
        @else
            <div class="kartu gulir" style="padding: 0;">
                <table class="kas" data-saring-kolom>
                    <thead><tr><th class="sempit" data-saring-sempit>Kode</th><th>Uraian</th><th class="angka">Volume</th><th class="sempit" data-saring-sempit>Satuan</th><th class="angka">Harga Satuan</th><th class="angka" title="Volume × Harga Satuan">Total Harga</th><th class="angka">Bobot</th>
                        <th class="angka">Modal</th><th class="angka">Biaya keluar</th>@if ($lainTot)<th class="angka" title="Biaya belum masuk item BOQ × bobot Total Harga">Bagian biaya lain</th>@endif<th style="width: 110px;">% modal</th>
                        <th style="width: 120px;">Terpasang (kumulatif)</th><th style="width: 110px;">% terpasang</th><th class="angka">Tertagih</th><th style="width: 110px;">% tertagih</th></tr></thead>
                    <tbody>
                        @foreach ($p['baris'] as $b)
                            @php($it = $b->item)
                            @php($bag = $lainTot * ($b->bobot ?? 0) / 100)
                            @php($pm = $b->modal > 0 ? ($b->biaya + $bag) / $b->modal * 100 : null)
                            @php($buka = $b->trx || $bag >= 1)
                            <tr data-item="{{ $it->id }}" @if ($buka) class="ada-trx" title="Klik untuk melihat {{ $b->trx ? count($b->trx).' transaksi pengeluaran '.$it->kode : 'asal bagian biaya lain '.$it->kode }}" @endif>
                                <td class="sempit" data-v="{{ $it->kode }}">@if ($buka)<span class="panah">▸</span>@endif<b>{{ $it->kode }}</b>@if ($b->trx)<div class="jml-trx">{{ count($b->trx) }} trx</div>@elseif ($bag >= 1)<div class="jml-trx">bagian</div>@endif</td>
                                <td>{{ $it->uraian }}</td>
                                <td class="angka" data-n="{{ $it->volume }}">{{ $f($it->volume, 2) }}</td>
                                <td class="sempit">{{ $it->satuan }}</td>
                                <td class="angka" data-n="{{ $it->harga }}">{{ $it->harga ? rp((int) round($it->harga)) : '' }}</td>
                                <td class="angka" data-n="{{ $b->nilai }}">{{ $b->nilai ? rp((int) round($b->nilai)) : '' }}</td>
                                <td class="angka" data-n="{{ $b->bobot }}">{{ $b->bobot ? $pr($b->bobot) : '' }}</td>
                                <td class="angka" data-n="{{ $b->modal }}">{{ $b->modal ? rp((int) round($b->modal)) : '' }}</td>
                                <td @class(['angka', 'kosong' => ! $b->biaya]) data-n="{{ $b->biaya }}" @if (! $b->biaya) title="Belum ada transaksi Kas/BCA yang diberi kode {{ $it->kode }} di Pilah Transaksi" @endif>{{ $b->biaya ? rp((int) round($b->biaya)) : '–' }}</td>
                                @if ($lainTot)<td class="angka" data-n="{{ $bag }}">{{ $bag >= 1 ? rp((int) round($bag)) : '' }}</td>@endif
                                <td class="p" data-n="{{ $pm }}"><span style="{{ $warna($pm) }}">{{ $pm !== null ? $pr($pm) : ($b->biaya + $bag >= 1 ? 'tanpa modal' : '') }}</span>@if ($pm !== null)<div class="bar modal"><span class="isi" style="width: {{ min(100, $pm) }}%"></span></div>@endif</td>
                                <td data-n="{{ $b->terpasang }}"><input class="angka" data-f="pasang" value="{{ $b->opname !== null ? $f($b->opname, 3) : '' }}" placeholder="{{ $b->tertagih ? $f($b->tertagih, 3) : '0' }}" inputmode="decimal" title="Volume terpasang s/d hari ini ({{ $it->satuan }}) — tersimpan sebagai opname hari ini">@if ($b->tgl_opname)<span class="redup" style="font-size: 10.5px;" data-tgl>opname {{ $b->tgl_opname->translatedFormat('d-M-y') }}</span>@else<span class="redup" style="font-size: 10.5px;" data-tgl></span>@endif</td>
                                <td class="p" data-n="{{ $b->p_pasang }}"><span data-persen @if ($b->p_pasang === null) class="redup" title="Isi kolom Terpasang (kumulatif) untuk mencatat opname" @elseif ($b->pasang_dari === 'tagihan') title="Belum ada opname — terpasang minimal = volume yang sudah ditagih" @endif>{{ $b->p_pasang === null ? 'belum opname' : $pr($b->p_pasang) }}</span>@if ($b->pasang_dari === 'tagihan')<span class="redup" style="font-size: 10.5px;"> dari tagihan</span>@endif<div class="bar"><span class="isi" style="width: {{ min(100, $b->p_pasang ?? 0) }}%"></span></div></td>
                                <td class="angka" data-n="{{ $b->tertagih }}">{{ $b->tertagih ? $f($b->tertagih, 3) : '' }}@if ($b->rp_tagih)<br><span class="redup" style="font-size: 11px;">{{ rp((int) round($b->rp_tagih)) }}</span>@endif</td>
                                <td class="p" data-n="{{ $b->p_tagih }}">{{ $pr($b->p_tagih) }}<div class="bar tagih"><span class="isi" style="width: {{ min(100, $b->p_tagih ?? 0) }}%"></span></div></td>
                            </tr>
                            @if ($buka)
                                <tr class="d"><td colspan="{{ $kol }}">
                                    @if ($b->trx)
                                        @include('boq._trx', ['trx' => $b->trx, 'bolehPilah' => $bolehPilah])
                                    @else
                                        <div class="redup" style="font-size: 12.5px; margin-bottom: 4px;">Belum ada transaksi yang diberi kode <b>{{ $it->kode }}</b>.</div>
                                    @endif
                                    @if ($bag >= 1)
                                        @include('boq._bagian', ['lain' => $lain, 'bobot' => $b->bobot, 'bag' => $bag, 'kode' => $it->kode, 'nama' => $tahap->nama])
                                    @endif
                                </td></tr>
                            @endif
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="sub"><td colspan="5">Total</td><td class="angka">{{ rp((int) round($p['nilai'])) }}</td><td class="angka">100%</td><td class="angka">{{ rp((int) round($p['modal'])) }}</td>
                            <td class="angka">{{ rp((int) round($p['biaya_boq'])) }}</td>@if ($lainTot)<td class="angka">{{ rp((int) round($lainTot)) }}</td>@endif<td class="p">{{ $p['modal'] > 0 ? $pr(($p['biaya_boq'] + $lainTot) / $p['modal'] * 100) : '–' }}</td><td></td><td class="p">{{ $pr($p['p_pasang']) }}</td><td class="angka">{{ rp((int) round($p['rp_tagih'])) }}</td><td class="p">{{ $pr($p['p_tagih']) }}</td></tr>
                        @if ($p['biaya_tanpa'] > 0)
                            <tr class="ada-trx" title="Klik untuk melihat transaksinya"><td colspan="8" class="redup"><span class="panah">▸</span> Biaya dihitung yang <b>belum diberi kode BOQ</b> ({{ $p['n_tanpa'] }} transaksi — gaji, sewa, umum, atau belum dipilah) · <a href="{{ route('pilah.index', ['saring' => 'tanpa-boq']) }}">pilah →</a></td>
                                <td class="angka">{{ rp((int) round($p['biaya_tanpa'])) }}</td><td colspan="{{ $lainTot ? 6 : 5 }}"></td></tr>
                            <tr class="d"><td colspan="{{ $kol }}">@include('boq._trx', ['trx' => $p['trx_tanpa'], 'bolehPilah' => $bolehPilah])</td></tr>
                        @endif
                    </tfoot>
                </table>
            </div>
            <p class="redup" style="font-size: 12px;">% modal &gt; 100% (merah) = biaya item sudah melewati modalnya. Terpasang diisi kumulatif (total terpasang sampai hari ini) dan tersimpan sebagai opname bertanggal hari ini; selama opname lebih kecil / belum ada, terpasang = volume yang sudah ditagih (abu-abu di kotak = volume tertagih).
                Klik baris item (▸) untuk melihat transaksi pengeluarannya. Biaya keluar = transaksi Kas & BCA yang dihitung untuk tahap ini dan diberi kode BOQ di <a href="{{ route('pilah.index') }}">Pilah Transaksi</a>.</p>

            <h3 style="margin: 18px 0 8px;">Tagihan progres</h3>
            <div class="kartu gulir" style="padding: 0;">
                <table class="kas" data-tanpa-saring-kolom>
                    <thead><tr><th>Tanggal</th><th>Nomor</th><th>Catatan</th><th class="angka">Item</th><th class="angka">Nilai</th><th class="angka">% kontrak</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($tagihan as $tg)
                            @php($nilaiTg = $tg->item->sum('nilai'))
                            <tr><td>{{ $tg->tanggal->translatedFormat('d-M-Y') }}</td><td><b>{{ $tg->nomor }}</b></td><td>{{ $tg->catatan }}</td><td class="angka">{{ $tg->item->count() }}</td>
                                <td class="angka">{{ rp((int) round($nilaiTg)) }}</td><td class="angka">{{ $p['nilai'] > 0 ? $pr($nilaiTg / $p['nilai'] * 100) : '–' }}</td>
                                <td><form method="POST" action="{{ route('boq.tagihan.hapus', $tg) }}" onsubmit="return confirm('Hapus tagihan {{ $tg->nomor }}?')">@csrf @method('DELETE')<button class="tombol polos" style="padding: 2px 8px;">Hapus</button></form></td></tr>
                        @empty
                            <tr><td colspan="7" class="redup">Belum ada tagihan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($p['baris']->isNotEmpty())
                <details class="kartu" @if ($errors->any()) open @endif>
                    <summary style="cursor: pointer;"><b>+ Tagihan progres baru</b> <span class="redup" style="font-size: 12px;">volume awal = terpasang − sudah ditagih</span></summary>
                    <form method="POST" action="{{ route('boq.tagihan', $tahap) }}" style="margin-top: 10px;">@csrf
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px;">
                            <input name="nomor" value="{{ old('nomor') }}" placeholder="Nomor tagihan / invoice" required>
                            <input type="date" name="tanggal" value="{{ old('tanggal', now()->toDateString()) }}" required>
                            <input name="catatan" value="{{ old('catatan') }}" placeholder="Catatan (opsional)" style="min-width: 300px;">
                        </div>
                        <table class="kas" data-tanpa-saring-kolom>
                            <thead><tr><th>Kode</th><th>Uraian</th><th class="angka">Volume BOQ</th><th class="angka">Terpasang</th><th class="angka">Sudah ditagih</th><th style="width: 130px;">Ditagih sekarang</th><th class="angka">Harga Satuan</th></tr></thead>
                            <tbody>
                                @foreach ($p['baris'] as $b)
                                    @php($sisa = max(0, ($b->terpasang ?? 0) - $b->tertagih))
                                    <tr><td>{{ $b->item->kode }}</td><td>{{ $b->item->uraian }}</td><td class="angka">{{ $f($b->item->volume, 2) }} {{ $b->item->satuan }}</td><td class="angka">{{ $f($b->terpasang, 3) }}</td>
                                        <td class="angka">{{ $f($b->tertagih, 3) }}</td><td><input class="angka" name="volume[{{ $b->item->id }}]" value="{{ old('volume.'.$b->item->id, $sisa > 0 ? round($sisa, 3) : '') }}" inputmode="decimal"></td>
                                        <td class="angka">{{ $b->item->harga ? rp((int) round($b->item->harga)) : '' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                        <button class="tombol" type="submit" style="margin-top: 8px;">Simpan tagihan</button>
                    </form>
                </details>
            @endif

            <script>
                (() => {
                    // klik baris item (bukan kotak isian / tautan) → buka/tutup rincian pengeluaran di bawahnya
                    document.querySelectorAll('table.kas tr.ada-trx').forEach(tr => tr.addEventListener('click', e => {
                        if (e.target.closest('input, a, button')) return;
                        const d = tr.nextElementSibling;
                        if (!d?.classList.contains('d')) return;
                        tr.classList.toggle('buka'); d.classList.toggle('buka', tr.classList.contains('buka'));
                    }));
                    const TOKEN = @json(csrf_token()), URL = @json(route('boq.opname', ['item' => '__ID__']));
                    document.querySelectorAll('tr[data-item] [data-f=pasang]').forEach(inp => {
                        let awal = inp.value;
                        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
                        inp.addEventListener('blur', async () => {
                            const v = inp.value.trim().replace(/\./g, '').replace(',', '.');
                            if (inp.value.trim() === awal.trim() || v === '' || isNaN(+v)) return;
                            const tr = inp.closest('tr'), data = new FormData();
                            data.append('_token', TOKEN); data.append('volume', v);
                            try {
                                const res = await fetch(URL.replace('__ID__', tr.dataset.item), {method: 'POST', body: data, headers: {Accept: 'application/json'}});
                                const h = await res.json().catch(() => ({}));
                                if (!res.ok) throw new Error(h.message || 'Gagal menyimpan');
                                awal = inp.value; tr.classList.add('ok-simpan');
                                tr.querySelector('[data-persen]').classList.remove('redup'); tr.querySelector('[data-persen]').textContent = h.persen === null ? '–' : h.persen.toLocaleString('id-ID') + '%';
                                tr.querySelector('.bar:not(.modal):not(.tagih) > span.isi').style.width = Math.min(100, h.persen || 0) + '%';
                                tr.querySelector('[data-tgl]').textContent = 'opname ' + h.tanggal;
                            } catch (err) { alert(err.message); }
                        });
                    });
                })();
            </script>
        @endif
    @endif
@endsection
