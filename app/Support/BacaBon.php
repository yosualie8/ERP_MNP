<?php

namespace App\Support;

use Anthropic\Client;
use App\Models\Pengaturan;
use RuntimeException;

/**
 * Baca foto nota/struk belanja dengan Claude (vision) → daftar barang + nominal, untuk mengisi rincian bon.
 * Hasilnya selalu diperiksa admin sebelum disimpan; baris yang tulisannya meragukan ditandai "ragu".
 */
class BacaBon
{
    public const MODEL = 'claude-opus-5-5';

    private const SISTEM = <<<'TXT'
Anda membaca foto nota, kuitansi, atau struk belanja dari Indonesia (toko bangunan, bengkel, SPBU, warung, minimarket, toko online, dll.) untuk dicatat sebagai pengeluaran kas perusahaan. Nota sering ditulis tangan, miring, terlipat, atau buram.

Aturan membaca:
- Ambil SETIAP baris barang/jasa yang dibeli. Jangan gabungkan baris, jangan mengarang baris yang tidak ada.
- Angka rupiah: titik/koma adalah pemisah ribuan ("25.000", "25,000", "25rb", "25k" = 25000). Tulis jumlah sebagai bilangan bulat rupiah tanpa pemisah.
- "jumlah" = total rupiah baris itu (qty × harga satuan bila tertulis). Bila hanya ada satu angka di baris, itu "jumlah".
- qty, satuan, dan harga_satuan isi hanya bila terbaca; selebihnya 0 atau "". Satuan pakai yang tertulis (pcs, sak, btg, lbr, roll, kg, m, liter, dus, set, unit, ...).
- nama: nama barang/jasa apa adanya dari nota, rapikan ejaan yang jelas salah ketik, tanpa qty dan satuan.
- ragu = true bila angka atau nama baris itu sulit dibaca/kemungkinan salah baca.
- Ongkos kirim, ongkos pasang, PPN, biaya layanan, materai: masukkan ke biaya_lain (bukan items).
- diskon: total potongan harga (bilangan positif), 0 bila tidak ada.
- total: angka total/jumlah yang harus dibayar yang tertulis di nota; 0 bila tidak ada.
- tanggal: format YYYY-MM-DD bila terbaca, "" bila tidak. toko: nama toko/penjual bila ada, "" bila tidak.
- catatan: singkat, dalam bahasa Indonesia, hanya hal yang perlu dicek admin (mis. "angka baris 3 tertutup lipatan", "total di nota tidak sama dengan jumlah baris"). Kosongkan bila semuanya jelas.
- Bila foto bukan nota/kuitansi, kembalikan items kosong dan jelaskan di catatan.
TXT;

    private const SKEMA = [
        'type' => 'object',
        'properties' => [
            'toko' => ['type' => 'string'],
            'tanggal' => ['type' => 'string'],
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nama' => ['type' => 'string'],
                        'qty' => ['type' => 'number'],
                        'satuan' => ['type' => 'string'],
                        'harga_satuan' => ['type' => 'integer'],
                        'jumlah' => ['type' => 'integer'],
                        'ragu' => ['type' => 'boolean'],
                    ],
                    'required' => ['nama', 'qty', 'satuan', 'harga_satuan', 'jumlah', 'ragu'],
                    'additionalProperties' => false,
                ],
            ],
            'biaya_lain' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => ['nama' => ['type' => 'string'], 'jumlah' => ['type' => 'integer']],
                    'required' => ['nama', 'jumlah'],
                    'additionalProperties' => false,
                ],
            ],
            'diskon' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'catatan' => ['type' => 'string'],
        ],
        'required' => ['toko', 'tanggal', 'items', 'biaya_lain', 'diskon', 'total', 'catatan'],
        'additionalProperties' => false,
    ];

    public static function apiKey(): ?string
    {
        return Pengaturan::ambil(Pengaturan::API_KEY_CLAUDE) ?: (config('services.anthropic.key') ?: null);
    }

    /**
     * @return array{toko: string, tanggal: string, items: array, biaya_lain: array, diskon: int, total: int, catatan: string, model: string}
     */
    public function baca(string $path, string $mime): array
    {
        $key = self::apiKey() ?? throw new RuntimeException('API key Claude belum diisi. Super admin dapat mengisinya di menu Pengguna.');
        [$data, $mime] = self::siapkanGambar($path, $mime);

        $client = new Client(apiKey: $key);
        $pesan = $client->beta->messages->create(
            model: config('services.anthropic.model') ?: self::MODEL,
            maxTokens: 16000,
            // Bila model menolak karena kebijakan, server mencoba model cadangan bawaan.
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
            outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::SKEMA]],
            system: self::SISTEM,
            messages: [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => $data]],
                    ['type' => 'text', 'text' => 'Baca nota ini.'],
                ],
            ]],
        );

        if ($pesan->stopReason === 'refusal') {
            throw new RuntimeException('Foto ini tidak bisa diproses. Isi rincian bon secara manual.');
        }
        if ($pesan->stopReason === 'max_tokens') {
            throw new RuntimeException('Nota terlalu panjang untuk dibaca sekaligus. Foto per bagian, atau isi manual.');
        }
        $teks = null;
        foreach ($pesan->content as $blok) {
            if ($blok->type === 'text') {
                $teks = $blok->text;
                break;
            }
        }
        $hasil = json_decode((string) $teks, true);
        if (! is_array($hasil)) {
            throw new RuntimeException('Hasil pembacaan tidak bisa diolah. Coba foto ulang dengan lebih jelas.');
        }

        return [...$hasil, 'model' => $pesan->model];
    }

    /**
     * Perkecil foto (sisi terpanjang 1600 px, JPEG) supaya cepat & hemat; foto HP biasanya 3–6 MB.
     * Tanpa ekstensi GD, foto dikirim apa adanya bila jenisnya didukung dan ukurannya ≤ 5 MB.
     *
     * @return array{0: string, 1: string} base64, media type
     */
    public static function siapkanGambar(string $path, string $mime): array
    {
        $didukung = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring((string) file_get_contents($path)))) {
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $putar = [3 => 180, 6 => -90, 8 => 90][(int) (@exif_read_data($path)['Orientation'] ?? 1)] ?? 0;
                if ($putar) {
                    $img = imagerotate($img, $putar, 0);
                }
            }
            [$w, $h] = [imagesx($img), imagesy($img)];
            $skala = min(1, 1600 / max($w, $h));
            if ($skala < 1) {
                $img = imagescale($img, (int) round($w * $skala), (int) round($h * $skala));
            }
            ob_start();
            imagejpeg($img, null, 85);
            $jpeg = ob_get_clean();
            imagedestroy($img);

            return [base64_encode($jpeg), 'image/jpeg'];
        }
        if (! in_array($mime, $didukung, true)) {
            throw new RuntimeException('Format foto tidak didukung. Gunakan JPG atau PNG (di iPhone: Setelan → Kamera → Format → Paling Kompatibel).');
        }
        if (filesize($path) > 5 * 1024 * 1024) {
            throw new RuntimeException('Foto lebih dari 5 MB. Kecilkan resolusi kamera atau potong fotonya.');
        }

        return [base64_encode((string) file_get_contents($path)), $mime];
    }
}
