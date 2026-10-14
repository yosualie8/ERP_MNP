@extends('layouts.app', ['judul' => 'Data BBM'])

@section('lebar', '1600px')

@section('isi')
    @php
        $l = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
        $jenisLabel = ['pakai' => '', 'masuk' => 'Masuk', 'pindah' => 'Pindah', 'susut' => 'Susut', 'opname' => 'Opname', 'operasional' => 'Operasional', 'cek' => 'Belum dikenal'];
        $jenisWarna = ['masuk' => 'hijau', 'pindah' => 'kuning', 'susut' => 'merah', 'opname' => 'kuning', 'operasional' => '', 'cek' => 'merah'];
    @endphp
    <style>
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 20px; margin-top: 2px; }
        .stok-minus { color: var(--merah); }
        tr.minus td.stok { color: var(--merah); }
        .opname { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .opname input { padding: 7px 9px; border-radius: 7px; }
    </style>

    <div style="margin-bottom: 10px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
        @foreach ($daftarBulan->slice(-18) as $b)
            <a href="{{ route('bbm.index', ['bulan' => $b]) }}" @class(['chip', 'aktif' => $b === $bulan])>{{ \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M Y') }}</a>
        @endforeach
        @if ($daftarBulan->count() > 18)
            <select onchange="location = this.value" style="padding: 4px 8px; border-radius: 6px;">
                <option value="">Bulan lain…</option>
                @foreach ($daftarBulan->slice(0, -18)->reverse() as $b)<option value="{{ route('bbm.index', ['bulan' => $b]) }}">{{ \Illuminate\Support\Carbon::parse($b.'-01')->translatedFormat('M Y') }}</option>@endforeach
            </select>
        @endif
        <a href="{{ route('bbm.index', ['perlu' => 1]) }}" @class(['chip', 'aktif' => $perlu]) style="{{ $jumlahPerlu ? 'border-color: var(--aksen);' : '' }}">⚠️ Perlu dilengkapi ({{ number_format($jumlahPerlu, 0, ',', '.') }})</a>
    </div>

    @php($pakai = $baris->where('jenis', 'pakai'))
    <div class="ringkas">
        <div class="kartu"><span class="redup">Stok solar sekarang</span><b @class(['stok-minus' => ($stokSekarang ?? 0) < 0])>{{ $l($stokSekarang ?? 0) }} L</b>
            <span class="redup" style="font-size: 12px;">per {{ $stokPada?->translatedFormat('j M Y') }}</span></div>
        <div class="kartu"><span class="redup">Solar keluar {{ $perlu ? '' : 'bulan ini' }}</span><b>{{ $l($baris->sum('keluar')) }} L</b>
            <span class="redup" style="font-size: 12px;">pemakaian alat {{ $l($pakai->sum('keluar')) }} L</span></div>
        <div class="kartu"><span class="redup">Solar masuk</span><b>{{ $l($baris->whereIn('jenis', ['masuk'])->sum('masuk')) }} L</b>
            @if ($op = $baris->where('jenis', 'opname')->sum(fn ($b) => ($b->masuk ?? 0) - ($b->keluar ?? 0)))<span class="redup" style="font-size: 12px;">selisih opname {{ $op > 0 ? '+' : '' }}{{ $l($op) }} L</span>@endif</div>
        <div class="kartu"><span class="redup">Jam HM alat</span><b>{{ $l($pakai->filter(fn ($b) => $b->total_hm !== null && $b->total_hm >= 0 && $b->total_hm <= 24)->sum('total_hm')) }}</b>
            <span class="redup" style="font-size: 12px;">{{ $pakai->pluck('unit')->unique()->count() }} unit</span></div>
    </div>

    <div style="margin-bottom: 12px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        @if ($perlu)
            <span class="redup">Pemakaian solar yang proyeknya belum diisi / tidak dikenali, dan unit yang belum dikenal. Klik Edit untuk melengkapi.</span>
        @else
            <span class="redup">{{ $baris->count() }} baris · terbaru di atas</span>
        @endif
        <span class="redup" style="margin-left: auto;">@if ($diimpor)Data diperbarui otomatis dari sheet · terakhir {{ \Illuminate\Support\Carbon::parse($diimpor)->translatedFormat('j M Y H:i') }}@endif</span>
        @if ($bolehInput)<a href="{{ route('bbm.input') }}" class="tombol" style="padding: 8px 14px;">+ Input BBM</a>@endif
    </div>

    @if ($bolehInput)
        <details class="kartu" style="padding: 10px 14px;" @if ($errors->has('opname')) open @endif>
            <summary style="cursor: pointer;"><b>📦 Stock opname</b> <span class="redup">— catat stok fisik tangki; selisihnya terhadap stok catatan ({{ $l($stokSekarang ?? 0) }} L) masuk sebagai baris "Stock Opname"</span></summary>
            @error('opname')<div class="pesan galat" style="margin: 8px 0;">{{ $message }}</div>@enderror
            <form method="POST" action="{{ route('bbm.opname') }}" class="opname" style="margin-top: 10px;" onsubmit="return confirm('Catat stock opname? Selisih terhadap stok catatan akan ditulis ke sheet Penggunaan BBM.')">
                @csrf
                <label>Tanggal <input type="text" name="tanggal" data-tanggal-id data-maks="{{ now()->toDateString() }}" value="{{ old('tanggal', now()->toDateString()) }}" style="width: 130px;"></label>
                <label>Stok fisik (liter) <input type="text" name="stok_fisik" inputmode="decimal" value="{{ old('stok_fisik') }}" style="width: 110px; text-align: right;" required></label>
                <input type="text" name="catatan" placeholder="catatan (opsional)" maxlength="300" value="{{ old('catatan') }}" style="width: 240px;">
                <button class="tombol" type="submit" style="padding: 7px 14px;">Catat opname</button>
            </form>
        </details>
    @endif

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas" data-saring-kolom>
            <thead>
                <tr><th>Tanggal</th><th>Unit</th><th>Jenis</th><th class="angka">HM awal</th><th class="angka">HM akhir</th><th class="angka">Total HM</th>
                    <th class="angka">Masuk</th><th class="angka">Keluar</th><th class="angka">Stok</th><th>Jam kerja</th><th>Status</th><th>Keterangan</th>
                    <th class="angka">Rental</th><th>Proyek</th><th>Keterangan kerja</th><th data-tanpa-saring></th></tr>
            </thead>
            <tbody>
                @forelse ($baris as $b)
                    <tr @class(['t', 'minus' => $b->stok !== null && $b->stok < 0])>
                        <td data-n="{{ $b->tanggal?->toDateString() }}" data-v="{{ $b->tanggal?->toDateString() }} {{ $b->tanggal?->translatedFormat('j M Y') }}" style="white-space: nowrap;">{{ $b->tanggal?->translatedFormat($perlu ? 'j M Y' : 'j M') }}</td>
                        <td style="white-space: nowrap;"><b>{{ $b->unit }}</b></td>
                        <td>@if ($jenisLabel[$b->jenis] ?? '')<span class="label {{ $jenisWarna[$b->jenis] ?? '' }}">{{ $jenisLabel[$b->jenis] }}</span>@else<span class="redup">Pakai</span>@endif</td>
                        <td class="angka" data-n="{{ $b->hm_awal }}">{{ $l($b->hm_awal) }}</td>
                        <td class="angka" data-n="{{ $b->hm_akhir }}">{{ $l($b->hm_akhir) }}</td>
                        <td class="angka" data-n="{{ $b->total_hm }}">{{ $l($b->total_hm) }}</td>
                        <td class="angka" data-n="{{ $b->masuk }}">{{ $l($b->masuk) }}</td>
                        <td class="angka" data-n="{{ $b->keluar }}">{{ $l($b->keluar) }}</td>
                        <td class="angka stok" data-n="{{ $b->stok }}">{{ $l($b->stok) }}</td>
                        <td style="white-space: nowrap;">{{ $b->jam_mulai }}@if ($b->jam_selesai)–{{ $b->jam_selesai }}@endif @if ($b->istirahat)<span class="redup">(rest {{ $b->istirahat }})</span>@endif</td>
                        <td>{{ $b->status }}</td>
                        <td>{{ $b->keterangan }}</td>
                        <td class="angka" data-n="{{ $b->rental }}">{{ $l($b->rental) }}</td>
                        <td @if ($b->jenis === 'pakai' && $b->keluar > 0 && ! $b->tahap_ids && $b->proyek !== 'Pool') style="color: var(--merah);" @endif>
                            @if ($b->proyek && str_contains($b->proyek, ' + '))
                                <span title="{{ $b->proyek }}">Bagi rata {{ count(explode(' + ', $b->proyek)) }} tahap</span>
                            @else{{ $b->proyek ?? ($b->jenis === 'pakai' && $b->keluar > 0 ? 'belum diisi' : '') }}@endif
                        </td>
                        <td>{{ $b->keterangan_kerja }}</td>
                        <td style="white-space: nowrap;">
                            @if ($bolehInput)
                                @if ($b->jenis !== 'opname')<a href="{{ route('bbm.edit', $b->baris) }}" class="tombol-edit">Edit</a>@endif
                                <button type="button" class="tombol-hapus" data-hapus="{{ route('bbm.hapus', $b->baris) }}"
                                    data-ringkasan="Baris {{ $b->baris }} · {{ $b->tanggal?->translatedFormat('j M Y') }} · {{ $b->unit }} · keluar {{ $l($b->keluar) }} · masuk {{ $l($b->masuk) }}">Hapus</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="16" class="redup" style="padding: 20px 14px;">{{ $perlu ? 'Tidak ada baris yang perlu dilengkapi. 👍' : 'Belum ada data.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <form method="POST" id="form-hapus" hidden>@csrf @method('DELETE')@if ($perlu)<input type="hidden" name="perlu" value="1">@endif</form>
    <script>
        document.querySelectorAll('[data-hapus]').forEach(t => t.addEventListener('click', () => {
            if (!confirm(`Hapus baris ini dari sheet Penggunaan BBM?\n\n${t.dataset.ringkasan}\n\nBarisnya di sheet dihapus dan stok baris sesudahnya ikut dihitung ulang. Isinya tetap tersimpan di riwayat aplikasi.`)) return;
            const f = document.getElementById('form-hapus');
            f.action = t.dataset.hapus;
            t.disabled = true; t.textContent = 'Menghapus…';
            f.submit();
        }));
    </script>
    <script src="{{ asset('js/tanggal-id.js') }}"></script>
@endsection
