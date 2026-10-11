<!DOCTYPE html>
<html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Trang đăng nhập, đăng ký, quên mật khẩu: không có gì để công cụ thu thập. --}}
        <meta name="robots" content="noindex, nofollow">

        <title>{{ config('app.name', 'Nhà Thuốc') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
        <!-- Bootstrap Icons -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo-icon.svg') }}?v={{ filemtime(public_path('images/logo-icon.svg')) }}">
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v={{ filemtime(public_path('favicon.ico')) }}">

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            body { font-family: 'Inter', sans-serif; }
        </style>
    </head>
    <body style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 30%, #f8fafc 60%, #ecfdf5 100%); background-attachment: fixed;">
        <div class="min-vh-100 d-flex flex-column align-items-center justify-content-center px-3 py-5">
            <!-- Logo -->
            <a href="{{ route('home') }}" class="text-decoration-none d-flex align-items-center gap-2 mb-4">
                <img src="{{ asset('images/logo-icon.svg') }}" alt="" width="48" height="48" style="height: 48px; width: auto;">
                <span class="fw-bold fs-4 bg-clip-text text-transparent bg-gradient-to-r from-emerald-900 to-emerald-600">{{ __('messages.app_name') }}</span>
            </a>

            <!-- Card -->
            <div class="w-100" style="max-width: 440px;">
                <div class="glass-card rounded-4 border-0 p-4 p-md-5 shadow-lg" style="background: rgba(255, 255, 255, 0.75); backdrop-filter: blur(20px);">
                    {{ $slot }}
                </div>
            </div>

            <!-- Footer -->
            <p class="text-gray-400 small mt-4">&copy; {{ date('Y') }} {{ __('messages.app_name') }}</p>
        </div>
    </body>
</html>
