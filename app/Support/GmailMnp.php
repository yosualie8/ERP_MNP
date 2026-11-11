<?php

namespace App\Support;

use App\Models\GoogleIntegrasi;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Gmail perusahaan (ptmultiniagaputra@gmail.com) — BACA SAJA (izin gmail.readonly), untuk mengenali penawaran
 * yang dikirim & SPK yang masuk. Memakai koneksi Google "gmail", terpisah dari koneksi sheet kas & Drive foto.
 */
class GmailMnp
{
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private GoogleSheets $google)
    {
    }

    public static function terhubung(): ?self
    {
        $i = GoogleIntegrasi::gmail();

        return $i ? new self(new GoogleSheets($i)) : null;
    }

    /** Daftar id pesan sesuai pencarian Gmail ($q sama seperti kotak cari Gmail, mis. "newer_than:90d has:attachment"). */
    public function cari(string $q, int $maks = 500): array
    {
        $ids = [];
        $halaman = null;
        do {
            $res = $this->google->http(60)->get(self::API.'/messages', array_filter([
                'q' => $q, 'maxResults' => min(500, $maks - count($ids)), 'pageToken' => $halaman,
            ]));
            $this->pastikan($res, 'mencari email');
            foreach ($res->json('messages', []) as $m) {
                $ids[] = $m['id'];
            }
            $halaman = $res->json('nextPageToken');
        } while ($halaman && count($ids) < $maks);

        return $ids;
    }

    /** Ringkasan satu pesan: tanggal, dari, ke, subjek, label, cuplikan, nama lampiran (+ id lampiran di 'berkas'). */
    public function ringkas(string $id): array
    {
        $res = $this->google->http(60)->get(self::API."/messages/{$id}", ['format' => 'full']);
        $this->pastikan($res, 'membaca email');
        $p = $res->json();
        $h = collect($p['payload']['headers'] ?? [])->mapWithKeys(fn ($x) => [strtolower($x['name']) => $x['value']]);
        $lampiran = [];
        $berkas = [];
        $jelajah = function (array $bagian) use (&$jelajah, &$lampiran, &$berkas) {
            if (($bagian['filename'] ?? '') !== '') {
                $lampiran[] = $bagian['filename'];
                if (isset($bagian['body']['attachmentId'])) {
                    $berkas[] = ['nama' => $bagian['filename'], 'id' => $bagian['body']['attachmentId']];
                }
            }
            foreach ($bagian['parts'] ?? [] as $anak) {
                $jelajah($anak);
            }
        };
        $jelajah($p['payload'] ?? []);

        return [
            'berkas' => $berkas,
            'id' => $id,
            'thread' => $p['threadId'] ?? null,
            'waktu' => isset($p['internalDate']) ? date('Y-m-d H:i', intdiv((int) $p['internalDate'], 1000)) : null,
            'dari' => $h['from'] ?? '',
            'ke' => $h['to'] ?? '',
            'subjek' => $h['subject'] ?? '',
            'label' => $p['labelIds'] ?? [],
            'cuplikan' => html_entity_decode($p['snippet'] ?? '', ENT_QUOTES),
            'lampiran' => $lampiran,
        ];
    }

    /** Isi biner satu lampiran. */
    public function lampiran(string $pesanId, string $lampiranId): string
    {
        $res = $this->google->http(120)->get(self::API."/messages/{$pesanId}/attachments/{$lampiranId}");
        $this->pastikan($res, 'mengambil lampiran');

        return base64_decode(strtr((string) $res->json('data'), '-_', '+/'));
    }

    private function pastikan(Response $res, string $aksi): void
    {
        if ($res->failed()) {
            throw new RuntimeException("Gagal {$aksi} di Gmail: ".($res->json('error.message') ?? $res->status()));
        }
    }
}
