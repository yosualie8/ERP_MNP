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
        table.pivot thead th { background: #d9e1f2; font-weight: 700; color: #111; }
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
        /* Per tahap: tiap sel = rit (tebal) + m³ (kecil). */
        table.pivot-tahap td { line-height: 1.15; vertical-align: middle; }
        table.pivot-tahap td .r { font-weight: 700; }
        table.pivot-tahap td .v { font-size: 11px; color: #555; }
        table.pivot-tahap tbody td { background: #fff; color: #111; }
        table.pivot-tahap tbody td.gt { background: #fff2cc; }
        table.pivot-tahap .st { font-size: 11px; font-weight: 400; padding: 0 6px; border-radius: 8px; }
        table.pivot-tahap .st.progress { background: #c6efce; color: #006100; }
        table.pivot-tahap .st.selesai { background: #e7e6e6; color: #444; }
        table.pivot-tahap .st.belum { background: #ffeb9c; color: #7a5b00; }
        table.pivot-tahap td.rentang { font-size: 11px; color: #555; font-weight: 400; }
        .rekap-kertas .kaki-rekap { font-size: 11px; color: #666; margin-top: 6px; }
    </style>

    <div class="alat-performa">
        <a href="{{ route('ritasi.performa', ['tahun' => $tahun]) }}" @class(['chip', 'aktif' => $tab === 'tahap'])>🏗️ Per tahap</a>
        <a href="{{ route('ritasi.performa', ['tab' => 'truk', 'tahun' => $tahun]) }}" @class(['chip', 'aktif' => $tab === 'truk'])>🚚 Per truk</a>
    </div>

    @if ($tab === 'tahap')
        @php
            $fmt = fn ($n, $d = 0) => number_format((float) $n, $d, ',', '.');
            $periode = $bulan ? \Illuminate\Support\Carbon::create($tahun, $bulan, 1)->translatedFormat('F Y') : 'Tahun '.$tahun;
            $namaFile = 'Bongkaran per Tahap '.$periode.($progress ? ' - progress' : '').' ('.now()->format('Y-m-d').').png';
            $teksBagikan = $tahap['tahap'] ? implode("\n", [
                '*Rekap Bongkaran per Tahap — '.$periode.'*',
                'Truk MNP'.($progress ? ' · tahap progress' : '').($tahap['terakhir'] ? ' · data s/d '.$tahap['terakhir']->translatedFormat('j M Y') : ''),
                'Total: *'.$fmt($tahap['jumlah']['rit']).' rit · '.$fmt($tahap['jumlah']['m3']).' m³* dari '.count($tahap['tahap']).' tahap',
                '',
                collect($tahap['tahap'])->sortByDesc('rit')->map(fn ($t) => $t['nama'].': '.$fmt($t['rit']).' rit · '.$fmt($t['m3']).' m³')->implode("\n"),
            ]) : '';
            $param = fn (array $ubah) => array_filter([...['tahun' => $tahun, 'bulan' => $bulan, 'status' => $progress ? 'progress' : null], ...$ubah]);
        @endphp
        <div class="alat-performa">
            <span class="redup">Tahun:</span>
            @foreach ($daftarTahun as $t)
                <a href="{{ route('ritasi.performa', $param(['tahun' => $t, 'bulan' => null])) }}" @class(['chip', 'aktif' => $t === $tahun])>{{ $t }}</a>
            @endforeach
            <span class="redup" style="margin-left: 12px;">Status tahap:</span>
            <a href="{{ route('ritasi.performa', $param(['status' => null])) }}" @class(['chip', 'aktif' => ! $progress])>Semua</a>
            <a href="{{ route('ritasi.performa', $param(['status' => 'progress'])) }}" @class(['chip', 'aktif' => $progress])>Progress saja</a>
        </div>
        <div class="alat-performa">
            <span class="redup">Kolom:</span>
            <a href="{{ route('ritasi.performa', $param(['bulan' => null])) }}" @class(['chip', 'aktif' => ! $bulan])>Per bulan</a>
            @foreach ($tahap['bulan_ada'] as $m)
                <a href="{{ route('ritasi.performa', $param(['bulan' => $m])) }}" @class(['chip', 'aktif' => $bulan === $m])>{{ \Illuminate\Support\Carbon::create($tahun, $m, 1)->translatedFormat('M') }} per tanggal</a>
            @endforeach
            <span style="margin-left: auto; display: flex; gap: 8px;">
                <button type="button" class="tombol polos" id="unduh-gambar">⬇ Unduh gambar</button>
                <button type="button" class="tombol" id="bagikan-gambar">📤 Bagikan ke WhatsApp</button>
            </span>
        </div>

        <div class="gulir-rekap" id="gulir-rekap">
            <div class="rekap-kertas" id="rekap">
                <div class="judul-rekap">REKAPAN BONGKARAN PER TAHAP — {{ mb_strtoupper($periode) }}</div>
                <div class="sub-rekap">Truk MNP (Data Aset){{ $progress ? ' · tahap berstatus progress' : '' }} · {{ count($tahap['tahap']) }} tahap
                    · {{ $fmt($tahap['jumlah']['rit']) }} rit · {{ $fmt($tahap['jumlah']['m3']) }} m³
                    @if ($tahap['terakhir']) · data ritasi s/d {{ $tahap['terakhir']->translatedFormat('j M Y') }}@endif</div>
                @if (! $tahap['tahap'])
                    <p style="margin: 10px 0;">Belum ada bongkaran truk MNP {{ $bulan ? 'di bulan ini' : 'di tahun ini' }}{{ $progress ? ' untuk tahap berstatus progress' : '' }}.</p>
                @else
                    <table class="pivot pivot-tahap">
                        <thead><tr>
                            <th class="kol-label">Tahap</th><th>Status</th><th>Bongkar</th>
                            @foreach ($tahap['kolom'] as $k => $label)<th>{{ $label }}</th>@endforeach
                            <th class="gt">Total</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($tahap['tahap'] as $t)
                                <tr>
                                    <td class="kol-label">{{ $t['nama'] }}</td>
                                    <td data-v="{{ $t['status'] ?? 'belum terpetakan' }}">@if ($t['status'])<span @class(['st', $t['status']])>{{ $t['status'] }}</span>@else<span class="st belum">belum terpetakan</span>@endif</td>
                                    <td class="rentang" data-n="{{ $t['mulai'] }}">{{ \Illuminate\Support\Carbon::parse($t['mulai'])->translatedFormat('j M') }}{{ $t['selesai'] !== $t['mulai'] ? ' – '.\Illuminate\Support\Carbon::parse($t['selesai'])->translatedFormat('j M') : '' }}</td>
                                    @foreach ($tahap['kolom'] as $k => $label)
                                        @php $s = $t['sel'][$k] ?? null; @endphp
                                        <td @if ($s) data-n="{{ $s['rit'] }}" @endif>@if ($s)<div class="r">{{ $s['rit'] }}</div><div class="v">{{ $fmt($s['m3']) }} m³</div>@endif</td>
                                    @endforeach
                                    <td class="gt" data-n="{{ $t['rit'] }}"><div class="r">{{ $fmt($t['rit']) }} rit</div><div class="v">{{ $fmt($t['m3']) }} m³</div></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr>
                            <td class="kol-label">Total</td><td></td><td></td>
                            @foreach ($tahap['kolom'] as $k => $label)
                                @php $s = $tahap['total_kolom'][$k]; @endphp
                                <td>@if ($s['rit'])<div class="r">{{ $fmt($s['rit']) }}</div><div class="v">{{ $fmt($s['m3']) }} m³</div>@endif</td>
                            @endforeach
                            <td class="gt"><div class="r">{{ $fmt($tahap['jumlah']['rit']) }} rit</div><div class="v">{{ $fmt($tahap['jumlah']['m3']) }} m³</div></td>
                        </tr></tfoot>
                    </table>
                @endif
                <div class="kaki-rekap">Aplikasi MNP · dibuat {{ now()->translatedFormat('j M Y H:i') }} · angka atas = rit, bawah = m³ (kubikasi) · ngepok tidak dihitung · tahap mengikuti Daftar Proyek</div>
            </div>
        </div>
    @else
        @php
            $bulanKini = array_key_last($data['bulan']);
            $judulTujuan = $tujuan ?? 'Semua tujuan buangan';
            $namaFile = 'Performa Ritasi '.$tahun.' - '.($tujuan ?? 'Semua').' ('.now()->format('Y-m-d').').png';
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
                <a href="{{ route('ritasi.performa', array_filter(['tab' => 'truk', 'tahun' => $t, 'tujuan' => $tujuan])) }}" @class(['chip', 'aktif' => $t === $tahun])>{{ $t }}</a>
            @endforeach
        </div>
        <div class="alat-performa">
            <span class="redup">Tujuan buangan:</span>
            <a href="{{ route('ritasi.performa', ['tab' => 'truk', 'tahun' => $tahun]) }}" @class(['chip', 'aktif' => ! $tujuan])>Semua (global)</a>
            @foreach ($daftarTujuan as $t => $n)
                <a href="{{ route('ritasi.performa', ['tab' => 'truk', 'tahun' => $tahun, 'tujuan' => $t]) }}" @class(['chip', 'aktif' => $tujuan === $t])>{{ $t }} · {{ $n }} truk</a>
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
                <div class="sub-rekap">{{ $judulTujuan }} · {{ count($data['truk']) }} truk
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
                    </table>
                @endif
                <div class="kaki-rekap">Aplikasi MNP · dibuat {{ now()->translatedFormat('j M Y H:i') }} · sel merah = truk tidak ada rit di tanggal itu</div>
            </div>
        </div>
    @endif

    @if ($teksBagikan)
        <div class="kartu" style="margin-top: 12px;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 8px;">
                <h4 style="margin: 0;">Teks rekap <span class="redup" style="font-weight: normal; font-size: 13px;">— ikut terkirim bersama gambar</span></h4>
                <button type="button" class="tombol polos" id="salin-teks" style="padding: 5px 12px; font-size: 13px;">📋 Salin teks</button>
            </div>
            <pre id="teks-rekap" style="margin: 0; white-space: pre-wrap; font-family: inherit; font-size: 14px; line-height: 1.5;">{{ $teksBagikan }}</pre>
        </div>
    @endif

    <script src="{{ asset('js/html2canvas.min.js') }}"></script>
    <script>
        (() => {
            const rekap = document.getElementById('rekap');
            // Tab per truk: buka/tutup rincian per tanggal.
            const atur = (tb, buka) => {
                tb.querySelectorAll('tr.hari').forEach(tr => tr.hidden = !buka);
                const t = tb.querySelector('tr.bln .tanda'); if (t) t.textContent = buka ? '⊟' : '⊞';
            };
            rekap.querySelectorAll('tbody[data-bulan]').forEach(tb => tb.querySelector('tr.bln').addEventListener('click', () => atur(tb, tb.querySelector('tr.hari')?.hidden)));
            document.getElementById('buka-semua')?.addEventListener('click', () => rekap.querySelectorAll('tbody[data-bulan]').forEach(tb => atur(tb, true)));
            document.getElementById('tutup-semua')?.addEventListener('click', () => rekap.querySelectorAll('tbody[data-bulan]').forEach(tb => atur(tb, false)));

            const namaFile = @json($namaFile);
            const teks = @json($teksBagikan);
            // Seluruh tabel (termasuk kolom yang tergulir) dijadikan PNG; baris saringan kolom tidak ikut.
            const gambar = async () => {
                const kanvas = await html2canvas(rekap, {
                    scale: 2, backgroundColor: '#ffffff', windowWidth: rekap.scrollWidth + 40,
                    onclone: d => { d.getElementById('gulir-rekap').style.overflow = 'visible'; d.querySelectorAll('tr.saring-kolom, .saring-info, .urut-ikon').forEach(x => x.remove()); },
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
            // Laptop: teks rekap dibawa lewat tautan resmi wa.me (terisi di kotak pesan setelah memilih chat),
            // gambarnya ditempel dari clipboard.
            const selesaiLaptop = (blob, tersalin) => {
                unduh(blob);
                window.open('https://wa.me/?text=' + encodeURIComponent(teks), '_blank');
                alert(tersalin
                    ? 'Gambar sudah disalin. Di tab WhatsApp yang baru terbuka, pilih chat tujuan — teks rekap sudah terisi — lalu tekan Ctrl+V untuk menempelkan gambarnya, dan kirim.\n\nGambar juga sudah diunduh bila ingin dilampirkan manual.'
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
