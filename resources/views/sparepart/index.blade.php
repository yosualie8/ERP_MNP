@extends('layouts.app', ['judul' => 'Database Sparepart'])

@section('lebar', '1600px')

@section('isi')
    @php($tgl = fn ($t) => \App\Http\Controllers\SparepartController::tgl($t))
    <style>
        .sp-atas { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
        .sp-atas .cari { flex: 1 1 320px; max-width: 520px; }
        .sp-atas .cari input { width: 100%; box-sizing: border-box; }
        .sp-atas .chip { margin: 0; }
        tr.tak-cocok { display: none !important; }
        table.kas td.lebar { min-width: 240px; max-width: 360px; white-space: normal; font-size: 12px; }
        table.kas tr.t.bisa-buka { cursor: pointer; }
        table.kas tr.t.bisa-buka:hover td { background: var(--kartu-2); }
        .nama-item { font-weight: 600; color: var(--teks); }
        .kecil { font-size: 11px; color: var(--redup); }
        #dialog-sp { border: 1px solid var(--garis); border-radius: 12px; background: var(--kartu); color: var(--teks); padding: 0; width: min(1200px, 96vw); max-height: 92vh; max-height: calc(100dvh - 32px); margin: auto; }
        #dialog-sp::backdrop { background: rgba(0, 0, 0, .6); }
        #dialog-sp .kepala { display: flex; gap: 12px; align-items: flex-start; padding: 16px 18px 10px; position: sticky; top: 0; background: var(--kartu); z-index: 3; border-bottom: 1px solid var(--garis); }
        #dialog-sp .kepala h3 { margin: 0; font-size: 18px; }
        #dialog-sp .badan { padding: 14px 18px 18px; }
        .ubin { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 14px; }
        .ubin > div { background: var(--kartu-2); border-radius: 8px; padding: 10px 12px; }
        .ubin b { display: block; font-size: 18px; font-variant-numeric: tabular-nums; margin: 2px 0; }
        .grafik { position: relative; background: var(--latar); border: 1px solid var(--garis); border-radius: 8px; padding: 8px 8px 4px; margin-bottom: 14px; }
        .grafik svg { display: block; max-width: 100%; }
        .grafik .tip { position: absolute; pointer-events: none; background: #0b0c0f; border: 1px solid var(--garis-kuat); border-radius: 6px; padding: 6px 9px; font-size: 12px; line-height: 1.45; white-space: nowrap; transform: translate(-50%, calc(-100% - 12px)); }
        .grafik .tip[hidden] { display: none; }
        .grafik-judul { display: flex; justify-content: space-between; gap: 10px; font-size: 12px; color: var(--redup); padding: 2px 4px 6px; flex-wrap: wrap; }
        .usul { font-style: italic; opacity: .8; }
        .periode-sp { font-size: 12.5px; color: var(--redup); margin: 0 0 10px; }
        .form-sp input[name=part_number]::placeholder { text-transform: none; }
        .info-sp { display: grid; grid-template-columns: 200px 1fr; gap: 6px 14px; background: var(--kartu-2); border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; font-size: 13px; }
        .info-sp .k { color: var(--redup); }
        .form-sp { border: 1px solid var(--garis); border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; }
        .form-sp .judul-form { font-size: 12px; font-weight: 700; color: var(--aksen-terang); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
        .isian-sp { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
        .isian-sp > label { display: flex; flex-direction: column; gap: 4px; min-width: 180px; }
        .isian-sp > label.lebar-isian { flex: 1 1 260px; }
        .isian-sp .lbl { display: block; font-size: 13px; font-weight: 600; color: var(--label, #c3c8d0); margin-bottom: 4px; }
        .isian-sp .pil { display: flex; gap: 6px; }
        .isian-sp .pil label { border: 1px solid var(--isian-garis, #5d6472); border-radius: 8px; padding: 8px 12px; cursor: pointer; font-weight: 400; }
        .isian-sp .pil label:has(input:checked) { border-color: var(--aksen); background: var(--aksen-muda); }
        .isian-sp .pil input { margin: 0 4px 0 0; }
        @media (max-width: 640px) { .info-sp { grid-template-columns: 1fr; } .info-sp .k { margin-top: 6px; } }
    </style>

    <p class="redup" style="margin-top: 0;">Riwayat pembelian sparepart dari <b>Kas Harian</b> (akun Biaya Sparepart*) dan <b>Kas UJ</b> (kategori Sparepart, dibeli sopir di jalan),
        nama barang sudah dibakukan dan dikoreksi Admin Plant. Supplier hanya ditampilkan bila sudah diverifikasi Admin Plant (lainnya kosong, harga tetap tercatat). Data {{ $tgl($info['awal']) }} s.d. <b>{{ $tgl($info['akhir']) }}</b> · {{ rp($info['baris']) }} transaksi.
        Harga satuan = nominal ÷ jumlah yang tertulis (tanpa jumlah dianggap 1). Klik item untuk melihat <b>ringkasan & histori pembeliannya</b>.
        Jenis / Part Number / Merk / Ori-KW <i>miring</i> = usulan dari unit & keterangan pembelian, belum dipastikan. Histori di detail item = 12 bulan terakhir.</p>

    <div class="sp-atas">
        <a href="{{ route('sparepart.index') }}" @class(['chip', 'aktif' => $tab === 'item'])>Daftar Item · {{ $item->count() }}</a>
        <a href="{{ route('sparepart.index', ['tab' => 'riwayat']) }}" @class(['chip', 'aktif' => $tab === 'riwayat'])>Riwayat Pembelian · {{ rp($info['baris']) }}</a>
        <div class="cari"><input type="search" id="sp-cari" placeholder="Cari: filter solar, vbelt, DT 026, FAW, PC200, nama toko…" autocomplete="off" autofocus value="{{ $cari }}"></div>
        <span class="redup" id="sp-jumlah" style="font-size: 13px;"></span>
    </div>

    @if (! $info['baris'])
        <div class="kartu redup">Database sparepart belum diisi.</div>
    @elseif ($tab === 'item')
        <div class="kartu gulir" style="padding: 0;">
            <table class="kas" id="sp-tabel">
                <thead><tr><th>Kategori</th><th>Jenis</th><th>Part Number</th><th>Nama Item</th><th>Satuan</th><th class="angka">Harga Terakhir</th><th>Tgl Pembelian Terakhir</th><th>Supplier Terakhir</th>
                    <th>Merk</th><th>Ori / KW</th></tr></thead>
                <tbody>
                    @foreach ($item as $x)
                        {{-- kolom yang tidak tampil (unit, spesifikasi, supplier lain) tetap ikut dicari lewat data-cari --}}
                        <tr class="t bisa-buka" data-nama="{{ $x->nama }}" data-satuan="{{ $x->satuan }}"
                            data-cari="{{ $x->cari }} | {{ $x->golongan->implode(' ') }} {{ $x->per_unit->pluck('tu')->implode(' ') }} {{ $x->spek->implode(' ') }} {{ $x->supplier->keys()->implode(' ') }}">
                            <td class="kecil">{{ $x->kategori }}</td>
                            <td data-v="{{ $x->jenis }}">@if ($x->jenis)<span @class(['usul' => $x->jenis_usul]) @if ($x->jenis_usul) title="Usulan dari unit di pembelian — belum dipastikan Admin Plant" @endif>{{ $x->jenis }}</span>@endif</td>
                            <td data-v="{{ $x->part }}" style="white-space: nowrap;">@if ($x->part)<span @class(['usul' => $x->part_usul]) @if ($x->part_usul) title="Usulan dari spesifikasi pembelian — belum dipastikan Admin Plant" @endif>{{ $x->part }}</span>@endif</td>
                            <td><span class="nama-item">{{ $x->nama }}</span></td>
                            <td>{{ $x->satuan }}</td>
                            <td class="angka" data-n="{{ $x->akhir->harga_satuan }}"><b>{{ rp($x->akhir->harga_satuan) }}</b></td>
                            <td data-n="{{ $x->akhir->tanggal }}" style="white-space: nowrap;">{{ $tgl($x->akhir->tanggal) }}</td>
                            <td>@if ($x->akhir_sup){{ $x->akhir_sup->supplier }}@if ($x->akhir_sup->tanggal !== $x->akhir->tanggal)<div class="kecil">{{ $tgl($x->akhir_sup->tanggal) }} · Rp {{ rp($x->akhir_sup->harga_satuan) }}</div>@endif @else<span class="redup">—</span>@endif</td>
                            <td data-v="{{ $x->merk }}">@if ($x->merk)<span @class(['usul' => $x->merk_usul]) @if ($x->merk_usul) title="Usulan dari keterangan pembelian — belum dipastikan Admin Plant" @endif>{{ $x->merk }}</span>@endif</td>
                            <td data-v="{{ \App\Models\SparepartItem::KUALITAS[$x->kualitas] ?? '' }}">@if ($x->kualitas)<span @class(['label', 'hijau' => $x->kualitas === 'ori', 'kuning' => $x->kualitas === 'kw', 'usul' => $x->kualitas_usul]) @if ($x->kualitas_usul) title="Usulan dari keterangan pembelian — belum dipastikan Admin Plant" @endif>{{ \App\Models\SparepartItem::KUALITAS[$x->kualitas] }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="redup" style="font-size: 12px;">Belanja satu nominal untuk beberapa barang sekaligus ({{ rp($jumlahPaket) }} transaksi "paket"), yang tidak dirinci, dan yang belum dikenali
            tidak masuk daftar item — lihat di Riwayat Pembelian. Paket yang memuat suatu item ikut ditampilkan di histori item itu.</p>
    @else
        <div class="sp-atas">
            <span class="redup" style="font-size: 13px;">Periode:</span>
            @foreach (['6' => '6 bulan terakhir', '12' => '12 bulan terakhir', 'semua' => 'Semua (sejak '.$tgl($info['awal']).')'] as $k => $l)
                <a href="{{ route('sparepart.index', array_filter(['tab' => 'riwayat', 'periode' => $k === '6' ? null : $k])) }}" @class(['chip', 'aktif' => $periode === $k])>{{ $l }}</a>
            @endforeach
            <span class="redup" style="font-size: 13px;">{{ rp($riwayat->count()) }} transaksi</span>
        </div>
        <div class="kartu gulir" style="padding: 0;">
            <table class="kas" id="sp-tabel">
                <thead><tr><th>Tanggal</th><th>Kategori</th><th>Nama Item</th><th>Spesifikasi</th><th>Unit</th><th>Tipe Unit</th><th>No Unit</th><th class="angka">Qty</th><th>Satuan</th>
                    <th class="angka">Harga Satuan</th><th class="angka">Total</th><th>Supplier / Penerima</th><th>PIC</th><th>Keterangan Asli</th><th>Keterangan Transfer / DO</th><th>Sumber</th><th>Ref</th></tr></thead>
                <tbody>
                    @foreach ($riwayat as $b)
                        <tr class="t" @if (! in_array($b->kategori, \App\Models\SparepartBeli::BUKAN_ITEM, true)) data-nama="{{ $b->nama_item }}" data-satuan="{{ $b->satuan }}" @endif>
                            <td data-n="{{ $b->tanggal }}" style="white-space: nowrap;">{{ $tgl($b->tanggal) }}</td>
                            <td class="kecil">{{ $b->kategori }}</td>
                            <td><span class="nama-item">{{ $b->nama_item }}</span></td>
                            <td class="kecil">{{ $b->spesifikasi }}</td>
                            <td>{{ $b->golongan }}</td>
                            <td>{{ $b->tipe_unit }}</td>
                            <td style="white-space: nowrap;">{{ $b->no_unit }}</td>
                            <td class="angka" data-n="{{ (float) $b->qty }}">{{ rtrim(rtrim(number_format((float) $b->qty, 2, ',', '.'), '0'), ',') }}</td>
                            <td>{{ $b->satuan }}</td>
                            <td class="angka" data-n="{{ (float) $b->harga_satuan }}">{{ rp($b->harga_satuan) }}</td>
                            <td class="angka" data-n="{{ (float) $b->total }}">{{ rp($b->total) }}</td>
                            <td>{{ $b->supplier }}</td>
                            <td>{{ $b->pic }}</td>
                            <td class="lebar">{{ $b->keterangan }}</td>
                            <td class="lebar redup">{{ $b->keterangan_transfer }}</td>
                            <td class="kecil">{{ $b->sumber }}</td>
                            <td class="kecil">{{ $b->ref }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <dialog id="dialog-sp">
        <div class="kepala">
            <div style="flex: 1; min-width: 0;"><h3 id="d-nama"></h3><div class="redup" id="d-sub" style="font-size: 13px; margin-top: 2px;"></div></div>
            <button type="button" class="tombol polos" id="d-tutup">Tutup</button>
        </div>
        <div class="badan" id="d-badan"></div>
    </dialog>
    <datalist id="daftar-jenis">
        @foreach (collect(\App\Support\DaftarSparepart::JENIS)->merge(\App\Models\SparepartItem::whereNotNull('jenis')->distinct()->pluck('jenis'))->unique() as $j)<option value="{{ $j }}">@endforeach
    </datalist>
    <datalist id="daftar-merk">
        @foreach (collect(\App\Support\DaftarSparepart::MERK)->map(fn ($m) => ucfirst(strtolower($m)))->merge(\App\Models\SparepartItem::whereNotNull('merk')->distinct()->pluck('merk'))->unique()->sort() as $m)<option value="{{ $m }}">@endforeach
    </datalist>

    <script>
        (() => {
            const URL_RIWAYAT = @json(route('sparepart.riwayat')), URL_SIMPAN = @json(route('sparepart-ubah.simpan'));
            const CSRF = document.querySelector('meta[name=csrf-token]').content;
            const tabel = document.getElementById('sp-tabel'), cari = document.getElementById('sp-cari'), jumlah = document.getElementById('sp-jumlah');
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const rp = n => Math.round(Number(n) || 0).toLocaleString('id-ID');
            const qty = n => Number(n).toLocaleString('id-ID', {maximumFractionDigits: 2});
            // Rupiah ringkas untuk sumbu: 350 rb, 1,2 jt
            const rpPendek = n => n >= 1e6 ? (n / 1e6).toLocaleString('id-ID', {maximumFractionDigits: 1}) + ' jt' : n >= 1e3 ? Math.round(n / 1e3) + ' rb' : String(Math.round(n));

            // ---- Pencarian: semua kata harus ada (nama, unit, supplier, spesifikasi, keterangan asli).
            // "DT 26" / "dt-026" / "DT026" disamakan jadi dt026 (supaya "026" tidak cocok dengan tahun 2026); kata juga dicocokkan tanpa spasi/tanda
            // ("vanbelt" = "Van Belt", "v-belt" = "vbelt"), plus beberapa istilah lapangan.
            const ISTILAH = {vbelt: 'vanbelt', fanbelt: 'vanbelt', laher: 'bearing', saringan: 'filter', accu: 'aki'};
            const rapikan = s => s.toLowerCase().replace(/\b(dt|lv|dz)\s*-?\s*0*(\d{1,3})\b/g, (_, a, n) => a + n.padStart(3, '0')).replace(/\s+/g, ' ');
            const padat = s => s.replace(/[^a-z0-9]/g, '');
            if (tabel) {
                const baris = [...tabel.tBodies[0].rows].map(tr => {
                    const teks = rapikan(tr.textContent + ' ' + (tr.dataset.cari || ''));
                    return {tr, teks, padat: padat(teks)};
                });
                const cocok = (b, k) => b.teks.includes(k) || (padat(k).length > 2 && b.padat.includes(padat(k))) || (ISTILAH[padat(k)] && b.padat.includes(ISTILAH[padat(k)]));
                const terapkan = () => {
                    const kata = rapikan(cari.value).trim().split(' ').filter(Boolean);
                    let n = 0;
                    baris.forEach(b => { const ok = kata.every(k => cocok(b, k)); b.tr.classList.toggle('tak-cocok', !ok); n += ok; });
                    jumlah.textContent = kata.length ? `${n.toLocaleString('id-ID')} cocok` : '';
                };
                let jeda;
                cari.addEventListener('input', () => { clearTimeout(jeda); jeda = setTimeout(terapkan, 120); });
                if (cari.value) terapkan();
                tabel.tBodies[0].addEventListener('click', e => {
                    const tr = e.target.closest('tr[data-nama]');
                    if (tr && !window.getSelection().toString()) buka(tr.dataset.nama, tr.dataset.satuan);
                });
                tabel.tBodies[0].addEventListener('keydown', e => {
                    const tr = e.target.closest?.('tr[data-nama]') || document.querySelector('#sp-tabel td.sel-aktif')?.closest('tr[data-nama]');
                    if (e.key === 'Enter' && tr) { e.preventDefault(); buka(tr.dataset.nama, tr.dataset.satuan); }
                });
            }

            // ---- Dialog histori harga
            const dlg = document.getElementById('dialog-sp'), badan = document.getElementById('d-badan');
            document.getElementById('d-tutup').addEventListener('click', () => dlg.close());
            dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });

            async function buka(nama, satuan) {
                document.getElementById('d-nama').textContent = nama;
                document.getElementById('d-sub').textContent = satuan ? 'per ' + satuan : '';
                badan.innerHTML = '<p class="redup">Memuat histori…</p>';
                if (!dlg.open) dlg.showModal();
                try {
                    const r = await fetch(URL_RIWAYAT + '?' + new URLSearchParams({nama, satuan: satuan || ''}), {headers: {Accept: 'application/json'}});
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    const j = await r.json();
                    tampil(nama, satuan, j.baris, j);
                } catch (err) {
                    badan.innerHTML = `<p class="pesan galat">Histori gagal dimuat (${esc(err.message)}). Coba lagi.</p>`;
                }
            }

            const KUALITAS = {ori: 'Ori', kw: 'KW'};
            function tampil(nama, satuan, data, j) {
                // urut tanggal pembelian terbaru dulu (server sudah urut; dijaga lagi di sini, sort stabil → urutan id per tanggal tetap)
                data = [...data].sort((a, b) => b.tanggal.localeCompare(a.tanggal));
                const item = data.filter(d => !d.paket), paket = data.filter(d => d.paket);
                const sat = satuan ? '/' + esc(satuan) : '';
                const rs = j.ringkas, at = j.atribut || {};
                let ubin = `<div class="periode-sp">Data <b>12 bulan terakhir</b> (sejak ${esc(j.sejak)}) · urut tanggal pembelian terbaru</div>`;
                if (!item.length) {
                    document.getElementById('d-sub').textContent = satuan ? 'per ' + satuan : '';
                    ubin += `<p class="pesan">Tidak ada pembelian item ini dalam 12 bulan terakhir.${j.terakhir ? ` Pembelian terakhir <b>${esc(j.terakhir.tgl)}</b> · Rp ${rp(j.terakhir.harga)}${sat}${j.terakhir.supplier ? ' · ' + esc(j.terakhir.supplier) : ''}.` : ''}</p>`;
                }
                if (item.length) {
                    const akhir = item[0], murah = item.reduce((a, b) => b.harga < a.harga ? b : a), mahal = item.reduce((a, b) => b.harga > a.harga ? b : a);
                    const rata = item.reduce((s, d) => s + d.harga, 0) / item.length;
                    document.getElementById('d-sub').textContent = [rs?.kategori, satuan ? 'per ' + satuan : '', `${item.length}× beli`, paket.length ? `+ ${paket.length} paket` : ''].filter(Boolean).join(' · ');
                    ubin += `<div class="ubin">
                        <div><span class="kecil">Harga terakhir</span><b>Rp ${rp(akhir.harga)}${sat}</b><span class="kecil">${[akhir.tgl, akhir.supplier].filter(Boolean).map(esc).join(' · ')}</span></div>
                        <div><span class="kecil">Termurah</span><b>Rp ${rp(murah.harga)}${sat}</b><span class="kecil">${[murah.tgl, murah.supplier].filter(Boolean).map(esc).join(' · ')}</span></div>
                        <div><span class="kecil">Tertinggi</span><b>Rp ${rp(mahal.harga)}${sat}</b><span class="kecil">${[mahal.tgl, mahal.supplier].filter(Boolean).map(esc).join(' · ')}</span></div>
                        <div><span class="kecil">Rata-rata</span><b>Rp ${rp(rata)}${sat}</b><span class="kecil">dari ${item.length} pembelian</span></div>
                        <div><span class="kecil">Jumlah dibeli</span><b>${qty(rs?.qty ?? 0)} ${esc(satuan || '')}</b><span class="kecil">${item.length}× dalam 12 bulan</span></div>
                        <div><span class="kecil">Total belanja</span><b>Rp ${rp(rs?.total ?? 0)}</b><span class="kecil">12 bulan, tanpa paket</span></div>
                    </div>`;
                }
                // ringkasan item: jenis, part number, merk, Ori/KW (dari semua pembelian) + unit pemakai, spesifikasi, supplier (12 bulan)
                const pil = (v, usul) => v ? `<span class="${usul ? 'usul' : ''}" ${usul ? 'title="Usulan dari keterangan pembelian — belum dipastikan"' : ''}>${esc(v)}</span>${usul ? ' <span class="kecil">(usulan)</span>' : ''}` : '<span class="redup">—</span>';
                const info = [
                    ['Jenis', pil(at.jenis, at.jenis_usul)],
                    ['Part number', pil(at.part, at.part_usul)],
                    ['Merk', pil(at.merk, at.merk_usul)],
                    ['Ori / KW', at.kualitas ? `<span class="label ${at.kualitas === 'ori' ? 'hijau' : 'kuning'} ${at.kualitas_usul ? 'usul' : ''}">${KUALITAS[at.kualitas]}</span>${at.kualitas_usul ? ' <span class="kecil">(usulan)</span>' : ''}` : '<span class="redup">—</span>'],
                ];
                if (rs) info.push(
                    ['Harga terakhir per tipe unit', rs.per_unit.length ? rs.per_unit.map(u => `${esc(u.tu)}: <b>Rp ${rp(u.harga)}</b> <span class="kecil">(${u.n}×)</span>`).join(' · ') : '<span class="redup">unit tidak disebut</span>'],
                    ['Dipakai untuk', rs.golongan.length ? rs.golongan.map(esc).join(', ') : '<span class="redup">—</span>'],
                    ['Spesifikasi tertulis', rs.spek.length ? rs.spek.map(esc).join(', ') : '<span class="redup">—</span>'],
                    ['Supplier (terverifikasi)', rs.supplier.length ? rs.supplier.map(s => `${esc(s.nama)} <span class="kecil">(${s.n}×)</span>`).join(', ') : '<span class="redup">belum ada yang terverifikasi</span>'],
                );
                if (j.isian.catatan) info.push(['Catatan Admin Plant', esc(j.isian.catatan)]);
                ubin += `<div class="info-sp">${info.map(([k, v]) => `<div class="k">${k}</div><div>${v}</div>`).join('')}</div>`;
                if (j.bisa_ubah) ubin += formItem(nama, satuan, j.isian, at);
                const baris = data.map(d => `<tr class="t">
                    <td data-n="${d.tanggal}" style="white-space: nowrap;">${esc(d.tgl)}</td>
                    <td>${d.paket ? '<span class="label kuning" title="Satu nominal untuk beberapa barang — harga bukan harga item ini saja">Paket</span> ' + esc(d.nama) : esc(d.spek)}</td>
                    <td>${esc(d.golongan)}</td><td>${esc(d.tipe_unit)}</td><td style="white-space: nowrap;">${esc(d.no_unit)}</td>
                    <td class="angka" data-n="${d.qty}">${qty(d.qty)}</td><td>${esc(d.satuan)}</td>
                    <td class="angka" data-n="${d.paket ? '' : d.harga}">${d.paket ? '<span class="redup">—</span>' : '<b>' + rp(d.harga) + '</b>'}</td>
                    <td class="angka" data-n="${d.total}">${rp(d.total)}</td>
                    <td>${esc(d.supplier)}</td><td>${esc(d.pic)}</td><td class="lebar">${esc(d.keterangan)}</td><td class="kecil">${esc(d.sumber)} ${esc(d.ref)}</td></tr>`).join('');
                badan.innerHTML = ubin + (item.length ? grafik(item, satuan) : '') + `
                    <div class="gulir" style="padding: 0; border: 1px solid var(--garis); border-radius: 8px;">
                    <table class="kas" id="d-tabel"><thead><tr><th>Tanggal</th><th>Spesifikasi / Isi Paket</th><th>Unit</th><th>Tipe Unit</th><th>No Unit</th><th class="angka">Qty</th><th>Satuan</th>
                        <th class="angka">Harga Satuan</th><th class="angka">Total</th><th>Supplier</th><th>PIC</th><th>Keterangan Asli</th><th>Sumber</th></tr></thead>
                    <tbody>${baris || '<tr><td colspan="13" class="redup">Tidak ada pembelian dalam 12 bulan terakhir.</td></tr>'}</tbody></table></div>`;
                pasangTip();
                pasangForm();
                window.SaringKolom?.pindai();
            }

            // ---- Isi Jenis / Part Number / Merk / Ori-KW (hak 'sparepart-ubah'); semua kosong = kembali ke usulan otomatis
            function formItem(nama, satuan, isian, rs) {
                const k = isian.kualitas || '';
                return `<form class="form-sp" data-nama="${esc(nama)}" data-satuan="${esc(satuan || '')}">
                    <div class="judul-form">Jenis, part number, merk & Ori / KW item ini ${isian.oleh ? `<span class="kecil">· terakhir diubah ${esc(isian.oleh)}, ${esc(isian.kapan)}</span>` : ''}</div>
                    <div class="isian-sp">
                        <label>Jenis<input name="jenis" list="daftar-jenis" maxlength="80" autocomplete="off" value="${esc(isian.jenis || '')}" placeholder="${esc(rs.jenis_usul ? 'usulan: ' + rs.jenis : 'Alat Berat / Truck / …')}"></label>
                        <label>Part number<input name="part_number" maxlength="80" autocomplete="off" value="${esc(isian.part_number || '')}" placeholder="${esc(rs.part_usul ? 'usulan: ' + rs.part : 'mis. P502466')}" style="text-transform: uppercase;"></label>
                        <label>Merk<input name="merk" list="daftar-merk" maxlength="80" autocomplete="off" value="${esc(isian.merk || '')}" placeholder="${esc(rs.merk_usul ? 'usulan: ' + rs.merk : 'mis. Sakura, Donaldson')}"></label>
                        <div><span class="lbl">Ori / KW</span><div class="pil">${[['', '—'], ['ori', 'Ori'], ['kw', 'KW']].map(([v, l]) => `<label><input type="radio" name="kualitas" value="${v}" ${k === v ? 'checked' : ''}> ${l}</label>`).join('')}</div></div>
                        <label class="lebar-isian">Catatan<input name="catatan" maxlength="300" autocomplete="off" value="${esc(isian.catatan || '')}" placeholder="opsional, mis. pakai yang Ori untuk PC200"></label>
                        <button type="submit" class="tombol">Simpan</button>
                    </div>
                    <div class="pesan galat" hidden></div>
                </form>`;
            }
            function pasangForm() {
                const f = badan.querySelector('.form-sp');
                if (!f) return;
                f.addEventListener('submit', async e => {
                    e.preventDefault();
                    const tombol = f.querySelector('button'), galat = f.querySelector('.pesan');
                    const data = new FormData(f);
                    data.append('nama', f.dataset.nama); data.append('satuan', f.dataset.satuan);
                    tombol.disabled = true; galat.hidden = true;
                    try {
                        const r = await fetch(URL_SIMPAN, {method: 'POST', headers: {'X-CSRF-TOKEN': CSRF, Accept: 'application/json'}, body: data});
                        if (!r.ok) { const j = await r.json().catch(() => ({})); throw new Error(j.message || 'HTTP ' + r.status); }
                        berubah = true;
                        buka(f.dataset.nama, f.dataset.satuan);
                    } catch (err) {
                        galat.textContent = 'Gagal menyimpan: ' + err.message; galat.hidden = false;
                    } finally { tombol.disabled = false; }
                });
            }
            // sesudah merk / Ori-KW diubah, muat ulang daftar saat dialog ditutup (posisi cari tetap)
            let berubah = false;
            dlg.addEventListener('close', () => {
                if (!berubah) return;
                const u = new URL(location.href);
                if (cari.value) u.searchParams.set('q', cari.value); else u.searchParams.delete('q');
                location.href = u.toString();
            });

            // Grafik titik harga satuan per pembelian (bukan garis: tiap titik bisa beda unit / supplier, jadi tidak disambung).
            function grafik(item, satuan) {
                const W = Math.max(320, Math.round(badan.clientWidth - 54)), H = 260, ki = 64, ka = 16, at = 14, ba = 30;
                const waktu = item.map(d => Date.parse(d.tanggal));
                let t0 = Math.min(...waktu), t1 = Math.max(...waktu);
                if (t1 - t0 < 30 * 864e5) { t0 -= 15 * 864e5; t1 += 15 * 864e5; }
                const maks = Math.max(...item.map(d => d.harga)) || 1;
                const langkah = (() => { const kasar = maks / 4, p = 10 ** Math.floor(Math.log10(kasar)); return [1, 2, 2.5, 5, 10].map(m => m * p).find(s => s >= kasar); })();
                const atas = Math.ceil(maks / langkah) * langkah;
                const x = t => ki + (t - t0) / (t1 - t0) * (W - ki - ka), y = v => at + (1 - v / atas) * (H - at - ba);
                let svg = '';
                for (let v = 0; v <= atas + 1e-6; v += langkah) {
                    svg += `<line x1="${ki}" x2="${W - ka}" y1="${y(v)}" y2="${y(v)}" stroke="${v ? '#262a31' : '#3b404b'}" stroke-width="1"/>
                        <text x="${ki - 8}" y="${y(v) + 4}" text-anchor="end" font-size="11" fill="#959ba5">${rpPendek(v)}</text>`;
                }
                const bln = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                const rentangBln = (t1 - t0) / (30.4 * 864e5), tiap = [1, 2, 3, 6, 12].find(k => rentangBln / k <= 8) || 12;
                const d0 = new Date(t0); let tk = new Date(d0.getFullYear(), d0.getMonth() + 1, 1);
                while (tk.getMonth() % tiap) tk = new Date(tk.getFullYear(), tk.getMonth() + 1, 1);
                for (; tk.getTime() <= t1; tk = new Date(tk.getFullYear(), tk.getMonth() + tiap, 1)) {
                    const px = x(tk.getTime());
                    svg += `<line x1="${px}" x2="${px}" y1="${H - ba}" y2="${H - ba + 5}" stroke="#3b404b"/>
                        <text x="${px}" y="${H - ba + 18}" text-anchor="middle" font-size="11" fill="#959ba5">${bln[tk.getMonth()]} ${String(tk.getFullYear()).slice(2)}</text>`;
                }
                // titik terlama dulu supaya yang terbaru di atas; titik terbaru diberi label harga
                [...item].reverse().forEach((d, i, arr) => {
                    const cx = x(Date.parse(d.tanggal)), cy = y(d.harga), akhir = i === arr.length - 1;
                    const tip = `${d.tgl} · Rp ${rp(d.harga)}${satuan ? '/' + satuan : ''}|${[d.tipe_unit, d.no_unit].filter(Boolean).join(' ') || 'unit tidak disebut'} · ${qty(d.qty)} ${d.satuan || ''}|${d.supplier || ''}`;
                    svg += `<g class="titik" data-tip="${esc(tip)}" data-x="${cx}" data-y="${cy}">
                        <circle cx="${cx}" cy="${cy}" r="12" fill="transparent"/>
                        <circle cx="${cx}" cy="${cy}" r="${akhir ? 5.5 : 4.5}" fill="#6ea8fe" stroke="#0e0f12" stroke-width="2"/></g>`;
                    if (akhir) svg += `<text x="${Math.min(cx + 9, W - ka)}" y="${cy - 9}" text-anchor="${cx > W - 120 ? 'end' : 'start'}" font-size="12" font-weight="600" fill="#e8e9ec">Rp ${rp(d.harga)}</text>`;
                });
                return `<div class="grafik"><div class="grafik-judul"><span>Harga satuan per pembelian${satuan ? ' (Rp/' + esc(satuan) + ')' : ''} — sentuh / arahkan ke titik untuk rincian</span>
                    <span>${item.length} titik · paket tidak digambar</span></div>
                    <svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-label="Grafik histori harga">${svg}</svg><div class="tip" hidden></div></div>`;
            }

            function pasangTip() {
                const kotak = badan.querySelector('.grafik');
                if (!kotak) return;
                const svg = kotak.querySelector('svg'), tip = kotak.querySelector('.tip');
                const tunjuk = g => {
                    const r = svg.getBoundingClientRect(), k = kotak.getBoundingClientRect(), vb = svg.viewBox.baseVal;
                    const [a, b, c] = g.dataset.tip.split('|');
                    tip.innerHTML = `<b>${esc(a)}</b><br>${esc(b)}<br><span class="redup">${esc(c)}</span>`;
                    tip.style.left = (r.left - k.left + g.dataset.x / vb.width * r.width) + 'px';
                    tip.style.top = (r.top - k.top + g.dataset.y / vb.height * r.height) + 'px';
                    tip.hidden = false;
                    const lebar = tip.offsetWidth / 2, kiri = parseFloat(tip.style.left);
                    tip.style.left = Math.max(lebar + 4, Math.min(k.width - lebar - 4, kiri)) + 'px';
                };
                svg.querySelectorAll('.titik').forEach(g => {
                    g.addEventListener('pointerenter', () => tunjuk(g));
                    g.addEventListener('click', () => tunjuk(g));
                });
                svg.addEventListener('pointerleave', () => { tip.hidden = true; });
            }
        })();
    </script>
@endsection
