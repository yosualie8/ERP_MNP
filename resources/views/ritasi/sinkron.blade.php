@extends('layouts.app', ['judul' => 'Sinkron Bongkaran & Pengajuan'])

@section('lebar', '1300px')

@section('isi')
    <style>
        .pilih-sinkron { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end; }
        .pilih-sinkron select { width: 100%; }
        @media (max-width: 800px) { .pilih-sinkron { grid-template-columns: 1fr; } }
        .do-list { font-size: 12.5px; line-height: 1.5; }
        .do-list .buang { color: #ff8a80; font-weight: 600; }
        .do-list .bongkar { color: #7ee2a8; }
        tr.ada-buang td { background: rgba(255, 138, 128, .06); }
    </style>

    <p class="redup" style="margin-top: 0;">Bandingkan satu <b>upload Excel surat jalan</b> (Monitor Ritasi) dengan satu <b>Pengajuan UJ</b>. Per DT, jumlah surat jalan di Excel =
        jumlah DO pengajuan yang dipertahankan (DO yang sudah tercatat bongkar didahulukan, sisanya urut nomor DO). DO sisanya dibatalkan —
        semua barisnya (Uang Jalan, Uang Tanah, dst.) yang masih menunggu. Baris yang sudah ditransfer tidak diubah, hanya dilaporkan.</p>

    <form method="GET" action="{{ route('ritasi.monitor.sinkron') }}" class="kartu pilih-sinkron">
        <div><label for="unggah">Upload surat jalan</label>
            <select id="unggah" name="unggah" required>
                @forelse ($daftarUnggah as $u)
                    <option value="{{ $u->id }}" @selected($unggah?->id === $u->id)>{{ $u->created_at->translatedFormat('j M H:i') }} · "{{ $u->isi['file'] ?? '?' }}" · {{ array_sum(\App\Support\SinkronPengajuan::ritPerDt($u)) }} surat jalan{{ $u->user ? ' · '.$u->user->name : '' }}</option>
                @empty
                    <option value="">— belum ada upload —</option>
                @endforelse
            </select></div>
        <div><label for="pengajuan">Pengajuan UJ</label>
            <select id="pengajuan" name="pengajuan" required>
                <option value="">— pilih pengajuan —</option>
                @foreach ($daftarPengajuan as $p)
                    <option value="{{ $p->id }}" @selected($pengajuan?->id === $p->id)>{{ $p->kode() }} · {{ $p->tanggal->translatedFormat('j M') }} · {{ rp($p->nominal) }}{{ $p->keterangan ? ' · '.mb_strimwidth($p->keterangan, 0, 60, '…') : '' }}</option>
                @endforeach
                @if ($pengajuan && ! $daftarPengajuan->contains('id', $pengajuan->id))<option value="{{ $pengajuan->id }}" selected>{{ $pengajuan->kode() }} ({{ \App\Models\UjPengajuan::STATUS[$pengajuan->status] ?? $pengajuan->status }})</option>@endif
            </select></div>
        <div><button type="submit" class="tombol">Pratinjau</button></div>
    </form>

    @if ($rencana)
        @php
            $nBatal = count($rencana['batal']);
            $nominal = collect($rencana['per_dt'])->sum('nominal_batal');
        @endphp
        <div class="kartu" style="padding: 0;">
            <table class="kas">
                <thead><tr><th>DT</th><th>Sopir</th><th class="angka">Rit di Excel</th><th class="angka">DO di pengajuan</th><th>DO dipertahankan</th><th>DO dibatalkan</th><th title="Satu DO biasanya punya 2 baris pengajuan: Uang Jalan + Uang Tanah">Baris pengajuan yang dibatalkan</th><th class="angka">Nominal dibatalkan</th></tr></thead>
                <tbody>
                    @foreach ($rencana['per_dt'] as $x)
                        <tr @class(['ada-buang' => $x['buang']])>
                            <td><b>{{ $x['dt'] }}</b></td>
                            <td>{{ $x['sopir'] }}</td>
                            <td class="angka">{{ $x['rit_excel'] }}</td>
                            <td class="angka">{{ $x['do_pengajuan'] }}</td>
                            <td class="do-list">@foreach ($x['simpan'] as $do)<span @class(['bongkar' => in_array($do, $x['sudah_bongkar'], true)]) title="{{ in_array($do, $x['sudah_bongkar'], true) ? 'Sudah tercatat bongkar di Data Ritasi' : '' }}">{{ $do }}</span>@if (! $loop->last), @endif @endforeach</td>
                            <td class="do-list">@forelse ($x['buang'] as $do)<span class="buang">{{ $do }}</span>@if (! $loop->last), @endif @empty <span class="redup">–</span> @endforelse</td>
                            <td class="do-list" data-n="{{ $x['baris_batal'] }}">@foreach ($x['kategori_batal'] as $kat => $k)<div>{{ $k['n'] }} × {{ $kat }} <span class="redup">{{ rp($k['rp']) }}</span></div>@endforeach</td>
                            <td class="angka">{{ $x['nominal_batal'] ? rp($x['nominal_batal']) : '' }}</td>
                        </tr>
                    @endforeach
                    @php
                        $nDo = collect($rencana['per_dt'])->sum(fn ($x) => count($x['buang']));
                        $perKat = collect($rencana['per_dt'])->flatMap(fn ($x) => collect($x['kategori_batal'])->map(fn ($k, $kat) => [$kat, $k]))
                            ->groupBy(0)->map(fn ($g) => ['n' => $g->sum(fn ($y) => $y[1]['n']), 'rp' => $g->sum(fn ($y) => $y[1]['rp'])]);
                    @endphp
                    <tr><td colspan="5"><b>Total</b></td><td><b>{{ $nDo }} DO</b></td>
                        <td class="do-list"><b>{{ $nBatal }} baris</b>@foreach ($perKat as $kat => $k)<div>{{ $k['n'] }} × {{ $kat }} <span class="redup">{{ rp($k['rp']) }}</span></div>@endforeach</td>
                        <td class="angka"><b>{{ rp($nominal) }}</b></td></tr>
                </tbody>
            </table>
        </div>

        @if ($rencana['lapor'])
            <div class="pesan galat"><b>{{ count($rencana['lapor']) }} baris DO yang tidak bongkar sudah ditransfer — tidak diubah:</b>
                <ul style="margin: 6px 0 0; padding-left: 18px;">@foreach ($rencana['lapor'] as $l)<li>{{ $l['dt'] }} · DO {{ $l['do'] }} · {{ $l['nama'] }} · {{ $l['kategori'] }} {{ rp($l['nominal']) }}{{ $l['id_uj'] ? ' · '.$l['id_uj'] : '' }}</li>@endforeach</ul>
                <div style="margin-top: 6px;">Uang jalan yang sudah di tangan sopir bisa dijadikan saldo sopir lewat menu Pengalihan UJ.</div></div>
        @endif
        @if ($rencana['lebih'])
            <div class="pesan"><b>Surat jalan di Excel yang tidak punya DO di pengajuan ini:</b>
                @foreach ($rencana['lebih'] as $dt => $n){{ $dt }} {{ $n }} rit {{ $loop->last ? '' : '·' }} @endforeach
                <span class="redup">— tidak ada yang dibatalkan untuk ini (mungkin ada di pengajuan lain).</span></div>
        @endif
        @if ($rencana['tanpa_mobil'])
            <div class="pesan"><span class="redup">{{ $rencana['tanpa_mobil'] }} baris pengajuan tanpa No Mobil tidak ikut dibandingkan.</span></div>
        @endif

        @if ($nBatal)
            <form method="POST" action="{{ route('ritasi.monitor.sinkron.jalankan') }}" class="kartu" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;"
                onsubmit="return confirm('Batalkan {{ $nDo }} DO ({{ $nBatal }} baris pengajuan) di {{ $pengajuan->kode() }} senilai {{ rp($nominal) }}? DO-nya tidak ada di data bongkar.')">
                @csrf
                <input type="hidden" name="unggah" value="{{ $unggah->id }}">
                <input type="hidden" name="pengajuan" value="{{ $pengajuan->id }}">
                <input type="hidden" name="batal" value="{{ implode(',', $rencana['batal']) }}">
                <span>Batalkan <b>{{ $nDo }} DO</b> (berwarna merah di atas) = <b>{{ $nBatal }} baris pengajuan</b> {{ $pengajuan->kode() }} senilai <b>{{ rp($nominal) }}</b>. Pemakaian saldo sopir baris itu dikembalikan ke saldo.</span>
                <button type="submit" class="tombol-hapus" style="margin-left: auto;">Batalkan {{ $nDo }} DO ({{ $nBatal }} baris)</button>
            </form>
        @else
            <div class="pesan sukses">✓ Semua DO {{ $pengajuan->kode() }} yang masih menunggu punya bongkaran di upload ini — tidak ada yang perlu dibatalkan.</div>
        @endif
    @elseif ($unggah && ! $pengajuan)
        <p class="redup">Pilih pengajuannya lalu klik Pratinjau.</p>
    @endif
@endsection
