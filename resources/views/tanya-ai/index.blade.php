@extends('layouts.app', ['judul' => 'Tanya AI'])

@section('lebar', '1380px')

@section('isi')
    <style>
        .ai-tata { display: grid; grid-template-columns: 280px 1fr; gap: 14px; align-items: start; }
        @media (max-width: 900px) { .ai-tata { grid-template-columns: 1fr; } .ai-daftar { max-height: 220px; } }
        .ai-daftar { padding: 10px; max-height: calc(100vh - 160px); overflow: auto; }
        .ai-daftar a.item { display: block; padding: 8px 10px; border-radius: 8px; color: var(--teks); text-decoration: none; font-size: 13px; margin-bottom: 2px; }
        .ai-daftar a.item:hover { background: var(--kartu-2); }
        .ai-daftar a.item.aktif { background: var(--aksen-muda); border-left: 3px solid var(--aksen); }
        .ai-daftar .meta { color: var(--redup); font-size: 11px; }
        .ai-chat { display: flex; flex-direction: column; min-height: calc(100vh - 160px); padding: 0; }
        .ai-pesan { flex: 1; overflow: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; max-height: calc(100vh - 290px); }
        .gelembung { max-width: 88%; padding: 10px 14px; border-radius: 12px; line-height: 1.55; font-size: 14px; }
        .gelembung.tanya { align-self: flex-end; background: var(--aksen-muda); border: 1px solid var(--aksen); }
        .gelembung .teks { white-space: pre-wrap; }
        .gelembung.jawab { align-self: flex-start; background: var(--kartu-2); border: 1px solid var(--garis); overflow-x: auto; }
        .gelembung.galat { align-self: flex-start; background: #2a1416; border: 1px solid var(--merah, #e5484d); color: #ffb3b5; }
        .gelembung.jawab table { border-collapse: collapse; margin: 6px 0; font-size: 13px; }
        .gelembung.jawab th, .gelembung.jawab td { border: 1px solid var(--garis); padding: 4px 8px; text-align: left; }
        .gelembung.jawab th { background: var(--kartu); }
        .gelembung.jawab p { margin: 0 0 8px; } .gelembung.jawab p:last-child { margin-bottom: 0; }
        .gelembung .info { margin-top: 6px; font-size: 11px; color: var(--redup); }
        .ai-masuk { border-top: 1px solid var(--garis); padding: 12px; display: flex; gap: 8px; align-items: flex-end; }
        .ai-masuk textarea { flex: 1; resize: none; min-height: 46px; max-height: 200px; padding: 10px 12px; border-radius: 10px; font-size: 14px; line-height: 1.4; }
        .ai-masuk .tombol { padding: 11px 16px; }
        #mik { min-width: 52px; font-size: 18px; }
        #mik.rekam { background: var(--aksen); color: #fff; animation: denyut 1.2s infinite; }
        @keyframes denyut { 50% { opacity: .65; } }
        #status-mik { font-size: 12px; color: var(--redup); padding: 0 14px 8px; min-height: 16px; }
        .contoh { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin-top: 10px; }
        .contoh button { background: var(--kartu-2); border: 1px solid var(--garis); color: var(--teks); border-radius: 999px; padding: 6px 12px; font-size: 13px; cursor: pointer; }
    </style>

    @if ($pengaturan)
        <details class="kartu" @if (! $siap) open @endif style="margin-bottom: 12px;">
            <summary style="cursor: pointer;"><b>⚙ Pengaturan Tanya AI (Super Admin)</b>
                <span class="redup">— API key {{ $pengaturan['ada_kunci'] ? 'tersimpan' : 'BELUM diisi' }} · model {{ $pengaturan['model'] ?? 'BELUM dipilih' }} · suara {{ $pengaturan['model_suara'] }}</span></summary>
            <form method="POST" action="{{ route('tanya-ai.pengaturan') }}" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; margin-top: 10px; align-items: end;">
                @csrf
                <label>API key OpenAI <span class="redup">(disimpan terenkripsi; kosongkan bila tidak diganti)</span>
                    <input type="password" name="api_key" autocomplete="off" placeholder="{{ $pengaturan['ada_kunci'] ? '•••••• (tersimpan)' : 'sk-…' }}"></label>
                <label>Model untuk menjawab
                    <input type="text" name="model" list="daftar-model" value="{{ $pengaturan['model'] }}" placeholder="pilih setelah API key disimpan"></label>
                <label>Model speech to text
                    <input type="text" name="model_suara" list="daftar-model" value="{{ $pengaturan['model_suara'] }}"></label>
                <button type="submit" class="tombol">Simpan</button>
                <datalist id="daftar-model">@foreach (session('model_tersedia', []) as $m)<option value="{{ $m }}">@endforeach</datalist>
            </form>
            @if ($pengaturan['ada_kunci'])
                <button type="button" class="tombol polos" id="muat-model" style="margin-top: 8px; font-size: 12px; padding: 4px 10px;">Tampilkan model yang tersedia</button>
                <span class="redup" id="info-model" style="font-size: 12px;"></span>
            @endif
        </details>
    @endif

    <div class="ai-tata">
        <div class="kartu ai-daftar">
            @if ($semua)
                <form method="GET" style="margin-bottom: 8px;">
                    <input type="hidden" name="semua" value="1">
                    <select name="pengguna" onchange="this.form.submit()" style="width: 100%;">
                        <option value="">Semua pengguna</option>
                        @foreach ($pengguna as $o)<option value="{{ $o->id }}" @selected($saringUser === $o->id)>{{ $o->name ?? $o->email }}</option>@endforeach
                    </select>
                </form>
                <a href="{{ route('tanya-ai') }}" class="tombol polos" style="display: block; text-align: center; margin-bottom: 8px; font-size: 13px;">← Percakapan saya</a>
            @else
                <a href="{{ route('tanya-ai') }}" class="tombol" style="display: block; text-align: center; margin-bottom: 8px;">+ Percakapan baru</a>
                @if ($pengaturan)<a href="{{ route('tanya-ai', ['semua' => 1]) }}" class="tombol polos" style="display: block; text-align: center; margin-bottom: 8px; font-size: 13px;">📋 Log semua pengguna</a>@endif
            @endif
            @forelse ($daftar as $d)
                <a href="{{ route('tanya-ai', array_filter(['c' => $d->id, 'semua' => $semua ? 1 : null, 'pengguna' => $semua ? $saringUser : null])) }}" @class(['item', 'aktif' => $aktif?->id === $d->id])>
                    {{ \Illuminate\Support\Str::limit($d->judul, 70) }}
                    <div class="meta">@if ($semua){{ $d->nama_user ?? $d->email_user }} · @endif{{ \Illuminate\Support\Carbon::parse($d->updated_at)->translatedFormat('d-M-Y H:i') }} · {{ $d->jumlah_tanya }} tanya</div>
                </a>
            @empty
                <div class="redup" style="font-size: 13px; padding: 8px;">Belum ada percakapan.</div>
            @endforelse
        </div>

        <div class="kartu ai-chat">
            <div class="ai-pesan" id="ai-pesan">
                @if ($aktif && $semua && $pemilik)
                    <div class="redup" style="font-size: 12px; text-align: center;">Percakapan milik <b>{{ $pemilik->name ?? $pemilik->email }}</b> (hanya lihat)</div>
                @endif
                @forelse ($pesan as $m)
                    <div class="gelembung {{ $m->peran }}">
                        @if ($m->peran === 'jawab'){!! \App\Http\Controllers\TanyaAiController::html($m->isi) !!}@else<span class="teks">{{ $m->isi }}</span>@endif
                        <div class="info">
                            {{ \Illuminate\Support\Carbon::parse($m->created_at)->translatedFormat('d-M-Y H:i') }}
                            @if ($m->peran === 'tanya' && $m->sumber === 'suara') · 🎤 suara @endif
                            @if ($m->peran === 'jawab')
                                @php($tl = json_decode($m->tool ?? '[]', true) ?: [])
                                · {{ $m->model }} · {{ number_format(($m->durasi_ms ?? 0) / 1000, 1, ',', '.') }} dtk
                                @if ($tl) · data: {{ collect($tl)->pluck('nama')->unique()->implode(', ') }}@endif
                            @endif
                        </div>
                    </div>
                @empty
                    <div style="margin: auto; text-align: center; max-width: 560px;">
                        <h3 style="margin: 0 0 6px; color: var(--aksen-terang);">Tanya data ERP MNP</h3>
                        <p class="redup" style="margin: 0;">Ketik atau tekan 🎤 lalu bicara. AI hanya membaca data sesuai menu akun Anda; setiap pertanyaan & jawaban dicatat.</p>
                        @if (! $semua)
                            <div class="contoh">
                                @foreach (['Berapa transaksi Kas Harian yang belum reimburse, rinci per bulan?', 'Siapa yang terakhir input kas dan kapan?', 'Uang jalan DT 068 bulan ini berapa?', 'Pengajuan UJ apa saja yang masih menunggu?'] as $c)
                                    <button type="button" data-contoh>{{ $c }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforelse
            </div>
            @if (! $semua || ($aktif && $pemilik?->id === auth()->id()))
                @if ($siap)
                    <div class="ai-masuk">
                        <button type="button" class="tombol polos" id="mik" title="Tekan lalu bicara; tekan lagi untuk selesai">🎤</button>
                        <textarea id="tanya" rows="1" placeholder="Tanya tentang kas, reimburse, UJ, ritasi… (Enter kirim, Shift+Enter baris baru)"></textarea>
                        <button type="button" class="tombol" id="kirim">Kirim</button>
                    </div>
                    <div id="status-mik"></div>
                @else
                    <div class="ai-masuk redup">Tanya AI belum siap: Super Admin perlu mengisi API key & model OpenAI di Pengaturan.</div>
                @endif
            @endif
        </div>
    </div>

    <script>
        (() => {
            const csrf = @json(csrf_token());
            document.getElementById('muat-model')?.addEventListener('click', async () => {
                const info = document.getElementById('info-model');
                info.textContent = 'Memuat…';
                const r = await (await fetch(@json(route('tanya-ai.model')), {headers: {Accept: 'application/json'}})).json();
                if (r.galat) { info.textContent = r.galat; return; }
                document.getElementById('daftar-model').innerHTML = r.model.map(m => `<option value="${m}">`).join('');
                info.textContent = r.model.length + ' model — klik isian model untuk memilih.';
            });

            const kotak = document.getElementById('ai-pesan');
            const tanya = document.getElementById('tanya');
            if (!tanya) return;
            let percakapan = @json($aktif && $aktif->user_id === auth()->id() ? $aktif->id : null);
            let sumber = 'ketik';
            const esc = s => String(s).replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
            const tambah = (kelas, html) => {
                if (!kotak.querySelector('.gelembung')) kotak.innerHTML = '';
                const d = document.createElement('div'); d.className = 'gelembung ' + kelas; d.innerHTML = html;
                kotak.appendChild(d); kotak.scrollTop = kotak.scrollHeight; return d;
            };
            const tombol = document.getElementById('kirim');
            const kirim = async (teks) => {
                teks = (teks ?? tanya.value).trim();
                if (!teks || tombol.disabled) return;
                tambah('tanya', '<span class="teks">' + esc(teks) + '</span>' + `<div class="info">baru saja${sumber === 'suara' ? ' · 🎤 suara' : ''}</div>`);
                tanya.value = ''; tanya.style.height = '';
                const tunggu = tambah('jawab', '<span class="redup">⏳ AI sedang mencari data…</span>');
                tombol.disabled = true;
                const mulai = Date.now();
                try {
                    const r = await fetch(@json(route('tanya-ai.kirim')), {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf},
                        body: JSON.stringify({pertanyaan: teks, percakapan, sumber})});
                    const j = await r.json().catch(() => ({galat: 'Server tidak merespons dengan benar (HTTP ' + r.status + ').'}));
                    if (j.percakapan && !percakapan) { percakapan = j.percakapan; history.replaceState(null, '', '?c=' + percakapan); }
                    if (j.galat || !r.ok) { tunggu.className = 'gelembung galat'; tunggu.textContent = j.galat || j.message || 'Gagal.'; }
                    else {
                        const data = [...new Set((j.tool || []).map(t => t.nama))];
                        tunggu.innerHTML = j.jawaban_html + `<div class="info">${((Date.now() - mulai) / 1000).toFixed(1)} dtk${data.length ? ' · data: ' + esc(data.join(', ')) : ''}</div>`;
                    }
                } catch (e) { tunggu.className = 'gelembung galat'; tunggu.textContent = 'Koneksi gagal: ' + e.message; }
                tombol.disabled = false; sumber = 'ketik'; kotak.scrollTop = kotak.scrollHeight; tanya.focus();
            };
            tombol.addEventListener('click', () => kirim());
            tanya.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); kirim(); } });
            tanya.addEventListener('input', () => { tanya.style.height = 'auto'; tanya.style.height = Math.min(tanya.scrollHeight, 200) + 'px'; });
            document.querySelectorAll('[data-contoh]').forEach(b => b.addEventListener('click', () => kirim(b.textContent)));
            kotak.scrollTop = kotak.scrollHeight;

            // ===== Speech to text: rekam di browser → transkripsi OpenAI → teks masuk kotak pertanyaan =====
            const mik = document.getElementById('mik');
            const status = document.getElementById('status-mik');
            let perekam = null, potongan = [], jam = null, detik = 0;
            const jenis = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].find(t => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';
            const berhenti = () => { if (perekam && perekam.state === 'recording') perekam.stop(); };
            mik.addEventListener('click', async () => {
                if (perekam && perekam.state === 'recording') { berhenti(); return; }
                if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) { status.textContent = 'Browser ini tidak mendukung rekam suara.'; return; }
                let aliran;
                try { aliran = await navigator.mediaDevices.getUserMedia({audio: true}); }
                catch (e) { status.textContent = 'Izin mikrofon ditolak. Izinkan mikrofon untuk situs ini di pengaturan browser.'; return; }
                potongan = []; detik = 0;
                perekam = new MediaRecorder(aliran, jenis ? {mimeType: jenis} : undefined);
                perekam.ondataavailable = e => e.data.size && potongan.push(e.data);
                perekam.onstop = async () => {
                    clearInterval(jam); aliran.getTracks().forEach(t => t.stop()); mik.classList.remove('rekam'); mik.textContent = '🎤';
                    const blob = new Blob(potongan, {type: perekam.mimeType || 'audio/webm'});
                    if (blob.size < 1000) { status.textContent = 'Rekaman terlalu pendek.'; return; }
                    status.textContent = '⏳ Mengubah suara menjadi teks…'; mik.disabled = true;
                    const fd = new FormData();
                    fd.append('audio', blob, 'suara.' + (blob.type.includes('mp4') ? 'mp4' : blob.type.includes('ogg') ? 'ogg' : 'webm'));
                    try {
                        const r = await fetch(@json(route('tanya-ai.transkripsi')), {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf}, body: fd});
                        const j = await r.json();
                        if (j.galat || !r.ok) status.textContent = j.galat || j.message || 'Transkripsi gagal.';
                        else { tanya.value = (tanya.value ? tanya.value + ' ' : '') + j.teks; sumber = 'suara'; tanya.dispatchEvent(new Event('input')); tanya.focus();
                            status.textContent = '✓ Silakan periksa teksnya, lalu tekan Kirim (atau Enter).'; }
                    } catch (e) { status.textContent = 'Koneksi gagal: ' + e.message; }
                    mik.disabled = false;
                };
                perekam.start();
                mik.classList.add('rekam'); mik.textContent = '⏹';
                jam = setInterval(() => { detik++; status.textContent = `🔴 Merekam ${Math.floor(detik / 60)}:${String(detik % 60).padStart(2, '0')} — tekan ⏹ untuk selesai`; if (detik >= 300) berhenti(); }, 1000);
                status.textContent = '🔴 Merekam 0:00 — tekan ⏹ untuk selesai';
            });
        })();
    </script>
@endsection
