@extends('layouts.app', ['judul' => 'Ritasi'])

@section('lebar', '1440px')

@section('isi')
    @if (! $bulan)
        <div class="kartu">
            <h3 style="margin-top: 0;">Belum ada data ritasi</h3>
            <p class="redup">Data diambil otomatis dari lembar "Ritasi" (sheet <i>Proyek ASG - Gsheet</i>) setiap 5 menit.</p>
        </div>
    @else
        <div style="margin-bottom: 10px;">
            @foreach ($daftarBulan as $b)
                <a href="{{ route('ritasi.index', ['bulan' => $b]) }}" @class(['chip', 'aktif' => $b === $bulan])>{{ \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M Y') }}</a>
            @endforeach
        </div>

        <div class="ringkas">
            <div class="kartu"><span class="redup">Jumlah rit</span><b>{{ number_format($rit->count(), 0, ',', '.') }}</b></div>
            <div class="kartu"><span class="redup">Total harga jual</span><b>{{ rp($rit->sum('harga_jual')) }}</b></div>
            <div class="kartu"><span class="redup">Proyek / tahap</span><b>{{ $rit->pluck('tahap')->filter()->unique()->count() }}</b></div>
            <div class="kartu"><span class="redup">Truk</span><b>{{ $rit->pluck('no_lambung')->filter()->unique()->count() ?: $rit->pluck('plat')->filter()->unique()->count() }}</b></div>
        </div>

        <form method="GET" action="{{ route('ritasi.index') }}" style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="bulan" value="{{ $bulan }}">
            <input type="search" name="q" value="{{ $q }}" placeholder="Cari tahap, No Seri, DT, plat/driver, galian, DO…">
            <button class="tombol" type="submit" style="padding: 8px 14px;">Cari</button>
            @if ($q !== '')<a href="{{ route('ritasi.index', ['bulan' => $bulan]) }}" class="redup">Hapus pencarian</a>@endif
            <span class="redup">{{ $rit->count() }} rit · terbaru di atas</span>
            <span class="redup" style="margin-left: auto;">@if ($diimpor)Data diperbarui otomatis · terakhir {{ \Illuminate\Support\Carbon::parse($diimpor)->translatedFormat('j M Y H:i') }}@endif</span>
            @if ($bolehInput)<a href="{{ route('ritasi.input') }}" class="tombol" style="padding: 8px 14px;">+ Input ritasi</a>@endif
        </form>

        <div class="kartu gulir" style="padding: 0;">
            <table class="kas" data-saring-kolom>
                <thead>
                    <tr><th title="Tanggal & jam bongkar menurut surat jalan">Tanggal bongkar</th><th>Tahap</th><th>No Seri</th><th>DT · Plat / Driver</th><th>Galian</th><th>Tanah · Buangan</th><th>Kendaraan · Pemilik</th><th>No DO</th><th class="angka">Kubikasi</th><th class="angka">Harga jual</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($rit as $r)
                        <tr class="t">
                            {{-- data-v: saring kolom Tanggal hanya membaca tanggalnya (jam 16:21 tidak ikut cocok dengan "21") --}}
                            <td data-v="{{ $r->tanggal?->translatedFormat('j M Y') }}">{{ $r->tanggal?->translatedFormat('j M') }} <span class="redup">{{ $r->jam }}</span></td>
                            <td>{{ $r->tahap }}</td>
                            <td class="i">{{ $r->no_seri }}</td>
                            <td>@if ($r->no_lambung)<b>{{ $r->no_lambung }}</b> @endif<span class="redup">{{ $r->plat }}</span>@if ($r->driver) · {{ $r->driver }}@endif</td>
                            <td>{{ $r->galian }}</td>
                            <td>{{ $r->jenis_tanah }}@if ($r->jenis_buangan && $r->jenis_buangan !== 'Ritasi') <i>{{ $r->jenis_buangan }}</i>@endif</td>
                            <td>{{ $r->jenis_kendaraan }} <span class="redup">{{ $r->pemilik }}</span></td>
                            <td class="i">{{ $r->no_do }}</td>
                            <td class="angka">{{ $r->kubikasi !== null ? rtrim(rtrim(number_format((float) $r->kubikasi, 2, ",", "."), "0"), ",")." m³" : "–" }}</td>
                            <td class="angka">{{ $r->harga_jual ? rp($r->harga_jual) : '–' }}</td>
                            <td style="white-space: nowrap;">
                                @if ($bolehInput)
                                    <a href="{{ route('ritasi.edit', $r->baris) }}" class="tombol-edit">Edit</a>
                                    <button type="button" class="tombol-hapus" data-hapus="{{ route('ritasi.hapus', $r->baris) }}"
                                        data-ringkasan="Baris {{ $r->baris }} · No Seri {{ $r->no_seri }} · {{ $r->tanggal?->translatedFormat('j M Y') }} · {{ $r->no_lambung ?? $r->plat }} · DO {{ $r->no_do }}">Hapus</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="redup" style="padding: 20px 14px;">Tidak ada rit yang cocok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <form method="POST" id="form-hapus" hidden>@csrf @method('DELETE')<input type="hidden" name="q" value="{{ $q }}"></form>
        <script>
            document.querySelectorAll('[data-hapus]').forEach(t => t.addEventListener('click', () => {
                if (!confirm(`Hapus rit ini dari sheet Ritasi?\n\n${t.dataset.ringkasan}\n\nBarisnya di sheet akan dihapus. Isinya tetap tersimpan di riwayat aplikasi.`)) return;
                const f = document.getElementById('form-hapus');
                f.action = t.dataset.hapus;
                t.disabled = true; t.textContent = 'Menghapus…';
                f.submit();
            }));
        </script>
    @endif
    <script src="{{ asset('js/saring-kolom.js') }}?v={{ filemtime(public_path('js/saring-kolom.js')) }}"></script>
@endsection
