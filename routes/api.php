<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// MCP server untuk ChatGPT (hanya baca), dilindungi OAuth: hanya email terdaftar (lihat McpController & OAuthMcpController).
Route::match(['get', 'post', 'delete'], '/mcp', \App\Http\Controllers\McpController::class)
    ->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class.':api')->middleware('throttle:240,1')->name('mcp');
Route::post('/oauth/register', [\App\Http\Controllers\OAuthMcpController::class, 'daftar'])->name('oauth.daftar');
Route::post('/oauth/token', [\App\Http\Controllers\OAuthMcpController::class, 'token'])->name('oauth.token');
