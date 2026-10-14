@extends('layouts.app', ['judul' => $karyawan->exists ? 'Edit '.$karyawan->nama : 'Tambah karyawan'])

@section('lebar', '900px')

@section('isi')
    <style>
        .form-k label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .form-k input, .form-k select, .form-k textarea { width: 100%; padding: 8px 10px; border-radius: 7px; font-size: 14px; box-sizing: border-box; }
        .form-k .baris { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; }
    </style>
    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi isian berikut:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ $karyawan->exists ? route('karyawan.update', $karyawan) : route('karyawan.store') }}" class="kartu form-k">
        @csrf
        @if ($karyawan->exists) @method('PUT') @endif
        <h3 style="margin: 0 0 14px;">{{ $karyawan->exists ? 'Edit karyawan '.$karyawan->nama : 'Tambah karyawan' }}</h3>
        <div class="baris">
            <div style="grid-column: span 2;"><label for="nama">Nama lengkap</label><input id="nama" name="nama" value="{{ old('nama', $karyawan->nama) }}" required autocomplete="off"></div>
            <div><label for="status">Status</label>
                <select id="status" name="status">@foreach (\App\Models\Karyawan::STATUS as $k => $l)<option value="{{ $k }}" @selected(old('status', $karyawan->status) === $k)>{{ $l }}</option>@endforeach</select></div>
        </div>
        <div class="baris">
            <div style="grid-column: span 2;"><label for="email">Email Google (untuk login absen dari HP)</label><input id="email" name="email" type="email" value="{{ old('email', $karyawan->email) }}" autocomplete="off" placeholder="nama@gmail.com"></div>
        </div>
        <div class="baris">
            <div><label for="nik">NIK (16 digit, sesuai KTP)</label><input id="nik" name="nik" inputmode="numeric" maxlength="16" value="{{ old('nik', $karyawan->nik) }}" autocomplete="off"></div>
            <div><label for="npwp">NPWP</label><input id="npwp" name="npwp" value="{{ old('npwp', $karyawan->npwp) }}" autocomplete="off"></div>
            <div><label for="site">Site</label><input id="site" name="site" list="saran-site" value="{{ old('site', $karyawan->site) }}" autocomplete="off" placeholder="mis. ASG, Site 21">
                <datalist id="saran-site">@foreach ($saranSite as $s)<option value="{{ $s }}">@endforeach</datalist></div>
            <div><label for="jabatan">Jabatan</label><input id="jabatan" name="jabatan" list="saran-jabatan" value="{{ old('jabatan', $karyawan->jabatan) }}" autocomplete="off" placeholder="mis. Operator Excavator">
                <datalist id="saran-jabatan">@foreach ($saranJabatan as $s)<option value="{{ $s }}">@endforeach</datalist></div>
        </div>
        <div class="baris">
            <div><label for="bank">Bank</label><input id="bank" name="bank" list="saran-bank" value="{{ old('bank', $karyawan->bank) }}" autocomplete="off">
                <datalist id="saran-bank">@foreach ($saranBank as $s)<option value="{{ $s }}">@endforeach</datalist></div>
            <div><label for="no_rekening">No rekening</label><input id="no_rekening" name="no_rekening" inputmode="numeric" value="{{ old('no_rekening', $karyawan->no_rekening) }}" autocomplete="off"></div>
            <div><label for="nama_rekening">Nama rekening</label><input id="nama_rekening" name="nama_rekening" value="{{ old('nama_rekening', $karyawan->nama_rekening) }}" autocomplete="off"></div>
        </div>
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="tombol">Simpan</button>
            <a href="{{ route('karyawan.index') }}" class="tombol polos">Batal</a>
        </div>
    </form>
@endsection
