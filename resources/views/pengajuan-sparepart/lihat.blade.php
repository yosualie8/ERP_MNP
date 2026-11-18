@extends('layouts.app', ['judul' => 'Pengajuan Sparepart '.$p->nomor])

@section('lebar', '1300px')

@section('isi')
    @php($qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ','))
    <style>
        .psp-kepala { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px 18px; margin-bottom: 14px; font-size: 13.5px; }
        .psp-kepala .k { font-size: 12px; color: var(--redup); }
        .psp-aksi { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
        .psp-aksi form { margin: 0; }
        .kecil { font-size: 11.5px; color: var(--redup); }
        .beda-naik { color: var(--merah); }
        .beda-turun { color: var(--sukses); }
        table.kas tfoot td { font-weight: 700; border-top: 2px solid var(--garis-kuat); }
        .form-putus { border: 1px solid var(--garis); border-radius: 8px; padding: 12px 14px; margin-top: 14px; }
        .form-putus textarea { width: 100%; box-sizing: border-box; }
        /* Status per baris (revisi Admin Plant 17 Nov 2026): warna sama di pilihan, label, ringkasan & latar baris. */
        .pg { --pg: var(--redup); }
        .pg-disetujui { --pg: #5b9cf5; } .pg-dibeli { --pg: #f0a43a; } .pg-terpasang { --pg: #4cc38a; } .pg-ditolak { --pg: #ff7b7f; } .pg-diulang { --pg: #a78bfa; }
        table.kas tr.pg:not(.pg-belum) td { background: color-mix(in srgb, var(--pg) 8%, transparent); }
        table.kas tr.pg:not(.pg-belum) td:first-child { box-shadow: inset 4px 0 0 var(--pg); }
        select.progres { width: 128px; font-weight: 600; color: var(--pg); border-color: color-mix(in srgb, var(--pg) 70%, transparent);
            background-color: color-mix(in srgb, var(--pg) 14%, var(--isian)); }
        select.progres option { color: var(--teks); background: var(--kartu); font-weight: 400; }
        select.progres.menyimpan { opacity: .5; }
        .label-pg { display: inline-block; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 4px; white-space: nowrap;
            color: var(--pg); background: color-mix(in srgb, var(--pg) 16%, transparent); }
        .psp-progres { display: flex; gap: 6px 8px; flex-wrap: wrap; align-items: center; margin: -2px 0 12px; font-size: 13px; }
        .psp-progres .batang { display: flex; height: 8px; border-radius: 4px; overflow: hidden; flex: 1 1 100%; max-width: 520px; background: var(--kartu-2); }
        .psp-progres .batang span { background: var(--pg); }
        .psp-progres .batang span.pg-belum { background: var(--garis-kuat); }
        .cetak-saja { display: none; }
        .foto-unggah { display: flex; gap: 10px 14px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 14px; }
        .foto-unggah label { display: block; margin-bottom: 4px; }
        .foto-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
        .foto-grid figure { margin: 0; border: 1px solid var(--garis); border-radius: 8px; overflow: hidden; background: var(--kartu-2); }
        .foto-grid img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; background: var(--latar); }
        .foto-grid figcaption { padding: 6px 8px; font-size: 13px; display: flex; flex-direction: column; gap: 2px; }
        .foto-grid figcaption form { margin: 2px 0 0; }
        #dialog-foto { max-width: min(96vw, 1200px); max-height: 96dvh; padding: 10px; background: var(--kartu); color: var(--teks); border: 1px solid var(--garis-kuat); border-radius: 10px; }
        #dialog-foto::backdrop { background: rgba(0, 0, 0, .8); }
        #dialog-foto img { display: block; max-width: 100%; max-height: calc(96dvh - 90px); margin: 0 auto; }
        #dialog-foto .ket { margin: 8px 0; font-size: 14px; }
        @media print {
            header, .samping, .psp-aksi, .form-putus, .tak-cetak { display: none !important; }
            .cetak-saja { display: inline-block; }
            .kartu { border: 0 !important; box-shadow: none !important; }
        }
        /* Nota untuk gambar WhatsApp: selalu terang (warna tetap, tidak ikut tema gelap), hanya tampil di salinan html2canvas.
           Font besar (owner 17 Nov 2026): gambar 720px dikecilkan ±setengahnya di layar HP, jadi teks dasar 21px ≈ 11px saat dibaca. */
        #nota-wa { width: 720px; padding: 22px 24px; background: #fff; color: #1f2329; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 21px; line-height: 1.35; }
        #nota-wa .atas { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 4px solid #c62828; padding-bottom: 12px; margin-bottom: 14px; }
        #nota-wa .pt { font-size: 16px; color: #6b7280; letter-spacing: .5px; }
        #nota-wa h2 { margin: 2px 0 0; font-size: 30px; color: #1f2329; }
        #nota-wa .status { font-size: 19px; font-weight: 700; padding: 4px 14px; border-radius: 16px; background: #fef3c7; color: #92400e; white-space: nowrap; }
        #nota-wa .status.hijau { background: #dcfce7; color: #166534; }
        #nota-wa .status.merah { background: #fee2e2; color: #991b1b; }
        #nota-wa .status.abu { background: #e5e7eb; color: #374151; }
        #nota-wa .info { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 18px; margin-bottom: 14px; }
        #nota-wa .info .k { font-size: 16px; color: #6b7280; }
        #nota-wa table { width: 100%; border-collapse: collapse; font-size: 20px; }
        #nota-wa th { background: #f3f4f6; color: #374151; text-align: left; font-weight: 600; padding: 7px 8px; border: 1px solid #d1d5db; }
        #nota-wa td { padding: 7px 8px; border: 1px solid #d1d5db; color: #1f2329; vertical-align: top; background: #fff; }
        #nota-wa .angka { text-align: right; white-space: nowrap; }
        #nota-wa th.angka { white-space: normal; }
        #nota-wa .sub { font-size: 16px; color: #6b7280; }
        #nota-wa .supplier { font-size: 18px; }
        /* status baris di gambar: warna tetap (html2canvas tidak mengenal color-mix) */
        #nota-wa .pg-wa { display: inline-block; font-size: 16px; font-weight: 700; padding: 1px 9px; border-radius: 10px; margin-left: 6px; vertical-align: 2px; }
        #nota-wa .pg-wa.pg-belum { display: none; }
        #nota-wa .pg-wa.pg-disetujui { background: #dbeafe; color: #1d4ed8; } #nota-wa tr.pg-disetujui td { background: #f5f9ff; }
        #nota-wa .pg-wa.pg-dibeli { background: #fef3c7; color: #b45309; } #nota-wa tr.pg-dibeli td { background: #fffbeb; }
        #nota-wa .pg-wa.pg-terpasang { background: #dcfce7; color: #166534; } #nota-wa tr.pg-terpasang td { background: #f2fdf5; }
        #nota-wa .pg-wa.pg-ditolak { background: #fee2e2; color: #991b1b; } #nota-wa tr.pg-ditolak td { background: #fff5f5; }
        #nota-wa .pg-wa.pg-diulang { background: #ede9fe; color: #6d28d9; } #nota-wa tr.pg-diulang td { background: #faf8ff; }
        #nota-wa .progres-wa { margin: -4px 0 12px; font-size: 18px; }
        #nota-wa tfoot td { font-weight: 700; background: #fff7ed; font-size: 22px; }
        #nota-wa .catatan { margin-top: 12px; font-size: 19px; }
        #nota-wa .kaki { margin-top: 14px; font-size: 15px; color: #9ca3af; }
    </style>

    <div class="psp-aksi">
        <a href="{{ route('pengajuan-sparepart.index') }}" class="tombol polos">← Daftar pengajuan</a>
        <button type="button" class="tombol" id="bagikan-wa">📤 Bagikan ke WhatsApp</button>
        <button type="button" class="tombol polos" onclick="window.print()">🖨 Cetak</button>
        @php($nBelum = $p->detail->whereNull('progres')->count())
        @if ($bolehTanda && $nBelum && ($nBelum < $p->detail->count() || $p->status === 'ditolak'))
            <a href="{{ route('pengajuan-sparepart.buat', ['dari' => $p->id]) }}" class="tombol polos" id="ajukan-ulang"
                title="Buat pengajuan baru berisi sparepart yang masih Belum di-ACC">↻ Ajukan ulang yang belum di-ACC ({{ $nBelum }})</a>
        @endif
        @if ($bolehUbah)
            <a href="{{ route('pengajuan-sparepart.ubah', $p) }}" class="tombol polos">✎ Ubah</a>
            <form method="POST" action="{{ route('pengajuan-sparepart.batal', $p) }}" onsubmit="return confirm('Batalkan pengajuan {{ $p->nomor }}?')">@csrf
                <button type="submit" class="tombol polos" style="color: var(--merah);">Batalkan</button></form>
        @endif
    </div>

    @if ($errors->any())<div class="pesan galat">{{ $errors->first() }}</div>@endif

    <div class="kartu">
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
            <h3 style="margin: 0;">Pengajuan Sparepart {{ $p->nomor }}</h3>
            <span class="label {{ \App\Models\PengajuanSparepart::WARNA[$p->status] }}">{{ \App\Models\PengajuanSparepart::STATUS[$p->status] }}</span>
        </div>
        <div class="psp-kepala">
            <div><div class="k">Tanggal</div>{{ $p->tanggal->translatedFormat('j F Y') }}</div>
            <div><div class="k">Diajukan oleh</div>{{ $p->pembuat?->name ?? '—' }}<div class="kecil">{{ $p->created_at?->translatedFormat('j M Y H:i') }}</div></div>
            <div><div class="k">Keperluan / unit</div>{{ $p->keperluan ?? '—' }}</div>
            <div><div class="k">Total</div><b style="font-size: 18px;">Rp {{ rp($p->total) }}</b></div>
            @if ($p->catatan)<div style="grid-column: 1 / -1;"><div class="k">Catatan</div>{{ $p->catatan }}</div>@endif
            @if ($p->diputus_pada)
                <div style="grid-column: 1 / -1;"><div class="k">{{ \App\Models\PengajuanSparepart::STATUS[$p->status] }} oleh</div>{{ $p->pemutus?->name ?? '—' }} · {{ $p->diputus_pada->translatedFormat('j M Y H:i') }}
                    @if ($p->catatan_putusan)<div style="margin-top: 2px;">“{{ $p->catatan_putusan }}”</div>@endif</div>
            @endif
            @if ($p->asal || $p->lanjutan->isNotEmpty())
                <div style="grid-column: 1 / -1;">
                    @if ($p->asal)<div><span class="k">Ajukan ulang dari</span> <a href="{{ route('pengajuan-sparepart.lihat', $p->asal) }}"><b>{{ $p->asal->nomor }}</b></a></div>@endif
                    @if ($p->lanjutan->isNotEmpty())<div><span class="k">Yang belum di-ACC diajukan ulang di</span>
                        @foreach ($p->lanjutan as $l)<a href="{{ route('pengajuan-sparepart.lihat', $l) }}"><b>{{ $l->nomor }}</b></a> ({{ \App\Models\PengajuanSparepart::STATUS[$l->status] }})@if (! $loop->last), @endif @endforeach</div>@endif
                </div>
            @endif
        </div>

        {{-- Ringkasan status barang: diisi skrip dari data-ringkas (ikut berubah saat baris ditandai). --}}
        <div class="psp-progres" id="psp-progres" data-ringkas='@json((object) $p->progres())'></div>

        <div class="gulir" style="padding: 0; border: 1px solid var(--garis); border-radius: 8px;">
            <table class="kas" data-tanpa-saring-kolom>
                <thead><tr><th class="angka">No</th><th>Nama Sparepart</th><th>Jenis</th><th>Part Number</th><th>Merk</th><th>Supplier</th><th class="angka">Qty</th><th>Satuan</th>
                    <th class="angka">Harga Satuan</th><th class="angka">Subtotal</th><th class="angka tak-cetak">Harga Database</th><th>Keterangan</th><th>Status Barang</th></tr></thead>
                <tbody>
                    @foreach ($p->detail as $i => $d)
                        <tr class="t pg pg-{{ $d->progres ?? 'belum' }}" data-id="{{ $d->id }}">
                            <td class="angka">{{ $i + 1 }}</td>
                            <td style="min-width: 150px;"><b>{{ $d->nama_item }}</b></td>
                            <td>{{ $d->jenis }}</td>
                            <td style="white-space: nowrap;">{{ $d->part_number }}</td>
                            <td>{{ $d->merk }}</td>
                            <td>{{ $d->supplier }}</td>
                            <td class="angka">{{ $qty($d->qty) }}</td>
                            <td>{{ $d->satuan }}</td>
                            <td class="angka">{{ rp($d->harga) }}</td>
                            <td class="angka"><b>{{ rp($d->subtotal) }}</b></td>
                            <td class="angka tak-cetak">
                                @if ($d->harga_saran !== null)
                                    {{ rp($d->harga_saran) }}
                                    @if ($d->dasar_harga)<div class="kecil">{{ $d->dasar_harga }}</div>@endif
                                    @if (round($d->harga) != round($d->harga_saran) && $d->harga_saran > 0)
                                        @php($pct = ($d->harga - $d->harga_saran) / $d->harga_saran * 100)
                                        <div class="kecil {{ $pct > 0 ? 'beda-naik' : 'beda-turun' }}">{{ $pct > 0 ? '+' : '' }}{{ number_format($pct, 0, ',', '.') }}% dari harga database</div>
                                    @endif
                                @else
                                    <span class="kecil" style="display: inline-block; min-width: 150px; white-space: normal;">tanpa usulan (belum pernah dibeli untuk jenis ini)</span>
                                @endif
                            </td>
                            <td style="font-size: 12.5px;">{{ $d->keterangan }}</td>
                            <td style="white-space: nowrap;">
                                @if ($d->progres === \App\Models\PengajuanSparepartDetail::DIULANG)
                                    <span class="label-pg pg pg-diulang">Diajukan ulang</span>
                                    @if ($l = $d->lanjutan)
                                        <div class="kecil" style="margin-top: 3px;">→ <a href="{{ route('pengajuan-sparepart.lihat', $l->pengajuan_sparepart_id) }}">{{ $l->pengajuan?->nomor }}</a>
                                            <span class="label-pg pg pg-{{ $l->progres ?? 'belum' }}" style="font-size: 11px;">{{ \App\Models\PengajuanSparepartDetail::labelProgres($l->progres) }}</span></div>
                                    @endif
                                @elseif ($bolehTanda)
                                    <select class="progres pg pg-{{ $d->progres ?? 'belum' }} tak-cetak" data-id="{{ $d->id }}" aria-label="Status {{ $d->nama_item }}">
                                        <option value="">Belum</option>
                                        @foreach (\App\Models\PengajuanSparepartDetail::PROGRES as $k => $l)<option value="{{ $k }}" @selected($d->progres === $k)>{{ $l }}</option>@endforeach
                                    </select>
                                    <span class="label-pg pg pg-{{ $d->progres ?? 'belum' }} cetak-saja">{{ \App\Models\PengajuanSparepartDetail::labelProgres($d->progres) }}</span>
                                    <div class="kecil oleh" style="margin-top: 3px;">{{ $d->progres ? collect([$d->penanda?->name, $d->progres_pada?->translatedFormat('j M H:i')])->filter()->implode(' · ') : '' }}</div>
                                @else
                                    <span class="label-pg pg pg-{{ $d->progres ?? 'belum' }}">{{ \App\Models\PengajuanSparepartDetail::labelProgres($d->progres) }}</span>
                                    @if ($d->progres)<div class="kecil" style="margin-top: 3px;">{{ collect([$d->penanda?->name, $d->progres_pada?->translatedFormat('j M H:i')])->filter()->implode(' · ') }}</div>@endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td colspan="9" class="angka">Total ({{ $p->detail->count() }} item)</td><td class="angka">Rp {{ rp($p->total) }}</td><td class="tak-cetak"></td><td colspan="2"></td></tr></tfoot>
            </table>
        </div>

        @if ($bolehPutus)
            <form method="POST" action="{{ route('pengajuan-sparepart-putus.simpan', $p) }}" class="form-putus">@csrf
                <label for="catatan_putusan">Catatan manajemen (opsional)</label>
                <textarea id="catatan_putusan" name="catatan_putusan" rows="2" maxlength="500" placeholder="mis. disetujui, beli di supplier X / tunda bulan depan"></textarea>
                <div style="display: flex; gap: 8px; margin-top: 10px;">
                    <button type="submit" name="putusan" value="disetujui" class="tombol">✓ Setujui</button>
                    <button type="submit" name="putusan" value="ditolak" class="tombol polos" style="color: var(--merah);" onclick="return confirm('Tolak pengajuan {{ $p->nomor }}?')">✕ Tolak</button>
                </div>
            </form>
        @endif
    </div>

    {{-- Foto progres pekerjaan (owner 17 Nov 2026): semua yang punya menu bisa melihat; unggah / hapus hanya hak tandai (Admin Plant). --}}
    @if ($bolehTanda || $p->foto->isNotEmpty())
        <div class="kartu tak-cetak" id="foto-progres" style="margin-top: 14px;">
            <h3 style="margin: 0 0 10px;">📷 Foto Progres Pekerjaan <span class="redup" style="font-weight: 400; font-size: 14px;">{{ $p->foto->count() }} foto</span></h3>
            @if ($bolehTanda)
                <form method="POST" action="{{ route('pengajuan-sparepart-tandai.foto', $p) }}" enctype="multipart/form-data" class="foto-unggah" id="form-foto">@csrf
                    <div><label for="foto-file">Foto (bisa beberapa sekaligus, maks. {{ \App\Http\Controllers\PengajuanSparepartController::MAKS_FOTO }})</label>
                        <input type="file" id="foto-file" name="foto[]" accept="image/*" multiple required></div>
                    <div style="flex: 1 1 260px;"><label for="foto-ket">Keterangan (opsional)</label>
                        <input id="foto-ket" name="keterangan" maxlength="300" list="daftar-item-foto" placeholder="mis. Filter oli DT 35 terpasang" style="width: 100%;"></div>
                    <button type="submit" class="tombol">⬆ Unggah foto</button>
                </form>
                <datalist id="daftar-item-foto">@foreach ($p->detail->pluck('nama_item')->unique() as $n)<option value="{{ $n }}">@endforeach</datalist>
            @endif
            @if ($p->foto->isNotEmpty())
                <div class="foto-grid">
                    @foreach ($p->foto as $f)
                        <figure>
                            <a href="{{ route('pengajuan-sparepart.foto', $f) }}" class="buka-foto" data-ket="{{ $f->keterangan }}"><img src="{{ route('pengajuan-sparepart.foto', ['foto' => $f, 'kecil' => 1]) }}" alt="{{ $f->keterangan ?? 'Foto progres' }}" loading="lazy"></a>
                            <figcaption>@if ($f->keterangan)<div>{{ $f->keterangan }}</div>@endif
                                <div class="kecil">{{ $f->pengunggah?->name }} · {{ $f->created_at->translatedFormat('j M Y H:i') }}</div>
                                @if ($bolehTanda)
                                    <form method="POST" action="{{ route('pengajuan-sparepart-tandai.foto-hapus', $f) }}" onsubmit="return confirm('Hapus foto ini?')">@csrf @method('DELETE')
                                        <button type="submit" class="tombol-hapus">Hapus</button></form>
                                @endif
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            @elseif ($bolehTanda)
                <p class="redup" style="margin: 10px 0 0;">Belum ada foto. Unggah foto barang yang dibeli / proses pemasangan supaya atasan bisa melihat progresnya.</p>
            @endif
        </div>
        <dialog id="dialog-foto"><img alt=""><div class="ket"></div><button type="button" class="tombol polos" onclick="this.closest('dialog').close()">Tutup</button></dialog>
    @endif

    {{-- Gambar yang dibagikan ke WhatsApp (tersembunyi; dimunculkan hanya di salinan html2canvas). --}}
    @php($status = \App\Models\PengajuanSparepart::STATUS[$p->status])
    <div id="nota-wa" hidden>
        <div class="atas">
            <div><div class="pt">PT MULTI NIAGA PUTRA</div><h2>Pengajuan Sparepart {{ $p->nomor }}</h2></div>
            <span class="status {{ ['disetujui' => 'hijau', 'ditolak' => 'merah', 'batal' => 'abu'][$p->status] ?? '' }}">{{ $status }}</span>
        </div>
        <div class="info">
            <div><div class="k">Tanggal</div>{{ $p->tanggal->translatedFormat('j F Y') }}</div>
            <div><div class="k">Diajukan oleh</div>{{ $p->pembuat?->name ?? '—' }}</div>
            <div style="grid-column: 1 / -1;"><div class="k">Keperluan / unit</div>{{ $p->keperluan ?? '—' }}</div>
        </div>
        <div class="progres-wa" id="progres-wa"></div>
        <table data-tanpa-saring-kolom data-tanpa-beku>
            <thead><tr><th class="angka">No</th><th>Nama Sparepart</th><th>Supplier</th><th class="angka">Qty</th><th class="angka">Harga Satuan</th><th class="angka">Subtotal</th></tr></thead>
            <tbody>
                @foreach ($p->detail as $i => $d)
                    <tr class="pg-{{ $d->progres ?? 'belum' }}" data-id="{{ $d->id }}" data-harga="{{ (float) $d->harga }}" data-subtotal="{{ (float) $d->subtotal }}">
                        <td class="angka">{{ $i + 1 }}</td>
                        <td><b>{{ $d->nama_item }}</b><span class="pg-wa pg-{{ $d->progres ?? 'belum' }}">{{ \App\Models\PengajuanSparepartDetail::labelProgres($d->progres) }}</span>
                            @php($rinci = collect([$d->jenis, $d->part_number, $d->merk, $d->keterangan])->filter()->implode(' · '))
                            @if ($rinci !== '')<div class="sub">{{ $rinci }}</div>@endif</td>
                        <td class="supplier">{{ $d->supplier }}</td>
                        <td class="angka">{{ $qty($d->qty) }} {{ $d->satuan }}</td>
                        <td class="angka h-satuan">{{ rp($d->harga) }}</td>
                        <td class="angka"><b class="h-sub">{{ rp($d->subtotal) }}</b></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="5" class="angka" id="total-wa-label">TOTAL ({{ $p->detail->count() }} item)</td><td class="angka" id="total-wa">Rp {{ rp($p->total) }}</td></tr></tfoot>
        </table>
        @if ($p->catatan)<div class="catatan"><b>Catatan:</b> {{ $p->catatan }}</div>@endif
        @if ($p->diputus_pada)
            <div class="catatan"><b>{{ $status }} oleh</b> {{ $p->pemutus?->name ?? '—' }} · {{ $p->diputus_pada->translatedFormat('j M Y H:i') }}@if ($p->catatan_putusan) — “{{ $p->catatan_putusan }}”@endif</div>
        @endif
        <div class="kaki">ERP MNP · dibuat {{ now()->translatedFormat('j M Y H:i') }}</div>
    </div>

    @php($teksWa = collect([
        "*Pengajuan Sparepart {$p->nomor}*",
        'Tanggal: '.$p->tanggal->translatedFormat('j F Y'),
        'Diajukan oleh: '.($p->pembuat?->name ?? '—'),
        $p->keperluan ? "Keperluan: {$p->keperluan}" : null,
        "Status: {$status}",
        '',
        '%%TOTAL%%',
        $p->catatan ? "Catatan: {$p->catatan}" : null,
        '',
        'Detail: '.route('pengajuan-sparepart.lihat', $p),
    ])->reject(fn ($x) => $x === null)->implode("\n"))

    <script src="{{ asset('js/html2canvas.min.js') }}"></script>
    <script src="{{ asset('js/kecilkan-foto.js') }}?v={{ filemtime(public_path('js/kecilkan-foto.js')) }}"></script>
    <script>
        // Foto progres: klik = tampil besar; unggah = diperkecil di browser dulu (foto HP 3–6 MB → ±300 KB) supaya cepat.
        document.querySelectorAll('.buka-foto').forEach(a => a.addEventListener('click', e => {
            const dlg = document.getElementById('dialog-foto');
            if (!dlg?.showModal) return;
            e.preventDefault();
            dlg.querySelector('img').src = a.href;
            dlg.querySelector('.ket').textContent = a.dataset.ket || '';
            dlg.showModal();
        }));
        document.getElementById('dialog-foto')?.addEventListener('click', e => { if (e.target === e.currentTarget) e.currentTarget.close(); });
        document.getElementById('form-foto')?.addEventListener('submit', async e => {
            const form = e.currentTarget, input = form.querySelector('input[type=file]'), tombol = form.querySelector('button[type=submit]');
            if (form.dataset.siap) return;
            e.preventDefault();
            const maks = {{ \App\Http\Controllers\PengajuanSparepartController::MAKS_FOTO }};
            if (input.files.length > maks) { alert(`Maksimal ${maks} foto sekali unggah.`); return; }
            tombol.disabled = true; tombol.textContent = 'Memperkecil foto…';
            try {
                const dt = new DataTransfer();
                for (const f of input.files) dt.items.add(await kecilkanFoto(f));
                input.files = dt.files;
            } catch (err) { /* gagal memperkecil: kirim file asli */ }
            tombol.textContent = 'Mengunggah…';
            form.dataset.siap = '1';
            form.submit();
        });
    </script>
    <script>
        // Status per baris: pilihan tersimpan langsung; ringkasan, warna baris & gambar WhatsApp ikut berubah.
        const LABEL_PG = {{ \Illuminate\Support\Js::from(['' => 'Belum', ...\App\Models\PengajuanSparepartDetail::PROGRES, \App\Models\PengajuanSparepartDetail::DIULANG => 'Diajukan ulang']) }};
        const kelasPg = (el, k) => { el.className = el.className.replace(/\bpg-(?!wa\b)\S+/g, '').trim() + ' pg-' + (k || 'belum'); };
        function tampilRingkas(r) {
            const el = document.getElementById('psp-progres'), n = Object.values(r).reduce((a, b) => a + b, 0);
            el.dataset.ringkas = JSON.stringify(r);
            const urut = Object.keys(LABEL_PG).filter(k => r[k]);
            el.innerHTML = '<span class="redup">Status barang:</span> ' + urut.map(k => `<span class="label-pg pg pg-${k || 'belum'}">${r[k]} ${LABEL_PG[k]}</span>`).join(' ')
                + `<div class="batang">${urut.map(k => `<span class="pg pg-${k || 'belum'}" style="width: ${r[k] / n * 100}%" title="${r[k]} ${LABEL_PG[k]}"></span>`).join('')}</div>`;
            const ditandai = urut.some(k => k);
            document.getElementById('progres-wa').innerHTML = ditandai ? '<b>Status barang:</b> ' + urut.map(k => `${r[k]} ${LABEL_PG[k].toLowerCase()}`).join(' · ') : '';
        }
        tampilRingkas(JSON.parse(document.getElementById('psp-progres').dataset.ringkas || '{}'));

        document.querySelectorAll('select.progres').forEach(sel => {
            let lama = sel.value;
            sel.addEventListener('change', async () => {
                const id = sel.dataset.id, nilai = sel.value;
                sel.classList.add('menyimpan'); sel.disabled = true;
                try {
                    const res = await fetch(@json(route('pengajuan-sparepart-tandai.progres', $p)), {
                        method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
                        body: JSON.stringify({detail: [Number(id)], progres: nilai || null}),
                    });
                    const d = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(d.message || `HTTP ${res.status}`);
                    lama = nilai;
                    const tr = sel.closest('tr'), nota = document.querySelector(`#nota-wa tr[data-id="${id}"]`);
                    [tr, sel, tr.querySelector('.cetak-saja'), nota, nota?.querySelector('.pg-wa')].forEach(el => el && kelasPg(el, nilai));
                    tr.querySelector('.cetak-saja').textContent = d.label;
                    if (nota) nota.querySelector('.pg-wa').textContent = d.label;
                    tr.querySelector('.oleh').textContent = [d.oleh, d.pada].filter(Boolean).join(' · ');
                    tampilRingkas(d.ringkas);
                } catch (err) {
                    sel.value = lama;
                    alert('Status gagal disimpan: ' + err.message);
                } finally {
                    sel.classList.remove('menyimpan'); sel.disabled = false;
                }
            });
        });

        (() => {
            const teksDasar = @json($teksWa), namaFile = @json($p->nomor.'.png');
            // Owner 18 Nov 2026: barang yang sudah Dibeli / Terpasang harganya dinolkan di gambar & teks WhatsApp,
            // supaya total yang dikirim = total sparepart yang belum dibeli. Halaman & database tetap memakai harga asli.
            const SUDAH = ['dibeli', 'terpasang'], rupiah = n => Math.round(n).toLocaleString('id-ID');
            const hargaNota = () => {
                const baris = [...document.querySelectorAll('#nota-wa tbody tr')];
                let total = 0, nol = 0;
                baris.forEach(tr => {
                    const sudah = SUDAH.some(k => tr.classList.contains('pg-' + k));
                    const harga = sudah ? 0 : Number(tr.dataset.harga), sub = sudah ? 0 : Number(tr.dataset.subtotal);
                    tr.querySelector('.h-satuan').textContent = rupiah(harga);
                    tr.querySelector('.h-sub').textContent = rupiah(sub);
                    total += sub; nol += sudah;
                });
                document.getElementById('total-wa').textContent = 'Rp ' + rupiah(total);
                document.getElementById('total-wa-label').textContent = nol
                    ? `TOTAL BELUM DIBELI (${baris.length - nol} dari ${baris.length} item)` : `TOTAL (${baris.length} item)`;
                return {total, nol, n: baris.length};
            };
            // ringkasan status barang disisipkan sesudah baris Status (ikut tanda terbaru tanpa muat ulang halaman)
            const teksWa = () => {
                const h = hargaNota();
                const baris = h.nol
                    ? `*Total belum dibeli: Rp ${rupiah(h.total)}* (${h.n - h.nol} dari ${h.n} item; ${h.nol} item sudah dibeli/terpasang = Rp 0)`
                    : `*Total: Rp ${rupiah(h.total)}* (${h.n} item)`;
                const teks = teksDasar.replace('%%TOTAL%%', baris);
                const r = JSON.parse(document.getElementById('psp-progres').dataset.ringkas || '{}');
                const urut = Object.keys(LABEL_PG).filter(k => r[k]);
                if (!urut.some(k => k)) return teks;
                return teks.replace(/^(Status: .*)$/m, `$1\nStatus barang: ${urut.map(k => `${r[k]} ${LABEL_PG[k].toLowerCase()}`).join(', ')}`);
            };
            const gambar = async () => {
                const kanvas = await html2canvas(document.getElementById('nota-wa'), {
                    scale: 2, backgroundColor: '#ffffff', windowWidth: 800,
                    onclone: d => { d.getElementById('nota-wa').hidden = false; },
                });
                return new Promise(ok => kanvas.toBlob(ok, 'image/png'));
            };
            const unduh = blob => Object.assign(document.createElement('a'), {href: URL.createObjectURL(blob), download: namaFile}).click();
            // HP: menu bagikan bawaan (pilih WhatsApp → gambar + teks terkirim bersama). Laptop: menu bagikan Windows sering gagal,
            // jadi gambar disalin ke clipboard + diunduh, lalu WhatsApp Web dibuka dengan teks terisi — tinggal Ctrl+V gambarnya.
            const hp = navigator.userAgentData?.mobile ?? /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
            document.getElementById('bagikan-wa').addEventListener('click', async e => {
                const tombol = e.currentTarget, asli = tombol.textContent;
                tombol.disabled = true; tombol.textContent = 'Membuat gambar…';
                const teks = teksWa();
                try {
                    // HP: teks disalin juga (WhatsApp iPhone kadang membuang teks yang dikirim bersama gambar → tinggal tempel).
                    if (hp) navigator.clipboard?.writeText(teks).catch(() => {});
                    const janji = gambar();
                    // Salin ke clipboard harus dimulai langsung saat klik (izin browser); gambarnya menyusul lewat promise.
                    const salin = !hp && window.ClipboardItem && navigator.clipboard?.write
                        ? navigator.clipboard.write([new ClipboardItem({'image/png': janji})]).then(() => true, () => false)
                        : Promise.resolve(false);
                    const blob = await janji;
                    if (hp) {
                        const file = new File([blob], namaFile, {type: 'image/png'});
                        if (navigator.canShare?.({files: [file]})) {
                            try { await navigator.share({files: [file], title: namaFile, text: teks}); return; }
                            catch (err) { if (err.name === 'AbortError') return; }
                        }
                    }
                    const tersalin = await salin;
                    unduh(blob);
                    window.open('https://wa.me/?text=' + encodeURIComponent(teks), '_blank');
                    alert(tersalin
                        ? 'Gambar pengajuan sudah disalin. Di tab WhatsApp yang baru terbuka, pilih chat tujuan — teks total sudah terisi — lalu tekan Ctrl+V untuk menempelkan gambarnya, dan kirim.\n\nGambar juga sudah diunduh bila ingin dilampirkan manual.'
                        : 'Gambar pengajuan sudah diunduh. Di tab WhatsApp yang baru terbuka, pilih chat tujuan (teks total sudah terisi), lalu lampirkan gambar itu.');
                } catch (err) {
                    alert('Gagal membuat gambar: ' + err.message);
                } finally {
                    tombol.disabled = false; tombol.textContent = asli;
                }
            });
        })();
    </script>
@endsection
