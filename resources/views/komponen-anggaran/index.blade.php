@extends('layouts.app', ['judul' => 'Master Komponen Anggaran'])

@section('lebar', '1300px')

@section('isi')
    <style>
        #dialog-komponen { border: 1px solid var(--garis); border-radius: 12px; background: var(--kartu); color: var(--teks); padding: 18px; width: min(560px, 94vw); }
        #dialog-komponen::backdrop { background: rgba(0, 0, 0, .55); }
        #dialog-komponen label.isian { display: block; font-size: 13px; color: var(--redup); margin: 10px 0 4px; }
        #dialog-komponen input[type=text], #dialog-komponen select { width: 100%; box-sizing: border-box; padding: 7px 9px; font-size: 14px; }
        #dialog-komponen .cek { display: inline-flex; gap: 5px; align-items: center; margin-right: 14px; font-size: 14px; color: var(--teks); }
    </style>
    @if ($errors->any())
        <div class="pesan galat">{{ $errors->first() }}</div>
    @endif
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
        <span class="redup" style="font-size: 13px;">Komponen yang dipakai menyusun anggaran tahap. <b>Template</b> = tipe tahap yang otomatis memakai komponen ini saat "Isi dari template". <b>Sumber realisasi</b> = data aktual pembandingnya.</span>
        <button type="button" class="tombol" data-baru style="margin-left: auto; padding: 8px 14px;">+ Tambah komponen</button>
    </div>
    <div class="kartu gulir" style="padding: 0;">
        <table class="kas">
            <thead><tr><th>Jenis</th><th>Komponen</th><th>Satuan</th><th>Cara hitung</th><th class="angka">Nilai bawaan</th><th>Sumber realisasi</th><th>Template</th><th>Status</th><th class="angka">Dipakai</th><th data-tanpa-saring></th></tr></thead>
            <tbody>
                @foreach ($komponen as $k)
                    <tr class="t">
                        <td><span @class(['label', 'hijau' => $k->jenis === 'omset'])>{{ \App\Models\KomponenAnggaran::JENIS[$k->jenis] }}</span></td>
                        <td><b>{{ $k->nama }}</b></td>
                        <td>{{ $k->satuan }}</td>
                        <td>{{ \App\Support\Anggaran::CARA[$k->cara] ?? $k->cara }}</td>
                        <td class="angka">{{ $k->nilai_bawaan === null ? '' : ($k->cara === 'persen' ? str_replace('.', ',', rtrim(rtrim(number_format($k->nilai_bawaan, 3, '.', ''), '0'), '.')).'%' : rp((int) round($k->nilai_bawaan))) }}</td>
                        <td>{{ \App\Support\Anggaran::SUMBER[$k->jenis][$k->sumber] ?? $k->sumber }}</td>
                        <td>{{ collect($k->tipe_bawaan ?? [])->map(fn ($t) => \App\Models\ProyekTahap::TIPE[$t] ?? $t)->implode(', ') }}</td>
                        <td>@if ($k->aktif)<span class="label hijau">Aktif</span>@else<span class="label">Nonaktif</span>@endif</td>
                        <td class="angka">{{ $dipakai[$k->id] ?? 0 }} tahap</td>
                        <td style="white-space: nowrap; text-align: right;">
                            <button type="button" class="tombol-edit" data-ubah="{{ json_encode(['url' => route('komponen-anggaran.update', $k), 'jenis' => $k->jenis, 'nama' => $k->nama, 'satuan' => $k->satuan, 'sumber' => $k->sumber, 'cara' => $k->cara, 'nilai' => $k->nilai_bawaan === null ? '' : str_replace('.', ',', rtrim(rtrim(number_format($k->nilai_bawaan, 3, '.', ''), '0'), '.')), 'tipe' => $k->tipe_bawaan ?? [], 'aktif' => $k->aktif]) }}">Ubah</button>
                            @unless ($dipakai[$k->id] ?? 0)
                                <form method="POST" action="{{ route('komponen-anggaran.hapus', $k) }}" style="display: inline;" onsubmit="return confirm('Hapus komponen {{ $k->nama }}?')">@csrf @method('DELETE')<button type="submit" class="tombol-hapus">Hapus</button></form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <dialog id="dialog-komponen">
        <form method="POST" id="form-komponen">
            @csrf <input type="hidden" name="_method" id="metode" value="POST">
            <h3 style="margin: 0 0 6px;" id="judul-dialog">Komponen baru</h3>
            <div id="isian-jenis"><label class="isian">Jenis</label>
                @foreach (\App\Models\KomponenAnggaran::JENIS as $j => $l)<label class="cek"><input type="radio" name="jenis" value="{{ $j }}" @checked($j === 'hpp')> {{ $l }}</label>@endforeach</div>
            <label class="isian">Nama komponen</label><input type="text" name="nama" required maxlength="100" autocomplete="off">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                <div><label class="isian">Satuan</label><input type="text" name="satuan" maxlength="20" placeholder="m³, rit, liter, ls" autocomplete="off"></div>
                <div><label class="isian">Cara hitung</label><select name="cara">@foreach (\App\Support\Anggaran::CARA as $c => $l)<option value="{{ $c }}">{{ $l }}</option>@endforeach</select></div>
                <div><label class="isian">Nilai bawaan</label><input type="text" name="nilai_bawaan" inputmode="decimal" placeholder="harga / %" autocomplete="off"></div>
            </div>
            <label class="isian">Sumber realisasi</label><select name="sumber"></select>
            <label class="isian">Template (otomatis dipakai tipe tahap)</label>
            @foreach (\App\Models\ProyekTahap::TIPE as $t => $l)<label class="cek"><input type="checkbox" name="tipe_bawaan[]" value="{{ $t }}"> {{ $l }}</label>@endforeach
            <div style="margin-top: 10px;"><label class="cek"><input type="checkbox" name="aktif" value="1" checked> Aktif (muncul di anggaran baru)</label></div>
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px;">
                <button type="button" class="tombol polos" onclick="this.closest('dialog').close()">Batal</button>
                <button type="submit" class="tombol">Simpan</button>
            </div>
        </form>
    </dialog>
    <script>
        (() => {
            const SUMBER = @json(\App\Support\Anggaran::SUMBER);
            const dlg = document.getElementById('dialog-komponen'), f = document.getElementById('form-komponen');
            const isiSumber = (jenis, pilih) => {
                const s = f.sumber; s.innerHTML = '';
                Object.entries(SUMBER[jenis]).forEach(([k, l]) => s.append(new Option(l, k, false, k === pilih)));
                // Omset selalu volume × harga.
                f.cara.value = jenis === 'omset' ? 'volume' : f.cara.value; f.cara.disabled = jenis === 'omset';
            };
            f.querySelectorAll('[name=jenis]').forEach(r => r.addEventListener('change', () => isiSumber(r.value)));
            document.querySelector('[data-baru]').addEventListener('click', () => {
                f.reset(); f.action = @json(route('komponen-anggaran.store')); f.querySelector('#metode').value = 'POST';
                document.getElementById('judul-dialog').textContent = 'Komponen baru'; document.getElementById('isian-jenis').hidden = false;
                isiSumber('hpp'); dlg.showModal(); f.nama.focus();
            });
            document.querySelectorAll('[data-ubah]').forEach(b => b.addEventListener('click', () => {
                const d = JSON.parse(b.dataset.ubah);
                f.reset(); f.action = d.url; f.querySelector('#metode').value = 'PATCH';
                document.getElementById('judul-dialog').textContent = 'Ubah komponen'; document.getElementById('isian-jenis').hidden = true;
                f.querySelector(`[name=jenis][value=${d.jenis}]`).checked = true;
                f.nama.value = d.nama; f.satuan.value = d.satuan ?? ''; f.cara.value = d.cara; f.nilai_bawaan.value = d.nilai;
                f.querySelectorAll('[name="tipe_bawaan[]"]').forEach(c => { c.checked = d.tipe.includes(c.value); });
                f.aktif.checked = d.aktif; isiSumber(d.jenis, d.sumber); dlg.showModal();
            }));
            // select cara yang dinonaktifkan tidak ikut terkirim → aktifkan sesaat sebelum kirim.
            f.addEventListener('submit', () => { f.cara.disabled = false; });
        })();
    </script>
@endsection
