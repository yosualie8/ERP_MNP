@extends('layouts.app', ['judul' => 'Daftar Proyek'])

@section('lebar', '1400px')

@section('isi')
    @php
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y') : '';
        $des = fn ($v, $d = 2) => $v !== null && $v !== '' ? number_format((float) $v, $d, ',', '.') : '';
        $warnaStatus = ['belum' => '', 'progress' => 'kuning', 'selesai' => 'hijau', 'batal' => 'merah'];
    @endphp
    <style>
        .lepas { border-left: 3px solid #e0a526; }
        .lepas table td { padding: 4px 8px; font-size: 13px; vertical-align: middle; }
        .lepas select { padding: 4px 6px; border-radius: 6px; max-width: 320px; }
        table.kas tr.t td .kecil { font-size: 12px; color: var(--redup); font-weight: 400; }
    </style>

    <div class="kartu">
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <h3 class="dash-judul" style="margin: 0; color: var(--aksen-terang);">Daftar Proyek</h3>
            <span class="redup" style="font-size: 13px;">Proyek → tahap. Nama lama di Ritasi, BBM, Cost Project & Kas Harian dipetakan ke tahap lewat "alias".</span>
            <a href="{{ route('proyek.create') }}" class="tombol" style="margin-left: auto; padding: 8px 14px;">+ Tahap baru</a>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px;">
            <a href="{{ route('proyek.index', array_filter(['q' => $cari])) }}" @class(['chip', 'aktif' => ! $status])>Semua · {{ $jumlahStatus->sum() }}</a>
            @foreach (\App\Models\ProyekTahap::STATUS as $k => $l)
                <a href="{{ route('proyek.index', array_filter(['status' => $k, 'q' => $cari])) }}" @class(['chip', 'aktif' => $status === $k])>{{ $l }} · {{ $jumlahStatus[$k] ?? 0 }}</a>
            @endforeach
            <form method="GET" style="display: flex; gap: 6px; margin-left: auto;">
                @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
                <input type="search" name="q" value="{{ $cari }}" placeholder="Cari tahap / lokasi / proyek…" style="width: 240px;">
                <button class="tombol polos" style="padding: 6px 12px;">Cari</button>
            </form>
            <button type="button" class="tombol polos" id="buka-semua" style="padding: 6px 12px;">Buka semua</button>
        </div>
    </div>

    @if ($lepasRitasi || $lepasKas)
        <div class="kartu lepas">
            <h4 style="margin: 0 0 6px; color: #f0c05a;">⚠ Nama yang belum terpetakan ke tahap</h4>
            <p class="redup" style="margin: 0 0 8px; font-size: 13px;">Data dengan nama ini belum ikut terhitung di tahap mana pun. Pilih tahapnya lalu klik Petakan.</p>
            <table>
                @foreach ([['ritasi', $lepasRitasi], ['kas_cc', $lepasKas]] as [$sumber, $daftar])
                    @foreach ($daftar as $l)
                        <tr>
                            <td><span class="label">{{ $sumber === 'ritasi' ? 'Ritasi' : 'Kas Harian' }}</span></td>
                            <td><b>{{ $l['nama'] }}</b></td>
                            <td class="redup">@if ($sumber === 'ritasi'){{ ($l['rit'] ?? 0) + ($l['ngepok'] ?? 0) }} rit · {{ $tgl($l['mulai']) }} s/d {{ $tgl($l['selesai']) }}@else{{ $l['n'] }} detail{{ $super ? ' · '.rp($l['rp']) : '' }}@endif</td>
                            <td>
                                <form method="POST" action="{{ route('proyek.petakan') }}" style="display: flex; gap: 6px;">
                                    @csrf
                                    <input type="hidden" name="sumber" value="{{ $sumber === 'ritasi' ? 'ritasi' : (str_contains($l['nama'], ' ') ? 'kas' : 'kas_cc') }}">
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
        <table class="kas" style="font-size: 13px;">
            <thead>
                <tr><th>Tahap</th><th>Status</th><th>Lokasi / surveyor</th><th class="angka">Volume · Luas · Tinggi</th>
                    @if ($super)<th class="angka">Omset</th>@endif
                    <th class="angka">Ritasi</th><th>Periode ritasi</th><th class="angka">Kas Harian</th><th></th></tr>
            </thead>
            @foreach ($proyek as $p)
                @php
                    $rit = $p->tahap->sum(fn ($t) => ($ritasi[$t->id]['rit'] ?? 0) + ($ritasi[$t->id]['ngepok'] ?? 0));
                    $kasN = $p->tahap->sum(fn ($t) => $kas[$t->id]['n'] ?? 0);
                    $kasRp = $p->tahap->sum(fn ($t) => $kas[$t->id]['rp'] ?? 0);
                @endphp
                <tbody @class(['grup', 'buka' => $cari !== '' || $status])>
                    <tr class="t ada-bon">
                        <td colspan="3"><span class="panah">▸</span> <b>{{ $p->nama }}</b>
                            <span class="kecil">· {{ $p->tahap->count() }} tahap{{ $p->cost_center ? ' · cost center '.$p->cost_center : '' }}</span></td>
                        <td></td>
                        @if ($super)<td class="angka"><b>{{ ($o = $p->tahap->sum(fn ($t) => $t->omsetHitung() ?? 0)) ? rp($o) : '' }}</b></td>@endif
                        <td class="angka">{{ $rit ? number_format($rit, 0, ',', '.') : '' }}</td><td></td>
                        <td class="angka">{{ $kasN ? number_format($kasN, 0, ',', '.').' detail' : '' }}@if ($super && $kasRp)<br><span class="kecil">{{ rp($kasRp) }}</span>@endif</td>
                        <td><a href="{{ route('proyek.create', ['proyek' => $p->id]) }}" class="tombol-edit" title="Tambah tahap di proyek ini">+ Tahap</a></td>
                    </tr>
                    @foreach ($p->tahap as $t)
                        @php($r = $ritasi[$t->id] ?? [])
                        <tr class="b">
                            <td><b>{{ $t->nama }}</b>@if ($t->tipe !== 'luas') <span class="label">{{ \App\Models\ProyekTahap::TIPE[$t->tipe] }}</span>@endif @if ($t->catatan)<br><span class="redup" style="font-size: 11px;">{{ \Illuminate\Support\Str::limit($t->catatan, 90) }}</span>@endif</td>
                            <td><span class="label {{ $warnaStatus[$t->status] ?? '' }}">{{ \App\Models\ProyekTahap::STATUS[$t->status] }}</span></td>
                            <td>{{ $t->lokasi }}</td>
                            <td class="angka" style="white-space: nowrap;">@if ($t->volume || $t->luas){{ $des($t->volume) }} m³ · {{ $des($t->luas) }} m² @if ($t->tinggiUrugan()) · {{ $des($t->tinggiUrugan(), 3) }} m @endif @endif</td>
                            @if ($super)<td class="angka">{{ $t->omsetHitung() ? rp($t->omsetHitung()) : '' }}</td>@endif
                            <td class="angka">{{ ($r['rit'] ?? 0) ? number_format($r['rit'], 0, ',', '.') : '' }}@if ($r['ngepok'] ?? 0)<br><span class="redup" style="font-size: 11px;">+{{ $r['ngepok'] }} ngepok</span>@endif</td>
                            <td style="white-space: nowrap;">{{ $tgl($r['mulai'] ?? null) }}@if (($r['selesai'] ?? null) && $r['selesai'] !== $r['mulai']) – {{ $tgl($r['selesai']) }}@endif</td>
                            <td class="angka">{{ ($kas[$t->id]['n'] ?? 0) ? number_format($kas[$t->id]['n'], 0, ',', '.').' detail' : '' }}@if ($super && ($kas[$t->id]['rp'] ?? 0))<br><span class="redup" style="font-size: 11px;">{{ rp($kas[$t->id]['rp']) }}</span>@endif</td>
                            <td style="white-space: nowrap;"><a href="{{ route('proyek.edit', $t) }}" class="tombol-edit">Edit</a> <span class="redup" title="Jumlah nama lama yang dipetakan" style="font-size: 11px;">{{ $t->alias_count }} alias</span></td>
                        </tr>
                    @endforeach
                </tbody>
            @endforeach
        </table>
    </div>

    <script>
        (() => {
            document.querySelectorAll('tbody.grup tr.t').forEach(tr => tr.addEventListener('click', e => {
                if (e.target.closest('a, button, form')) return;
                tr.parentElement.classList.toggle('buka'); atur();
            }));
            const tombol = document.getElementById('buka-semua');
            const grup = () => [...document.querySelectorAll('tbody.grup')];
            const atur = () => tombol.textContent = grup().every(g => g.classList.contains('buka')) ? 'Tutup semua' : 'Buka semua';
            tombol.addEventListener('click', () => { const b = !grup().every(g => g.classList.contains('buka')); grup().forEach(g => g.classList.toggle('buka', b)); atur(); });
            atur();
        })();
    </script>
@endsection
