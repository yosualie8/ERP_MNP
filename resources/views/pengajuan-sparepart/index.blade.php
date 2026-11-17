@extends('layouts.app', ['judul' => 'Pengajuan Sparepart'])

@section('lebar', '1400px')

@section('isi')
    <style>
        .psp-atas { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
        .psp-atas .chip { margin: 0; }
        table.kas tr.t[data-href] { cursor: pointer; }
        table.kas tr.t[data-href]:hover td { background: var(--kartu-2); }
        .kecil { font-size: 11.5px; color: var(--redup); }
        /* Status barang per pengajuan (warna sama dengan halaman detail) */
        .pg { --pg: var(--garis-kuat); }
        .pg-disetujui { --pg: #5b9cf5; } .pg-dibeli { --pg: #f0a43a; } .pg-terpasang { --pg: #4cc38a; } .pg-ditolak { --pg: #ff7b7f; } .pg-diulang { --pg: #a78bfa; }
        .pg-batang { display: flex; height: 7px; width: 130px; border-radius: 4px; overflow: hidden; background: var(--kartu-2); margin-bottom: 4px; }
        .pg-batang span { background: var(--pg); }
        .pg-teks { font-size: 11.5px; white-space: nowrap; }
        .pg-teks b { color: var(--pg); font-weight: 600; }
        .pg-teks .pg-belum b { color: var(--redup); }
    </style>

    <p class="redup" style="margin-top: 0;">Daftar sparepart yang diajukan ke manajemen. Saat membuat pengajuan, ketik nama sparepart & jenisnya — sistem mengusulkan item dan
        harganya dari <b>Database Sparepart</b> (harga pembelian terakhir), subtotal & total dihitung otomatis. Klik baris untuk melihat rinciannya, status tiap sparepart
        (<b>Disetujui / Dibeli / Terpasang / Ditolak</b>, ditandai Admin Plant) dan foto progres pekerjaannya; yang belum di-ACC bisa diajukan ulang dari halaman detail.</p>

    <div class="psp-atas">
        <a href="{{ route('pengajuan-sparepart.index') }}" @class(['chip', 'aktif' => ! $status])>Semua · {{ $jumlah->sum() }}</a>
        @foreach (\App\Models\PengajuanSparepart::STATUS as $k => $l)
            @if ($jumlah[$k] ?? 0)<a href="{{ route('pengajuan-sparepart.index', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $l }} · {{ $jumlah[$k] }}</a>@endif
        @endforeach
        <a href="{{ route('pengajuan-sparepart.buat') }}" class="tombol" style="margin-left: auto;">+ Buat pengajuan</a>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Nomor</th><th>Tanggal</th><th>Diajukan oleh</th><th>Keperluan</th><th class="angka">Item</th><th>Status Barang</th><th class="angka">Total</th><th>Status</th><th>Diputus</th></tr></thead>
            <tbody>
                @forelse ($daftar as $p)
                    <tr class="t" data-href="{{ route('pengajuan-sparepart.lihat', $p) }}">
                        <td style="white-space: nowrap;"><a href="{{ route('pengajuan-sparepart.lihat', $p) }}"><b>{{ $p->nomor }}</b></a></td>
                        <td data-n="{{ $p->tanggal->toDateString() }}" style="white-space: nowrap;">{{ $p->tanggal->translatedFormat('j M Y') }}</td>
                        <td>{{ $p->pembuat?->name }}</td>
                        <td>{{ $p->keperluan }}@if ($p->catatan)<div class="kecil">{{ \Illuminate\Support\Str::limit($p->catatan, 80) }}</div>@endif</td>
                        <td class="angka">{{ $p->detail_count }}</td>
                        @php($pg = $p->progres())
                        <td data-n="{{ $p->detail_count ? round((($pg['terpasang'] ?? 0) * 3 + ($pg['dibeli'] ?? 0) * 2 + ($pg['disetujui'] ?? 0)) / $p->detail_count / 3 * 100) : 0 }}">
                            <div class="pg-batang">@foreach ($pg as $k => $n)<span class="pg pg-{{ $k ?: 'belum' }}" style="width: {{ $n / max(1, $p->detail_count) * 100 }}%"></span>@endforeach</div>
                            <div class="pg-teks">@foreach ($pg as $k => $n)<span class="pg pg-{{ $k ?: 'belum' }}"><b>{{ $n }} {{ strtolower(\App\Models\PengajuanSparepartDetail::labelProgres($k ?: null)) }}</b></span>@if (! $loop->last) · @endif @endforeach</div>
                            @if ($p->foto_count)<div class="kecil">📷 {{ $p->foto_count }} foto progres</div>@endif
                        </td>
                        <td class="angka" data-n="{{ $p->total }}"><b>{{ rp($p->total) }}</b></td>
                        <td data-v="{{ \App\Models\PengajuanSparepart::STATUS[$p->status] }}"><span class="label {{ \App\Models\PengajuanSparepart::WARNA[$p->status] }}">{{ \App\Models\PengajuanSparepart::STATUS[$p->status] }}</span></td>
                        <td>@if ($p->diputus_pada){{ $p->pemutus?->name }}<div class="kecil">{{ $p->diputus_pada->translatedFormat('j M Y H:i') }}</div>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="redup">Belum ada pengajuan{{ $status ? ' berstatus '.\App\Models\PengajuanSparepart::STATUS[$status] : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
        document.querySelector('table.kas tbody').addEventListener('click', e => {
            const tr = e.target.closest('tr[data-href]');
            if (tr && !e.target.closest('a') && !window.getSelection().toString()) location.href = tr.dataset.href;
        });
    </script>
@endsection
