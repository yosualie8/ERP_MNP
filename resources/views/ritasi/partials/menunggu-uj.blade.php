{{-- Catatan surat jalan yang menunggu uang jalan (owner 11 Nov 2026): surat jalan suatu DT lebih banyak dari DO belum bongkarnya —
     yang tidak kebagian DO tidak diisi; admin upload lagi setelah uang jalannya diinput di Kas UJ. --}}
@php($tunggu = collect($perDt)->filter(fn ($p) => ! empty($p['menunggu'])))
@if ($tunggu->isNotEmpty())
    <div class="pesan" style="margin: 8px 0; background: rgba(240, 192, 90, .1); color: #f0c05a; border-color: rgba(240, 192, 90, .35);">
        <b>⏳ {{ $tunggu->sum(fn ($p) => count($p['menunggu'])) }} ritasi menunggu uang jalan</b> — belum ikut terisi / tersimpan karena DO belum bongkarnya kurang.
        Rit ini tersimpan di daftar menunggu; setelah uang jalannya diinput di Kas UJ, muncul di banner atas Monitor Ritasi — klik <b>Isi ke form</b> lalu Simpan bongkar.
        <ul style="margin: 6px 0 0; padding-left: 18px;">
            @foreach ($tunggu as $p)
                <li><b>{{ $p['dt'] }}</b>: {{ collect($p['menunggu'])->map(fn ($x) => $x['no_seri'].' ('.\Illuminate\Support\Carbon::parse($x['tanggal'])->translatedFormat('j M').($x['jam'] ? ' '.$x['jam'] : '').')')->implode(', ') }}</li>
            @endforeach
        </ul>
    </div>
@endif
