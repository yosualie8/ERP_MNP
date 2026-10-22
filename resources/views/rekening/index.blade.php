@extends('layouts.app', ['judul' => 'Daftar Rekening'])

@section('lebar', '1500px')

@section('isi')
    <style>
        .pil-tampil { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .pil-tampil a { padding: 5px 12px; border-radius: 999px; border: 1px solid var(--garis-kuat); color: var(--teks); text-decoration: none; font-size: 13px; }
        .pil-tampil a.aktif { background: var(--aksen); border-color: var(--aksen); color: #fff; }
        .form-tambah { display: grid; grid-template-columns: 170px 150px 1fr 1fr auto; gap: 8px; align-items: end; }
        .form-tambah label { font-size: 12px; margin: 0 0 3px; display: block; }
        .form-tambah input { width: 100%; box-sizing: border-box; }
        @media (max-width: 900px) { .form-tambah { grid-template-columns: 1fr; } }
        table.kas td.daftar-nama { font-size: 12px; min-width: 160px; max-width: 280px; white-space: normal; }
        #dialog-rek { border: 1px solid var(--garis); border-radius: 12px; background: var(--kartu); color: var(--teks); padding: 18px; width: min(480px, 92vw); }
        #dialog-rek::backdrop { background: rgba(0, 0, 0, .55); }
        #dialog-rek input { width: 100%; box-sizing: border-box; padding: 8px 10px; font-size: 14px; }
        #dialog-rek label { display: block; margin: 10px 0 4px; font-size: 13px; }
        .saran-nama { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .saran-nama button { font-size: 12px; padding: 3px 9px; border-radius: 999px; border: 1px solid var(--garis-kuat); background: var(--kartu-2); color: var(--teks); cursor: pointer; }
    </style>

    <p class="redup" style="margin-top: 0;">Satu nomor rekening = satu nama <b>pemilik</b>, dipakai bersama Input Kas & Input UJ (saran nama/rekening) dan tampilan Kas UJ.
        Nama pemilik diambil dari Nama Rekening di Data Karyawan, lalu nama yang paling sering ditulis di Kas Harian. Kas UJ tidak punya kolom penerima
        (kolom Nama = sopir), jadi rekening yang hanya ada di Kas UJ dan dipakai banyak sopir <b>belum ada namanya</b> — isi di sini.</p>

    @if ($errors->any())
        <div class="pesan galat">{{ $errors->first() }}</div>
    @endif

    <div class="pil-tampil">
        @foreach (['semua' => 'Semua', 'belum' => '⚠ Belum ada nama pemilik', 'cek' => 'Perlu dicek (nama dari Kas UJ)', 'beda' => 'Tulisan berbeda di Kas Harian'] as $k => $l)
            <a href="{{ route('rekening.index', $k === 'semua' ? [] : ['tampil' => $k]) }}" @class(['aktif' => $tampil === $k])>{{ $l }} · {{ $jumlah[$k] }}</a>
        @endforeach
    </div>

    <details class="kartu" @if ($errors->has('no_rekening')) open @endif>
        <summary style="cursor: pointer; font-weight: 600;">+ Tambah rekening</summary>
        <form method="POST" action="{{ route('rekening.simpan', $tampil !== 'semua' ? ['tampil' => $tampil] : []) }}" class="form-tambah" style="margin-top: 10px;">
            @csrf
            <div><label for="t-no">No rekening</label><input id="t-no" name="no_rekening" value="{{ old('no_rekening') }}" inputmode="numeric" required autocomplete="off"></div>
            <div class="isian-saran"><label for="t-bank">Bank / e-wallet</label><input id="t-bank" name="bank" value="{{ old('bank') }}" autocomplete="off"></div>
            <div><label for="t-nama">Nama pemilik</label><input id="t-nama" name="nama" value="{{ old('nama') }}" required maxlength="150" autocomplete="off"></div>
            <div><label for="t-cat">Catatan</label><input id="t-cat" name="catatan" value="{{ old('catatan') }}" maxlength="300" autocomplete="off"></div>
            <div><button type="submit" class="tombol">Simpan</button></div>
        </form>
    </details>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Status</th><th>No Rekening</th><th>Bank</th><th>Nama pemilik</th><th>Sumber</th><th class="angka">Kas Harian</th><th>Tulisan lain di Kas Harian</th>
                <th class="angka">Kas UJ</th><th>Dipakai untuk sopir (Kas UJ)</th><th>Data Karyawan</th><th>Catatan</th><th data-tanpa-saring></th></tr></thead>
            <tbody>
                @forelse ($baris as $b)
                    @php($r = $b->r)
                    <tr>
                        <td>@if ($b->status === 'belum')<span class="label merah">Belum ada nama</span>@elseif ($b->status === 'cek')<span class="label kuning">Perlu dicek</span>@else<span class="label hijau">OK</span>@endif</td>
                        <td style="white-space: nowrap;"><b>{{ $r->no_rekening }}</b></td>
                        <td>{{ $r->bank }}</td>
                        <td><b>{{ $r->nama ?? '—' }}</b></td>
                        <td class="redup" style="font-size: 12px;">{{ \App\Models\Rekening::SUMBER[$r->sumber] ?? $r->sumber }}@if ($r->sumber === 'admin' && $r->user)<div>{{ $r->user->name }}</div>@endif</td>
                        <td class="angka" data-n="{{ $b->kas_n }}">{{ $b->kas_n ?: '' }}@if ($b->kas_n)<div class="redup" style="font-size: 11px;">{{ $b->kas_akhir }}</div>@endif</td>
                        <td class="daftar-nama">{{ $b->kas_lain->map(fn ($n, $t) => "{$t} ({$n}×)")->implode(', ') }}</td>
                        <td class="angka" data-n="{{ $b->uj_n }}">{{ $b->uj_n ?: '' }}@if ($b->uj_n)<div class="redup" style="font-size: 11px;">{{ $b->uj_akhir }}</div>@endif</td>
                        <td class="daftar-nama">{{ $b->sopir->take(10)->map(fn ($n, $t) => "{$t} ({$n}×)")->implode(', ') }}@if ($b->sopir->count() > 10) <span class="redup">+{{ $b->sopir->count() - 10 }}</span>@endif</td>
                        <td class="daftar-nama">{{ $b->karyawan->implode('; ') }}</td>
                        <td class="daftar-nama redup">{{ $r->catatan }}</td>
                        <td><button type="button" class="tombol-edit" data-ubah="{{ route('rekening.ubah', $r) }}" data-no="{{ $r->no_rekening }}" data-nama="{{ $r->nama }}"
                            data-bank="{{ $r->bank }}" data-catatan="{{ $r->catatan }}"
                            data-saran="{{ json_encode(array_values(array_unique([...$b->kas_lain->keys()->all(), ...$b->karyawan->map(fn ($k) => explode(' · ', $k)[0])->all(), ...$b->sopir->keys()->take(5)->all()]))) }}">{{ $r->nama ? 'Ubah' : 'Isi nama' }}</button></td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="redup" style="padding: 20px 14px;">Tidak ada rekening di tampilan ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <dialog id="dialog-rek">
        <form method="POST" id="form-rek">
            @csrf @method('PATCH')
            <input type="hidden" name="tampil" value="{{ $tampil !== 'semua' ? $tampil : '' }}">
            <h3 style="margin: 0 0 4px;">Rekening <span id="d-no"></span></h3>
            <label for="d-nama">Nama pemilik rekening</label>
            <input type="text" name="nama" id="d-nama" required maxlength="150" autocomplete="off">
            <div class="saran-nama" id="d-saran"></div>
            <label for="d-bank">Bank / e-wallet</label>
            <div class="isian-saran"><input type="text" name="bank" id="d-bank" autocomplete="off"></div>
            <label for="d-cat">Catatan</label>
            <input type="text" name="catatan" id="d-cat" maxlength="300" autocomplete="off">
            <p class="redup" style="font-size: 12px; margin: 10px 0 14px;">Nama ini dipakai sebagai saran di Input Kas & Input UJ dan ditampilkan sebagai pemilik rekening di Kas UJ. Data lama di sheet tidak diubah.</p>
            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <button type="button" class="tombol polos" onclick="this.closest('dialog').close()">Batal</button>
                <button type="submit" class="tombol">Simpan</button>
            </div>
        </form>
    </dialog>
    <script>
        (() => {
            const daftarBank = @json($bank);
            PilihBank.pasang(document.getElementById('t-bank'), daftarBank, {});
            const bankDialog = document.getElementById('d-bank');
            PilihBank.pasang(bankDialog, daftarBank, {});
            const dlg = document.getElementById('dialog-rek'), f = document.getElementById('form-rek'), nama = document.getElementById('d-nama');
            const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            document.querySelectorAll('[data-ubah]').forEach(b => b.addEventListener('click', () => {
                f.action = b.dataset.ubah;
                document.getElementById('d-no').textContent = b.dataset.no;
                nama.value = b.dataset.nama; bankDialog.value = b.dataset.bank; document.getElementById('d-cat').value = b.dataset.catatan;
                const saran = JSON.parse(b.dataset.saran || '[]').filter(s => s && s !== b.dataset.nama);
                document.getElementById('d-saran').innerHTML = saran.map(s => `<button type="button">${esc(s)}</button>`).join('');
                document.querySelectorAll('#d-saran button').forEach(x => x.addEventListener('click', () => { nama.value = x.textContent; nama.focus(); }));
                dlg.showModal(); nama.select();
            }));
        })();
    </script>
@endsection
