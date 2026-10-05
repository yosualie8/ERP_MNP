@extends('layouts.app', ['judul' => 'Foto Bon'])

@section('lebar', '1440px')

@section('isi')
    <style>
        .tata-bon { display: grid; grid-template-columns: 380px minmax(0, 1fr); gap: 16px; align-items: start; }
        .tata-bon .penampil { height: calc(100vh - 200px); }
        .tata-bon .gambar-kecil img { width: 72px; height: 72px; }
        @media (max-width: 1000px) { .tata-bon { grid-template-columns: minmax(0, 1fr); } .tata-bon .penampil { height: 60vh; } }
    </style>

    <p style="margin: 0 0 12px;"><a href="{{ route('kas.index', ['lembar' => $transfer->kasBulan->lembar, 'tgl' => $transfer->tanggal->day]) }}">← Kembali ke Kas Harian {{ $transfer->tanggal->translatedFormat('j M Y') }}</a></p>

    @if ($errors->any())
        <div class="pesan galat">{{ $errors->first() }}</div>
    @endif

    <div class="tata-bon">
        <div>
            <div class="kartu">
                <h3 style="margin: 0 0 4px;">{{ $transfer->debet ? 'Uang masuk' : 'Transfer' }} {{ rp($transfer->debet ?: $transfer->kredit) }}</h3>
                <p style="margin: 0;"><b>{{ $transfer->nama_tujuan ?? '—' }}</b></p>
                <p class="redup" style="margin: 2px 0 0;">{{ $transfer->tanggal->translatedFormat('j M Y') }} · lembar {{ $transfer->kasBulan->lembar }} baris {{ $transfer->baris }} · NO ID {{ $transfer->no_id }}</p>
                @if ($transfer->keterangan)
                    <p style="margin: 8px 0 0;">{{ $transfer->keterangan }}</p>
                @endif
                @if ($transfer->bon->isNotEmpty())
                    <table style="font-size: 13px; margin-top: 10px;">
                        <thead><tr><th>Keterangan detail</th><th class="angka">Nominal</th></tr></thead>
                        <tbody>
                            @foreach ($transfer->bon as $b)
                                <tr><td>{{ $b->keterangan }} <span class="redup">{{ $b->pic }}</span></td><td class="angka">{{ rp($b->nominal) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="kartu">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                    <h3 style="margin: 0;">Foto bon ({{ $foto->count() }})</h3>
                    <form method="POST" action="{{ route('kas.bon.store', $transfer->no_id) }}" enctype="multipart/form-data" id="form-unggah">
                        @csrf
                        <label class="tombol" style="cursor: pointer; padding: 6px 12px; display: inline-block; font-size: 14px;">
                            <span>📷 Tambah foto</span>
                            <input type="file" name="foto[]" id="unggah-foto" accept="image/*" multiple hidden>
                        </label>
                    </form>
                </div>
                @if ($foto->isEmpty())
                    <p class="redup" style="margin: 8px 0 0;">Belum ada foto bon untuk transfer ini.</p>
                @else
                    <div class="gambar-kecil" id="daftar-gambar">
                        @foreach ($foto as $f)
                            <button type="button" data-penuh="{{ route('kas.foto', $f) }}" data-indeks="{{ $loop->index }}" @class(['aktif' => $loop->first])>
                                <img src="{{ route('kas.foto', ['foto' => $f, 'ukuran' => 'kecil']) }}" alt="Foto bon {{ $loop->iteration }}" loading="lazy">
                            </button>
                        @endforeach
                    </div>
                    @foreach ($foto as $f)
                        <div class="info-foto-item" data-indeks="{{ $loop->index }}" @if (! $loop->first) hidden @endif style="font-size: 12px; color: var(--redup); margin-top: 8px;">
                            Foto {{ $loop->iteration }} · {{ $f->user?->name ?? '—' }} · {{ $f->created_at->translatedFormat('j M Y H:i') }} · {{ round($f->ukuran / 1024) }} KB ·
                            @if ($f->status_drive === 'terunggah')
                                <a href="{{ $f->drive_link }}" target="_blank" rel="noopener">buka di Google Drive</a>
                            @elseif ($f->status_drive === 'gagal')
                                <span class="label merah" title="{{ $f->pesan_drive }}">gagal ke Drive, dicoba lagi</span>
                            @else
                                <span class="label">menunggu dipindah ke Drive</span>
                            @endif
                            <form method="POST" action="{{ route('kas.foto.destroy', $f) }}" onsubmit="return confirm('Hapus foto bon ini? File di Google Drive dipindah ke sampah.')" style="display: inline;">
                                @csrf @method('DELETE')
                                <button type="submit" class="tombol-hapus">Hapus</button>
                            </form>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

        <div class="penampil" id="penampil-bon" data-src="{{ $foto->isNotEmpty() ? route('kas.foto', $foto->first()) : '' }}"></div>
    </div>

    <script>
        (() => {
            const penampil = PenampilFoto.pasang(document.getElementById('penampil-bon'));
            document.querySelectorAll('#daftar-gambar button').forEach(b => b.addEventListener('click', () => {
                document.querySelectorAll('#daftar-gambar button').forEach(x => x.classList.toggle('aktif', x === b));
                document.querySelectorAll('.info-foto-item').forEach(x => x.hidden = x.dataset.indeks !== b.dataset.indeks);
                penampil.tampilkan(b.dataset.penuh);
            }));

            // Perkecil di browser dulu supaya unggahan cepat, lalu kirim.
            const input = document.getElementById('unggah-foto');
            input.addEventListener('change', async () => {
                if (!input.files.length) return;
                const label = input.previousElementSibling;
                label.textContent = 'Memperkecil…';
                const dt = new DataTransfer();
                for (const f of [...input.files].slice(0, {{ \App\Http\Controllers\KasFotoController::MAKS_FOTO }})) dt.items.add(await kecilkanFoto(f));
                input.files = dt.files;
                label.textContent = 'Mengunggah…';
                input.form.submit();
            });
        })();
    </script>
@endsection
