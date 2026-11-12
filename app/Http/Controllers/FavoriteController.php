<?php

namespace App\Http\Controllers;

use App\Models\YeuThich;
use App\Models\SanPham;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function ids(Request $request): JsonResponse
    {
        return response()->json([
            'favorites' => $this->favoriteIdsFor($request),
        ]);
    }

    public function section(Request $request): JsonResponse
    {
        $favoriteItems = YeuThich::profileItemsFor($request->user());

        return response()->json([
            'favorites' => $this->favoriteIdsFor($request),
            'html' => view('profile.partials.favorites', [
                'favoriteItems' => $favoriteItems,
            ])->render(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'distinct', 'exists:san_phams,ma_sp'],
        ]);

        $productIds = collect($validated['product_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(100)
            ->values();

        $syncedCount = 0;

        foreach ($productIds as $productId) {
            $yeuThich = YeuThich::firstOrCreate([
                'ma_nd' => $request->user()->ma_nd,
                'ma_sp' => $productId,
            ]);

            if ($yeuThich->wasRecentlyCreated) {
                $syncedCount++;
            }
        }

        return response()->json([
            'favorites' => $this->favoriteIdsFor($request),
            'synced_count' => $syncedCount,
        ]);
    }

    public function toggle(Request $request, SanPham $product): JsonResponse
    {
        $yeuThich = YeuThich::where('ma_nd', $request->user()->ma_nd)
            ->where('ma_sp', $product->ma_sp)
            ->first();

        if ($yeuThich) {
            $yeuThich->delete();
            $saved = false;
        } else {
            YeuThich::create([
                'ma_nd' => $request->user()->ma_nd,
                'ma_sp' => $product->ma_sp,
            ]);
            $saved = true;
        }

        return response()->json([
            'product_id' => (string) $product->ma_sp,
            'saved' => $saved,
            'favorites' => $this->favoriteIdsFor($request),
        ]);
    }

    public function destroy(Request $request, SanPham $product): JsonResponse
    {
        YeuThich::where('ma_nd', $request->user()->ma_nd)
            ->where('ma_sp', $product->ma_sp)
            ->delete();

        return response()->json([
            'product_id' => (string) $product->ma_sp,
            'saved' => false,
            'favorites' => $this->favoriteIdsFor($request),
        ]);
    }

    private function favoriteIdsFor(Request $request): array
    {
        return YeuThich::idsForUser($request->user());
    }
}
