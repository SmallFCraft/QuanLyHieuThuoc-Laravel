<?php

use App\Helpers\VietnameseHelper;

if (!function_exists('format_currency_vi')) {
    /**
     * Dinh dang tien te theo dinh dang tieng Viet
     */
    function format_currency_vi($amount): string
    {
        return VietnameseHelper::formatCurrency($amount);
    }
}

if (!function_exists('order_status_text')) {
    /**
     * Lay van ban trang thai don hang tieng Viet
     */
    function order_status_text(string $status): string
    {
        return VietnameseHelper::getOrderStatusText($status);
    }
}

if (!function_exists('role_text')) {
    /**
     * Lay van ban vai tro tieng Viet
     */
    function role_text(string $role): string
    {
        return VietnameseHelper::getRoleText($role);
    }
}
