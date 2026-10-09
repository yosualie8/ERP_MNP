@php($tanah = $mode === 'tanah')
@extends('layouts.app', ['judul' => $tanah ? 'Bayar Tanah' : 'Monitor Ritasi'])

@section('lebar', '1440px')

@section('isi')
    <style>
        .umur { display: inline-block; min-width: 54px; text-align: center; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 10px; }
        .umur.baru { background: rgba(76, 195, 138, .18); color: var(--sukses); }
        .umur.sedang { background: rgba(224, 165, 38, .18); color: #f0c05a; }
        .umur.lama { background: rgba(229, 72, 77, .18); color: #ff8a8f; }
        table.kas td.angka, table.kas th.angka { text-align: right; font-variant-numeric: tabular-nums; }
        .cari-monitor { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
        .cari-monitor input[type=search] { min-width: 280px; padding: 8px 10px; border-radius: 7px; }
        table.bongkar td { vertical-align: middle; }
        table.bongkar td.sel-do { cursor: pointer; white-space: nowrap; }
        table.bongkar input.isi-bongkar { width: 100%; min-width: 110px; padding: 6px 8px; border-radius: 6px; font-size: 13px; }
        table.bongkar input[data-tanggal-id].isi-bongkar { min-width: 112px; }
        .tanggal-id { display: inline-flex; align-items: center; gap: 4px; position: relative; width: 100%; }
        .isi-massal .tanggal-id { width: auto; }
        .tanggal-id .tombol-kalender { background: none; border: 1px solid var(--garis); border-radius: 6px; padding: 4px 6px; cursor: pointer; font-size: 13px; line-height: 1; }
        input.tanggal-salah { border-color: var(--aksen) !important; box-shadow: 0 0 0 2px var(--aksen-muda); }
        table.bongkar tr.t.diisi td { background: rgba(76, 195, 138, .10); }
        table.bongkar tr.t.belum-lengkap td, table.bongkar tr.t.baris-salah td { background: rgba(224, 165, 38, .12); }
        table.bongkar tr.t.belum-lengkap input.wajib:placeholder-shown, table.bongkar tr.t.belum-lengkap input.wajib[value=""] { border-color: #e0a526; }
        .simpan-bongkar { position: sticky; bottom: 0; display: flex; gap: 12px; align-items: center; padding: 12px 16px; margin-top: 12px;
            background: var(--kartu); border: 1px solid var(--garis); border-radius: 10px; z-index: 2; }
    </style>

    <div class="ringkas">
        <div class="kartu"><span class="redup">{{ $tanah ? 'DO belum bayar tanah' : 'DO belum ada di Ritasi' }}</span><b>{{ $semua->count() }}</b></div>
        <div class="kartu"><span class="redup">Uang jalan DO tersebut</span><b>{{ rp($semua->sum('total')) }}</b></div>
        <div class="kartu"><span class="redup">Lebih dari 30 hari</span><b>{{ $jumlahUmur['lama'] }}</b></div>
        @if ($tanah)
            <div class="kartu"><span class="redup">Sudah bongkar (ada di Ritasi)</span><b>{{ $semua->whereNotNull('bongkar')->count() }}</b></div>
        @endif
        <div class="kartu"><span class="redup">Data Ritasi terakhir</span><b>{{ $ritasiTerakhir ? \Illuminate\Support\Carbon::parse($ritasiTerakhir)->translatedFormat('j M Y') : '–' }}</b></div>
    </div>

    <p class="redup" style="margin: 0 0 12px; font-size: 13px;">@if ($tanah)Daftar No DO yang sudah ada Uang Jalan-nya di Kas UJ tetapi belum ada transaksi Uang Tanah-nya. Galian yang memang tidak bayar tanah (mis. ambil material) bisa disaring lewat baris Galian.@else DO yang sudah ada uang jalannya di Kas UJ tetapi belum bongkar (belum ada di data Ritasi). Begitu surat jalannya masuk, isi Tanggal Bongkar, Tujuan Bongkar,
            Jenis Tanah & No Surat Jalan lalu klik <b>Simpan bongkar</b> — rit tercatat di Ritasi (aplikasi & sheet) dan DO hilang dari daftar ini.@endif
        Umur dihitung dari tanggal uang jalan pertama DO itu. Klik {{ $tanah ? 'baris' : 'No DO' }} untuk melihat transaksi uang jalannya.</p>

    @php($tautan = fn (array $ubah) => route($rute, array_filter([...['umur' => $umur, 'q' => $q, 'tujuan' => $tujuan, 'galian' => $galian], ...$ubah], fn ($v) => $v !== null && $v !== '')))
    <form method="GET" action="{{ route($rute) }}" class="cari-monitor">
        <span class="redup">Umur:</span>
        <a href="{{ $tautan(['umur' => '7']) }}" @class(['chip', 'aktif' => $umur === '7'])>≤ 7 hari · {{ $jumlahUmur['7'] }}</a>
        <a href="{{ $tautan(['umur' => '30']) }}" @class(['chip', 'aktif' => $umur === '30'])>≤ 30 hari · {{ $jumlahUmur['30'] }}</a>
        <a href="{{ $tautan(['umur' => null]) }}" @class(['chip', 'aktif' => ! $umur])>All time · {{ $semua->count() }}</a>
        @if ($umur)<input type="hidden" name="umur" value="{{ $umur }}">@endif
        @if ($tujuan !== '')<input type="hidden" name="tujuan" value="{{ $tujuan }}">@endif
        @if ($galian !== '')<input type="hidden" name="galian" value="{{ $galian }}">@endif
        <input type="search" name="q" value="{{ $q }}" placeholder="Cari No DO, DT, driver, galian, keterangan…" style="margin-left: auto;">
        <button class="tombol polos" type="submit">Cari</button>
        @if ($q)<a href="{{ $tautan(['q' => null]) }}" class="redup">Hapus pencarian</a>@endif
    </form>
    <div class="cari-monitor">
        <span class="redup">Tujuan buangan:</span>
        <a href="{{ $tautan(['tujuan' => null]) }}" @class(['chip', 'aktif' => $tujuan === ''])>Semua</a>
        @foreach ($jumlahTujuan as $t => $n)
            <a href="{{ $tautan(['tujuan' => $t]) }}" @class(['chip', 'aktif' => $tujuan === $t])>{{ $t }} · {{ $n }}</a>
        @endforeach
        @if ($jumlahTanpa)
            <a href="{{ $tautan(['tujuan' => \App\Support\BuanganTruk::TANPA]) }}" @class(['chip', 'aktif' => $tujuan === \App\Support\BuanganTruk::TANPA])
                title="DO yang truknya tidak terdaftar / tidak aktif di Buangan Truck" style="opacity: .75;">Truk tidak aktif · {{ $jumlahTanpa }}</a>
        @endif
        <a href="{{ route('ritasi.buangan') }}" class="redup" style="margin-left: auto; font-size: 13px;">Atur tujuan buangan truk →</a>
    </div>
    <div class="cari-monitor">
        <span class="redup">Galian:</span>
        <a href="{{ $tautan(['galian' => null]) }}" @class(['chip', 'aktif' => $galian === ''])>Semua</a>
        @foreach ($jumlahGalian as $g => $n)
            <a href="{{ $tautan(['galian' => $g]) }}" @class(['chip', 'aktif' => $galian === $g])>{{ $g }} · {{ $n }}</a>
        @endforeach
    </div>

    @unless ($tanah)
        {{-- Monitor Ritasi: admin mengisi data bongkar dari surat jalan; tiap DO yang diisi menjadi satu rit di Ritasi. --}}
        <form method="POST" action="{{ route('ritasi.monitor.bongkar') }}" id="form-bongkar">
            @csrf
            @if ($errors->any())
                <div class="pesan galat"><b>Belum tersimpan — perbaiki dulu:</b>
                    <ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
            @endif
            @if ($daftar->isNotEmpty())
                <div class="cari-monitor isi-massal">
                    <span class="redup">Isi sekaligus untuk {{ $daftar->count() }} DO yang tampil:</span>
                    <input type="text" id="tanggal-massal" data-tanggal-id data-maks="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" style="padding: 7px 9px; border-radius: 7px; width: 130px;">
                    <button type="button" class="tombol polos" data-massal="tanggal" data-sumber="tanggal-massal" data-label="tanggal bongkar">Isi tanggal bongkar</button>
                    <input type="text" id="jam-massal" data-jam-id value="08:00" style="padding: 7px 9px; border-radius: 7px; width: 80px;">
                    <button type="button" class="tombol polos" data-massal="jam" data-sumber="jam-massal" data-label="jam bongkar">Isi jam bongkar</button>
                    <input type="text" id="tahap-massal" list="saran-tahap" autocomplete="off" placeholder="Tujuan bongkar, mis. ASG Tahap 116" style="padding: 7px 9px; border-radius: 7px; min-width: 210px;">
                    <button type="button" class="tombol polos" data-massal="tahap" data-sumber="tahap-massal" data-label="tujuan bongkar">Isi tujuan bongkar</button>
                    <input type="text" id="tanah-massal" list="saran-tanah" autocomplete="off" placeholder="Jenis tanah / barang" style="padding: 7px 9px; border-radius: 7px; min-width: 170px;">
                    <button type="button" class="tombol polos" data-massal="jenis_tanah" data-sumber="tanah-massal" data-label="jenis tanah / barang">Isi jenis tanah</button>
                    <button type="button" class="tombol polos" id="kosongkan-massal" style="padding: 7px 12px;">Kosongkan isian sekaligus</button>
                    <span class="redup" style="font-size: 12px; flex-basis: 100%;">DO yang hanya berisi isian dari tombol-tombol ini tidak ikut disimpan — cukup ketik No Surat Jalan (atau ubah isiannya) di DO yang sudah bongkar, lalu Simpan bongkar.</span>
                </div>
            @endif
            <div class="kartu gulir" style="padding: 0;">
                <table class="kas bongkar">
                    <thead><tr><th>No DO</th><th>Nomor Mobil</th><th>Jenis Mobil</th><th>Nama Supir</th><th>Tanggal UJ</th><th>Galian</th>
                        <th>Tanggal Bongkar</th><th>Jam Bongkar</th><th>Tujuan Bongkar</th><th>Jenis Tanah / Barang</th><th>No Surat Jalan</th><th>Keterangan</th></tr></thead>
                    @forelse ($daftar as $d)
                        @php($n = 'bongkar['.$d['do'].']')
                        @php($lama = old('bongkar.'.$d['do'], []))
                        @php($salah = $errors->has('bongkar.'.$d['do']) || $errors->has('konfirmasi.'.$d['do']))
                        <tbody class="grup">
                            <tr @class(['t', 'ada-bon', 'baris-salah' => $salah]) data-do="{{ $d['do'] }}">
                                <td class="sel-do" title="Klik untuk melihat transaksi UJ DO ini"><span class="panah">▸</span> <b>{{ $d['do'] }}</b></td>
                                <td>{{ implode(', ', $d['mobil']) ?: '–' }}</td>
                                <td>{{ implode(', ', $d['jenis']) ?: '–' }}</td>
                                <td>{{ implode(', ', $d['driver']) ?: '–' }}</td>
                                <td style="white-space: nowrap;">{{ $d['pertama']?->translatedFormat('j M Y') ?? '–' }}
                                    @if ($d['umur'] !== null)<br><span @class(['umur', 'baru' => $d['umur'] <= 7, 'sedang' => $d['umur'] > 7 && $d['umur'] <= 30, 'lama' => $d['umur'] > 30])>{{ $d['umur'] }} hari</span>@endif</td>
                                <td><input type="text" name="{{ $n }}[galian]" value="{{ $lama['galian'] ?? $d['galian_saran'] }}" list="saran-galian" autocomplete="off" class="isi-bongkar" data-bawaan="{{ $d['galian_saran'] }}"></td>
                                <td><input type="text" name="{{ $n }}[tanggal]" value="{{ $lama['tanggal'] ?? '' }}" data-tanggal-id data-maks="{{ now()->toDateString() }}" class="isi-bongkar wajib"></td>
                                <td><input type="text" name="{{ $n }}[jam]" value="{{ $lama['jam'] ?? '' }}" data-jam-id class="isi-bongkar" style="min-width: 70px;"></td>
                                <td><input type="text" name="{{ $n }}[tahap]" value="{{ $lama['tahap'] ?? '' }}" list="saran-tahap" autocomplete="off" class="isi-bongkar wajib" placeholder="mis. ASG Tahap 116"></td>
                                <td><input type="text" name="{{ $n }}[jenis_tanah]" value="{{ $lama['jenis_tanah'] ?? '' }}" list="saran-tanah" autocomplete="off" class="isi-bongkar wajib"></td>
                                <td><input type="text" name="{{ $n }}[no_seri]" value="{{ $lama['no_seri'] ?? '' }}" autocomplete="off" class="isi-bongkar wajib" inputmode="numeric"></td>
                                <td><input type="text" name="{{ $n }}[keterangan]" value="{{ $lama['keterangan'] ?? '' }}" autocomplete="off" class="isi-bongkar" maxlength="300" placeholder="catatan admin">
                                    @if ($errors->has('konfirmasi.'.$d['do']) || ($lama['konfirmasi'] ?? ''))
                                        <input type="text" name="{{ $n }}[konfirmasi]" value="{{ $lama['konfirmasi'] ?? '' }}" autocomplete="off" class="isi-bongkar" maxlength="1000"
                                            placeholder="konfirmasi FLAG (min. 10 karakter)" style="margin-top: 4px; border-color: #e0a526;">
                                    @endif
                                </td>
                            </tr>
                            <tr class="bh"><td>ID UJ</td><td>Tanggal</td><td>Status</td><td>No Mobil · Jenis</td><td>Supir</td><td colspan="5">Keterangan UJ</td><td>Kategori</td><td class="angka">Nominal</td></tr>
                            @foreach ($d['detail'] as $x)
                                <tr class="b">
                                    <td class="i">{{ $x->id_uj ?: 'baris '.$x->baris }}</td>
                                    <td>{{ $x->tanggal?->translatedFormat('j M Y') }}</td>
                                    <td class="redup" style="font-size: 12px;">{{ $x->status }}</td>
                                    <td>{{ $x->no_mobil }}@if ($x->jenis_kendaraan) <span class="redup">· {{ $x->jenis_kendaraan }}</span>@endif</td>
                                    <td class="p">{{ $x->nama }}</td>
                                    <td colspan="5">{{ $x->keterangan }}</td>
                                    <td>{{ $x->kategori }}</td>
                                    <td class="angka">{{ rp((int) $x->nominal) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @empty
                        <tbody><tr><td colspan="12" class="redup" style="padding: 20px 14px;">{{ $q || $umur || $tujuan !== '' || $galian !== '' ? 'Tidak ada DO yang cocok dengan saringan ini.' : 'Semua DO di Kas UJ sudah ada di data Ritasi.' }}</td></tr></tbody>
                    @endforelse
                </table>
            </div>
            <datalist id="saran-tahap">@foreach ($saran['tahap'] as $s)<option value="{{ $s }}">@endforeach</datalist>
            <datalist id="saran-tanah">@foreach ($saran['jenis_tanah'] as $s)<option value="{{ $s }}">@endforeach</datalist>
            <datalist id="saran-galian">@foreach ($saran['galian'] as $s)<option value="{{ $s }}">@endforeach</datalist>
            <div class="simpan-bongkar">
                <button class="tombol" type="submit" id="simpan-bongkar" disabled>Simpan bongkar</button>
                <span class="redup" id="info-bongkar">Isi Tanggal Bongkar, Tujuan Bongkar, Jenis Tanah & No Surat Jalan dari surat jalan untuk DO yang sudah bongkar.</span>
            </div>
        </form>
    @else
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>No DO</th><th>UJ pertama</th><th>Umur</th><th>No Mobil</th><th>Tujuan buangan</th><th>Driver</th><th>Galian</th>@if ($tanah)<th>Bongkar</th>@endif<th>Kategori</th><th class="angka">Transaksi</th><th class="angka">Total UJ</th></tr></thead>
            @forelse ($daftar as $d)
                <tbody class="grup">
                    <tr class="t ada-bon">
                        <td><span class="panah">▸</span> <b>{{ $d['do'] }}</b></td>
                        <td>{{ $d['pertama']?->translatedFormat('j M Y') ?? '–' }}@if ($d['terakhir'] && $d['pertama'] && ! $d['terakhir']->isSameDay($d['pertama']))<span class="redup" style="font-size: 12px;"> s/d {{ $d['terakhir']->translatedFormat('j M') }}</span>@endif</td>
                        <td>@if ($d['umur'] !== null)<span @class(['umur', 'baru' => $d['umur'] <= 7, 'sedang' => $d['umur'] > 7 && $d['umur'] <= 30, 'lama' => $d['umur'] > 30])>{{ $d['umur'] }} hari</span>@endif</td>
                        <td>{{ implode(', ', $d['mobil']) ?: '–' }}</td>
                        <td>{{ implode(', ', $d['buangan']) ?: '–' }}</td>
                        <td>{{ implode(', ', $d['driver']) ?: '–' }}</td>
                        <td>{{ $d['tujuan'] ? ucwords($d['tujuan']) : '–' }}</td>
                        @if ($tanah)<td>{!! $d['bongkar'] ? '✓ '.e($d['bongkar']->translatedFormat('j M')) : '<span class="redup">belum</span>' !!}</td>@endif
                        <td class="ringkas-gl">{{ implode(', ', $d['kategori']) }}</td>
                        <td class="angka">{{ count($d['detail']) }}</td>
                        <td class="angka"><b>{{ rp($d['total']) }}</b></td>
                    </tr>
                    <tr class="bh"><td>ID UJ</td><td>Tanggal</td><td>Status</td><td>No Mobil · Jenis</td><td>Driver</td><td colspan="{{ $tanah ? 4 : 3 }}">Keterangan</td><td>Kategori</td><td class="angka">Nominal</td></tr>
                    @foreach ($d['detail'] as $x)
                        <tr class="b">
                            <td class="i">{{ $x->id_uj ?: 'baris '.$x->baris }}</td>
                            <td>{{ $x->tanggal?->translatedFormat('j M Y') }}</td>
                            <td class="redup" style="font-size: 12px;">{{ $x->status }}</td>
                            <td>{{ $x->no_mobil }}@if ($x->jenis_kendaraan) <span class="redup">· {{ $x->jenis_kendaraan }}</span>@endif</td>
                            <td class="p">{{ $x->nama }}</td>
                            <td colspan="{{ $tanah ? 4 : 3 }}">{{ $x->keterangan }} <span class="redup">· DO {{ $x->no_do }}</span></td>
                            <td>{{ $x->kategori }}</td>
                            <td class="angka">{{ rp((int) $x->nominal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="{{ $tanah ? 11 : 10 }}" class="redup" style="padding: 20px 14px;">{{ $q || $umur || $tujuan !== '' || $galian !== '' ? 'Tidak ada DO yang cocok dengan saringan ini.' : ($tanah ? 'Semua DO yang ada uang jalannya sudah ada transaksi uang tanahnya.' : 'Semua DO di Kas UJ sudah ada di data Ritasi.') }}</td></tr></tbody>
            @endforelse
        </table>
    </div>
    @endunless

    @if ($bukanAngka->isNotEmpty())
        <p class="redup" style="font-size: 12px; margin-top: 10px;">Tidak ikut dimonitor: {{ $bukanAngka->count() }} isian "No DO" di Kas UJ yang bukan nomor DO
            ({{ $bukanAngka->take(12)->implode(', ') }}{{ $bukanAngka->count() > 12 ? ', …' : '' }}).</p>
    @endif

    <script src="{{ asset('js/tanggal-id.js') }}"></script>
    <script>
        (() => {
            // Bayar Tanah: klik baris membuka transaksi. Monitor Ritasi: hanya sel No DO (sel lain berisi isian).
            document.querySelectorAll('tbody.grup tr.t').forEach(tr => (tr.querySelector('.sel-do') ?? tr).addEventListener('click', () => tr.parentElement.classList.toggle('buka')));
            const form = document.getElementById('form-bongkar');
            if (!form) return;
            // Tanggal selalu tampil "dd-MMM-yyyy" (mis. 09-Okt-2026), tidak mengikuti pengaturan bahasa komputer.
            TanggalId.pasangSemua(form);
            const tombol = document.getElementById('simpan-bongkar');
            const info = document.getElementById('info-bongkar');
            const awal = info.textContent;
            const baris = [...form.querySelectorAll('tr.t[data-do]')];
            // Baris terisi = salah satu kolom bongkar/keterangan diisi. Galian (terisi otomatis) dan tanggal hasil "isi massal"
            // tidak dihitung, supaya DO yang hanya berisi tanggal massal tidak ikut disimpan.
            const terisi = tr => [...tr.querySelectorAll('.isi-bongkar')].some(i => i.name.endsWith('[galian]') || i.dataset.massal === '1' ? false : i.value.trim());
            const kurang = tr => [...tr.querySelectorAll('.isi-bongkar.wajib')].filter(i => !i.value.trim()).length + (tr.querySelector('[name$="[galian]"]').value.trim() ? 0 : 1);
            const hitung = () => {
                const isi = baris.filter(terisi);
                baris.forEach(tr => tr.classList.toggle('diisi', terisi(tr)));
                baris.forEach(tr => tr.classList.toggle('belum-lengkap', terisi(tr) && kurang(tr) > 0));
                const belum = isi.filter(tr => kurang(tr) > 0);
                tombol.disabled = !isi.length || belum.length > 0;
                info.textContent = !isi.length ? awal : belum.length
                    ? `${isi.length} DO diisi · ${belum.length} belum lengkap (DO ${belum.map(tr => tr.dataset.do).join(', ')}) — lengkapi Tanggal, Tujuan, Galian, Jenis Tanah & No Surat Jalan.`
                    : `${isi.length} DO siap disimpan sebagai bongkar: ${isi.map(tr => tr.dataset.do).join(', ')}.`;
            };
            form.addEventListener('input', e => {
                if (e.target.dataset?.massal) delete e.target.dataset.massal; // diubah sendiri = isian biasa
                hitung();
            });
            // Isi Tanggal / Jam Bongkar semua DO yang tampil (hasil saringan) sekaligus.
            const kolomBaris = f => baris.map(tr => tr.querySelector(`[name$="[${f}]"]`));
            // Kolom bongkar lain (selain galian & isian sekaligus) sudah diisi = baris memang sedang dicatat.
            const lainTerisi = tr => [...tr.querySelectorAll('.isi-bongkar')].some(x => !x.name.endsWith('[galian]') && x.dataset.massal !== '1' && x.value.trim());
            form.querySelectorAll('button[data-massal]').forEach(b => b.addEventListener('click', () => {
                const f = b.dataset.massal, label = b.dataset.label, nilai = document.getElementById(b.dataset.sumber).value.trim().replace(/\s+/g, ' ');
                if (!nilai) { alert(`Isi ${label}nya dulu.`); return; }
                const ada = kolomBaris(f).filter(i => i.value && i.value !== nilai && i.dataset.massal !== '1');
                const timpa = ada.length ? confirm(`${ada.length} DO sudah punya ${label} lain. Timpa juga?\n\nOK = timpa semua · Batal = isi yang masih kosong saja`) : false;
                kolomBaris(f).forEach(i => {
                    if (i.value && i.dataset.massal !== '1' && !timpa) return;
                    i.value = nilai;
                    i.dispatchEvent(new Event('blur')); // rapikan format & hapus tanda salah lama
                    i.dataset.massal = '1';
                    if (lainTerisi(i.closest('tr'))) delete i.dataset.massal;
                });
                hitung();
            }));
            // Enter di kotak isian sekaligus = jalankan tombol "Isi …"-nya (bukan mengirim form).
            form.querySelectorAll('button[data-massal]').forEach(b => document.getElementById(b.dataset.sumber)
                ?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); b.click(); } }));
            // Kosongkan isian sekaligus di DO yang belum dicatat (isian yang diketik sendiri, dan DO yang sudah diberi
            // No Surat Jalan / isian lain, tidak disentuh).
            document.getElementById('kosongkan-massal')?.addEventListener('click', () => {
                ['tanggal', 'jam', 'tahap', 'jenis_tanah'].flatMap(kolomBaris)
                    .forEach(i => { if (i.dataset.massal === '1' && !lainTerisi(i.closest('tr'))) { i.value = ''; delete i.dataset.massal; } });
                hitung();
            });
            // Baris yang tidak diisi tidak ikut dikirim.
            form.addEventListener('submit', e => {
                if (!confirm(`Simpan ${baris.filter(terisi).length} DO sebagai sudah bongkar? Data masuk ke Ritasi (aplikasi & sheet).`)) { e.preventDefault(); return; }
                baris.filter(tr => !terisi(tr)).forEach(tr => tr.querySelectorAll('input').forEach(i => i.disabled = true));
                tombol.disabled = true; tombol.textContent = 'Menyimpan…';
            });
            window.addEventListener('beforeunload', e => { if (baris.some(terisi) && tombol.textContent !== 'Menyimpan…') e.preventDefault(); });
            hitung();
        })();
    </script>
@endsection
