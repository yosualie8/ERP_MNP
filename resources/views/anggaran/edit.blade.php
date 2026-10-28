@extends('layouts.app', ['judul' => 'Anggaran '.$tahap->nama])

@section('lebar', '1400px')

@section('isi')
    @php
        $h = \App\Support\Anggaran::hitung($baris, $tahap);
        $langsung = array_sum(array_column($rincian, 1));
        $realisasi = $langsung + $overhead;
        $volTanah = \App\Support\Anggaran::volumeTanah($baris);
        $serapan = $h['hpp'] > 0 ? $realisasi / $h['hpp'] * 100 : null;
        $ang = fn ($v, $d = 0) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',');
        $persen = fn ($v) => $v === null ? '–' : number_format($v, 1, ',', '.').'%';
        $b = $tahap->anggaran_baseline;
        // tunda = komponen yang belum masuk HPP rencana sebelum direalisasikan (mis. komisi lapangan sebelum tanggal bayarnya diisi).
        $dataKomponen = $komponen->mapWithKeys(fn ($k) => [$k->id => ['nama' => $k->nama, 'jenis' => $k->jenis, 'cara' => $k->cara, 'satuan' => $k->satuan, 'aktif' => $k->aktif, 'bawaan' => $k->nilai_bawaan, 'kode' => $k->kode, 'sumber' => $k->sumber,
            'tunda' => \App\Support\Anggaran::ditunda($k, $tahap)]]);
        $dataAwal = $baris->map(fn ($x) => ['komponen_id' => $x->komponen_id, 'uraian' => $x->uraian, 'volume' => $ang($x->volume, 3), 'harga' => $ang($x->harga, 2), 'persen' => $ang($x->persen, 3)])->values();
    @endphp
    <style>
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 19px; margin-top: 2px; }
        .ringkas small { font-size: 12px; color: var(--redup); }
        table.ang { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.ang th, table.ang td { border: 1px solid var(--garis); padding: 4px 6px; vertical-align: middle; }
        table.ang th { text-align: left; background: var(--kartu-2, #262b33); }
        table.ang input, table.ang select { width: 100%; box-sizing: border-box; padding: 5px 7px; font-size: 13px; }
        table.ang input.angka-input { text-align: right; }
        table.ang td.nilai { text-align: right; white-space: nowrap; font-weight: 600; }
        table.ang tfoot td { font-weight: 700; text-align: right; }
        table.ang .tanpa { opacity: .3; }
        table.ang .angka { text-align: right; white-space: nowrap; }
        table.ang input.rekom { color: #c4b5fd; border-color: rgba(167, 139, 250, .6); }
        .judul-bagian { display: flex; gap: 10px; align-items: center; margin: 14px 0 6px; }
        .judul-bagian h4 { margin: 0; color: var(--aksen-terang); }
    </style>

    <div class="kartu">
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="{{ route('anggaran.index') }}" class="tombol polos" style="padding: 6px 12px;">← Anggaran Proyek</a>
            <a href="{{ route('anggaran.excel', $tahap) }}" class="tombol polos" style="padding: 6px 12px;" title="Summary + pemasukan & pengeluaran per sumber (Ritasi, BBM, Kas Harian, BCA, Kas UJ, biaya umum, tagihan)">⬇ Download Excel</a>
            <div><h3 style="margin: 0; color: var(--aksen-terang);">{{ $tahap->nama }}</h3>@include('proyek._sub', ['t' => $tahap])</div>
            <span class="redup">{{ $tahap->proyek->nama }} · {{ \App\Models\ProyekTahap::TIPE[$tahap->tipe] }} · {{ \App\Models\ProyekTahap::STATUS[$tahap->status] }}</span>
            @if (auth()->user()->bolehMenu('daftar-proyek'))<a href="{{ route('proyek.show', $tahap) }}" style="font-size: 13px;">detail tahap →</a>@endif
        </div>
    </div>

    <div class="ringkas">
        <div class="kartu"><small>Omset rencana</small><b>{{ rp((int) round($h['omset'])) }}</b></div>
        <div class="kartu"><small>HPP rencana</small><b>{{ rp((int) round($h['hpp'])) }}</b>@if ($h['tunda'])<small>belum termasuk {{ count($h['tunda']) }} komponen yang menunggu realisasi</small>@endif</div>
        <div class="kartu"><small>Margin rencana</small><b @if ($h['margin'] < 0) style="color: var(--merah);" @endif>{{ rp((int) round($h['margin'])) }}</b><small>{{ $persen($h['persen_margin']) }} dari omset</small></div>
        <div class="kartu"><small>Realisasi HPP s/d hari ini</small><b>{{ rp((int) round($realisasi)) }}</b><small>langsung {{ rp($langsung) }} + biaya umum {{ rp((int) round($overhead)) }}</small></div>
        @if ($pf)
            <div class="kartu"><small>Progres fisik — {{ $pf['jenis'] === 'kubikasi' ? 'kubikasi bongkaran ÷ m³ HPP tanah' : $pf['ket'] }}</small><b>{{ $persen($pf['persen']) }}</b><small>{{ $pf['jenis'] === 'kubikasi' ? $pf['ket'] : '' }}</small></div>
        @endif
        <div class="kartu"><small>{{ $pf ? 'Serapan biaya' : 'Progres' }} (realisasi ÷ HPP rencana)</small><b @if ($serapan > 100 || ($pf && $serapan > $pf['persen'] + 10)) style="color: var(--merah);" @endif>{{ $persen($serapan) }}</b><small>{{ $h['hpp'] > 0 ? ($realisasi > $h['hpp'] ? 'realisasi melebihi HPP rencana' : 'sisa '.rp((int) round($h['hpp'] - $realisasi))) : 'HPP rencana belum diisi' }}</small></div>
    </div>

    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    @if ($boq)
        @php
            $pb = $pf['boq'] ?? \App\Support\ProgresBoq::hitung($tahap);
        @endphp
        <div class="pesan" style="background: rgba(96, 165, 250, .1); border: 1px solid rgba(96, 165, 250, .4);">
            📐 <b>Dari BOQ ({{ $boq['n'] }} item):</b> omset rencana sudah termasuk <b>nilai kontrak {{ rp((int) round($boq['nilai'])) }}</b> dan HPP rencana sudah termasuk <b>modal {{ rp((int) round($boq['modal'])) }}</b> (material + jasa per item).
            Baris komponen di bawah hanya untuk biaya di luar item BOQ (mis. jasa harian, komisi, biaya umum).
            Progres BOQ: modal {{ $persen($pb['p_modal']) }} · terpasang {{ $pb['ada_opname'] ? $persen($pb['p_pasang']) : 'belum ada opname' }} · tertagih {{ $persen($pb['p_tagih']) }}
            @if (auth()->user()->bolehMenu('boq-proyek'))· <a href="{{ route('boq.index', ['tahap' => $tahap->id]) }}">BOQ & Progres →</a>@endif
        </div>
    @endif
    @if ($pakaiTemplate && ! $boq)
        <div class="pesan" style="background: rgba(240, 192, 90, .12); border: 1px solid rgba(240, 192, 90, .4);">Anggaran tahap ini belum ada — baris awal disiapkan otomatis dari <b>template tipe {{ \App\Models\ProyekTahap::TIPE[$tahap->tipe] }}</b> & data tahap, <b>belum disimpan</b>. Lengkapi volume/harga lalu tekan Simpan anggaran.</div>
    @endif

    @if ($perKomponen)
        @php
            $fisik = $pf['persen'] ?? null;
            $sumR = collect($perKomponen)->reject(fn ($k) => $k['tunda'])->sum('rp_rencana');
            $sumA = collect($perKomponen)->sum('rp_real');
        @endphp
        <div class="kartu gulir" style="padding: 0;">
            <div style="padding: 12px 14px 6px; display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap;">
                <h4 style="margin: 0; color: var(--aksen-terang);">Progres per komponen — rencana vs realisasi</h4>
                <span class="redup" style="font-size: 12px;">% terpakai dibanding progres fisik{{ $fisik !== null ? ' ('.$persen($fisik).')' : '' }}: merah = melebihi rencana, kuning = lebih cepat dari progres fisik</span>
            </div>
            <table class="ang" data-tanpa-saring-kolom data-tanpa-beku>
                <thead><tr><th>Komponen</th><th class="angka">Rencana</th><th class="angka">Realisasi</th><th class="angka">Rencana (Rp)</th><th class="angka">Realisasi (Rp)</th><th class="angka">% terpakai</th><th class="angka">Sisa (Rp)</th></tr></thead>
                <tbody>
                    @foreach ($perKomponen as $k)
                        @php
                            $pakai = $k['rp_rencana'] > 0 ? $k['rp_real'] / $k['rp_rencana'] * 100 : null;
                            $warna = $pakai === null ? '' : ($pakai > 100 ? 'color: var(--merah); font-weight: 700;' : ($fisik !== null && $pakai > $fisik + 10 ? 'color: #f0c05a; font-weight: 700;' : ''));
                            $vol = fn ($v) => $v === null ? '' : $ang($v, 2).($k['satuan'] ? ' '.$k['satuan'] : '');
                        @endphp
                        <tr>
                            <td>{{ $k['nama'] }}@if ($k['tunda']) <span class="label kuning" style="font-size: 10px;">menunggu realisasi</span>@endif @if ($k['catatan'])<span class="redup" style="font-size: 11px;"> · {{ $k['catatan'] }}</span>@endif</td>
                            <td class="angka">{{ $vol($k['v_rencana']) }}</td>
                            <td class="angka">{{ $vol($k['v_real']) }}</td>
                            <td class="angka">{{ $k['rp_rencana'] ? rp((int) round($k['rp_rencana'])) : '' }}@if ($k['tunda'] && $k['rp_rencana'])<br><span class="redup" style="font-size: 11px;">tidak dijumlah</span>@endif</td>
                            <td class="angka">{{ $k['rp_real'] ? rp((int) round($k['rp_real'])) : '–' }}</td>
                            <td class="angka" style="{{ $warna }}">{{ $pakai === null ? '' : $persen($pakai) }}</td>
                            <td class="angka">{{ $k['rp_rencana'] > 0 && ! $k['tunda'] ? rp((int) round($k['rp_rencana'] - $k['rp_real'])) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td>Total HPP</td><td></td><td></td><td class="angka">{{ rp((int) round($sumR)) }}</td><td class="angka">{{ rp((int) round($sumA)) }}</td>
                    <td class="angka">{{ $sumR > 0 ? $persen($sumA / $sumR * 100) : '' }}</td><td class="angka">{{ rp((int) round($sumR - $sumA)) }}</td></tr></tfoot>
            </table>
        </div>
    @endif

    <div class="kartu">
        @include('anggaran._rincian-biaya', ['kas' => $kasTahap, 'overheadBulan' => $overheadBulan])
    </div>

    @if ($rekomendasi)
        @php($rs = $rekomendasi['rasio'])
        <div class="kartu" style="border-color: rgba(167, 139, 250, .45);">
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <h4 style="margin: 0; color: #c4b5fd;">Rekomendasi dari {{ $rekomendasi['n'] }} tahap {{ $tahap->proyek->nama }} sejenis</h4>
                <span class="redup" style="font-size: 12px;" title="{{ implode(', ', $rekomendasi['sampel']) }}">median per m³ kubikasi bongkaran, tahap selesai 12 bulan terakhir (arahkan kursor untuk daftar tahap)</span>
                <button type="button" class="tombol" id="isi-rekomendasi" style="margin-left: auto; padding: 6px 12px; background: #7c3aed; border-color: #7c3aed;">Isi komponen lain dari rekomendasi</button>
            </div>
            <div style="display: flex; gap: 18px; flex-wrap: wrap; margin-top: 8px; font-size: 13px;">
                <span>Solar <b>{{ number_format($rs['liter'], 3, ',', '.') }}</b> liter/m³</span>
                <span>Rental <b>{{ number_format($rs['hm'], 3, ',', '.') }}</b> jam/m³</span>
                <span>Ngepok <b>{{ number_format($rs['ngepok'], 3, ',', '.') }}</b> rit/m³</span>
                <span>Gaji & UM <b>{{ rp((int) round($rs['gaji'])) }}</b>/m³</span>
                <span>Operasional <b>{{ rp((int) round($rs['ops'])) }}</b>/m³</span>
                <span>Biaya umum <b>{{ $rekomendasi['overhead_m3'] ? rp((int) $rekomendasi['overhead_m3']) : '–' }}</b>/m³ <span class="redup">(tanpa akun HPP/BBM/sparepart/DT)</span></span>
            </div>
            <p class="redup" style="margin: 6px 0 0; font-size: 12px;">Isi dulu volume <b>HPP tanah urug (m³)</b> & harganya, lalu tekan tombol ungu: solar, rental, ngepok, gaji, operasional & persen biaya umum dihitung dari volume itu (isian ungu = hasil rekomendasi, boleh diubah). Komisi tetap dari persen tahap.</p>
        </div>
    @endif

    <form method="POST" action="{{ route('anggaran.simpan', $tahap) }}" class="kartu" id="form-anggaran">
        @csrf
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <button type="submit" class="tombol">Simpan anggaran</button>
            <span class="redup" style="font-size: 13px;">Angka boleh ditulis 139.000 atau 2.551,75. Komisi & overhead dihitung dari total omset.</span>
        </div>
        @foreach (['omset' => 'Omset (pendapatan)', 'hpp' => 'HPP (biaya)'] as $jenis => $judul)
            <div class="judul-bagian"><h4>{{ $judul }}</h4><button type="button" class="tombol polos" data-tambah="{{ $jenis }}" style="padding: 3px 10px; font-size: 12px;">+ baris</button></div>
            <table class="ang" data-tanpa-saring-kolom data-tanpa-beku>
                <thead><tr><th style="width: 260px;">Komponen</th><th>Uraian / catatan</th><th style="width: 120px;">Volume</th><th style="width: 70px;">Satuan</th><th style="width: 150px;">Harga satuan / %</th><th style="width: 150px;">Nilai (Rp)</th><th style="width: 36px;"></th></tr></thead>
                <tbody data-jenis="{{ $jenis }}"></tbody>
                <tfoot><tr><td colspan="5">Total {{ $jenis === 'omset' ? 'omset' : 'HPP' }}</td><td data-total="{{ $jenis }}"></td><td></td></tr></tfoot>
            </table>
        @endforeach
        <div style="margin-top: 10px; text-align: right; font-size: 15px;"><span class="redup">Margin rencana:</span> <b id="margin"></b></div>
    </form>

    <div class="kartu">
        <h4 style="margin: 0 0 6px; color: var(--aksen-terang);">Baseline (rencana awal)</h4>
        @if ($b)
            <p style="margin: 0 0 8px;">Dikunci {{ $tahap->anggaran_dikunci_at->translatedFormat('j M Y H:i') }}: omset <b>{{ rp((int) round($b['omset'])) }}</b>, HPP <b>{{ rp((int) round($b['hpp'])) }}</b>
                @if (abs($b['omset'] - $h['omset']) > 1 || abs($b['hpp'] - $h['hpp']) > 1)
                    — <span style="color: #f0c05a;">anggaran sekarang berbeda (omset {{ rp((int) round($h['omset'] - $b['omset'])) }}, HPP {{ rp((int) round($h['hpp'] - $b['hpp'])) }} dari baseline)</span>
                @endif
            </p>
        @else
            <p class="redup" style="margin: 0 0 8px;">Belum dikunci. Kunci setelah anggaran disepakati supaya rencana awal tersimpan; perubahan berikutnya (addendum) tetap bisa dibandingkan.</p>
        @endif
        @if ($tahap->anggaran->isNotEmpty())
            <form method="POST" action="{{ route('anggaran.kunci', $tahap) }}" onsubmit="return confirm('{{ $b ? 'Ganti baseline dengan anggaran yang tersimpan sekarang?' : 'Kunci anggaran yang tersimpan sekarang sebagai baseline?' }}')">
                @csrf
                <button type="submit" class="tombol polos">{{ $b ? 'Perbarui baseline (addendum)' : 'Kunci sebagai baseline' }}</button>
            </form>
        @endif
    </div>

    <template id="tpl-baris">
        <tr>
            <td><select data-k="komponen_id"></select></td>
            <td><input data-k="uraian" maxlength="150" autocomplete="off"></td>
            <td><input data-k="volume" class="angka-input" inputmode="decimal" autocomplete="off"></td>
            <td class="satuan redup"></td>
            <td><input data-k="harga" class="angka-input" inputmode="decimal" autocomplete="off"><input data-k="persen" class="angka-input" inputmode="decimal" autocomplete="off" placeholder="%" hidden></td>
            <td class="nilai"></td>
            <td><button type="button" class="tombol-hapus" title="Hapus baris" style="padding: 2px 8px;">✕</button></td>
        </tr>
    </template>
    <script>
        (() => {
            const KOMPONEN = @json($dataKomponen);
            const AWAL = @json($dataAwal);
            const LAMA = @json(old('baris'));
            const form = document.getElementById('form-anggaran'), tpl = document.getElementById('tpl-baris');
            const angka = v => { v = String(v ?? '').replace(/[\s%]|Rp/gi, ''); if (!v) return 0; v = v.includes(',') ? v.replace(/\./g, '').replace(',', '.') : (/^\d+\.\d{1,2}$/.test(v) ? v : v.replace(/\./g, '')); return parseFloat(v) || 0; };
            const rp = n => Math.round(n).toLocaleString('id-ID');
            let no = 0;
            const tambah = (jenis, isi = {}) => {
                const tr = tpl.content.firstElementChild.cloneNode(true), sel = tr.querySelector('[data-k=komponen_id]');
                sel.append(new Option('— pilih —', ''));
                Object.entries(KOMPONEN).filter(([id, k]) => k.jenis === jenis && (k.aktif || String(id) === String(isi.komponen_id))).forEach(([id, k]) => sel.append(new Option(k.nama, id)));
                const i = no++;
                tr.querySelectorAll('[data-k]').forEach(x => { x.name = `baris[${i}][${x.dataset.k}]`; if (isi[x.dataset.k] != null) x.value = isi[x.dataset.k]; });
                tr.querySelector('.tombol-hapus').addEventListener('click', () => { tr.remove(); hitung(); });
                sel.addEventListener('change', () => {
                    // Komponen baru dipilih: harga/persen bawaan diisi bila masih kosong.
                    const k = KOMPONEN[sel.value];
                    if (k && k.bawaan != null) { const f = tr.querySelector(k.cara === 'persen' ? '[data-k=persen]' : '[data-k=harga]'); if (!f.value) f.value = String(k.bawaan).replace('.', ','); }
                    hitung();
                });
                form.querySelector(`tbody[data-jenis=${jenis}]`).append(tr);
                return tr;
            };
            const hitung = () => {
                let omset = 0, hpp = 0;
                const baris = [...form.querySelectorAll('tbody tr')];
                const nilai = tr => {
                    const k = KOMPONEN[tr.querySelector('[data-k=komponen_id]').value], persen = k?.cara === 'persen';
                    tr.querySelector('.satuan').textContent = k?.satuan ?? '';
                    tr.querySelector('[data-k=harga]').hidden = persen; tr.querySelector('[data-k=persen]').hidden = !persen;
                    tr.querySelector('[data-k=volume]').disabled = persen; tr.querySelector('[data-k=volume]').classList.toggle('tanpa', persen);
                    return {k, persen};
                };
                baris.filter(tr => tr.closest('tbody').dataset.jenis === 'omset').forEach(tr => {
                    nilai(tr); const v = angka(tr.querySelector('[data-k=volume]').value) * angka(tr.querySelector('[data-k=harga]').value);
                    tr.querySelector('.nilai').textContent = v ? rp(v) : ''; omset += v;
                });
                baris.filter(tr => tr.closest('tbody').dataset.jenis === 'hpp').forEach(tr => {
                    const {k, persen} = nilai(tr);
                    const v = persen ? angka(tr.querySelector('[data-k=persen]').value) / 100 * omset : angka(tr.querySelector('[data-k=volume]').value) * angka(tr.querySelector('[data-k=harga]').value);
                    // Komponen "tunda realisasi" (komisi lapangan sebelum dibayar): nilai tampil, tidak masuk total HPP.
                    tr.querySelector('.nilai').innerHTML = v ? (k?.tunda ? `<span class="redup" title="Belum masuk HPP — dihitung setelah direalisasikan (tanggal bayar diisi di data tahap)">(${rp(v)})<br><small>menunggu realisasi</small></span>` : rp(v)) : '';
                    if (!k?.tunda) hpp += v;
                });
                form.querySelector('[data-total=omset]').textContent = rp(omset);
                form.querySelector('[data-total=hpp]').textContent = rp(hpp);
                const m = document.getElementById('margin');
                m.textContent = `${rp(omset - hpp)}` + (omset ? ` (${((omset - hpp) / omset * 100).toFixed(1).replace('.', ',')}%)` : '');
                m.style.color = omset - hpp < 0 ? 'var(--merah)' : '';
            };
            (LAMA ? Object.values(LAMA) : AWAL).forEach(isi => { const k = KOMPONEN[isi.komponen_id]; tambah(k ? k.jenis : (isi.jenis ?? 'hpp'), isi); });
            ['omset', 'hpp'].forEach(j => { if (!form.querySelector(`tbody[data-jenis=${j}] tr`)) tambah(j); });
            form.querySelectorAll('[data-tambah]').forEach(b => b.addEventListener('click', () => { tambah(b.dataset.tambah).querySelector('select').focus(); hitung(); }));
            form.addEventListener('input', hitung); hitung();
            // Rekomendasi dari tahap sejenis: rasio per m³ × volume HPP tanah (m³).
            const REKOM = @json($rekomendasi);
            const TARIF = {solar: {{ (float) ($tahap->tarif_bbm ?? \App\Models\ProyekTahap::BAWAAN['tarif_bbm']) }}, rental: {{ (float) ($tahap->tarif_rental ?? \App\Models\ProyekTahap::BAWAAN['tarif_rental']) }}};
            const tulis = (x, v) => { x.value = v; x.classList.add('rekom'); };
            const fmt = (v, d = 0) => (Math.round(v * 10 ** d) / 10 ** d).toLocaleString('id-ID', {maximumFractionDigits: d});
            const barisKode = kode => {
                const id = Object.keys(KOMPONEN).find(i => KOMPONEN[i].kode === kode);
                if (!id) return null;
                return [...form.querySelectorAll('tbody tr')].find(t => t.querySelector('select').value === id) || tambah(KOMPONEN[id].jenis, {komponen_id: id});
            };
            document.getElementById('isi-rekomendasi')?.addEventListener('click', () => {
                const tanah = [...form.querySelectorAll('tbody[data-jenis=hpp] tr')].filter(tr => KOMPONEN[tr.querySelector('select').value]?.sumber === 'ritasi_m3');
                const m3 = tanah.reduce((s, tr) => s + angka(tr.querySelector('[data-k=volume]').value), 0);
                if (!m3) { alert('Isi dulu volume HPP tanah urug & angkutan (m³).'); return; }
                const r = REKOM.rasio;
                const isi = (kode, f) => { const tr = barisKode(kode); if (tr) f(k => tr.querySelector('[data-k=' + k + ']')); };
                isi('solar', q => { tulis(q('volume'), fmt(r.liter * m3)); if (!q('harga').value) tulis(q('harga'), fmt(TARIF.solar)); });
                isi('rental', q => { tulis(q('volume'), fmt(r.hm * m3, 1)); if (!q('harga').value) tulis(q('harga'), fmt(TARIF.rental)); });
                if (r.ngepok > 0 && REKOM.harga_ngepok) isi('ngepok', q => { tulis(q('volume'), fmt(Math.max(1, r.ngepok * m3))); tulis(q('harga'), fmt(REKOM.harga_ngepok)); });
                isi('gaji', q => { tulis(q('volume'), '1'); tulis(q('harga'), fmt(Math.round(r.gaji * m3 / 1000) * 1000)); });
                isi('operasional', q => { tulis(q('volume'), '1'); tulis(q('harga'), fmt(Math.round(r.ops * m3 / 1000) * 1000)); });
                hitung();
                // Biaya umum: Rp/m³ × volume ÷ total omset → persen.
                const omset = angka(form.querySelector('[data-total=omset]').textContent);
                if (REKOM.overhead_m3) {
                    if (omset > 0) isi('overhead', q => tulis(q('persen'), fmt(REKOM.overhead_m3 * m3 / omset * 100, 2)));
                    else alert('Isi omset dulu supaya persen biaya umum bisa dihitung.');
                }
                hitung();
            });
        })();
    </script>
@endsection
