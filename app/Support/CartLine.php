<?php

namespace App\Support;

/**
 * The session cart is stored as `[ma_sp => quantity]`, but AnToanThuocService and
 * ThanhToanService expect rows. Keeping the transform here means the shape is
 * defined once instead of in every controller that touches the cart.
 */
final class CartLine
{
    /**
     * @param array<int|string, int|string> $cart session cart, ma_sp => quantity
     * @return array<int, array{ma_sp: int, quantity: int}>
     */
    public static function rowsFrom(array $cart): array
    {
        $rows = [];

        foreach ($cart as $productId => $quantity) {
            $rows[] = ['ma_sp' => (int) $productId, 'quantity' => (int) $quantity];
        }

        return $rows;
    }
}
