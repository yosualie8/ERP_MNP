@extends('layouts.app', ['judul' => 'Rekap Absen & Gaji Mingguan'])

@section('lebar', '1500px')

@section('isi')
    @php
        $minggu = $hari->last();
        $pendek = fn (?string $t) => $t ? mb_strimwidth(preg_replace(['/^ASG Tahap /i', '/^Pantai Mutiara 2 - /i', '/^Proyek Industri /i'], ['T', 'PM2 ', ''], $t), 0, 14, '…') : null;
        $pilihan = fn ($v) => $v === null ? 'auto' : ($v ? '1' : '0');
    @endphp
    <style>
        .ringkas-gaji { display: grid; grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas-gaji .kartu { margin: 0; padding: 10px 14px; }
        .ringkas-gaji b { display: block; font-size: 21px; }
        .grid-absen td.hr { text-align: center; white-space: nowrap; font-size: 12px; cursor: pointer; min-width: 70px; }
        .grid-absen td.hr:hover { outline: 1px solid var(--aksen); outline-offset: -1px; }
        .grid-absen td.hr .s { font-weight: 700; font-size: 13px; }
        .grid-absen td.hr .t { display: block; font-size: 10.5px; color: var(--redup); }
        .grid-absen td.hr.bayar .s { color: var(--sukses); }
        .grid-absen td.hr.tunggu { background: rgba(240, 192, 90, .16); }
        .grid-absen td.hr.tunggu .s { color: #f0c05a; }
        .grid-absen td.hr.tolak .s { color: var(--merah); }
        .grid-absen td.hr.kosong .s { color: var(--redup); font-weight: 400; }
        .grid-absen td.um { cursor: pointer; }
        .grid-absen td.um:hover { outline: 1px solid var(--aksen); outline-offset: -1px; }
        .grid-absen td.um.sendiri { color: #8ab4f8; }
        .grid-absen tr.tanpa td { opacity: .5; }
        .legenda-absen { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12.5px; color: var(--redup); margin: 0 0 10px; }
        .legenda-absen b { font-size: 13px; }
        dialog.atur-hari { border: 1px solid var(--garis-kuat); border-radius: 10px; background: var(--kartu); color: var(--teks); width: min(440px, 94vw); padding: 16px; }
        dialog.atur-hari::backdrop { background: rgba(0, 0, 0, .55); }
        dialog.atur-hari label.baris { display: block; margin: 10px 0 4px; font-weight: 600; font-size: 13px; }
        dialog.atur-hari .pilih3 { display: flex; gap: 6px; flex-wrap: wrap; }
        dialog.atur-hari .pilih3 label { display: inline-flex; gap: 5px; align-items: center; padding: 4px 10px; border: 1px solid var(--garis); border-radius: 999px; font-size: 13px; cursor: pointer; margin: 0; }
        dialog.atur-hari select, dialog.atur-hari input[type=text] { width: 100%; box-sizing: border-box; }
    </style>

    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('gaji-mingguan.index', ['minggu' => $senin->copy()->subWeek()->toDateString()]) }}">‹</a>
        <input type="text" name="minggu" data-tanggal-id value="{{ $senin->toDateString() }}" style="width: 150px;" onchange="this.form.submit()">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('gaji-mingguan.index', ['minggu' => $senin->copy()->addWeek()->toDateString()]) }}">›</a>
        <b>{{ $senin->translatedFormat('j M') }} – {{ $minggu->translatedFormat('j M Y') }}</b>
        @if ($ringkas['tunggu'])
            <button type="submit" form="form-setujui" class="tombol polos" style="margin-left: auto;"
                onclick="return confirm('Setujui (bayar) semua {{ $ringkas['tunggu'] }} absen / lembur yang belum lengkap minggu ini? Pastikan sudah diperiksa.')">✔ Setujui semua yang belum lengkap ({{ $ringkas['tunggu'] }})</button>
        @endif
        <a class="tombol polos" style="padding: 6px 12px; {{ $ringkas['tunggu'] ? '' : 'margin-left: auto;' }}" href="{{ route('gaji-mingguan.unduh', ['minggu' => $senin->toDateString()]) }}">⬇ Excel</a>
    </form>
    <form method="POST" action="{{ route('gaji-mingguan.setujui') }}" id="form-setujui" hidden>@csrf<input type="hidden" name="minggu" value="{{ $senin->toDateString() }}"></form>

    <div class="ringkas-gaji">
        <div class="kartu"><span class="redup">Karyawan dibayar</span><b>{{ $ringkas['orang'] }}</b></div>
        <div class="kartu"><span class="redup">Hari kerja dibayar</span><b>{{ $ringkas['hari'] }}</b></div>
        <div class="kartu"><span class="redup">UM</span><b>{{ rp($ringkas['total_um']) }}</b></div>
        <div class="kartu"><span class="redup">Lembur ({{ $ringkas['lembur'] }} × ½ UM)</span><b>{{ rp($ringkas['total_lembur']) }}</b></div>
        <div class="kartu"><span class="redup">Total dibayar</span><b>{{ rp($ringkas['total']) }}</b></div>
        <div class="kartu"><span class="redup">Menunggu persetujuan</span><b @if ($ringkas['tunggu']) style="color: #f0c05a;" @endif>{{ $ringkas['tunggu'] }}</b>
            <span class="redup" style="font-size: 12px;">absen / lembur belum lengkap</span></div>
        <div class="kartu"><span class="redup">Hadir tanpa UM</span><b @if ($ringkas['tanpa_um']) style="color: var(--merah);" @endif>{{ $ringkas['tanpa_um'] }}</b>
            <span class="redup" style="font-size: 12px;">klik kolom UM / isi Master Jabatan</span></div>
    </div>

    <div class="legenda-absen">
        <span><b style="color: var(--sukses);">✓</b> absen lengkap · dibayar</span>
        <span><b style="color: var(--sukses);">A</b> disetujui admin</span>
        <span><b style="color: var(--sukses);">M</b> diisi mandor</span>
        <span><b style="color: #f0c05a;">?</b> absen belum lengkap — menunggu persetujuan</span>
        <span><b style="color: var(--merah);">✗</b> tidak dibayar</span>
        <span><b>+L</b> lembur ½ UM (<b>+L?</b> menunggu)</span>
        <span>Klik sel hari untuk menyetujui / menolak / mengisi tahap · klik UM untuk mengubah UM orang itu.</span>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas grid-absen">
            <thead><tr>
                <th>Karyawan</th><th>Jabatan</th><th>Site</th><th class="angka">UM / hari</th>
                @foreach ($hari as $h)<th style="text-align: center;">{{ $h->translatedFormat('D') }} {{ $h->format('j/n') }}</th>@endforeach
                <th class="angka">Hari</th><th class="angka">Lembur</th><th class="angka">Total</th>
            </tr></thead>
            <tbody>
                @forelse ($baris as $b)
                    <tr @class(['tanpa' => ! $b->ada])>
                        <td data-v="{{ $b->k->nama }}"><b>{{ $b->k->nama }}</b></td>
                        <td>{{ $b->k->jabatan }}</td>
                        <td>{{ $b->k->site }}</td>
                        <td @class(['angka', 'um', 'sendiri' => $b->um_sendiri]) data-n="{{ (int) $b->um }}" data-k="{{ $b->k->id }}" data-nama="{{ $b->k->nama }}"
                            data-um="{{ $b->k->um_harian }}" title="{{ $b->um_sendiri ? 'UM khusus orang ini' : 'UM dari jabatan' }} — klik untuk mengubah">{{ $b->um ? rp($b->um) : '–' }}</td>
                        @foreach ($b->hari as $d => $x)
                            @php
                                $kelas = $x->tunggu ? 'tunggu' : ($x->hadir ? 'bayar' : ($x->putus_hadir === false && ($x->absen || $x->sumber) ? 'tolak' : 'kosong'));
                                $simbol = match ($kelas) {
                                    'tunggu' => '?', 'tolak' => '✗', 'kosong' => '·',
                                    default => $x->sumber === 'mandor' && $x->putus_hadir ? 'M' : ($x->absen === 'lengkap' && $x->putus_hadir === null ? '✓' : 'A'),
                                };
                                $info = collect([$x->masuk || $x->pulang ? 'masuk '.($x->masuk ?? '–').' · pulang '.($x->pulang ?? '–') : null,
                                    $x->lembur_mulai || $x->lembur_selesai ? 'lembur '.($x->lembur_mulai ?? '–').'–'.($x->lembur_selesai ?? '–') : null,
                                    $x->sumber === 'mandor' ? 'diisi mandor' : null, $x->catatan])->filter()->implode(' · ');
                            @endphp
                            <td class="hr {{ $kelas }}" data-v="{{ $simbol }}" data-k="{{ $b->k->id }}" data-nama="{{ $b->k->nama }}" data-d="{{ $d }}"
                                data-label="{{ $x->tanggal->translatedFormat('l, j M') }}" data-hadir="{{ $pilihan($x->putus_hadir) }}" data-lembur="{{ $pilihan($x->putus_lembur) }}"
                                data-tahap="{{ $x->tahap_diisi ? $x->tahap_id : '' }}" data-tahap-tebak="{{ $x->tahap }}" data-catatan="{{ $x->catatan }}" data-info="{{ $info }}"
                                title="{{ $info }}{{ $x->tahap ? ' · '.$x->tahap : '' }}">
                                <span class="s">{{ $simbol }}{{ $x->lembur ? '+L' : ($x->tunggu_lembur ? '+L?' : '') }}</span>
                                @if ($x->hadir || $x->tunggu)<span class="t">{{ $pendek($x->tahap) ?? 'tahap ?' }}</span>@endif
                            </td>
                        @endforeach
                        <td class="angka" data-n="{{ $b->hadir }}">{{ $b->hadir ?: '' }}</td>
                        <td class="angka" data-n="{{ $b->lembur }}">{{ $b->lembur ?: '' }}</td>
                        <td class="angka" data-n="{{ $b->total }}"><b>{{ $b->total ? rp($b->total) : '' }}</b></td>
                    </tr>
                @empty
                    <tr><td colspan="14" class="redup" style="padding: 20px 14px;">Belum ada karyawan.</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr>
                <th colspan="11">Total</th><th class="angka">{{ $ringkas['hari'] }}</th><th class="angka">{{ $ringkas['lembur'] }}</th><th class="angka">{{ rp($ringkas['total']) }}</th>
            </tr></tfoot>
        </table>
    </div>

    <div class="kartu gulir" style="padding: 0; max-width: 560px;">
        <div style="padding: 12px 14px 0;"><b>UM per tahap / proyek</b> <span class="redup" style="font-size: 12.5px;">— UM + lembur dibebankan ke tahap tempat bekerja hari itu</span></div>
        <table class="kas">
            <thead><tr><th>Tahap / Proyek</th><th class="angka">Total</th></tr></thead>
            <tbody>
                @forelse ($perTahap as $t => $v)
                    <tr><td @if ($t === \App\Support\RekapAbsenMingguan::BELUM_TAHAP) style="color: var(--merah);" @endif>{{ $t }}</td><td class="angka" data-n="{{ $v }}">{{ rp($v) }}</td></tr>
                @empty
                    <tr><td colspan="2" class="redup">Belum ada yang dibayar minggu ini.</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr><th>Total</th><th class="angka">{{ rp($perTahap->sum()) }}</th></tr></tfoot>
        </table>
    </div>

    <dialog class="atur-hari" id="atur-hari">
        <form method="dialog" id="form-hari">
            <b id="ah-judul"></b>
            <div class="redup" id="ah-info" style="font-size: 12.5px; margin-top: 4px;"></div>
            <label class="baris">Hari ini dibayar?</label>
            <div class="pilih3">
                <label><input type="radio" name="hadir" value="auto"> Ikut absen</label>
                <label><input type="radio" name="hadir" value="1"> Bayar</label>
                <label><input type="radio" name="hadir" value="0"> Tidak dibayar</label>
            </div>
            <label class="baris">Lembur (½ UM)?</label>
            <div class="pilih3">
                <label><input type="radio" name="lembur" value="auto"> Ikut absen</label>
                <label><input type="radio" name="lembur" value="1"> Bayar lembur</label>
                <label><input type="radio" name="lembur" value="0"> Tanpa lembur</label>
            </div>
            <label class="baris" for="ah-tahap">Tahap kerja</label>
            <select id="ah-tahap" name="tahap">
                <option value="" id="ah-tahap-tebak">(otomatis)</option>
                @foreach ($tahap as $id => $nama)<option value="{{ $id }}">{{ $nama }}</option>@endforeach
            </select>
            <label style="display: flex; gap: 6px; align-items: center; font-size: 13px; margin-top: 6px;"><input type="checkbox" name="semua_minggu" value="1"> Pakai tahap ini untuk semua hari kerja orang ini minggu ini</label>
            <label class="baris" for="ah-catatan">Catatan</label>
            <input type="text" id="ah-catatan" name="catatan" maxlength="300" placeholder="mis. lupa absen pulang, sudah dicek mandor">
            <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 14px;">
                <button type="button" class="tombol polos" onclick="this.closest('dialog').close()">Batal</button>
                <button type="submit" class="tombol" id="ah-simpan">Simpan</button>
            </div>
        </form>
    </dialog>

    <script>
        (() => {
            const token = document.querySelector('meta[name=csrf-token]').content;
            const kirim = (url, data) => fetch(url, {method: 'POST', headers: {'X-CSRF-TOKEN': token, 'Accept': 'application/json'}, body: data})
                .then(async r => { if (!r.ok) throw new Error((await r.json().catch(() => ({}))).message || r.status); return r.json(); });
            // Muat ulang di posisi gulir yang sama.
            const muatUlang = () => { try { sessionStorage.setItem('gulir-gaji', String(scrollY)); } catch (e) {} location.reload(); };
            try { const y = sessionStorage.getItem('gulir-gaji'); if (y) { sessionStorage.removeItem('gulir-gaji'); addEventListener('load', () => scrollTo(0, +y)); } } catch (e) {}

            const dlg = document.getElementById('atur-hari'), form = document.getElementById('form-hari');
            let sel = null;
            document.querySelector('.grid-absen').addEventListener('click', e => {
                const um = e.target.closest('td.um');
                if (um) {
                    const v = prompt(`UM per hari untuk ${um.dataset.nama} (kosongkan = ikut tarif jabatan):`, um.dataset.um || '');
                    if (v === null) return;
                    const data = new FormData();
                    data.append('karyawan_id', um.dataset.k);
                    data.append('um', v.replace(/\D/g, ''));
                    kirim(@json(route('gaji-mingguan.um')), data).then(muatUlang).catch(err => alert('Gagal menyimpan: ' + err.message));
                    return;
                }
                const td = e.target.closest('td.hr');
                if (!td) return;
                sel = td;
                document.getElementById('ah-judul').textContent = `${td.dataset.nama} — ${td.dataset.label}`;
                document.getElementById('ah-info').textContent = td.dataset.info || 'Tidak ada absen hari ini.';
                form.hadir.value = td.dataset.hadir;
                form.lembur.value = td.dataset.lembur;
                document.getElementById('ah-tahap-tebak').textContent = '(otomatis' + (td.dataset.tahapTebak ? ': ' + td.dataset.tahapTebak : '') + ')';
                form.tahap.value = td.dataset.tahap;
                form.semua_minggu.checked = false;
                form.catatan.value = td.dataset.catatan || '';
                document.getElementById('ah-simpan').disabled = false;
                dlg.showModal();
            });
            form.addEventListener('submit', e => {
                e.preventDefault();
                const data = new FormData(form);
                data.append('karyawan_id', sel.dataset.k);
                data.append('tanggal', sel.dataset.d);
                document.getElementById('ah-simpan').disabled = true;
                kirim(@json(route('gaji-mingguan.hari')), data).then(muatUlang)
                    .catch(err => { alert('Gagal menyimpan: ' + err.message); document.getElementById('ah-simpan').disabled = false; });
            });
        })();
    </script>
@endsection
