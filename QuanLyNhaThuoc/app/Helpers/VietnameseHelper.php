<?php

namespace App\Helpers;

use Carbon\Carbon;

class VietnameseHelper
{
    /**
     * Format date to Vietnamese format (dd/MM/yyyy)
     *
     * @param string|Carbon $date
     * @return string
     */
    public static function formatDate($date): string
    {
        if (!$date) {
            return '';
        }

        if (!$date instanceof Carbon) {
            $date = Carbon::parse($date);
        }

        return $date->format('d/m/Y');
    }

    /**
     * Format datetime to Vietnamese format (dd/MM/yyyy HH:mm)
     *
     * @param string|Carbon $datetime
     * @return string
     */
    public static function formatDateTime($datetime): string
    {
        if (!$datetime) {
            return '';
        }

        if (!$datetime instanceof Carbon) {
            $datetime = Carbon::parse($datetime);
        }

        return $datetime->format('d/m/Y H:i');
    }

    /**
     * Format currency to Vietnamese format (123.456 ₫)
     *
     * @param float|int $amount
     * @return string
     */
    public static function formatCurrency($amount): string
    {
        if ($amount === null) {
            return '0 ₫';
        }

        return number_format($amount, 0, ',', '.') . ' ₫';
    }

    /**
     * Format phone number to Vietnamese format ((+84) xxx-xxx-xxxx)
     *
     * @param string $phone
     * @return string
     */
    public static function formatPhone(string $phone): string
    {
        if (!$phone) {
            return '';
        }

        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Convert 0 prefix to +84
        if (str_starts_with($phone, '0')) {
            $phone = '84' . substr($phone, 1);
        }

        // Format: (+84) xxx-xxx-xxxx
        if (strlen($phone) === 11 && str_starts_with($phone, '84')) {
            return '(+84) ' . substr($phone, 2, 3) . '-' . substr($phone, 5, 3) . '-' . substr($phone, 8);
        }

        // Return original if format doesn't match
        return $phone;
    }

    /**
     * Validate Vietnamese phone number format
     *
     * @param string $phone
     * @return bool
     */
    public static function isValidPhone(string $phone): bool
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Check if starts with 0 and has 10 digits, or starts with 84 and has 11 digits
        return (preg_match('/^0[0-9]{9}$/', $phone) || preg_match('/^84[0-9]{9}$/', $phone));
    }

    /**
     * Get Vietnamese order status text
     *
     * @param string $status
     * @return string
     */
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

    /**
     * Get Vietnamese role text
     *
     * @param string $role
     * @return string
     */
    public static function getRoleText(string $role): string
    {
        $roles = [
            'Administrator' => 'Quản trị viên',
            'User' => 'Người dùng',
        ];

        return $roles[$role] ?? $role;
    }

    /**
     * Get Vietnamese status text
     *
     * @param string $status
     * @return string
     */
    public static function getStatusText(string $status): string
    {
        $statuses = [
            'active' => 'Hoạt động',
            'inactive' => 'Không hoạt động',
            'new' => 'Mới',
            'read' => 'Đã đọc',
            'responded' => 'Đã phản hồi',
        ];

        return $statuses[$status] ?? $status;
    }

    /**
     * Format number with thousand separators (Vietnamese style)
     *
     * @param float|int $number
     * @param int $decimals
     * @return string
     */
    public static function formatNumber($number, int $decimals = 0): string
    {
        if ($number === null) {
            return '0';
        }

        return number_format($number, $decimals, ',', '.');
    }

    /**
     * Get Vietnamese month name
     *
     * @param int $month
     * @return string
     */
    public static function getMonthName(int $month): string
    {
        $months = [
            1 => 'Tháng 1',
            2 => 'Tháng 2',
            3 => 'Tháng 3',
            4 => 'Tháng 4',
            5 => 'Tháng 5',
            6 => 'Tháng 6',
            7 => 'Tháng 7',
            8 => 'Tháng 8',
            9 => 'Tháng 9',
            10 => 'Tháng 10',
            11 => 'Tháng 11',
            12 => 'Tháng 12',
        ];

        return $months[$month] ?? '';
    }

    /**
     * Get Vietnamese day name
     *
     * @param int $day (0 = Sunday, 6 = Saturday)
     * @return string
     */
    public static function getDayName(int $day): string
    {
        $days = [
            0 => 'Chủ nhật',
            1 => 'Thứ hai',
            2 => 'Thứ ba',
            3 => 'Thứ tư',
            4 => 'Thứ năm',
            5 => 'Thứ sáu',
            6 => 'Thứ bảy',
        ];

        return $days[$day] ?? '';
    }

    /**
     * Parse Vietnamese date format to Carbon instance
     *
     * @param string $date (dd/MM/yyyy)
     * @return Carbon|null
     */
    public static function parseDate(string $date): ?Carbon
    {
        try {
            return Carbon::createFromFormat('d/m/Y', $date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get relative time in Vietnamese
     *
     * @param string|Carbon $datetime
     * @return string
     */
    public static function getRelativeTime($datetime): string
    {
        if (!$datetime instanceof Carbon) {
            $datetime = Carbon::parse($datetime);
        }

        $now = Carbon::now();
        $diff = $datetime->diffInSeconds($now);

        if ($diff < 60) {
            return 'Vừa xong';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' phút trước';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' giờ trước';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' ngày trước';
        } elseif ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . ' tuần trước';
        } elseif ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return $months . ' tháng trước';
        } else {
            $years = floor($diff / 31536000);
            return $years . ' năm trước';
        }
    }
}
