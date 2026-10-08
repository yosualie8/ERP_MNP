@extends('layouts.app', ['judul' => 'Cek Data Truk'])

@section('lebar', '1500px')

@section('isi')
    @php($tgl = fn ($t) => \Illuminate\Support\Carbon::parse($t)->translatedFormat('j M Y'))
    <div class="kartu">
        <div style="display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: center;">
            <h3 style="margin: 0;">Pencocokan truk MNP × Ritasi × Kas UJ <span class="redup" style="font-weight: normal;">sejak 1 Jan 2026</span></h3>
            <a href="{{ route('aset.temuan.excel') }}" class="tombol polos">⬇ Excel semua temuan</a>
        </div>
        <table style="margin-top: 10px;">
            <thead><tr><th>Kode</th><th>Jenis temuan</th><th class="angka">Jumlah</th><th class="angka">Nilai</th><th>Arti / tindakan</th></tr></thead>
            <tbody>
                @foreach (\App\Support\CekTruk::JENIS as $k => [$judul, $arti])
                    @php($g = $perKode->get($k, collect()))
                    <tr @class(['dipilih' => $kode === $k])>
                        <td><a href="{{ route('aset.temuan', ['kode' => $k, 'mobil' => $mobil]) }}" @class(['chip', 'aktif' => $kode === $k])>{{ $k }}</a></td>
                        <td>{{ $judul }}</td><td class="angka"><b>{{ $g->count() }}</b></td><td class="angka">{{ $g->sum('nominal') ? rp($g->sum('nominal')) : '' }}</td>
                        <td class="redup">{{ $arti }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
        <span class="redup">Menampilkan {{ $temuan->count() }} temuan</span>
        @if ($kode)<span class="label kuning">{{ $kode }} · {{ \App\Support\CekTruk::JENIS[$kode][0] }}</span>@endif
        @if ($mobil)<span class="label kuning">{{ $mobil }}</span>@endif
        @if ($kode || $mobil)<a href="{{ route('aset.temuan') }}" class="redup">Hapus saringan</a>@endif
    </div>
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Kode</th><th>Tanggal</th><th>No Mobil</th><th>Referensi</th><th>No DO</th><th>Nama/Driver</th><th>Keterangan</th><th class="angka">Nominal</th><th>Rincian</th></tr></thead>
            <tbody>
                @forelse ($temuan->take(500) as $t)
                    <tr>
                        <td><span class="label kuning" title="{{ \App\Support\CekTruk::JENIS[$t['kode']][0] }}">{{ $t['kode'] }}</span></td>
                        <td style="white-space: nowrap;">{{ $tgl($t['tanggal']) }}</td>
                        <td>@if ($t['mobil'])<a href="{{ route('aset.temuan', ['kode' => $kode, 'mobil' => $t['mobil']]) }}">{{ $t['mobil'] }}</a>@else<span class="label merah">kosong</span>@endif</td>
                        <td class="i">{{ $t['ref'] }}</td><td>{{ $t['do'] }}</td><td>{{ $t['nama'] }}</td><td>{{ $t['ket'] }}</td>
                        <td class="angka">{{ $t['nominal'] ? rp($t['nominal']) : '' }}</td><td>{{ $t['rincian'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="redup" style="padding: 20px 14px;">Tidak ada temuan. 🎉</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($temuan->count() > 500)<p class="redup">Menampilkan 500 terbaru dari {{ $temuan->count() }} — saring per kode / truk, atau unduh Excel untuk semuanya.</p>@endif
@endsection
