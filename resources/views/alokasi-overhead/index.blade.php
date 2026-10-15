@extends('layouts.app', ['judul' => 'Alokasi Biaya Umum'])

@section('lebar', '1300px')

@section('isi')
    @php($namaBulan = fn ($ym) => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $ym.'-01')->translatedFormat('M Y'))
    <style>
        .pos { margin-bottom: 14px; }
        .pos-kepala { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
        .pos-kepala h4 { margin: 0; color: var(--aksen-terang); }
        table.calon { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.calon th, table.calon td { border: 1px solid var(--garis); padding: 4px 8px; }
        table.calon th { text-align: left; background: var(--kartu-2, #262b33); }
        table.calon tr.lain td { opacity: .75; }
        table.calon tr.dipilih td { background: rgba(76, 195, 138, .07); }
        .angka { text-align: right !important; white-space: nowrap; }
        .chip small { opacity: .8; }
    </style>

    <div class="kartu">
        <p class="redup" style="margin: 0 0 8px; font-size: 13px;">Biaya Kas Harian yang masuk pos biaya umum (mis. ASG tanpa tahap, kantor JKT) dibagi tiap bulan ke tahap yang <b>berjalan</b> di bulan itu
            (ada ritasi, BBM, atau Kas). Centang awal = <b>rekomendasi</b> (proyek induk yang sama, atau semua proyek untuk pos umum). Ubah bila perlu lalu <b>Simpan</b> — hanya alokasi tersimpan yang masuk realisasi HPP di Anggaran Proyek.
            Proporsional omset memakai omset rencana anggaran (atau omset tahap bila anggaran belum ada).</p>
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            @foreach ($bulanAda as $ym)
                @php($st = $statusBulan[$ym])
                <a href="{{ route('alokasi-overhead.index', ['bulan' => $ym]) }}" @class(['chip', 'aktif' => $ym === $bulan]) title="{{ $st['disimpan'] }} dari {{ $st['pos'] }} pos sudah disimpan">
                    {{ $namaBulan($ym) }} <small>{{ $st['disimpan'] >= $st['pos'] ? '✓' : $st['disimpan'].'/'.$st['pos'] }}</small></a>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('alokasi-overhead.simpan') }}" id="form-alokasi">
        @csrf
        <input type="hidden" name="bulan" value="{{ $bulan }}">
        @forelse ($kartu as $k)
            <div class="kartu pos" data-biaya="{{ $k->biaya }}">
                <div class="pos-kepala">
                    <h4>{{ $k->p->nama }}</h4>
                    <span>Biaya {{ $namaBulan($bulan) }}: <b>{{ rp((int) round($k->biaya)) }}</b></span>
                    <span class="redup">· rekomendasi: {{ \App\Support\Overhead::CAKUPAN[$k->p->pos_overhead] }}{{ $k->p->pos_overhead === 'induk' ? ' ('.$k->p->proyek->nama.')' : '' }}</span>
                    @if ($k->simpan)
                        <span class="label hijau">disimpan {{ $k->simpan->updated_at->translatedFormat('j M Y') }}</span>@if ($k->beda)<span class="label kuning">pilihan berbeda dari rekomendasi</span>@endif
                    @else
                        <span class="label kuning">rekomendasi — belum disimpan</span>
                    @endif
                    <span style="margin-left: auto; display: flex; gap: 12px;">
                        @foreach (\App\Models\AlokasiOverhead::METODE as $m => $l)
                            <label style="display: inline-flex; gap: 4px; align-items: center; font-size: 13px;"><input type="radio" name="pos[{{ $k->p->id }}][metode]" value="{{ $m }}" @checked($k->metode === $m)> {{ $l }}</label>
                        @endforeach
                    </span>
                </div>
                @if ($k->calon->isEmpty())
                    <p class="redup" style="margin: 0;">Tidak ada tahap yang berjalan di bulan ini — biaya pos ini belum bisa dibagi.</p>
                @else
                    <table class="calon" data-tanpa-saring-kolom data-tanpa-beku>
                        <thead><tr><th style="width: 34px;"></th><th>Tahap berjalan</th><th>Proyek induk</th><th class="angka">Omset (basis)</th><th class="angka">Porsi</th><th class="angka">Bagian biaya</th></tr></thead>
                        <tbody>
                            @foreach ($k->calon as $c)
                                <tr @class(['lain' => ! $c->rekom, 'dipilih' => $c->pilih]) data-basis="{{ $c->basis }}">
                                    <td style="text-align: center;"><input type="checkbox" name="pos[{{ $k->p->id }}][tahap][]" value="{{ $c->t->id }}" @checked($c->pilih)></td>
                                    <td><b>{{ $c->t->nama }}</b>@if ($c->rekom) <span class="label" style="font-size: 10px;">rekomendasi</span>@endif</td>
                                    <td class="redup">{{ $c->t->proyek->nama }}</td>
                                    <td class="angka">{{ $c->basis ? rp((int) round($c->basis)) : '–' }}</td>
                                    <td class="angka porsi"></td>
                                    <td class="angka bagian"></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="peringatan" style="font-size: 12px; margin-top: 4px; color: #f0c05a;" hidden></div>
                @endif
            </div>
        @empty
            <div class="kartu"><p class="redup" style="margin: 0;">Tidak ada biaya pos biaya umum di {{ $namaBulan($bulan) }}.</p></div>
        @endforelse
        @if ($kartu->isNotEmpty())
            <div class="kartu" style="display: flex; gap: 10px; align-items: center;">
                <button type="submit" class="tombol">Simpan alokasi {{ $namaBulan($bulan) }}</button>
                <span class="redup" style="font-size: 13px;">Pos tanpa centang = tidak dialokasikan. Biaya pos dihitung ulang dari Kas, jadi transaksi susulan ikut terbagi.</span>
            </div>
        @endif
    </form>

    <details class="kartu">
        <summary style="cursor: pointer;"><b>Pengaturan pos biaya umum</b> <span class="redup" style="font-size: 13px;">— tahap mana yang biayanya dibagi ke proyek</span></summary>
        <form method="POST" action="{{ route('alokasi-overhead.pos') }}" style="margin-top: 10px;">
            @csrf
            <table class="calon" data-tanpa-saring-kolom data-tanpa-beku>
                <thead><tr><th>Tahap (pos biaya)</th><th class="angka">Biaya 12 bulan</th><th>Dibagi ke</th><th>Metode bawaan</th></tr></thead>
                <tbody>
                    @foreach ($calonPos as $t)
                        @php($setahun = collect($kasPos[$t->id] ?? [])->filter(fn ($v, $ym) => $ym >= now()->subMonths(11)->format('Y-m'))->sum())
                        <tr>
                            <td><b>{{ $t->nama }}</b> <span class="redup" style="font-size: 11px;">· {{ $t->proyek->nama }}</span></td>
                            <td class="angka">{{ $setahun ? rp((int) round($setahun)) : '–' }}</td>
                            <td><select name="pos[{{ $t->id }}][cakupan]">
                                <option value="">Tidak dibagi (bukan biaya umum)</option>
                                @foreach (\App\Support\Overhead::CAKUPAN as $c => $l)<option value="{{ $c }}" @selected($t->pos_overhead === $c)>{{ $l }}</option>@endforeach
                            </select></td>
                            <td><select name="pos[{{ $t->id }}][metode]">@foreach (\App\Models\AlokasiOverhead::METODE as $m => $l)<option value="{{ $m }}" @selected($t->metode_overhead === $m)>{{ $l }}</option>@endforeach</select></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <button type="submit" class="tombol polos" style="margin-top: 10px;">Simpan pengaturan pos</button>
        </form>
    </details>

    <script>
        (() => {
            const rp = n => Math.round(n).toLocaleString('id-ID');
            // Porsi & bagian dihitung ulang saat centang / metode berubah (sama dengan perhitungan server).
            const hitung = kartu => {
                const biaya = parseFloat(kartu.dataset.biaya) || 0;
                const metode = kartu.querySelector('input[type=radio]:checked')?.value;
                const baris = [...kartu.querySelectorAll('tbody tr')];
                const pilih = baris.filter(tr => tr.querySelector('input[type=checkbox]').checked);
                let bobot = pilih.map(tr => metode === 'omset' ? Math.max(0, parseFloat(tr.dataset.basis) || 0) : 1);
                const rataPaksa = metode === 'omset' && pilih.length && bobot.every(b => b === 0);
                if (rataPaksa) bobot = bobot.map(() => 1);
                const total = bobot.reduce((a, b) => a + b, 0);
                baris.forEach(tr => { tr.classList.remove('dipilih'); tr.querySelector('.porsi').textContent = ''; tr.querySelector('.bagian').textContent = ''; });
                pilih.forEach((tr, i) => {
                    tr.classList.add('dipilih');
                    const p = total ? bobot[i] / total : 0;
                    tr.querySelector('.porsi').textContent = (p * 100).toFixed(1).replace('.', ',') + '%';
                    tr.querySelector('.bagian').textContent = rp(biaya * p);
                });
                // Metode omset: tahap terpilih tanpa omset mendapat 0 — beri tahu supaya omset/anggarannya diisi atau pakai metode rata.
                const nol = metode === 'omset' && !rataPaksa ? pilih.filter(tr => !(parseFloat(tr.dataset.basis) > 0)).map(tr => tr.querySelector('td:nth-child(2) b').textContent) : [];
                const w = kartu.querySelector('.peringatan');
                if (w) {
                    w.hidden = !rataPaksa && !nol.length;
                    w.textContent = rataPaksa ? '⚠ Semua tahap terpilih belum punya omset — porsi dibagi rata.'
                        : `⚠ ${nol.length} tahap terpilih belum punya omset sehingga mendapat 0: ${nol.join(', ')}. Isi omset/anggarannya, atau pakai metode Rata per proyek.`;
                }
            };
            document.querySelectorAll('.pos[data-biaya]').forEach(k => { k.addEventListener('change', () => hitung(k)); hitung(k); });
        })();
    </script>
@endsection
