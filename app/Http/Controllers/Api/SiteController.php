<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiteLocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function __construct(protected SiteLocator $locator) {}

    /**
     * Nearest vaccination sites to a coordinate.
     */
    public function nearest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
            'union_council' => ['nullable', 'string', 'max:120'],
        ]);

        $hits = $this->locator->nearest(
            (float) $data['lat'],
            (float) $data['lng'],
            (int) ($data['limit'] ?? 5),
            $data['union_council'] ?? null,
        );

        return response()->json([
            'sites' => array_map(fn ($h) => [
                'fix_site' => $h['site']->fix_site,
                'outreach_site' => $h['site']->outreach_site,
                'district' => $h['site']->district,
                'union_council' => $h['site']->union_council,
                'latitude' => $h['site']->latitude,
                'longitude' => $h['site']->longitude,
                'distance_km' => round($h['distance_km'], 2),
            ], $hits),
        ]);
    }
}
