@extends('layouts.app', ['judul' => 'Pengajuan UJ'])

@section('lebar', '1440px')

@section('isi')
    <style>
        table.kas tr.b.terealisasi td { color: var(--sukses); }
        table.kas tr.b.dialihkan td { color: #dcb3ff; }
        table.kas tr.b.batal td { color: var(--redup); text-decoration: line-through; }
        table.kas tr.b.berbeda td { color: #f0c05a; }
        table.kas tr.realisasi td { padding-top: 0; font-size: 12px; color: var(--teks); }
        table.kas tbody.grup:not(.buka) tr.realisasi { display: none; }
        .info-beda { border-left: 3px solid #e0a526; background: #1d1810; border-radius: 6px; padding: 6px 10px; line-height: 1.5; }
        .info-beda.dialihkan { border-left-color: #c77dff; background: #1d1426; }
        .info-beda del { color: var(--redup); }
        .label.ungu { background: #3b2350; color: #dcb3ff; }
    </style>
    <div class="ringkas">
        <div class="kartu"><span class="redup">Menunggu realisasi</span><b>{{ rp((int) $menunggu->s) }}</b></div>
        <div class="kartu"><span class="redup">Detail menunggu</span><b>{{ (int) $menunggu->n }}</b></div>
        <div class="kartu"><span class="redup">Pengajuan terbuka</span><b>{{ $semua->whereIn('status', ['diajukan', 'sebagian'])->count() }}</b></div>
        <a class="kartu" href="{{ route('pengajuan-uj.daftar', ['beda' => 1]) }}" style="text-decoration: none; color: inherit;">
            <span class="redup">Realisasi tidak sesuai</span>
            <b>{{ $detailBeda->count() }}</b>
            <span class="redup" style="font-size: 12px;">{{ $detailBeda->where('jenis_realisasi', 'dialihkan')->count() }} dialihkan · {{ $detailBeda->where('jenis_realisasi', 'penyesuaian')->count() }} penyesuaian</span>
        </a>
    </div>

    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="redup">Status:</span>
        <a href="{{ route('pengajuan-uj.daftar') }}" @class(['chip', 'aktif' => ! $status && ! $beda])>Terbuka + terbaru</a>
        @foreach (\App\Models\UjPengajuan::STATUS as $k => $l)
            @if ($n = $semua->where('status', $k)->count())
                <a href="{{ route('pengajuan-uj.daftar', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $l }} · {{ $n }}</a>
            @endif
        @endforeach
        @if ($detailBeda->count())
            <a href="{{ route('pengajuan-uj.daftar', ['beda' => 1]) }}" @class(['chip', 'aktif' => $beda])>⚠ Tidak sesuai / dialihkan · {{ $detailBeda->count() }}</a>
        @endif
        <a href="{{ route('pengajuan-uj.buat') }}" class="tombol" style="margin-left: auto; padding: 8px 14px;">+ Ajukan uang jalan</a>
        @if ($bolehRealisasi)<a href="{{ route('uj.input') }}" class="tombol polos" style="padding: 8px 14px;">Realisasikan di Input UJ →</a>@endif
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Kode</th><th>Tanggal pengajuan</th><th>Driver</th><th>Kategori</th><th class="angka">Total</th><th>Status</th><th>Diajukan</th><th></th></tr></thead>
            @forelse ($pengajuan as $p)
                @php($real = $p->detail->whereIn('status', ['terealisasi', 'dialihkan']))
                @php($nBeda = $p->detail->filter(fn ($d) => $d->berbeda())->count())
                <tbody @class(['grup', 'buka' => $beda || in_array($p->status, ['diajukan', 'sebagian'], true)])>
                    <tr class="t ada-bon">
                        <td><span class="panah">▸</span> <b>{{ $p->kode() }}</b> <span class="jumlah-bon">{{ $p->detail->count() }}</span></td>
                        <td>{{ $p->tanggal->translatedFormat('j M Y') }}</td>
                        <td><b>{{ $p->detail->pluck('nama')->filter()->unique()->take(4)->implode(', ') }}</b>@if ($p->detail->pluck('nama')->filter()->unique()->count() > 4) …@endif</td>
                        <td class="ringkas-gl">{{ $p->detail->pluck('kategori')->unique()->take(3)->implode(', ') }}</td>
                        <td class="angka"><b>{{ rp($p->nominal) }}</b></td>
                        <td><span @class(['label', 'kuning' => $p->status === 'sebagian', 'hijau' => $p->status === 'selesai', 'merah' => $p->status === 'batal'])>{{ \App\Models\UjPengajuan::STATUS[$p->status] }}</span>
                            @if ($nBeda)<span class="label kuning" title="Realisasi tidak sama dengan pengajuan">⚠ {{ $nBeda }} tidak sesuai</span>@endif
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
                        <tr @class(['b', $d->status, 'berbeda' => $d->jenis_realisasi === 'penyesuaian'])>
                            <td></td><td class="p">{{ $d->nama }}</td><td>{{ $d->keterangan }}@if ($d->temuan)<i title="{{ collect($d->temuan)->pluck('pesan')->implode(' · ') }} — konfirmasi: {{ $d->konfirmasi }}"> ⚠ FLAG dikonfirmasi</i>@endif</td>
                            <td>{{ $d->kategori }} @if ($d->no_mobil)<i>{{ $d->no_mobil }}</i>@endif @if ($d->no_do)<span class="redup">DO {{ $d->no_do }}</span>@endif</td>
                            <td class="angka">{{ rp($d->nominal) }}</td>
                            <td>{{ $d->jenis_realisasi === 'penyesuaian' ? '✓ Terealisasi (berbeda)' : \App\Models\UjPengajuanDetail::STATUS[$d->status] ?? $d->status }}</td>
                            <td class="i">{{ $d->id_uj }}@if ($d->realisasi_pada) · {{ $d->realisasi_pada->translatedFormat('j M') }}@endif</td>
                            <td style="white-space: nowrap;">@if ($bolehRealisasi && $d->status === 'menunggu')<a href="{{ route('uj.input', ['pengajuan' => $d->id]) }}" class="tombol-edit"
                                title="Buka Input UJ yang sudah berisi detail ini persis seperti pengajuannya">➜ Input UJ</a>@endif</td>
                        </tr>
                        @if ($d->berbeda() && $d->realisasi)
                            @php($r = $d->realisasi)
                            <tr class="realisasi"><td></td><td colspan="7">
                                <div @class(['info-beda', 'dialihkan' => $d->jenis_realisasi === 'dialihkan'])>
                                    <span @class(['label', 'ungu' => $d->jenis_realisasi === 'dialihkan', 'kuning' => $d->jenis_realisasi !== 'dialihkan'])>{{ $d->jenis_realisasi === 'dialihkan' ? '↪ Dialihkan' : '≠ Penyesuaian' }}</span>
                                    <b>Realisasi {{ $r['id_uj'] ?? $d->id_uj }}:</b> {{ $r['keterangan'] ?? '' }} · {{ $r['kategori'] ?? '' }}@if (! empty($r['no_mobil'])) · {{ $r['no_mobil'] }}@endif @if (! empty($r['no_do'])) · DO {{ $r['no_do'] }}@endif · <b>{{ rp((int) ($r['nominal'] ?? 0)) }}</b>
                                    @if ((int) ($r['nominal'] ?? 0) !== (int) $d->nominal)<span class="redup">(selisih {{ ((int) $r['nominal'] - (int) $d->nominal) > 0 ? '+' : '−' }}{{ rp(abs((int) $r['nominal'] - (int) $d->nominal)) }})</span>@endif
                                    <br>Perbedaan: @foreach ($r['beda'] ?? [] as $b){{ $b['kolom'] }} <del>{{ $b['lama'] }}</del> → <b>{{ $b['baru'] }}</b>@if (! $loop->last); @endif @endforeach
                                    <br>Alasan: <i>{{ $d->alasan }}</i>@if ($d->jenis_realisasi === 'dialihkan' && $d->no_do) <span class="redup">· DO {{ $d->no_do }} tetap dihitung sudah dibiayai</span>@endif
                                </div>
                            </td></tr>
                        @endif
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="8" class="redup" style="padding: 20px 14px;">{{ $beda ? 'Belum ada realisasi yang tidak sesuai dengan pengajuannya.' : 'Belum ada pengajuan uang jalan.' }}</td></tr></tbody>
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
