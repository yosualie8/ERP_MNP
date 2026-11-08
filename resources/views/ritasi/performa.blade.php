@extends('layouts.app', ['judul' => 'Performa Ritasi'])

@section('lebar', '1600px')

@section('isi')
    @include('partials.gaya-dasbor')
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
        /* Kolom truk non-MNP (proyek ASG): satu kolom per pemilik. */
        table.pivot thead th.mitra { background: #f8cbad; white-space: normal; max-width: 56px; font-size: 11px; line-height: 1.1; }
        .pilih-proyek { display: flex; gap: 6px; align-items: center; }
        .pilih-proyek input { width: 340px; max-width: 70vw; }
        .pilih-pemilik { gap: 6px 12px; }
        .pilih-pemilik .centang-pemilik { display: inline-flex; gap: 5px; align-items: center; margin: 0; font-weight: 400; cursor: pointer; white-space: nowrap; }
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
        // Hanya satu bulan yang terbuka (owner 22 Okt 2026): bawaan bulan terakhir, atau ?bulan=.
        $bulanKini = $bulanBuka;
        $judulTujuan = ($pilihan['label'] ?? 'Semua proyek').($pemilik ? ' · pemilik: '.implode(', ', $pemilik) : '');
        $namaFile = 'Performa Ritasi '.$tahun.' - '.($tahap['nama'] ?? ($cc ? 'Cost center '.$cc : ($kata !== '' ? 'Semua '.$kata : 'Semua'))).' ('.now()->format('Y-m-d').').png';
        // Teks yang ikut dibagikan = rekap bulan yang sedang dibuka (disiapkan untuk tiap bulan; berganti saat bulan lain dibuka).
        $teksPerBulan = [];
        foreach ($data['bulan'] as $mTeks => $b) {
            $hariData = collect($b['hari']);
            $akhir = $hariData->last();
            $perTruk = collect($b['truk'])->sortDesc();
            $jalan = $perTruk->filter();
            $nol = $perTruk->reject(fn ($n) => $n > 0)->keys()->map(fn ($k) => str_starts_with($k, '~') ? substr($k, 1) : $k);
            $namaKolom = fn ($k) => str_starts_with($k, '~') ? substr($k, 1).' (non-MNP)' : $k;
            $rata = $hariData->count() ? $b['total'] / $hariData->count() : 0;
            $teksPerBulan[$mTeks] = implode("\n", array_filter([
                '*Rekap Ritasi '.$b['awal']->translatedFormat('F Y').'*',
                $judulTujuan.' · data s/d '.$akhir['tanggal']->translatedFormat('j M Y'),
                'Total: *'.number_format($b['total'], 0, ',', '.').' rit* dari '.$jalan->keys()->reject(fn ($k) => str_starts_with($k, '~'))->count().' truk MNP'
                    .($data['mitra'] ? ' + '.$jalan->keys()->filter(fn ($k) => str_starts_with($k, '~'))->count().' pemilik non-MNP' : '').' · rata-rata '
                    .number_format($rata, 1, ',', '.').' rit/hari ('.$hariData->count().' hari)',
                $akhir['tanggal']->translatedFormat('j M').': '.$akhir['total'].' rit',
                '',
                'Per truk:',
                $jalan->map(fn ($n, $dt) => $namaKolom($dt).": {$n}")->implode("\n"),
                $nol->isNotEmpty() ? "\nTanpa rit bulan ini: ".$nol->implode(', ') : null,
            ], fn ($x) => $x !== null));
        }
        $teksBagikan = $teksPerBulan[$bulanKini] ?? '';
        // Ringkasan KPI (owner 9 Nov 2026, gaya dashboard v2): tahun ini, bulan yang dibuka, hari data terakhir, truk aktif.
        $kpiTahun = collect($data['bulan'])->sum('total');
        $bk = $data['bulan'][$bulanKini] ?? null;
        $bkHari = collect($bk['hari'] ?? []);
        $bkRata = $bkHari->count() ? $bk['total'] / $bkHari->count() : null;
        $bkAkhir = $bkHari->last();
        $bkTruk = $bk ? collect($bk['truk'])->filter()->keys()->reject(fn ($k) => str_starts_with($k, '~'))->count() : 0;
        $bkMitra = $bk ? collect($bk['truk'])->filter()->keys()->filter(fn ($k) => str_starts_with($k, '~'))->count() : 0;
        $bkSebelum = $data['bulan'][$bulanKini - 1] ?? null;
        $bkSebelumRata = $bkSebelum && count($bkSebelum['hari']) ? $bkSebelum['total'] / count($bkSebelum['hari']) : null;
        $bkBeda = $bkRata !== null && $bkSebelumRata ? ($bkRata - $bkSebelumRata) / $bkSebelumRata * 100 : null;
    @endphp
    <div class="alat-performa">
        <span class="redup">Tahun</span>
        <nav class="segmen" aria-label="Tahun">
            @foreach ($daftarTahun as $t)
                <a href="{{ route('ritasi.performa', ['tahun' => $t]) }}" @class(['aktif' => $t === $tahun]) @if ($t === $tahun) aria-current="page" @endif>{{ $t }}</a>
            @endforeach
        </nav>
    </div>
    {{-- Proyek = kolom Tahap di Data Ritasi (dicocokkan ke Daftar Proyek), terbaru dibongkar dulu. --}}
    {{-- Saring menurut: Proyek (tahap / kata) ATAU Cost center — salah satu (owner 6 Nov 2026). --}}
    <div class="alat-performa">
        <span class="redup">Saring menurut</span>
        <span class="segmen" role="group" aria-label="Saring menurut">
            <button type="button" @class(['aktif' => ! $cc]) data-mode="proyek">Proyek</button>
            <button type="button" @class(['aktif' => (bool) $cc]) data-mode="cc">Cost center</button>
        </span>
    </div>
    <div class="alat-performa pilih-cc" @if (! $cc) hidden @endif>
        <span class="redup">Cost center:</span>
        @foreach ($daftarCc as $c)
            <a href="{{ route('ritasi.performa', ['tahun' => $tahun, 'cc' => $c['kode']]) }}" @class(['chip', 'aktif' => $cc === $c['kode']])
               title="{{ $c['tahap'] }} tahap · {{ number_format($c['rit'], 0, ',', '.') }} rit truk MNP {{ $tahun }}">{{ $c['kode'] }} <span class="redup">· {{ number_format($c['rit'], 0, ',', '.') }} rit</span></a>
        @endforeach
        @if ($cc)<a href="{{ route('ritasi.performa', ['tahun' => $tahun]) }}" class="tombol polos" style="padding: 4px 10px;">× Semua cost center</a>@endif
    </div>
    <div class="alat-performa pilih-proyek" @if ($cc) hidden @endif>
        <span class="redup">Proyek:</span>
        <input type="search" id="cari-proyek" list="daftar-proyek" autocomplete="off" placeholder="Semua proyek — ketik nama proyek / tahap, atau kata lalu Enter (mis. ASG)…"
            value="{{ $pilihan['isi'] ?? '' }}" title="Pilih satu proyek dari saran, pilih &quot;Semua yang mengandung: …&quot;, atau ketik kata lalu tekan Enter">
        <datalist id="daftar-proyek">
            {{-- Grup: semua tahap yang namanya mengandung kata depan yang sama (ASG, Infra, …). --}}
            @foreach ($grup as $g => $n)
                <option value="Semua yang mengandung: {{ $g }}">{{ $n }} tahap</option>
            @endforeach
            @foreach ($daftarTahap as $t)
                <option value="{{ $t['nama'] }}">{{ number_format($t['rit'], 0, ',', '.') }} rit · bongkar terakhir {{ \Illuminate\Support\Carbon::parse($t['terakhir'])->translatedFormat('j M') }}{{ $t['status'] ? ' · '.$t['status'] : '' }}</option>
            @endforeach
        </datalist>
        @if ($pilihan && ! $cc)<a href="{{ route('ritasi.performa', ['tahun' => $tahun]) }}" class="tombol polos" style="padding: 4px 10px;">× Semua proyek</a>@endif
    </div>
    {{-- Proyek ASG: pilih pemilik truk yang ditampilkan (MNP = semua DT MNP; lainnya kolom oranye per pemilik). Pilihannya hanya
         pemilik yang bongkar di bulan yang sedang dibuka (owner 22 Okt 2026); berganti saat bulan lain dibuka. --}}
    @if ($mitra && $data['pemilik_ada'])
        <form method="GET" action="{{ route('ritasi.performa') }}" class="alat-performa pilih-pemilik" id="form-pemilik">
            <input type="hidden" name="tahun" value="{{ $tahun }}">
            @if ($tahap)<input type="hidden" name="tahap" value="{{ $tahap['id'] }}">@elseif ($cc)<input type="hidden" name="cc" value="{{ $cc }}">@else<input type="hidden" name="kata" value="{{ $kata }}">@endif
            <input type="hidden" name="bulan" id="bulan-pemilik" value="{{ $bulanKini }}">
            <span class="redup">Pemilik truk <b id="judul-bulan-pemilik">{{ $bulanKini ? \Illuminate\Support\Carbon::create($tahun, $bulanKini, 1)->translatedFormat('F') : '' }}</b>:</span>
            @foreach ($data['pemilik_ada'] as $p => $n)
                <label class="centang-pemilik" data-n='@json(collect($data['pemilik_bulan'])->map(fn ($x) => $x[$p] ?? 0)->filter())'>
                    <input type="checkbox" name="pemilik[]" value="{{ $p }}" @checked(! $pemilik || in_array($p, $pemilik, true))>
                    {{ $p }} <span class="redup n-pemilik"></span></label>
            @endforeach
            <button type="button" class="tombol polos" data-semua-pemilik="1" style="padding: 3px 10px; font-size: 12px;">Semua</button>
            <button type="button" class="tombol polos" data-semua-pemilik="0" style="padding: 3px 10px; font-size: 12px;">Kosongkan</button>
            <button type="submit" class="tombol" style="padding: 4px 14px;">Terapkan</button>
        </form>
    @endif
    @if ($data['truk'])
        <div class="kpi-baris" style="margin-top: 6px;">
            <div class="kpi"><div class="kpi-label"><i style="background: #5b8def;"></i>Total rit {{ $tahun }}</div>
                <div class="kpi-nilai">{{ number_format($kpiTahun, 0, ',', '.') }}<small>rit</small></div>
                <div class="kpi-sub">{{ $judulTujuan }}</div></div>
            @if ($bk)
                <div class="kpi"><div class="kpi-label"><i style="background: #3fae7a;"></i>{{ $bk['awal']->translatedFormat('F Y') }}</div>
                    <div class="kpi-nilai">{{ number_format($bk['total'], 0, ',', '.') }}<small>rit</small></div>
                    <div class="kpi-sub">{{ $bkHari->count() }} hari ber-rit</div></div>
                <div class="kpi"><div class="kpi-label"><i style="background: #f0c05a;"></i>Rata-rata per hari</div>
                    <div class="kpi-nilai">{{ $bkRata !== null ? number_format($bkRata, 1, ',', '.') : '–' }}<small>rit/hari</small></div>
                    <div class="kpi-sub">@if ($bkBeda !== null)<span class="delta {{ abs($bkBeda) < .5 ? 'datar' : ($bkBeda > 0 ? 'baik' : 'buruk') }}">{{ $bkBeda > 0 ? '▲' : '▼' }} {{ number_format(abs($bkBeda), 0, ',', '.') }}%</span> vs {{ $bkSebelum['awal']->translatedFormat('F') }}@else&nbsp;@endif</div></div>
                <div class="kpi"><div class="kpi-label"><i style="background: #a78bfa;"></i>Hari data terakhir</div>
                    <div class="kpi-nilai">{{ $bkAkhir ? number_format($bkAkhir['total'], 0, ',', '.') : '–' }}<small>rit</small></div>
                    <div class="kpi-sub">{{ $bkAkhir ? $bkAkhir['tanggal']->translatedFormat('l, j M Y') : '' }}</div></div>
                <div class="kpi"><div class="kpi-label"><i style="background: var(--aksen);"></i>Truk aktif {{ $bk['awal']->translatedFormat('M') }}</div>
                    <div class="kpi-nilai">{{ $bkTruk }}<small>DT MNP</small></div>
                    <div class="kpi-sub">@if ($bkMitra)+ {{ $bkMitra }} pemilik non-MNP @else&nbsp;@endif</div></div>
            @endif
        </div>
    @endif
    <div class="alat-performa">
        <span class="redup" style="font-size: 13px;">Klik baris bulan untuk membuka rincian per tanggal — hanya satu bulan yang terbuka. Gambar yang dibagikan mengikuti tampilan saat ini.</span>
        <span style="margin-left: auto; display: flex; gap: 8px;">
            <button type="button" class="tombol polos" id="unduh-gambar">⬇ Unduh gambar</button>
            <button type="button" class="tombol" id="bagikan-gambar">📤 Bagikan ke WhatsApp</button>
        </span>
    </div>

    <div class="gulir-rekap" id="gulir-rekap">
        <div class="rekap-kertas" id="rekap">
            <div class="judul-rekap">REKAPAN RITASI PER DT THN {{ $tahun }}</div>
            <div class="sub-rekap">{{ $judulTujuan }} · {{ count($data['truk']) - count($data['mitra']) }} truk MNP
                @if ($data['mitra']) + {{ count($data['mitra']) }} pemilik non-MNP (kolom oranye, semua truknya digabung per pemilik)@endif
                @if ($data['terakhir']) · data ritasi s/d {{ $data['terakhir']->translatedFormat('j M Y') }}@endif</div>
            @if (! $data['truk'])
                <p style="margin: 10px 0;">Belum ada ritasi truk MNP di tahun ini{{ $pilihan ? ' untuk '.$pilihan['label'] : '' }}.</p>
            @else
                <table class="pivot" data-tanpa-urut data-tanpa-saring-kolom>
                    <thead><tr><th class="kol-label">Bulan / Tanggal</th>
                        @foreach ($data['truk'] as $dt)
                            @if (str_starts_with($dt, '~'))<th class="mitra" title="Truk non-MNP milik {{ $data['label'][$dt] }} (semua plat digabung)">{{ $data['label'][$dt] }}</th>
                            @else<th>{{ $data['label'][$dt] }}</th>@endif
                        @endforeach
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
            // Saringan proyek: satu tahap dari saran (?tahap=), "Semua yang mengandung: ASG" atau kata + Enter (?kata=), kosong = semua proyek.
            const PROYEK = @json(collect($daftarTahap)->mapWithKeys(fn ($t) => [mb_strtolower($t['nama']) => $t['id']]));
            const KINI = @json($tahap ? 'tahap='.$tahap['id'] : ($cc ? 'cc='.rawurlencode($cc) : ($kata !== '' ? 'kata='.rawurlencode($kata) : '')));
            // Saring menurut Proyek / Cost center: tampilkan pilihannya; berpindah mode tidak langsung menyaring sampai salah satu dipilih.
            document.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => {
                const cc = b.dataset.mode === 'cc';
                document.querySelectorAll('[data-mode]').forEach(x => x.classList.toggle('aktif', x === b));
                document.querySelector('.pilih-cc').hidden = !cc;
                document.querySelector('.pilih-proyek').hidden = cc;
                if (!cc) document.getElementById('cari-proyek').focus();
            }));
            const cariProyek = document.getElementById('cari-proyek');
            const tujuanProyek = (bebas) => {
                const asli = cariProyek.value.trim(), v = asli.toLowerCase();
                if (v === '') return '';
                if (PROYEK[v] !== undefined) return 'tahap=' + PROYEK[v];
                const g = asli.match(/^semua yang mengandung:\s*(.+)$/i);
                if (g) return 'kata=' + encodeURIComponent(g[1].trim());
                return bebas ? 'kata=' + encodeURIComponent(asli) : null; // kata bebas hanya lewat Enter
            };
            const keProyek = bebas => {
                const q = tujuanProyek(bebas);
                if (q === null || q === KINI) return;
                location.href = @json(route('ritasi.performa', ['tahun' => $tahun])) + (q ? '&' + q : '');
            };
            cariProyek.addEventListener('change', () => keProyek(false));
            cariProyek.addEventListener('input', () => { if (tujuanProyek(false)) keProyek(false); }); // dipilih dari saran
            cariProyek.addEventListener('search', () => keProyek(true)); // Enter atau tombol × di kotak isian
            cariProyek.addEventListener('focus', () => cariProyek.select());
            // Pilihan pemilik (proyek ASG): tombol Semua / Kosongkan; Terapkan tanpa centang = semua pemilik.
            document.querySelectorAll('[data-semua-pemilik]').forEach(b => b.addEventListener('click', () =>
                document.querySelectorAll('#form-pemilik input[name="pemilik[]"]').forEach(c => { c.checked = b.dataset.semuaPemilik === '1'; })));
            document.getElementById('form-pemilik')?.addEventListener('submit', e => {
                const c = [...e.target.querySelectorAll('input[name="pemilik[]"]')];
                if (c.every(x => x.checked)) c.forEach(x => { x.disabled = true; }); // semua dicentang = tanpa saringan (URL pendek)
                else if (!c.some(x => x.checked)) { e.preventDefault(); alert('Centang minimal satu pemilik truk.'); }
            });

            const rekap = document.getElementById('rekap');
            // Hanya satu bulan terbuka (warna kuning ikut bulan yang terbuka); pilihan pemilik & teks rekap mengikuti bulan itu.
            const TEKS_BULAN = @json((object) $teksPerBulan);
            const NAMA_BULAN = @json((object) collect($data['bulan'])->map(fn ($b) => $b['awal']->translatedFormat('F'))->all());
            let teks = @json($teksBagikan);
            const aturPemilik = m => {
                const form = document.getElementById('form-pemilik');
                if (!form) return;
                form.hidden = !m;
                document.getElementById('bulan-pemilik').value = m || '';
                document.getElementById('judul-bulan-pemilik').textContent = m ? NAMA_BULAN[m] : '';
                form.querySelectorAll('label.centang-pemilik').forEach(l => {
                    const n = JSON.parse(l.dataset.n || '{}')[m] || 0;
                    l.hidden = !n;
                    l.querySelector('input').disabled = !n; // pemilik yang tidak bongkar di bulan ini tidak ikut dikirim
                    l.querySelector('.n-pemilik').textContent = n ? n.toLocaleString('id-ID') : '';
                });
            };
            const atur = (tb, buka) => {
                tb.querySelectorAll('tr.hari').forEach(tr => { tr.hidden = !buka; tr.classList.toggle('kini', buka); });
                tb.querySelector('tr.bln').classList.toggle('kini', buka);
                const t = tb.querySelector('tr.bln .tanda'); if (t) t.textContent = buka ? '⊟' : '⊞';
            };
            const bukaBulan = tbBuka => {
                rekap.querySelectorAll('tbody[data-bulan]').forEach(tb => atur(tb, tb === tbBuka));
                const m = tbBuka?.dataset.bulan || '';
                aturPemilik(m);
                teks = TEKS_BULAN[m] || '';
                const pre = document.getElementById('teks-rekap'); if (pre) pre.textContent = teks;
            };
            rekap.querySelectorAll('tbody[data-bulan]').forEach(tb => tb.querySelector('tr.bln').addEventListener('click', () =>
                bukaBulan(tb.querySelector('tr.hari')?.hidden ? tb : null)));
            aturPemilik(@json((string) ($bulanKini ?? '')));

            const namaFile = @json($namaFile);
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
