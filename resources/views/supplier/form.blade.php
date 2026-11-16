@extends('layouts.app', ['judul' => $supplier->exists ? 'Edit '.$supplier->nama : 'Tambah supplier'])

@section('lebar', '900px')

@section('isi')
    <style>
        .form-sup label { display: block; margin-bottom: 6px; }
        .form-sup input, .form-sup textarea { width: 100%; box-sizing: border-box; }
        .form-sup .baris { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .form-sup .centang { display: flex; gap: 8px; align-items: center; font-weight: 400; }
        .form-sup .centang input { width: auto; }
    </style>
    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi isian berikut:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ $supplier->exists ? route('supplier.update', $supplier) : route('supplier.store') }}" class="kartu form-sup">
        @csrf
        @if ($supplier->exists) @method('PUT') @endif
        <h3 style="margin: 0 0 14px;">{{ $supplier->exists ? 'Edit supplier '.$supplier->nama : 'Tambah supplier' }}</h3>
        <div class="baris">
            <div><label for="nama">Nama supplier</label><input id="nama" name="nama" list="daftar-nama" value="{{ old('nama', $supplier->nama) }}" required autocomplete="off" maxlength="150" placeholder="sama persis dengan nama di Daftar Rekening">
                <datalist id="daftar-nama">@foreach ($saran as $n)<option value="{{ $n }}">@endforeach</datalist>
                <div class="redup" style="font-size: 12px; margin-top: 4px;">Pilih dari saran supaya tersambung ke rekening & riwayat pembelian.</div></div>
            <div><label for="kontak">Kontak (nama orang)</label><input id="kontak" name="kontak" value="{{ old('kontak', $supplier->kontak) }}" autocomplete="off" maxlength="100"></div>
            <div><label for="telepon">Telepon / WA</label><input id="telepon" name="telepon" inputmode="tel" value="{{ old('telepon', $supplier->telepon) }}" autocomplete="off" maxlength="40" placeholder="0812…"></div>
        </div>
        <div class="baris">
            <div><label for="alamat">Alamat</label><input id="alamat" name="alamat" value="{{ old('alamat', $supplier->alamat) }}" autocomplete="off" maxlength="300"></div>
            <div><label for="barang">Barang yang biasa dibeli</label><input id="barang" name="barang" value="{{ old('barang', $supplier->barang) }}" autocomplete="off" maxlength="200" placeholder="mis. filter, oli, ban"></div>
        </div>
        <div style="margin-bottom: 12px;"><label for="catatan">Catatan</label><textarea id="catatan" name="catatan" rows="3" maxlength="500">{{ old('catatan', $supplier->catatan) }}</textarea></div>
        <input type="hidden" name="aktif" value="0">
        <label class="centang" style="margin-bottom: 16px;"><input type="checkbox" name="aktif" value="1" @checked(old('aktif', $supplier->aktif))> Aktif (supplier nonaktif disembunyikan dari daftar bawaan)</label>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="tombol">Simpan</button>
            <a href="{{ route('supplier.index') }}" class="tombol polos">Batal</a>
        </div>
    </form>
@endsection
