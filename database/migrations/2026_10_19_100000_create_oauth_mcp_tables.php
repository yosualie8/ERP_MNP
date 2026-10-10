<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// OAuth 2.1 untuk MCP server (ChatGPT): klien terdaftar (Dynamic Client Registration), kode otorisasi (PKCE) & token.
// Yang disimpan hanya hash kode/token. Token ikut terhapus bila penggunanya dihapus dari menu Pengguna.
return new class extends Migration
{
    public function up(): void
    {
        // Akses lama lewat URL rahasia (/api/mcp/{token}) dimatikan: tokennya dihapus.
        DB::table('pengaturan_app')->where('kunci', 'mcp_token_hash')->delete();

        Schema::create('oauth_klien', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 100)->unique();
            $table->string('nama', 200)->nullable();
            $table->json('redirect_uris');
            $table->string('secret_hash', 64)->nullable();
            $table->string('metode_auth', 30)->default('none');
            $table->timestamps();
        });

        Schema::create('oauth_kode', function (Blueprint $table) {
            $table->id();
            $table->string('kode_hash', 64)->unique();
            $table->string('client_id', 100);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('redirect_uri', 500);
            $table->string('code_challenge', 128);
            $table->string('resource', 300)->nullable();
            $table->string('scope', 100)->nullable();
            $table->dateTime('kedaluwarsa');
            $table->timestamps();
        });

        Schema::create('oauth_token', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('jenis', 10); // akses | refresh
            $table->string('client_id', 100);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 100)->nullable();
            $table->dateTime('kedaluwarsa');
            $table->dateTime('dicabut')->nullable();
            $table->dateTime('terakhir_dipakai')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_token');
        Schema::dropIfExists('oauth_kode');
        Schema::dropIfExists('oauth_klien');
    }
};
