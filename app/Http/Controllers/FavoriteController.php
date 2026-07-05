<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Product;
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
        $favoriteItems = Favorite::profileItemsFor($request->user());

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
            'product_ids.*' => ['integer', 'distinct', 'exists:products,MaSP'],
        ]);

        $productIds = collect($validated['product_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(100)
            ->values();

        $syncedCount = 0;

        foreach ($productIds as $productId) {
            $favorite = Favorite::firstOrCreate([
                'MaKH' => $request->user()->MaKH,
                'MaSP' => $productId,
            ]);

            if ($favorite->wasRecentlyCreated) {
                $syncedCount++;
            }
        }

        return response()->json([
            'favorites' => $this->favoriteIdsFor($request),
            'synced_count' => $syncedCount,
        ]);
    }

    public function toggle(Request $request, Product $product): JsonResponse
    {
        $favorite = Favorite::where('MaKH', $request->user()->MaKH)
            ->where('MaSP', $product->MaSP)
            ->first();

        if ($favorite) {
            $favorite->delete();
            $saved = false;
        } else {
            Favorite::create([
                'MaKH' => $request->user()->MaKH,
                'MaSP' => $product->MaSP,
            ]);
            $saved = true;
        }

        return response()->json([
            'product_id' => (string) $product->MaSP,
            'saved' => $saved,
            'favorites' => $this->favoriteIdsFor($request),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        Favorite::where('MaKH', $request->user()->MaKH)
            ->where('MaSP', $product->MaSP)
            ->delete();

        return response()->json([
            'product_id' => (string) $product->MaSP,
            'saved' => false,
            'favorites' => $this->favoriteIdsFor($request),
        ]);
    }

    private function favoriteIdsFor(Request $request): array
    {
        return Favorite::idsForUser($request->user());
    }
}
