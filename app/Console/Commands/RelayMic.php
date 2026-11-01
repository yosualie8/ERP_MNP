<?php

namespace App\Console\Commands;

use App\Support\TokenMic;
use Illuminate\Console\Command;

/**
 * Relay suara "Mic ke Laptop": server WebSocket kecil (PHP murni, stream_select) di 127.0.0.1, di belakang Nginx (/mic-relay).
 * HP pengirim (token "kirim") → semua aplikasi Windows penerima (token "terima") milik akun yang sama.
 * Pesan teks JSON dari pengirim ({"t":"mulai",...}) diteruskan apa adanya; pesan biner (paket Opus / PCM) diteruskan mentah.
 * Dijalankan sebagai layanan systemd (user mnp): php artisan mnp:relay-mic
 */
class RelayMic extends Command
{
    protected $signature = 'mnp:relay-mic {--port= : Port lokal (bawaan config mnp.mic_relay_port)}';

    protected $description = 'Relay WebSocket suara HP → aplikasi Windows (menu Mic ke Laptop)';

    private const MAKS_PESAN = 1048576;     // 1 MB

    private const MAKS_ANTRE_SUARA = 65536; // penerima lambat: paket suara baru dibuang (bukan menumpuk jeda)

    /** @var array<int, array{sock: resource, buf: string, keluar: string, siap: bool, user: ?int, peran: ?string, aktif: int, tutup: bool, frag: string, fragOp: int, ip: string}> */
    private array $klien = [];

    /** Pesan "mulai" terakhir per akun, dikirim ke penerima yang baru tersambung saat HP sedang berbicara. */
    private array $mulai = [];

    public function handle(): int
    {
        $port = (int) ($this->option('port') ?: config('mnp.mic_relay_port'));
        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if (! $server) {
            $this->error("Tidak bisa membuka port {$port}: {$errstr}");

            return self::FAILURE;
        }
        stream_set_blocking($server, false);
        $this->log("relay siap di 127.0.0.1:{$port}");

        $ping = time();
        while (true) {
            $baca = [$server];
            $tulis = [];
            foreach ($this->klien as $k) {
                $baca[] = $k['sock'];
                if ($k['keluar'] !== '') {
                    $tulis[] = $k['sock'];
                }
            }
            $kecuali = null;
            if (@stream_select($baca, $tulis, $kecuali, 1) === false) {
                continue;
            }

            foreach ($baca as $s) {
                if ($s === $server) {
                    $this->terima($server);

                    continue;
                }
                $id = (int) $s;
                if (! isset($this->klien[$id])) {
                    continue;
                }
                $data = @fread($s, 65536);
                if ($data === false || ($data === '' && feof($s))) {
                    $this->tutup($id);

                    continue;
                }
                if ($data === '') {
                    continue;
                }
                $this->klien[$id]['buf'] .= $data;
                $this->klien[$id]['aktif'] = time();
                $this->proses($id);
            }

            foreach ($tulis as $s) {
                $id = (int) $s;
                if (! isset($this->klien[$id])) {
                    continue;
                }
                $n = @fwrite($s, $this->klien[$id]['keluar']);
                if ($n === false) {
                    $this->tutup($id);

                    continue;
                }
                $this->klien[$id]['keluar'] = (string) substr($this->klien[$id]['keluar'], $n);
                if ($this->klien[$id]['keluar'] === '' && $this->klien[$id]['tutup']) {
                    $this->tutup($id);
                }
            }

            if (time() - $ping >= 20) {
                $ping = time();
                foreach ($this->klien as $id => $k) {
                    if ($k['aktif'] < time() - 60) {
                        $this->tutup($id); // tidak ada balasan pong / data selama 60 detik
                    } elseif ($k['siap']) {
                        $this->kirim($id, 0x9, 'p');
                    }
                }
            }
        }
    }

    private function terima($server): void
    {
        $c = @stream_socket_accept($server, 0, $alamat);
        if (! $c) {
            return;
        }
        stream_set_blocking($c, false);
        $this->klien[(int) $c] = [
            'sock' => $c, 'buf' => '', 'keluar' => '', 'siap' => false, 'user' => null, 'peran' => null,
            'aktif' => time(), 'tutup' => false, 'frag' => '', 'fragOp' => 0, 'ip' => (string) $alamat,
        ];
    }

    private function proses(int $id): void
    {
        if (! $this->klien[$id]['siap']) {
            $this->jabatTangan($id);
            if (! isset($this->klien[$id]) || ! $this->klien[$id]['siap']) {
                return;
            }
        }

        while (isset($this->klien[$id])) {
            $buf = &$this->klien[$id]['buf'];
            if (strlen($buf) < 2) {
                return;
            }
            $b1 = ord($buf[0]);
            $b2 = ord($buf[1]);
            $fin = (bool) ($b1 & 0x80);
            $op = $b1 & 0x0F;
            $len = $b2 & 0x7F;
            $off = 2;
            if ($len === 126) {
                if (strlen($buf) < 4) {
                    return;
                }
                $len = unpack('n', substr($buf, 2, 2))[1];
                $off = 4;
            } elseif ($len === 127) {
                if (strlen($buf) < 10) {
                    return;
                }
                $len = unpack('J', substr($buf, 2, 8))[1];
                $off = 10;
            }
            if ($len > self::MAKS_PESAN) {
                $this->tutup($id);

                return;
            }
            $mask = '';
            if ($b2 & 0x80) {
                if (strlen($buf) < $off + 4) {
                    return;
                }
                $mask = substr($buf, $off, 4);
                $off += 4;
            }
            if (strlen($buf) < $off + $len) {
                return;
            }
            $isi = (string) substr($buf, $off, $len);
            $buf = (string) substr($buf, $off + $len);
            unset($buf);
            if ($mask !== '' && $len > 0) {
                $isi ^= substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len);
            }

            if ($op === 0x0) { // lanjutan pesan terpecah
                $this->klien[$id]['frag'] .= $isi;
                if (! $fin) {
                    continue;
                }
                $op = $this->klien[$id]['fragOp'];
                $isi = $this->klien[$id]['frag'];
                $this->klien[$id]['frag'] = '';
            } elseif (! $fin && ($op === 0x1 || $op === 0x2)) {
                $this->klien[$id]['fragOp'] = $op;
                $this->klien[$id]['frag'] = $isi;

                continue;
            }

            match ($op) {
                0x1, 0x2 => $this->pesan($id, $op, $isi),
                0x8 => $this->tutupSopan($id),
                0x9 => $this->kirim($id, 0xA, $isi),
                default => null, // pong & lainnya
            };
        }
    }

    private function jabatTangan(int $id): void
    {
        $buf = $this->klien[$id]['buf'];
        $akhir = strpos($buf, "\r\n\r\n");
        if ($akhir === false) {
            if (strlen($buf) > 8192) {
                $this->tutup($id);
            }

            return;
        }
        $this->klien[$id]['buf'] = (string) substr($buf, $akhir + 4);
        $baris = explode("\r\n", substr($buf, 0, $akhir));
        $kepala = [];
        foreach (array_slice($baris, 1) as $b) {
            if (str_contains($b, ':')) {
                [$k, $v] = explode(':', $b, 2);
                $kepala[strtolower(trim($k))] = trim($v);
            }
        }
        if (isset($kepala['x-real-ip'])) {
            $this->klien[$id]['ip'] = $kepala['x-real-ip'];
        }
        $kunciWs = $kepala['sec-websocket-key'] ?? '';
        $jalur = explode(' ', $baris[0] ?? '')[1] ?? '';
        parse_str((string) parse_url($jalur, PHP_URL_QUERY), $q);
        $token = is_string($q['t'] ?? null) ? TokenMic::periksa($q['t']) : null;

        if ($kunciWs === '' || ! $token) {
            $this->klien[$id]['keluar'] .= "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
            $this->klien[$id]['tutup'] = true;
            $this->log('ditolak (token tidak sah) dari '.$this->klien[$id]['ip']);

            return;
        }

        $terima = base64_encode(sha1($kunciWs.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $this->klien[$id]['keluar'] .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$terima}\r\n\r\n";
        $this->klien[$id]['siap'] = true;
        $this->klien[$id]['user'] = (int) $token['u'];
        $this->klien[$id]['peran'] = $token['p'];
        $this->log("{$token['p']} tersambung (akun {$token['u']}".($token['d'] ? ", laptop #{$token['d']}" : '').') dari '.$this->klien[$id]['ip']);

        if ($token['p'] === TokenMic::TERIMA && isset($this->mulai[$token['u']])) {
            $this->kirim($id, 0x1, $this->mulai[$token['u']]);
        }
        $this->kabariJumlah((int) $token['u']);
    }

    private function pesan(int $id, int $op, string $isi): void
    {
        $k = $this->klien[$id];
        if ($k['peran'] !== TokenMic::KIRIM) {
            return; // penerima tidak mengirim apa pun selain ping/pong
        }
        if ($op === 0x1) {
            $json = json_decode($isi, true);
            $t = is_array($json) ? ($json['t'] ?? '') : '';
            if ($t === 'mulai') {
                $this->mulai[$k['user']] = $isi;
            } elseif ($t === 'stop') {
                unset($this->mulai[$k['user']]);
            }
        }
        foreach ($this->klien as $tid => $t) {
            if ($t['siap'] && $t['user'] === $k['user'] && $t['peran'] === TokenMic::TERIMA) {
                if ($op === 0x2 && strlen($t['keluar']) > self::MAKS_ANTRE_SUARA) {
                    continue;
                }
                $this->kirim($tid, $op, $isi);
            }
        }
    }

    /** Pengirim diberi tahu berapa laptop penerima tersambung, penerima diberi tahu berapa HP tersambung. */
    private function kabariJumlah(int $user): void
    {
        $nKirim = $nTerima = 0;
        foreach ($this->klien as $k) {
            if ($k['siap'] && $k['user'] === $user) {
                $k['peran'] === TokenMic::KIRIM ? $nKirim++ : $nTerima++;
            }
        }
        foreach ($this->klien as $id => $k) {
            if ($k['siap'] && $k['user'] === $user) {
                $this->kirim($id, 0x1, $k['peran'] === TokenMic::KIRIM
                    ? json_encode(['t' => 'penerima', 'n' => $nTerima])
                    : json_encode(['t' => 'pengirim', 'n' => $nKirim]));
            }
        }
    }

    private function kirim(int $id, int $op, string $isi): void
    {
        if (! isset($this->klien[$id]) || $this->klien[$id]['tutup']) {
            return;
        }
        $len = strlen($isi);
        $kepala = chr(0x80 | $op);
        if ($len < 126) {
            $kepala .= chr($len);
        } elseif ($len < 65536) {
            $kepala .= chr(126).pack('n', $len);
        } else {
            $kepala .= chr(127).pack('J', $len);
        }
        $this->klien[$id]['keluar'] .= $kepala.$isi;
    }

    private function tutupSopan(int $id): void
    {
        $this->kirim($id, 0x8, '');
        $this->klien[$id]['tutup'] = true;
    }

    private function tutup(int $id): void
    {
        if (! isset($this->klien[$id])) {
            return;
        }
        $k = $this->klien[$id];
        @fclose($k['sock']);
        unset($this->klien[$id]);
        if (! $k['siap']) {
            return;
        }
        $this->log("{$k['peran']} terputus (akun {$k['user']})");
        if ($k['peran'] === TokenMic::KIRIM) {
            $masihAda = collect($this->klien)->contains(fn ($x) => $x['siap'] && $x['user'] === $k['user'] && $x['peran'] === TokenMic::KIRIM);
            if (! $masihAda && isset($this->mulai[$k['user']])) {
                unset($this->mulai[$k['user']]);
                foreach ($this->klien as $tid => $t) {
                    if ($t['siap'] && $t['user'] === $k['user']) {
                        $this->kirim($tid, 0x1, json_encode(['t' => 'stop']));
                    }
                }
            }
        }
        $this->kabariJumlah((int) $k['user']);
    }

    private function log(string $pesan): void
    {
        $this->line('['.now()->format('Y-m-d H:i:s').'] '.$pesan);
    }
}
