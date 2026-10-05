@extends('layouts.app', ['judul' => 'Foto Bon'])

@section('lebar', '1180px')

@section('isi')
    <style>
        .galeri { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
        .galeri figure { margin: 0; border: 1px solid var(--garis); border-radius: 10px; background: var(--kartu); overflow: hidden; }
        .galeri img { width: 100%; height: 340px; object-fit: contain; background: var(--latar); display: block; }
        .galeri figcaption { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 8px 10px; font-size: 12px; color: var(--redup); }
    </style>

    <p style="margin: 0 0 12px;"><a href="{{ route('kas.index', ['lembar' => $transfer->kasBulan->lembar, 'tgl' => $transfer->tanggal->day]) }}">← Kembali ke Kas Harian {{ $transfer->tanggal->translatedFormat('j M Y') }}</a></p>

    <div class="kartu">
        <div style="display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: baseline;">
            <h3 style="margin: 0;">{{ $transfer->debet ? 'Uang masuk' : 'Transfer' }} {{ rp($transfer->debet ?: $transfer->kredit) }} · {{ $transfer->nama_tujuan ?? '—' }}</h3>
            <span class="redup">{{ $transfer->tanggal->translatedFormat('j M Y') }} · lembar {{ $transfer->kasBulan->lembar }} baris {{ $transfer->baris }} · NO ID {{ $transfer->no_id }}</span>
        </div>
        @if ($transfer->keterangan)
            <p style="margin: 6px 0 0;">{{ $transfer->keterangan }}</p>
        @endif
        @if ($transfer->bon->isNotEmpty())
            <table style="font-size: 13px; margin-top: 10px;">
                <thead><tr><th>PIC</th><th>Keterangan detail</th><th class="angka">Nominal</th></tr></thead>
                <tbody>
                    @foreach ($transfer->bon as $b)
                        <tr><td class="redup">{{ $b->pic }}</td><td>{{ $b->keterangan }}</td><td class="angka">{{ rp($b->nominal) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="kartu">
        <div style="display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 12px;">
            <h3 style="margin: 0;">Foto bon ({{ $foto->count() }})</h3>
            <form method="POST" action="{{ route('kas.bon.store', $transfer->no_id) }}" enctype="multipart/form-data" id="form-unggah">
                @csrf
                <label class="tombol" style="cursor: pointer; padding: 8px 14px; display: inline-block;">
                    <span>📷 Tambah foto</span>
                    <input type="file" name="foto[]" accept="image/*" multiple hidden onchange="if (this.files.length) { this.previousElementSibling.textContent = 'Mengunggah…'; this.form.submit(); }">
                </label>
            </form>
        </div>
        @if ($errors->any())
            <div class="pesan galat">{{ $errors->first() }}</div>
        @endif
        @if ($foto->isEmpty())
            <p class="redup" style="margin: 0;">Belum ada foto bon untuk transfer ini.</p>
        @else
            <div class="galeri">
                @foreach ($foto as $f)
                    <figure>
                        <img src="{{ route('kas.foto', $f) }}" alt="Foto bon {{ $loop->iteration }}" loading="lazy" data-perbesar>
                        <figcaption>
                            <span>{{ $f->user?->name ?? '—' }} · {{ $f->created_at->translatedFormat('j M Y H:i') }} · {{ round($f->ukuran / 1024) }} KB</span>
                            <form method="POST" action="{{ route('kas.foto.destroy', $f) }}" onsubmit="return confirm('Hapus foto bon ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="tombol-hapus">Hapus</button>
                            </form>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        @endif
    </div>
@endsection
