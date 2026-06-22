<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VaccinationCard;
use App\Models\Worker;
use App\Services\Gemini;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CardController extends Controller
{
    public function __construct(protected Gemini $gemini) {}

    /**
     * POST /api/card/scan — read a photographed card with Gemini Vision and
     * return the extracted fields for the worker to review. Nothing is stored
     * as a card yet; the image is kept so the confirmed record can reference it.
     */
    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'device_id' => ['required', 'string', 'min:8', 'max:64'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:12288'],
        ]);

        $file = $request->file('image');
        $path = $file->store('cards', 'local');
        $mime = $file->getMimeType() ?: 'image/jpeg';

        try {
            $extracted = $this->gemini->extractCardData(Storage::disk('local')->path($path), $mime);
        } catch (Throwable $e) {
            Log::error('Card extraction failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'extract_failed'], 503);
        }

        return response()->json([
            'image_path' => $path,
            'data' => $extracted,
        ]);
    }

    /**
     * POST /api/card — store the worker-confirmed card (also visible in admin).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'min:8', 'max:64'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'child_name' => ['nullable', 'string', 'max:120'],
            'sex' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'string', 'max:40'],
            'father_name' => ['nullable', 'string', 'max:120'],
            'mother_name' => ['nullable', 'string', 'max:120'],
            'card_number' => ['nullable', 'string', 'max:60'],
            'district' => ['nullable', 'string', 'max:120'],
            'town' => ['nullable', 'string', 'max:120'],
            'union_council' => ['nullable', 'string', 'max:120'],
            'next_due_date' => ['nullable', 'string', 'max:40'],
            'vaccines' => ['nullable', 'array'],
            'vaccines.*.name' => ['nullable', 'string', 'max:60'],
            'vaccines.*.given_date' => ['nullable', 'string', 'max:40'],
            'vaccines.*.due_date' => ['nullable', 'string', 'max:40'],
        ]);

        $worker = Worker::where('device_id', $data['device_id'])->first();

        $card = VaccinationCard::create([
            'device_id' => $data['device_id'],
            'worker_id' => $worker?->id,
            'child_name' => $data['child_name'] ?? null,
            'sex' => $data['sex'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'father_name' => $data['father_name'] ?? null,
            'mother_name' => $data['mother_name'] ?? null,
            'card_number' => $data['card_number'] ?? null,
            'district' => $data['district'] ?? null,
            'town' => $data['town'] ?? null,
            'union_council' => $data['union_council'] ?? null,
            'next_due_date' => $data['next_due_date'] ?? null,
            'vaccines' => $data['vaccines'] ?? [],
            'image_path' => $data['image_path'] ?? null,
        ]);

        return response()->json(['card' => $card]);
    }

    /**
     * GET /api/card?device_id= — the latest scanned card for the device (the
     * current child), so the app and chat can reference it. {card: null} if none.
     */
    public function show(Request $request): JsonResponse
    {
        $deviceId = (string) $request->query('device_id');
        if (mb_strlen($deviceId) < 8) {
            abort(400, 'device_id required');
        }

        return response()->json([
            'card' => VaccinationCard::where('device_id', $deviceId)->latest()->first(),
        ]);
    }
}
