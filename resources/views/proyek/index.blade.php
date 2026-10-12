@extends('layouts.app', ['judul' => 'Daftar Proyek'])

@section('lebar', '1400px')

@section('isi')
    @php
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y') : '';
        // Nol di belakang koma dibuang hanya untuk desimal (50 tetap 50, 175,80 jadi 175,8).
        $ang = fn ($v, $d = 0) => ! $v ? '0' : ($d ? rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',') : number_format((float) $v, 0, ',', '.'));
        $warnaStatus = ['belum' => '', 'progress' => 'kuning', 'selesai' => 'hijau', 'batal' => 'merah'];
        $rincian = ['rit' => ['Ritasi', 'rit'], 'ngepok' => ['Ngepok', 'rit'], 'solar' => ['Solar', 'liter'], 'rental' => ['Rental alat', 'jam HM'], 'kas' => ['Operasional Kas Harian', 'transaksi']];
    @endphp
    <style>
        .lepas { border-left: 3px solid #e0a526; }
        .lepas table td { padding: 4px 8px; font-size: 13px; vertical-align: middle; }
        .lepas select { padding: 4px 6px; border-radius: 6px; max-width: 320px; }
        table.grid { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.grid th, table.grid td { border: 1px solid var(--garis); padding: 6px 8px; vertical-align: middle; }
        table.grid thead th { position: sticky; top: 0; background: var(--kartu, #1e2229); z-index: 1; }
        table.grid thead tr.saring th { top: 31px; padding: 4px; }
        table.grid .saring input, table.grid .saring select { width: 100%; box-sizing: border-box; padding: 4px 6px; font-size: 12px; border-radius: 5px; min-width: 60px; }
        table.grid tr.m { cursor: pointer; }
        table.grid tr.m:hover td { background: rgba(255, 255, 255, .03); }
        table.grid tr.m .panah { display: inline-block; width: 12px; transition: transform .15s; color: var(--redup); }
        table.grid tr.m.buka .panah { transform: rotate(90deg); }
        table.grid tr.d { display: none; }
        table.grid tr.d.buka { display: table-row; }
        table.grid tr.d > td { background: rgba(0, 0, 0, .15); padding: 8px 12px 10px 34px; }
        table.rinci { border-collapse: collapse; min-width: 520px; }
        table.rinci td, table.rinci th { border: 1px solid var(--garis); padding: 4px 10px; font-size: 12.5px; }
        table.rinci tr.total td { font-weight: 700; }
        .angka { text-align: right; white-space: nowrap; }
    </style>

    <div class="kartu">
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <h3 class="dash-judul" style="margin: 0; color: var(--aksen-terang);">Daftar Proyek</h3>
            <span class="redup" style="font-size: 13px;">Semua tahap, urut tanggal mulai. Klik baris untuk melihat rincian biaya. Isi kotak di bawah judul kolom untuk menyaring (angka/tanggal bisa pakai &gt; atau &lt;, mis. &gt;100000000).</span>
            <a href="{{ route('proyek.create') }}" class="tombol" style="margin-left: auto; padding: 8px 14px;">+ Tahap baru</a>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px; font-size: 13px;">
            <span id="jumlah-tampil" class="redup"></span>
            <button type="button" class="tombol polos" id="hapus-saring" style="padding: 6px 12px;">Hapus saringan</button>
            <button type="button" class="tombol polos" id="buka-semua" style="padding: 6px 12px;">Buka semua</button>
            <span class="redup" style="margin-left: auto;">Data solar & jam HM dari lembar Penggunaan BBM{{ $waktuBbm ? ', diambil '.\Illuminate\Support\Carbon::parse($waktuBbm)->translatedFormat('d-M H:i') : ' (sheet belum terhubung)' }}</span>
            <a href="{{ route('proyek.index', ['segarkan' => 1]) }}" class="tombol polos" style="padding: 6px 12px;">↻ Segarkan BBM</a>
        </div>
    </div>

    @if ($lepasRitasi || $lepasKas || $lepasBbm)
        <div class="kartu lepas">
            <h4 style="margin: 0 0 6px; color: #f0c05a;">⚠ Nama yang belum terpetakan ke tahap</h4>
            <p class="redup" style="margin: 0 0 8px; font-size: 13px;">Data dengan nama ini belum ikut terhitung di tahap mana pun. Pilih tahapnya lalu klik Petakan.</p>
            <table>
                @foreach ([['ritasi', $lepasRitasi], ['bbm', $lepasBbm], ['kas_cc', $lepasKas]] as [$sumber, $daftar])
                    @foreach ($daftar as $l)
                        <tr>
                            <td><span class="label">{{ ['ritasi' => 'Ritasi', 'bbm' => 'BBM', 'kas_cc' => 'Kas Harian'][$sumber] }}</span></td>
                            <td><b>{{ $l['nama'] }}</b></td>
                            <td class="redup">
                                @if ($sumber === 'ritasi'){{ ($l['rit'] ?? 0) + ($l['ngepok'] ?? 0) }} rit · {{ $tgl($l['mulai']) }} s/d {{ $tgl($l['selesai']) }}
                                @elseif ($sumber === 'bbm'){{ $l['n'] }} baris · {{ $ang($l['liter']) }} liter
                                @else{{ $l['n'] }} detail{{ $super ? ' · '.rp($l['rp']) : '' }}
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('proyek.petakan') }}" style="display: flex; gap: 6px;">
                                    @csrf
                                    <input type="hidden" name="sumber" value="{{ $sumber === 'kas_cc' ? (str_contains($l['nama'], ' ') ? 'kas' : 'kas_cc') : $sumber }}">
                                    <input type="hidden" name="nama" value="{{ $l['nama'] }}">
                                    <select name="tahap_id" required><option value="">— pilih tahap —</option>
                                        @foreach ($semuaTahap as $t)<option value="{{ $t->id }}">{{ $t->proyek->nama }} › {{ $t->nama }}</option>@endforeach</select>
                                    <button class="tombol-edit">Petakan</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </table>
        </div>
    @endif

    <div class="kartu gulir" style="padding: 0;">
        <table class="grid" id="grid">
            <thead>
                <tr><th style="width: 40px;">No</th><th>Tahap</th><th>Status</th><th>Surveyor</th><th>Tanggal mulai</th><th>Tanggal akhir</th>
                    @if ($super)<th class="angka">Total biaya</th>@endif</tr>
                <tr class="saring">
                    <th></th>
                    <th><input data-kolom="1" placeholder="Saring…"></th>
                    <th><select data-kolom="2"><option value="">Semua</option>@foreach (\App\Models\ProyekTahap::STATUS as $l)<option>{{ $l }}</option>@endforeach</select></th>
                    <th><input data-kolom="3" placeholder="Saring…"></th>
                    <th><input data-kolom="4" placeholder="mis. 2026 / >2026-07-01"></th>
                    <th><input data-kolom="5" placeholder="Saring…"></th>
                    @if ($super)<th><input data-kolom="6" placeholder="mis. >100000000"></th>@endif
                </tr>
            </thead>
            <tbody>
                @foreach ($baris as $i => $x)
                    @php($t = $x->t)
                    <tr class="m">
                        <td class="angka redup">{{ $i + 1 }}</td>
                        <td data-v="{{ $t->nama }} {{ $t->proyek->nama }}"><span class="panah">▸</span> <a href="{{ route('proyek.show', $t) }}" title="Lihat transaksi Kas Harian tahap ini"><b>{{ $t->nama }}</b></a> <span class="redup" style="font-size: 11px;">· {{ $t->proyek->nama }}</span>@if ($t->tipe !== 'luas') <span class="label">{{ \App\Models\ProyekTahap::TIPE[$t->tipe] }}</span>@endif</td>
                        <td data-v="{{ \App\Models\ProyekTahap::STATUS[$t->status] }}"><span class="label {{ $warnaStatus[$t->status] ?? '' }}">{{ \App\Models\ProyekTahap::STATUS[$t->status] }}</span></td>
                        <td>{{ $t->lokasi }}</td>
                        <td data-v="{{ $x->mulai }} {{ $tgl($x->mulai) }}" data-n="{{ $x->mulai }}" style="white-space: nowrap;">{{ $tgl($x->mulai) }}</td>
                        <td data-v="{{ $x->akhir }} {{ $tgl($x->akhir) }}" data-n="{{ $x->akhir }}" style="white-space: nowrap;">{{ $tgl($x->akhir) }}</td>
                        @if ($super)<td class="angka" data-n="{{ $x->total }}"><b>{{ $x->total ? rp($x->total) : '' }}</b></td>@endif
                    </tr>
                    <tr class="d">
                        <td colspan="{{ $super ? 7 : 6 }}">
                            <table class="rinci">
                                <tr><th style="text-align: left;">Biaya</th><th class="angka">Jumlah</th>@if ($super)<th class="angka">Nominal</th><th style="text-align: left;">Dasar hitung</th>@endif</tr>
                                @foreach ($rincian as $k => [$label, $satuan])
                                    @php([$n, $rp] = $x->biaya[$k])
                                    <tr><td>{{ $label }}@if ($k === 'kas' && $n) <a href="{{ route('proyek.show', $t) }}" style="font-size: 12px;">lihat transaksi →</a>@endif</td><td class="angka">{{ $ang($n, $k === 'solar' || $k === 'rental' ? 1 : 0) }} {{ $satuan }}</td>
                                        @if ($super)<td class="angka">{{ rp($rp) }}</td>
                                            <td class="redup">{{ match ($k) {
                                                'rit', 'ngepok' => 'Harga Jual di lembar Ritasi',
                                                'solar' => '× '.rp($t->tarif_bbm ?? \App\Models\ProyekTahap::BAWAAN['tarif_bbm']).'/liter',
                                                'rental' => '× '.rp($t->tarif_rental ?? \App\Models\ProyekTahap::BAWAAN['tarif_rental']).'/jam',
                                                'kas' => 'transaksi detail Kas Harian',
                                            } }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                                @if ($super)<tr class="total"><td>Total biaya</td><td></td><td class="angka">{{ rp($x->total) }}</td><td></td></tr>@endif
                            </table>
                            @if ($t->catatan)<div class="redup" style="font-size: 12px; margin-top: 6px;">Catatan: {{ $t->catatan }}</div>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <script>
        (() => {
            const grid = document.getElementById('grid');
            const master = [...grid.querySelectorAll('tbody tr.m')];
            const saring = [...grid.querySelectorAll('[data-kolom]')];
            const info = document.getElementById('jumlah-tampil');
            const tombol = document.getElementById('buka-semua');
            const cocok = (td, q) => {
                q = q.trim().toLowerCase();
                if (!q) return true;
                const m = q.match(/^([<>]=?)\s*(.+)$/);
                if (m && td.dataset.n !== undefined) {
                    const a = td.dataset.n, b = m[2].replace(/\./g, '');
                    if (a === '') return false;
                    const [x, y] = /^\d{4}-/.test(b) ? [a, b] : [parseFloat(a), parseFloat(b)];
                    return m[1] === '>' ? x > y : m[1] === '<' ? x < y : m[1] === '>=' ? x >= y : x <= y;
                }
                return (td.dataset.v ?? td.textContent).toLowerCase().includes(q);
            };
            const terapkan = () => {
                let n = 0;
                master.forEach(tr => {
                    const tampil = saring.every(s => cocok(tr.cells[s.dataset.kolom], s.value));
                    tr.style.display = tampil ? '' : 'none';
                    tr.nextElementSibling.style.display = tampil ? '' : 'none';
                    n += tampil;
                });
                info.textContent = `${n} dari ${master.length} tahap`;
            };
            saring.forEach(s => s.addEventListener('input', terapkan));
            document.getElementById('hapus-saring').addEventListener('click', () => { saring.forEach(s => s.value = ''); terapkan(); });
            const q = new URLSearchParams(location.search).get('q');
            if (q) saring[0].value = q;
            terapkan();

            const atur = () => tombol.textContent = master.every(m => m.classList.contains('buka')) ? 'Tutup semua' : 'Buka semua';
            const buka = (tr, b) => { tr.classList.toggle('buka', b); tr.nextElementSibling.classList.toggle('buka', b); };
            master.forEach(tr => tr.addEventListener('click', e => {
                if (e.target.closest('a, button, form')) return;
                buka(tr, !tr.classList.contains('buka')); atur();
            }));
            tombol.addEventListener('click', () => { const b = !master.every(m => m.classList.contains('buka')); master.forEach(m => buka(m, b)); atur(); });
            atur();
        })();
    </script>
@endsection
