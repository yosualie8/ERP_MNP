@extends('layouts.app', ['judul' => 'Performa Ritasi'])

@section('lebar', '1600px')

@section('isi')
    <style>
        .alat-performa { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
        .gulir-rekap { overflow-x: auto; border-radius: 8px; }
        /* Area yang dijadikan gambar: warna terang seperti pivot Excel, tidak ikut tema gelap. */
        .rekap-kertas { width: max-content; min-width: 100%; background: #fff; color: #111; padding: 12px; font-family: Calibri, Arial, sans-serif; }
        .rekap-kertas .judul-rekap { font-size: 18px; font-weight: 700; margin: 0 0 2px; }
        .rekap-kertas .sub-rekap { font-size: 13px; color: #444; margin: 0 0 8px; }
        table.pivot { border-collapse: collapse; font-size: 14px; }
        table.pivot th, table.pivot td { border: 1px solid #9aa4b5; padding: 3px 8px; text-align: center; min-width: 38px; white-space: nowrap; }
        table.pivot thead th { background: #d9e1f2; font-weight: 700; }
        table.pivot th.kol-label, table.pivot td.kol-label { text-align: left; min-width: 96px; font-weight: 700; }
        table.pivot .gt { min-width: 84px; font-weight: 700; }
        table.pivot tr.bln td { background: #00b0f0; font-weight: 700; cursor: pointer; }
        table.pivot tr.bln.kini td { background: #ffff00; }
        table.pivot tr.hari td { background: #fff; font-weight: 400; }
        table.pivot tr.hari td.kol-label { padding-left: 22px; font-weight: 700; }
        table.pivot tr.hari.kini td.kol-label, table.pivot tr.hari.kini td.gt { background: #ffff00; }
        table.pivot tr.hari td.nol { background: #ff0000; }
        table.pivot tr.hari[hidden] { display: none; }
        table.pivot tfoot td { background: #ffff00; font-weight: 700; }
        table.pivot .tanda { display: inline-block; width: 14px; font-family: monospace; }
        .rekap-kertas .kaki-rekap { font-size: 11px; color: #666; margin-top: 6px; }
    </style>

    @php
        $bulanKini = array_key_last($data['bulan']);
        $judulTujuan = $tujuan ?? 'Semua tujuan buangan';
        $namaFile = 'Performa Ritasi '.$tahun.' - '.($tujuan ?? 'Semua').' ('.now()->format('Y-m-d').').png';
        $teksBagikan = 'Performa ritasi DT '.$tahun.' · '.$judulTujuan.' · total '.number_format($data['jumlah'], 0, ',', '.').' rit'
            .($data['terakhir'] ? ', data s/d '.$data['terakhir']->translatedFormat('j M Y') : '');
    @endphp
    <div class="alat-performa">
        <span class="redup">Tahun:</span>
        @foreach ($daftarTahun as $t)
            <a href="{{ route('ritasi.performa', array_filter(['tahun' => $t, 'tujuan' => $tujuan])) }}" @class(['chip', 'aktif' => $t === $tahun])>{{ $t }}</a>
        @endforeach
    </div>
    <div class="alat-performa">
        <span class="redup">Tujuan buangan:</span>
        <a href="{{ route('ritasi.performa', ['tahun' => $tahun]) }}" @class(['chip', 'aktif' => ! $tujuan])>Semua (global)</a>
        @foreach ($daftarTujuan as $t => $n)
            <a href="{{ route('ritasi.performa', ['tahun' => $tahun, 'tujuan' => $t]) }}" @class(['chip', 'aktif' => $tujuan === $t])>{{ $t }} · {{ $n }} truk</a>
        @endforeach
    </div>
    <div class="alat-performa">
        <button type="button" class="tombol polos" id="buka-semua">Buka semua bulan</button>
        <button type="button" class="tombol polos" id="tutup-semua">Tutup semua</button>
        <span class="redup" style="font-size: 13px;">Klik baris bulan untuk membuka/menutup rincian per tanggal. Gambar yang dibagikan mengikuti tampilan saat ini.</span>
        <span style="margin-left: auto; display: flex; gap: 8px;">
            <button type="button" class="tombol polos" id="unduh-gambar">⬇ Unduh gambar</button>
            <button type="button" class="tombol" id="bagikan-gambar">📤 Bagikan ke WhatsApp</button>
        </span>
    </div>

    <div class="gulir-rekap" id="gulir-rekap">
        <div class="rekap-kertas" id="rekap">
            <div class="judul-rekap">REKAPAN RITASI PER DT THN {{ $tahun }}</div>
            <div class="sub-rekap">{{ $judulTujuan }} · {{ count($data['truk']) }} truk · total {{ number_format($data['jumlah'], 0, ',', '.') }} rit
                @if ($data['terakhir']) · data ritasi s/d {{ $data['terakhir']->translatedFormat('j M Y') }}@endif</div>
            @if (! $data['truk'])
                <p style="margin: 10px 0;">Belum ada ritasi truk MNP di tahun ini{{ $tujuan ? ' untuk tujuan buangan ini' : '' }}.</p>
            @else
                <table class="pivot">
                    <thead><tr><th class="kol-label">Bulan / Tanggal</th>
                        @foreach ($data['truk'] as $dt)<th>{{ str_replace('DT ', '', $dt) }}</th>@endforeach
                        <th class="gt">Grand Total</th></tr></thead>
                    @foreach ($data['bulan'] as $m => $b)
                        @php($kini = $m === $bulanKini)
                        <tbody data-bulan="{{ $m }}">
                            <tr @class(['bln', 'kini' => $kini])>
                                <td class="kol-label"><span class="tanda">{{ $kini ? '⊟' : '⊞' }}</span>{{ $b['awal']->translatedFormat('M') }}</td>
                                @foreach ($data['truk'] as $dt)<td>{{ $b['truk'][$dt] ?: '' }}</td>@endforeach
                                <td class="gt">{{ $b['total'] }}</td>
                            </tr>
                            @foreach ($b['hari'] as $h)
                                <tr @class(['hari', 'kini' => $kini]) @unless ($kini) hidden @endunless>
                                    <td class="kol-label">{{ $h['tanggal']->translatedFormat('d-M') }}</td>
                                    @foreach ($data['truk'] as $dt)
                                        @php($n = $h['truk'][$dt] ?? 0)
                                        <td @class(['nol' => ! $n])>{{ $n ?: '' }}</td>
                                    @endforeach
                                    <td class="gt">{{ $h['total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                    <tfoot><tr><td class="kol-label">Grand Total</td>
                        @foreach ($data['truk'] as $dt)<td>{{ $data['total'][$dt] }}</td>@endforeach
                        <td class="gt">{{ $data['jumlah'] }}</td></tr></tfoot>
                </table>
            @endif
            <div class="kaki-rekap">Aplikasi MNP · dibuat {{ now()->translatedFormat('j M Y H:i') }} · sel merah = truk tidak ada rit di tanggal itu</div>
        </div>
    </div>

    <script src="{{ asset('js/html2canvas.min.js') }}"></script>
    <script>
        (() => {
            const rekap = document.getElementById('rekap');
            const atur = (tb, buka) => {
                tb.querySelectorAll('tr.hari').forEach(tr => tr.hidden = !buka);
                const t = tb.querySelector('tr.bln .tanda'); if (t) t.textContent = buka ? '⊟' : '⊞';
            };
            rekap.querySelectorAll('tbody[data-bulan]').forEach(tb => tb.querySelector('tr.bln').addEventListener('click', () => atur(tb, tb.querySelector('tr.hari')?.hidden)));
            document.getElementById('buka-semua').addEventListener('click', () => rekap.querySelectorAll('tbody[data-bulan]').forEach(tb => atur(tb, true)));
            document.getElementById('tutup-semua').addEventListener('click', () => rekap.querySelectorAll('tbody[data-bulan]').forEach(tb => atur(tb, false)));

            const namaFile = @json($namaFile);
            const teks = @json($teksBagikan);
            // Seluruh tabel (termasuk kolom yang tergulir) dijadikan PNG.
            const gambar = async () => {
                const kanvas = await html2canvas(rekap, {
                    scale: 2, backgroundColor: '#ffffff', windowWidth: rekap.scrollWidth + 40,
                    onclone: d => { d.getElementById('gulir-rekap').style.overflow = 'visible'; },
                });
                return new Promise(ok => kanvas.toBlob(ok, 'image/png'));
            };
            const unduh = blob => Object.assign(document.createElement('a'), {href: URL.createObjectURL(blob), download: namaFile}).click();
            const jalan = async (tombol, kerja) => {
                const asli = tombol.textContent; tombol.disabled = true; tombol.textContent = 'Membuat gambar…';
                try { await kerja(await gambar()); }
                catch (err) { if (err.name !== 'AbortError') alert('Gagal: ' + err.message); }
                finally { tombol.disabled = false; tombol.textContent = asli; }
            };
            document.getElementById('unduh-gambar').addEventListener('click', e => jalan(e.currentTarget, async blob => unduh(blob)));
            // HP: menu bagikan bawaan (langsung pilih WhatsApp). Laptop: menu bagikan Windows sering gagal ("Try that again"),
            // jadi gambar disalin ke clipboard + diunduh, lalu WhatsApp Web dibuka — tinggal Ctrl+V di chat.
            const hp = navigator.userAgentData?.mobile ?? /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
            const selesaiLaptop = (blob, tersalin) => {
                unduh(blob);
                window.open('https://web.whatsapp.com/', '_blank');
                alert(tersalin
                    ? 'Gambar sudah disalin. Buka chat di WhatsApp Web (tab baru), lalu tekan Ctrl+V untuk menempelkan gambarnya.\n\nGambar juga sudah diunduh bila ingin dilampirkan manual.'
                    : 'Gambar sudah diunduh. Lampirkan gambar itu di chat WhatsApp Web yang baru dibuka.');
            };
            document.getElementById('bagikan-gambar').addEventListener('click', async e => {
                const tombol = e.currentTarget;
                const asli = tombol.textContent; tombol.disabled = true; tombol.textContent = 'Membuat gambar…';
                try {
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
                    selesaiLaptop(blob, await salin);
                } catch (err) {
                    alert('Gagal membuat gambar: ' + err.message);
                } finally {
                    tombol.disabled = false; tombol.textContent = asli;
                }
            });
        })();
    </script>
@endsection
