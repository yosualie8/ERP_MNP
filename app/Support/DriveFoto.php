<?php

namespace App\Support;

use App\Models\GoogleIntegrasi;
use App\Models\KasFoto;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Foto bon di Google Drive perusahaan (folder "Foto Bon MNP", subfolder per lembar mis. "1026").
 * Memakai koneksi Google "foto" (akun perusahaan, izin Drive) — terpisah dari koneksi sheet kas.
 */
class DriveFoto
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive';

    private const API = 'https://www.googleapis.com/drive/v3/files';

    private const FOLDER = 'application/vnd.google-apps.folder';

    public function __construct(private GoogleSheets $google)
    {
    }

    public static function terhubung(): ?self
    {
        $i = GoogleIntegrasi::foto();

        return $i ? new self(new GoogleSheets($i)) : null;
    }

    /** Unggah file penuh foto ini ke Drive; kembalikan [id, link]. */
    public function unggah(KasFoto $foto, string $judul): array
    {
        $isi = Storage::get($foto->path) ?? throw new RuntimeException("File sementara {$foto->path} tidak ada.");
        $batas = 'mnp-'.Str::random(16);
        $meta = json_encode(['name' => $judul, 'parents' => [$this->folderLembar($foto->lembar)]]);
        $body = "--{$batas}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n"
            ."--{$batas}\r\nContent-Type: image/jpeg\r\n\r\n{$isi}\r\n--{$batas}--";

        $res = $this->google->http(180)->withBody($body, "multipart/related; boundary={$batas}")
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,webViewLink');
        $this->pastikan($res, 'mengunggah foto');

        return [$res->json('id'), $res->json('webViewLink')];
    }

    /** Isi file dari Drive (untuk ditampilkan di aplikasi). */
    public function ambil(string $fileId): string
    {
        $res = $this->google->http(60)->get(self::API."/{$fileId}", ['alt' => 'media', 'supportsAllDrives' => 'true']);
        $this->pastikan($res, 'mengambil foto');

        return $res->body();
    }

    /** Pindahkan ke sampah Drive (masih bisa dipulihkan 30 hari dari Drive). */
    public function hapus(string $fileId): void
    {
        $res = $this->google->http(30)->patch(self::API."/{$fileId}?supportsAllDrives=true", ['trashed' => true]);
        if ($res->status() !== 404) {
            $this->pastikan($res, 'menghapus foto');
        }
    }

    /** Pastikan folder utama bisa ditulis; kembalikan namanya. */
    public function periksaFolder(): string
    {
        $res = $this->google->http(30)->get(self::API.'/'.config('mnp.drive_foto_folder'), ['fields' => 'name,capabilities(canAddChildren)', 'supportsAllDrives' => 'true']);
        $this->pastikan($res, 'membuka folder Foto Bon MNP');
        if (! $res->json('capabilities.canAddChildren')) {
            throw new RuntimeException('Akun ini tidak punya hak menambah file di folder Foto Bon MNP.');
        }

        return (string) $res->json('name');
    }

    private function folderLembar(string $lembar): string
    {
        $induk = config('mnp.drive_foto_folder');

        return Cache::rememberForever("drive-foto-folder-{$induk}-{$lembar}", function () use ($induk, $lembar) {
            $q = sprintf("name = '%s' and mimeType = '%s' and '%s' in parents and trashed = false", $lembar, self::FOLDER, $induk);
            $ada = $this->google->http(30)->get(self::API, ['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true']);
            $this->pastikan($ada, "mencari folder {$lembar}");
            if ($id = $ada->json('files.0.id')) {
                return $id;
            }
            $baru = $this->google->http(30)->post(self::API.'?supportsAllDrives=true&fields=id', ['name' => $lembar, 'mimeType' => self::FOLDER, 'parents' => [$induk]]);
            $this->pastikan($baru, "membuat folder {$lembar}");

            return $baru->json('id');
        });
    }

    private function pastikan(Response $res, string $kegiatan): void
    {
        if ($res->successful()) {
            return;
        }
        $pesan = $res->json('error.message') ?? $res->body();
        if (str_contains((string) $pesan, 'has not been used') || str_contains((string) $pesan, 'is disabled')) {
            $pesan = 'Google Drive API belum diaktifkan di proyek Google Cloud MNP.';
        }
        throw new RuntimeException("Google Drive gagal saat {$kegiatan}: ".mb_strimwidth((string) $pesan, 0, 300, '…'));
    }
}
