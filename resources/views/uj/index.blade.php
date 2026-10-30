@extends('layouts.app', ['judul' => 'Kas UJ'])

@section('lebar', '1440px')

@section('isi')
    <style>
        table.kas td.mobil { white-space: nowrap; }
        table.kas tr.b.fee td { color: var(--redup); font-style: italic; }
        .label.alih { background: rgba(78, 161, 255, .16); color: #8cc4ff; }
        .label.kembali { background: rgba(167, 139, 250, .16); color: #c4b5fd; }
        .label.batal { background: var(--merah-muda); color: var(--merah); }
    </style>
    @php
        // Penanda Pengalihan UJ per ID UJ.
        $tandaAlih = function ($d) use ($pengalihan) {
            $p = $d->id_uj ? ($pengalihan[$d->id_uj] ?? null) : null;
            if (! $p) {
                return null;
            }
            $sebagian = abs((float) $p->nominal - (float) $d->nominal) >= 1 ? ' (sebagian '.rp((int) $p->nominal).')' : '';
            $teks = match ($p->jenis) {
                'alih' => '↪ Jadi saldo sopir '.($p->sopir ?: $p->nama).$sebagian,
                'kembali' => '↩ Dikembalikan sopir'.$sebagian,
                default => '✕ Ditutup tanpa bongkar',
            };

            return ['kelas' => $p->jenis, 'teks' => $teks, 'judul' => trim(\App\Models\UjPengalihan::JENIS[$p->jenis].' '.$p->tanggal?->translatedFormat('j M Y')
                .($p->dari ? ' · dari '.$p->dari->nama : '').($p->keterangan ? ' · '.$p->keterangan : ''))];
        };
        $namaAlih = ['alih' => '↪ Jadi saldo sopir', 'kembali' => '↩ Dikembalikan', 'batal' => '✕ Batal (tanpa bongkar)'];
    @endphp
    @if (! $bulan)
        <div class="kartu">
            <h3 style="margin-top: 0;">Belum ada data Kas Seabank</h3>
            <p class="redup">Data diambil otomatis dari lembar "Kas Seabank" (sheet <i>KAS MMP Uang Jalan dan UM</i>) setiap 5 menit.</p>
        </div>
    @else
        @include('partials.periode', ['rute' => 'uj.index', 'bawa' => ['q' => $q]])

        @php($detailSemua = $transaksi->flatMap->detail)
        <div class="ringkas">
            <div class="kartu"><span class="redup">Transfer</span><b>{{ $transaksi->count() }}</b></div>
            <div class="kartu"><span class="redup">Total nominal master</span><b>{{ rp($transaksi->sum('nominal')) }}</b></div>
            <div class="kartu"><span class="redup">Biaya transfer</span><b>{{ rp($detailSemua->where('biaya_transfer', true)->sum('nominal')) }}</b></div>
            <div class="kartu"><span class="redup">Belum direimburse</span><b>{{ rp($detailSemua->whereNull('tanggal_reimburse')->sum('nominal')) }}</b></div>
        </div>

        <form method="GET" action="{{ route('uj.index') }}" style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            @foreach ($periode->param() as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            @if ($alih)<input type="hidden" name="alih" value="{{ $alih }}">@endif
            <input type="search" name="q" value="{{ $q }}" placeholder="Cari nama, keterangan, ID UJ, no mobil, DO, kategori…">
            <button class="tombol" type="submit" style="padding: 8px 14px;">Cari</button>
            @if ($q !== '')
                <a href="{{ route('uj.index', $periode->param()) }}" class="redup">Hapus pencarian</a>
            @endif
            <span class="redup">{{ $transaksi->count() }} transfer · {{ $detailSemua->count() }} detail · terbaru di atas</span>
            <button type="button" class="tombol polos" id="buka-semua">Buka semua detail</button>
            <span class="redup" style="margin-left: auto;">@if ($diimpor)Data diperbarui otomatis · terakhir {{ \Illuminate\Support\Carbon::parse($diimpor)->translatedFormat('j M Y H:i') }}@endif</span>
            @if ($bolehInput)<a href="{{ route('uj.input') }}" class="tombol" style="padding: 8px 14px;">+ Input UJ</a>@endif
        </form>

        @if ($jumlahPengalihan)
            <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: -4px 0 12px;">
                <span class="redup" style="font-size: 13px;">Hutang Supir:</span>
                <a href="{{ route('uj.index', array_filter(['q' => $q]) + $periode->param()) }}" @class(['chip', 'aktif' => ! $alih]) style="margin: 0;">Semua transaksi</a>
                @foreach ($namaAlih as $j => $nama)
                    @if ($jumlahPengalihan[$j] ?? 0)
                        <a href="{{ route('uj.index', ['alih' => $j] + array_filter(['q' => $q]) + $periode->param()) }}" @class(['chip', 'aktif' => $alih === $j]) style="margin: 0;">{{ $nama }} · {{ $jumlahPengalihan[$j] }}</a>
                    @endif
                @endforeach
                @if (count($jumlahPengalihan) > 1)<a href="{{ route('uj.index', ['alih' => 'semua'] + array_filter(['q' => $q]) + $periode->param()) }}" @class(['chip', 'aktif' => $alih === 'semua']) style="margin: 0;">Semua pengalihan · {{ array_sum($jumlahPengalihan) }}</a>@endif
                @if ($bolehPengalihan)<a href="{{ route('pengalihan-uj.index') }}" class="redup" style="font-size: 13px; margin-left: auto;">Buka Hutang Supir →</a>@endif
            </div>
            @if ($alih)
                <div class="kartu" style="padding: 10px 14px; margin-bottom: 12px; border-left: 3px solid #4ea1ff;">
                    Menampilkan <b>{{ $detailSemua->count() }} UJ</b> {{ $alih === 'semua' ? 'yang dialihkan / dikembalikan / dibatalkan' : mb_strtolower(\App\Models\UjPengalihan::JENIS[$alih]) }}
                    senilai <b>{{ rp($detailSemua->sum('nominal')) }}</b>@php($nilaiAlih = (int) round($detailSemua->sum(fn ($d) => (float) ($pengalihan[$d->id_uj]->nominal ?? 0))))
                    @if ($nilaiAlih !== (int) $detailSemua->sum('nominal')), yang dialihkan <b>{{ rp($nilaiAlih) }}</b> ({{ $detailSemua->filter(fn ($d) => abs((float) $pengalihan[$d->id_uj]->nominal - (float) $d->nominal) >= 1)->count() }} UJ hanya sebagian)@endif
                    dari <b>semua periode</b> (pilihan bulan di atas diabaikan). Detail lain di transfer yang sama disembunyikan.
                    <a href="{{ route('uj.index', array_filter(['q' => $q]) + $periode->param()) }}">Tampilkan semua transaksi</a>
                </div>
            @endif
        @endif

        <div class="kartu gulir" style="padding: 0;">
            <table class="kas" data-saring-kolom="grup">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Penerima / Nama</th>
                        <th>Keterangan</th>
                        <th>Kategori</th>
                        <th>No Mobil</th>
                        <th>Jenis</th>
                        <th>No DO</th>
                        <th class="angka">Nominal</th>
                        <th>ID UJ · status</th>
                        <th></th>
                    </tr>
                </thead>
                @forelse ($transaksi as $t)
                    @php($detail = $t->detail)
                    @php($jumlah = $detail->reject->biaya_transfer->sum('nominal'))
                    @php($sudah = $t->sudahReimburse())
                    @php($jumlahSudah = $detail->whereNotNull('tanggal_reimburse')->count())
                    @php($nAlih = $detail->filter(fn ($d) => $d->id_uj && isset($pengalihan[$d->id_uj]))->count())
                    <tbody @class(['grup', 'buka' => $q !== '' || $alih])>
                        <tr @class(['t', 'ada-bon' => $detail->isNotEmpty()])>
                            <td>
                                <span class="panah" aria-hidden="true">@if ($detail->isNotEmpty())▸@endif</span>
                                {{ $t->tanggal?->translatedFormat('j M') }}
                                @if ($detail->count() > 1)<span class="jumlah-bon" title="{{ $detail->count() }} baris detail">{{ $detail->count() }}</span>@endif
                            </td>
                            @php($pemilik = $pemilikRek[\App\Models\Rekening::kunci($t->rekening)] ?? null)
                            {{-- Penerima = pemilik rekening (Daftar Rekening); nama di sheet adalah sopir baris pertama. --}}
                            <td title="{{ $pemilik && $pemilik !== $t->nama ? 'Pemilik rekening (Daftar Rekening). Nama di sheet: '.$t->nama : '' }}"><b>{{ $pemilik ?? $t->nama ?? '—' }}</b> <span class="redup">{{ $t->bank }} {{ $t->rekening }}</span></td>
                            <td>{{ $detail->first()?->keterangan }}@if ($t->nominal && $jumlah !== $t->nominal) <i class="x" title="Jumlah detail (tanpa biaya transfer) tidak sama dengan Nominal Master">detail {{ rp($jumlah) }}</i>@endif</td>
                            <td class="ringkas-gl">{{ $detail->reject->biaya_transfer->pluck('kategori')->filter()->unique()->take(3)->implode(', ') }}</td>
                            <td class="ringkas-gl">{{ $detail->pluck('no_mobil')->filter()->unique()->take(3)->implode(', ') }}</td>
                            <td class="ringkas-gl">{{ $detail->pluck('jenis_kendaraan')->filter()->unique()->take(3)->implode(', ') }}</td>
                            <td class="ringkas-gl">{{ ($dos = $detail->pluck('no_do')->filter()->unique())->take(3)->implode(', ') }}@if ($dos->count() > 3) +{{ $dos->count() - 3 }}@endif</td>
                            <td class="angka"><b>{{ rp($t->nominal, true) }}</b></td>
                            <td class="i">@if ($t->no_uj)UJ-{{ $t->no_uj }}@else<i title="Baris master belum diberi ID UJ di sheet">tanpa ID</i>@endif
                                @if ($jumlahSudah === $detail->count() && $sudah)<span class="label hijau">Sudah reimburse</span>
                                @elseif ($sudah)<span class="label kuning" title="{{ $jumlahSudah }} dari {{ $detail->count() }} detail sudah direimburse">Sebagian {{ $jumlahSudah }}/{{ $detail->count() }}</span>@endif
                                @if ($nAlih && ! $alih)<span class="label alih" title="Ada UJ di transfer ini yang dialihkan / dikembalikan / dibatalkan (menu Hutang Supir)">↪ {{ $nAlih }} pengalihan</span>@endif</td>
                            <td style="white-space: nowrap;">
                                {{-- Edit, foto bon & Hapus hanya untuk akun yang punya menu Input UJ; yang sudah (sebagian) direimburse dikunci. --}}
                                @if ($bolehInput && $sudah)
                                    <span class="kunci-reimburse" title="Sudah {{ $jumlahSudah < $detail->count() ? 'sebagian ' : '' }}direimburse — tidak bisa diedit atau dihapus">🔒</span>
                                @endif
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
                            <tr class="bh"><td>Transaksi detail</td><td>Nama</td><td>Keterangan</td><td>Kategori</td><td>No Mobil</td><td>Jenis</td><td>No DO</td><td class="angka">Nominal</td><td>ID UJ · reimburse</td><td></td></tr>
                        @endif
                        @foreach ($detail as $d)
                            <tr @class(['b', 'fee' => $d->biaya_transfer])>
                                <td></td>
                                <td class="p">{{ $d->nama }}</td>
                                <td>{{ $d->keterangan }}</td>
                                <td>{{ $d->kategori }}</td>
                                <td class="mobil">{{ $d->no_mobil }}</td>
                                <td>{{ $d->jenis_kendaraan }}</td>
                                <td>{{ $d->no_do }}</td>
                                <td class="angka">{{ rp($d->nominal) }}</td>
                                @php($ta = $tandaAlih($d))
                                <td class="i" @if ($ta) data-v="{{ $d->id_uj }} {{ $ta['teks'] }}" @endif>{{ $d->id_uj }} @if ($d->tanggal_reimburse)<i title="Tanggal reimburse">✓ {{ $d->tanggal_reimburse->translatedFormat('j M') }}</i>@endif
                                    @if ($ta)<span class="label {{ $ta['kelas'] }}" title="{{ $ta['judul'] }}" style="white-space: nowrap;">{{ $ta['teks'] }}</span>@endif</td>
                                <td></td>
                            </tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody><tr><td colspan="10" class="redup" style="padding: 20px 14px;">Tidak ada transaksi yang cocok.</td></tr></tbody>
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
    <script src="{{ asset('js/saring-kolom.js') }}?v={{ filemtime(public_path('js/saring-kolom.js')) }}"></script>
@endsection
