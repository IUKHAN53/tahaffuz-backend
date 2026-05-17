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
    Route::post('/text', [ChatController::class, 'text']);
    Route::post('/audio', [ChatController::class, 'audio']);
    Route::get('/{chat}', [ChatController::class, 'show']);
    Route::delete('/{chat}', [ChatController::class, 'destroy']);
});

Route::get('/chats', [ChatController::class, 'index']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
