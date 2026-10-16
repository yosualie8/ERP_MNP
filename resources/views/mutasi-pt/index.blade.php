@extends('layouts.app', ['judul' => 'Mutasi Rekening PT (BCA)'])

@section('lebar', '1600px')

@section('isi')
    @php
        $nb = fn ($ym) => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $ym.'-01')->translatedFormat('F Y');
        $tgl = fn ($d) => $d ? $d->translatedFormat('d-M-Y') : '';
        $keluar = $baris->where('m.dbcr', 'DB')->sum('m.jumlah');
        $masuk = $baris->where('m.dbcr', 'CR')->sum('m.jumlah');
        $daftar = $bulanAda->keys()->values();
        $i = $bulan ? $daftar->search($bulan) : false;
        $warna = ['dihitung' => 'hijau', 'tidak' => '', 'belum' => 'kuning'];
        $dataTahap = $tahap->mapWithKeys(fn ($t) => [$t->nama => $t->id]);
    @endphp
    <style>
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 19px; margin-top: 2px; }
        .ringkas small { font-size: 12px; color: var(--redup); }
        table.kas select { padding: 3px 5px; font-size: 12.5px; max-width: 100%; }
        table.kas td.tanda { white-space: nowrap; }
        table.kas .alasan { display: block; font-size: 10.5px; color: var(--redup); }
        table.kas tr.ok-simpan td { transition: background .6s; background: rgba(76, 195, 138, .12); }
        table.kas tr.galat-simpan td { background: rgba(255, 92, 97, .12); }
        table.kas input.catatan-admin { display: block; width: 100%; box-sizing: border-box; margin-top: 4px; padding: 3px 6px; font-size: 12.5px;
            background: transparent; border: 1px dashed var(--garis); color: #93c5fd; }
        table.kas input.catatan-admin:focus { border-style: solid; background: var(--kartu-2, #262b33); }
        table.kas input.catatan-admin:not(:placeholder-shown) { border-color: transparent; font-weight: 600; }
        .catatan-admin-teks { margin-top: 3px; font-size: 12.5px; font-weight: 600; color: #93c5fd; }
    </style>

    <div class="kartu" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <form method="GET" style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
            @if ($i !== false && $i < $daftar->count() - 1)<a class="tombol polos" style="padding: 6px 12px;" href="{{ route('mutasi-pt.index', ['bulan' => $daftar[$i + 1]]) }}">‹</a>@endif
            <select name="bulan" onchange="this.form.q.value = ''; this.form.submit()" style="min-width: 170px;">
                @foreach ($bulanAda as $ym => $n)<option value="{{ $ym }}" @selected($q === '' && ! $status && $ym === $bulan)>{{ $nb($ym) }} · {{ $n }}</option>@endforeach
            </select>
            @if ($i !== false && $i > 0)<a class="tombol polos" style="padding: 6px 12px;" href="{{ route('mutasi-pt.index', ['bulan' => $daftar[$i - 1]]) }}">›</a>@endif
            <input type="search" name="q" value="{{ $q }}" placeholder="Cari keterangan BCA / keterangan tambahan di semua bulan" style="min-width: 300px;" autocomplete="off">
            <button type="submit" class="tombol polos" style="padding: 6px 12px;">Cari</button>
            @if ($q !== '' || $status)<a href="{{ route('mutasi-pt.index', ['bulan' => $bulan]) }}" style="font-size: 13px;">kembali per bulan</a>@endif
        </form>
        <form method="POST" action="{{ route('mutasi-pt.perbarui') }}" style="margin-left: auto;">@csrf<button type="submit" class="tombol polos" style="padding: 6px 12px;">↻ Perbarui dari Drive</button></form>
    </div>

    <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-bottom: 10px;">
        <span class="redup" style="font-size: 13px;">Sejak 1 Jan 2026:</span>
        @foreach (\App\Support\TandaMutasiPt::STATUS as $k => $l)
            <a href="{{ route('mutasi-pt.index', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $l }} · {{ number_format($hitung[$k] ?? 0, 0, ',', '.') }}</a>
        @endforeach
        <span class="redup" style="font-size: 13px; margin-left: 6px;">Total dihitung sebagai biaya: <b>{{ rp((int) round($rpDihitung)) }}</b></span>
    </div>

    <div class="ringkas">
        <div class="kartu"><small>{{ $status ? \App\Support\TandaMutasiPt::STATUS[$status].' (semua bulan 2026)' : ($q !== '' ? 'Hasil cari "'.$q.'"' : $nb($bulan ?? now()->format('Y-m'))) }}</small><b>{{ number_format($baris->count(), 0, ',', '.') }} transaksi</b></div>
        <div class="kartu"><small>Keluar (DB)</small><b style="color: var(--merah);">{{ rp((int) round($keluar)) }}</b></div>
        <div class="kartu"><small>Masuk (CR)</small><b style="color: var(--sukses);">{{ rp((int) round($masuk)) }}</b></div>
        <div class="kartu"><small>Saldo terakhir</small><b>{{ $saldoAkhir?->saldo !== null ? rp((int) round($saldoAkhir->saldo)) : '–' }}</b><small>{{ $saldoAkhir ? $tgl($saldoAkhir->tanggal) : '' }}</small></div>
    </div>
    <p class="redup" style="font-size: 12px; margin: -4px 0 10px;">
        <b>Dihitung</b> = dibayar langsung dari BCA (transaksi besar / berpajak) → wajib isi <b>Cost Center = tahap detail</b> (nama persis dari Daftar Proyek); bila tahapnya pos biaya umum (mis. "ASG (biaya tanpa tahap)", "JKT (biaya lokasi)") biaya ikut dibagi di Alokasi Biaya Umum.
        <b>Tidak dihitung</b> = uang masuk, reimburse Kas Harian/UJ, rekening internal (Barnabas, Yosua, Multi Transportasi), ke Hans Anderson, pelunasan pinjaman, ke rekening MNP sendiri, leasing, DP/beli unit, sparepart/overhaul/ban, HPP ritasi — ditandai otomatis (alasan tertulis di bawah status), boleh diubah. Tidak ada yang otomatis Dihitung.
        Sumber: file Drive "{{ $impor['nama'] ?? 'Mutasi Rek PT Gabungan MNP.xlsx' }}"@if ($impor), diimpor {{ \Illuminate\Support\Carbon::parse($impor['waktu'])->translatedFormat('d-M H:i') }}, diperiksa tiap 30 menit @endif.</p>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas" data-saring-kolom>
            <thead><tr><th>Tanggal</th><th>Keterangan</th><th>DB/CR</th><th class="angka">Keluar</th><th class="angka">Masuk</th><th class="angka">Saldo</th>
                <th style="width: 150px;">Dihitung</th><th style="width: 260px;">Cost Center (tahap detail)</th></tr></thead>
            <tbody>
                @forelse ($baris as $x)
                    @php($m = $x->m)
                    @php($t = $x->t)
                    <tr class="t" @if ($t) data-kunci="{{ $t->kunci }}" @endif>
                        <td data-n="{{ $m->tanggal?->toDateString() }}" data-v="{{ $m->tanggal?->toDateString() }} {{ $tgl($m->tanggal) }}" style="white-space: nowrap;">{{ $tgl($m->tanggal) }}</td>
                        <td data-v="{{ $m->keterangan }} {{ $t?->tanda?->catatan }}">{{ $m->keterangan }}
                            @if ($t && $bolehTandai)
                                <input data-f="catatan" value="{{ $t->tanda?->catatan }}" maxlength="500" placeholder="+ keterangan tambahan" autocomplete="off" class="catatan-admin">
                            @elseif ($t?->tanda?->catatan)
                                <div class="catatan-admin-teks">{{ $t->tanda->catatan }}</div>
                            @endif
                        </td>
                        <td>@if ($m->dbcr === 'DB')<span class="label merah">DB</span>@elseif ($m->dbcr === 'CR')<span class="label hijau">CR</span>@else{{ $m->dbcr }}@endif</td>
                        <td class="angka" data-n="{{ $m->dbcr === 'DB' ? $m->jumlah : '' }}">{{ $m->dbcr === 'DB' ? rp((int) round($m->jumlah)) : '' }}</td>
                        <td class="angka" data-n="{{ $m->dbcr === 'CR' ? $m->jumlah : '' }}">{{ $m->dbcr === 'CR' ? rp((int) round($m->jumlah)) : '' }}</td>
                        <td class="angka" data-n="{{ $m->saldo }}">{{ $m->saldo !== null ? rp((int) round($m->saldo)) : '' }}</td>
                        @if (! $t)
                            <td class="redup" colspan="2" style="font-size: 12px;">sebelum 2026 — tidak ditandai</td>
                        @else
                            <td class="tanda" data-v="{{ \App\Support\TandaMutasiPt::STATUS[$t->status] }}">
                                @if ($bolehTandai)
                                    <select data-f="status">@foreach (\App\Support\TandaMutasiPt::STATUS as $k => $l)@if ($k !== 'dihitung' || $m->dbcr === 'DB')<option value="{{ $k }}" @selected($t->status === $k)>{{ $l }}</option>@endif @endforeach</select>
                                @else<span class="label {{ $warna[$t->status] }}">{{ \App\Support\TandaMutasiPt::STATUS[$t->status] }}</span>@endif
                                @if ($t->alasan)<span class="alasan">{{ $t->alasan }}</span>@endif
                            </td>
                            <td data-v="{{ $t->tanda?->tahap?->nama }}">
                                @if ($bolehTandai)
                                    <input data-f="tahap" list="daftar-tahap" value="{{ $t->tanda?->tahap?->nama }}" placeholder="{{ $m->dbcr === 'DB' ? 'ketik / pilih tahap' : '' }}" autocomplete="off" style="width: 100%; padding: 3px 6px; font-size: 12.5px;" @disabled($m->dbcr !== 'DB')>
                                @else{{ $t->tanda?->tahap?->nama }}@endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="8" class="redup" style="padding: 16px;">{{ $bulanAda->isEmpty() ? 'Belum ada data — tekan "Perbarui dari Drive".' : 'Tidak ada transaksi.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($bolehTandai)
        <datalist id="daftar-tahap">@foreach ($tahap as $tp)<option value="{{ $tp->nama }}">{{ $tp->pos_overhead ? 'pos biaya umum · ' : '' }}{{ $tp->proyek->nama }}{{ $tp->status !== 'progress' ? ' · '.\App\Models\ProyekTahap::STATUS[$tp->status] : '' }}</option>@endforeach</datalist>
        <script>
            (() => {
                // Cost Center = tahap detail (nama persis dari Daftar Proyek); wajib bila Dihitung.
                const TAHAP = @json($dataTahap);
                const URL_TANDA = @json(route('mutasi-pt.tandai')), URL_CATATAN = @json(route('mutasi-pt.catatan')), TOKEN = @json(csrf_token());
                const atur = tr => {
                    const st = tr.querySelector('[data-f=status]').value, th = tr.querySelector('[data-f=tahap]');
                    const v = th.value.trim(), ada = v !== '' && Object.hasOwn(TAHAP, v);
                    th.style.borderColor = (st === 'dihitung' && !ada) || (v !== '' && !ada) ? 'var(--merah)' : '';
                    th.title = v !== '' && !ada ? 'Tahap tidak terdaftar — pilih dari daftar (nama persis)' : '';
                    return {st, v, ada};
                };
                document.querySelectorAll('tr[data-kunci]').forEach(tr => {
                    const th = tr.querySelector('[data-f=tahap]');
                    if (!th) return;
                    atur(tr);
                    th.addEventListener('input', () => atur(tr));
                    // Keterangan tambahan: disimpan sendiri saat kotaknya ditinggalkan (tidak mengubah status).
                    const cat = tr.querySelector('[data-f=catatan]');
                    if (cat) {
                        let awal = cat.value;
                        cat.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); cat.blur(); } });
                        cat.addEventListener('change', e => e.stopPropagation());
                        cat.addEventListener('blur', async () => {
                            if (cat.value.trim() === awal.trim()) return;
                            const data = new FormData();
                            data.append('_token', TOKEN); data.append('kunci', tr.dataset.kunci); data.append('catatan', cat.value);
                            tr.classList.remove('ok-simpan', 'galat-simpan');
                            try {
                                const res = await fetch(URL_CATATAN, {method: 'POST', body: data, headers: {Accept: 'application/json'}});
                                const h = await res.json().catch(() => ({}));
                                if (!res.ok) throw new Error(h.galat || h.message || 'Gagal menyimpan keterangan');
                                cat.value = awal = h.catatan || ''; tr.classList.add('ok-simpan');
                            } catch (err) { tr.classList.add('galat-simpan'); alert(err.message); }
                        });
                    }
                    tr.addEventListener('change', async () => {
                        const {st, v, ada} = atur(tr);
                        if (v !== '' && !ada) return;                      // nama tahap belum persis
                        if (st === 'dihitung' && !ada) { th.focus(); return; } // tunggu cost center (tahap) diisi
                        const data = new FormData();
                        data.append('_token', TOKEN); data.append('kunci', tr.dataset.kunci); data.append('status', st);
                        data.append('tahap_id', st === 'dihitung' ? TAHAP[v] : '');
                        tr.classList.remove('ok-simpan', 'galat-simpan');
                        try {
                            const res = await fetch(URL_TANDA, {method: 'POST', body: data, headers: {Accept: 'application/json'}});
                            const h = await res.json().catch(() => ({}));
                            if (!res.ok) throw new Error(h.galat || h.message || 'Gagal menyimpan');
                            tr.classList.add('ok-simpan');
                            const al = tr.querySelector('.alasan'); if (al) al.textContent = 'ditandai manual';
                            tr.querySelector('td.tanda').dataset.v = h.label; th.closest('td').dataset.v = st === 'dihitung' ? v : '';
                            if (st !== 'dihitung') th.value = '';
                        } catch (err) { tr.classList.add('galat-simpan'); alert(err.message); }
                    });
                });
            })();
        </script>
    @endif
@endsection
