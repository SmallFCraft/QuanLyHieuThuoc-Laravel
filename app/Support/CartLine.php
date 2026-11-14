<?php

namespace App\Support;

/**
 * Gio hang trong session duoc luu nhu `[ma_sp => quantity]`, nhung AnToanThuocService va
 * ThanhToanService dung cac dong ban ghi. Giu phep chuyen doi tai day nghia la hinh dang
 * duoc dinh nghia mot lan thay vi trong moi controller cham vao gio hang.
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
