@extends('layouts.app', ['judul' => 'Beranda'])

@section('isi')
    @if ($bulanTerakhir)
        <div class="kartu">
            <h3 style="margin-top: 0;">Kas Bank Jago · {{ $bulanTerakhir->bulan->translatedFormat('F Y') }}</h3>
            <div class="ringkas" style="margin-bottom: 8px;">
                <div><span class="redup">Saldo awal</span><b>{{ rp($bulanTerakhir->saldo_awal) }}</b></div>
                <div><span class="redup">Uang masuk</span><b>{{ rp($bulanTerakhir->total_debet) }}</b></div>
                <div><span class="redup">Transfer keluar</span><b>{{ rp($bulanTerakhir->total_kredit) }}</b></div>
                <div><span class="redup">Saldo akhir</span><b>{{ rp($bulanTerakhir->saldo_akhir) }}</b></div>
            </div>
            <p class="redup" style="margin: 0;">
                {{ $jumlahBulan }} bulan diimpor, terakhir {{ $bulanTerakhir->diimpor_pada->translatedFormat('j M Y H:i') }}.
                @if ($bulanBercatatan->isNotEmpty())
                    Bulan dengan catatan rekonsiliasi: {{ $bulanBercatatan->implode(', ') }}.
                @else
                    Semua bulan cocok 100%.
                @endif
                <a href="{{ route('kas.index') }}">Buka Kas Harian →</a>
            </p>
        </div>
    @endif

    <div class="kartu">
        <h3 style="margin-top: 0;">Akses Google Sheets</h3>
        @if ($emailGoogle)
            <p style="margin-bottom: 0;">Terhubung ke <strong>{{ $emailGoogle }}</strong>. Data kas diambil dari spreadsheet <em>Kas Harian MNP - 2026</em>.</p>
        @else
            <p class="redup" style="margin-bottom: 0;">Belum terhubung. Super admin perlu menghubungkan akun Google pemilik sheet.</p>
        @endif
        @can('super-admin')
            <p style="margin-bottom: 0;">
                <a href="{{ route('google.hubungkan-sheets') }}" class="tombol polos">{{ $emailGoogle ? 'Hubungkan ulang Google Sheets' : 'Hubungkan Google Sheets' }}</a>
                <span class="redup">Pilih akun pemilik sheet Kas Harian MNP dan centang semua izin.</span>
            </p>
        @endcan
    </div>

    <div class="kartu">
        <h3 style="margin-top: 0;">Google Drive foto bon</h3>
        @if ($emailDriveFoto)
            <p>Foto bon disimpan di Google Drive <strong>{{ $emailDriveFoto }}</strong>, folder
                <a href="https://drive.google.com/drive/folders/{{ config('mnp.drive_foto_folder') }}" target="_blank" rel="noopener">Foto Bon MNP</a>.
                Server hanya menyimpan pratinjau kecil.</p>
        @else
            <p class="redup">Belum terhubung — foto bon sementara disimpan di server dan akan dipindah ke Drive setelah akun {{ config('mnp.drive_foto_email') }} dihubungkan.</p>
        @endif
        <p class="redup" style="margin-bottom: 0;">
            Di Drive: {{ $fotoStatus['terunggah'] ?? 0 }} foto
            · menunggu dipindah: {{ $fotoStatus['menunggu'] ?? 0 }}
            @if ($fotoStatus['gagal'] ?? 0)
                · <span class="label merah">gagal: {{ $fotoStatus['gagal'] }}</span>
            @endif
        </p>
        @can('super-admin')
            <p style="margin-bottom: 0;">
                <a href="{{ route('google.hubungkan-drive-foto') }}" class="tombol polos">{{ $emailDriveFoto ? 'Hubungkan ulang Google Drive foto' : 'Hubungkan Google Drive foto' }}</a>
                <span class="redup">Login sebagai {{ config('mnp.drive_foto_email') }} dan centang izin Google Drive.</span>
            </p>
        @endcan
    </div>
@endsection
