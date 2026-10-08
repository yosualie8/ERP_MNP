@extends('layouts.app', ['judul' => 'Kas Harian'])

@section('lebar', '1280px')

@section('isi')
    @if ($daftarBulan->isEmpty())
        <div class="kartu">
            <h3 style="margin-top: 0;">Belum ada data kas</h3>
            <p class="redup">Jalankan <code>php artisan mnp:impor-kas --simpan</code> di server untuk mengimpor lembar bulanan dari Kas Harian MNP.</p>
        </div>
    @else
        @include('partials.periode', ['rute' => 'kas.index', 'bawa' => ['q' => $q, 'status' => $filterStatus]])
    @if (! $bulan)
        <div class="kartu"><p class="redup" style="margin: 0;">Belum ada data Kas Harian di periode {{ $periode->label() }}.</p></div>
    @else
        <div class="ringkas">
            <div class="kartu"><span class="redup">Saldo awal{{ $periode->tunggal() ? '' : ' · '.$periode->awal()->translatedFormat('M') }}</span><b>{{ rp($ringkas['saldo_awal']) }}</b></div>
            <div class="kartu"><span class="redup">Uang masuk</span><b>{{ rp($ringkas['masuk']) }}</b></div>
            <div class="kartu"><span class="redup">Transfer keluar</span><b>{{ rp($ringkas['keluar']) }}</b></div>
            <div class="kartu"><span class="redup">Saldo akhir{{ $periode->tunggal() ? '' : ' · '.$bulan->bulan->translatedFormat('M') }}</span><b>{{ rp($ringkas['saldo_akhir']) }}</b></div>
            <div class="kartu">
                <span class="redup">Rekonsiliasi</span>
                <b style="font-size: 16px;">
                    @if ($ringkas['cocok'])
                        <span class="label hijau" style="font-size: 14px;">Cocok 100%</span>
                    @else
                        <span class="label merah" style="font-size: 14px;">{{ count($ringkas['catatan']) ?: $ringkas['tidak_cocok'] }} catatan</span>
                    @endif
                </b>
            </div>
        </div>

        @if ($ringkas['catatan'])
            <div class="pesan galat">
                <b>Catatan rekonsiliasi {{ $periode->label() }}</b>
                <ul style="margin: 6px 0 0; padding-left: 18px;">
                    @foreach ($ringkas['catatan'] as $c)
                        <li>{{ $c }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php($pp = $periode->param())
        @if ($periode->tunggal())
            <div style="margin-bottom: 8px;">
                <span class="redup" style="margin-right: 6px;">Tanggal:</span>
                <a href="{{ route('kas.index', $pp + ['q' => $q ?: null, 'status' => $filterStatus]) }}" @class(['chip', 'aktif' => ! $tanggal])>Sebulan</a>
                @foreach ($daftarTanggal as $tgl)
                    <a href="{{ route('kas.index', $pp + ['tgl' => $tgl->day, 'q' => $q ?: null, 'status' => $filterStatus]) }}" @class(['chip', 'aktif' => $tanggal === $tgl->day])>{{ $tgl->day }}</a>
                @endforeach
            </div>
        @endif
        <div style="margin-bottom: 8px;">
            @php($p = $pp + ['tgl' => $tanggal, 'q' => $q ?: null])
            <span class="redup" style="margin-right: 6px;">Status reimburse:</span>
            <a href="{{ route('kas.index', $p) }}" @class(['chip', 'aktif' => ! $filterStatus])>Semua</a>
            <a href="{{ route('kas.index', $p + ['status' => 'belum']) }}" @class(['chip', 'aktif' => $filterStatus === 'belum'])>Belum reimburse · {{ $ringkasStatus['belum'] }} transfer · {{ rp($ringkasStatus['nilai_belum']) }}</a>
            <a href="{{ route('kas.index', $p + ['status' => 'sudah']) }}" @class(['chip', 'aktif' => $filterStatus === 'sudah'])>Sudah reimburse · {{ $ringkasStatus['sudah'] }}</a>
            @if ($antreanSheet)<span class="redup" title="Status sudah tersimpan di aplikasi; sedang ditulis ke lembar Sudah Reimburse">⏳ {{ $antreanSheet }} status menunggu ditulis ke sheet</span>@endif
            @if (auth()->user()->bolehMenu('kas-belum-reimburse'))
                <a href="{{ route('kas.belum-reimburse') }}" class="tombol polos" style="padding: 4px 12px; font-size: 13px;" title="Semua transaksi Kas Harian yang belum reimburse (semua periode)">⬇ Excel belum reimburse</a>
            @endif
        </div>

        <form method="GET" action="{{ route('kas.index') }}" style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            @foreach ($pp as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            @if ($tanggal)
                <input type="hidden" name="tgl" value="{{ $tanggal }}">
            @endif
            @if ($filterStatus)<input type="hidden" name="status" value="{{ $filterStatus }}">@endif
            <input type="search" name="q" value="{{ $q }}" placeholder="Cari keterangan, PIC, tujuan, Kode GL, ID transaksi…">
            <button class="tombol" type="submit" style="padding: 8px 14px;">Cari</button>
            @if ($q !== '')
                <a href="{{ route('kas.index', $pp + ['tgl' => $tanggal, 'status' => $filterStatus]) }}" class="redup">Hapus pencarian</a>
            @endif
            <span class="redup">{{ $transfer->count() }} transfer · {{ $transfer->sum(fn ($t) => $t->bon->count()) }} detail · terbaru di atas</span>
            <button type="button" class="tombol polos" id="buka-semua">Buka semua detail</button>
            <span class="redup" style="margin-left: auto;">Diimpor {{ $lembar->min('diimpor_pada')->translatedFormat('j M Y H:i') }} dari lembar {{ $lembar->pluck('lembar')->implode(', ') }}</span>
            <button type="submit" form="form-sinkron" class="tombol polos" title="Ambil ulang lembar {{ $lembar->pluck('lembar')->implode(', ') }} dari sheet (setelah sheet diubah langsung)">Sinkron dari sheet</button>
            @if ($bolehInput)<a href="{{ route('kas.input') }}" class="tombol" style="padding: 8px 14px;">+ Input kas</a>@endif
        </form>
        <form method="POST" action="{{ route('kas.sinkron') }}" id="form-sinkron">
            @csrf
            <input type="hidden" name="lembar" value="{{ $lembar->pluck('lembar')->implode(',') }}">
        </form>

        <div class="kartu gulir" style="padding: 0;">
            <table class="kas">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Tujuan / PIC</th>
                        <th>Keterangan</th>
                        <th>Kode GL</th>
                        <th class="angka">Masuk</th>
                        <th class="angka">Keluar / Detail</th>
                        <th class="angka">Saldo</th>
                        <th>Reimburse</th>
                        <th></th>
                    </tr>
                </thead>
                @forelse ($transfer as $t)
                    @php($jumlahBon = $t->bon->sum('nominal'))
                    @php($akunBon = $t->bon->map(fn ($b) => $b->kodeGl ? ($b->kodeGl->akun?->nama ?? $b->kodeGl->kode_asli).($b->kodeGl->costCenter ? ' '.$b->kodeGl->costCenter->kode : '') : 'tanpa Kode GL')->unique()->values())
                    <tbody @class(['grup', 'buka' => $q !== ''])>
                        <tr @class(['t', 'm' => $t->debet, 'ada-bon' => $t->bon->isNotEmpty()])>
                            <td>
                                <span class="panah" aria-hidden="true">@if ($t->bon->isNotEmpty())▸@endif</span>
                                {{ $t->tanggal->translatedFormat('j M') }}
                                @if ($t->bon->count() > 1)<span class="jumlah-bon" title="{{ $t->bon->count() }} transaksi detail">{{ $t->bon->count() }}</span>@endif
                            </td>
                            <td><b>{{ $t->nama_tujuan ?? '—' }}</b> <span class="redup">{{ $t->bank_tujuan }} {{ $t->no_rek_tujuan }}</span></td>
                            <td>{{ $t->keterangan }}@if ($t->kredit && $jumlahBon !== $t->kredit) <i class="x">detail {{ rp($jumlahBon) }}</i>@endif</td>
                            <td class="ringkas-gl">{{ $akunBon->take(2)->implode(', ') }}@if ($akunBon->count() > 2) +{{ $akunBon->count() - 2 }}@endif</td>
                            <td class="angka">{{ rp($t->debet, true) }}</td>
                            <td class="angka"><b>{{ rp($t->kredit, true) }}</b></td>
                            <td class="angka">{{ rp($t->saldo) }}</td>
                            @php($s = $statusTransfer[$t->id])
                            <td style="white-space: nowrap;">
                                @if ($s['kode'] === 'sudah')
                                    <span class="label hijau" title="Semua detail sudah direimburse">✓ Sudah{{ $s['tanggal'] ? ' · '.$s['tanggal']->translatedFormat('j M') : '' }}</span>
                                @elseif ($s['kode'] === 'sebagian')
                                    <span class="label kuning" title="{{ $s['sudah'] }} dari {{ $s['jumlah'] }} detail sudah direimburse; belum {{ rp($s['nilai_belum']) }}">Sebagian {{ $s['sudah'] }}/{{ $s['jumlah'] }}</span>
                                @elseif ($s['kode'] === 'belum')
                                    <span class="label merah">Belum</span>
                                @elseif ($s['kode'] === 'masuk')
                                    <span class="redup" style="font-size: 12px;">Saldo masuk</span>
                                @endif
                            </td>
                            <td style="white-space: nowrap;">
                                {{-- Edit, foto bon & Hapus hanya untuk akun yang punya menu Input Kas. --}}
                                {{-- Transaksi yang sudah (sebagian) direimburse dikunci: tidak bisa diedit/dihapus. --}}
                                @php($terkunci = in_array($s['kode'], ['sudah', 'sebagian'], true))
                                @if ($bolehInput)
                                    @if ($terkunci)
                                        <span class="kunci-reimburse" title="Sudah {{ $s['kode'] === 'sebagian' ? 'sebagian ' : '' }}direimburse — tidak bisa diedit atau dihapus">🔒</span>
                                    @elseif ($t->no_id)
                                        <a href="{{ route('kas.edit', $t->no_id) }}" class="tombol-edit" title="Edit transaksi ini (ditulis ulang di sheet)">Edit</a>
                                    @endif
                                    @if ($t->no_id)
                                        @if ($n = $jumlahFoto[$t->no_id] ?? 0)
                                            <a href="{{ route('kas.bon', $t->no_id) }}" class="lampiran ada" title="Lihat {{ $n }} foto bon">📎 {{ $n }}</a>
                                        @else
                                            <a href="{{ route('kas.bon', $t->no_id) }}" class="lampiran kosong" title="Lampirkan foto bon">📎+</a>
                                        @endif
                                    @endif
                                    @unless ($terkunci)
                                    <button type="button" class="tombol-hapus" data-hapus="{{ route('kas.hapus', $t) }}" data-lembar="{{ $namaLembar[$t->kas_bulan_id] }}" data-baris="{{ $t->baris }}"
                                        data-ringkasan="{{ ($t->debet ? 'Uang masuk ' : 'Transfer ').rp($t->debet ?: $t->kredit).' · '.$t->tanggal->translatedFormat('j M').' · '.($t->nama_tujuan ?? '').' · '.($t->keterangan ?? '') }}"
                                        data-bon="{{ $t->bon->count() }}" @if (isset($punyaBiaya[$t->id])) data-biaya="1" @endif>Hapus</button>
                                    @endunless
                                @endif</td>
                        </tr>
                        @if ($t->bon->isNotEmpty())
                            <tr class="bh"><td>Transaksi detail</td><td>PIC</td><td>Keterangan</td><td>Kode GL</td><td></td><td class="angka">Nominal</td><td>ID transaksi</td><td>Reimburse</td><td></td></tr>
                        @endif
                        @foreach ($t->bon as $b)
                            @php($k = $b->kodeGl)
                            <tr class="b"><td></td><td class="p">{{ $b->pic }}</td><td>{{ $b->keterangan }}</td><td>@if ($k){{ $k->akun?->nama ?? $k->kode_asli }}@if ($k->costCenter) <i>{{ $k->costCenter->kode }}</i>@endif @if ($k->ref)<i title="Tahap proyek">{{ $k->ref }}</i>@endif @if ($b->kode_gl_ditebak)<i title="Kode GL kosong di sheet, ditebak dari keterangan">ditebak</i>@endif @else<i class="x">tanpa Kode GL</i>@endif</td><td></td><td class="angka">{{ rp($b->nominal) }}</td><td class="i">{{ $b->id_transaksi }}</td><td style="white-space: nowrap;">@if ($statusBon[$b->id]['sudah'])<span class="label hijau">✓ Sudah{{ $statusBon[$b->id]['tanggal'] ? ' · '.$statusBon[$b->id]['tanggal']->translatedFormat('j M') : '' }}</span>@else<span class="label merah">Belum</span>@endif</td><td></td></tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody><tr><td colspan="9" class="redup" style="padding: 20px 14px;">Tidak ada transfer yang cocok.</td></tr></tbody>
                @endforelse
            </table>
        </div>

        <form method="POST" id="form-hapus" hidden>
            @csrf
            @method('DELETE')
            <input type="hidden" name="dengan_biaya" value="0">
            <input type="hidden" name="tgl" value="{{ $tanggal }}">
            <input type="hidden" name="q" value="{{ $q }}">
        </form>
        <script>
            // Bon tertutup di awal; klik baris transfer untuk membuka/menutup.
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
                const bon = +d.bon ? ` beserta ${d.bon} transaksi detail di bawahnya` : '';
                if (!confirm(`Hapus dari sheet (lembar ${d.lembar}, baris ${d.baris})${bon}?\n\n${d.ringkasan}\n\nBaris di sheet akan dihapus. Isinya tetap tersimpan di riwayat aplikasi.`)) return;
                const form = document.getElementById('form-hapus');
                form.dengan_biaya.value = d.biaya && confirm('Tepat di bawahnya ada baris "Biaya Transfer Keluar" untuk transfer ini.\n\nHapus juga? (OK = hapus juga, Batal = biarkan)') ? '1' : '0';
                form.action = d.hapus;
                tombol.disabled = true;
                tombol.textContent = 'Menghapus…';
                form.submit();
            }));
        </script>
    @endif
    @endif
@endsection
