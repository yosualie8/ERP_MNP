@extends('layouts.app', ['judul' => 'Review Absen Harian'])

@section('lebar', '1200px')

@section('isi')
    <style>
        .ringkas-review { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .ringkas-review .kartu { margin: 0; padding: 10px 14px; }
        .ringkas-review b { display: block; font-size: 22px; }
        #teks-grup { width: 100%; box-sizing: border-box; min-height: 220px; font-family: inherit; font-size: 13px; line-height: 1.45; padding: 10px; }
        .wa { white-space: nowrap; }
    </style>

    <form method="GET" style="display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap;">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('review-absen.index', ['tanggal' => $tanggal->copy()->subDay()->toDateString()]) }}">‹</a>
        <input type="text" name="tanggal" data-tanggal-id value="{{ $tanggal->toDateString() }}" style="width: 150px;" onchange="this.form.submit()">
        <a class="tombol polos" style="padding: 6px 12px;" href="{{ route('review-absen.index', ['tanggal' => $tanggal->copy()->addDay()->toDateString()]) }}">›</a>
        <b>{{ $hari }}</b>
        <span class="redup" style="font-size: 13px;">· jam kerja standar {{ $jam['masuk'] }}–{{ $jam['pulang'] }}</span>
    </form>

    <div class="ringkas-review">
        @foreach (\App\Http\Controllers\ReviewAbsenController::STATUS as $s => [$label, $warna])
            <div class="kartu"><span class="redup">{{ $label }}</span><b @if ($warna !== 'hijau' && ($jumlah[$s] ?? 0)) style="color: {{ $warna === 'merah' ? 'var(--merah)' : '#f0c05a' }};" @endif>{{ $jumlah[$s] ?? 0 }}</b></div>
        @endforeach
        <div class="kartu"><span class="redup">Karyawan direview</span><b>{{ $baris->count() }}</b><span class="redup" style="font-size: 12px;">aktif, kecuali sopir DT</span></div>
    </div>

    <div class="kartu">
        <h4 style="margin: 0 0 8px;">📣 Review untuk grup WhatsApp</h4>
        <label style="display: inline-flex; gap: 6px; align-items: center; font-weight: 400; margin: 0 0 8px;"><input type="checkbox" id="sertakan-lengkap"> Sertakan nama karyawan yang absennya lengkap</label>
        <textarea id="teks-grup" spellcheck="false"></textarea>
        <div style="display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; align-items: center;">
            <button type="button" class="tombol" id="kirim-grup">💬 Kirim ke WhatsApp</button>
            <button type="button" class="tombol polos" id="salin-grup">📋 Salin teks</button>
            <span class="redup" id="info-grup" style="font-size: 12.5px;">Teks bisa diubah dulu sebelum dikirim. Pilih grup WhatsApp setelah tombol ditekan.</span>
        </div>
    </div>

    <div class="kartu gulir" style="padding: 0;">
        <h4 style="margin: 0; padding: 12px 14px 6px;">Per karyawan
            @if ($tanpaHp)<span class="redup" style="font-size: 12px; font-weight: 400;"> · {{ $tanpaHp }} karyawan yang belum lengkap belum punya No HP — isi di Data Karyawan untuk tombol WA pribadi</span>@endif</h4>
        <table class="kas">
            <thead><tr><th>Karyawan</th><th>Jabatan</th><th>Site</th><th>Status</th><th>Masuk</th><th>Pulang</th><th class="angka">Jam kerja</th><th>Proyek · lokasi</th><th>No HP</th><th data-tanpa-saring>WA pribadi</th></tr></thead>
            <tbody>
                @forelse ($baris as $b)
                    @php
                        [$label, $warna] = \App\Http\Controllers\ReviewAbsenController::STATUS[$b->status];
                    @endphp
                    <tr>
                        <td><b>{{ $b->k->nama }}</b></td>
                        <td>{{ $b->k->jabatan }}</td>
                        <td>{{ $b->k->site }}</td>
                        <td data-nilai="{{ $label }}"><span class="label {{ $warna }}">{{ $label }}</span></td>
                        <td>{{ $b->masuk ?? '–' }}</td>
                        <td>{{ $b->pulang ?? '–' }}</td>
                        <td class="angka" data-n="{{ $b->jam_kerja ?? '' }}">{{ $b->jam_kerja !== null ? intdiv($b->jam_kerja, 60).' j '.($b->jam_kerja % 60).' m' : '' }}</td>
                        <td>{{ $b->lokasi }}</td>
                        <td style="white-space: nowrap;">{{ $b->k->no_hp }}</td>
                        <td class="wa">
                            @if ($b->wa)
                                <a class="tombol polos" style="padding: 3px 10px; font-size: 12px;" target="_blank" rel="noopener" href="https://wa.me/{{ $b->wa }}?text={{ rawurlencode($b->pesan) }}" title="{{ $b->pesan }}">💬 Kirim review</a>
                            @else
                                <a class="redup" style="font-size: 12px;" href="{{ route('karyawan.edit', $b->k) }}">isi No HP</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="redup" style="padding: 20px 14px;">Belum ada karyawan wajib absen.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
        (() => {
            const HARI = @json($hari);
            const DATA = @json($dataJs);
            const teks = document.getElementById('teks-grup'), sertakan = document.getElementById('sertakan-lengkap');
            const kelompok = s => DATA.filter(d => d.status === s);
            const susun = () => {
                const baris = [`*Review Absen — ${HARI}*`, ''];
                const bagian = (judul, isi, fmt) => { if (isi.length) baris.push(`${judul} (${isi.length}):`, ...isi.map(fmt), ''); };
                bagian('❌ Tidak absen', kelompok('tidak_absen'), d => `• ${d.nama}`);
                bagian('⚠️ Belum absen pulang', kelompok('tanpa_pulang'), d => `• ${d.nama} (masuk ${d.masuk})`);
                bagian('⚠️ Belum absen masuk', kelompok('tanpa_masuk'), d => `• ${d.nama} (pulang ${d.pulang})`);
                const lengkap = kelompok('lengkap');
                if (sertakan.checked) bagian('✅ Lengkap', lengkap, d => `• ${d.nama} (${d.masuk}–${d.pulang})`);
                else baris.push(`✅ Lengkap: ${lengkap.length} karyawan`, '');
                baris.push('Yang belum lengkap mohon segera melengkapi lewat Lapor Lapangan atau konfirmasi ke admin. Terima kasih 🙏');
                teks.value = baris.join('\n');
            };
            sertakan.addEventListener('change', susun);
            susun();
            const info = document.getElementById('info-grup');
            document.getElementById('salin-grup').addEventListener('click', async () => {
                try { await navigator.clipboard.writeText(teks.value); info.textContent = '✅ Teks disalin — tempel di grup WhatsApp.'; }
                catch { teks.select(); document.execCommand('copy'); info.textContent = '✅ Teks disalin.'; }
            });
            document.getElementById('kirim-grup').addEventListener('click', () => window.open('https://wa.me/?text=' + encodeURIComponent(teks.value), '_blank'));
        })();
    </script>
@endsection
