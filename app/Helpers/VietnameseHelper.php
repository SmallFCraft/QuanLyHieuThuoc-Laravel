<?php

namespace App\Helpers;

class VietnameseHelper
{
    /**
     * Format currency to Vietnamese format (123.456 ₫).
     *
     * The single formatter for VND. Model accessors (Order::formatted_total,
     * OrderItem::formatted_subtotal, Product::formatted_price) and ChatbotService all
     * route here, so the rounding rule cannot drift between them.
     *
     * @param float|int|null $amount
     */
    public static function formatCurrency($amount): string
    {
        if ($amount === null) {
            return '0 ₫';
        }

        return number_format((int) round((float) $amount), 0, ',', '.') . ' ₫';
    }

    public static function getOrderStatusText(string $status): string
    {
        $statuses = [
            'Pending' => 'Chờ xử lý',
            'Delivering' => 'Đang giao',
            'Completed' => 'Hoàn thành',
            'Cancelled' => 'Đã hủy',
        ];

        return $statuses[$status] ?? $status;
    }

    public static function getRoleText(string $role): string
    {
        $roles = [
            'Administrator' => 'Quản trị viên',
            'Pharmacist' => 'Dược sĩ',
            'User' => 'Người dùng',
        ];

        return $roles[$role] ?? $role;
    }
}
