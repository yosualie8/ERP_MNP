@extends('layouts.app', ['judul' => 'Absen Tukang (Mandor)'])

@section('lebar', '1000px')

@section('isi')
    <style>
        .absen-mandor td { vertical-align: middle; }
        .absen-mandor input[type=checkbox] { width: 20px; height: 20px; }
        .absen-mandor tr.dicentang td { background: rgba(76, 195, 138, .08); }
        .absen-mandor tr.dikunci td { opacity: .55; }
        .absen-mandor select { max-width: 220px; }
    </style>

    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px; flex-wrap: wrap;">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('absen-mandor.index', ['tanggal' => $tanggal->copy()->subDay()->toDateString()]) }}">‹</a>
        <input type="text" name="tanggal" data-tanggal-id value="{{ $tanggal->toDateString() }}" style="width: 150px;" onchange="this.form.submit()">
        @if ($tanggal->lt(today()))
            <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('absen-mandor.index', ['tanggal' => $tanggal->copy()->addDay()->toDateString()]) }}">›</a>
        @endif
        <b>{{ $tanggal->translatedFormat('l, j F Y') }}</b>
    </form>

    <p class="redup" style="font-size: 13px; margin: 0 0 10px;">Centang tukang / helper yang <b>hadir</b> hari ini (dan <b>lembur</b> bila lembur). Absen ini langsung masuk
        Rekap Absen & Gaji Mingguan. Yang punya akun absen HP sendiri tidak tampil di sini.</p>

    <form method="POST" action="{{ route('absen-mandor.simpan') }}" id="form-absen">
        @csrf
        <input type="hidden" name="tanggal" value="{{ $tanggal->toDateString() }}">
        <div class="kartu" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <label style="margin: 0; font-weight: 600;" for="tahap-umum">Tahap kerja</label>
            <select name="tahap" id="tahap-umum" style="min-width: 240px;">
                <option value="">(pilih tahap)</option>
                @foreach ($tahap as $id => $nama)<option value="{{ $id }}" @selected($id == $tahapAkhir)>{{ $nama }}</option>@endforeach
            </select>
            <input type="search" id="cari-orang" placeholder="Cari nama / jabatan / site…" style="flex: 1; min-width: 180px;">
            <span class="redup" style="font-size: 13px;"><b id="jumlah-hadir">0</b> hadir · <b id="jumlah-lembur">0</b> lembur</span>
        </div>

        <div class="kartu gulir absen-mandor" style="padding: 0;">
            <table class="kas" data-tanpa-saring-kolom>
                <thead><tr><th>Nama</th><th>Jabatan</th><th>Site</th><th style="text-align: center;">Hadir</th><th style="text-align: center;">Lembur</th><th>Tahap (bila beda)</th></tr></thead>
                <tbody>
                    @forelse ($orang as $k)
                        @php
                            $r = $rekap[$k->id] ?? null;
                            $kunci = $r && $r->sumber === 'admin' && $r->hadir !== null;
                        @endphp
                        <tr @class(['orang', 'dicentang' => $r?->hadir, 'dikunci' => $kunci]) data-cari="{{ mb_strtolower($k->nama.' '.$k->jabatan.' '.$k->site) }}">
                            <td><b>{{ $k->nama }}</b>@if ($kunci)<div class="redup" style="font-size: 11px;">sudah diputuskan admin ({{ $r->hadir ? 'dibayar' : 'tidak dibayar' }})</div>@endif</td>
                            <td>{{ $k->jabatan }}</td>
                            <td>{{ $k->site }}</td>
                            <td style="text-align: center;"><input type="checkbox" name="orang[{{ $k->id }}][hadir]" value="1" class="cek-hadir" @checked($r?->hadir) @disabled($kunci)></td>
                            <td style="text-align: center;"><input type="checkbox" name="orang[{{ $k->id }}][lembur]" value="1" class="cek-lembur" @checked($r?->lembur) @disabled($kunci)></td>
                            <td><select name="orang[{{ $k->id }}][tahap]" @disabled($kunci)>
                                <option value="">sama</option>
                                @if ($r?->proyek_tahap_id && $r->proyek_tahap_id != $tahapAkhir)<option value="{{ $r->proyek_tahap_id }}" selected>{{ $tahap[$r->proyek_tahap_id] ?? '?' }}</option>@endif
                            </select></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="redup" style="padding: 18px 14px;">Belum ada tukang / helper tanpa akun absen. Tambahkan di bawah.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="display: flex; justify-content: flex-end; margin-bottom: 18px;">
            <button type="submit" class="tombol">Simpan absen {{ $tanggal->translatedFormat('j M') }}</button>
        </div>
    </form>

    <details class="kartu">
        <summary style="cursor: pointer; font-weight: 600;">➕ Tambah tukang / helper baru</summary>
        <form method="POST" action="{{ route('absen-mandor.tambah') }}" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; margin-top: 10px;">
            @csrf
            <input type="hidden" name="tanggal" value="{{ $tanggal->toDateString() }}">
            <label style="margin: 0;">Nama<br><input type="text" name="nama" required maxlength="100" value="{{ old('nama') }}"></label>
            <label style="margin: 0;">Jabatan<br><input type="text" name="jabatan" required maxlength="60" list="daftar-jabatan" value="{{ old('jabatan') }}"></label>
            <label style="margin: 0;">Site<br><input type="text" name="site" maxlength="40" list="daftar-site" value="{{ old('site', $siteSaya) }}"></label>
            <button type="submit" class="tombol polos">Tambah</button>
            <datalist id="daftar-jabatan">@foreach ($jabatan as $j)<option value="{{ $j }}">@endforeach</datalist>
            <datalist id="daftar-site">@foreach ($site as $s)<option value="{{ $s }}">@endforeach</datalist>
        </form>
    </details>

    <script>
        (() => {
            const umum = document.getElementById('tahap-umum');
            // Pilihan tahap per baris diisi dari daftar tahap umum (tidak diulang di HTML tiap baris).
            document.querySelectorAll('.absen-mandor select').forEach(s => {
                const pilih = s.value;
                [...umum.options].slice(1).forEach(o => { if (o.value !== pilih) s.add(new Option(o.text, o.value)); });
                s.value = pilih;
            });
            const hitung = () => {
                const baris = [...document.querySelectorAll('tr.orang')];
                baris.forEach(tr => tr.classList.toggle('dicentang', tr.querySelector('.cek-hadir').checked));
                document.getElementById('jumlah-hadir').textContent = baris.filter(tr => tr.querySelector('.cek-hadir').checked).length;
                document.getElementById('jumlah-lembur').textContent = baris.filter(tr => tr.querySelector('.cek-hadir').checked && tr.querySelector('.cek-lembur').checked).length;
            };
            document.querySelectorAll('.cek-lembur').forEach(c => c.addEventListener('change', () => {
                if (c.checked) c.closest('tr').querySelector('.cek-hadir').checked = true; // lembur berarti hadir
                hitung();
            }));
            document.querySelectorAll('.cek-hadir').forEach(c => c.addEventListener('change', () => {
                if (!c.checked) c.closest('tr').querySelector('.cek-lembur').checked = false;
                hitung();
            }));
            document.getElementById('cari-orang').addEventListener('input', e => {
                const q = e.target.value.trim().toLowerCase();
                document.querySelectorAll('tr.orang').forEach(tr => { tr.hidden = q && !tr.dataset.cari.includes(q); });
            });
            document.getElementById('form-absen').addEventListener('submit', e => {
                const ada = document.querySelectorAll('.cek-hadir:checked').length;
                const tanpaTahap = [...document.querySelectorAll('tr.orang')].some(tr => tr.querySelector('.cek-hadir').checked && !tr.querySelector('select').value);
                if (ada && tanpaTahap && !umum.value && !confirm('Tahap kerja belum dipilih. Simpan tanpa tahap?')) e.preventDefault();
            });
            hitung();
        })();
    </script>
@endsection
