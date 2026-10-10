<?php

namespace App\Console\Commands;

use App\Http\Controllers\McpController;
use App\Models\PengaturanApp;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Buat (atau ganti) token rahasia MCP server ChatGPT. Token lama langsung tidak berlaku; yang disimpan hanya hash-nya. */
class McpToken extends Command
{
    protected $signature = 'mnp:mcp-token {--cabut : Matikan MCP server (hapus token) tanpa membuat yang baru}';

    protected $description = 'Buat/ganti URL rahasia MCP server untuk ChatGPT (hanya baca)';

    public function handle(): int
    {
        if ($this->option('cabut')) {
            PengaturanApp::simpan(McpController::KUNCI_TOKEN, null);
            $this->info('MCP server dimatikan: semua URL lama tidak berlaku.');

            return self::SUCCESS;
        }
        $token = Str::random(48);
        PengaturanApp::simpan(McpController::KUNCI_TOKEN, hash('sha256', $token));
        $this->info('Token MCP baru dibuat (token lama tidak berlaku lagi). URL MCP server untuk ChatGPT:');
        $this->line(route('mcp', $token));
        $this->warn('Simpan URL ini seperti password — siapa pun yang tahu URL ini bisa membaca data ERP.');

        return self::SUCCESS;
    }
}
