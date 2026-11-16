@extends('layouts.app', ['judul' => 'Dashboard HM & KM'])

@section('lebar', '1500px')

@section('isi')
    @include('partials.gaya-dasbor')
    @php
        $l = function ($v, $d = 1) { if ($v === null) return '–'; $t = number_format((float) $v, $d, ',', '.'); return $d > 0 ? rtrim(rtrim($t, '0'), ',') : $t; };
        $tgl = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->translatedFormat('j M Y') : '';
        $namaBulan = fn ($m) => \Illuminate\Support\Carbon::parse($m.'-01')->translatedFormat('M y');
        $basi = \App\Support\MeterAset::BASI;
        $segar = $baris->filter(fn ($b) => $b->umur !== null && $b->umur <= $basi)->count();
        $lama = $baris->filter(fn ($b) => $b->umur !== null && $b->umur > $basi)->count();
        $belum = $baris->filter(fn ($b) => $b->umur === null)->count();
        $totalIni = $baris->groupBy('satuan')->map(fn ($g) => ['nilai' => $g->sum(fn ($b) => $b->ini['nilai'] ?? 0), 'unit' => $g->filter(fn ($b) => $b->ini['nilai'] !== null)->count()]);
        $totalLalu = $baris->groupBy('satuan')->map(fn ($g) => $g->sum(fn ($b) => $b->lalu['nilai'] ?? 0));
        $q = fn (array $ubah) => route('dashboard.hm-km', array_filter([...['kelompok' => $kelompok, 'bulan' => $bulan === now()->format('Y-m') ? null : $bulan, 'semua' => $semuaStatus ? 1 : null], ...$ubah]));
        $satuSatuan = $baris->pluck('satuan')->unique()->count() === 1;
        $maksIni = max(1, (float) $baris->max(fn ($b) => $b->ini['nilai'] ?? 0));
        $unitJs = $baris->mapWithKeys(fn ($b) => [$b->aset->no_lambung => ['id' => $b->aset->id, 'satuan' => $b->satuan, 'akhir' => $b->akhir?->nilai, 'tanggal' => $b->akhir?->tanggal]]);
    @endphp
    <style>
        .hk-atas { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
        .hk-atas .chip { margin: 0; }
        table.kas tr.t.bisa-buka { cursor: pointer; }
        table.kas tr.t.bisa-buka:hover td { background: var(--kartu-2); }
        .kecil { font-size: 11.5px; color: var(--redup); }
        .bar-hk { height: 5px; border-radius: 3px; background: var(--kartu-2); margin-top: 4px; min-width: 70px; }
        .bar-hk span { display: block; height: 100%; border-radius: 3px; background: var(--aksen); }
        #dialog-hk, #dialog-catat { border: 1px solid var(--garis); border-radius: 12px; background: var(--kartu); color: var(--teks); padding: 0; }
        #dialog-hk { width: min(1000px, 96vw); max-height: 92vh; }
        #dialog-catat { width: min(460px, 96vw); }
        #dialog-hk::backdrop, #dialog-catat::backdrop { background: rgba(0, 0, 0, .6); }
        .dlg-kepala { display: flex; gap: 12px; align-items: flex-start; padding: 16px 18px 10px; position: sticky; top: 0; background: var(--kartu); z-index: 3; border-bottom: 1px solid var(--garis); }
        .dlg-kepala h3 { margin: 0; font-size: 18px; }
        .dlg-badan { padding: 14px 18px 18px; }
        .grafik-hk { position: relative; background: var(--latar); border: 1px solid var(--garis); border-radius: 8px; padding: 8px; margin-bottom: 14px; }
        .grafik-hk svg { display: block; width: 100%; height: auto; }
        .grafik-hk .tip { position: absolute; pointer-events: none; background: #0b0c0f; border: 1px solid var(--garis-kuat); border-radius: 6px; padding: 6px 9px; font-size: 12px; line-height: 1.45; white-space: nowrap; transform: translate(-50%, calc(-100% - 12px)); }
        .grafik-hk .tip[hidden] { display: none; }
        #form-catat .isian { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px; }
        #form-catat input[type=text], #form-catat input:not([type]) { width: 100%; box-sizing: border-box; }
        #form-catat .centang { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; margin-bottom: 12px; color: var(--teks); font-weight: 400; }
        #catat-pesan:empty { display: none; }
    </style>

    <p class="redup" style="margin-top: 0;">HM alat berat diambil otomatis dari <b>Data BBM & HM</b> (HM akhir per hari). KM dump truck dan HM / KM kendaraan lain dicatat di halaman ini
        @if ($bolehCatat)(tombol <b>+ Catat</b>)@endif. Pemakaian = jumlah kenaikan antar-catatan; angka yang turun atau melompat tidak wajar
        (&gt; {{ \App\Support\MeterAset::MAKS_PER_HARI['HM'] }} HM / {{ rp(\App\Support\MeterAset::MAKS_PER_HARI['KM']) }} KM per hari) tidak dihitung dan ditandai ⚠. Klik baris untuk riwayat unit.</p>

    <div class="hk-atas">
        @foreach (\App\Support\MeterAset::KELOMPOK as $k => [$judul])
            <a href="{{ $q(['kelompok' => $k]) }}" @class(['chip', 'aktif' => $kelompok === $k])>{{ $judul }} · {{ $jumlahPer[$k] }}</a>
        @endforeach
        <a href="{{ $q(['semua' => $semuaStatus ? null : 1]) }}" class="chip" style="margin-left: 8px;">{{ $semuaStatus ? '☑' : '☐' }} Termasuk tidak aktif / dijual</a>
        @if ($bolehCatat)<button type="button" class="tombol" id="tombol-catat" style="margin-left: auto;">+ Catat HM / KM</button>@endif
    </div>
    <div class="hk-atas">
        <span class="redup" style="font-size: 13px;">Bulan:</span>
        <div class="segmen">
            @foreach ($daftarBulan as $m)
                <a href="{{ $q(['bulan' => $m === now()->format('Y-m') ? null : $m]) }}" @class(['aktif' => $bulan === $m])>{{ $namaBulan($m) }}</a>
            @endforeach
        </div>
    </div>

    <div class="kpi-baris">
        <div class="kpi"><div class="kpi-label">Unit</div><div class="kpi-nilai">{{ $baris->count() }}</div>
            <div class="kpi-sub">{{ $baris->countBy(fn ($b) => $b->aset->labelKategori())->map(fn ($n, $k) => "{$k} {$n}")->implode(' · ') }}</div></div>
        <div class="kpi"><div class="kpi-label"><i style="background: var(--sukses);"></i>Tercatat ≤ {{ $basi }} hari</div><div class="kpi-nilai">{{ $segar }}<small>unit</small></div>
            <div class="kpi-sub">angka terakhir masih baru</div></div>
        <div @class(['kpi', 'peringatan' => $lama + $belum > 0])><div class="kpi-label"><i style="background: #f0c05a;"></i>Perlu dicatat</div>
            <div class="kpi-nilai">{{ $lama + $belum }}<small>unit</small></div><div class="kpi-sub">{{ $lama }} lebih dari {{ $basi }} hari · {{ $belum }} belum pernah</div></div>
        <div class="kpi"><div class="kpi-label"><i style="background: var(--aksen);"></i>Pemakaian {{ $awal->translatedFormat('F Y') }}</div>
            <div class="kpi-nilai">@foreach ($totalIni as $s => $t){{ $l($t['nilai']) }}<small>{{ $s }}</small>@if (! $loop->last) · @endif @endforeach @if ($totalIni->isEmpty())–@endif</div>
            <div class="kpi-sub">@foreach ($totalIni as $s => $t){{ $t['unit'] }} unit · {{ $lalu->translatedFormat('M') }}: {{ $l($totalLalu[$s] ?? 0) }} {{ $s }}@if (! $loop->last) · @endif @endforeach</div></div>
    </div>

    <div class="kartu gulir bulat" style="padding: 0;">
        <table class="kas" id="tabel-hk">
            <thead>
                <tr>
                    <th>Unit</th>@if ($kelompok === 'lain')<th>Kategori</th>@endif<th>Jenis / merek</th><th>Plat</th><th>Status</th><th>Satuan</th>
                    <th class="angka">Angka terakhir</th><th>Tanggal terakhir</th><th class="angka">Umur (hari)</th><th>Sumber</th>
                    <th class="angka">Pakai {{ $awal->translatedFormat('M Y') }}</th><th class="angka">Rata / hari</th><th class="angka">Pakai {{ $lalu->translatedFormat('M Y') }}</th>
                    <th class="angka">Jumlah catatan</th>@if ($bolehCatat)<th data-tanpa-saring data-tanpa-urut></th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($baris as $b)
                    @php($a = $b->aset)
                    <tr class="t bisa-buka" data-aset="{{ $a->id }}">
                        <td><b>{{ $a->no_lambung }}</b></td>
                        @if ($kelompok === 'lain')<td>{{ $a->labelKategori() }}</td>@endif
                        <td>{{ $a->jenis }}</td>
                        <td>{{ $a->plat ?? '—' }}</td>
                        <td><span @class(['label', 'hijau' => $a->status === 'aktif', 'kuning' => $a->status === 'perbaikan', 'merah' => $a->status === 'dijual'])>{{ $a->labelStatus() }}</span></td>
                        <td>{{ $b->satuan }}</td>
                        <td class="angka" data-n="{{ $b->akhir?->nilai }}"><b>{{ $b->akhir ? $l($b->akhir->nilai) : '' }}</b></td>
                        <td data-n="{{ $b->akhir?->tanggal }}" style="white-space: nowrap;">{!! $b->akhir ? e($tgl($b->akhir->tanggal)) : '<span class="label kuning">belum ada</span>' !!}</td>
                        <td class="angka" data-n="{{ $b->umur }}">@if ($b->umur !== null)<span @class(['label', 'kuning' => $b->umur > $basi && $b->umur <= 30, 'merah' => $b->umur > 30, 'hijau' => $b->umur <= $basi])>{{ $b->umur }}</span>@endif</td>
                        <td class="kecil">{{ $b->akhir ? ($b->akhir->sumber === 'bbm' ? 'Data BBM' : 'Dicatat'.($b->akhir->oleh ? ' · '.$b->akhir->oleh : '')) : '' }}</td>
                        <td class="angka" data-n="{{ $b->ini['nilai'] }}">{{ $l($b->ini['nilai']) }}@if ($b->ini['janggal']) <span title="{{ $b->ini['janggal'] }} catatan janggal tidak dihitung">⚠</span>@endif
                            @if ($satuSatuan && $b->ini['nilai'])<div class="bar-hk"><span style="width: {{ min(100, $b->ini['nilai'] / $maksIni * 100) }}%"></span></div>@endif</td>
                        <td class="angka" data-n="{{ $b->ini['rata'] }}">{{ $l($b->ini['rata']) }}</td>
                        <td class="angka" data-n="{{ $b->lalu['nilai'] }}">{{ $l($b->lalu['nilai']) }}@if ($b->lalu['janggal']) <span title="{{ $b->lalu['janggal'] }} catatan janggal tidak dihitung">⚠</span>@endif</td>
                        <td class="angka" data-n="{{ $b->n }}">{{ $b->n }}@if ($b->janggal) <span class="label merah" title="catatan turun / melompat tidak wajar">{{ $b->janggal }} ⚠</span>@endif</td>
                        @if ($bolehCatat)<td><button type="button" class="tombol-edit" data-catat="{{ $a->id }}">+ Catat</button></td>@endif
                    </tr>
                @empty
                    <tr><td colspan="16" class="redup" style="padding: 20px 14px;">Tidak ada aset di kelompok ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <dialog id="dialog-hk">
        <div class="dlg-kepala">
            <div style="flex: 1; min-width: 0;"><h3 id="d-nama"></h3><div class="redup" id="d-sub" style="font-size: 13px; margin-top: 2px;"></div></div>
            @if ($bolehCatat)<button type="button" class="tombol" id="d-catat">+ Catat</button>@endif
            <button type="button" class="tombol polos" id="d-tutup">Tutup</button>
        </div>
        <div class="dlg-badan" id="d-badan"></div>
    </dialog>

    @if ($bolehCatat)
        <dialog id="dialog-catat">
            <form id="form-catat" method="dialog">
                <div class="dlg-kepala"><div style="flex: 1;"><h3>Catat HM / KM</h3></div></div>
                <div class="dlg-badan">
                    <div class="isian"><label for="c-unit">Unit</label>
                        <input id="c-unit" list="c-daftar" autocomplete="off" placeholder="Ketik No Lambung / nama alat…" required>
                        <datalist id="c-daftar">@foreach ($baris as $b)<option value="{{ $b->aset->no_lambung }}">{{ $b->aset->labelKategori() }} · {{ $b->satuan }}</option>@endforeach</datalist>
                        <span class="kecil" id="c-info"></span></div>
                    <div class="isian"><label for="c-tanggal">Tanggal</label><input type="date" id="c-tanggal" max="{{ now()->toDateString() }}" required></div>
                    <div class="isian"><label for="c-nilai" id="c-label-nilai">Angka</label><input id="c-nilai" inputmode="decimal" autocomplete="off" placeholder="mis. 5370,5" required style="text-align: right;"></div>
                    <label class="centang"><input type="checkbox" id="c-reset"> <span>Meter diganti / direset — angka ini jadi awal hitungan baru</span></label>
                    <div class="isian"><label for="c-catatan">Catatan (opsional)</label><input id="c-catatan" maxlength="300" autocomplete="off" placeholder="mis. dari foto speedometer"></div>
                    <div class="pesan galat" id="catat-pesan"></div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                        <button type="button" class="tombol polos" id="c-batal">Batal</button>
                        <button type="submit" class="tombol" id="c-simpan">Simpan</button>
                    </div>
                </div>
            </form>
        </dialog>
    @endif

    <script>
        (() => {
            const URL_RIWAYAT = @json(route('dashboard.hm-km.riwayat')), URL_SIMPAN = @json(route('hm-km-catat.simpan')), URL_HAPUS = @json(url('/hm-km'));
            const CSRF = document.querySelector('meta[name=csrf-token]').content;
            // daftar unit di halaman ini: No Lambung → {id, satuan, akhir, tanggal}
            const UNIT = @json($unitJs);
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const ang = n => n === null || n === undefined ? '–' : Number(n).toLocaleString('id-ID', {maximumFractionDigits: 1});
            const dlg = document.getElementById('dialog-hk'), badan = document.getElementById('d-badan');
            let asetBuka = null;

            const grafik = data => {
                const titik = data.bacaan.filter(b => !b.janggal).slice().reverse();
                if (titik.length < 2) return '';
                const W = 900, H = 220, kiri = 64, kanan = 14, atas = 14, bawah = 26;
                const t0 = Date.parse(titik[0].tanggal), t1 = Date.parse(titik[titik.length - 1].tanggal) || t0 + 1;
                const vMin = Math.min(...titik.map(b => b.nilai)), vMax = Math.max(...titik.map(b => b.nilai)), rentang = (vMax - vMin) || 1;
                const x = t => kiri + (W - kiri - kanan) * ((Date.parse(t) - t0) / ((t1 - t0) || 1));
                const y = v => atas + (H - atas - bawah) * (1 - (v - vMin) / rentang);
                let s = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Grafik ${esc(data.satuan)}">`;
                for (let i = 0; i <= 4; i++) {
                    const v = vMin + rentang * i / 4, yy = y(v);
                    s += `<line x1="${kiri}" x2="${W - kanan}" y1="${yy}" y2="${yy}" stroke="#2a2e36"/><text x="${kiri - 6}" y="${yy + 4}" fill="#8b919c" font-size="11" text-anchor="end">${ang(v)}</text>`;
                }
                [titik[0], titik[titik.length - 1]].forEach((b, i) => s += `<text x="${x(b.tanggal)}" y="${H - 6}" fill="#8b919c" font-size="11" text-anchor="${i ? 'end' : 'start'}">${esc(b.tgl)}</text>`);
                // garis diputus di meter baru / direset (bukan garis turun tajam)
                const ruas = titik.reduce((r, b) => { if (b.reset || !r.length) r.push([]); r[r.length - 1].push(b); return r; }, []);
                ruas.forEach(r => s += `<polyline fill="none" stroke="var(--aksen)" stroke-width="2" points="${r.map(b => `${x(b.tanggal).toFixed(1)},${y(b.nilai).toFixed(1)}`).join(' ')}"/>`);
                titik.forEach(b => s += `<circle cx="${x(b.tanggal).toFixed(1)}" cy="${y(b.nilai).toFixed(1)}" r="${titik.length > 60 ? 2 : 3.5}" fill="${b.sumber === 'input' ? '#f0c05a' : 'var(--aksen)'}" data-tip="${esc(b.tgl)}<br><b>${ang(b.nilai)} ${esc(data.satuan)}</b>${b.selisih !== null ? ` · +${ang(b.selisih)}` : ''}<br>${b.sumber === 'bbm' ? 'Data BBM' : 'Dicatat'}"/>`);
                return `<div class="grafik-hk">${s}</svg><div class="tip" hidden></div></div>`;
            };

            const buka = async id => {
                asetBuka = id;
                document.getElementById('d-nama').textContent = 'Memuat…';
                document.getElementById('d-sub').textContent = '';
                badan.innerHTML = '';
                if (!dlg.open) dlg.showModal();
                const r = await fetch(`${URL_RIWAYAT}?aset=${id}`, {headers: {Accept: 'application/json'}});
                if (!r.ok) { badan.innerHTML = '<p class="pesan galat">Gagal memuat riwayat.</p>'; return; }
                const d = await r.json();
                document.getElementById('d-nama').textContent = `${d.aset} · ${d.satuan}`;
                document.getElementById('d-sub').textContent = [d.kategori, d.jenis, d.plat].filter(Boolean).join(' · ') + ` · ${d.bacaan.length} catatan`;
                if (!d.bacaan.length) { badan.innerHTML = '<p class="redup">Belum ada catatan HM / KM untuk unit ini.</p>'; return; }
                badan.innerHTML = grafik(d) + `<div class="gulir" style="max-height: 50vh;"><table class="kas"><thead><tr><th>Tanggal</th><th class="angka">${esc(d.satuan)}</th><th class="angka">Selisih</th><th class="angka">Hari</th><th class="angka">Per hari</th><th>Sumber</th><th>Catatan</th><th data-tanpa-saring data-tanpa-urut></th></tr></thead><tbody>`
                    + d.bacaan.map(b => `<tr class="t"><td data-n="${b.tanggal}" style="white-space: nowrap;">${esc(b.tgl)}</td><td class="angka" data-n="${b.nilai}"><b>${ang(b.nilai)}</b></td>`
                        + `<td class="angka" data-n="${b.selisih ?? ''}">${b.reset ? (b.reset_otomatis ? '<span class="label kuning" title="angka turun lalu terus naik dari angka baru ini — dianggap meter diganti">meter baru (otomatis)</span>' : '<span class="label kuning">meter direset</span>') : b.selisih === null ? '' : (b.janggal ? `<span class="label merah" title="${b.janggal === 'turun' ? 'lebih kecil dari catatan sebelumnya' : 'naik melebihi batas wajar per hari'} — tidak dihitung">${b.selisih > 0 ? '+' : ''}${ang(b.selisih)} ⚠ ${b.janggal}</span>` : '+' + ang(b.selisih))}</td>`
                        + `<td class="angka">${b.hari ?? ''}</td><td class="angka">${b.selisih !== null && !b.janggal && b.hari ? ang(b.selisih / b.hari) : ''}</td>`
                        + `<td class="kecil">${b.sumber === 'bbm' ? 'Data BBM' : 'Dicatat' + (b.oleh ? ' · ' + esc(b.oleh) : '')}</td><td class="kecil">${esc(b.catatan)}</td>`
                        + `<td>${b.bisa_hapus ? `<button type="button" class="tombol-hapus" data-hapus="${b.id}" data-label="${esc(b.tgl)} = ${ang(b.nilai)}">Hapus</button>` : ''}</td></tr>`).join('')
                    + '</tbody></table></div>';
            };

            document.getElementById('tabel-hk').addEventListener('click', e => {
                const tombol = e.target.closest('[data-catat]');
                if (tombol) { e.stopPropagation(); bukaCatat(+tombol.dataset.catat); return; }
                const tr = e.target.closest('tr[data-aset]');
                if (tr) buka(+tr.dataset.aset);
            });
            document.getElementById('d-tutup').addEventListener('click', () => dlg.close());
            dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
            badan.addEventListener('mousemove', e => {
                const tip = badan.querySelector('.tip'), c = e.target.closest('circle[data-tip]');
                if (!tip) return;
                if (!c) { tip.hidden = true; return; }
                const kotak = c.closest('.grafik-hk').getBoundingClientRect(), b = c.getBoundingClientRect();
                tip.innerHTML = c.dataset.tip; tip.hidden = false;
                tip.style.left = (b.left + b.width / 2 - kotak.left) + 'px'; tip.style.top = (b.top - kotak.top) + 'px';
            });
            badan.addEventListener('click', async e => {
                const h = e.target.closest('[data-hapus]');
                if (!h || !confirm(`Hapus catatan ${h.dataset.label}?`)) return;
                const r = await fetch(`${URL_HAPUS}/${h.dataset.hapus}`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': CSRF, Accept: 'application/json'}});
                if (r.ok) { perluMuat = true; buka(asetBuka); } else alert('Gagal menghapus.');
            });
            let perluMuat = false;
            dlg.addEventListener('close', () => { if (perluMuat) location.reload(); });

            // ---- Catat HM / KM
            const dc = document.getElementById('dialog-catat');
            if (!dc) return;
            const f = id => document.getElementById(id);
            const pesan = f('catat-pesan');
            const isiInfo = () => {
                const u = UNIT[f('c-unit').value.trim()];
                f('c-label-nilai').textContent = u ? `Angka ${u.satuan}` : 'Angka';
                f('c-info').textContent = u ? (u.akhir !== null ? `Terakhir ${ang(u.akhir)} ${u.satuan} (${TanggalId.format(new Date(u.tanggal + 'T00:00'))})` : `Belum ada catatan ${u.satuan}`) : '';
            };
            const bukaCatat = id => {
                const nama = Object.keys(UNIT).find(k => UNIT[k].id === id) ?? '';
                f('form-catat').reset();
                f('c-unit').value = nama;
                f('c-tanggal').value = @json(now()->toDateString());
                pesan.textContent = '';
                isiInfo();
                dc.showModal();
                (nama ? f('c-nilai') : f('c-unit')).focus();
            };
            f('c-unit').addEventListener('input', isiInfo);
            f('tombol-catat')?.addEventListener('click', () => bukaCatat(null));
            f('d-catat')?.addEventListener('click', () => bukaCatat(asetBuka));
            f('c-batal').addEventListener('click', () => dc.close());
            const kirim = async yakin => {
                const u = UNIT[f('c-unit').value.trim()];
                if (!u) { pesan.textContent = 'Pilih unit dari daftar.'; f('c-unit').focus(); return; }
                const data = new FormData();
                data.append('aset_id', u.id); data.append('tanggal', f('c-tanggal').value); data.append('nilai', f('c-nilai').value);
                data.append('reset', f('c-reset').checked ? 1 : 0); data.append('catatan', f('c-catatan').value);
                if (yakin) data.append('yakin', 1);
                f('c-simpan').disabled = true;
                try {
                    const r = await fetch(URL_SIMPAN, {method: 'POST', headers: {'X-CSRF-TOKEN': CSRF, Accept: 'application/json'}, body: data});
                    const j = await r.json().catch(() => ({}));
                    if (r.status === 409 && j.konfirmasi) { if (confirm(j.konfirmasi)) return kirim(true); return; }
                    if (!r.ok) { pesan.textContent = j.message || 'Gagal menyimpan.'; return; }
                    dc.close();
                    if (dlg.open && asetBuka === u.id) { perluMuat = true; buka(u.id); } else location.reload();
                } finally { f('c-simpan').disabled = false; }
            };
            f('form-catat').addEventListener('submit', e => { e.preventDefault(); kirim(false); });
        })();
    </script>
@endsection
