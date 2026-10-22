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
        /* Kolom DT selebar isinya (owner 22 Okt 2026: tanpa geser kiri-kanan). */
        table.pivot { border-collapse: collapse; font-size: 13px; }
        table.pivot th, table.pivot td { border: 1px solid #9aa4b5; padding: 2px 3px; text-align: center; min-width: 0; white-space: nowrap; }
        table.pivot thead th { background: #d9e1f2; font-weight: 700; color: #111; }
        table.pivot th.kol-label, table.pivot td.kol-label { text-align: left; min-width: 70px; font-weight: 700; padding: 2px 6px; }
        table.pivot .gt { font-weight: 700; padding: 2px 6px; }
        .pilih-proyek { display: flex; gap: 6px; align-items: center; }
        .pilih-proyek input { width: 340px; max-width: 70vw; }
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
        $judulTujuan = $tahap['nama'] ?? 'Semua proyek';
        $namaFile = 'Performa Ritasi '.$tahun.' - '.($tahap['nama'] ?? 'Semua').' ('.now()->format('Y-m-d').').png';
        // Teks yang ikut dibagikan = rekap bulan terakhir (bukan total setahun).
        $teksBagikan = '';
        if ($bulanKini) {
            $b = $data['bulan'][$bulanKini];
            $hariData = collect($b['hari']);
            $akhir = $hariData->last();
            $perTruk = collect($b['truk'])->sortDesc();
            $jalan = $perTruk->filter();
            $nol = $perTruk->reject(fn ($n) => $n > 0)->keys();
            $rata = $hariData->count() ? $b['total'] / $hariData->count() : 0;
            $teksBagikan = implode("\n", array_filter([
                '*Rekap Ritasi '.$b['awal']->translatedFormat('F Y').'*',
                $judulTujuan.' · data s/d '.$akhir['tanggal']->translatedFormat('j M Y'),
                'Total: *'.number_format($b['total'], 0, ',', '.').' rit* dari '.$jalan->count().' truk · rata-rata '
                    .number_format($rata, 1, ',', '.').' rit/hari ('.$hariData->count().' hari)',
                $akhir['tanggal']->translatedFormat('j M').': '.$akhir['total'].' rit',
                '',
                'Per truk:',
                $jalan->map(fn ($n, $dt) => "{$dt}: {$n}")->implode("\n"),
                $nol->isNotEmpty() ? "\nTanpa rit bulan ini: ".$nol->implode(', ') : null,
            ], fn ($x) => $x !== null));
        }
    @endphp
    <div class="alat-performa">
        <span class="redup">Tahun:</span>
        @foreach ($daftarTahun as $t)
            <a href="{{ route('ritasi.performa', ['tahun' => $t]) }}" @class(['chip', 'aktif' => $t === $tahun])>{{ $t }}</a>
        @endforeach
    </div>
    {{-- Proyek = kolom Tahap di Data Ritasi (dicocokkan ke Daftar Proyek), terbaru dibongkar dulu. --}}
    <div class="alat-performa pilih-proyek">
        <span class="redup">Proyek:</span>
        <input type="search" id="cari-proyek" list="daftar-proyek" autocomplete="off" placeholder="Semua proyek — ketik nama proyek / tahap…"
            value="{{ $tahap['nama'] ?? '' }}" title="Ketik sebagian nama, lalu pilih dari saran">
        <datalist id="daftar-proyek">
            @foreach ($daftarTahap as $t)
                <option value="{{ $t['nama'] }}">{{ number_format($t['rit'], 0, ',', '.') }} rit · bongkar terakhir {{ \Illuminate\Support\Carbon::parse($t['terakhir'])->translatedFormat('j M') }}{{ $t['status'] ? ' · '.$t['status'] : '' }}</option>
            @endforeach
        </datalist>
        @if ($tahap)<a href="{{ route('ritasi.performa', ['tahun' => $tahun]) }}" class="tombol polos" style="padding: 4px 10px;">× Semua proyek</a>@endif
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
            <div class="sub-rekap">{{ $judulTujuan }} · {{ count($data['truk']) }} truk
                @if ($data['terakhir']) · data ritasi s/d {{ $data['terakhir']->translatedFormat('j M Y') }}@endif</div>
            @if (! $data['truk'])
                <p style="margin: 10px 0;">Belum ada ritasi truk MNP di tahun ini{{ $tahap ? ' untuk proyek ini' : '' }}.</p>
            @else
                <table class="pivot" data-tanpa-urut data-tanpa-saring-kolom>
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
                </table>
            @endif
            <div class="kaki-rekap">Aplikasi MNP · dibuat {{ now()->translatedFormat('j M Y H:i') }} · sel merah = truk tidak ada rit di tanggal itu</div>
        </div>
    </div>

    @if ($teksBagikan)
        <div class="kartu" style="margin-top: 12px;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 8px;">
                <h4 style="margin: 0;">Teks rekap bulanan <span class="redup" style="font-weight: normal; font-size: 13px;">— ikut terkirim bersama gambar</span></h4>
                <button type="button" class="tombol polos" id="salin-teks" style="padding: 5px 12px; font-size: 13px;">📋 Salin teks</button>
            </div>
            <pre id="teks-rekap" style="margin: 0; white-space: pre-wrap; font-family: inherit; font-size: 14px; line-height: 1.5;">{{ $teksBagikan }}</pre>
        </div>
    @endif

    <script src="{{ asset('js/html2canvas.min.js') }}"></script>
    <script>
        (() => {
            // Saringan proyek: pilih dari saran (datalist) → muat ulang dengan ?tahap=; kosongkan → semua proyek.
            const PROYEK = @json(collect($daftarTahap)->mapWithKeys(fn ($t) => [mb_strtolower($t['nama']) => $t['id']]));
            const cariProyek = document.getElementById('cari-proyek');
            const keProyek = () => {
                const v = cariProyek.value.trim().toLowerCase();
                const id = v === '' ? '' : PROYEK[v];
                if (id === undefined || String(id) === @json((string) ($tahap['id'] ?? ''))) return;
                location.href = @json(route('ritasi.performa', ['tahun' => $tahun])) + (id ? '&tahap=' + id : '');
            };
            cariProyek.addEventListener('change', keProyek);
            cariProyek.addEventListener('input', () => { if (PROYEK[cariProyek.value.trim().toLowerCase()] !== undefined) keProyek(); });
            cariProyek.addEventListener('search', keProyek); // tombol × di kotak isian
            cariProyek.addEventListener('focus', () => cariProyek.select());

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
            // Laptop: teks rekap bulanan dibawa lewat tautan resmi wa.me (terisi di kotak pesan setelah memilih chat),
            // gambarnya ditempel dari clipboard.
            const selesaiLaptop = (blob, tersalin) => {
                unduh(blob);
                window.open('https://wa.me/?text=' + encodeURIComponent(teks), '_blank');
                alert(tersalin
                    ? 'Gambar sudah disalin. Di tab WhatsApp yang baru terbuka, pilih chat tujuan — teks rekap bulanan sudah terisi — lalu tekan Ctrl+V untuk menempelkan gambarnya, dan kirim.\n\nGambar juga sudah diunduh bila ingin dilampirkan manual.'
                    : 'Gambar sudah diunduh. Di tab WhatsApp yang baru terbuka, pilih chat tujuan (teks rekap sudah terisi), lalu lampirkan gambar itu.');
            };
            document.getElementById('salin-teks')?.addEventListener('click', async e => {
                try { await navigator.clipboard.writeText(teks); e.currentTarget.textContent = '✓ Tersalin'; }
                catch { alert('Tidak bisa menyalin otomatis — blok teksnya lalu tekan Ctrl+C.'); }
            });
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
