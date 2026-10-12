    @php
        $isi = fn ($f) => old($f, $tahap->{$f} instanceof \DateTimeInterface ? $tahap->{$f}->toDateString() : $tahap->{$f});
        $des = fn ($f) => ($v = old($f, $tahap->{$f})) !== null && $v !== '' && is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',') : old($f, $tahap->{$f});
    @endphp
    <style>
        .form-p label { display: block; font-size: 13px; color: var(--redup); margin-bottom: 4px; }
        .form-p input, .form-p select, .form-p textarea { width: 100%; padding: 8px 10px; border-radius: 7px; font-size: 14px; box-sizing: border-box; }
        .form-p .baris { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 12px; }
        .form-p h4 { margin: 18px 0 8px; color: var(--aksen-terang); }
        .tanggal-id { display: flex; align-items: center; gap: 4px; }
        .tanggal-id .tombol-kalender { background: none; border: 2px solid #4a5160; border-radius: 6px; padding: 4px 6px; cursor: pointer; }
        .alias td { padding: 4px 8px; font-size: 13px; border-bottom: 1px solid var(--garis); }
    </style>
    @if ($errors->any())
        <div class="pesan galat"><b>Periksa lagi:</b><ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ $tahap->exists ? route('proyek.update', $tahap) : route('proyek.store') }}" class="kartu form-p">
        @csrf
        @if ($tahap->exists) @method('PUT') @endif
        <h3 style="margin: 0 0 14px;">{{ $tahap->exists ? 'Data tahap' : 'Tahap baru' }}</h3>
        <div class="baris">
            <div><label for="proyek_id">Proyek induk</label>
                <select id="proyek_id" name="proyek_id"><option value="">— pilih —</option>
                    @foreach ($proyek as $p)<option value="{{ $p->id }}" @selected((int) old('proyek_id', $tahap->proyek_id) === $p->id)>{{ $p->nama }}</option>@endforeach</select></div>
            <div><label for="proyek_baru">…atau proyek baru</label><input id="proyek_baru" name="proyek_baru" value="{{ old('proyek_baru') }}" placeholder="mis. Pantai Mutiara 3" autocomplete="off"></div>
            <div style="grid-column: span 2;"><label for="nama">Nama tahap (nama baku)</label><input id="nama" name="nama" value="{{ $isi('nama') }}" placeholder="mis. ASG Tahap 118" required autocomplete="off"></div>
        </div>
        <div class="baris">
            <div><label for="tipe">Tipe</label><select id="tipe" name="tipe">@foreach (\App\Models\ProyekTahap::TIPE as $k => $l)<option value="{{ $k }}" @selected($isi('tipe') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div><label for="status">Status</label><select id="status" name="status">@foreach (\App\Models\ProyekTahap::STATUS as $k => $l)<option value="{{ $k }}" @selected($isi('status') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div><label for="cost_center">Cost center</label><select id="cost_center" name="cost_center"><option value="">Ikut proyek induk{{ $tahap->proyek?->cost_center ? ' ('.$tahap->proyek->cost_center.')' : '' }}</option>@foreach (\App\Models\CostCenter::orderBy('kode')->pluck('kode') as $c)<option @selected($isi('cost_center') === $c)>{{ $c }}</option>@endforeach</select></div>
            <div><label for="lokasi">Lokasi / surveyor</label><input id="lokasi" name="lokasi" value="{{ $isi('lokasi') }}" autocomplete="off"></div>
        </div>
        <div class="baris" data-luas>
            <div><label for="volume">Volume (m³)</label><input id="volume" name="volume" inputmode="decimal" value="{{ $des('volume') }}" autocomplete="off"></div>
            <div><label for="luas">Luas (m²)</label><input id="luas" name="luas" inputmode="decimal" value="{{ $des('luas') }}" autocomplete="off"></div>
            <div><label>Tinggi urugan</label><input value="{{ $tahap->tinggiUrugan() ? number_format($tahap->tinggiUrugan(), 3, ',', '.').' m' : '–' }}" disabled></div>
        </div>
        @if ($super)
            <h4>Keuangan (khusus Super Admin)</h4>
            <div class="baris" data-luas>
                <div><label for="harga_m3">Harga per m³ (Rp)</label><input id="harga_m3" name="harga_m3" inputmode="numeric" value="{{ $isi('harga_m3') ? number_format($isi('harga_m3'), 0, ',', '.') : '' }}" autocomplete="off"></div>
                <div><label for="omset">Omset (Rp) <span class="redup">— kosong = volume × harga</span></label><input id="omset" name="omset" inputmode="numeric" value="{{ $isi('omset') ? number_format($isi('omset'), 0, ',', '.') : '' }}" autocomplete="off"></div>
                <div><label for="tarif_bbm">Tarif BBM (Rp/liter)</label><input id="tarif_bbm" name="tarif_bbm" inputmode="numeric" value="{{ $isi('tarif_bbm') ? number_format($isi('tarif_bbm'), 0, ',', '.') : '' }}" autocomplete="off"></div>
                <div><label for="tarif_rental">Tarif rental (Rp/jam HM)</label><input id="tarif_rental" name="tarif_rental" inputmode="numeric" value="{{ $isi('tarif_rental') ? number_format($isi('tarif_rental'), 0, ',', '.') : '' }}" autocomplete="off"></div>
            </div>
            <div class="baris" data-luas>
                <div><label for="komisi_lapangan">Komisi lapangan (%)</label><input id="komisi_lapangan" name="komisi_lapangan" inputmode="decimal" value="{{ $des('komisi_lapangan') }}"></div>
                <div><label for="komisi_yosua">Komisi Yosua (%)</label><input id="komisi_yosua" name="komisi_yosua" inputmode="decimal" value="{{ $des('komisi_yosua') }}"></div>
                <div><label for="komisi_verlix">Komisi Verlix (%)</label><input id="komisi_verlix" name="komisi_verlix" inputmode="decimal" value="{{ $des('komisi_verlix') }}"></div>
            </div>
            <div class="baris" data-luas>
                <div><label for="bayar_lapangan">Tgl bayar komisi lapangan</label><input type="text" data-tanggal-id id="bayar_lapangan" name="bayar_lapangan" value="{{ old('bayar_lapangan', $tahap->bayar_lapangan?->translatedFormat('d-M-Y')) }}" autocomplete="off"></div>
                <div><label for="bayar_yosua">Tgl bayar komisi Yosua</label><input type="text" data-tanggal-id id="bayar_yosua" name="bayar_yosua" value="{{ old('bayar_yosua', $tahap->bayar_yosua?->translatedFormat('d-M-Y')) }}" autocomplete="off"></div>
                <div><label for="bayar_verlix">Tgl bayar komisi Verlix</label><input type="text" data-tanggal-id id="bayar_verlix" name="bayar_verlix" value="{{ old('bayar_verlix', $tahap->bayar_verlix?->translatedFormat('d-M-Y')) }}" autocomplete="off"></div>
            </div>
        @endif
        <div style="margin-bottom: 14px;"><label for="catatan">Catatan</label><textarea id="catatan" name="catatan" rows="2">{{ $isi('catatan') }}</textarea></div>
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="tombol">Simpan</button>
            <a href="{{ route('proyek.index') }}" class="tombol polos">Kembali</a>
        </div>
    </form>

    @if ($tahap->exists)
        <div class="kartu form-p">
            <h4 style="margin-top: 0;">Nama lama yang dipetakan ke tahap ini (alias)</h4>
            <p class="redup" style="margin: 0 0 8px; font-size: 13px;">Data di Ritasi / BBM / Cost Project / Kas Harian yang memakai nama ini ikut terhitung ke {{ $tahap->nama }}.</p>
            <table class="alias" style="width: 100%; border-collapse: collapse;">
                @forelse ($tahap->alias->sortBy(['sumber', 'nama']) as $a)
                    <tr><td style="width: 200px;"><span class="label">{{ \App\Models\ProyekAlias::SUMBER[$a->sumber] }}</span></td><td>{{ $a->nama }}</td>
                        <td style="text-align: right;"><form method="POST" action="{{ route('proyek.alias.hapus', $a) }}" onsubmit="return confirm('Hapus pemetaan &quot;{{ $a->nama }}&quot;?')">@csrf @method('DELETE')<button class="tombol-hapus">Hapus</button></form></td></tr>
                @empty
                    <tr><td class="redup">Belum ada.</td></tr>
                @endforelse
            </table>
            <form method="POST" action="{{ route('proyek.petakan') }}" style="display: flex; gap: 8px; margin-top: 10px; align-items: end;">
                @csrf
                <input type="hidden" name="tahap_id" value="{{ $tahap->id }}">
                <div style="width: 220px;"><label>Sumber</label><select name="sumber">@foreach (\App\Models\ProyekAlias::SUMBER as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div style="flex: 1;"><label>Nama / tulisan</label><input name="nama" placeholder="mis. ASG Tahap 118 Bunda Suci, atau ASG T118 untuk Kas" required autocomplete="off"></div>
                <button class="tombol polos">+ Tambah alias</button>
            </form>
        </div>
    @endif
    <script src="{{ asset('js/tanggal-id.js') }}"></script>
    <script>
        (() => {
            TanggalId.pasangSemua(document.querySelector('form.form-p'));
            const tipe = document.getElementById('tipe');
            const atur = () => document.querySelectorAll('[data-luas]').forEach(el => el.style.display = tipe.value === 'luas' ? '' : 'none');
            tipe.addEventListener('change', atur); atur();
        })();
    </script>
