@extends('layouts.app', ['judul' => 'Kas Harian'])

@section('lebar', '1280px')

@section('isi')
    @if (! $bulan)
        <div class="kartu">
            <h3 style="margin-top: 0;">Belum ada data kas</h3>
            <p class="redup">Jalankan <code>php artisan mnp:impor-kas --simpan</code> di server untuk mengimpor lembar bulanan dari Kas Harian MNP.</p>
        </div>
    @else
        <div style="margin-bottom: 10px;">
            @foreach ($daftarBulan as $b)
                <a href="{{ route('kas.index', ['lembar' => $b->lembar]) }}" @class(['chip', 'aktif' => $b->is($bulan)])>
                    {{ $b->bulan->translatedFormat('M Y') }}@unless ($b->cocok()) <span title="Ada catatan rekonsiliasi">•</span>@endunless
                </a>
            @endforeach
        </div>

        <div class="ringkas">
            <div class="kartu"><span class="redup">Saldo awal</span><b>{{ rp($bulan->saldo_awal) }}</b></div>
            <div class="kartu"><span class="redup">Uang masuk</span><b>{{ rp($bulan->total_debet) }}</b></div>
            <div class="kartu"><span class="redup">Transfer keluar</span><b>{{ rp($bulan->total_kredit) }}</b></div>
            <div class="kartu"><span class="redup">Saldo akhir</span><b>{{ rp($bulan->saldo_akhir) }}</b></div>
            <div class="kartu">
                <span class="redup">Rekonsiliasi</span>
                <b style="font-size: 16px;">
                    @if ($bulan->cocok())
                        <span class="label hijau" style="font-size: 14px;">Cocok 100%</span>
                    @else
                        <span class="label merah" style="font-size: 14px;">{{ count($bulan->catatan ?? []) ?: 1 }} catatan</span>
                    @endif
                </b>
            </div>
        </div>

        @if ($bulan->catatan)
            <div class="pesan galat">
                <b>Catatan rekonsiliasi {{ $bulan->bulan->translatedFormat('F Y') }}</b>
                <ul style="margin: 6px 0 0; padding-left: 18px;">
                    @foreach ($bulan->catatan as $c)
                        <li>{{ $c }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div style="margin-bottom: 8px;">
            <span class="redup" style="margin-right: 6px;">Tanggal:</span>
            <a href="{{ route('kas.index', ['lembar' => $bulan->lembar, 'q' => $q ?: null]) }}" @class(['chip', 'aktif' => ! $tanggal])>Sebulan</a>
            @foreach ($daftarTanggal as $tgl)
                <a href="{{ route('kas.index', ['lembar' => $bulan->lembar, 'tgl' => $tgl->day, 'q' => $q ?: null]) }}" @class(['chip', 'aktif' => $tanggal === $tgl->day])>{{ $tgl->day }}</a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('kas.index') }}" style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="lembar" value="{{ $bulan->lembar }}">
            @if ($tanggal)
                <input type="hidden" name="tgl" value="{{ $tanggal }}">
            @endif
            <input type="search" name="q" value="{{ $q }}" placeholder="Cari keterangan, PIC, tujuan, Kode GL, ID transaksi…">
            <button class="tombol" type="submit" style="padding: 8px 14px;">Cari</button>
            @if ($q !== '')
                <a href="{{ route('kas.index', ['lembar' => $bulan->lembar, 'tgl' => $tanggal]) }}" class="redup">Hapus pencarian</a>
            @endif
            <span class="redup">{{ $transfer->count() }} transfer · {{ $transfer->sum(fn ($t) => $t->bon->count()) }} bon · terbaru di atas</span>
            <button type="button" class="tombol polos" id="buka-semua">Buka semua bon</button>
            <span class="redup" style="margin-left: auto;">Diimpor {{ $bulan->diimpor_pada->translatedFormat('j M Y H:i') }} dari lembar {{ $bulan->lembar }}</span>
            <button type="submit" form="form-sinkron" class="tombol polos" title="Ambil ulang lembar ini dari sheet (setelah sheet diubah langsung)">Sinkron dari sheet</button>
            <a href="{{ route('kas.input') }}" class="tombol" style="padding: 8px 14px;">+ Input kas</a>
        </form>
        <form method="POST" action="{{ route('kas.sinkron') }}" id="form-sinkron">
            @csrf
            <input type="hidden" name="lembar" value="{{ $bulan->lembar }}">
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
                        <th class="angka">Keluar / Bon</th>
                        <th class="angka">Saldo</th>
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
                                @if ($t->bon->count() > 1)<span class="jumlah-bon" title="{{ $t->bon->count() }} bon">{{ $t->bon->count() }}</span>@endif
                            </td>
                            <td><b>{{ $t->nama_tujuan ?? '—' }}</b> <span class="redup">{{ $t->bank_tujuan }} {{ $t->no_rek_tujuan }}</span></td>
                            <td>{{ $t->keterangan }}@if ($t->kredit && $jumlahBon !== $t->kredit) <i class="x">bon {{ rp($jumlahBon) }}</i>@endif</td>
                            <td class="ringkas-gl">{{ $akunBon->take(2)->implode(', ') }}@if ($akunBon->count() > 2) +{{ $akunBon->count() - 2 }}@endif</td>
                            <td class="angka">{{ rp($t->debet, true) }}</td>
                            <td class="angka"><b>{{ rp($t->kredit, true) }}</b></td>
                            <td class="angka">{{ rp($t->saldo) }}</td>
                            <td><button type="button" class="tombol-hapus" data-hapus="{{ route('kas.hapus', $t) }}" data-baris="{{ $t->baris }}"
                                    data-ringkasan="{{ ($t->debet ? 'Uang masuk ' : 'Transfer ').rp($t->debet ?: $t->kredit).' · '.$t->tanggal->translatedFormat('j M').' · '.($t->nama_tujuan ?? '').' · '.($t->keterangan ?? '') }}"
                                    data-bon="{{ $t->bon->count() }}" @if (isset($punyaBiaya[$t->id])) data-biaya="1" @endif>Hapus</button></td>
                        </tr>
                        @foreach ($t->bon as $b)
                            @php($k = $b->kodeGl)
                            <tr class="b"><td></td><td class="p">{{ $b->pic }}</td><td>{{ $b->keterangan }}</td><td>@if ($k){{ $k->akun?->nama ?? $k->kode_asli }}@if ($k->costCenter) <i>{{ $k->costCenter->kode }}</i>@endif @if ($k->ref)<i title="Tahap proyek">{{ $k->ref }}</i>@endif @if ($b->kode_gl_ditebak)<i title="Kode GL kosong di sheet, ditebak dari keterangan">ditebak</i>@endif @else<i class="x">tanpa Kode GL</i>@endif</td><td></td><td class="angka">{{ rp($b->nominal) }}</td><td class="i">{{ $b->id_transaksi }}</td><td></td></tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody><tr><td colspan="8" class="redup" style="padding: 20px 14px;">Tidak ada transfer yang cocok.</td></tr></tbody>
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
            const aturTombolSemua = () => tombolSemua.textContent = grup().every(g => g.classList.contains('buka')) ? 'Tutup semua bon' : 'Buka semua bon';
            tombolSemua.addEventListener('click', () => {
                const buka = !grup().every(g => g.classList.contains('buka'));
                grup().forEach(g => g.classList.toggle('buka', buka));
                aturTombolSemua();
            });
            aturTombolSemua();

            document.querySelectorAll('[data-hapus]').forEach(tombol => tombol.addEventListener('click', () => {
                const d = tombol.dataset;
                const bon = +d.bon ? ` beserta ${d.bon} bon di bawahnya` : '';
                if (!confirm(`Hapus dari sheet (lembar {{ $bulan->lembar }}, baris ${d.baris})${bon}?\n\n${d.ringkasan}\n\nBaris di sheet akan dihapus. Isinya tetap tersimpan di riwayat aplikasi.`)) return;
                const form = document.getElementById('form-hapus');
                form.dengan_biaya.value = d.biaya && confirm('Tepat di bawahnya ada baris "Biaya Transfer Keluar" 2.500 untuk transfer ini.\n\nHapus juga? (OK = hapus juga, Batal = biarkan)') ? '1' : '0';
                form.action = d.hapus;
                tombol.disabled = true;
                tombol.textContent = 'Menghapus…';
                form.submit();
            }));
        </script>
    @endif
@endsection
