@extends('layouts.app', ['judul' => 'Login Perangkat Lain'])

@section('isi')
    <h1 style="margin-top: 0;">📱 Login Perangkat Lain</h1>
    <p class="redup" style="max-width: 720px;">
        Masuk ke ERP di HP lain (mis. HP pinjaman) tanpa memasukkan akun Google: buat QR, scan dengan kamera HP itu, lalu tekan <strong>Masuk</strong>.
        QR berlaku {{ \App\Models\LoginPerangkat::MENIT_BERLAKU }} menit dan hanya bisa dipakai sekali. Perangkat itu masuk sebagai akun ini
        (<strong>{{ auth()->user()->email }}</strong>) tanpa "ingat saya", jadi sesinya berakhir sendiri bila tidak dipakai
        {{ intdiv((int) config('session.lifetime'), 60) }} jam — atau keluarkan kapan saja dari tabel di bawah.
    </p>

    <div class="kartu" style="max-width: 420px; text-align: center;">
        @if ($aktif)
            <div style="background: #fff; padding: 12px; border-radius: 10px; display: inline-block; line-height: 0;">{!! $qr !!}</div>
            <p style="margin: 12px 0 4px;">Scan dengan kamera HP · berlaku sampai <strong>{{ $aktif->kedaluwarsa_pada->format('H:i') }}</strong>
                (<span id="sisa-qr" data-sampai="{{ $aktif->kedaluwarsa_pada->timestamp }}"></span>)</p>
            <p class="redup" id="status-qr" style="margin: 0 0 12px;">Menunggu di-scan…</p>
        @endif
        <form method="POST" action="{{ route('login-perangkat.buat') }}">
            @csrf
            <button class="tombol" type="submit">{{ $aktif ? '↻ Buat QR baru' : 'Buat QR login' }}</button>
        </form>
    </div>

    @if ($riwayat->isNotEmpty())
        <h3>Riwayat</h3>
        <table class="kas">
            <thead><tr><th>Dibuat</th><th>Status</th><th>Perangkat</th><th>IP</th><th></th></tr></thead>
            <tbody>
                @foreach ($riwayat as $r)
                    <tr>
                        <td>{{ $r->created_at->translatedFormat('j M Y H:i') }}</td>
                        <td>
                            @if ($r->dikeluarkan_pada) Dikeluarkan {{ $r->dikeluarkan_pada->translatedFormat('j M H:i') }}
                            @elseif ($r->sesiAktif()) <strong>Masuk</strong> {{ $r->dipakai_pada->translatedFormat('j M H:i') }}
                            @elseif ($r->dipakai_pada) Masuk {{ $r->dipakai_pada->translatedFormat('j M H:i') }} · sesi sudah berakhir
                            @elseif ($r->kedaluwarsa_pada->isPast()) <span class="redup">Tidak dipakai</span>
                            @else Menunggu di-scan
                            @endif
                        </td>
                        <td>{{ \App\Http\Controllers\LoginPerangkatController::namaPerangkat($r->perangkat) }}</td>
                        <td>{{ $r->ip_pakai ?? '-' }}</td>
                        <td>
                            @if ($r->sesiAktif())
                                <form method="POST" action="{{ route('login-perangkat.keluarkan', $r) }}" onsubmit="return confirm('Keluarkan perangkat ini?')">
                                    @csrf
                                    <button class="tombol polos" type="submit">Keluarkan</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($aktif)
        <script>
            (() => {
                const sisa = document.getElementById('sisa-qr'), status = document.getElementById('status-qr');
                const sampai = Number(sisa.dataset.sampai) * 1000;
                const detik = () => {
                    const s = Math.max(0, Math.round((sampai - Date.now()) / 1000));
                    sisa.textContent = s ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} lagi` : 'kedaluwarsa';
                };
                detik(); setInterval(detik, 1000);
                const cek = setInterval(async () => {
                    try {
                        const r = await (await fetch(@json(route('login-perangkat.status', $aktif)), { headers: { Accept: 'application/json' } })).json();
                        if (r.dipakai) { clearInterval(cek); status.textContent = '✓ Sudah dipakai — perangkat masuk.'; setTimeout(() => location.reload(), 1200); }
                        else if (r.kedaluwarsa) { clearInterval(cek); status.textContent = 'QR kedaluwarsa. Buat QR baru.'; }
                    } catch (e) {}
                }, 3000);
            })();
        </script>
    @endif
@endsection
