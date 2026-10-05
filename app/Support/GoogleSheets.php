<?php

namespace App\Support;

use App\Models\GoogleIntegrasi;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Baca spreadsheet atas nama akun Google yang dihubungkan saat login
 * (izin "spreadsheets" untuk isi sheet + "drive.readonly" untuk mencari/mendaftar file).
 */
class GoogleSheets
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive.readonly',
    ];

    private const API = 'https://sheets.googleapis.com/v4/spreadsheets';

    public function __construct(private GoogleIntegrasi $integrasi)
    {
    }

    public static function terhubung(): ?self
    {
        $integrasi = GoogleIntegrasi::aktif();

        return $integrasi ? new self($integrasi) : null;
    }

    public static function wajib(): self
    {
        return self::terhubung() ?? throw new RuntimeException('Akun Google belum dihubungkan. Login ke aplikasi MNP dengan Google terlebih dahulu.');
    }

    public function email(): string
    {
        return $this->integrasi->email;
    }

    /** Ambil id spreadsheet dari link lengkap atau id mentah. */
    public static function idDari(string $linkAtauId): string
    {
        return preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $linkAtauId, $m) ? $m[1] : trim($linkAtauId);
    }

    /** Judul spreadsheet + daftar lembar (judul, gid, ukuran grid, sel gabungan). */
    public function info(string $id): array
    {
        $res = $this->api()->get(self::API."/{$id}", [
            'fields' => 'properties.title,sheets(properties(sheetId,title,index,hidden,gridProperties),merges)',
        ]);
        $this->pastikan($res, 'membaca info spreadsheet');

        return $res->json();
    }

    /**
     * Isi beberapa lembar sekaligus.
     *
     * @param  string[]  $ranges  judul lembar atau range A1 (mis. "DATA!A1:H100")
     * @param  string  $render  FORMATTED_VALUE (seperti tampil di sheet), UNFORMATTED_VALUE, atau FORMULA
     * @return array<string, array<int, array<int, mixed>>> range => baris
     */
    public function nilai(string $id, array $ranges, string $render = 'FORMATTED_VALUE'): array
    {
        $query = http_build_query(['valueRenderOption' => $render, 'dateTimeRenderOption' => 'FORMATTED_STRING']);
        foreach ($ranges as $r) {
            $query .= '&ranges='.rawurlencode(self::kutip($r));
        }
        $res = $this->api()->get(self::API."/{$id}/values:batchGet?{$query}");
        $this->pastikan($res, 'membaca isi sheet');

        $hasil = [];
        foreach ($res->json('valueRanges', []) as $i => $vr) {
            $hasil[$ranges[$i]] = $vr['values'] ?? [];
        }

        return $hasil;
    }

    /**
     * Tulis beberapa range sekaligus, diisi seperti diketik di sheet (USER_ENTERED: rumus & tanggal dikenali).
     *
     * @param  array<string, array<int, array<int, mixed>>>  $data  range A1 => baris
     */
    public function tulis(string $id, array $data): void
    {
        $res = $this->api()->post(self::API."/{$id}/values:batchUpdate", [
            'valueInputOption' => 'USER_ENTERED',
            'data' => collect($data)->map(fn ($nilai, $range) => ['range' => self::kutip($range), 'values' => $nilai])->values()->all(),
        ]);
        $this->pastikan($res, 'menulis ke sheet');
    }

    /** Sisipkan $jumlah baris kosong mulai baris $baris (nomor baris sheet, 1 = baris pertama), mewarisi format baris di atasnya. */
    public function sisipBaris(string $id, int $sheetId, int $baris, int $jumlah): void
    {
        $res = $this->api()->post(self::API."/{$id}:batchUpdate", ['requests' => [[
            'insertDimension' => [
                'range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $baris - 1, 'endIndex' => $baris - 1 + $jumlah],
                'inheritFromBefore' => true,
            ],
        ]]]);
        $this->pastikan($res, 'menyisipkan baris');
    }

    /** Hapus baris $dari sampai $sampai (nomor baris sheet, inklusif). */
    public function hapusBaris(string $id, int $sheetId, int $dari, int $sampai): void
    {
        $res = $this->api()->post(self::API."/{$id}:batchUpdate", ['requests' => [[
            'deleteDimension' => ['range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $dari - 1, 'endIndex' => $sampai]],
        ]]]);
        $this->pastikan($res, 'menghapus baris');
    }

    /** Buat spreadsheet baru (dipakai untuk uji coba tulis di salinan). */
    public function buat(string $judul): string
    {
        $res = $this->api()->post(self::API, ['properties' => ['title' => $judul]]);
        $this->pastikan($res, 'membuat spreadsheet');

        return $res->json('spreadsheetId');
    }

    /** Salin satu lembar ke spreadsheet lain; kembalikan sheetId lembar hasil salinan. */
    public function salinLembar(string $dariId, int $sheetId, string $keId): int
    {
        $res = $this->api()->post(self::API."/{$dariId}/sheets/{$sheetId}:copyTo", ['destinationSpreadsheetId' => $keId]);
        $this->pastikan($res, 'menyalin lembar');

        return $res->json('sheetId');
    }

    /** Info lokal spreadsheet (locale & zona waktu). */
    public function lokal(string $id): array
    {
        $res = $this->api()->get(self::API."/{$id}", ['fields' => 'properties(locale,timeZone)']);
        $this->pastikan($res, 'membaca lokal spreadsheet');

        return $res->json('properties');
    }

    /** Samakan locale & zona waktu (dipakai salinan uji supaya angka, tanggal, dan pemisah rumus sama dengan aslinya). */
    public function aturLokal(string $id, string $locale, string $timeZone): void
    {
        $res = $this->api()->post(self::API."/{$id}:batchUpdate", ['requests' => [[
            'updateSpreadsheetProperties' => ['properties' => ['locale' => $locale, 'timeZone' => $timeZone], 'fields' => 'locale,timeZone'],
        ]]]);
        $this->pastikan($res, 'mengatur lokal spreadsheet');
    }

    /** Ganti judul lembar. */
    public function gantiJudulLembar(string $id, int $sheetId, string $judul): void
    {
        $res = $this->api()->post(self::API."/{$id}:batchUpdate", ['requests' => [[
            'updateSheetProperties' => ['properties' => ['sheetId' => $sheetId, 'title' => $judul], 'fields' => 'title'],
        ]]]);
        $this->pastikan($res, 'mengganti judul lembar');
    }

    /** @return array<int, array{id: string, name: string, modifiedTime: string, webViewLink: string}> */
    public function daftarSpreadsheet(int $jumlah = 20): array
    {
        $res = $this->api()->get('https://www.googleapis.com/drive/v3/files', [
            'q' => "mimeType = 'application/vnd.google-apps.spreadsheet' and trashed = false",
            'orderBy' => 'modifiedTime desc',
            'pageSize' => $jumlah,
            'fields' => 'files(id,name,modifiedTime,webViewLink)',
        ]);
        $this->pastikan($res, 'mendaftar spreadsheet');

        return $res->json('files', []);
    }

    /** Cabut izin aplikasi di akun Google. Gagal mencabut tidak dianggap error. */
    public function cabut(): void
    {
        Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/revoke', ['token' => $this->integrasi->refresh_token]);
    }

    /** Judul lembar berspasi/berangka perlu dikutip: 'DAILY 1'!A1. */
    private static function kutip(string $range): string
    {
        [$lembar, $sel] = array_pad(explode('!', $range, 2), 2, null);
        if (! str_starts_with($lembar, "'")) {
            $lembar = "'".str_replace("'", "''", $lembar)."'";
        }

        return $sel === null ? $lembar : "{$lembar}!{$sel}";
    }

    /** Klien HTTP ber-token untuk API Google lain (mis. Drive foto bon) memakai koneksi yang sama. */
    public function http(int $timeout = 120): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->token())->timeout($timeout);
    }

    private function api()
    {
        return Http::withToken($this->token())->timeout(120)->acceptJson();
    }

    private function token(): string
    {
        $i = $this->integrasi;
        if ($i->access_token && $i->access_token_kedaluwarsa?->isFuture()) {
            return $i->access_token;
        }

        $res = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $i->refresh_token,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
        ]);
        if ($res->json('error') === 'invalid_grant') {
            throw new RuntimeException('Izin Google sudah dicabut atau kedaluwarsa. Logout lalu login ulang dengan Google.');
        }
        $this->pastikan($res, 'memperbarui akses Google');

        $i->update([
            'access_token' => $res->json('access_token'),
            'access_token_kedaluwarsa' => now()->addSeconds(max(60, (int) $res->json('expires_in', 3600) - 120)),
        ]);

        return $i->access_token;
    }

    private function pastikan(Response $res, string $kegiatan): void
    {
        if ($res->successful()) {
            return;
        }
        $pesan = $res->json('error.message') ?? $res->json('error_description') ?? $res->json('error') ?? $res->body();
        if (str_contains((string) $pesan, 'has not been used') || str_contains((string) $pesan, 'is disabled')) {
            $pesan = 'API belum diaktifkan di Google Cloud Console proyek MNP. '.$pesan;
        }
        throw new RuntimeException("Google gagal saat {$kegiatan}: ".mb_strimwidth((string) $pesan, 0, 300, '…'));
    }
}
