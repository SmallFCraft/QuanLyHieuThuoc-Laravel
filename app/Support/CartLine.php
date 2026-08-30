<?php

namespace App\Support;

/**
 * The session cart is stored as `[MaSP => quantity]`, but DrugSafetyService and
 * CheckoutService expect rows. Keeping the transform here means the shape is
 * defined once instead of in every controller that touches the cart.
 */
final class CartLine
{
    /**
     * @param array<int|string, int|string> $cart session cart, MaSP => quantity
     * @return array<int, array{MaSP: int, quantity: int}>
     */
    public static function rowsFrom(array $cart): array
    {
        $rows = [];

        foreach ($cart as $productId => $quantity) {
            $rows[] = ['MaSP' => (int) $productId, 'quantity' => (int) $quantity];
        }

        return $rows;
    }
}
