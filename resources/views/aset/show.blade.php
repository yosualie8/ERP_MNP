@extends('layouts.app', ['judul' => 'Aset '.$aset->no_lambung])

@section('lebar', '1280px')

@section('isi')
    @php($tgl = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->translatedFormat('j M Y') : '–')
    <div class="kartu">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
            <h3 style="margin: 0;">🚛 {{ $aset->no_lambung }} <span class="redup" style="font-weight: normal;">{{ $aset->plat }} · {{ $aset->jenis }}</span>
                <span @class(['label', 'hijau' => $aset->status === 'aktif', 'kuning' => $aset->status === 'perbaikan', 'merah' => $aset->status === 'dijual'])>{{ $aset->labelStatus() }}</span></h3>
            <div style="display: flex; gap: 8px;">
                <a href="{{ route('aset.index') }}" class="tombol polos">← Daftar</a>
                @if (auth()->user()->bolehMenu('aset'))<a href="{{ route('aset.edit', $aset) }}" class="tombol">Edit</a>@endif
            </div>
        </div>
        <table style="margin-top: 12px; max-width: 900px;">
            <tr><td class="redup" style="width: 180px;">Tahun</td><td>{{ $aset->tahun ?? '–' }}</td><td class="redup" style="width: 160px;">Driver tetap</td><td>{{ $aset->driver_tetap ?? '–' }}</td></tr>
            <tr><td class="redup">No rangka</td><td>{{ $aset->no_rangka ?? '–' }}</td><td class="redup">No mesin</td><td>{{ $aset->no_mesin ?? '–' }}</td></tr>
            <tr><td class="redup">STNK berlaku s.d.</td><td>{{ $tgl($aset->stnk_berlaku) }}@if ($aset->stnk_berlaku?->isPast()) <span class="label merah">lewat</span>@endif</td>
                <td class="redup">KIR berlaku s.d.</td><td>{{ $tgl($aset->kir_berlaku) }}@if ($aset->kir_berlaku?->isPast()) <span class="label merah">lewat</span>@endif</td></tr>
            @if ($aset->catatan)<tr><td class="redup">Catatan</td><td colspan="3">{{ $aset->catatan }}</td></tr>@endif
        </table>
    </div>

    <div class="ringkas">
        <div class="kartu"><span class="redup">Rit sejak Jan 2026</span><b>{{ $statistik['rit'] ?? 0 }}</b></div>
        <div class="kartu"><span class="redup">Rit 30 hari</span><b>{{ $statistik['rit_30'] ?? 0 }}</b></div>
        <div class="kartu"><span class="redup">Rit terakhir</span><b>{{ $tgl($statistik['rit_terakhir'] ?? null) }}</b></div>
        <div class="kartu"><span class="redup">Kas UJ 30 hari</span><b>{{ rp($statistik['uj_30'] ?? 0) }}</b></div>
        <div class="kartu"><span class="redup">Data janggal</span><b>@if ($temuan->count())<a href="{{ route('aset.temuan', ['mobil' => $aset->no_lambung]) }}">{{ $temuan->count() }} temuan</a>@else 0 @endif</b></div>
    </div>

    <div class="kartu gulir">
        <h3 style="margin: 0 0 8px;">Per bulan</h3>
        @php($bulan = $ritPerBulan->keys()->merge($ujPerBulan->keys())->unique()->sort()->values())
        <table>
            <tr><td class="redup">Bulan</td>@foreach ($bulan as $b)<td class="angka">{{ \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M y') }}</td>@endforeach</tr>
            <tr><td class="redup">Rit</td>@foreach ($bulan as $b)<td class="angka">{{ $ritPerBulan[$b] ?? '–' }}</td>@endforeach</tr>
            <tr><td class="redup">Kas UJ (rb)</td>@foreach ($bulan as $b)<td class="angka">{{ isset($ujPerBulan[$b]) ? number_format($ujPerBulan[$b] / 1000, 0, ',', '.') : '–' }}</td>@endforeach</tr>
        </table>
    </div>

    @if ($temuan->isNotEmpty())
        <div class="kartu gulir" style="padding: 0;">
            <h3 style="margin: 12px 14px 8px;">⚠ Data janggal {{ $aset->no_lambung }} ({{ $temuan->count() }})</h3>
            <table class="kas">
                <thead><tr><th>Jenis</th><th>Tanggal</th><th>Referensi</th><th>No DO</th><th>Nama</th><th>Keterangan</th><th class="angka">Nominal</th><th>Rincian</th></tr></thead>
                <tbody>
                    @foreach ($temuan->take(100) as $t)
                        <tr><td><span class="label kuning" title="{{ \App\Support\CekTruk::JENIS[$t['kode']][1] }}">{{ $t['kode'] }}</span> {{ \App\Support\CekTruk::JENIS[$t['kode']][0] }}</td>
                            <td>{{ $tgl($t['tanggal']) }}</td><td class="i">{{ $t['ref'] }}</td><td>{{ $t['do'] }}</td><td>{{ $t['nama'] }}</td><td>{{ $t['ket'] }}</td>
                            <td class="angka">{{ $t['nominal'] ? rp($t['nominal']) : '' }}</td><td>{{ $t['rincian'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
        <div class="kartu gulir" style="padding: 0;">
            <h3 style="margin: 12px 14px 8px;">Rit terakhir</h3>
            <table class="kas">
                <thead><tr><th>Tanggal</th><th>Seri</th><th>DO</th><th>Driver</th><th>Tahap · galian</th></tr></thead>
                <tbody>
                    @forelse ($rit as $r)
                        <tr><td>{{ $tgl($r->tanggal) }}</td><td>{{ $r->no_seri }}</td><td>{{ $r->no_do }}</td><td>{{ explode('/', (string) $r->no_polisi)[1] ?? '' }}</td><td>{{ $r->tahap }} · {{ $r->galian }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="redup">Belum ada rit bernomor {{ $aset->no_lambung }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="kartu gulir" style="padding: 0;">
            <h3 style="margin: 12px 14px 8px;">Kas UJ terakhir</h3>
            <table class="kas">
                <thead><tr><th>Tanggal</th><th>ID UJ</th><th>Nama</th><th>Keterangan</th><th class="angka">Nominal</th></tr></thead>
                <tbody>
                    @forelse ($uj as $u)
                        <tr><td>{{ $tgl($u->tanggal) }}</td><td class="i">{{ $u->id_uj }}</td><td>{{ $u->nama }}</td><td>{{ $u->keterangan }} <span class="redup">{{ $u->kategori }}@if ($u->no_do) · DO {{ $u->no_do }}@endif</span></td><td class="angka">{{ rp($u->nominal) }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="redup">Belum ada transaksi Kas UJ untuk {{ $aset->no_lambung }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
