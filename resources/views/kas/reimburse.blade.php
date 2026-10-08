@extends('layouts.app', ['judul' => 'Reimburse Kas'])

@section('lebar', '1440px')

@section('isi')
    <style>
        .panel-pilih { display: grid; grid-template-columns: 240px 180px auto 1fr; gap: 12px; align-items: end; }
        .panel-pilih label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .panel-pilih input[type=text], .panel-pilih input[type=date] { width: 100%; padding: 9px 10px; border-radius: 7px; font-size: 15px; }
        .panel-pilih #target { font-size: 18px; font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }
        .hasil-pilih { display: flex; gap: 18px; flex-wrap: wrap; align-items: baseline; margin-top: 12px; font-size: 14px; }
        .hasil-pilih b { font-size: 20px; font-variant-numeric: tabular-nums; }
        .hasil-pilih .selisih { color: var(--redup); }
        table.kas tr.t.dipilih { background: rgba(76, 195, 138, .16); }
        table.kas tr.t.dipilih td:first-child { box-shadow: inset 4px 0 0 var(--sukses); }
        table.kas tr.t input[type=checkbox] { width: 17px; height: 17px; vertical-align: middle; cursor: pointer; }
        .batch-baru { border: 1px solid rgba(76, 195, 138, .45); background: var(--sukses-muda); }
        .batch-baru .aksi { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
        table.kas tr.b.fee td { color: var(--redup); font-style: italic; }
        @media (max-width: 900px) { .panel-pilih { grid-template-columns: 1fr 1fr; } }
    </style>

    @if ($baru)
        <div class="kartu batch-baru">
            <h3 style="margin: 0 0 4px;">✓ Reimburse {{ $baru->tanggal->translatedFormat('j F Y') }} tercatat — {{ rp($baru->total) }}</h3>
            <div class="redup">{{ $baru->jumlah_transfer }} transfer · {{ $baru->jumlah_baris }} transaksi detail · status tersimpan di aplikasi dan ditulis ke lembar "Sudah Reimburse".</div>
            <div class="aksi">
                <a class="tombol" href="{{ route('kas.reimburse.unduh', $baru) }}">⬇ Unduh Excel</a>
                <button type="button" class="tombol polos" id="bagikan" data-url="{{ route('kas.reimburse.unduh', $baru) }}"
                    data-nama="Reimburse Kas {{ $baru->tanggal->format('Y-m-d') }} - Rp {{ number_format($baru->total, 0, ',', '.') }}.xlsx"
                    data-teks="Reimburse kas harian {{ $baru->tanggal->translatedFormat('j M Y') }}: {{ rp($baru->total) }} ({{ $baru->jumlah_transfer }} transfer, {{ $baru->jumlah_baris }} transaksi detail)">📤 Bagikan ke WhatsApp</button>
            </div>
        </div>
    @endif

    @php($totalBelum = $antrean->sum('total'))
    <div class="ringkas">
        <div class="kartu"><span class="redup">Belum reimburse</span><b>{{ rp($totalBelum) }}</b></div>
        <div class="kartu"><span class="redup">Transfer</span><b>{{ $antrean->count() }}</b></div>
        <div class="kartu"><span class="redup">Transaksi detail</span><b>{{ $antrean->sum(fn ($t) => count($t['detail'])) }}</b></div>
        <div class="kartu"><span class="redup">Paling lama</span><b>{{ $antrean->first()['tgl'] ?? '–' }}</b></div>
    </div>

    <form method="POST" action="{{ route('kas.reimburse.simpan') }}" id="form-reimburse">
        @csrf
        <div class="kartu">
            <h3 style="margin: 0 0 12px;">Pilih transaksi untuk direimburse</h3>
            <div class="panel-pilih">
                <div>
                    <label for="target">Nominal reimburse</label>
                    <input type="text" id="target" name="target" inputmode="numeric" autocomplete="off" placeholder="mis. 20.000.000">
                </div>
                <div>
                    <label for="tanggal">Tanggal reimburse</label>
                    <input type="date" id="tanggal" name="tanggal" value="{{ now()->toDateString() }}" required>
                </div>
                <div>
                    <button type="button" class="tombol polos" id="pilih-semua">Pilih semua</button>
                    <button type="button" class="tombol polos" id="kosongkan">Kosongkan</button>
                </div>
                <div class="redup" style="font-size: 12px;">Ketik nominal → dipilih otomatis <b>kombinasi transfer yang paling mendekati</b> nominal tanpa melebihi (bila sama dekat, yang lebih lama didahulukan). Centang/hapus centang untuk menyesuaikan.</div>
            </div>
            <div class="hasil-pilih">
                <span>Terpilih <b id="total-pilih">Rp 0</b></span>
                <span class="redup"><span id="jumlah-pilih">0</span> transfer · <span id="baris-pilih">0</span> transaksi detail</span>
                <span class="selisih" id="selisih"></span>
                <button type="submit" class="tombol" id="setuju" disabled style="margin-left: auto;">Setujui &amp; catat sudah reimburse</button>
            </div>
        </div>

        <div class="kartu gulir" style="padding: 0;">
            <table class="kas">
                <thead>
                    <tr>
                        <th style="width: 34px;"></th>
                        <th>Tanggal</th>
                        <th>Tujuan</th>
                        <th>Keterangan</th>
                        <th>Kode GL</th>
                        <th>NO ID</th>
                        <th class="angka">Nominal</th>
                    </tr>
                </thead>
                @forelse ($antrean as $t)
                    <tbody class="grup" data-id="{{ $t['id'] }}" data-total="{{ $t['total'] }}" data-baris="{{ count($t['detail']) }}">
                        <tr class="t ada-bon">
                            <td><input type="checkbox" name="transfer[]" value="{{ $t['id'] }}" aria-label="Pilih transfer"></td>
                            <td><span class="panah" aria-hidden="true">▸</span> {{ $t['tgl'] }} <span class="jumlah-bon">{{ count($t['detail']) }}</span></td>
                            <td><b>{{ $t['nama'] ?? '—' }}</b> <span class="redup">{{ $t['bank'] }} {{ $t['rekening'] }}</span></td>
                            <td>{{ $t['ket'] }}@if ($t['sebagian']) <span class="label kuning" title="Sebagian detail transfer ini sudah direimburse; yang tampil hanya yang belum">sisa</span>@endif</td>
                            <td class="ringkas-gl">{{ collect($t['detail'])->reject(fn ($d) => $d['fee'])->pluck('kode_gl')->filter()->unique()->take(2)->implode(', ') }}</td>
                            <td class="i">{{ $t['no_id'] }} <span class="redup">· {{ $t['lembar'] }}</span></td>
                            <td class="angka"><b>{{ rp($t['total']) }}</b></td>
                        </tr>
                        <tr class="bh"><td></td><td>Transaksi detail</td><td>PIC</td><td>Keterangan</td><td>Kode GL</td><td>ID transaksi</td><td class="angka">Nominal</td></tr>
                        @foreach ($t['detail'] as $d)
                            <tr @class(['b', 'fee' => $d['fee']])><td></td><td></td><td class="p">{{ $d['pic'] }}</td><td>{{ $d['ket'] }}</td>
                                <td>{{ $d['kode_gl'] }}</td><td class="i">{{ $d['id'] }}</td><td class="angka">{{ rp($d['nominal']) }}</td></tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody><tr><td colspan="7" class="redup" style="padding: 20px 14px;">Tidak ada transaksi Kas Harian yang belum direimburse. 🎉</td></tr></tbody>
                @endforelse
            </table>
        </div>
    </form>

    <div class="kartu">
        <h3 style="margin: 0 0 8px;">Riwayat reimburse</h3>
        <table>
            <thead><tr><th>Tanggal reimburse</th><th class="angka">Total</th><th>Isi</th><th>Oleh</th><th>Dicatat</th><th></th></tr></thead>
            <tbody>
                @forelse ($riwayat as $r)
                    <tr>
                        <td>{{ $r->tanggal->translatedFormat('j M Y') }}</td>
                        <td class="angka"><b>{{ rp($r->total) }}</b>@if ($r->target) <span class="redup">/ target {{ rp($r->target) }}</span>@endif</td>
                        <td class="redup">{{ $r->jumlah_transfer }} transfer · {{ $r->jumlah_baris }} detail</td>
                        <td class="redup">{{ $r->user?->email }}</td>
                        <td class="redup">{{ $r->created_at->translatedFormat('j M Y H:i') }}</td>
                        <td><a href="{{ route('kas.reimburse.unduh', $r) }}" class="tombol-edit">⬇ Excel</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="redup">Belum ada reimburse yang dicatat lewat aplikasi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
        (() => {
            const fmt = n => 'Rp ' + new Intl.NumberFormat('id-ID').format(n || 0);
            const angka = v => parseInt(String(v ?? '').replace(/\D/g, ''), 10) || 0;
            const grup = [...document.querySelectorAll('tbody.grup')];
            const target = document.getElementById('target');
            const tombol = document.getElementById('setuju');

            const hitung = () => {
                let total = 0, jumlah = 0, baris = 0;
                grup.forEach(g => {
                    const pilih = g.querySelector('input[type=checkbox]').checked;
                    g.querySelector('tr.t').classList.toggle('dipilih', pilih);
                    if (pilih) { total += +g.dataset.total; jumlah++; baris += +g.dataset.baris; }
                });
                document.getElementById('total-pilih').textContent = fmt(total);
                document.getElementById('jumlah-pilih').textContent = jumlah;
                document.getElementById('baris-pilih').textContent = baris;
                const t = angka(target.value);
                document.getElementById('selisih').textContent = t ? (total <= t ? `kurang ${fmt(t - total)} dari ${fmt(t)}` : `lebih ${fmt(total - t)} dari ${fmt(t)}`) : '';
                tombol.disabled = !jumlah;
            };
            // Pilih otomatis: kombinasi transfer yang jumlahnya PALING MENDEKATI nominal tanpa melebihi (subset-sum).
            // Transfer diproses dari yang paling lama, jadi bila beberapa kombinasi sama dekatnya, yang lebih lama didahulukan.
            const gcd = (a, b) => { while (b) [a, b] = [b, a % b]; return a; };
            const pilihOtomatis = () => {
                const t = angka(target.value);
                const nilai = grup.map(g => +g.dataset.total);
                const pilih = new Array(grup.length).fill(false);
                const unit = nilai.reduce((a, b) => gcd(a, b), 0) || 1;
                const batas = Math.floor(t / unit);
                if (t > 0 && batas <= 3_000_000) {
                    const w = nilai.map(v => v / unit);
                    const capai = new Uint8Array(batas + 1), oleh = new Int32Array(batas + 1).fill(-1);
                    capai[0] = 1;
                    w.forEach((wi, i) => {
                        for (let s = batas; s >= wi; s--) if (!capai[s] && capai[s - wi]) { capai[s] = 1; oleh[s] = i; }
                    });
                    let s = batas;
                    while (s > 0 && !capai[s]) s--;
                    while (s > 0) { const i = oleh[s]; pilih[i] = true; s -= w[i]; }
                } else if (t > 0) {
                    let sisa = t; // terlalu besar untuk dihitung tepat → dari yang paling lama, ambil yang masih muat
                    nilai.forEach((v, i) => { if (v <= sisa) { pilih[i] = true; sisa -= v; } });
                }
                grup.forEach((g, i) => g.querySelector('input[type=checkbox]').checked = pilih[i]);
                hitung();
            };
            target.addEventListener('input', () => {
                const pos = target.value.length - target.selectionStart;
                const d = target.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
                target.value = d ? new Intl.NumberFormat('id-ID').format(+d) : '';
                target.setSelectionRange(target.value.length - pos, target.value.length - pos);
                pilihOtomatis();
            });
            document.getElementById('pilih-semua').addEventListener('click', () => { grup.forEach(g => g.querySelector('input').checked = true); hitung(); });
            document.getElementById('kosongkan').addEventListener('click', () => { grup.forEach(g => g.querySelector('input').checked = false); hitung(); });
            grup.forEach(g => {
                g.querySelector('input[type=checkbox]').addEventListener('change', hitung);
                g.querySelector('tr.t').addEventListener('click', e => {
                    if (e.target.closest('input, a, button') || getSelection().toString()) return;
                    g.classList.toggle('buka');
                });
            });
            document.getElementById('form-reimburse').addEventListener('submit', e => {
                const tgl = document.getElementById('tanggal').value;
                const teks = `Catat ${document.getElementById('jumlah-pilih').textContent} transfer (${document.getElementById('baris-pilih').textContent} transaksi detail) senilai ${document.getElementById('total-pilih').textContent} sebagai SUDAH REIMBURSE tanggal ${tgl}?\n\nTransaksinya tidak bisa diedit/dihapus lagi, dan barisnya ditulis ke lembar "Sudah Reimburse".`;
                if (!confirm(teks)) { e.preventDefault(); return; }
                tombol.disabled = true;
                tombol.textContent = 'Mencatat…';
            });
            hitung();

            // Bagikan file Excel ke WhatsApp (menu berbagi HP); di komputer: unduh lalu lampirkan di WhatsApp.
            document.getElementById('bagikan')?.addEventListener('click', async e => {
                const b = e.currentTarget;
                try {
                    const blob = await (await fetch(b.dataset.url)).blob();
                    const file = new File([blob], b.dataset.nama, {type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
                    if (navigator.canShare?.({files: [file]})) {
                        await navigator.share({files: [file], title: b.dataset.nama, text: b.dataset.teks});
                        return;
                    }
                    const a = Object.assign(document.createElement('a'), {href: URL.createObjectURL(blob), download: b.dataset.nama});
                    a.click();
                    window.open('https://web.whatsapp.com/', '_blank');
                    alert('File Excel sudah diunduh. Lampirkan file itu di chat WhatsApp Web yang baru dibuka.');
                } catch (err) {
                    if (err.name !== 'AbortError') alert('Gagal membagikan: ' + err.message);
                }
            });
        })();
    </script>
@endsection
