<?php

use App\Http\Controllers\Api\AreaController;
use App\Http\Controllers\Api\BookmarkController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\QuickAnswersController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\TtsController;
use App\Http\Controllers\Api\WorkerController;
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

// Text-to-speech. GET so the app can stream it straight into an audio player.
// Cached on disk per phrase; cache hits never touch the rate-limited TTS model.
Route::get('/tts', [TtsController::class, 'speak'])->middleware('throttle:60,1');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Feedback endpoints
Route::prefix('feedback')->middleware('throttle:60,1')->group(function () {
    Route::post('/', [FeedbackController::class, 'store']);
    Route::get('/{messageId}', [FeedbackController::class, 'show']);
    Route::get('/{messageId}/check', [FeedbackController::class, 'check']);
});

// Bookmark endpoints
Route::prefix('bookmarks')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [BookmarkController::class, 'index']);
    Route::post('/toggle', [BookmarkController::class, 'toggle']);
    Route::get('/{messageId}/check', [BookmarkController::class, 'check']);
    Route::delete('/{bookmarkId}', [BookmarkController::class, 'destroy']);
});

// Search endpoints
Route::prefix('search')->middleware('throttle:30,1')->group(function () {
    Route::get('/messages', [SearchController::class, 'messages']);
});

// Offline quick answers (cached Q&A pairs)
Route::get('/quick-answers', [QuickAnswersController::class, 'index'])->middleware('throttle:10,1');

// Assistant memory — what it remembers about this worker/child, and clearing it.
Route::prefix('memories')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [MemoryController::class, 'index']);
    Route::delete('/', [MemoryController::class, 'destroy']);
});

// Registration + area hierarchy (onboarding)
Route::get('/areas', [AreaController::class, 'index'])->middleware('throttle:30,1');
Route::post('/register', [WorkerController::class, 'register'])->middleware('throttle:20,1');
Route::get('/worker', [WorkerController::class, 'show'])->middleware('throttle:60,1');

// Nearest vaccination sites by GPS
Route::get('/sites/nearest', [SiteController::class, 'nearest'])->middleware('throttle:60,1');

// Vaccination card scanner: extract → review → store → current child
Route::post('/card/scan', [CardController::class, 'scan'])->middleware('throttle:20,1');
Route::post('/card', [CardController::class, 'store'])->middleware('throttle:30,1');
Route::get('/card', [CardController::class, 'show'])->middleware('throttle:60,1');
// Computed immunization schedule for the current child, and overdue children.
Route::get('/card/schedule', [CardController::class, 'schedule'])->middleware('throttle:60,1');
Route::get('/defaulters', [CardController::class, 'defaulters'])->middleware('throttle:60,1');

// Push notification endpoints
Route::prefix('push')->middleware('throttle:30,1')->group(function () {
    Route::post('/register', [PushTokenController::class, 'register']);
    Route::post('/preferences', [PushTokenController::class, 'updatePreferences']);
    Route::post('/unregister', [PushTokenController::class, 'unregister']);
});
