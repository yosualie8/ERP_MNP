@extends('layouts.app', ['judul' => 'Master Jabatan'])

@section('lebar', '1000px')

@section('isi')
    <style>
        #dialog-jabatan { border: 1px solid var(--garis); border-radius: 12px; background: var(--kartu); color: var(--teks); padding: 18px; width: min(420px, 92vw); }
        #dialog-jabatan::backdrop { background: rgba(0, 0, 0, .55); }
        #dialog-jabatan input { width: 100%; box-sizing: border-box; padding: 8px 10px; font-size: 14px; }
    </style>
    @if ($errors->any())
        <div class="pesan galat">{{ $errors->first() }}</div>
    @endif
    <div class="kartu">
        <form method="POST" action="{{ route('jabatan.store') }}" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            @csrf
            <input type="text" name="nama" value="{{ old('nama') }}" placeholder="Nama jabatan baru, mis. Operator Dozer" required maxlength="100" autocomplete="off" style="min-width: 280px; flex: 1;">
            <button type="submit" class="tombol" style="padding: 8px 14px;">+ Tambah jabatan</button>
        </form>
        <p class="redup" style="margin: 8px 0 0; font-size: 13px;">Jabatan di Data Karyawan harus dipilih dari daftar ini. Menu yang bisa dibuka tiap jabatan diatur di
            @can('super-admin')<a href="{{ route('jabatan-menu.index') }}">Hak Akses Jabatan</a>@else Hak Akses Jabatan (Super Admin)@endcan; jabatan baru awalnya hanya mendapat menu Absen.</p>
        @if ($liar->isNotEmpty())
            <div class="pesan galat" style="margin: 10px 0 0;">Jabatan dipakai karyawan tetapi belum ada di master: <b>{{ $liar->implode(', ') }}</b> — tambahkan di atas (nama persis sama) atau ganti jabatan karyawannya.</div>
        @endif
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Jabatan</th><th class="angka">Karyawan aktif</th><th class="angka">Semua karyawan</th><th class="angka">Jumlah menu</th><th data-tanpa-saring></th></tr></thead>
            <tbody>
                @foreach ($jabatan as $j)
                    <tr class="t">
                        <td><b>{{ $j->nama }}</b></td>
                        <td class="angka">{{ $j->aktif }}</td>
                        <td class="angka">{{ $j->semua }}</td>
                        <td class="angka">{{ $j->menu }}</td>
                        <td style="white-space: nowrap; text-align: right;">
                            <button type="button" class="tombol-edit" data-ubah="{{ route('jabatan.update', $j->id) }}" data-nama="{{ $j->nama }}" data-jumlah="{{ $j->semua }}">Ganti nama</button>
                            @if (! $j->semua)
                                <form method="POST" action="{{ route('jabatan.hapus', $j->id) }}" style="display: inline;" onsubmit="return confirm('Hapus jabatan {{ $j->nama }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="tombol-hapus">Hapus</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <dialog id="dialog-jabatan">
        <form method="POST" id="form-ubah">
            @csrf @method('PATCH')
            <h3 style="margin: 0 0 10px;">Ganti nama jabatan</h3>
            <input type="text" name="nama" id="nama-baru" required maxlength="100" autocomplete="off">
            <p class="redup" id="info-ubah" style="font-size: 13px; margin: 8px 0 14px;"></p>
            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <button type="button" class="tombol polos" onclick="this.closest('dialog').close()">Batal</button>
                <button type="submit" class="tombol">Simpan</button>
            </div>
        </form>
    </dialog>
    <script>
        (() => {
            const dlg = document.getElementById('dialog-jabatan'), f = document.getElementById('form-ubah'), isian = document.getElementById('nama-baru');
            document.querySelectorAll('[data-ubah]').forEach(b => b.addEventListener('click', () => {
                f.action = b.dataset.ubah; isian.value = b.dataset.nama;
                document.getElementById('info-ubah').textContent = +b.dataset.jumlah
                    ? `Jabatan ${b.dataset.jumlah} karyawan ikut diganti ke nama baru; hak akses menunya tetap.` : 'Belum dipakai karyawan.';
                dlg.showModal(); isian.select();
            }));
        })();
    </script>
@endsection
