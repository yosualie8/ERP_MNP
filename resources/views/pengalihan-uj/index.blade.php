@extends('layouts.app', ['judul' => 'Pengalihan UJ'])

@section('lebar', '1400px')

@section('isi')
    @php
        $tgl = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-y');
        $usul = $calon->where('usul', true);
        $mobilCalon = $calon->groupBy(fn ($c) => $c->u->no_mobil);
    @endphp
    <style>
        table.kas td.angka, table.kas th.angka { white-space: nowrap; }
        tr.sub td { font-weight: 700; background: rgba(255, 255, 255, .03); }
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 19px; margin-top: 2px; }
        .ringkas small { font-size: 12px; color: var(--redup); }
        .baris-isian { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        .baris-isian label { display: block; font-size: 12px; color: var(--redup); margin-bottom: 3px; }
    </style>

    <form method="GET" class="kartu baris-isian">
        <div><label>Proyek asal (UJ belum bongkar)</label>
            <select name="asal" onchange="this.form.submit()">@foreach ($ritasi as $t)<option value="{{ $t->id }}" @selected($asal?->id === $t->id)>{{ $t->nama }}</option>@endforeach</select></div>
        <div><label>Proyek tujuan</label>
            <select name="tujuan" onchange="this.form.submit()"><option value="">—</option>@foreach ($ritasi as $t)@if ($t->id !== $asal?->id)<option value="{{ $t->id }}" @selected($tujuan?->id === $t->id)>{{ $t->nama }}</option>@endif @endforeach</select></div>
        <div style="flex: 1; min-width: 260px;"><label>Mobil yang dipindah (pisahkan koma, kosong = semua)</label>
            <input name="mobil" value="{{ $pilihMobil->implode(', ') }}" placeholder="mis. DT 026, DT 029" style="width: 100%;"></div>
        <button class="tombol polos" type="submit">Tampilkan</button>
    </form>
    <p class="redup" style="font-size: 12px; margin: -6px 0 12px;">Untuk UJ yang sudah diberikan tetapi truknya dipindah ke proyek lain sebelum bongkar. Transaksi Kas UJ aslinya <b>tidak diubah</b>.
        <b>Dialihkan</b> = biaya UJ pindah ke proyek tujuan dan menjadi saldo sopir (dipakai untuk Uang MOB / dipotong dari UJ berikutnya). <b>Dikembalikan</b> = uangnya dikembalikan sopir, keluar dari biaya proyek asal.</p>

    @if ($tujuan && $saldo->isNotEmpty())
        <div class="ringkas">
            <div class="kartu"><small>UJ dialihkan ke {{ $tujuan->nama }}</small><b>{{ $saldo->sum('n') }} UJ · {{ rp((int) $saldo->sum('alih')) }}</b><small>{{ $saldo->count() }} mobil</small></div>
            <div class="kartu"><small>Dipakai Uang MOB</small><b>{{ rp((int) $saldo->sum('mob')) }}</b></div>
            <div class="kartu"><small>Dipotong / pemakaian lain</small><b>{{ rp((int) $saldo->sum('pakai_lain')) }}</b></div>
            <div class="kartu"><small>Saldo sopir (potong UJ berikutnya)</small><b>{{ rp((int) $saldo->sum('saldo')) }}</b></div>
        </div>
        <div class="kartu gulir" style="padding: 0;">
            <h4 style="margin: 0; padding: 12px 14px 6px; color: var(--aksen-terang);">Saldo per mobil di {{ $tujuan->nama }}</h4>
            <table class="kas" data-tanpa-saring-kolom>
                <thead><tr><th>Mobil</th><th>Sopir</th><th class="angka">UJ dialihkan</th><th class="angka">Rp dialihkan</th><th class="angka">Uang MOB</th><th class="angka">Dipotong / lain</th><th class="angka">Saldo</th><th>Catat pemakaian saldo</th></tr></thead>
                <tbody>
                    @foreach ($saldo as $mobil => $s)
                        <tr><td><b>{{ $mobil }}</b></td><td>{{ $s['nama'] }}</td><td class="angka">{{ $s['n'] }}</td><td class="angka">{{ rp((int) $s['alih']) }}</td><td class="angka">{{ $s['mob'] ? rp((int) $s['mob']) : '' }}</td>
                            <td class="angka">{{ $s['pakai_lain'] ? rp((int) $s['pakai_lain']) : '' }}</td><td class="angka"><b>{{ rp((int) $s['saldo']) }}</b></td>
                            <td>@if ($s['saldo'] > 0)
                                <form method="POST" action="{{ route('pengalihan-uj.pakai') }}" style="display: flex; gap: 4px; flex-wrap: wrap;">@csrf
                                    <input type="hidden" name="tujuan" value="{{ $tujuan->id }}"><input type="hidden" name="no_mobil" value="{{ $mobil }}">
                                    <input type="date" name="tanggal" value="{{ now()->toDateString() }}" style="width: 130px; padding: 2px 4px;">
                                    <select name="peruntukan" style="padding: 2px 4px;">@foreach (\App\Models\UjPengalihan::PERUNTUKAN as $k => $l)<option value="{{ $k }}" @selected($k === 'uj')>{{ $l }}</option>@endforeach</select>
                                    <input name="nominal" value="{{ (int) $s['saldo'] }}" style="width: 90px; text-align: right; padding: 2px 4px;" inputmode="numeric">
                                    <input name="keterangan" placeholder="mis. potong UJ-xxxx" style="width: 140px; padding: 2px 4px;">
                                    <button class="tombol polos" style="padding: 2px 8px;">Catat</button>
                                </form>@endif</td></tr>
                    @endforeach
                    <tr class="sub"><td colspan="2">Total</td><td class="angka">{{ $saldo->sum('n') }}</td><td class="angka">{{ rp((int) $saldo->sum('alih')) }}</td><td class="angka">{{ rp((int) $saldo->sum('mob')) }}</td><td class="angka">{{ rp((int) $saldo->sum('pakai_lain')) }}</td><td class="angka">{{ rp((int) $saldo->sum('saldo')) }}</td><td></td></tr>
                </tbody>
            </table>
        </div>
    @endif

    @if ($calon->isNotEmpty())
        <form method="POST" action="{{ route('pengalihan-uj.simpan') }}" class="kartu gulir" style="padding: 0;">@csrf
            <input type="hidden" name="asal" value="{{ $asal->id }}"><input type="hidden" name="tujuan" value="{{ $tujuan?->id }}">
            <div style="padding: 12px 14px 6px;">
                <h4 style="margin: 0 0 6px; color: var(--aksen-terang);">UJ {{ $asal->nama }} yang belum bongkar ({{ $calon->count() }})</h4>
                <span class="redup" style="font-size: 12px;">Tercentang = usulan: UJ yang tanggalnya ≥ rit terakhir truk itu & uang tanahnya belum dibayar{{ $pilihMobil->isNotEmpty() ? ', mobil: '.$pilihMobil->implode(', ') : '' }}. Yang tidak dicentang mungkin sudah jalan tetapi belum diinput di Ritasi — cek dulu.</span>
            </div>
            <table class="kas" data-tanpa-saring-kolom>
                <thead><tr><th style="width: 30px;"><input type="checkbox" id="semua" title="Centang semua"></th><th>Mobil</th><th>Sopir</th><th>Tanggal UJ</th><th>ID UJ</th><th>No DO</th><th>Kategori</th><th>Keterangan</th><th class="angka">Nominal</th><th>Rit terakhir truk</th><th>Catatan</th></tr></thead>
                <tbody>
                    @foreach ($calon as $c)
                        <tr @if (! $c->usul) style="opacity: .75;" @endif>
                            <td><input type="checkbox" name="uj[]" value="{{ $c->u->id_uj }}" data-rp="{{ $c->u->nominal }}" @checked(collect(old('uj'))->contains($c->u->id_uj) || (! old('uj') && $c->usul))></td>
                            <td><b>{{ $c->u->no_mobil }}</b></td><td>{{ $c->u->nama }}</td><td>{{ $tgl($c->tgl) }}</td><td>{{ $c->u->id_uj }}</td><td>{{ $c->u->no_do }}</td><td>{{ $c->u->kategori }}</td><td>{{ $c->u->keterangan }}</td>
                            <td class="angka">{{ rp((int) $c->u->nominal) }}</td><td>{{ $c->rit_akhir ? $tgl($c->rit_akhir) : '–' }}</td><td style="font-size: 12px; color: #f0c05a;">{{ $c->alasan }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="baris-isian" style="padding: 12px 14px;">
                <div><label>Tindakan</label><select name="jenis" id="jenis"><option value="alih">Dialihkan ke {{ $tujuan?->nama ?? '(pilih tujuan)' }}</option><option value="kembali">Dikembalikan sopir (refund)</option></select></div>
                <div><label>Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', now()->toDateString()) }}"></div>
                <div id="isian-mob"><label>Langsung catat Uang MOB per mobil dari saldo (Rp)</label><input name="mob" value="{{ old('mob', '475000') }}" style="width: 120px; text-align: right;" inputmode="numeric"></div>
                <div style="flex: 1; min-width: 220px;"><label>Keterangan</label><input name="keterangan" value="{{ old('keterangan', $tujuan ? 'Truk dipindah ke '.$tujuan->nama.' sebelum bongkar' : '') }}" style="width: 100%;"></div>
                <button class="tombol" type="submit">Simpan <span id="jml"></span></button>
            </div>
        </form>
    @elseif ($asal)
        <div class="kartu redup">Tidak ada UJ {{ $asal->nama }} yang belum bongkar.</div>
    @endif

    @if ($buku->isNotEmpty() || $kembali->isNotEmpty())
        <div class="kartu gulir" style="padding: 0;">
            <h4 style="margin: 0; padding: 12px 14px 6px; color: var(--aksen-terang);">Riwayat pengalihan</h4>
            <table class="kas" data-saring-kolom>
                <thead><tr><th>Tanggal</th><th>Jenis</th><th>Mobil</th><th>Sopir</th><th>ID UJ</th><th>Dari</th><th>Ke</th><th>Peruntukan</th><th class="angka">Nominal</th><th>Keterangan</th><th data-tanpa-saring></th></tr></thead>
                <tbody>
                    @foreach ($buku->concat($kembali) as $p)
                        <tr><td>{{ $p->tanggal->translatedFormat('d-M-y') }}</td><td>{{ \App\Models\UjPengalihan::JENIS[$p->jenis] }}</td><td>{{ $p->no_mobil }}</td><td>{{ $p->nama }}</td><td>{{ $p->id_uj }}</td>
                            <td>{{ $p->dari?->nama }}</td><td>{{ $p->ke?->nama }}</td><td>{{ $p->peruntukan ? \App\Models\UjPengalihan::PERUNTUKAN[$p->peruntukan] : '' }}</td><td class="angka">{{ rp((int) $p->nominal) }}</td><td style="font-size: 12px;">{{ $p->keterangan }}</td>
                            <td><form method="POST" action="{{ route('pengalihan-uj.hapus', $p) }}" onsubmit="return confirm('Hapus catatan ini?')">@csrf @method('DELETE')<button class="tombol polos" style="padding: 2px 8px;">Hapus</button></form></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <script>
        (() => {
            const kotak = [...document.querySelectorAll('input[name="uj[]"]')], jml = document.getElementById('jml'), jenis = document.getElementById('jenis'), mob = document.getElementById('isian-mob');
            const hitung = () => { const c = kotak.filter(k => k.checked); if (jml) jml.textContent = c.length ? `(${c.length} UJ · Rp ${c.reduce((a, k) => a + +k.dataset.rp, 0).toLocaleString('id-ID')})` : ''; };
            kotak.forEach(k => k.addEventListener('change', hitung)); hitung();
            document.getElementById('semua')?.addEventListener('change', e => { kotak.forEach(k => k.checked = e.target.checked); hitung(); });
            jenis?.addEventListener('change', () => { mob.style.display = jenis.value === 'alih' ? '' : 'none'; });
        })();
    </script>
@endsection
