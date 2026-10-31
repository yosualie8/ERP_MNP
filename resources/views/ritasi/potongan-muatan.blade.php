@extends('layouts.app', ['judul' => 'Potongan Muatan'])

@section('lebar', '1400px')

@section('isi')
    @php
        $m3 = fn ($v) => \App\Support\PotonganMuatan::m3($v);
        $mulai = $aturan['mulai'] ? \Illuminate\Support\Carbon::parse($aturan['mulai']) : null;
    @endphp
    <style>
        .ringkas-muatan { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas-muatan .kartu { margin: 0; padding: 10px 14px; }
        .ringkas-muatan b { display: block; font-size: 21px; }
        .aturan-muatan { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; }
        .aturan-muatan label { margin: 0; font-size: 13px; }
        .aturan-muatan input { width: 110px; }
        .status-muatan { font-size: 12px; white-space: nowrap; }
    </style>

    <div class="kartu">
        <b>Aturan</b>
        <p class="redup" style="font-size: 13px; margin: 4px 0 10px;">Hanya rit Kendal & Jogja yang diukur loadscanner (No Seri LS-…). Tidak dipotong selama volume ≥ standar − toleransi.
            Di bawah itu: potongan = (standar − volume) × tarif per m³. Potongan direkap per sopir per hari bongkar, lalu dicatat ke <b>Hutang Supir</b> bertanggal hari
            berikutnya supaya terpotong dari uang jalan besoknya.
            @if ($mulai) Berlaku untuk bongkar mulai <b>{{ $mulai->translatedFormat('j M Y') }}</b>; sebelumnya hanya laporan.
            @else <b style="color: #f0c05a;">Tanggal mulai belum diisi — semua masih laporan, belum bisa dicatat ke Hutang Supir.</b>@endif</p>
        <form method="POST" action="{{ route('potongan-muatan.aturan') }}" class="aturan-muatan">
            @csrf
            @foreach ($aturan['standar'] as $j => $s)
                <label>Standar {{ $j }} (m³)<br><input type="number" step="0.1" name="standar[{{ $j }}]" value="{{ $s }}" @disabled(! $bolehAtur)></label>
            @endforeach
            <label>Toleransi (m³)<br><input type="number" step="0.1" name="toleransi" value="{{ $aturan['toleransi'] }}" @disabled(! $bolehAtur)></label>
            <label>Tarif per m³ (Rp)<br><input type="number" step="1000" name="tarif" value="{{ $aturan['tarif'] }}" @disabled(! $bolehAtur)></label>
            <label>Mulai berlaku (bongkar)<br><input type="text" name="mulai" data-tanggal-id value="{{ $aturan['mulai'] }}" style="width: 140px;" @disabled(! $bolehAtur)></label>
            @if ($bolehAtur)<button type="submit" class="tombol polos">Simpan aturan</button>@else<span class="redup" style="font-size: 12px;">Hanya Super Admin yang bisa mengubah.</span>@endif
        </form>
        <div class="redup" style="font-size: 12.5px; margin-top: 8px;">Batas potong:
            @foreach ($aturan['standar'] as $j => $s){{ $j }} &lt; {{ $m3($s - $aturan['toleransi']) }} m³@if (! $loop->last) · @endif @endforeach</div>
    </div>

    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
        <span class="redup">Bongkar</span>
        <input type="text" name="dari" data-tanggal-id value="{{ $dari->toDateString() }}" style="width: 140px;">
        <span class="redup">s.d.</span>
        <input type="text" name="sampai" data-tanggal-id value="{{ $sampai->toDateString() }}" style="width: 140px;">
        <button type="submit" class="tombol polos">Tampilkan</button>
    </form>

    <div class="ringkas-muatan">
        <div class="kartu"><span class="redup">Rit ber-LS</span><b>{{ rp($ringkas['rit']) }}</b>
            <span class="redup" style="font-size: 12px;">rata-rata @foreach ($ringkas['rata'] as $j => $r){{ $j ?: '?' }} {{ $m3($r) }}@if (! $loop->last) · @endif @endforeach m³</span></div>
        <div class="kartu"><span class="redup">Rit di bawah batas</span><b @if ($ringkas['kurang']) style="color: #f0c05a;" @endif>{{ $ringkas['kurang'] }}</b>
            <span class="redup" style="font-size: 12px;">{{ $ringkas['rit'] ? number_format($ringkas['kurang'] / $ringkas['rit'] * 100, 1, ',', '.') : 0 }}% rit</span></div>
        <div class="kartu"><span class="redup">Total potongan</span><b>{{ rp($ringkas['potongan']) }}</b></div>
        @if ($ringkas['tanpa_standar'])
            <div class="kartu"><span class="redup">Rit tanpa standar (jenis truk bukan Faw/Mercy)</span><b style="color: var(--merah);">{{ $ringkas['tanpa_standar'] }}</b></div>
        @endif
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <div style="padding: 12px 14px 0;"><b>Rekap per hari bongkar</b></div>
        <table class="kas">
            <thead><tr><th>Tanggal bongkar</th><th class="angka">Rit LS</th><th class="angka">Rit kurang</th><th class="angka">Sopir kena</th><th class="angka">Potongan</th>
                <th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse ($perHari as $d => $h)
                    <tr>
                        <td data-n="{{ $d }}">{{ $h->tanggal->translatedFormat('D, j M Y') }}</td>
                        <td class="angka">{{ $h->rit }}</td>
                        <td class="angka">{{ $h->rit_kurang ?: '' }}</td>
                        <td class="angka">{{ $h->sopir ?: '' }}</td>
                        <td class="angka" data-n="{{ $h->potongan }}"><b>{{ $h->potongan ? rp($h->potongan) : '' }}</b></td>
                        <td class="status-muatan">
                            @if (! $h->boleh)<span class="redup">laporan (sebelum berlaku)</span>
                            @elseif (! $h->potongan && ! $h->beda)<span class="redup">tidak ada potongan</span>
                            @elseif ($h->beda)<span style="color: #f0c05a;">berubah sejak dicatat</span>
                            @elseif ($h->tercatat)<span style="color: var(--sukses);">✓ tercatat di Hutang Supir</span>
                            @else<span style="color: #f0c05a;">belum dicatat</span>@endif
                        </td>
                        <td>
                            @if ($h->boleh && ($h->potongan || $h->beda) && (! $h->tercatat || $h->beda))
                                <form method="POST" action="{{ route('potongan-muatan.catat') }}" onsubmit="return confirm('Catat potongan muatan bongkar {{ $h->tanggal->translatedFormat('j M') }} ke Hutang Supir (tanggal {{ $h->tanggal->copy()->addDay()->translatedFormat('j M') }})?')">
                                    @csrf<input type="hidden" name="tanggal" value="{{ $d }}">
                                    <button type="submit" class="tombol polos" style="padding: 3px 10px; font-size: 12.5px;">{{ $h->beda ? 'Perbarui' : 'Catat ke Hutang Supir' }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="redup" style="padding: 18px 14px;">Tidak ada rit ber-LS di rentang ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <div style="padding: 12px 14px 0;"><b>Potongan per sopir per hari</b></div>
        <table class="kas">
            <thead><tr><th>Tanggal bongkar</th><th>Sopir</th><th>DT</th><th>Jenis</th><th>Proyek</th><th class="angka">Rit</th><th class="angka">Rit kurang</th>
                <th>Rit kurang (No LS · m³ · standar)</th><th class="angka">Potongan</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($harian as $h)
                    <tr>
                        <td data-n="{{ $h->tanggal->toDateString() }}">{{ $h->tanggal->translatedFormat('j M') }}</td>
                        <td><b>{{ $h->sopir ?? '?' }}</b></td>
                        <td>{{ $h->dt }}</td>
                        <td>{{ $h->jenis }}</td>
                        <td>{{ $h->tahap }}</td>
                        <td class="angka">{{ $h->rit }}</td>
                        <td class="angka">{{ $h->rit_kurang }}</td>
                        <td style="font-size: 12.5px;">{{ $h->kurang->map(fn ($r) => $r->ls.' · '.$m3($r->volume).' (−'.$m3($r->standar - $r->volume).')')->implode('; ') }}</td>
                        <td class="angka" data-n="{{ $h->potongan }}"><b>{{ rp($h->potongan) }}</b></td>
                        <td class="status-muatan">
                            @if (! $h->boleh)<span class="redup">laporan</span>
                            @elseif ($h->beda)<span style="color: #f0c05a;">berubah ({{ rp($h->catat->nominal) }} tercatat)</span>
                            @elseif ($h->catat)<span style="color: var(--sukses);">✓ tercatat {{ $h->catat->tanggal->translatedFormat('j M') }}</span>
                            @else<span style="color: #f0c05a;">belum dicatat</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="redup" style="padding: 18px 14px;">Tidak ada rit di bawah batas di rentang ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
