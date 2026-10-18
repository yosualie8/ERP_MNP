@extends('layouts.app', ['judul' => 'Ritasi '.$tahap->nama])

@section('lebar', '1400px')

@section('isi')
    @php
        $ang = fn ($v, $d = 0) => $v === null ? '' : (rtrim(rtrim(number_format((float) $v, $d, ',', '.'), '0'), ',') ?: '0');
        $persen = fn ($v) => $v === null ? '–' : number_format($v, 1, ',', '.').'%';
        $tgl = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-y');
        $perM3 = fn ($v) => $r['m3'] > 0 ? $v / $r['m3'] : null;
        $perRit = fn ($v) => $r['rit'] > 0 ? $v / $r['rit'] : null;
        $satuan = $r['dasar'] === 'rit' ? 'rit' : 'm³';
        $kum = 0;
    @endphp
    <style>
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 19px; margin-top: 2px; }
        .ringkas small { font-size: 12px; color: var(--redup); }
        table.kas td.angka, table.kas th.angka { white-space: nowrap; }
        tr.total td { font-weight: 700; background: rgba(255, 255, 255, .03); }
        .setelan { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
        .setelan label { display: block; font-size: 12px; color: var(--redup); margin-bottom: 3px; }
    </style>

    <div class="kartu">
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <a href="{{ route('anggaran.index') }}" class="tombol polos" style="padding: 6px 12px;">← Anggaran Proyek</a>
            <div><h3 style="margin: 0; color: var(--aksen-terang);">{{ $tahap->nama }}</h3>@include('proyek._sub', ['t' => $tahap])</div>
            <span class="redup">{{ $tahap->proyek->nama }} · Ritasi · {{ \App\Models\ProyekTahap::STATUS[$tahap->status] }}@if ($r['mulai']) · bongkar {{ $tgl($r['mulai']) }} – {{ $tgl($r['selesai']) }}@endif</span>
            @if (auth()->user()->bolehMenu('daftar-proyek'))<a href="{{ route('proyek.show', $tahap) }}" style="font-size: 13px;">detail tahap →</a>@endif
        </div>
    </div>

    @if ($errors->any())
        <div class="pesan galat"><ul style="margin: 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('anggaran.ritasi', $tahap) }}" class="kartu setelan">@csrf
        <div><label>Dibayar per</label>
            <select name="dasar_bayar" id="dasar"><option value="m3" @selected($r['dasar'] === 'm3')>m³ bongkaran</option><option value="rit" @selected($r['dasar'] === 'rit')>rit bongkaran</option></select></div>
        <div><label>Harga (Rp per <span id="sat">{{ $satuan }}</span>)</label>
            <input name="harga" id="harga" value="{{ old('harga', $ang($r['harga'], 2)) }}" data-m3="{{ $ang($tahap->harga_m3, 2) }}" data-rit="{{ $ang($tahap->harga_rit, 2) }}" inputmode="decimal" style="width: 150px; text-align: right;" required></div>
        <div style="flex: 1; min-width: 260px;"><label>Kata kunci Kas UJ (pisahkan koma) — untuk UJ yang belum bongkar</label>
            <input name="kata_uj" value="{{ old('kata_uj', $tahap->kata_uj) }}" placeholder="mis. KIK, Kendal" style="width: 100%;"></div>
        <div><label title="UJ yang belum bongkar lebih lama dari ini ditandai kuning di Monitor Ritasi & Pengajuan UJ; lebih dari {{ \App\Support\KontrolUj::MERAH }} hari merah (pengajuan berikutnya wajib beralasan)">Batas wajar bongkar (hari)</label>
            <input name="batas_bongkar" value="{{ old('batas_bongkar', $tahap->batas_bongkar) }}" placeholder="{{ \App\Support\KontrolUj::BATAS_BAWAAN }}" inputmode="numeric" style="width: 90px; text-align: right;"></div>
        <button class="tombol" type="submit">Simpan setelan</button>
    </form>
    <p class="redup" style="font-size: 12px; margin: -6px 0 12px;">Proyek ritasi tidak memakai anggaran HPP / omset: omset = bongkaran × harga, biaya = data aktual (Kas UJ, Data BBM, Kas Harian & BCA, biaya umum) — keduanya ikut bertambah setiap ada data baru.
        "Harga Jual" di lembar Ritasi (tarif internal truk proyek cut & fill) tidak dihitung di sini.</p>

    <div class="ringkas">
        <div class="kartu"><small>Bongkaran</small><b>{{ $ang($r['rit']) }} rit · {{ $ang($r['m3'], 2) }} m³</b><small>{{ $r['ber_ls'] }} rit bernomor tiket LS @if ($r['ngepok'])· {{ $r['ngepok'] }} ngepok @endif</small></div>
        <div class="kartu"><small>Omset realisasi ({{ $ang($r[$r['dasar'] === 'rit' ? 'rit' : 'm3'], 2) }} {{ $satuan }} × {{ rp((int) round($r['harga'])) }})</small><b>{{ rp((int) round($r['omset'])) }}</b></div>
        <div class="kartu"><small>Biaya realisasi</small><b>{{ rp((int) round($r['total'])) }}</b><small>{{ $perRit($r['total']) ? rp((int) round($perRit($r['total']))).'/rit · '.rp((int) round($perM3($r['total']))).'/m³' : '' }}</small></div>
        <div class="kartu"><small>Margin realisasi</small><b @if ($r['margin'] < 0) style="color: var(--merah);" @endif>{{ rp((int) round($r['margin'])) }}</b><small>{{ $persen($r['persen_margin']) }} dari omset{{ $perRit($r['margin']) !== null ? ' · '.rp((int) round($perRit($r['margin']))).'/rit' : '' }}</small></div>
        <div class="kartu"><small>Uang jalan diberikan</small><b>{{ $r['uj_diberi'] }} rit</b><small>sudah bongkar <b style="display: inline; font-size: 12px;">{{ $r['uj_bongkar'] }}</b> · belum bongkar <b style="display: inline; font-size: 12px; {{ $r['uj_belum'] ? 'color: #f0c05a;' : '' }}">{{ $r['uj_belum'] }}</b>@if ($r['rit_tanpa_uj']) · {{ $r['rit_tanpa_uj'] }} rit tanpa UJ @endif @if ($r['uj_sebagian'] ?? 0) · {{ $r['uj_sebagian'] }} dialihkan sebagian @endif</small>@if ($r['alih_keluar']['n'])<small style="display: block;">{{ $r['alih_keluar']['n'] }} UJ ({{ rp((int) $r['alih_keluar']['rp']) }}) dialihkan / dikembalikan — tidak dihitung · <a href="{{ route('pengalihan-uj.index', ['asal' => $tahap->id]) }}">Pengalihan UJ →</a></small>@endif @if ($r['alih_masuk']['n'])<small style="display: block;">+ {{ $r['alih_masuk']['n'] }} UJ dialihkan masuk ({{ rp((int) $r['alih_masuk']['rp']) }}) · terpakai lewat DO lama {{ rp((int) ($r['alih_masuk']['terpakai_do'] ?? 0)) }} · dipakai {{ rp((int) $r['alih_masuk']['pakai']) }} · <b>saldo sopir {{ rp((int) $r['alih_masuk']['saldo']) }}</b> (belum biaya) · <a href="{{ route('pengalihan-uj.index', ['tujuan' => $tahap->id]) }}">Pengalihan UJ →</a></small>@endif</div>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <h4 style="margin: 0; padding: 12px 14px 6px; color: var(--aksen-terang);">Biaya realisasi</h4>
        <table class="kas" data-tanpa-saring-kolom>
            <thead><tr><th>Komponen</th><th class="angka">Jumlah</th><th class="angka">Rata-rata</th><th class="angka">Total (Rp)</th><th class="angka">Per rit bongkar</th><th class="angka">Per m³ bongkar</th></tr></thead>
            <tbody>
                @foreach ($r['biaya'] as $k => [$nama, $n, $rp, $sat])
                    <tr>
                        <td>{{ $nama }}</td>
                        <td class="angka">{{ $n !== null ? $ang($n, 1).' '.$sat : '' }}</td>
                        <td class="angka">{{ $n ? rp((int) round($rp / $n)).' / '.($sat === 'rit diberi UJ' ? 'rit' : ($sat === 'DO' ? 'DO' : $sat)) : '' }}</td>
                        <td class="angka">{{ $rp ? rp((int) round($rp)) : '–' }}</td>
                        <td class="angka">{{ $rp && $perRit($rp) !== null ? rp((int) round($perRit($rp))) : '' }}</td>
                        <td class="angka">{{ $rp && $perM3($rp) !== null ? rp((int) round($perM3($rp))) : '' }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td>Total biaya</td><td></td><td></td><td class="angka">{{ rp((int) round($r['total'])) }}</td><td class="angka">{{ $perRit($r['total']) !== null ? rp((int) round($perRit($r['total']))) : '' }}</td><td class="angka">{{ $perM3($r['total']) !== null ? rp((int) round($perM3($r['total']))) : '' }}</td></tr>
                <tr><td>Omset realisasi</td><td></td><td></td><td class="angka">{{ rp((int) round($r['omset'])) }}</td><td class="angka">{{ $perRit($r['omset']) !== null ? rp((int) round($perRit($r['omset']))) : '' }}</td><td class="angka">{{ $perM3($r['omset']) !== null ? rp((int) round($perM3($r['omset']))) : '' }}</td></tr>
                <tr class="total"><td>Margin</td><td></td><td></td><td class="angka" @if ($r['margin'] < 0) style="color: var(--merah);" @endif>{{ rp((int) round($r['margin'])) }}</td><td class="angka">{{ $perRit($r['margin']) !== null ? rp((int) round($perRit($r['margin']))) : '' }}</td><td class="angka">{{ $perM3($r['margin']) !== null ? rp((int) round($perM3($r['margin']))) : '' }}</td></tr>
            </tbody>
        </table>
    </div>
    <p class="redup" style="font-size: 12px;">Uang Jalan + Uang MOB = Kas UJ yang DO-nya ada di Ritasi tahap ini (sudah bongkar), ditambah UJ berkata kunci "{{ $tahap->kata_uj ?: '—' }}" yang DO-nya belum ada di Ritasi (belum bongkar). Uang MOB PIK→Kendal sudah termasuk uang jalan rit pertama.
        Uang Tanah & Kas UJ lain = transaksi Kas UJ dengan DO yang sama.</p>

    <div class="kartu gulir" style="padding: 0;">
        <h4 style="margin: 0; padding: 12px 14px 6px; color: var(--aksen-terang);">Per tanggal</h4>
        <table class="kas" data-saring-kolom>
            <thead><tr><th>Tanggal</th><th class="angka">UJ diberi (rit)</th><th class="angka">Uang jalan (Rp)</th><th class="angka">Uang tanah (Rp)</th><th class="angka">Rit bongkar</th><th class="angka">m³ bongkar</th><th class="angka">Omset (Rp)</th><th class="angka">Omset kumulatif</th></tr></thead>
            <tbody>
                @foreach ($r['hari'] as $d => $h)
                    @php($kum += $h['omset'] ?? 0)
                    <tr><td data-n="{{ $d }}" data-v="{{ $d }} {{ $tgl($d) }}">{{ $tgl($d) }}</td><td class="angka">{{ $h['uj'] ?? '' }}</td><td class="angka">{{ isset($h['rp_uj']) ? rp((int) $h['rp_uj']) : '' }}</td>
                        <td class="angka">{{ isset($h['rp_tanah']) ? rp((int) $h['rp_tanah']) : '' }}</td><td class="angka">{{ $h['rit'] ?? '' }}</td><td class="angka">{{ isset($h['m3']) ? $ang($h['m3'], 2) : '' }}</td>
                        <td class="angka">{{ ($h['omset'] ?? 0) ? rp((int) round($h['omset'])) : '' }}</td><td class="angka">{{ rp((int) round($kum)) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($r['belum']->isNotEmpty())
        <details class="kartu">
            <summary style="cursor: pointer;"><b>{{ $r['belum']->count() }} UJ belum bongkar</b> <span class="redup" style="font-size: 12px;">(DO belum ada di Ritasi tahap ini)</span></summary>
            <table class="kas" style="margin-top: 8px;" data-tanpa-saring-kolom>
                <thead><tr><th>Tanggal UJ</th><th>ID UJ</th><th>Driver</th><th>No mobil</th><th>No DO</th><th>Keterangan</th><th class="angka">Nominal</th><th class="angka">Umur (hari)</th></tr></thead>
                <tbody>
                    @foreach ($r['belum'] as $u)
                        <tr><td>{{ $u->tanggal->translatedFormat('d-M-y') }}</td><td>{{ $u->id_uj }}</td><td>{{ $u->nama }}</td><td>{{ $u->no_mobil }}</td><td>{{ $u->no_do }}</td><td>{{ $u->keterangan }}</td>
                            <td class="angka">{{ rp((int) $u->nominal) }}</td><td class="angka">{{ (int) $u->tanggal->diffInDays(now()) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endif

    <div class="kartu">
        @include('anggaran._rincian-biaya', ['kas' => $kasTahap, 'overheadBulan' => $overheadBulan])
    </div>

    <script>
        (() => {
            const dasar = document.getElementById('dasar'), harga = document.getElementById('harga'), sat = document.getElementById('sat');
            dasar.addEventListener('change', () => { sat.textContent = dasar.value === 'rit' ? 'rit' : 'm³'; harga.value = harga.dataset[dasar.value] || ''; });
        })();
    </script>
@endsection
