<?php

use App\Helpers\VietnameseHelper;

if (!function_exists('format_date_vi')) {
    /**
     * Format date to Vietnamese format
     */
    function format_date_vi($date): string
    {
        return VietnameseHelper::formatDate($date);
    }
}

if (!function_exists('format_datetime_vi')) {
    /**
     * Format datetime to Vietnamese format
     */
    function format_datetime_vi($datetime): string
    {
        return VietnameseHelper::formatDateTime($datetime);
    }
}

if (!function_exists('format_currency_vi')) {
    /**
     * Format currency to Vietnamese format
     */
    function format_currency_vi($amount): string
    {
        return VietnameseHelper::formatCurrency($amount);
    }
}

if (!function_exists('format_phone_vi')) {
    /**
     * Format phone number to Vietnamese format
     */
    function format_phone_vi(string $phone): string
    {
        return VietnameseHelper::formatPhone($phone);
    }
}

if (!function_exists('is_valid_phone_vi')) {
    /**
     * Validate Vietnamese phone number
     */
    function is_valid_phone_vi(string $phone): bool
    {
        return VietnameseHelper::isValidPhone($phone);
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

if (!function_exists('status_text')) {
    /**
     * Get Vietnamese status text
     */
    function status_text(string $status): string
    {
        return VietnameseHelper::getStatusText($status);
    }
}

if (!function_exists('format_number_vi')) {
    /**
     * Format number with Vietnamese thousand separators
     */
    function format_number_vi($number, int $decimals = 0): string
    {
        return VietnameseHelper::formatNumber($number, $decimals);
    }
}

if (!function_exists('month_name_vi')) {
    /**
     * Get Vietnamese month name
     */
    function month_name_vi(int $month): string
    {
        return VietnameseHelper::getMonthName($month);
    }
}

if (!function_exists('day_name_vi')) {
    /**
     * Get Vietnamese day name
     */
    function day_name_vi(int $day): string
    {
        return VietnameseHelper::getDayName($day);
    }
}

if (!function_exists('parse_date_vi')) {
    /**
     * Parse Vietnamese date format to Carbon
     */
    function parse_date_vi(string $date): ?\Carbon\Carbon
    {
        return VietnameseHelper::parseDate($date);
    }
}

if (!function_exists('relative_time_vi')) {
    /**
     * Get relative time in Vietnamese
     */
    function relative_time_vi($datetime): string
    {
        return VietnameseHelper::getRelativeTime($datetime);
    }
}
