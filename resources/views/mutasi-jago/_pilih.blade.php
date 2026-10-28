{{--
    Master dari mutasi Jago (owner 28 Okt 2026) — dipakai Input UJ (kantong 'uj') & Input Kas (kantong 'kas').
    Isian master (tanggal, bank, rekening, nominal, biaya; Kas Harian juga arah) diisi dari transfer bank yang dipilih lalu dikunci;
    server menimpanya lagi dengan data bank (App\Support\MutasiJago::terapkanMaster). Super Admin boleh "di luar bank" dengan alasan.
--}}
@php
    $calonMutasi ??= [];
    // Edit transaksi lama (master bukan dari mutasi): tanpa panel — diedit seperti biasa.
    $aktif = ! ($edit ?? null) || ($mutasiTertaut ?? null);
    $mutasiTertaut ??= null;
    $super = auth()->user()?->isSuperAdmin();
    $lamaId = (int) old('jago_mutasi_id');
    $namaBuku = $kantong === 'uj' ? 'Kas UJ' : 'Kas Harian';
    $namaKantong = \App\Support\MutasiJago::KANTONG[$kantong]['nama'];
@endphp
@if ($aktif)
<style>
    .mutasi-pilih table { width: 100%; font-size: 13px; }
    .mutasi-pilih td, .mutasi-pilih th { padding: 5px 6px; text-align: left; vertical-align: top; }
    .mutasi-pilih td.angka, .mutasi-pilih th.angka { text-align: right; white-space: nowrap; }
    .mutasi-pilih tr.dipilih td { background: var(--aksen-muda); }
    .mutasi-pilih .kecil { font-size: 11.5px; color: var(--redup); }
    .mutasi-terpilih { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 10px 12px; border: 1px solid var(--sukses); border-radius: 8px; background: rgba(76, 195, 138, .1); margin-bottom: 12px; }
    .form-kas input.dari-mutasi { background: var(--otomatis-latar, rgba(124, 92, 255, .12)); border-color: var(--otomatis-garis, #7c5cff); cursor: not-allowed; }
    .biaya-terkunci { pointer-events: none; opacity: .85; }
</style>
<input type="hidden" name="jago_mutasi_id" id="jago_mutasi_id" value="{{ $lamaId ?: '' }}">
<div class="mutasi-terpilih" id="mutasi-terpilih" hidden></div>
@if ($mutasiTertaut)
    {{-- Edit: master tetap terkunci ke transfer bank-nya. --}}
@else
    <div class="kartu mutasi-pilih" id="mutasi-pilih">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
            <h3 style="margin: 0;">🏦 Pilih transfer dari mutasi Jago <span class="redup" style="font-size: 13px; font-weight: normal;">kantong {{ $namaKantong }} · belum ada di {{ $namaBuku }} · 14 hari terakhir</span></h3>
            <a href="{{ route('mutasi-jago.index', ['kantong' => $kantong]) }}" class="redup" style="font-size: 13px;">Mutasi Jago →</a>
        </div>
        <p class="redup" style="margin: 6px 0 8px; font-size: 13px;">Master transaksi (tanggal, bank, rekening, nominal, biaya transfer) diambil dari mutasi bank — pilih transfernya, lalu isi detailnya.
            Transfer yang belum muncul: tunggu unggahan mutasi berikutnya (10.00 · 13.00 · 16.00 · 19.00).</p>
        @if ($calonMutasi)
            <div class="gulir" style="max-height: 320px;">
                <table>
                    <thead><tr><th>Waktu</th><th>{{ $kantong === 'kas' ? 'Dari / ke' : 'Penerima' }}</th><th>Bank · rekening</th><th>Catatan bank</th><th class="angka">Nominal</th><th class="angka">Biaya</th><th data-tanpa-saring></th></tr></thead>
                    <tbody>
                        @foreach ($calonMutasi as $c)
                            <tr data-mutasi="{{ $c['id'] }}">
                                <td style="white-space: nowrap;">{{ \Illuminate\Support\Carbon::parse($c['waktu'])->translatedFormat('d-M') }} <b>{{ $c['jam'] }}</b></td>
                                <td>{{ $c['nama'] }}@if ($kantong === 'kas')<div class="kecil">{{ $c['arah'] === 'masuk' ? '⬇ uang masuk' : '⬆ transfer keluar' }}</div>@endif</td>
                                <td>{{ trim($c['bank'].' '.$c['rekening']) }}</td>
                                <td style="max-width: 320px;">{{ $c['catatan'] }}@if ($c['saran'])<div class="kecil" style="color: #f0c05a;">⚠ {{ $c['saran'] }} — pastikan belum pernah diinput.</div>@endif</td>
                                <td class="angka"><b>{{ number_format($c['nominal'], 0, ',', '.') }}</b></td>
                                <td class="angka">{{ $c['biaya'] ? number_format($c['biaya'], 0, ',', '.') : '–' }}</td>
                                <td><button type="button" class="tombol" data-pakai="{{ $c['id'] }}" style="padding: 3px 12px; font-size: 12.5px;">Pakai</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="pesan">Tidak ada transfer bank yang belum diinput. Bila transfernya sudah terjadi, minta mutasi terbaru diunggah di menu <a href="{{ route('mutasi-jago.index', ['kantong' => $kantong]) }}">Mutasi Jago</a>.</div>
        @endif
        @if ($super)
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--garis); display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <label style="display: flex; gap: 6px; align-items: center; margin: 0;"><input type="checkbox" name="luar_bank" id="luar_bank" value="1" @checked(old('luar_bank'))> Transaksi di luar bank (tunai) — khusus Super Admin</label>
                <input type="text" name="luar_bank_alasan" id="luar_bank_alasan" value="{{ old('luar_bank_alasan') }}" placeholder="alasan, mis. uang dikembalikan tunai, tidak lewat rekening" style="flex: 1; min-width: 260px;" maxlength="300">
            </div>
        @endif
    </div>
@endif
<script>
    // Dijalankan sesudah seluruh form (dan skrip form, mis. aturan biaya transfer) selesai dimuat.
    document.addEventListener('DOMContentLoaded', () => {
        const KANTONG = @json($kantong);
        const CALON = @json(collect($calonMutasi)->keyBy('id'));
        const TERTAUT = @json($mutasiTertaut);
        const SUPER = @json((bool) $super);
        const form = document.getElementById('form-kas');
        const $ = id => document.getElementById(id);
        const fmt = n => new Intl.NumberFormat('id-ID').format(n || 0);
        const tglId = d => new Date(d + 'T00:00:00').toLocaleDateString('id-ID', {day: 'numeric', month: 'short', year: 'numeric'});
        const kunci = [];
        const isi = (id, nilai, kunciIsian = true) => {
            const el = $(id);
            if (!el) return;
            el.value = nilai;
            el.dispatchEvent(new Event('input', {bubbles: true}));
            el.dispatchEvent(new Event('change', {bubbles: true}));
            if (kunciIsian) { el.readOnly = true; el.classList.add('dari-mutasi'); el.title = 'Dari mutasi Jago — tidak bisa diubah'; kunci.push(el); }
        };
        const lepas = () => {
            kunci.splice(0).forEach(el => { el.readOnly = false; el.classList.remove('dari-mutasi'); el.title = ''; });
            document.querySelectorAll('.biaya-terkunci').forEach(el => el.classList.remove('biaya-terkunci'));
            document.querySelectorAll('input[name=arah]').forEach(r => r.disabled = false);
        };
        const terapkan = m => {
            lepas();
            $('jago_mutasi_id').value = TERTAUT ? '' : m.id;
            isi('tanggal', m.waktu);
            isi('bank', m.bank || '');
            isi('no_rek', m.rekening || '');
            if (KANTONG === 'kas') {
                const r = $(m.arah === 'masuk' ? 'arah-masuk' : 'arah-keluar');
                if (r) { r.checked = true; r.dispatchEvent(new Event('change', {bubbles: true})); }
                document.querySelectorAll('input[name=arah]').forEach(x => { if (x !== r) x.disabled = true; });
                // Bunga / pajak bunga: nama & keterangan baku "Bunga" / "Pajak Bunga" (tanpa rekening & bank — memang dari bank langsung).
                if (m.nama_buku) { isi('nama_tujuan', m.nama_buku, false); isi('keterangan', m.nama_buku, false); }
                if ($('nama_tujuan') && !$('nama_tujuan').value) isi('nama_tujuan', m.nama, false);
                if ($('keterangan') && !$('keterangan').value && m.catatan) isi('keterangan', m.catatan, false);
                isi(m.arah === 'masuk' ? 'nominal_masuk' : 'nominal_transfer', fmt(m.nominal));
            } else {
                isi('nominal_transfer', fmt(m.nominal));
            }
            // Biaya transfer mengikuti bank (sesudah aturan otomatis bank tujuan berjalan).
            setTimeout(() => {
                const cb = $('biaya_transfer');
                if (cb && m.arah !== 'masuk') {
                    if (cb.checked !== m.biaya > 0) { cb.checked = m.biaya > 0; cb.dispatchEvent(new Event('change', {bubbles: true})); }
                    cb.closest('label')?.classList.add('biaya-terkunci');
                    if (m.biaya > 0) isi('nominal_biaya', fmt(m.biaya)); else if ($('nominal_biaya')) { $('nominal_biaya').readOnly = true; kunci.push($('nominal_biaya')); }
                }
            }, 0);
            document.querySelectorAll('#mutasi-pilih tr[data-mutasi]').forEach(tr => tr.classList.toggle('dipilih', tr.dataset.mutasi === String(m.id)));
            const kotak = $('mutasi-terpilih');
            kotak.hidden = false;
            kotak.innerHTML = `<span>🏦 <b>Master dari mutasi Jago</b> · ${tglId(m.waktu)} ${m.jam} · ${m.nama} · ${(m.bank || '') + ' ' + (m.rekening || '')} ·
                <b>Rp ${fmt(m.nominal)}</b>${m.biaya ? ' + biaya ' + fmt(m.biaya) : (m.arah === 'masuk' ? ' (uang masuk)' : ' · tanpa biaya')}${m.catatan ? ' · <i>' + m.catatan.replace(/[<>&]/g, '') + '</i>' : ''}</span>`
                + (TERTAUT ? '<span class="redup" style="font-size: 12px;">— master terkunci ke transfer bank ini</span>' : '<button type="button" class="tombol polos" id="ganti-mutasi" style="padding: 3px 10px; font-size: 12px; margin-left: auto;">Ganti</button>');
            $('ganti-mutasi')?.addEventListener('click', () => { lepas(); $('jago_mutasi_id').value = ''; kotak.hidden = true; document.querySelectorAll('#mutasi-pilih tr.dipilih').forEach(tr => tr.classList.remove('dipilih')); $('mutasi-pilih')?.scrollIntoView({block: 'center'}); });
        };
        document.querySelectorAll('[data-pakai]').forEach(b => b.addEventListener('click', () => terapkan(CALON[b.dataset.pakai])));
        if (TERTAUT) terapkan(TERTAUT);
        else if (CALON[$('jago_mutasi_id').value]) terapkan(CALON[$('jago_mutasi_id').value]);
        else $('jago_mutasi_id').value = '';

        // Input baru tanpa transfer bank tidak bisa disimpan (server juga menolak), kecuali Super Admin "di luar bank" dengan alasan.
        form?.addEventListener('submit', e => {
            if (TERTAUT || $('jago_mutasi_id').value) return;
            if (SUPER && $('luar_bank')?.checked && ($('luar_bank_alasan')?.value || '').trim().length >= 10) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            alert(SUPER ? 'Pilih transfer dari daftar mutasi Jago, atau centang "Transaksi di luar bank" dan tulis alasannya (min. 10 karakter).'
                : 'Pilih dulu transfernya dari daftar mutasi Jago di atas form — master transaksi diambil dari mutasi bank.');
            $('mutasi-pilih')?.scrollIntoView({block: 'center'});
        }, true);
    });
</script>
@endif
