<?php

use App\Helpers\VietnameseHelper;

if (!function_exists('format_currency_vi')) {
    /**
     * Định dạng tiền tệ theo định dạng tiếng Việt
     */
    function format_currency_vi($amount): string
    {
        return VietnameseHelper::formatCurrency($amount);
    }
}

if (!function_exists('order_status_text')) {
    /**
     * Lấy văn bản trạng thái đơn hàng tiếng Việt
     */
    function order_status_text(string $status): string
    {
        return VietnameseHelper::getOrderStatusText($status);
    }
}

if (!function_exists('role_text')) {
    /**
     * Lấy văn bản vai trò tiếng Việt
     */
    function role_text(string $role): string
    {
        return VietnameseHelper::getRoleText($role);
    }
}
