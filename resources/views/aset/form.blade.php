@extends('layouts.app', ['judul' => $aset->exists ? 'Edit '.$aset->no_lambung : 'Tambah truk'])

@section('lebar', '900px')

@section('isi')
    <style>
        .form-aset label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .form-aset input, .form-aset select, .form-aset textarea { width: 100%; padding: 8px 10px; border-radius: 7px; font-size: 14px; }
        .form-aset .baris { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 12px; }
    </style>
    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi isian berikut:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ $aset->exists ? route('aset.update', $aset) : route('aset.store') }}" class="kartu form-aset">
        @csrf
        @if ($aset->exists) @method('PUT') @endif
        <h3 style="margin: 0 0 14px;">{{ $aset->exists ? 'Edit truk '.$aset->no_lambung : 'Tambah truk ke Data Aset' }}</h3>
        <div class="baris">
            <div><label for="no_lambung">No Lambung</label><input id="no_lambung" name="no_lambung" value="{{ old('no_lambung', $aset->no_lambung) }}" placeholder="DT 070" required autocomplete="off"></div>
            <div><label for="plat">Plat nomor</label><input id="plat" name="plat" value="{{ old('plat', $aset->plat) }}" placeholder="B 9231 UIR" autocomplete="off"></div>
            <div><label for="jenis">Jenis / merek</label><input id="jenis" name="jenis" list="daftar-jenis" value="{{ old('jenis', $aset->jenis) }}" autocomplete="off">
                <datalist id="daftar-jenis">@foreach ($jenis as $j)<option value="{{ $j }}">@endforeach</datalist></div>
            <div><label for="tahun">Tahun</label><input id="tahun" name="tahun" inputmode="numeric" value="{{ old('tahun', $aset->tahun) }}" autocomplete="off"></div>
        </div>
        <div class="baris">
            <div><label for="no_rangka">No rangka</label><input id="no_rangka" name="no_rangka" value="{{ old('no_rangka', $aset->no_rangka) }}" autocomplete="off"></div>
            <div><label for="no_mesin">No mesin</label><input id="no_mesin" name="no_mesin" value="{{ old('no_mesin', $aset->no_mesin) }}" autocomplete="off"></div>
            <div><label for="stnk_berlaku">STNK berlaku s.d.</label><input type="date" id="stnk_berlaku" name="stnk_berlaku" value="{{ old('stnk_berlaku', $aset->stnk_berlaku?->toDateString()) }}"></div>
            <div><label for="kir_berlaku">KIR berlaku s.d.</label><input type="date" id="kir_berlaku" name="kir_berlaku" value="{{ old('kir_berlaku', $aset->kir_berlaku?->toDateString()) }}"></div>
        </div>
        <div class="baris">
            <div><label for="status">Status</label>
                <select id="status" name="status">@foreach (\App\Models\AsetTruk::STATUS as $k => $l)<option value="{{ $k }}" @selected(old('status', $aset->status) === $k)>{{ $l }}</option>@endforeach</select></div>
            <div><label for="driver_tetap">Driver tetap</label><input id="driver_tetap" name="driver_tetap" value="{{ old('driver_tetap', $aset->driver_tetap) }}" autocomplete="off"></div>
        </div>
        <div style="margin-bottom: 14px;"><label for="catatan">Catatan</label><textarea id="catatan" name="catatan" rows="3">{{ old('catatan', $aset->catatan) }}</textarea></div>
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="tombol">Simpan</button>
            <a href="{{ route('aset.index') }}" class="tombol polos">Batal</a>
        </div>
    </form>
@endsection
