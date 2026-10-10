@extends('layouts.app', ['judul' => 'Dashboard Aktivitas Admin'])

@section('lebar', '1280px')

@section('isi')
    @php
        $waktu = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-Y H:i') : '—';
        $lalu = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->diffForHumans() : '';
        $saring = fn (array $ubah) => route('dashboard.aktivitas', array_filter([...['periode' => $periode, 'pengguna' => $pengguna, 'area' => $area, 'q' => $cari], ...$ubah], fn ($v) => $v !== null && $v !== ''));
        $warnaArea = ['Kas Harian' => 'hijau', 'Uang Jalan' => 'kuning', 'Ritasi' => 'ungu', 'Aset' => ''];
    @endphp
    @include('dashboard.gaya')
    <style>
        .label.ungu { background: #3b2350; color: #dcb3ff; }
        table.log td { vertical-align: top; }
        .orang-aktif { cursor: pointer; }
        .orang-aktif.dipilih td { background: var(--aksen-muda); }
    </style>

    <div class="kartu gulir" style="padding: 0;">
        <div style="padding: 12px 14px 4px;"><h3 class="dash-judul" style="margin: 0;">Pengguna</h3></div>
        <table style="font-size: 13px;">
            <thead><tr><th style="padding-left: 14px;">Nama</th><th>Peran</th><th>Terakhir aktif</th><th class="angka">Hari ini</th><th class="angka">7 hari</th><th class="angka" style="padding-right: 14px;">Semua</th></tr></thead>
            <tbody>
                @foreach ($orang as $o)
                    <tr @class(['orang-aktif', 'dipilih' => $pengguna === $o['id']]) onclick="location.href='{{ $saring(['pengguna' => $pengguna === $o['id'] ? null : $o['id']]) }}'" title="Klik untuk melihat log {{ $o['nama'] }} saja">
                        <td style="padding-left: 14px;"><b>{{ $o['nama'] }}</b></td>
                        <td>@if ($o['super'])<span class="label merah">Super Admin</span>@else<span class="label">Admin</span>@endif</td>
                        <td>{{ $o['terakhir'] ? $waktu($o['terakhir']) : '—' }} <span class="redup">{{ $lalu($o['terakhir']) }}</span></td>
                        <td class="angka"><b>{{ $o['hari_ini'] ?: '–' }}</b></td>
                        <td class="angka">{{ $o['tujuh_hari'] ?: '–' }}</td>
                        <td class="angka" style="padding-right: 14px;">{{ $o['semua'] ?: '–' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="kartu">
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
            <span class="redup">Periode:</span>
            @foreach (\App\Http\Controllers\AktivitasAdminController::PERIODE as $k => $l)
                <a href="{{ $saring(['periode' => $k]) }}" @class(['chip', 'aktif' => $periode === $k])>{{ $l }}</a>
            @endforeach
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
            <span class="redup">Admin:</span>
            <a href="{{ $saring(['pengguna' => null]) }}" @class(['chip', 'aktif' => ! $pengguna])>Semua · {{ $perOrang->sum() }}</a>
            @foreach ($orang->sortBy('nama') as $o)
                <a href="{{ $saring(['pengguna' => $o['id']]) }}" @class(['chip', 'aktif' => $pengguna === $o['id']])>{{ $o['nama'] }} · {{ $perOrang[$o['id']] ?? 0 }}</a>
            @endforeach
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
            <span class="redup">Area:</span>
            <a href="{{ $saring(['area' => null]) }}" @class(['chip', 'aktif' => ! $area])>Semua · {{ $perArea->sum() }}</a>
            @foreach (['Kas Harian', 'Uang Jalan', 'Ritasi', 'Aset'] as $a)
                <a href="{{ $saring(['area' => $a]) }}" @class(['chip', 'aktif' => $area === $a])>{{ $a }} · {{ $perArea[$a] ?? 0 }}</a>
            @endforeach
        </div>
        <form method="GET" action="{{ route('dashboard.aktivitas') }}" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            @foreach (['periode' => $periode, 'pengguna' => $pengguna, 'area' => $area] as $k => $v)@if ($v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach
            <input type="search" name="q" value="{{ $cari }}" placeholder="Cari di keterangan log: NO ID, ID UJ, PUJ, nama, nominal…" style="max-width: 420px;">
            <button class="tombol" type="submit" style="padding: 8px 14px;">Cari</button>
            @if ($pengguna || $area || $cari !== '')<a href="{{ route('dashboard.aktivitas', ['periode' => $periode]) }}" class="redup">Hapus saringan</a>@endif
            <span class="redup" style="margin-left: auto;">{{ number_format($log->total(), 0, ',', '.') }} aktivitas</span>
        </form>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <div style="padding: 12px 14px 4px;"><h3 class="dash-judul" style="margin: 0;">Log kegiatan</h3></div>
        <table class="log" style="font-size: 13px;">
            <thead><tr><th style="padding-left: 14px;">Waktu</th><th>Pengguna</th><th>Aktivitas</th><th style="padding-right: 14px;">Keterangan</th></tr></thead>
            <tbody>
                @forelse ($log as $r)
                    @php($ar = \App\Support\AktivitasAdmin::area($r->aksi))
                    <tr>
                        <td style="padding-left: 14px; white-space: nowrap;">{{ $waktu($r->created_at) }}<br><span class="redup" style="font-size: 11px;">{{ $lalu($r->created_at) }}</span></td>
                        <td style="white-space: nowrap;">{{ $r->user?->name ?? $r->user?->email ?? '—' }}</td>
                        <td style="white-space: nowrap;"><span class="label {{ $warnaArea[$ar] ?? '' }}">{{ $ar }}</span><br><b>{{ \App\Support\AktivitasAdmin::nama($r->aksi) }}</b></td>
                        <td style="padding-right: 14px;">{{ $r->ringkasan }}
                            @if ($r->baris_awal)<br><span class="redup" style="font-size: 11px;">lembar {{ $r->lembar }} · baris {{ $r->baris_awal }}{{ $r->baris_akhir && $r->baris_akhir !== $r->baris_awal ? '–'.$r->baris_akhir : '' }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="redup" style="padding: 16px 14px;">Tidak ada aktivitas pada saringan ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($log->hasPages())
        <div style="display: flex; gap: 10px; align-items: center; justify-content: center; margin: 10px 0;">
            @if ($log->previousPageUrl())<a href="{{ $log->previousPageUrl() }}" class="tombol polos">← Lebih baru</a>@endif
            <span class="redup">Halaman {{ $log->currentPage() }} dari {{ $log->lastPage() }}</span>
            @if ($log->nextPageUrl())<a href="{{ $log->nextPageUrl() }}" class="tombol polos">Lebih lama →</a>@endif
        </div>
    @endif
@endsection
