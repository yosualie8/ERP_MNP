@extends('layouts.app', ['judul' => 'Mic ke Laptop'])

@section('isi')
    <style>
        .mic-wadah { max-width: 520px; margin: 0 auto; text-align: center; }
        .mic-status { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; margin: 8px 0 18px; }
        .mic-chip { padding: 4px 12px; border-radius: 999px; font-size: 14px; background: #eef1f4; color: #445; }
        .mic-chip.ok { background: #dcf5e3; color: #17663a; }
        .mic-chip.galat { background: #fde2e1; color: #9b1c1c; }
        #mic-tombol { width: 220px; height: 220px; border-radius: 50%; border: none; font-size: 64px; cursor: pointer;
            background: #e9edf2; color: #334; box-shadow: 0 6px 22px rgba(0,0,0,.12); transition: transform .08s, background .15s;
            touch-action: none; user-select: none; -webkit-user-select: none; -webkit-touch-callout: none; }
        #mic-tombol:active { transform: scale(.97); }
        #mic-tombol.aktif { background: #e53935; color: #fff; box-shadow: 0 0 0 10px rgba(229,57,53,.18), 0 6px 22px rgba(0,0,0,.18); }
        #mic-tombol:disabled { opacity: .5; cursor: default; }
        .mic-label { font-size: 18px; margin: 14px 0 6px; font-weight: 600; }
        .mic-level { height: 10px; border-radius: 6px; background: #e9edf2; overflow: hidden; margin: 10px auto 0; max-width: 320px; }
        .mic-level > div { height: 100%; width: 0; background: linear-gradient(90deg, #43a047, #fdd835 70%, #e53935); transition: width .06s; }
        .mic-opsi { margin: 16px 0; font-size: 15px; }
        .mic-pasang { text-align: left; margin-top: 28px; }
        .mic-kode { font-size: 40px; letter-spacing: 8px; font-weight: 700; font-family: ui-monospace, monospace; margin: 8px 0; }
    </style>

    <div class="mic-wadah">
        <h1 style="margin-top: 0;">🎤 Mic ke Laptop</h1>
        <p class="redup" style="margin-top: -6px;">Bicara di HP/tablet ini — suaranya jadi mikrofon di laptop (Claude, Zoom, Meet, …).</p>

        <div class="mic-status">
            <span class="mic-chip" id="st-relay">Menyambung…</span>
            <span class="mic-chip" id="st-laptop">Laptop: -</span>
        </div>

        <button type="button" id="mic-tombol" disabled aria-label="Mikrofon">🎤</button>
        <div class="mic-label" id="mic-label">Menyiapkan…</div>
        <div class="mic-level"><div id="mic-level"></div></div>
        <div class="redup" id="mic-info" style="font-size: 13px; margin-top: 6px;">&nbsp;</div>

        <div class="mic-opsi">
            <label><input type="checkbox" id="mic-tahan"> Tekan &amp; tahan untuk bicara (lepas = berhenti)</label>
        </div>
        <p class="redup" style="font-size: 13px;">Biarkan halaman ini tetap terbuka &amp; layar menyala selama dipakai — browser menghentikan mikrofon bila tab ditinggal.</p>
    </div>

    <div class="kartu mic-pasang" style="max-width: 640px; margin-left: auto; margin-right: auto;">
        <h3 style="margin-top: 0;">💻 Laptop penerima (aplikasi Windows "Mic HP")</h3>
        @if (session('status'))
            <p><strong>{{ session('status') }}</strong></p>
        @endif
        <p class="redup" style="margin-top: 0;">Di laptop: klik kanan ikon Mic HP di tray → <em>Pasangkan…</em>, lalu ketik kode di bawah (berlaku {{ $menitKode }} menit, sekali pakai).</p>
        <div id="kode-wadah" style="display: none;">
            <div class="mic-kode" id="kode-angka"></div>
            <div class="redup" id="kode-sisa"></div>
        </div>
        <button type="button" class="tombol" id="kode-buat">Buat kode pasang</button>

        @if ($perangkat->isNotEmpty())
            <table class="kas" style="margin-top: 14px;">
                <thead><tr><th>Laptop</th><th>Dipasangkan</th><th>Terakhir aktif</th><th></th></tr></thead>
                <tbody>
                    @foreach ($perangkat as $p)
                        <tr>
                            <td>{{ $p->nama }}</td>
                            <td>{{ $p->created_at->translatedFormat('j M Y H:i') }}</td>
                            <td>{{ $p->terakhir_aktif?->diffForHumans() ?? '-' }}</td>
                            <td>
                                <form method="POST" action="{{ route('mic.hapus', $p) }}" onsubmit="return confirm('Lepas laptop ini? Aplikasinya harus dipasangkan ulang.')">
                                    @csrf @method('DELETE')
                                    <button class="tombol polos" type="submit">Lepas</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <script>
    (() => {
        const CSRF = document.querySelector('meta[name="csrf-token"]').content;
        const URL_TOKEN = @json(route('mic.token')), URL_KODE = @json(route('mic.kode'));
        const $ = id => document.getElementById(id);
        const tombol = $('mic-tombol'), label = $('mic-label'), level = $('mic-level'), info = $('mic-info');
        const stRelay = $('st-relay'), stLaptop = $('st-laptop'), tahan = $('mic-tahan');

        try { tahan.checked = localStorage.getItem('mnp-mic-tahan') === '1'; } catch (e) {}
        tahan.addEventListener('change', () => { try { localStorage.setItem('mnp-mic-tahan', tahan.checked ? '1' : '0'); } catch (e) {} perbaruiLabel(); });

        let ws = null, nLaptop = 0, aktif = false, mulaiPending = null;
        let stream = null, ctx = null, analyser = null, reader = null, enc = null, worklet = null, wakeLock = null, terkirim = 0;

        function chip(el, teks, kelas) { el.textContent = teks; el.className = 'mic-chip' + (kelas ? ' ' + kelas : ''); }
        function perbaruiLabel() {
            if (aktif) label.textContent = tahan.checked ? 'Bicara… (lepas untuk berhenti)' : 'Sedang mengirim suara — ketuk untuk berhenti';
            else label.textContent = tahan.checked ? 'Tekan & tahan untuk bicara' : 'Ketuk untuk mulai bicara';
        }
        function kirimTeks(obj) { if (ws && ws.readyState === 1) ws.send(JSON.stringify(obj)); }
        function kirimBiner(buf) { if (ws && ws.readyState === 1 && ws.bufferedAmount < 64000) { ws.send(buf); terkirim += buf.byteLength; } }

        // ---- WebSocket ke relay (tetap tersambung selama halaman terbuka, untuk status laptop) ----
        async function sambung() {
            chip(stRelay, 'Menyambung…');
            try {
                const r = await fetch(URL_TOKEN, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' } });
                if (!r.ok) throw new Error('token ' + r.status);
                const { token, relay } = await r.json();
                ws = new WebSocket(relay + '?t=' + encodeURIComponent(token));
                ws.binaryType = 'arraybuffer';
                ws.onopen = () => { chip(stRelay, 'Server: tersambung', 'ok'); tombol.disabled = false; perbaruiLabel(); if (aktif && mulaiPending) kirimTeks(mulaiPending); };
                ws.onmessage = e => {
                    if (typeof e.data !== 'string') return;
                    const m = JSON.parse(e.data);
                    if (m.t === 'penerima') { nLaptop = m.n; chip(stLaptop, m.n ? `Laptop: tersambung${m.n > 1 ? ' (' + m.n + ')' : ''}` : 'Laptop: belum tersambung', m.n ? 'ok' : 'galat'); }
                };
                ws.onclose = () => { chip(stRelay, 'Server: terputus — menyambung ulang…', 'galat'); chip(stLaptop, 'Laptop: -'); setTimeout(sambung, 2000); };
            } catch (e) {
                chip(stRelay, 'Server: gagal (' + e.message + ') — mencoba lagi…', 'galat');
                setTimeout(sambung, 3000);
            }
        }

        // ---- Mikrofon ----
        async function mulai() {
            if (aktif) return;
            aktif = true; tombol.classList.add('aktif'); perbaruiLabel();
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } });
            } catch (e) {
                aktif = false; tombol.classList.remove('aktif'); perbaruiLabel();
                info.textContent = 'Izin mikrofon ditolak / tidak ada: ' + e.message; return;
            }
            if (!aktif) { stream.getTracks().forEach(t => t.stop()); return; } // sudah dilepas sebelum izin selesai
            const track = stream.getAudioTracks()[0];
            ctx = new AudioContext();
            analyser = ctx.createAnalyser(); analyser.fftSize = 512;
            ctx.createMediaStreamSource(stream).connect(analyser);
            meter();
            try { wakeLock = await navigator.wakeLock?.request('screen'); } catch (e) {}

            const opusBisa = 'MediaStreamTrackProcessor' in window && 'AudioEncoder' in window
                && (await AudioEncoder.isConfigSupported({ codec: 'opus', sampleRate: 48000, numberOfChannels: 1, bitrate: 24000 }).catch(() => ({}))).supported;
            if (opusBisa) await jalankanOpus(track); else await jalankanPcm();
        }

        async function jalankanOpus(track) {
            info.textContent = 'Opus 24 kbps';
            reader = new MediaStreamTrackProcessor({ track }).readable.getReader();
            while (aktif) {
                const { value: data, done } = await reader.read().catch(() => ({ done: true }));
                if (done || !data) break;
                if (!enc) {
                    enc = new AudioEncoder({
                        output: chunk => { const b = new Uint8Array(chunk.byteLength + 1); b[0] = 1; chunk.copyTo(b.subarray(1)); kirimBiner(b.buffer); },
                        error: e => { info.textContent = 'Encoder: ' + e.message; },
                    });
                    enc.configure({ codec: 'opus', sampleRate: data.sampleRate, numberOfChannels: data.numberOfChannels, bitrate: 24000, opus: { frameDuration: 20000 } });
                    mulaiPending = { t: 'mulai', codec: 'opus', rate: data.sampleRate, ch: data.numberOfChannels };
                    kirimTeks(mulaiPending);
                }
                if (enc.state === 'configured') enc.encode(data);
                data.close();
            }
        }

        async function jalankanPcm() {
            // Cadangan tanpa WebCodecs: PCM 16-bit 16 kHz (±256 kbps).
            info.textContent = 'PCM 16 kHz (browser tanpa Opus)';
            const kode = `class P extends AudioWorkletProcessor{constructor(){super();this.b=new Float32Array(320);this.n=0}
                process(i){const c=i[0]&&i[0][0];if(c){for(let k=0;k<c.length;k++){this.b[this.n++]=c[k];if(this.n===320){this.port.postMessage(this.b.slice());this.n=0}}}return true}}
                registerProcessor('p',P)`;
            const ctx16 = new AudioContext({ sampleRate: 16000 });
            await ctx16.audioWorklet.addModule(URL.createObjectURL(new Blob([kode], { type: 'application/javascript' })));
            worklet = { ctx: ctx16, node: new AudioWorkletNode(ctx16, 'p') };
            ctx16.createMediaStreamSource(stream).connect(worklet.node);
            mulaiPending = { t: 'mulai', codec: 'pcm16', rate: 16000, ch: 1 };
            kirimTeks(mulaiPending);
            worklet.node.port.onmessage = e => {
                const f = e.data, b = new Uint8Array(1 + f.length * 2), v = new DataView(b.buffer);
                b[0] = 2;
                for (let k = 0; k < f.length; k++) v.setInt16(1 + k * 2, Math.max(-1, Math.min(1, f[k])) * 32767, true);
                kirimBiner(b.buffer);
            };
        }

        async function berhenti() {
            if (!aktif) return;
            aktif = false; tombol.classList.remove('aktif'); perbaruiLabel();
            kirimTeks({ t: 'stop' }); mulaiPending = null;
            try { await reader?.cancel(); } catch (e) {}
            reader = null;
            try { if (enc && enc.state !== 'closed') { await enc.flush().catch(() => {}); enc.close(); } } catch (e) {}
            enc = null;
            if (worklet) { worklet.node.disconnect(); worklet.ctx.close(); worklet = null; }
            stream?.getTracks().forEach(t => t.stop()); stream = null;
            ctx?.close(); ctx = null; analyser = null; level.style.width = '0';
            try { await wakeLock?.release(); } catch (e) {} wakeLock = null;
            info.textContent = ' ';
        }

        function meter() {
            if (!analyser) return;
            const d = new Uint8Array(analyser.fftSize); analyser.getByteTimeDomainData(d);
            let puncak = 0; for (const x of d) puncak = Math.max(puncak, Math.abs(x - 128));
            level.style.width = Math.min(100, puncak / 128 * 140) + '%';
            requestAnimationFrame(meter);
        }
        setInterval(() => { if (aktif && terkirim) { info.textContent = info.textContent.split(' · ')[0] + ' · ' + Math.round(terkirim * 8 / 1000) + ' kbps'; } terkirim = 0; }, 1000);

        // Ketuk = mulai/berhenti; mode tahan = selama ditekan.
        tombol.addEventListener('pointerdown', e => { if (tahan.checked) { e.preventDefault(); tombol.setPointerCapture(e.pointerId); mulai(); } });
        ['pointerup', 'pointercancel'].forEach(ev => tombol.addEventListener(ev, () => { if (tahan.checked) berhenti(); }));
        tombol.addEventListener('click', () => { if (!tahan.checked) (aktif ? berhenti() : mulai()); });
        tombol.addEventListener('contextmenu', e => e.preventDefault());
        document.addEventListener('visibilitychange', async () => {
            if (document.visibilityState === 'visible' && aktif && !wakeLock) { try { wakeLock = await navigator.wakeLock?.request('screen'); } catch (e) {} }
        });

        // ---- Kode pasang laptop ----
        let hitung = null;
        $('kode-buat').addEventListener('click', async () => {
            const r = await (await fetch(URL_KODE, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' } })).json();
            $('kode-wadah').style.display = ''; $('kode-angka').textContent = r.kode.replace(/(\d{3})(\d{3})/, '$1 $2');
            const sampai = Date.now() + r.menit * 60000;
            clearInterval(hitung);
            hitung = setInterval(() => {
                const s = Math.max(0, Math.round((sampai - Date.now()) / 1000));
                $('kode-sisa').textContent = s ? `berlaku ${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} lagi` : 'kedaluwarsa — buat kode baru';
                if (!s) clearInterval(hitung);
            }, 1000);
        });

        sambung();
    })();
    </script>
@endsection
