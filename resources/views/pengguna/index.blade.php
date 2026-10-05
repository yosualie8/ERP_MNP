@extends('layouts.app', ['judul' => 'Pengguna'])

@section('isi')
    <div class="kartu">
        <h3 style="margin-top: 0;">Tambah pengguna</h3>
        <p class="redup" style="margin-top: 0;">Pengguna login dengan akun Google sesuai email di bawah. Admin bisa input, hapus, dan melihat kas; Super Admin juga mengatur pengguna & pengaturan.</p>
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
            <thead><tr><th style="padding-left: 16px;">Nama</th><th>Email</th><th>Peran</th><th>Login</th><th></th></tr></thead>
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

@endsection
