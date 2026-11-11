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
        /* Form: label kecil abu tebal di atas, kotak isian seragam (satu font, satu ukuran, satu tinggi) */
        #ps-kartu-form { padding: 0; overflow: hidden; }
        #ps-kartu-form [hidden] { display: none !important; }
        .ps-kepala { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 20px; border-bottom: 1px solid var(--garis); background: var(--kartu-2); }
        .ps-kepala h3 { margin: 0; font-size: 17px; font-weight: 650; display: flex; align-items: center; gap: 10px; }
        .ps-lencana { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; padding: 3px 8px; border-radius: 6px; background: var(--aksen-muda); color: var(--aksen-terang); }
        .ps-lencana.pen { background: rgba(120, 160, 255, .14); color: #9db8ff; }
        .ps-kepala .meta { font-size: 12px; color: var(--redup); text-align: right; max-width: 55%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ps-form { padding: 6px 20px 18px; }
        .ps-bagian { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 14px 14px; padding: 14px 0 16px; border-bottom: 1px solid var(--garis); }
        .ps-bagian:last-of-type { border-bottom: 0; }
        .ps-bagian > h4 { grid-column: span 6; margin: 0 0 -4px; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--aksen-terang); }
        .ps-medan { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .ps-form label { margin: 0; }
        .ps-form input:not([type=hidden]), .ps-form select, .ps-form textarea { width: 100%; }
        .ps-form textarea { min-height: 64px; }
        .ps-form select { padding-right: 30px; text-overflow: ellipsis; }
        .ps-form input.angka { text-align: right; font-variant-numeric: tabular-nums; }
        .ps-form .l2 { grid-column: span 2; } .ps-form .l3 { grid-column: span 3; } .ps-form .l4 { grid-column: span 4; } .ps-form .l6 { grid-column: span 6; }
        @media (max-width: 700px) { .ps-form .l2, .ps-form .l3, .ps-form .l4 { grid-column: span 6; } }
        .ps-info { font-size: 12px; color: var(--redup); line-height: 1.45; }
        .ps-info:empty { display: none; }
        .ps-cek { font-size: 12px; font-variant-numeric: tabular-nums; padding: 6px 10px; border-radius: 8px; background: var(--kartu-2); color: var(--redup); }
        .ps-cek:empty { display: none; }
        .ps-cek.cocok { background: var(--sukses-muda); color: var(--sukses); }
        .ps-cek.beda { background: var(--merah-muda); color: var(--merah); font-weight: 600; }
        .ps-catatan-box { font-size: 13px; line-height: 1.5; padding: 10px 12px; border-radius: 8px; background: var(--kartu-2); border-left: 3px solid var(--garis-kuat); color: var(--teks); }
        .ps-catatan-box:empty { display: none; }
        .ps-catatan-box.saran { border-left-color: var(--aksen); }
        .ps-tombol { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; padding-top: 16px; border-top: 1px solid var(--garis); }
        .ps-tombol .tombol { font-size: 14px; height: 40px; padding: 0 16px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
        .ps-tombol .tombol.polos { font-weight: 500; }
        .ps-tombol .status { margin-left: auto; font-size: 12px; color: var(--redup); }
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
                <div class="ps-kepala">
                    <h3><span class="ps-lencana" id="f-jenis"></span> <span id="f-judul"></span></h3>
                    <span class="meta" id="f-email"></span>
                </div>
                <form method="POST" id="ps-form" class="ps-form">
                    @csrf @method('PATCH')
                    <input type="hidden" name="tampil" value="{{ $tampil }}">
                    <div class="ps-bagian">
                        <h4>Dokumen</h4>
                        <div class="ps-medan l2"><label for="f-kode">Kode</label><input id="f-kode" name="kode" maxlength="60" autocomplete="off"></div>
                        <div class="ps-medan l2"><label for="f-nomor">Nomor dokumen</label><input id="f-nomor" name="nomor" maxlength="100" autocomplete="off"></div>
                        <div class="ps-medan l2"><label for="f-tanggal">Tanggal</label><input id="f-tanggal" name="tanggal" type="date"></div>
                        <div class="ps-medan l3"><label for="f-pihak" id="f-pihak-l">Pihak</label><input id="f-pihak" name="pihak" maxlength="200" autocomplete="off"></div>
                        <div class="ps-medan l3"><label for="f-lokasi">Lokasi</label><input id="f-lokasi" name="lokasi" maxlength="250" autocomplete="off"></div>
                        <div class="ps-medan l6"><label for="f-pekerjaan">Pekerjaan</label><textarea id="f-pekerjaan" name="pekerjaan" maxlength="2000" rows="2"></textarea></div>
                    </div>
                    <div class="ps-bagian">
                        <h4>Nilai</h4>
                        <div class="ps-medan l2"><label for="f-volume">Volume (m³)</label><input id="f-volume" name="volume" class="angka" inputmode="decimal" autocomplete="off"></div>
                        <div class="ps-medan l2"><label for="f-harga">Harga per m³ (Rp)</label><input id="f-harga" name="harga" class="angka" inputmode="numeric" autocomplete="off"></div>
                        <div class="ps-medan l2"><label for="f-nilai">Nilai (Rp, belum PPN)</label><input id="f-nilai" name="nilai" class="angka" inputmode="numeric" autocomplete="off"></div>
                        <div class="ps-cek l6" id="f-kali"></div>
                        <div class="ps-catatan-box l6" id="f-penawaran"></div>
                    </div>
                    <div class="ps-bagian">
                        <h4 id="f-bagian-tahap">Tautan ke tahap</h4>
                        <div class="ps-medan l4" id="f-tahap-w"><label for="f-tahap">Tahap proyek</label>
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
                        </select></div>
                        <div class="ps-medan l2"><label for="f-catatan">Catatan</label><input id="f-catatan" name="catatan" maxlength="500" autocomplete="off"></div>
                        <div class="ps-catatan-box saran l6" id="f-saran"></div>
                    </div>
                    <div class="ps-tombol">
                        <button type="submit" name="aksi" value="tautkan" class="tombol" id="b-tautkan">✔ Tautkan ke tahap &amp; isi omset</button>
                        <button type="submit" name="aksi" value="simpan" class="tombol polos">Simpan koreksi</button>
                        <button type="submit" name="aksi" value="abaikan" class="tombol polos" id="b-abaikan" onclick="return confirm('Abaikan dokumen ini? (tidak dihitung ke omset)')">Abaikan</button>
                        <button type="submit" name="aksi" value="kembalikan" class="tombol polos" id="b-kembali">↩ Lepas tautan / kembalikan</button>
                        <span class="status" id="f-dicek"></span>
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
                const el = $('f-kali');
                if (v == null || h == null) { el.textContent = ''; el.className = 'ps-cek l6'; return; }
                const k = v * h, beda = n != null && Math.abs(k - n) > 1000;
                el.className = 'ps-cek l6 ' + (beda ? 'beda' : 'cocok');
                el.textContent = `Volume × harga = Rp ${rp(k)}` + (beda ? ` — tidak sama dengan nilai (selisih Rp ${rp(n - k)})` : ' ✓ sama dengan nilai');
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
                $('f-tahap-w').hidden = !spk; $('f-saran').hidden = !spk; $('b-tautkan').hidden = !spk;
                $('f-bagian-tahap').textContent = spk ? 'Tautan ke tahap' : 'Catatan';
                $('f-catatan').closest('.ps-medan').className = 'ps-medan ' + (spk ? 'l2' : 'l6');
                $('f-jenis').classList.toggle('pen', !spk);
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
