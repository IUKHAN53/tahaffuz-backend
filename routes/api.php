<?php

use App\Http\Controllers\Api\ChatController;
use App\Models\KnowledgeBase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['ok' => true, 'app' => config('app.name')]);

Route::get('/knowledge-bases', function () {
    return KnowledgeBase::query()->select(['id', 'slug', 'name', 'language', 'is_default'])->get();
});

Route::prefix('chat')->group(function () {
    // Gemini-backed endpoints: throttled per device to protect free-tier quota.
    Route::middleware('throttle:chat')->group(function () {
        Route::post('/text', [ChatController::class, 'text']);
        Route::post('/text/stream', [ChatController::class, 'stream']);
        Route::post('/audio', [ChatController::class, 'audio']);
    });

    // Cheap read/delete endpoints: a looser per-device limit.
    Route::middleware('throttle:chat-read')->group(function () {
        Route::get('/{chat}', [ChatController::class, 'show']);
        Route::delete('/{chat}', [ChatController::class, 'destroy']);
    });
});

Route::get('/chats', [ChatController::class, 'index'])->middleware('throttle:chat-read');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
