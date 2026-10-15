@extends('layouts.app', ['judul' => 'Hak Akses Jabatan'])

@section('lebar', '1280px')

@section('isi')
    <style>
        .menu-akun { display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; }
        .menu-akun label { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: var(--teks); cursor: pointer; }
        .menu-akun .simpan { padding: 4px 10px; font-size: 12px; }
        .menu-akun .grup { display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: center; flex-basis: 100%; }
        .menu-akun .grup b { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--redup); min-width: 110px; }
        .menu-akun.berubah .simpan { border-color: var(--aksen); color: var(--aksen-terang); }
        .orang { font-size: 12px; color: var(--redup); }
        .status-simpan { font-size: 13px; }
        .status-simpan.ok { color: var(--sukses); font-weight: 600; }
        .status-simpan.galat { color: var(--merah); }
    </style>
    <div class="kartu">
        <h3 style="margin-top: 0;">Menu per jabatan</h3>
        <p class="redup" style="margin: 0;">Menu yang bisa dibuka seseorang mengikuti <b>jabatan</b>-nya di Data Karyawan (karyawan aktif yang email akun Google-nya diisi).
            Super Admin selalu bisa semua menu. Karyawan aktif yang emailnya terdaftar langsung bisa login tanpa didaftarkan di menu Pengguna.
            Jabatan yang belum pernah diatur otomatis hanya mendapat menu Absen.</p>
    </div>

    <div class="kartu" style="padding: 0;">
        <table style="font-size: 14px;" data-tanpa-saring-kolom data-tanpa-navigasi>
            <thead><tr><th style="padding-left: 16px; width: 240px;">Jabatan</th><th>Menu yang bisa dibuka</th></tr></thead>
            <tbody>
                @foreach ($jabatan as $j)
                    <tr>
                        <td style="padding-left: 16px; vertical-align: top;">
                            <b>{{ $j->nama }}</b>
                            <div class="orang">{{ $j->karyawan->count() }} karyawan aktif · {{ $j->bisaLogin }} punya email</div>
                            @if ($j->karyawan->isNotEmpty())<div class="orang" style="margin-top: 4px;">{{ $j->karyawan->pluck('nama')->implode(', ') }}</div>@endif
                            @if (! $j->diatur)<span class="label kuning belum-diatur" style="margin-top: 4px; display: inline-block;">belum diatur — hanya Absen</span>@endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('jabatan-menu.update') }}" class="menu-akun">
                                @csrf @method('PATCH')
                                <input type="hidden" name="jabatan" value="{{ $j->nama }}">
                                <div class="grup"><b>Umum</b><label title="Selalu tampil"><input type="checkbox" checked disabled> Beranda</label></div>
                                @foreach (\App\Support\MenuAkses::KATEGORI as [$judul, $ikon, $isi])
                                    <div class="grup"><b>{{ $ikon }} {{ $judul }}</b>
                                        @foreach (array_diff($isi, \App\Support\MenuAkses::KHUSUS_SUPER) as $kunci)
                                            @php([$nama, , , $ket] = \App\Support\MenuAkses::DAFTAR[$kunci])
                                            <label title="{{ $ket }}"><input type="checkbox" name="menu[]" value="{{ $kunci }}" @checked(in_array($kunci, $j->menu, true))> {{ $nama }}</label>
                                        @endforeach
                                    </div>
                                @endforeach
                                <button type="submit" class="tombol polos simpan">Simpan menu {{ $j->nama }}</button>
                                <span class="status-simpan" aria-live="polite"></span>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <script>
        // Tombol Simpan disorot bila centang diubah tetapi belum disimpan. Simpan tanpa memuat ulang halaman
        // (posisi gulir tetap, jabatan lain bisa langsung diatur).
        document.querySelectorAll('form.menu-akun').forEach(f => {
            const status = f.querySelector('.status-simpan'), tombol = f.querySelector('.simpan');
            f.addEventListener('change', () => { f.classList.add('berubah'); status.textContent = ''; });
            f.addEventListener('submit', async e => {
                e.preventDefault();
                tombol.disabled = true; status.className = 'status-simpan redup'; status.textContent = 'Menyimpan…';
                try {
                    const res = await fetch(f.action, {method: 'POST', body: new FormData(f), headers: {Accept: 'application/json'}});
                    const h = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(h.message || 'Gagal menyimpan (kode ' + res.status + ').');
                    f.classList.remove('berubah');
                    status.className = 'status-simpan ok'; status.textContent = '✓ Tersimpan';
                    f.closest('tr').querySelector('.belum-diatur')?.remove();
                } catch (err) {
                    status.className = 'status-simpan galat'; status.textContent = '⚠ ' + err.message;
                } finally { tombol.disabled = false; }
            });
        });
    </script>
@endsection
