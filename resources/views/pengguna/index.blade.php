@extends('layouts.app', ['judul' => 'Pengguna'])

@section('lebar', '1280px')

@section('isi')
    <style>
        .menu-akun { display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; }
        .menu-akun label { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: var(--teks); cursor: pointer; }
        .menu-akun .simpan { padding: 4px 10px; font-size: 12px; }
        .menu-akun .grup { display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: center; flex-basis: 100%; }
        .menu-akun .grup b { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--redup); min-width: 110px; }
        .menu-akun.berubah .simpan { border-color: var(--aksen); color: var(--aksen-terang); }
    </style>
    <div class="kartu">
        <h3 style="margin-top: 0;">Tambah pengguna</h3>
        <p class="redup" style="margin-top: 0;">Pengguna login dengan akun Google sesuai email di bawah. Super Admin melihat semua menu dan mengatur pengguna; menu yang boleh dilihat tiap Admin diatur di tabel bawah.</p>
        @if ($errors->any())
            <div class="pesan galat">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('pengguna.store') }}" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            @csrf
            <input type="search" name="email" value="{{ old('email') }}" placeholder="email@gmail.com" required style="min-width: 240px;">
            <input type="search" name="name" value="{{ old('name') }}" placeholder="Nama (boleh kosong)" style="min-width: 200px;">
            @foreach (\App\Models\User::ROLE as $kunci => $nama)
                <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 14px;">
                    <input type="radio" name="role" value="{{ $kunci }}" @checked(old('role', 'admin') === $kunci)> {{ $nama }}
                </label>
            @endforeach
            <button class="tombol" type="submit" style="padding: 8px 14px;">Tambah</button>
        </form>
    </div>

    <div class="kartu" style="padding: 0;">
        <table style="font-size: 14px;">
            <thead><tr><th style="padding-left: 16px;">Nama</th><th>Email</th><th>Peran</th><th>Menu yang bisa dilihat</th><th>Login</th><th></th></tr></thead>
            <tbody>
                @foreach ($pengguna as $u)
                    <tr>
                        <td style="padding-left: 16px;"><b>{{ $u->name }}</b></td>
                        <td>{{ $u->email }}</td>
                        <td>
                            <form method="POST" action="{{ route('pengguna.update', $u) }}" style="display: inline-flex; gap: 6px;">
                                @csrf @method('PATCH')
                                @foreach (\App\Models\User::ROLE as $kunci => $nama)
                                    <button type="submit" name="role" value="{{ $kunci }}" @class(['chip', 'aktif' => $u->role === $kunci]) style="cursor: pointer;">{{ $nama }}</button>
                                @endforeach
                            </form>
                        </td>
                        <td>
                            @if ($u->isSuperAdmin())
                                <span class="label hijau">Semua menu</span> <span class="redup">(Super Admin, termasuk Pengguna)</span>
                            @else
                                <form method="POST" action="{{ route('pengguna.menu', $u) }}" class="menu-akun">
                                    @csrf @method('PATCH')
                                    <div class="grup"><b>Umum</b><label title="Selalu tampil"><input type="checkbox" checked disabled> Beranda</label></div>
                                    @foreach (\App\Support\MenuAkses::KATEGORI as [$judul, $ikon, $isi])
                                        <div class="grup"><b>{{ $ikon }} {{ $judul }}</b>
                                            @foreach ($isi as $kunci)
                                                @php([$nama, , , $ket] = \App\Support\MenuAkses::DAFTAR[$kunci])
                                                <label title="{{ $ket }}"><input type="checkbox" name="menu[]" value="{{ $kunci }}" @checked($u->bolehMenu($kunci))> {{ $nama }}</label>
                                            @endforeach
                                        </div>
                                    @endforeach
                                    <button type="submit" class="tombol polos simpan">Simpan menu</button>
                                    @if ($u->menu === null)<span class="redup" style="font-size: 12px;">belum diatur — semua menu</span>@endif
                                </form>
                            @endif
                        </td>
                        <td class="redup">{{ $u->google_id ? 'sudah pernah login' : 'belum pernah login' }}</td>
                        <td style="text-align: right; padding-right: 16px;">
                            @unless ($u->is(auth()->user()))
                                <form method="POST" action="{{ route('pengguna.destroy', $u) }}" onsubmit="return confirm('Hapus {{ $u->email }}? Ia tidak bisa login lagi.')">
                                    @csrf @method('DELETE')
                                    <button class="tombol-hapus" type="submit">Hapus</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <script>
        // Tombol Simpan menu disorot bila centang diubah tetapi belum disimpan.
        document.querySelectorAll('form.menu-akun').forEach(f => f.addEventListener('change', () => f.classList.add('berubah')));
    </script>
@endsection
