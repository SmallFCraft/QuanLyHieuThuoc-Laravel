<?php

namespace App\Support;

/**
 * Giỏ hàng trong session được lưu như `[ma_sp => quantity]`, nhưng AnToanThuocService và
 * ThanhToanService cần các dòng bản ghi. Giữ phép chuyển đổi tại đây nghĩa là hình dạng
 * được định nghĩa một lần thay vì trong mỗi controller chạm vào giỏ hàng.
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
