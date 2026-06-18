<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    /**
     * Register (or update) the worker behind a device. Called once at
     * onboarding; safe to call again to edit their details.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'min:8', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'designation' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:120'],
            'town' => ['nullable', 'string', 'max:120'],
            'union_council' => ['nullable', 'string', 'max:120'],
        ]);

        $worker = Worker::updateOrCreate(
            ['device_id' => $data['device_id']],
            $data,
        );

        return response()->json(['worker' => $worker]);
    }

    /**
     * Fetch the worker for a device, so the app knows whether onboarding is
     * already done. Returns {worker: null} when not registered.
     */
    public function show(Request $request): JsonResponse
    {
        $deviceId = (string) $request->query('device_id');
        if (mb_strlen($deviceId) < 8) {
            abort(400, 'device_id required');
        }

        return response()->json([
            'worker' => Worker::where('device_id', $deviceId)->first(),
        ]);
    }
}
