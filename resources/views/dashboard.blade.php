@extends('layouts.app', ['judul' => 'Beranda'])

@section('isi')
    <div class="kartu">
        <h3 style="margin-top: 0;">Akses Google Sheets</h3>
        @if ($emailGoogle)
            <p>Terhubung ke <strong>{{ $emailGoogle }}</strong>. Aplikasi bisa membaca semua spreadsheet di akun ini.</p>
        @else
            <p class="redup">Belum terhubung. Keluar lalu masuk lagi dengan Google dan centang semua izin.</p>
        @endif
    </div>

    @if ($galat)
        <div class="pesan galat">{{ $galat }}</div>
    @endif

    @if ($daftar)
        <div class="kartu">
            <h3 style="margin-top: 0;">Spreadsheet terakhir diubah</h3>
            <table>
                <thead><tr><th>Nama</th><th>Diubah</th></tr></thead>
                <tbody>
                    @foreach ($daftar as $f)
                        <tr>
                            <td><a href="{{ $f['webViewLink'] }}" target="_blank" rel="noopener">{{ $f['name'] }}</a></td>
                            <td class="redup">{{ \Illuminate\Support\Carbon::parse($f['modifiedTime'])->timezone(config('app.timezone'))->translatedFormat('j M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
