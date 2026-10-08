@extends('layouts.app', ['judul' => 'Pengajuan UJ'])

@section('lebar', '1440px')

@section('isi')
    <style>
        table.kas tr.b.terealisasi td { color: var(--sukses); }
        table.kas tr.b.batal td { color: var(--redup); text-decoration: line-through; }
    </style>
    <div class="ringkas">
        <div class="kartu"><span class="redup">Menunggu realisasi</span><b>{{ rp((int) $menunggu->s) }}</b></div>
        <div class="kartu"><span class="redup">Detail menunggu</span><b>{{ (int) $menunggu->n }}</b></div>
        <div class="kartu"><span class="redup">Pengajuan terbuka</span><b>{{ $semua->whereIn('status', ['diajukan', 'sebagian'])->count() }}</b></div>
    </div>

    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="redup">Status:</span>
        <a href="{{ route('pengajuan-uj.daftar') }}" @class(['chip', 'aktif' => ! $status])>Terbuka + terbaru</a>
        @foreach (\App\Models\UjPengajuan::STATUS as $k => $l)
            @if ($n = $semua->where('status', $k)->count())
                <a href="{{ route('pengajuan-uj.daftar', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $l }} · {{ $n }}</a>
            @endif
        @endforeach
        <a href="{{ route('pengajuan-uj.buat') }}" class="tombol" style="margin-left: auto; padding: 8px 14px;">+ Ajukan uang jalan</a>
        @if ($bolehRealisasi)<a href="{{ route('uj.input') }}" class="tombol polos" style="padding: 8px 14px;">Realisasikan di Input UJ →</a>@endif
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Kode</th><th>Tanggal pengajuan</th><th>Driver</th><th>Kategori</th><th class="angka">Total</th><th>Status</th><th>Diajukan</th><th></th></tr></thead>
            @forelse ($pengajuan as $p)
                @php($real = $p->detail->where('status', 'terealisasi'))
                <tbody @class(['grup', 'buka' => in_array($p->status, ['diajukan', 'sebagian'], true)])>
                    <tr class="t ada-bon">
                        <td><span class="panah">▸</span> <b>{{ $p->kode() }}</b> <span class="jumlah-bon">{{ $p->detail->count() }}</span></td>
                        <td>{{ $p->tanggal->translatedFormat('j M Y') }}</td>
                        <td><b>{{ $p->detail->pluck('nama')->filter()->unique()->take(4)->implode(', ') }}</b>@if ($p->detail->pluck('nama')->filter()->unique()->count() > 4) …@endif</td>
                        <td class="ringkas-gl">{{ $p->detail->pluck('kategori')->unique()->take(3)->implode(', ') }}</td>
                        <td class="angka"><b>{{ rp($p->nominal) }}</b></td>
                        <td><span @class(['label', 'kuning' => $p->status === 'sebagian', 'hijau' => $p->status === 'selesai', 'merah' => $p->status === 'batal'])>{{ \App\Models\UjPengajuan::STATUS[$p->status] }}</span>
                            @if ($real->count())<span class="redup" style="font-size: 12px;">{{ $real->count() }}/{{ $p->detail->count() }} · {{ rp($real->sum('nominal')) }}</span>@endif</td>
                        <td class="redup" style="font-size: 12px;">{{ $p->user?->name ?? $p->user?->email }} · {{ $p->created_at->translatedFormat('j M H:i') }}</td>
                        <td style="white-space: nowrap;">
                            @if ($p->status === 'diajukan')<a href="{{ route('pengajuan-uj.ubah', $p) }}" class="tombol-edit">Edit</a>@endif
                            @if (in_array($p->status, ['diajukan', 'sebagian'], true))
                                <form method="POST" action="{{ route('pengajuan-uj.batal', $p) }}" style="display: inline;" onsubmit="return confirm('Batalkan detail {{ $p->kode() }} yang masih menunggu? Detail yang sudah terealisasi tidak berubah.')">
                                    @csrf<button class="tombol-hapus" type="submit">Batalkan</button></form>
                            @endif
                        </td>
                    </tr>
                    <tr class="bh"><td>Detail</td><td>Nama</td><td>Keterangan</td><td>Kategori · Mobil · DO</td><td class="angka">Nominal</td><td>Status</td><td>Realisasi</td><td></td></tr>
                    @foreach ($p->detail as $d)
                        <tr @class(['b', 'terealisasi' => $d->status === 'terealisasi', 'batal' => $d->status === 'batal'])>
                            <td></td><td class="p">{{ $d->nama }}</td><td>{{ $d->keterangan }}@if ($d->temuan)<i title="{{ collect($d->temuan)->pluck('pesan')->implode(' · ') }} — konfirmasi: {{ $d->konfirmasi }}"> ⚠ FLAG dikonfirmasi</i>@endif</td>
                            <td>{{ $d->kategori }} @if ($d->no_mobil)<i>{{ $d->no_mobil }}</i>@endif @if ($d->no_do)<span class="redup">DO {{ $d->no_do }}</span>@endif</td>
                            <td class="angka">{{ rp($d->nominal) }}</td>
                            <td>{{ ['menunggu' => '⏳ Menunggu', 'terealisasi' => '✓ Terealisasi', 'batal' => 'Dibatalkan'][$d->status] }}</td>
                            <td class="i">{{ $d->id_uj }}@if ($d->realisasi_pada) · {{ $d->realisasi_pada->translatedFormat('j M') }}@endif</td><td></td>
                        </tr>
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="8" class="redup" style="padding: 20px 14px;">Belum ada pengajuan uang jalan.</td></tr></tbody>
            @endforelse
        </table>
    </div>
    <script>
        document.querySelectorAll('tbody.grup tr.t').forEach(tr => tr.addEventListener('click', e => {
            if (e.target.closest('button, a, form')) return;
            tr.parentElement.classList.toggle('buka');
        }));
    </script>
@endsection
