@extends('layouts.app', ['judul' => 'Muatan Kurang (MK)'])

@section('lebar', '1400px')

@section('isi')
    <style>
        .mk-status { font-size: 12.5px; white-space: nowrap; }
        .mk-cari { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .mk-cari input[name=cari] { width: 260px; }
        .mk-aksi { display: flex; gap: 6px; align-items: center; flex-wrap: nowrap; }
        .mk-aksi input { width: 100px; text-align: right; padding: 3px 6px; }
        .mk-aksi .tombol { padding: 3px 10px; font-size: 12.5px; }
    </style>

    <div class="kartu">
        <b>Tandai surat jalan MK</b>
        <p class="redup" style="font-size: 13px; margin: 4px 0 10px;">
            Surat jalan yang dinyatakan muatan kurang → hutang sopir bertambah <b>Rp{{ rp(\App\Models\MuatanKurang::NOMINAL) }}</b> (tanggal bongkar). Saat uang jalan berikutnya
            ditransfer kurang, Input UJ mengusulkan potongan MK itu otomatis — begitu disimpan, MK lunas. Bisa juga dicentang langsung di <a href="{{ route('ritasi.monitor') }}">Monitor Ritasi</a> saat Simpan bongkar.
            @unless ($bolehNominal)<br>Nominal hanya bisa diubah Valen, Eddy &amp; Super Admin.@endunless
        </p>
        <form method="GET" class="mk-cari">
            <input type="text" name="cari" value="{{ $cari }}" placeholder="No Surat Jalan / No LS / No DO" autocomplete="off" autofocus>
            <button type="submit" class="tombol polos">Cari rit</button>
            @if ($cari !== '')<a href="{{ route('mk.index') }}" class="redup" style="font-size: 13px;">bersihkan</a>@endif
        </form>
        @if ($cari !== '')
            <div class="gulir" style="margin-top: 10px;">
                <table class="kas">
                    <thead><tr><th>Tanggal bongkar</th><th>No Surat Jalan</th><th>No DO</th><th>DT</th><th>Sopir</th><th>Galian → Tujuan</th><th>Sumber</th><th>Tandai MK</th></tr></thead>
                    <tbody>
                        @forelse ($calon as $r)
                            <tr>
                                <td data-n="{{ $r['tanggal']?->toDateString() }}">{{ $r['tanggal']?->translatedFormat('j M Y') }} {{ $r['jam'] }}</td>
                                <td><b>{{ $r['no_seri'] }}</b></td>
                                <td>{{ $r['no_do'] ?? '–' }}</td>
                                <td>{{ $r['no_mobil'] ?? '–' }}</td>
                                <td>{{ $r['sopir'] ?? '?' }}</td>
                                <td>{{ $r['galian'] ?? '?' }} → {{ $r['tahap'] ?? '?' }}</td>
                                <td class="redup" style="font-size: 12.5px;">{{ $r['sumber'] }}</td>
                                <td>
                                    @if ($r['mk'])
                                        <span class="mk-status" style="color: var(--sukses);">✓ sudah MK Rp{{ rp($r['mk']->nominal) }}</span>
                                    @elseif (! $r['sopir'])
                                        <span class="mk-status redup">sopir belum diketahui</span>
                                    @else
                                        <form method="POST" action="{{ route('mk.tandai') }}" class="mk-aksi" onsubmit="return confirm('Tandai MK surat jalan {{ $r['no_seri'] }} ({{ $r['sopir'] }})? Hutang sopir bertambah.')">
                                            @csrf
                                            <input type="hidden" name="no_seri" value="{{ $r['no_seri'] }}">
                                            <input type="text" name="nominal" value="{{ number_format(\App\Models\MuatanKurang::NOMINAL, 0, ',', '.') }}" inputmode="numeric" @readonly(! $bolehNominal) title="{{ $bolehNominal ? 'Nominal MK' : 'Nominal hanya bisa diubah Valen, Eddy & Super Admin' }}">
                                            <input type="text" name="alasan" placeholder="alasan (opsional)" maxlength="250" style="width: 170px; text-align: left;">
                                            <button type="submit" class="tombol">Tandai MK</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="redup" style="padding: 14px;">Tidak ada rit dengan No Surat Jalan / DO "{{ $cari }}" (minimal 3 karakter).</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px;">
        <span class="redup">Tampilkan</span>
        <select name="status" onchange="this.form.submit()" style="width: auto;">
            @foreach (['semua' => 'Semua MK', 'belum' => 'Belum dipotong', 'dipotong' => 'Sudah dipotong'] as $k => $l)
                <option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>
            @endforeach
        </select>
        <span class="redup" style="font-size: 13px;">{{ $daftar->count() }} MK · belum dipotong Rp{{ rp((int) $daftar->whereNull('pakai_id')->sum('nominal')) }}</span>
    </form>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Tanggal bongkar</th><th>Sopir</th><th>DT</th><th>No Surat Jalan</th><th>No DO</th><th>Galian → Tujuan</th><th class="angka">Nominal</th><th>Alasan</th>
                <th>Status</th><th>Ditandai</th><th data-tanpa-saring></th></tr></thead>
            <tbody>
                @forelse ($daftar as $m)
                    <tr>
                        <td data-n="{{ $m->tanggal->toDateString() }}">{{ $m->tanggal->translatedFormat('j M Y') }}</td>
                        <td><b>{{ $m->sopir }}</b></td>
                        <td>{{ $m->no_mobil }}</td>
                        <td>{{ $m->no_seri }}</td>
                        <td>{{ $m->no_do }}</td>
                        <td>{{ $m->galian ?? '?' }} → {{ $m->tahap ?? '?' }}</td>
                        <td class="angka" data-n="{{ $m->nominal }}">Rp{{ rp($m->nominal) }}</td>
                        <td style="font-size: 12.5px;">{{ $m->alasan }}</td>
                        <td class="mk-status">
                            @if ($m->dipotong())<span style="color: var(--sukses);">✓ dipotong {{ $m->id_uj }}</span>
                            @else<span style="color: #f0c05a;">belum dipotong</span>@endif
                        </td>
                        <td class="redup" style="font-size: 12px;">{{ $m->user?->name }} · {{ $m->created_at?->translatedFormat('j M H:i') }}</td>
                        <td>
                            @unless ($m->dipotong())
                                <div class="mk-aksi">
                                    @if ($bolehNominal)
                                        <form method="POST" action="{{ route('mk.ubah', $m) }}" class="mk-aksi">
                                            @csrf
                                            <input type="text" name="nominal" value="{{ number_format($m->nominal, 0, ',', '.') }}" inputmode="numeric">
                                            <button type="submit" class="tombol polos">Ubah</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('mk.hapus', $m) }}" onsubmit="return confirm('Hapus MK surat jalan {{ $m->no_seri }}? Hutang {{ $m->sopir }} berkurang Rp{{ rp($m->nominal) }}.')">
                                        @csrf<button type="submit" class="tombol polos">Hapus</button>
                                    </form>
                                </div>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="redup" style="padding: 18px 14px;">Belum ada MK.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
