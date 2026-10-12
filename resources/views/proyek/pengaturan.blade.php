@extends('layouts.app', ['judul' => 'Pengaturan Daftar Proyek'])

@section('lebar', '820px')

@section('isi')
    <style>
        .atur h4 { margin: 18px 0 6px; color: var(--aksen-terang); }
        .atur .pilihan { display: flex; flex-wrap: wrap; gap: 6px 18px; }
        .atur label { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; cursor: pointer; }
        .atur .ket { font-size: 13px; margin: 0 0 6px; }
    </style>
    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ route('pengaturan-proyek.simpan') }}" class="kartu atur">
        @csrf
        <h3 style="margin: 0 0 4px;">Filter bawaan Daftar Proyek</h3>
        <p class="redup ket">Tahap yang tampil saat menu Daftar Proyek dibuka, untuk semua pengguna. Di Daftar Proyek tetap bisa memilih tahun lain atau "Tampilkan semua tanpa filter bawaan".</p>

        <h4>Periode</h4>
        <p class="redup ket">Tahap "berjalan" di suatu tahun = periode tanggal mulai–akhirnya menyentuh tahun itu. Tahap yang belum punya data tetap tampil bila statusnya Belum mulai / Progress.</p>
        <div class="pilihan">
            @foreach (\App\Support\DaftarProyek::PERIODE as $k => $l)
                <label><input type="radio" name="periode" value="{{ $k }}" @checked(old('periode', $atur['periode']) === $k)> {{ $l }}{{ $k === 'tahun_ini' ? ' ('.now()->year.')' : ($k === 'dua_tahun' ? ' ('.(now()->year - 1).'–'.now()->year.')' : '') }}</label>
            @endforeach
        </div>

        <h4>Status</h4>
        <div class="pilihan">
            @foreach (\App\Models\ProyekTahap::STATUS as $k => $l)
                <label><input type="checkbox" name="status[]" value="{{ $k }}" @checked(in_array($k, old('status', $atur['status']), true))> {{ $l }}</label>
            @endforeach
        </div>

        <h4>Tipe tahap</h4>
        <div class="pilihan">
            @foreach (\App\Models\ProyekTahap::TIPE as $k => $l)
                <label><input type="checkbox" name="tipe[]" value="{{ $k }}" @checked(in_array($k, old('tipe', $atur['tipe']), true))> {{ $l }}{{ $k === 'biaya' ? ' (biaya lokasi / tanpa tahap)' : '' }}</label>
            @endforeach
        </div>

        <h4>Cost center</h4>
        <p class="redup ket">Semua dicentang = semua cost center, termasuk cost center baru nanti.
            <a href="#" data-centang="1">Centang semua</a> · <a href="#" data-centang="0">Kosongkan</a></p>
        <div class="pilihan" id="cc">
            @foreach ([...$costCenter, '-'] as $c)
                <label><input type="checkbox" name="cost_center[]" value="{{ $c }}" @checked(! $atur['cost_center'] || in_array($c, old('cost_center', $atur['cost_center']), true))> {{ $c === '-' ? 'Tanpa cost center' : $c }}</label>
            @endforeach
        </div>

        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <button type="submit" class="tombol">Simpan</button>
            <a href="{{ route('proyek.index') }}" class="tombol polos">Ke Daftar Proyek</a>
        </div>
    </form>
    <script>
        document.querySelectorAll('[data-centang]').forEach(a => a.addEventListener('click', e => {
            e.preventDefault();
            document.querySelectorAll('#cc input').forEach(i => i.checked = a.dataset.centang === '1');
        }));
    </script>
@endsection
