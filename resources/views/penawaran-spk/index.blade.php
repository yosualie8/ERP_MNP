@extends('layouts.app', ['judul' => 'Penawaran & SPK'])

@section('lebar', '100%')

@section('isi')
    <style>
        .pil-tampil { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .pil-tampil a { padding: 5px 12px; border-radius: 999px; border: 1px solid var(--garis-kuat); color: var(--teks); text-decoration: none; font-size: 13px; }
        .pil-tampil a.aktif { background: var(--aksen); border-color: var(--aksen); color: #fff; }
        .ps-wadah { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; align-items: start; }
        @media (max-width: 1100px) { .ps-wadah { grid-template-columns: 1fr; } }
        .ps-pdf { position: sticky; top: 10px; height: calc(100vh - 30px); padding: 0; overflow: hidden; }
        .ps-pdf iframe { width: 100%; height: 100%; border: 0; display: block; }
        .ps-pdf .kosong { padding: 30px; }
        table.kas tr.dipilih td { background: color-mix(in srgb, var(--aksen) 18%, transparent); }
        table.kas tbody tr { cursor: pointer; }
        table.kas td.teks { white-space: normal; min-width: 180px; max-width: 320px; font-size: 12px; }
        .ps-form { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px 10px; }
        .ps-form label { font-size: 12px; margin: 0 0 3px; display: block; }
        .ps-form input, .ps-form select, .ps-form textarea { width: 100%; box-sizing: border-box; }
        .ps-form textarea { min-height: 52px; resize: vertical; font: inherit; font-size: 13px; }
        .ps-form .l2 { grid-column: span 2; } .ps-form .l3 { grid-column: span 3; } .ps-form .l4 { grid-column: span 4; } .ps-form .l6 { grid-column: span 6; }
        .ps-form input.angka { text-align: right; }
        .ps-info { font-size: 12px; margin-top: 3px; }
        .ps-tombol { grid-column: span 6; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .ps-beda { color: var(--merah, #d33); font-weight: 600; }
    </style>

    <p class="redup" style="margin-top: 0;">Dibaca otomatis dari Gmail {{ config('mnp.gmail_email') }} tiap 15 menit: <b>penawaran</b> = lampiran penawaran di email terkirim,
        <b>SPK</b> = lampiran email dari {{ \App\Support\SinkronGmail::DARI_SPK }} (scan, dibaca OCR — <b>cek angkanya</b> dengan PDF di kanan).
        Klik baris → PDF tampil di kanan; koreksi bila salah, pilih tahap, lalu <b>Tautkan</b>: omset tahap = jumlah nilai SPK yang ditautkan ke tahap itu.</p>

    @if ($errors->any())
        <div class="pesan galat">{{ $errors->first() }}</div>
    @endif

    <div class="pil-tampil">
        @foreach (\App\Http\Controllers\PenawaranSpkController::TAMPIL as $k => $l)
            <a href="{{ route('penawaran-spk.index', ['tampil' => $k]) }}" @class(['aktif' => $tampil === $k])>{{ $l }} · {{ $jumlah[$k] }}</a>
        @endforeach
    </div>

    <div class="ps-wadah">
        <div>
            <div class="kartu" id="ps-kartu-form" @if (! $baris->count()) hidden @endif>
                <form method="POST" id="ps-form" class="ps-form">
                    @csrf @method('PATCH')
                    <input type="hidden" name="tampil" value="{{ $tampil }}">
                    <div class="l6" style="display: flex; justify-content: space-between; gap: 10px; align-items: baseline;">
                        <h3 style="margin: 0;"><span id="f-jenis"></span> <span id="f-judul"></span></h3>
                        <span class="redup" style="font-size: 12px;" id="f-email"></span>
                    </div>
                    <div class="l2"><label for="f-kode">Kode</label><input id="f-kode" name="kode" maxlength="60" autocomplete="off"></div>
                    <div class="l3"><label for="f-nomor">Nomor dokumen</label><input id="f-nomor" name="nomor" maxlength="100" autocomplete="off"></div>
                    <div><label for="f-tanggal">Tanggal</label><input id="f-tanggal" name="tanggal" type="date"></div>
                    <div class="l3"><label for="f-pihak" id="f-pihak-l">Pihak</label><input id="f-pihak" name="pihak" maxlength="200" autocomplete="off"></div>
                    <div class="l3"><label for="f-lokasi">Lokasi</label><input id="f-lokasi" name="lokasi" maxlength="250" autocomplete="off"></div>
                    <div class="l6"><label for="f-pekerjaan">Pekerjaan</label><textarea id="f-pekerjaan" name="pekerjaan" maxlength="2000"></textarea></div>
                    <div class="l2"><label for="f-volume">Volume (m³)</label><input id="f-volume" name="volume" class="angka" inputmode="decimal" autocomplete="off"></div>
                    <div class="l2"><label for="f-harga">Harga / m³ (Rp)</label><input id="f-harga" name="harga" class="angka" inputmode="numeric" autocomplete="off"></div>
                    <div class="l2"><label for="f-nilai">Nilai (Rp, belum PPN)</label><input id="f-nilai" name="nilai" class="angka" inputmode="numeric" autocomplete="off">
                        <div class="ps-info redup" id="f-kali"></div></div>
                    <div class="l6 ps-info" id="f-penawaran"></div>
                    <div class="l4" id="f-tahap-w"><label for="f-tahap">Tahap proyek</label>
                        <select id="f-tahap" name="proyek_tahap_id">
                            <option value="">— pilih tahap —</option>
                            <optgroup label="Sedang berjalan">
                                @foreach ($tahap->where('status', 'progress') as $t)
                                    <option value="{{ $t['id'] }}">{{ $t['nama'] }}{{ $t['lokasi'] ? ' — '.$t['lokasi'] : '' }} · omset kini {{ $t['omset'] ? rp($t['omset']) : 'kosong' }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="Lainnya (ada ritasi 12 bulan terakhir)">
                                @foreach ($tahap->where('status', '!=', 'progress') as $t)
                                    <option value="{{ $t['id'] }}">{{ $t['nama'] }}{{ $t['lokasi'] ? ' — '.$t['lokasi'] : '' }} · {{ $t['status'] }} · omset {{ $t['omset'] ? rp($t['omset']) : 'kosong' }}</option>
                                @endforeach
                            </optgroup>
                        </select>
                        <div class="ps-info redup" id="f-saran"></div></div>
                    <div class="l2"><label for="f-catatan">Catatan</label><input id="f-catatan" name="catatan" maxlength="500" autocomplete="off"></div>
                    <div class="ps-tombol">
                        <button type="submit" name="aksi" value="tautkan" class="tombol" id="b-tautkan">✔ Tautkan ke tahap &amp; isi omset</button>
                        <button type="submit" name="aksi" value="simpan" class="tombol polos">Simpan koreksi</button>
                        <button type="submit" name="aksi" value="abaikan" class="tombol polos" id="b-abaikan" onclick="return confirm('Abaikan dokumen ini? (tidak dihitung ke omset)')">Abaikan</button>
                        <button type="submit" name="aksi" value="kembalikan" class="tombol polos" id="b-kembali">↩ Lepas tautan / kembalikan</button>
                        <span class="redup" style="font-size: 12px;" id="f-dicek"></span>
                    </div>
                </form>
            </div>

            <div class="kartu gulir" style="padding: 0;">
                <table class="kas" id="ps-tabel">
                    <thead><tr><th>Jenis</th><th>Kode</th><th>Tanggal</th><th>Pekerjaan / lokasi</th><th class="angka">Volume</th><th class="angka">Harga</th><th class="angka">Nilai</th><th>Tahap</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($baris as $d)
                            <tr data-id="{{ $d->id }}">
                                <td>{{ $d->jenis === 'spk' ? 'SPK' : 'Penawaran' }}</td>
                                <td style="white-space: nowrap;"><b>{{ $d->kode ?? '—' }}</b></td>
                                <td style="white-space: nowrap;" data-n="{{ $d->tanggal?->format('Ymd') ?? $d->waktu_email?->format('Ymd') }}">{{ ($d->tanggal ?? $d->waktu_email)?->translatedFormat('j M Y') }}</td>
                                <td class="teks">{{ \Illuminate\Support\Str::limit($d->pekerjaan, 110) }}@if ($d->lokasi)<div class="redup">📍 {{ $d->lokasi }}</div>@endif</td>
                                <td class="angka" data-n="{{ $d->volume }}">{{ $d->volume !== null ? number_format($d->volume, 2, ',', '.') : '' }}</td>
                                <td class="angka" data-n="{{ $d->harga }}">{{ $d->harga !== null ? rp($d->harga) : '' }}</td>
                                <td class="angka" data-n="{{ $d->nilai }}"><b>{{ $d->nilai !== null ? rp($d->nilai) : '' }}</b></td>
                                <td class="teks">@if ($d->tahap)<b>{{ $d->tahap->nama }}</b>@elseif ($d->saranTahap)<span class="redup">usul: {{ $d->saranTahap->nama }}</span>@endif</td>
                                <td>@if ($d->status === 'ditautkan')<span class="label hijau">Ditautkan</span>@elseif ($d->status === 'diabaikan')<span class="label">Diabaikan</span>@elseif ($d->jenis === 'spk')<span class="label kuning">Belum dicek</span>@else<span class="label">Penawaran</span>@endif
                                    @if ($d->dicek_at && $d->status === 'baru')<div class="redup" style="font-size: 11px;">dikoreksi</div>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="redup" style="padding: 20px 14px;">Tidak ada dokumen di tampilan ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="kartu ps-pdf">
            <iframe id="ps-iframe" title="PDF dokumen" hidden></iframe>
            <p class="redup kosong" id="ps-kosong">Klik salah satu baris untuk melihat PDF-nya di sini.</p>
        </div>
    </div>

    @php
        $data = $baris->mapWithKeys(fn ($d) => [$d->id => [
            'id' => $d->id, 'jenis' => $d->jenis, 'kode' => $d->kode, 'nomor' => $d->nomor, 'tanggal' => $d->tanggal?->toDateString(),
            'pihak' => $d->pihak, 'lokasi' => $d->lokasi, 'pekerjaan' => $d->pekerjaan,
            'volume' => $d->volume !== null ? number_format($d->volume, 2, ',', '.') : '', 'harga' => $d->harga !== null ? rp($d->harga) : '', 'nilai' => $d->nilai !== null ? rp($d->nilai) : '',
            'catatan' => $d->catatan, 'status' => $d->status, 'tahap' => $d->proyek_tahap_id ?? $d->saran_tahap_id, 'tertaut' => (bool) $d->proyek_tahap_id,
            'saran' => $d->saranTahap ? 'Usulan: '.$d->saranTahap->nama.($d->saran_alasan ? ' ('.$d->saran_alasan.')' : '') : null,
            'email' => trim(($d->waktu_email?->translatedFormat('j M Y H:i') ?? '').' · '.$d->subjek),
            'dicek' => $d->dicek_at ? 'Terakhir dicek '.$d->dicek_at->translatedFormat('j M Y H:i').($d->user ? ' oleh '.$d->user->name : '') : 'Angka hasil baca otomatis — belum dicek',
            'pdf' => route('penawaran-spk.pdf', $d), 'ubah' => route('penawaran-spk.ubah', $d),
            'penawaran' => $d->penawaran ? ['id' => $d->penawaran->id, 'kode' => $d->penawaran->kode, 'nilai' => $d->penawaran->nilai, 'volume' => $d->penawaran->volume, 'harga' => $d->penawaran->harga,
                'pdf' => route('penawaran-spk.pdf', $d->penawaran->id)] : null,
            'kode_penawaran' => $d->kode_penawaran,
        ]]);
    @endphp
    <script>
        (() => {
            const data = @json($data), pilihAwal = {{ $pilih ?: 'null' }};
            const $ = id => document.getElementById(id);
            const form = $('ps-form'), iframe = $('ps-iframe');
            const angka = s => { s = String(s || '').replace(/[^\d.,]/g, ''); if (!s) return null;
                if (/,\d{1,3}$/.test(s)) s = s.replace(/\./g, '').replace(',', '.'); else if (/^\d{1,3}([.,]\d{3})+$/.test(s)) s = s.replace(/[.,]/g, ''); else s = s.replace(',', '.');
                const n = parseFloat(s); return isNaN(n) ? null : n; };
            const rp = n => n == null ? '' : Math.round(n).toLocaleString('id-ID');
            const kali = () => {
                const v = angka($('f-volume').value), h = angka($('f-harga').value), n = angka($('f-nilai').value);
                if (v == null || h == null) { $('f-kali').textContent = ''; return; }
                const k = v * h, beda = n != null && Math.abs(k - n) > 1000;
                $('f-kali').innerHTML = `volume × harga = Rp ${rp(k)}` + (beda ? ` <span class="ps-beda">≠ nilai (selisih ${rp(n - k)})</span>` : ' ✓');
            };
            ['f-volume', 'f-harga', 'f-nilai'].forEach(id => $(id).addEventListener('input', kali));
            const tampilPdf = url => { iframe.hidden = false; $('ps-kosong').hidden = true; if (iframe.dataset.url !== url) { iframe.src = url + '#view=FitH'; iframe.dataset.url = url; } };
            const pilih = id => {
                const d = data[id]; if (!d) return;
                document.querySelectorAll('#ps-tabel tr.dipilih').forEach(r => r.classList.remove('dipilih'));
                document.querySelector(`#ps-tabel tr[data-id="${id}"]`)?.classList.add('dipilih');
                form.action = d.ubah;
                $('f-jenis').textContent = d.jenis === 'spk' ? 'SPK' : 'Penawaran';
                $('f-judul').textContent = d.kode || '';
                $('f-email').textContent = d.email;
                $('f-pihak-l').textContent = d.jenis === 'spk' ? 'Pemberi kerja' : 'Kepada';
                for (const k of ['kode', 'nomor', 'tanggal', 'pihak', 'lokasi', 'pekerjaan', 'volume', 'harga', 'nilai', 'catatan']) $('f-' + k).value = d[k] ?? '';
                $('f-tahap').value = d.tahap ?? '';
                $('f-saran').textContent = d.tertaut ? 'Sudah ditautkan.' : (d.saran || 'Belum ada usulan tahap — pilih sendiri.');
                const spk = d.jenis === 'spk';
                $('f-tahap-w').hidden = !spk; $('b-tautkan').hidden = !spk;
                $('b-tautkan').textContent = d.tertaut ? '✔ Simpan & hitung ulang omset tahap' : '✔ Tautkan ke tahap & isi omset';
                $('b-abaikan').hidden = d.status === 'diabaikan'; $('b-kembali').hidden = d.status === 'baru';
                $('f-dicek').textContent = d.dicek;
                const p = d.penawaran;
                $('f-penawaran').innerHTML = p
                    ? `Merujuk <a href="#" id="f-buka-pen">penawaran ${p.kode}</a>: ${p.volume != null ? p.volume.toLocaleString('id-ID') + ' m³ × Rp ' + rp(p.harga) + ' = ' : ''}Rp ${rp(p.nilai)} · <a href="#" id="f-buka-spk">kembali ke PDF SPK</a>`
                    : (d.kode_penawaran ? `<span class="redup">Merujuk penawaran no. ${d.kode_penawaran} (belum ada di Gmail yang terbaca)</span>` : '');
                $('f-buka-pen')?.addEventListener('click', e => { e.preventDefault(); tampilPdf(p.pdf); });
                $('f-buka-spk')?.addEventListener('click', e => { e.preventDefault(); tampilPdf(d.pdf); });
                $('ps-kartu-form').hidden = false;
                kali();
                tampilPdf(d.pdf);
                history.replaceState(null, '', location.pathname + '?tampil={{ $tampil }}&pilih=' + id);
            };
            document.querySelectorAll('#ps-tabel tbody tr[data-id]').forEach(r => r.addEventListener('click', () => pilih(r.dataset.id)));
            const awal = pilihAwal && data[pilihAwal] ? pilihAwal : document.querySelector('#ps-tabel tbody tr[data-id]')?.dataset.id;
            if (awal) pilih(awal);
        })();
    </script>
@endsection
