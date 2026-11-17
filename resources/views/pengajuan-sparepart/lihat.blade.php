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
        @media print {
            header, .samping, .psp-aksi, .form-putus, .tak-cetak { display: none !important; }
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
        #nota-wa .sub { font-size: 16px; color: #6b7280; }
        #nota-wa tfoot td { font-weight: 700; background: #fff7ed; font-size: 22px; }
        #nota-wa .catatan { margin-top: 12px; font-size: 19px; }
        #nota-wa .kaki { margin-top: 14px; font-size: 15px; color: #9ca3af; }
    </style>

    <div class="psp-aksi">
        <a href="{{ route('pengajuan-sparepart.index') }}" class="tombol polos">← Daftar pengajuan</a>
        <button type="button" class="tombol" id="bagikan-wa">📤 Bagikan ke WhatsApp</button>
        <button type="button" class="tombol polos" onclick="window.print()">🖨 Cetak</button>
        @if ($bolehUbah)
            <a href="{{ route('pengajuan-sparepart.ubah', $p) }}" class="tombol polos">✎ Ubah</a>
            <form method="POST" action="{{ route('pengajuan-sparepart.batal', $p) }}" onsubmit="return confirm('Batalkan pengajuan {{ $p->nomor }}?')">@csrf
                <button type="submit" class="tombol polos" style="color: var(--merah);">Batalkan</button></form>
        @endif
    </div>

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
        </div>

        <div class="gulir" style="padding: 0; border: 1px solid var(--garis); border-radius: 8px;">
            <table class="kas" data-tanpa-saring-kolom>
                <thead><tr><th class="angka">No</th><th>Nama Sparepart</th><th>Jenis</th><th>Part Number</th><th>Merk</th><th class="angka">Qty</th><th>Satuan</th>
                    <th class="angka">Harga Satuan</th><th class="angka">Subtotal</th><th class="angka tak-cetak">Harga Database</th><th>Keterangan</th></tr></thead>
                <tbody>
                    @foreach ($p->detail as $i => $d)
                        <tr class="t">
                            <td class="angka">{{ $i + 1 }}</td>
                            <td><b>{{ $d->nama_item }}</b></td>
                            <td>{{ $d->jenis }}</td>
                            <td style="white-space: nowrap;">{{ $d->part_number }}</td>
                            <td>{{ $d->merk }}</td>
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
                                    <span class="kecil">tanpa usulan (belum pernah dibeli untuk jenis ini)</span>
                                @endif
                            </td>
                            <td style="font-size: 12.5px;">{{ $d->keterangan }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td colspan="8" class="angka">Total ({{ $p->detail->count() }} item)</td><td class="angka">Rp {{ rp($p->total) }}</td><td colspan="2" class="tak-cetak"></td></tr></tfoot>
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
        <table data-tanpa-saring-kolom data-tanpa-beku>
            <thead><tr><th class="angka">No</th><th>Nama Sparepart</th><th class="angka">Qty</th><th class="angka">Harga Satuan</th><th class="angka">Subtotal</th></tr></thead>
            <tbody>
                @foreach ($p->detail as $i => $d)
                    <tr>
                        <td class="angka">{{ $i + 1 }}</td>
                        <td><b>{{ $d->nama_item }}</b>
                            @php($rinci = collect([$d->jenis, $d->part_number, $d->merk, $d->keterangan])->filter()->implode(' · '))
                            @if ($rinci !== '')<div class="sub">{{ $rinci }}</div>@endif</td>
                        <td class="angka">{{ $qty($d->qty) }} {{ $d->satuan }}</td>
                        <td class="angka">{{ rp($d->harga) }}</td>
                        <td class="angka"><b>{{ rp($d->subtotal) }}</b></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="4" class="angka">TOTAL ({{ $p->detail->count() }} item)</td><td class="angka">Rp {{ rp($p->total) }}</td></tr></tfoot>
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
        '*Total: Rp '.rp($p->total).'* ('.$p->detail->count().' item)',
        $p->catatan ? "Catatan: {$p->catatan}" : null,
        '',
        'Detail: '.route('pengajuan-sparepart.lihat', $p),
    ])->reject(fn ($x) => $x === null)->implode("\n"))

    <script src="{{ asset('js/html2canvas.min.js') }}"></script>
    <script>
        (() => {
            const teks = @json($teksWa), namaFile = @json($p->nomor.'.png');
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
