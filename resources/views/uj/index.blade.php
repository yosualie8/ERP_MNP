@extends('layouts.app', ['judul' => 'Kas UJ'])

@section('lebar', '1440px')

@section('isi')
    <style>
        table.kas td.mobil { white-space: nowrap; }
        table.kas tr.b.fee td { color: var(--redup); font-style: italic; }
    </style>
    @if (! $bulan)
        <div class="kartu">
            <h3 style="margin-top: 0;">Belum ada data Kas Seabank</h3>
            <p class="redup">Klik sinkron untuk mengambil lembar "Kas Seabank" dari sheet <i>KAS MMP Uang Jalan dan UM</i>.</p>
            <form method="POST" action="{{ route('uj.sinkron') }}">@csrf<button class="tombol" type="submit">Sinkron dari sheet</button></form>
        </div>
    @else
        <div style="margin-bottom: 10px;">
            @foreach ($daftarBulan as $b)
                <a href="{{ route('uj.index', ['bulan' => $b]) }}" @class(['chip', 'aktif' => $b === $bulan])>{{ \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M Y') }}</a>
            @endforeach
        </div>

        @php($detailSemua = $transaksi->flatMap->detail)
        <div class="ringkas">
            <div class="kartu"><span class="redup">Transfer</span><b>{{ $transaksi->count() }}</b></div>
            <div class="kartu"><span class="redup">Total nominal master</span><b>{{ rp($transaksi->sum('nominal')) }}</b></div>
            <div class="kartu"><span class="redup">Biaya transfer</span><b>{{ rp($detailSemua->where('biaya_transfer', true)->sum('nominal')) }}</b></div>
            <div class="kartu"><span class="redup">Belum direimburse</span><b>{{ rp($detailSemua->whereNull('tanggal_reimburse')->sum('nominal')) }}</b></div>
        </div>

        <form method="GET" action="{{ route('uj.index') }}" style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="bulan" value="{{ $bulan }}">
            <input type="search" name="q" value="{{ $q }}" placeholder="Cari nama, keterangan, ID UJ, no mobil, DO, kategori…">
            <button class="tombol" type="submit" style="padding: 8px 14px;">Cari</button>
            @if ($q !== '')
                <a href="{{ route('uj.index', ['bulan' => $bulan]) }}" class="redup">Hapus pencarian</a>
            @endif
            <span class="redup">{{ $transaksi->count() }} transfer · {{ $detailSemua->count() }} detail · terbaru di atas</span>
            <button type="button" class="tombol polos" id="buka-semua">Buka semua detail</button>
            <span class="redup" style="margin-left: auto;">@if ($diimpor)Disinkron {{ \Illuminate\Support\Carbon::parse($diimpor)->translatedFormat('j M Y H:i') }}@endif</span>
            <button type="submit" form="form-sinkron" class="tombol polos" title="Ambil ulang lembar Kas Seabank dari sheet (setelah sheet diubah langsung)">Sinkron dari sheet</button>
            @if ($bolehInput)<a href="{{ route('uj.input') }}" class="tombol" style="padding: 8px 14px;">+ Input UJ</a>@endif
        </form>
        <form method="POST" action="{{ route('uj.sinkron') }}" id="form-sinkron">@csrf</form>

        <div class="kartu gulir" style="padding: 0;">
            <table class="kas">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Penerima / Nama</th>
                        <th>Keterangan</th>
                        <th>Kategori</th>
                        <th>Mobil · DO</th>
                        <th class="angka">Nominal</th>
                        <th>ID UJ · status</th>
                        <th></th>
                    </tr>
                </thead>
                @forelse ($transaksi as $t)
                    @php($detail = $t->detail)
                    @php($jumlah = $detail->reject->biaya_transfer->sum('nominal'))
                    @php($sudah = $t->sudahReimburse())
                    <tbody @class(['grup', 'buka' => $q !== ''])>
                        <tr @class(['t', 'ada-bon' => $detail->isNotEmpty()])>
                            <td>
                                <span class="panah" aria-hidden="true">@if ($detail->isNotEmpty())▸@endif</span>
                                {{ $t->tanggal?->translatedFormat('j M') }}
                                @if ($detail->count() > 1)<span class="jumlah-bon" title="{{ $detail->count() }} baris detail">{{ $detail->count() }}</span>@endif
                            </td>
                            <td><b>{{ $t->nama ?? '—' }}</b> <span class="redup">{{ $t->bank }} {{ $t->rekening }}</span></td>
                            <td>{{ $detail->first()?->keterangan }}@if ($t->nominal && $jumlah !== $t->nominal) <i class="x" title="Jumlah detail (tanpa biaya transfer) tidak sama dengan Nominal Master">detail {{ rp($jumlah) }}</i>@endif</td>
                            <td class="ringkas-gl">{{ $detail->reject->biaya_transfer->pluck('kategori')->filter()->unique()->take(3)->implode(', ') }}</td>
                            <td class="ringkas-gl">{{ $detail->pluck('no_mobil')->filter()->unique()->take(3)->implode(', ') }}</td>
                            <td class="angka"><b>{{ rp($t->nominal, true) }}</b></td>
                            <td class="i">@if ($t->no_uj)UJ-{{ $t->no_uj }}@else<i title="Baris master belum diberi ID UJ di sheet">tanpa ID</i>@endif
                                @if ($sudah)<span class="label hijau">Sudah reimburse</span>@endif</td>
                            <td style="white-space: nowrap;">
                                {{-- Edit, foto bon & Hapus hanya untuk akun yang punya menu Input UJ. --}}
                                @if ($bolehInput && $t->no_uj && ! $sudah)
                                    <a href="{{ route('uj.edit', $t->no_uj) }}" class="tombol-edit" title="Edit transaksi ini (ditulis ulang di sheet)">Edit</a>
                                @endif
                                @if ($bolehInput && ($n = $jumlahFoto[$t->no_uj] ?? 0))
                                    <a href="{{ route('uj.edit', $t->no_uj) }}" class="lampiran ada" title="{{ $n }} foto bon (buka Edit untuk melihat)">📎 {{ $n }}</a>
                                @endif
                                @if ($bolehInput && $t->no_uj && ! $sudah)
                                    <button type="button" class="tombol-hapus" data-hapus="{{ route('uj.hapus', $t->no_uj) }}" data-baris="{{ $t->baris }}–{{ $t->baris_akhir }}"
                                        data-ringkasan="{{ 'UJ-'.$t->no_uj.' · '.rp((int) $t->nominal).' · '.$t->tanggal?->translatedFormat('j M').' · '.$t->nama }}" data-bon="{{ $detail->count() }}">Hapus</button>
                                @endif
                            </td>
                        </tr>
                        @if ($detail->isNotEmpty())
                            <tr class="bh"><td>Transaksi detail</td><td>Nama</td><td>Keterangan</td><td>Kategori</td><td>Mobil · DO</td><td class="angka">Nominal</td><td>ID UJ · reimburse</td><td></td></tr>
                        @endif
                        @foreach ($detail as $d)
                            <tr @class(['b', 'fee' => $d->biaya_transfer])>
                                <td></td>
                                <td class="p">{{ $d->nama }}</td>
                                <td>{{ $d->keterangan }}</td>
                                <td>{{ $d->kategori }}</td>
                                <td class="mobil">{{ $d->no_mobil }}@if ($d->jenis_kendaraan) <i>{{ $d->jenis_kendaraan }}</i>@endif @if ($d->no_do)<span class="redup">DO {{ $d->no_do }}</span>@endif</td>
                                <td class="angka">{{ rp($d->nominal) }}</td>
                                <td class="i">{{ $d->id_uj }} @if ($d->tanggal_reimburse)<i title="Tanggal reimburse">✓ {{ $d->tanggal_reimburse->translatedFormat('j M') }}</i>@endif</td>
                                <td></td>
                            </tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody><tr><td colspan="8" class="redup" style="padding: 20px 14px;">Tidak ada transaksi yang cocok.</td></tr></tbody>
                @endforelse
            </table>
        </div>

        <form method="POST" id="form-hapus" hidden>
            @csrf
            @method('DELETE')
            <input type="hidden" name="q" value="{{ $q }}">
        </form>
        <script>
            document.querySelectorAll('tbody.grup tr.t.ada-bon').forEach(tr => tr.addEventListener('click', e => {
                if (e.target.closest('button, a') || getSelection().toString()) return;
                tr.parentElement.classList.toggle('buka');
                aturTombolSemua();
            }));
            const tombolSemua = document.getElementById('buka-semua');
            const grup = () => [...document.querySelectorAll('tbody.grup')].filter(g => g.querySelector('tr.b'));
            const aturTombolSemua = () => tombolSemua.textContent = grup().every(g => g.classList.contains('buka')) ? 'Tutup semua detail' : 'Buka semua detail';
            tombolSemua.addEventListener('click', () => {
                const buka = !grup().every(g => g.classList.contains('buka'));
                grup().forEach(g => g.classList.toggle('buka', buka));
                aturTombolSemua();
            });
            aturTombolSemua();

            document.querySelectorAll('[data-hapus]').forEach(tombol => tombol.addEventListener('click', () => {
                const d = tombol.dataset;
                if (!confirm(`Hapus dari sheet Kas Seabank (baris ${d.baris}) beserta ${d.bon} baris detailnya?\n\n${d.ringkasan}\n\nBaris di sheet akan dihapus. Isinya tetap tersimpan di riwayat aplikasi.`)) return;
                const form = document.getElementById('form-hapus');
                form.action = d.hapus;
                tombol.disabled = true;
                tombol.textContent = 'Menghapus…';
                form.submit();
            }));
        </script>
    @endif
@endsection
