@extends('layouts.app', ['judul' => 'Pengajuan UJ'])

@section('lebar', '1440px')

@section('isi')
    <style>
        table.kas tr.b.terealisasi td { color: var(--sukses); }
        table.kas tr.b.dialihkan td { color: #dcb3ff; }
        table.kas tr.b.batal td { color: var(--redup); text-decoration: line-through; }
        table.kas tr.b.berbeda td { color: #f0c05a; }
        table.kas tr.realisasi td { padding-top: 0; font-size: 12px; color: var(--teks); }
        table.kas tbody.grup:not(.buka) tr.realisasi { display: none; }
        .info-beda { border-left: 3px solid #e0a526; background: #1d1810; border-radius: 6px; padding: 6px 10px; line-height: 1.5; }
        .info-beda.dialihkan { border-left-color: #c77dff; background: #1d1426; }
        .info-beda del { color: var(--redup); }
        .label.ungu { background: #3b2350; color: #dcb3ff; }
        input.pilih-real, input.pilih-semua-pj { width: 17px; height: 17px; cursor: pointer; vertical-align: middle; }
        table.kas tr.b.dipilih td { background: rgba(76, 195, 138, .14); }
        .bar-realisasi { position: sticky; bottom: 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; padding: 12px 16px; margin-top: 12px;
            background: var(--kartu); border: 1px solid var(--aksen); border-radius: 10px; z-index: 2; }
        .bar-realisasi[hidden] { display: none; }
        td.sel-transfer { font-size: 12px; min-width: 220px; }
        td.sel-transfer .tf-baris { margin: 3px 0; white-space: nowrap; }
        td.sel-transfer .lepas-tf { background: none; border: none; color: var(--redup); cursor: pointer; padding: 0 3px; font-size: 12px; }
        td.sel-transfer .lepas-tf:hover { color: var(--aksen); }
        td.sel-transfer .pilih-transfer { margin-top: 4px; }
        dialog#dialog-transfer { width: min(1100px, 96vw); max-height: 86vh; padding: 0; border: 1px solid var(--garis); border-radius: 12px; background: var(--kartu); color: var(--teks); }
        dialog#dialog-transfer::backdrop { background: rgba(0, 0, 0, .6); }
        #dialog-transfer .kepala-dt { padding: 12px 16px; border-bottom: 1px solid var(--garis); display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        #dialog-transfer .kepala-dt input { flex: 1; min-width: 220px; padding: 6px 9px; border-radius: 6px; border: 2px solid #4a5160; font-size: 13px; }
        #dialog-transfer .isi-dt { max-height: 58vh; overflow: auto; }
        #dialog-transfer table { width: 100%; border-collapse: collapse; font-size: 13px; }
        #dialog-transfer th { position: sticky; top: 0; background: var(--kartu-2); text-align: left; padding: 6px 8px; font-size: 12px; color: var(--redup); }
        #dialog-transfer td { padding: 5px 8px; border-top: 1px solid var(--garis); cursor: pointer; }
        #dialog-transfer td.angka, #dialog-transfer th.angka { text-align: right; font-variant-numeric: tabular-nums; }
        #dialog-transfer tr.dipilih td { background: rgba(76, 195, 138, .14); }
        #dialog-transfer tr.terpasang td { color: var(--redup); }
        #dialog-transfer .kaki-dt { padding: 10px 16px; border-top: 1px solid var(--garis); display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .saring-pj { padding: 10px 14px; margin-bottom: 12px; }
        .saring-pj .kolom-saring { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px 12px; }
        .saring-pj .kolom-saring label { display: flex; flex-direction: column; gap: 3px; margin: 0; min-width: 0; }
        .saring-pj .kolom-saring label span { font-size: 12px; font-weight: 700; color: var(--teks); }
        .saring-pj .kolom-saring input { box-sizing: border-box; width: 100%; min-width: 0; padding: 5px 8px; border-radius: 6px; font-size: 13px; border: 2px solid #4a5160; }
        .saring-pj .kolom-saring input:focus, .saring-pj .kolom-saring input.aktif { border-color: var(--aksen); outline: none; }
        .saring-pj .kaki-saring { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-top: 8px; font-size: 13px; }
        .saring-pj .kaki-saring .tombol { padding: 4px 10px; font-size: 12px; margin-left: auto; }
        .saring-pj .pilih-tampil { display: flex; align-items: center; gap: 6px; margin: 0; cursor: pointer; }
        table.kas tr.b.tersaring, table.kas tr.realisasi.tersaring, table.kas tbody.grup.tersaring { display: none; }
    </style>
    <div class="ringkas">
        <div class="kartu"><span class="redup">Menunggu realisasi</span><b>{{ rp((int) $menunggu->s) }}</b></div>
        <div class="kartu"><span class="redup">Detail menunggu</span><b>{{ (int) $menunggu->n }}</b></div>
        <div class="kartu"><span class="redup">Pengajuan terbuka</span><b>{{ $semua->whereIn('status', ['diajukan', 'sebagian'])->count() }}</b></div>
        <a class="kartu" href="{{ route('pengajuan-uj.daftar', ['beda' => 1]) }}" style="text-decoration: none; color: inherit;">
            <span class="redup">Realisasi tidak sesuai</span>
            <b>{{ $detailBeda->count() }}</b>
            <span class="redup" style="font-size: 12px;">{{ $detailBeda->where('jenis_realisasi', 'dialihkan')->count() }} dialihkan · {{ $detailBeda->where('jenis_realisasi', 'penyesuaian')->count() }} penyesuaian</span>
        </a>
    </div>

    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="redup">Status:</span>
        <a href="{{ route('pengajuan-uj.daftar') }}" @class(['chip', 'aktif' => ! $status && ! $beda])>Terbuka + terbaru</a>
        @foreach (\App\Models\UjPengajuan::STATUS as $k => $l)
            @if ($n = $semua->where('status', $k)->count())
                <a href="{{ route('pengajuan-uj.daftar', ['status' => $k]) }}" @class(['chip', 'aktif' => $status === $k])>{{ $l }} · {{ $n }}</a>
            @endif
        @endforeach
        @if ($detailBeda->count())
            <a href="{{ route('pengajuan-uj.daftar', ['beda' => 1]) }}" @class(['chip', 'aktif' => $beda])>⚠ Tidak sesuai / dialihkan · {{ $detailBeda->count() }}</a>
        @endif
        @if ($pengajuan->isNotEmpty())
            <span style="margin-left: auto; display: flex; gap: 6px;">
                <button type="button" class="tombol polos" id="buka-semua-pj" style="padding: 6px 12px; font-size: 13px;" title="Buka rincian semua pengajuan">▾ Expand all</button>
                <button type="button" class="tombol polos" id="tutup-semua-pj" style="padding: 6px 12px; font-size: 13px;" title="Tutup rincian semua pengajuan">▸ Collapse all</button>
            </span>
        @endif
        <a href="{{ route('pengajuan-uj.buat') }}" class="tombol" style="{{ $pengajuan->isNotEmpty() ? '' : 'margin-left: auto; ' }}padding: 8px 14px;">+ Ajukan uang jalan</a>
        @if ($bolehRealisasi)<a href="{{ route('uj.input') }}" class="tombol polos" style="padding: 8px 14px;">Realisasikan di Input UJ →</a>@endif
    </div>

    @if ($pengajuan->isNotEmpty())
        {{-- Saringan per kolom detail transaksi (seperti filter Excel): beberapa kata dalam satu kotak = semuanya harus ada. --}}
        <div class="kartu saring-pj" id="saring-pj">
            <div class="kolom-saring">
                @foreach (['mobil' => 'No Mobil', 'nama' => 'Driver', 'nominal' => 'Nominal', 'kategori' => 'Kategori', 'do' => 'No DO', 'status' => 'Status', 'kode' => 'Kode'] as $k => $l)
                    <label><span>{{ $l }}</span><input type="search" data-saring="{{ $k }}" autocomplete="off" placeholder="saring…" @if ($k === 'status') list="saran-status-pj" @endif></label>
                @endforeach
            </div>
            <datalist id="saran-status-pj"><option value="Menunggu"><option value="Terealisasi"><option value="Terealisasi (berbeda)"><option value="Dialihkan"><option value="Dibatalkan"></datalist>
            <div class="kaki-saring">
                <span id="info-saring" class="redup"></span>
                @if ($bolehRealisasi)<label class="pilih-tampil"><input type="checkbox" id="pilih-tampil"> Centang semua transaksi menunggu yang tampil</label>@endif
                <button type="button" class="tombol polos" id="hapus-saring">Hapus saringan</button>
            </div>
        </div>
    @endif
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Kode</th><th>Tanggal pengajuan</th><th>Driver</th><th>Kategori</th><th class="angka">Total</th><th>Status</th><th>Transfer (Kas Harian)</th><th>Diajukan</th><th></th></tr></thead>
            @forelse ($pengajuan as $p)
                @php($real = $p->detail->whereIn('status', ['terealisasi', 'dialihkan']))
                @php($nBeda = $p->detail->filter(fn ($d) => $d->berbeda())->count())
                <tbody @class(['grup', 'buka' => $beda || in_array($p->status, ['diajukan', 'sebagian'], true)])>
                    <tr class="t ada-bon">
                        <td>@if ($bolehRealisasi && $p->detail->where('status', 'menunggu')->isNotEmpty())<input type="checkbox" class="pilih-semua-pj" data-pj="{{ $p->id }}" title="Pilih semua detail {{ $p->kode() }} yang masih menunggu"> @endif<span class="panah">▸</span> <b>{{ $p->kode() }}</b> <span class="jumlah-bon">{{ $p->detail->count() }}</span></td>
                        <td>{{ $p->tanggal->translatedFormat('j M Y') }}</td>
                        <td><b>{{ $p->detail->pluck('nama')->filter()->unique()->take(4)->implode(', ') }}</b>@if ($p->detail->pluck('nama')->filter()->unique()->count() > 4) …@endif</td>
                        <td class="ringkas-gl">{{ $p->detail->pluck('kategori')->unique()->take(3)->implode(', ') }}</td>
                        <td class="angka"><b>{{ rp($p->nominal) }}</b></td>
                        <td><span @class(['label', 'kuning' => $p->status === 'sebagian', 'hijau' => $p->status === 'selesai', 'merah' => $p->status === 'batal'])>{{ \App\Models\UjPengajuan::STATUS[$p->status] }}</span>
                            @if ($nBeda)<span class="label kuning" title="Realisasi tidak sama dengan pengajuan">⚠ {{ $nBeda }} tidak sesuai</span>@endif
                            @if ($real->count())<span class="redup" style="font-size: 12px;">{{ $real->count() }}/{{ $p->detail->count() }} · {{ rp($real->sum('nominal')) }}</span>@endif</td>
                        @php($totalTf = (int) $p->transfer->sum('nominal'))
                        <td class="sel-transfer">
                            @if ($p->transfer->isEmpty())
                                <span class="label">Belum ditransfer</span>
                            @else
                                <span @class(['label', 'hijau' => $totalTf >= $p->nominal, 'kuning' => $totalTf < $p->nominal])>{{ $totalTf >= $p->nominal ? 'Sudah ditransfer' : 'Kurang '.rp($p->nominal - $totalTf) }}</span>
                                @if ($totalTf > $p->nominal)<span class="redup" style="font-size: 11px;">lebih {{ rp($totalTf - $p->nominal) }}</span>@endif
                                @foreach ($p->transfer as $tf)
                                    @php($kini = $kasKini[$tf->kas_no_id] ?? null)
                                    @php($berubah = ! $kini || ! $kini->tanggal->isSameDay($tf->kas_tanggal) || (int) $kini->kredit !== (int) $tf->nominal)
                                    <div class="tf-baris" title="{{ $tf->nama_tujuan }} · {{ $tf->keterangan }}{{ $tf->user ? ' · ditautkan '.($tf->user->name ?? $tf->user->email) : '' }}">
                                        {{ $tf->kas_tanggal->translatedFormat('j M') }} · <b>{{ rp($tf->nominal) }}</b> · <span class="redup">{{ $tf->idKas() }}</span>
                                        @if ($berubah)<span class="label merah" title="{{ $kini ? 'Transaksi Kas Harian ini sekarang '.$kini->tanggal->translatedFormat('j M Y').' '.rp((int) $kini->kredit) : 'Transaksi Kas Harian ini tidak ditemukan lagi (dihapus / NO ID berubah)' }}">⚠ {{ $kini ? 'berubah' : 'hilang' }}</span>@endif
                                        <form method="POST" action="{{ route('pengajuan-uj.transfer-lepas', $tf) }}" style="display: inline;" onsubmit="return confirm('Lepas tautan transfer {{ $tf->idKas() }} dari {{ $p->kode() }}?')">
                                            @csrf @method('DELETE')<button type="submit" class="lepas-tf" title="Lepas tautan">✕</button></form>
                                    </div>
                                @endforeach
                            @endif
                            <button type="button" class="tombol-edit pilih-transfer" data-pj="{{ $p->id }}" data-kode="{{ $p->kode() }}" data-nominal="{{ (int) $p->nominal }}"
                                data-tgl="{{ $p->tanggal->translatedFormat('j M Y') }}" data-ditransfer="{{ $totalTf }}"
                                data-url="{{ route('pengajuan-uj.transfer-kandidat', $p) }}" data-simpan="{{ route('pengajuan-uj.transfer-tautkan', $p) }}">🔗 {{ $p->transfer->isEmpty() ? 'Pilih transfer' : 'Tambah' }}</button>
                        </td>
                        <td class="redup" style="font-size: 12px;">{{ $p->user?->name ?? $p->user?->email }} · {{ $p->created_at->translatedFormat('j M H:i') }}</td>
                        <td style="white-space: nowrap;">
                            @if ($p->status === 'diajukan')<a href="{{ route('pengajuan-uj.ubah', $p) }}" class="tombol-edit">Edit</a>@endif
                            @if (in_array($p->status, ['diajukan', 'sebagian'], true))
                                <form method="POST" action="{{ route('pengajuan-uj.batal', $p) }}" style="display: inline;" onsubmit="return confirm('Batalkan detail {{ $p->kode() }} yang masih menunggu? Detail yang sudah terealisasi tidak berubah.')">
                                    @csrf<button class="tombol-hapus" type="submit">Batalkan</button></form>
                            @endif
                        </td>
                    </tr>
                    <tr class="bh"><td>Detail</td><td>No Mobil</td><td>Driver</td><td class="angka">Nominal</td><td>Kategori</td><td>No DO</td><td></td><td></td><td></td></tr>
                    @foreach ($p->detail as $d)
                        @php($teksStatus = $d->jenis_realisasi === 'penyesuaian' ? 'Terealisasi (berbeda)' : ['menunggu' => 'Menunggu', 'terealisasi' => 'Terealisasi', 'dialihkan' => 'Dialihkan', 'batal' => 'Dibatalkan'][$d->status] ?? $d->status)
                        <tr @class(['b', $d->status, 'berbeda' => $d->jenis_realisasi === 'penyesuaian']) data-detail
                            data-kode="{{ $p->kode() }}" data-nama="{{ $d->nama }}" data-ket="{{ $d->keterangan }}" data-kategori="{{ $d->kategori }}"
                            data-mobil="{{ $d->no_mobil }}" data-do="{{ $d->no_do }}" data-nominal="{{ (int) $d->nominal }} {{ number_format((int) $d->nominal, 0, ',', '.') }}"
                            data-status="{{ $teksStatus }}"
                            title="{{ $teksStatus }}{{ $d->id_uj ? ' · '.$d->id_uj : '' }}{{ $d->realisasi_pada ? ' · '.$d->realisasi_pada->translatedFormat('j M') : '' }} · {{ $d->keterangan }}{{ $d->temuan ? ' · FLAG dikonfirmasi: '.$d->konfirmasi : '' }}">
                            <td>@if ($bolehRealisasi && $d->status === 'menunggu')<input type="checkbox" class="pilih-real" value="{{ $d->id }}" data-pj="{{ $p->id }}" data-nominal="{{ (int) $d->nominal }}"
                                data-nama="{{ $d->nama }}" title="Pilih untuk direalisasikan bersama (satu transfer)">@endif</td>
                            <td>{{ $d->no_mobil ?: '–' }}</td>
                            <td>{{ $d->nama }}</td>
                            <td class="angka">{{ rp($d->nominal) }}</td>
                            <td>{{ $d->kategori }}</td>
                            <td>{{ $d->no_do ?: '–' }}</td>
                            <td></td><td></td><td></td>
                        </tr>
                        @if ($d->berbeda() && $d->realisasi)
                            @php($r = $d->realisasi)
                            <tr class="realisasi"><td></td><td colspan="8">
                                <div @class(['info-beda', 'dialihkan' => $d->jenis_realisasi === 'dialihkan'])>
                                    <span @class(['label', 'ungu' => $d->jenis_realisasi === 'dialihkan', 'kuning' => $d->jenis_realisasi !== 'dialihkan'])>{{ $d->jenis_realisasi === 'dialihkan' ? '↪ Dialihkan' : '≠ Penyesuaian' }}</span>
                                    <b>Realisasi {{ $r['id_uj'] ?? $d->id_uj }}:</b> {{ $r['keterangan'] ?? '' }} · {{ $r['kategori'] ?? '' }}@if (! empty($r['no_mobil'])) · {{ $r['no_mobil'] }}@endif @if (! empty($r['no_do'])) · DO {{ $r['no_do'] }}@endif · <b>{{ rp((int) ($r['nominal'] ?? 0)) }}</b>
                                    @if ((int) ($r['nominal'] ?? 0) !== (int) $d->nominal)<span class="redup">(selisih {{ ((int) $r['nominal'] - (int) $d->nominal) > 0 ? '+' : '−' }}{{ rp(abs((int) $r['nominal'] - (int) $d->nominal)) }})</span>@endif
                                    <br>Perbedaan: @foreach ($r['beda'] ?? [] as $b){{ $b['kolom'] }} <del>{{ $b['lama'] }}</del> → <b>{{ $b['baru'] }}</b>@if (! $loop->last); @endif @endforeach
                                    <br>Alasan: <i>{{ $d->alasan }}</i>@if ($d->jenis_realisasi === 'dialihkan' && $d->no_do) <span class="redup">· DO {{ $d->no_do }} tetap dihitung sudah dibiayai</span>@endif
                                </div>
                            </td></tr>
                        @endif
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="9" class="redup" style="padding: 20px 14px;">{{ $beda ? 'Belum ada realisasi yang tidak sesuai dengan pengajuannya.' : 'Belum ada pengajuan uang jalan.' }}</td></tr></tbody>
            @endforelse
        </table>
    </div>
    @if ($bolehRealisasi)
        {{-- Pilih beberapa detail (boleh lintas pengajuan & driver) → satu transfer: Input UJ terisi semua detail itu, admin tinggal isi rekening penerima. --}}
        <div class="bar-realisasi" id="bar-realisasi" hidden>
            <b id="jumlah-pilih">0 transaksi dipilih</b>
            <span class="redup" id="ringkas-pilih"></span>
            <span style="margin-left: auto; display: flex; gap: 8px;">
                <button type="button" class="tombol polos" id="batal-pilih">Batal pilih</button>
                <a href="#" class="tombol" id="realisasi-pilih">➜ Realisasikan transaksi terpilih</a>
            </span>
        </div>
    @endif
    {{-- Pilih transfer Kas Harian yang membiayai sebuah pengajuan. --}}
    <dialog id="dialog-transfer">
        <form method="POST" id="form-transfer">
            @csrf
            <div class="kepala-dt">
                <b id="judul-dt">Pilih transfer Kas Harian</b>
                <input type="search" id="cari-dt" placeholder="Cari keterangan, tujuan, NO ID atau nominal (kosong = sekitar tanggal pengajuan)" autocomplete="off">
                <button type="button" class="tombol polos" id="tutup-dt">Tutup</button>
            </div>
            <div class="isi-dt"><table>
                <thead><tr><th style="width: 30px;"></th><th>Tanggal</th><th>ID Kas Harian</th><th>Tujuan</th><th>Keterangan</th><th class="angka">Nominal</th><th>Sudah ditautkan ke</th></tr></thead>
                <tbody id="daftar-dt"></tbody>
            </table></div>
            <div class="kaki-dt">
                <span id="info-dt" class="redup"></span>
                <button type="submit" class="tombol" id="simpan-dt" style="margin-left: auto;" disabled>🔗 Tautkan transfer terpilih</button>
            </div>
        </form>
    </dialog>
    <script>
        (() => {
            const dlg = document.getElementById('dialog-transfer');
            if (!dlg) return;
            const fmtRp = n => 'Rp ' + new Intl.NumberFormat('id-ID').format(n || 0);
            const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const daftar = document.getElementById('daftar-dt');
            const cari = document.getElementById('cari-dt');
            const info = document.getElementById('info-dt');
            const simpan = document.getElementById('simpan-dt');
            let aktif = null, data = [], jeda = null;
            const hitung = () => {
                const pilih = [...daftar.querySelectorAll('input:checked')].map(c => data.find(t => t.no_id === +c.value));
                daftar.querySelectorAll('tr').forEach(tr => tr.classList.toggle('dipilih', !!tr.querySelector('input:checked')));
                const total = pilih.reduce((s, t) => s + t.nominal, 0) + aktif.ditransfer;
                info.textContent = `${pilih.length} transfer dipilih · ${fmtRp(pilih.reduce((s, t) => s + t.nominal, 0))} — pengajuan ${fmtRp(aktif.nominal)}`
                    + (aktif.ditransfer ? `, sudah tertaut ${fmtRp(aktif.ditransfer)}` : '')
                    + (pilih.length ? (total === aktif.nominal ? ' · ✓ pas' : total < aktif.nominal ? ` · kurang ${fmtRp(aktif.nominal - total)}` : ` · lebih ${fmtRp(total - aktif.nominal)}`) : '');
                simpan.disabled = !pilih.length;
            };
            const muat = async () => {
                daftar.innerHTML = '<tr><td colspan="7" class="redup">Memuat…</td></tr>';
                const q = cari.value.trim();
                data = (await (await fetch(aktif.url + (q ? '?q=' + encodeURIComponent(q) : ''), {headers: {Accept: 'application/json'}})).json()).transfer;
                daftar.innerHTML = data.length ? data.map(t => `<tr class="${t.terpasang ? 'terpasang' : ''}">
                    <td>${t.terpasang ? '✓' : `<input type="checkbox" name="no_id[]" value="${t.no_id}">`}</td>
                    <td>${esc(t.tgl)}</td><td>${esc(t.id_kas)}</td><td>${esc(t.nama || '')}${t.bank ? ' <span class="redup">· ' + esc(t.bank) + '</span>' : ''}</td>
                    <td>${esc(t.keterangan || '')}</td><td class="angka"><b>${fmtRp(t.nominal).replace('Rp ', '')}</b></td>
                    <td class="redup">${esc(t.dipakai.join(', '))}</td></tr>`).join('')
                    : `<tr><td colspan="7" class="redup">${q ? 'Tidak ada transaksi Kas Harian yang cocok.' : 'Tidak ada transaksi keluar Kas Harian di sekitar tanggal pengajuan — coba cari.'}</td></tr>`;
                daftar.querySelectorAll('tr').forEach(tr => tr.addEventListener('click', e => {
                    const c = tr.querySelector('input'); if (!c) return;
                    if (!e.target.matches('input')) c.checked = !c.checked;
                    hitung();
                }));
                hitung();
            };
            document.querySelectorAll('button.pilih-transfer').forEach(b => b.addEventListener('click', e => {
                e.stopPropagation();
                aktif = {url: b.dataset.url, nominal: +b.dataset.nominal, ditransfer: +b.dataset.ditransfer};
                document.getElementById('form-transfer').action = b.dataset.simpan;
                document.getElementById('judul-dt').textContent = `Transfer Kas Harian untuk ${b.dataset.kode} (${fmtRp(+b.dataset.nominal)}, diajukan ${b.dataset.tgl})`;
                cari.value = '';
                dlg.showModal();
                muat();
            }));
            cari.addEventListener('input', () => { clearTimeout(jeda); jeda = setTimeout(muat, 350); });
            cari.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(jeda); muat(); } });
            document.getElementById('tutup-dt').addEventListener('click', () => dlg.close());
        })();
    </script>
    <script>
        (() => {
            document.querySelectorAll('tbody.grup tr.t').forEach(tr => tr.addEventListener('click', e => {
                if (e.target.closest('button, a, form, input')) return;
                tr.parentElement.classList.toggle('buka');
            }));
            // Expand / collapse all: semua pengajuan yang sedang tampil (hasil saringan).
            const grupTampil = () => [...document.querySelectorAll('tbody.grup')].filter(tb => !tb.classList.contains('tersaring'));
            document.getElementById('buka-semua-pj')?.addEventListener('click', () => grupTampil().forEach(tb => tb.classList.add('buka')));
            document.getElementById('tutup-semua-pj')?.addEventListener('click', () => grupTampil().forEach(tb => tb.classList.remove('buka')));
            const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);

            // ===== Saringan per kolom detail (seperti filter Excel) =====
            const isianSaring = [...document.querySelectorAll('#saring-pj input[data-saring]')];
            const detailRows = [...document.querySelectorAll('tr[data-detail]')];
            const tampak = tr => !tr.classList.contains('tersaring');
            let setelahSaring = () => {};
            const saring = () => {
                const aturan = isianSaring.map(i => [i.dataset.saring, i.value.trim().toLowerCase()]).filter(([, v]) => v);
                isianSaring.forEach(i => i.classList.toggle('aktif', !!i.value.trim()));
                detailRows.forEach(tr => {
                    const cocok = aturan.every(([k, v]) => { const isi = (tr.dataset[k] || '').toLowerCase(); return v.split(/\s+/).every(w => isi.includes(w)); });
                    tr.classList.toggle('tersaring', !cocok);
                    // Catatan realisasi berbeda (baris tepat di bawahnya) ikut disembunyikan.
                    const ikut = tr.nextElementSibling;
                    if (ikut?.classList.contains('realisasi')) ikut.classList.toggle('tersaring', !cocok);
                });
                document.querySelectorAll('tbody.grup').forEach(tb => {
                    const ada = [...tb.querySelectorAll('tr[data-detail]')].some(tampak);
                    tb.classList.toggle('tersaring', aturan.length > 0 && !ada);
                    if (aturan.length > 0 && ada) tb.classList.add('buka'); // pengajuan yang cocok dibuka (masih bisa ditutup)
                });
                const terlihat = detailRows.filter(tampak);
                const info = document.getElementById('info-saring');
                if (info) info.textContent = aturan.length
                    ? `${terlihat.length} dari ${detailRows.length} detail tampil · Rp ${fmt(terlihat.reduce((s, tr) => s + (parseInt(tr.dataset.nominal, 10) || 0), 0))}`
                    : `${detailRows.length} detail · ketik di kotak saringan untuk menyaring`;
                setelahSaring();
            };
            isianSaring.forEach(i => i.addEventListener('input', saring));
            document.getElementById('hapus-saring')?.addEventListener('click', () => { isianSaring.forEach(i => i.value = ''); saring(); });
            saring();

            const bar = document.getElementById('bar-realisasi');
            if (!bar) return;
            const semua = [...document.querySelectorAll('input.pilih-real')];
            const terlihat = c => !c.closest('tr').classList.contains('tersaring');
            const dasar = @json(route('uj.input'));
            const hitung = () => {
                const pilih = semua.filter(c => c.checked);
                semua.forEach(c => c.closest('tr').classList.toggle('dipilih', c.checked));
                document.querySelectorAll('input.pilih-semua-pj').forEach(m => {
                    const anak = semua.filter(c => c.dataset.pj === m.dataset.pj && terlihat(c));
                    m.checked = anak.length > 0 && anak.every(c => c.checked); m.indeterminate = !m.checked && anak.some(c => c.checked);
                });
                bar.hidden = !pilih.length;
                const driver = [...new Set(pilih.map(c => c.dataset.nama).filter(Boolean))];
                document.getElementById('jumlah-pilih').textContent = `${pilih.length} transaksi dipilih · Rp ${fmt(pilih.reduce((s, c) => s + +c.dataset.nominal, 0))}`;
                document.getElementById('ringkas-pilih').textContent = driver.length ? `driver: ${driver.join(', ')}` : '';
                const tombol = document.getElementById('realisasi-pilih');
                tombol.href = dasar + '?pengajuan=' + pilih.map(c => c.value).join(',');
                // Semua transaksi terpilih dibayar dalam satu kali transfer ke satu rekening penerima.
                tombol.textContent = `➜ Realisasikan ${pilih.length} transaksi dalam 1 kali transfer`;
                const tampil = semua.filter(terlihat), semuaTampil = document.getElementById('pilih-tampil');
                if (semuaTampil) { semuaTampil.checked = tampil.length > 0 && tampil.every(c => c.checked); semuaTampil.indeterminate = !semuaTampil.checked && tampil.some(c => c.checked); }
            };
            setelahSaring = () => hitung();
            // Centang semua transaksi menunggu yang sedang tampil (hasil saringan).
            document.getElementById('pilih-tampil')?.addEventListener('change', e => { semua.filter(terlihat).forEach(c => c.checked = e.target.checked); hitung(); });
            semua.forEach(c => c.addEventListener('change', hitung));
            document.querySelectorAll('input.pilih-semua-pj').forEach(m => m.addEventListener('change', () => {
                semua.filter(c => c.dataset.pj === m.dataset.pj && terlihat(c)).forEach(c => c.checked = m.checked); // hanya yang tampil
                hitung();
            }));
            document.getElementById('batal-pilih').addEventListener('click', () => { semua.forEach(c => c.checked = false); hitung(); });
            hitung();
        })();
    </script>
@endsection
