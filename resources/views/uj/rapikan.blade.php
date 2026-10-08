@extends('layouts.app', ['judul' => 'Rapikan Kas UJ'])

@section('lebar', '1440px')

@section('isi')
    <style>
        table.kas input.pilih-dt { width: 120px; padding: 6px 8px; border-radius: 6px; font-size: 13px; }
        table.kas input.pilih-dt.terisi { border-color: var(--sukses); background: #143523; }
        .saran-dt { border: 1px dashed var(--otomatis-garis); background: var(--otomatis-latar); color: var(--teks); border-radius: 999px; padding: 2px 9px; font-size: 12px; cursor: pointer; }
        .saran-dt:hover { border-style: solid; }
    </style>

    <div class="kartu">
        <h3 style="margin: 0 0 6px;">No Mobil kosong di Kas UJ — {{ number_format($semua->count(), 0, ',', '.') }} baris</h3>
        <p class="redup" style="margin: 0 0 10px;">Pilih nomor truk dari Data Aset untuk setiap baris (klik saran ungu untuk memakainya), atau pilih <b>BUKAN TRUK</b> bila biayanya memang tidak untuk truk tertentu. Klik <b>Simpan</b> — No Mobil & Jenis Kendaraan ditulis ke sheet Kas Seabank dan aplikasi.</p>
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            <a href="{{ route('uj.rapikan') }}" @class(['chip', 'aktif' => ! $kategori])>Semua · {{ $semua->count() }}</a>
            @foreach ($semua->countBy('kategori')->sortDesc() as $k => $n)
                <a href="{{ route('uj.rapikan', ['kategori' => $k]) }}" @class(['chip', 'aktif' => $kategori === $k])>{{ $k }} · {{ $n }}</a>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('uj.rapikan.simpan') }}" id="form-rapikan">
        @csrf
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;">
            <button type="button" class="tombol polos" id="pakai-saran">Pakai semua saran di halaman ini ({{ count($saran) }})</button>
            <span class="redup" id="jumlah-isi">0 baris diisi</span>
            <button type="submit" class="tombol" style="margin-left: auto;">Simpan ke sheet & aplikasi</button>
        </div>
        <div class="kartu gulir" style="padding: 0;">
            <table class="kas">
                <thead><tr><th>Tanggal</th><th>ID UJ</th><th>Nama</th><th>Keterangan</th><th>Kategori</th><th>No DO</th><th class="angka">Nominal</th><th>No Mobil</th><th>Saran</th></tr></thead>
                <tbody>
                    @forelse ($baris as $d)
                        <tr>
                            <td style="white-space: nowrap;">{{ $d->tanggal?->translatedFormat('j M Y') }}</td>
                            <td class="i">{{ $d->id_uj }}</td>
                            <td>{{ $d->nama }}</td>
                            <td>{{ $d->keterangan }}</td>
                            <td>{{ $d->kategori }}</td>
                            <td>{{ $d->no_do }}</td>
                            <td class="angka">{{ rp($d->nominal) }}</td>
                            <td><input type="text" class="pilih-dt" name="mobil[{{ $d->baris }}]" list="daftar-aset" autocomplete="off" placeholder="DT …" value="{{ old('mobil.'.$d->baris) }}"></td>
                            <td>@if ($s = $saran[$d->baris] ?? null)<button type="button" class="saran-dt" data-dt="{{ $s['dt'] }}" title="Saran dari {{ $s['asal'] }}">{{ $s['dt'] }}</button> <span class="redup" style="font-size: 11px;">{{ $s['asal'] }}</span>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="redup" style="padding: 20px 14px;">Tidak ada baris dengan No Mobil kosong. 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($halaman > 1)
            <div style="display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap;">
                @for ($i = 1; $i <= $halaman; $i++)
                    <a href="{{ route('uj.rapikan', ['kategori' => $kategori, 'hal' => $i]) }}" @class(['chip', 'aktif' => $hal === $i])>{{ $i }}</a>
                @endfor
                <span class="redup">{{ $jumlah }} baris · 200 per halaman — simpan dulu sebelum pindah halaman.</span>
            </div>
        @endif
    </form>
    <datalist id="daftar-aset">
        @foreach ($aset as $a)<option value="{{ $a->no_lambung }}">{{ trim($a->plat.' · '.$a->jenis.' · '.$a->driver_tetap, ' ·') }}</option>@endforeach
        <option value="BUKAN TRUK">Biaya tidak untuk truk tertentu</option>
    </datalist>

    <script>
        (() => {
            const isian = [...document.querySelectorAll('input.pilih-dt')];
            const hitung = () => {
                isian.forEach(i => i.classList.toggle('terisi', !!i.value.trim()));
                document.getElementById('jumlah-isi').textContent = isian.filter(i => i.value.trim()).length + ' baris diisi';
            };
            document.querySelectorAll('.saran-dt').forEach(b => b.addEventListener('click', () => {
                b.closest('tr').querySelector('input.pilih-dt').value = b.dataset.dt; hitung();
            }));
            document.getElementById('pakai-saran').addEventListener('click', () => {
                document.querySelectorAll('.saran-dt').forEach(b => { const i = b.closest('tr').querySelector('input.pilih-dt'); if (!i.value) i.value = b.dataset.dt; });
                hitung();
            });
            isian.forEach(i => i.addEventListener('input', hitung));
            // Excel-like: Enter/↓ ke baris bawah, ↑ ke atas.
            isian.forEach((i, k) => i.addEventListener('keydown', e => {
                const t = e.key === 'ArrowDown' || e.key === 'Enter' ? isian[k + 1] : e.key === 'ArrowUp' ? isian[k - 1] : null;
                if (t) { e.preventDefault(); t.focus(); t.select(); }
            }));
            document.getElementById('form-rapikan').addEventListener('submit', e => {
                const n = isian.filter(i => i.value.trim()).length;
                if (!n) { e.preventDefault(); alert('Belum ada baris yang diisi.'); return; }
                if (!confirm(`Simpan No Mobil untuk ${n} baris ke sheet Kas Seabank & aplikasi?`)) e.preventDefault();
            });
            hitung();
        })();
    </script>
@endsection
