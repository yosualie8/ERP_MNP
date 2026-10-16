@extends('layouts.app', ['judul' => 'BOQ & Progres Proyek'])

@section('lebar', '1800px')

@section('isi')
    @php
        $f = fn ($v, $d = 0) => $v === null ? '' : (rtrim(rtrim(number_format($v, $d, ',', '.'), '0'), ',') ?: '0');
        $pr = fn ($v) => $v === null ? '–' : number_format($v, 1, ',', '.').'%';
        $warna = fn ($v) => $v === null ? '' : ($v > 100 ? 'color: var(--merah);' : '');
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
        .bar > i { display: block; height: 100%; background: var(--sukses); }
        .bar.modal > i { background: #f0c05a; }
        .bar.tagih > i { background: #60a5fa; }
        table.kas tr.ok-simpan td { transition: background .6s; background: rgba(76, 195, 138, .12); }
        tr.sub td { background: rgba(255, 255, 255, .03); font-weight: 600; }
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
            <div class="kartu"><small>Progres modal (biaya ÷ modal)</small><b style="{{ $warna($p['p_modal']) }}">{{ $pr($p['p_modal']) }}</b><div class="bar modal"><i style="width: {{ min(100, $p['p_modal'] ?? 0) }}%"></i></div></div>
            <div class="kartu"><small>Progres terpasang (volume, bobot nilai)</small><b>{{ $pr($p['p_pasang']) }}</b><div class="bar"><i style="width: {{ min(100, $p['p_pasang'] ?? 0) }}%"></i></div></div>
            <div class="kartu"><small>Progres tertagih</small><b>{{ $pr($p['p_tagih']) }}</b><div class="bar tagih"><i style="width: {{ min(100, $p['p_tagih'] ?? 0) }}%"></i></div><small>{{ rp((int) round($p['rp_tagih'])) }}</small></div>
        </div>

        @if ($ubah)
            <form method="POST" action="{{ route('boq.simpan', $tahap) }}" class="kartu gulir" style="padding: 0;">@csrf
                <table class="kas" id="tabel-ubah" data-tanpa-saring-kolom>
                    <thead><tr><th style="width: 70px;">Kode</th><th>Uraian</th><th style="width: 70px;">Sat</th><th style="width: 110px;">Volume</th><th style="width: 130px;">Harga jual / sat</th>
                        <th style="width: 140px;">Modal material (total)</th><th style="width: 140px;">Modal jasa (total)</th><th style="width: 90px;">Transaksi</th><th style="width: 40px;"></th></tr></thead>
                    <tbody>
                        @foreach ($p['baris'] as $i => $b)
                            @php($it = $b->item)
                            <tr>
                                <td><input type="hidden" name="item[{{ $i }}][id]" value="{{ $it->id }}"><input name="item[{{ $i }}][kode]" value="{{ $it->kode }}" required></td>
                                <td><input name="item[{{ $i }}][uraian]" value="{{ $it->uraian }}" required></td>
                                <td><input name="item[{{ $i }}][satuan]" value="{{ $it->satuan }}"></td>
                                @foreach (['volume', 'harga', 'modal_material', 'modal_jasa'] as $k)<td><input class="angka" name="item[{{ $i }}][{{ $k }}]" value="{{ $it->$k }}" inputmode="decimal"></td>@endforeach
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
                        tr.innerHTML = `<td><input name="item[${i}][kode]" required></td><td><input name="item[${i}][uraian]" required></td><td><input name="item[${i}][satuan]"></td>`
                            + ['volume', 'harga', 'modal_material', 'modal_jasa'].map(k => `<td><input class="angka" name="item[${i}][${k}]" inputmode="decimal"></td>`).join('')
                            + '<td></td><td><button type="button" class="tombol polos hapus" style="padding: 2px 8px;">✕</button></td>';
                        tb.appendChild(tr); tr.querySelector('input').focus();
                    });
                })();
            </script>
        @else
            <div class="kartu gulir" style="padding: 0;">
                <table class="kas" data-saring-kolom>
                    <thead><tr><th>Kode</th><th>Uraian</th><th>Sat</th><th class="angka">Volume</th><th class="angka">Harga</th><th class="angka">Nilai jual</th><th class="angka">Bobot</th>
                        <th class="angka">Modal</th><th class="angka">Biaya keluar</th><th style="width: 110px;">% modal</th>
                        <th style="width: 120px;">Terpasang (kumulatif)</th><th style="width: 110px;">% terpasang</th><th class="angka">Tertagih</th><th style="width: 110px;">% tertagih</th></tr></thead>
                    <tbody>
                        @foreach ($p['baris'] as $b)
                            @php($it = $b->item)
                            <tr data-item="{{ $it->id }}">
                                <td><b>{{ $it->kode }}</b></td>
                                <td>{{ $it->uraian }}</td>
                                <td>{{ $it->satuan }}</td>
                                <td class="angka" data-n="{{ $it->volume }}">{{ $f($it->volume, 2) }}</td>
                                <td class="angka" data-n="{{ $it->harga }}">{{ $it->harga ? rp((int) round($it->harga)) : '' }}</td>
                                <td class="angka" data-n="{{ $b->nilai }}">{{ $b->nilai ? rp((int) round($b->nilai)) : '' }}</td>
                                <td class="angka" data-n="{{ $b->bobot }}">{{ $b->bobot ? $pr($b->bobot) : '' }}</td>
                                <td class="angka" data-n="{{ $b->modal }}">{{ $b->modal ? rp((int) round($b->modal)) : '' }}</td>
                                <td class="angka" data-n="{{ $b->biaya }}">{{ $b->biaya ? rp((int) round($b->biaya)) : '' }}</td>
                                <td class="p" data-n="{{ $b->p_modal }}"><span style="{{ $warna($b->p_modal) }}">{{ $b->p_modal !== null ? $pr($b->p_modal) : ($b->biaya ? 'tanpa modal' : '') }}</span>@if ($b->p_modal !== null)<div class="bar modal"><i style="width: {{ min(100, $b->p_modal) }}%"></i></div>@endif</td>
                                <td data-n="{{ $b->terpasang }}"><input class="angka" data-f="pasang" value="{{ $b->terpasang !== null ? $f($b->terpasang, 3) : '' }}" placeholder="0" inputmode="decimal" title="Volume terpasang s/d hari ini ({{ $it->satuan }}) — tersimpan sebagai opname hari ini">@if ($b->tgl_opname)<span class="redup" style="font-size: 10.5px;" data-tgl>opname {{ $b->tgl_opname->translatedFormat('d-M-y') }}</span>@else<span class="redup" style="font-size: 10.5px;" data-tgl></span>@endif</td>
                                <td class="p" data-n="{{ $b->p_pasang }}"><span data-persen>{{ $pr($b->p_pasang) }}</span><div class="bar"><i style="width: {{ min(100, $b->p_pasang ?? 0) }}%"></i></div></td>
                                <td class="angka" data-n="{{ $b->tertagih }}">{{ $b->tertagih ? $f($b->tertagih, 3) : '' }}@if ($b->rp_tagih)<br><span class="redup" style="font-size: 11px;">{{ rp((int) round($b->rp_tagih)) }}</span>@endif</td>
                                <td class="p" data-n="{{ $b->p_tagih }}">{{ $pr($b->p_tagih) }}<div class="bar tagih"><i style="width: {{ min(100, $b->p_tagih ?? 0) }}%"></i></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="sub"><td colspan="5">Total</td><td class="angka">{{ rp((int) round($p['nilai'])) }}</td><td class="angka">100%</td><td class="angka">{{ rp((int) round($p['modal'])) }}</td>
                            <td class="angka">{{ rp((int) round($p['biaya_boq'])) }}</td><td class="p">{{ $p['modal'] > 0 ? $pr($p['biaya_boq'] / $p['modal'] * 100) : '–' }}</td><td></td><td class="p">{{ $pr($p['p_pasang']) }}</td><td class="angka">{{ rp((int) round($p['rp_tagih'])) }}</td><td class="p">{{ $pr($p['p_tagih']) }}</td></tr>
                        @if ($p['biaya_tanpa'] > 0)
                            <tr><td colspan="8" class="redup">Biaya dihitung yang <b>belum diberi kode BOQ</b> ({{ $p['n_tanpa'] }} transaksi — gaji, sewa, umum, atau belum dipilah) · <a href="{{ route('pilah.index', ['saring' => 'tanpa-boq']) }}">pilah →</a></td>
                                <td class="angka">{{ rp((int) round($p['biaya_tanpa'])) }}</td><td colspan="5"></td></tr>
                        @endif
                    </tfoot>
                </table>
            </div>
            <p class="redup" style="font-size: 12px;">% modal &gt; 100% (merah) = biaya item sudah melewati modalnya. Terpasang diisi kumulatif (total terpasang sampai hari ini) dan tersimpan sebagai opname bertanggal hari ini.
                Biaya keluar = transaksi Kas & BCA yang dihitung untuk tahap ini dan diberi kode BOQ di <a href="{{ route('pilah.index') }}">Pilah Transaksi</a>.</p>

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
                            <thead><tr><th>Kode</th><th>Uraian</th><th class="angka">Volume BOQ</th><th class="angka">Terpasang</th><th class="angka">Sudah ditagih</th><th style="width: 130px;">Ditagih sekarang</th><th class="angka">Harga</th></tr></thead>
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
                                tr.querySelector('[data-persen]').textContent = h.persen === null ? '–' : h.persen.toLocaleString('id-ID') + '%';
                                tr.querySelector('.bar:not(.modal):not(.tagih) > i').style.width = Math.min(100, h.persen || 0) + '%';
                                tr.querySelector('[data-tgl]').textContent = 'opname ' + h.tanggal;
                            } catch (err) { alert(err.message); }
                        });
                    });
                })();
            </script>
        @endif
    @endif
@endsection
