<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class AreaController extends Controller
{
    /**
     * The District → Town → Union Council hierarchy for the registration
     * dropdowns. Built from the static area list and cached.
     */
    public function index(): JsonResponse
    {
        $tree = Cache::remember('areas_tree', 86400, function () {
            $rows = require database_path('data/areas.php');

            $byDistrict = [];
            foreach ($rows as $r) {
                $d = $r['district'];
                $t = $r['town'] !== '' ? $r['town'] : 'Other';
                $byDistrict[$d][$t][] = $r['uc'];
            }

            $districts = [];
            foreach ($byDistrict as $district => $towns) {
                $townList = [];
                foreach ($towns as $town => $ucs) {
                    $townList[] = [
                        'name' => $town,
                        'union_councils' => array_values(array_unique($ucs)),
                    ];
                }
                $districts[] = ['name' => $district, 'towns' => $townList];
            }

            return $districts;
        });

        return response()->json(['districts' => $tree]);
    }
}
