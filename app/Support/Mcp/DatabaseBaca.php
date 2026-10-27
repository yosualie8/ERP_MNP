<?php

namespace App\Support\Mcp;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Akses BACA SAJA ke seluruh database ERP MNP untuk MCP (owner 27 Okt 2026, khusus Super Admin). Dikunci dua lapis:
 * 1. koneksi 'baca' memakai user MySQL yang hanya punya hak SELECT (tidak bisa mengubah / menghapus apa pun);
 * 2. di aplikasi: hanya satu perintah SELECT / WITH / SHOW / DESCRIBE / EXPLAIN, tabel & kolom rahasia (token, password) ditolak,
 *    batas waktu 15 detik, hasil maksimal 500 baris.
 */
class DatabaseBaca
{
    /** Tabel berisi rahasia (token login / Google, kunci OAuth) — tidak bisa dibaca sama sekali. */
    public const TABEL_RAHASIA = ['google_integrasi', 'oauth_klien', 'oauth_kode', 'oauth_token', 'personal_access_tokens', 'password_reset_tokens', 'failed_jobs', 'sessions', 'cache', 'cache_locks', 'jobs'];

    /** Kolom rahasia — query yang menyebutnya ditolak, dan tidak ditampilkan di daftar kolom. */
    public const KOLOM_RAHASIA = ['password', 'remember_token', 'token_hash', 'refresh_token', 'access_token', 'secret_hash', 'google_id'];

    public const MAKS_BARIS = 500;

    private static function db()
    {
        $c = DB::connection('baca');
        $c->statement('SET SESSION MAX_EXECUTION_TIME = 15000'); // milidetik, hanya berlaku untuk SELECT

        return $c;
    }

    /** Semua tabel (kecuali rahasia) + jumlah baris & kolom; dengan $tabel: kolom lengkap + contoh isi. */
    public static function tabel(?string $tabel = null, int $contoh = 5): array
    {
        $skema = config('database.connections.baca.database');
        $kolom = collect(DB::connection('baca')->select('SELECT table_name t, column_name c, column_type tipe, is_nullable n, column_key k, column_comment ket
            FROM information_schema.columns WHERE table_schema = ? ORDER BY table_name, ordinal_position', [$skema]))
            ->reject(fn ($x) => in_array($x->t, self::TABEL_RAHASIA, true) || in_array($x->c, self::KOLOM_RAHASIA, true))->groupBy('t');
        if ($tabel !== null) {
            $tabel = strtolower(trim($tabel));
            if (! $kolom->has($tabel)) {
                throw new InvalidArgumentException("Tabel \"{$tabel}\" tidak ada atau tidak boleh dibaca. Pakai daftar_tabel tanpa argumen untuk melihat semua tabel.");
            }
            $pilih = $kolom[$tabel]->pluck('c')->map(fn ($c) => "`{$c}`")->implode(', ');
            $isi = self::db()->select("SELECT {$pilih} FROM `{$tabel}` ORDER BY 1 DESC LIMIT ".max(0, min($contoh, 50)));

            return ['tabel' => $tabel, 'jumlah_baris' => (int) DB::connection('baca')->table($tabel)->count(),
                'kolom' => $kolom[$tabel]->map(fn ($x) => ['nama' => $x->c, 'tipe' => $x->tipe, 'boleh_kosong' => $x->n === 'YES', 'kunci' => $x->k ?: null, 'keterangan' => $x->ket ?: null])->values(),
                'contoh' => array_map(fn ($r) => self::rapi((array) $r), $isi)];
        }
        $baris = collect(DB::connection('baca')->select('SELECT table_name t, table_rows n FROM information_schema.tables WHERE table_schema = ?', [$skema]))->pluck('n', 't');

        return ['catatan' => 'Jumlah baris adalah perkiraan MySQL. Kolom rahasia (password/token) & tabel token tidak ditampilkan. Pakai query_database untuk membaca isinya.',
            'tabel' => $kolom->map(fn ($k, $t) => ['tabel' => $t, 'perkiraan_baris' => (int) ($baris[$t] ?? 0), 'kolom' => $k->pluck('c')->implode(', ')])->values()];
    }

    /** Jalankan satu query baca. @return array{kolom: string[], baris: array, jumlah: int, terpotong: bool} */
    public static function query(string $sql, int $batas = 100): array
    {
        $sql = trim(preg_replace('/;\s*$/', '', trim($sql)));
        $batas = max(1, min($batas, self::MAKS_BARIS));
        $tanpaString = preg_replace(["/'(?:[^'\\\\]|\\\\.)*'/s", '/"(?:[^"\\\\]|\\\\.)*"/s'], "''", $sql); // isi teks diabaikan saat memeriksa
        $kecil = strtolower($tanpaString);
        if ($sql === '' || ! preg_match('/^\s*(select|with|show|describe|desc|explain)\b/i', $sql)) {
            throw new InvalidArgumentException('Hanya query baca yang diizinkan: SELECT, WITH … SELECT, SHOW, DESCRIBE atau EXPLAIN.');
        }
        if (str_contains($tanpaString, ';')) {
            throw new InvalidArgumentException('Hanya satu perintah per query (tanpa titik koma di tengah).');
        }
        if (preg_match('/\b(insert|update|delete|drop|alter|create|truncate|grant|revoke|rename|lock|unlock|call|handler|load_file|outfile|dumpfile|sleep|benchmark|get_lock)\b/', $kecil, $m)) {
            throw new InvalidArgumentException("Kata \"{$m[1]}\" tidak diizinkan — akses database ini hanya untuk membaca.");
        }
        if (preg_match('/\b(mysql|performance_schema|sys)\s*\./', $kecil)) {
            throw new InvalidArgumentException('Hanya database ERP MNP yang boleh dibaca.');
        }
        foreach ([...self::TABEL_RAHASIA, ...self::KOLOM_RAHASIA] as $r) {
            if (preg_match('/\b'.preg_quote($r, '/').'\b/', $kecil)) {
                throw new InvalidArgumentException("\"{$r}\" berisi data rahasia (token / password) dan tidak boleh dibaca.");
            }
        }
        // users: SELECT * akan ikut mengambil password → wajib sebut kolomnya.
        if (preg_match('/\busers\b/', $kecil) && preg_match('/(^|[\s,(])(\w+\.)?\*/', $kecil)) {
            throw new InvalidArgumentException('Tabel users: sebutkan kolomnya (mis. id, name, email, role) — SELECT * tidak diizinkan karena ada kolom rahasia.');
        }
        $isSelect = preg_match('/^\s*(select|with)\b/i', $sql);
        $jalan = $isSelect ? 'SELECT * FROM ('.$sql.') AS hasil_mcp LIMIT '.($batas + 1) : $sql;
        $baris = array_map(fn ($r) => self::rapi((array) $r), self::db()->select($jalan));
        $terpotong = count($baris) > $batas;
        $baris = array_slice($baris, 0, $batas);

        return ['kolom' => $baris ? array_keys($baris[0]) : [], 'jumlah' => count($baris), 'terpotong' => $terpotong, 'baris' => $baris]
            + ($terpotong ? ['catatan' => "Hasil dipotong {$batas} baris pertama — persempit dengan WHERE / GROUP BY, atau naikkan batas (maks ".self::MAKS_BARIS.').'] : []);
    }

    /** Kolom rahasia dibuang (bila lolos lewat alias), teks panjang dipotong. */
    private static function rapi(array $r): array
    {
        foreach ($r as $k => $v) {
            if (in_array(strtolower((string) $k), self::KOLOM_RAHASIA, true)) {
                unset($r[$k]);
            } elseif (is_string($v) && mb_strlen($v) > 1000) {
                $r[$k] = mb_substr($v, 0, 1000).'…';
            }
        }

        return $r;
    }
}
