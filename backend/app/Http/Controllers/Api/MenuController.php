<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MenuResource;
use App\Models\Menu;
use Illuminate\Http\JsonResponse;

class MenuController extends Controller
{
    public function show(string $location): MenuResource|JsonResponse
    {
        $menu = Menu::query()
            ->where('location', $location)
            ->where('is_active', true)
            ->with('activeItems')
            ->first();

        if (! $menu) {
            return response()->json([
                'data' => [
                    'id' => null,
                    'name' => null,
                    'location' => $location,
                    'items' => [],
                ],
            ]);
        }

        return MenuResource::make($menu);
    }
}
