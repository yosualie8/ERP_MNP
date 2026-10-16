@extends('layouts.app', ['judul' => 'Pilah Transaksi Proyek'])

@section('lebar', '1800px')

@section('isi')
    @php
        $tgl = fn ($d) => \Illuminate\Support\Carbon::parse($d)->translatedFormat('d-M-y');
        $dataTahap = $tahap->mapWithKeys(fn ($t) => [$t->nama => $t->id]);
        $dataBoq = $boq;
        $qs = ['proyek' => $pilih->all(), 'dari' => $dari];
        $rpTotal = $baris->sum('nominal');
    @endphp
    <style>
        table.kas td.pilih input, table.kas td.pilih select { width: 100%; box-sizing: border-box; padding: 3px 6px; font-size: 12.5px; }
        table.kas .rek { font-size: 12px; line-height: 1.35; }
        table.kas .rek .dasar { display: block; font-size: 10.5px; color: var(--redup); }
        table.kas .rek button { padding: 1px 7px; font-size: 11px; margin-top: 2px; }
        table.kas .rek.sesuai { color: var(--sukses); }
        table.kas .status { font-size: 11.5px; }
        table.kas .status.tidak { color: var(--redup); }
        table.kas .catatan-bca { display: block; font-size: 12px; font-weight: 600; color: #93c5fd; }
        table.kas tr.ok-simpan td { transition: background .6s; background: rgba(76, 195, 138, .12); }
        table.kas tr.galat-simpan td { background: rgba(255, 92, 97, .12); }
        .ringkas { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas .kartu { margin: 0; padding: 10px 14px; }
        .ringkas b { display: block; font-size: 17px; margin-top: 2px; }
        .ringkas small { font-size: 12px; color: var(--redup); }
    </style>

    <form method="GET" class="kartu" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <span class="redup" style="font-size: 13px;">Proyek induk:</span>
        @foreach ($semuaProyek as $p)
            <label class="chip" style="cursor: pointer; {{ $pilih->contains($p->id) ? '' : 'opacity: .55;' }}"><input type="checkbox" name="proyek[]" value="{{ $p->id }}" @checked($pilih->contains($p->id)) onchange="this.form.submit()" style="margin: 0 4px 0 0;">{{ $p->nama }}</label>
        @endforeach
        <label class="redup" style="font-size: 13px;">sejak <input type="date" name="dari" value="{{ $dari }}" onchange="this.form.submit()"></label>
        <input type="hidden" name="saring" value="{{ $saring }}">
        <span style="margin-left: auto; display: flex; gap: 6px;">
            <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('pilah.index', $qs + ['saring' => $saring, 'segarkan' => 1]) }}" title="Baca ulang tag Proyek/BOQ dari sheet monitoring Infra">↻ Tag sheet</a>
            <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('pilah.unduh', $qs) }}">⬇ Excel</a>
        </span>
    </form>

    <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-bottom: 10px;">
        @foreach (\App\Http\Controllers\PilahTransaksiController::SARING as $k => $l)
            <a href="{{ route('pilah.index', $qs + ['saring' => $k]) }}" @class(['chip', 'aktif' => $saring === $k])>{{ $l }} · {{ number_format($hitungSaring[$k], 0, ',', '.') }}</a>
        @endforeach
        <button type="button" class="tombol" id="terapkan" style="padding: 6px 12px; margin-left: auto;" title="Pakai rekomendasi tahap & BOQ untuk semua baris yang terlihat (setelah saringan kolom) yang rekomendasinya beda">✓ Terapkan rekomendasi (baris terlihat)</button>
    </div>
    @if ($galatSheet)<div class="pesan galat">Tag sheet monitoring Infra tidak terbaca ({{ $galatSheet }}) — rekomendasi hanya dari keterangan & transaksi serupa.</div>@endif

    <div class="ringkas">
        <div class="kartu"><small>Ditampilkan</small><b>{{ number_format($baris->count(), 0, ',', '.') }} dari {{ number_format($semuaBaris, 0, ',', '.') }} transaksi</b><small>{{ rp((int) round($rpTotal)) }}</small></div>
        @foreach ($per->sortByDesc('rp')->take(7) as $id => $p)
            <div class="kartu"><small>{{ $tahapNama[$id] ?? $id }}</small><b>{{ rp((int) round($p['rp'])) }}</b><small>{{ $p['n'] }} transaksi dihitung · ber-BOQ {{ $p['rp'] > 0 ? round($p['boq'] / $p['rp'] * 100) : 0 }}%</small></div>
        @endforeach
    </div>
    <p class="redup" style="font-size: 12px; margin: -4px 0 10px;">
        Pilih <b>tahap detail</b> tiap transaksi (Kas = mengalahkan Kode GL; BCA = otomatis jadi <b>Dihitung</b>) atau <b>— bukan biaya —</b>; lalu <b>kode BOQ</b> (pilihan mengikuti BOQ tahap itu — isi di menu BOQ & Progres Proyek).
        Rekomendasi dari: tag Proyek/BOQ di sheet monitoring Infra → transaksi serupa yang sudah dipilah → kata di keterangan. Tersimpan otomatis tiap kali diubah.
        Tahap tipe <b>Infra</b>: Kas akun HPP (material) & BBM non alat berat ikut dihitung.
    </p>

    <div class="kartu gulir" style="padding: 0;">
        <table class="kas" data-saring-kolom>
            <thead><tr><th>Sumber</th><th>Tanggal</th><th style="min-width: 280px;">Keterangan</th><th>Akun / Kode GL</th><th class="angka">Nominal</th><th>Status hitung</th>
                <th style="width: 240px;">Tahap detail</th><th style="width: 200px;">Rekomendasi tahap</th><th style="width: 240px;">Kode BOQ</th><th style="width: 220px;">Rekomendasi BOQ</th></tr></thead>
            <tbody>
                @forelse ($baris as $x)
                    @php
                        $pilihTahap = $x->abaikan ? '— bukan biaya —' : ($tahapNama[$x->tahap_id] ?? '');
                        $rt = $x->rek_tahap; $rb = $x->rek_boq;
                        $rtNama = $rt ? ($rt[0] === 'abaikan' ? '— bukan biaya —' : ($tahapNama[$rt[0]] ?? '')) : null;
                        $rtSesuai = $rt && $rtNama === $pilihTahap;
                        $rbSesuai = $rb && $rb[0]->id === $x->boq?->id;
                    @endphp
                    <tr data-sumber="{{ $x->sumber }}" data-kunci="{{ $x->kunci }}" data-rek-tahap="{{ $rt && ! $rtSesuai ? $rtNama : '' }}" data-rek-boq="{{ $rb && ! $rbSesuai ? $rb[0]->id : '' }}" data-rek-boq-tahap="{{ $rb ? $rb[0]->proyek_tahap_id : '' }}">
                        <td>@if ($x->sumber === 'bca')<span class="label">BCA</span>@else<span class="label hijau">Kas</span>@endif</td>
                        <td data-n="{{ $x->tanggal }}" data-v="{{ $x->tanggal }} {{ $tgl($x->tanggal) }}" style="white-space: nowrap;">{{ $tgl($x->tanggal) }}</td>
                        <td data-v="{{ $x->keterangan }} {{ $x->catatan }} {{ $x->pic }}">{{ $x->keterangan }}@if ($x->pic)<span class="redup" style="font-size: 11px;"> · {{ $x->pic }}</span>@endif
                            @if ($x->catatan)<span class="catatan-bca">{{ $x->catatan }}</span>@endif</td>
                        <td style="font-size: 12px;">{{ $x->akun }}@if ($x->kode)<br><span class="redup">{{ $x->kode }}</span>@endif</td>
                        <td class="angka" data-n="{{ $x->nominal }}">{{ rp((int) round($x->nominal)) }}</td>
                        <td data-v="{{ $x->status }}"><span @class(['status', 'tidak' => ! $x->dihitung])>{{ $x->status }}</span></td>
                        <td class="pilih" data-v="{{ $pilihTahap }}"><input data-f="tahap" list="daftar-tahap" value="{{ $pilihTahap }}" placeholder="{{ $x->sumber === 'kas' ? 'ikut Kode GL' : 'belum dipilih' }}" autocomplete="off"></td>
                        <td data-v="{{ $rtNama }}">@if ($rt)<div @class(['rek', 'sesuai' => $rtSesuai])>{{ $rtSesuai ? '✓ ' : '' }}{{ $rtNama }}<span class="dasar">{{ $rt[1] }}</span>@if (! $rtSesuai)<button type="button" class="tombol polos" data-pakai="tahap">pakai</button>@endif</div>@endif</td>
                        <td class="pilih" data-v="{{ $x->boq?->label() }}"><select data-f="boq" data-awal="{{ $x->boq?->id }}"></select></td>
                        <td data-v="{{ $rb ? $rb[0]->label() : '' }}">@if ($rb)<div @class(['rek', 'sesuai' => $rbSesuai])>{{ $rbSesuai ? '✓ ' : '' }}{{ $rb[0]->label() }}<span class="dasar">{{ ($tahapNama[$rb[0]->proyek_tahap_id] ?? '') }} · {{ $rb[1] }}</span>@if (! $rbSesuai)<button type="button" class="tombol polos" data-pakai="boq">pakai</button>@endif</div>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="redup" style="padding: 16px;">Tidak ada transaksi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <datalist id="daftar-tahap"><option value="— bukan biaya —">bukan biaya proyek / tidak dihitung</option>@foreach ($tahap as $tp)<option value="{{ $tp->nama }}">{{ $tp->pos_overhead ? 'pos biaya umum · ' : '' }}{{ $tp->proyek->nama }}{{ $tp->status !== 'progress' ? ' · '.\App\Models\ProyekTahap::STATUS[$tp->status] : '' }}</option>@endforeach</datalist>
    <script>
        (() => {
            const TAHAP = @json($dataTahap), BOQ = @json($dataBoq), ABAIKAN = '— bukan biaya —';
            const URL = @json(route('pilah.simpan')), TOKEN = @json(csrf_token());
            const idTahap = v => v === ABAIKAN ? 'abaikan' : (Object.hasOwn(TAHAP, v) ? String(TAHAP[v]) : (v === '' ? '' : null));
            const isiBoq = (tr, pilih) => {
                const sel = tr.querySelector('[data-f=boq]'), t = idTahap(tr.querySelector('[data-f=tahap]').value.trim());
                const daftar = (t && t !== 'abaikan' && BOQ[t]) || [];
                sel.innerHTML = '<option value="">' + (daftar.length ? '— tanpa kode BOQ —' : (t && t !== 'abaikan' ? '(tahap ini belum punya BOQ)' : '—')) + '</option>'
                    + daftar.map(([id, l]) => `<option value="${id}">${l.replace(/</g, '&lt;')}</option>`).join('');
                sel.value = daftar.some(([id]) => String(id) === String(pilih)) ? String(pilih) : '';
                sel.disabled = !daftar.length;
            };
            const nilai = tr => ({sumber: tr.dataset.sumber, kunci: tr.dataset.kunci, tahap: idTahap(tr.querySelector('[data-f=tahap]').value.trim()), boq: tr.querySelector('[data-f=boq]').value || null});
            const kirim = async trs => {
                const data = trs.map(nilai);
                if (data.some(d => d.tahap === null)) { alert('Nama tahap tidak terdaftar — pilih dari daftar (nama persis).'); return; }
                trs.forEach(tr => tr.classList.remove('ok-simpan', 'galat-simpan'));
                try {
                    const res = await fetch(URL, {method: 'POST', body: JSON.stringify({baris: data}), headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': TOKEN}});
                    const h = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(h.galat || h.message || 'Gagal menyimpan');
                    const st = Object.fromEntries(h.baris.map(b => [b.kunci, b.status]));
                    trs.forEach(tr => {
                        tr.classList.add('ok-simpan');
                        const s = tr.querySelector('.status'); s.textContent = st[tr.dataset.kunci]; s.classList.toggle('tidak', !/^dihitung|^Dihitung|biaya umum/.test(st[tr.dataset.kunci]));
                        tr.querySelector('[data-f=tahap]').closest('td').dataset.v = tr.querySelector('[data-f=tahap]').value;
                        const b = tr.querySelector('[data-f=boq]'); b.dataset.awal = b.value; b.closest('td').dataset.v = b.value ? b.selectedOptions[0].textContent : '';
                    });
                } catch (err) { trs.forEach(tr => tr.classList.add('galat-simpan')); alert(err.message); }
            };
            const rows = [...document.querySelectorAll('tr[data-kunci]')];
            rows.forEach(tr => {
                const th = tr.querySelector('[data-f=tahap]'), sel = tr.querySelector('[data-f=boq]');
                isiBoq(tr, sel.dataset.awal);
                let awal = th.value;
                th.addEventListener('input', () => { th.style.borderColor = idTahap(th.value.trim()) === null ? 'var(--merah)' : ''; });
                th.addEventListener('change', () => {
                    if (th.value.trim() === awal.trim() || idTahap(th.value.trim()) === null) return;
                    awal = th.value; isiBoq(tr, sel.dataset.awal); kirim([tr]);
                });
                sel.addEventListener('change', () => kirim([tr]));
                tr.querySelectorAll('[data-pakai]').forEach(btn => btn.addEventListener('click', () => {
                    pakai(tr, btn.dataset.pakai); kirim([tr]);
                }));
            });
            // Pakai rekomendasi: tahap → isi nama tahap; BOQ → bila BOQ milik tahap lain, tahap ikut diganti.
            const pakai = (tr, apa) => {
                const th = tr.querySelector('[data-f=tahap]'), sel = tr.querySelector('[data-f=boq]');
                if (apa === 'tahap' && tr.dataset.rekTahap) { th.value = tr.dataset.rekTahap; isiBoq(tr, sel.value); }
                if (apa === 'boq' && tr.dataset.rekBoq) {
                    const t = tr.dataset.rekBoqTahap, nama = Object.keys(TAHAP).find(n => String(TAHAP[n]) === t);
                    if (idTahap(th.value.trim()) !== t && nama) th.value = nama;
                    isiBoq(tr, tr.dataset.rekBoq);
                }
                tr.querySelectorAll('[data-pakai]').forEach(b => { if (b.dataset.pakai === apa) b.remove(); });
            };
            document.getElementById('terapkan').addEventListener('click', () => {
                const ubah = rows.filter(tr => tr.offsetParent !== null && (tr.dataset.rekTahap || tr.dataset.rekBoq) && tr.querySelector('[data-pakai]'));
                if (!ubah.length) { alert('Tidak ada rekomendasi yang belum dipakai pada baris yang terlihat.'); return; }
                if (!confirm(`Pakai rekomendasi untuk ${ubah.length} baris yang terlihat?`)) return;
                ubah.forEach(tr => { if (tr.querySelector('[data-pakai=tahap]')) pakai(tr, 'tahap'); if (tr.querySelector('[data-pakai=boq]')) pakai(tr, 'boq'); });
                for (let i = 0; i < ubah.length; i += 200) kirim(ubah.slice(i, i + 200));
            });
        })();
    </script>
@endsection
