<?php

use App\Helpers\VietnameseHelper;

if (!function_exists('format_currency_vi')) {
    /**
     * Format currency to Vietnamese format
     */
    function format_currency_vi($amount): string
    {
        return VietnameseHelper::formatCurrency($amount);
    }
}

if (!function_exists('order_status_text')) {
    /**
     * Get Vietnamese order status text
     */
    function order_status_text(string $status): string
    {
        return VietnameseHelper::getOrderStatusText($status);
    }
}

if (!function_exists('role_text')) {
    /**
     * Get Vietnamese role text
     */
    function role_text(string $role): string
    {
        return VietnameseHelper::getRoleText($role);
    }
}
