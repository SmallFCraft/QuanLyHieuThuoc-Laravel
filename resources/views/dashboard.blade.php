@extends('layouts.app')

@section('title', 'Tài khoản của tôi - Nhà Thuốc Sinh Thái')

@section('content')
<section class="py-4" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 50%, #f8fafc 100%);">
    <div class="container">
        <h1 class="fw-bold text-gray-900 mb-1">Tài khoản của tôi</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none">Trang chủ</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tài khoản</li>
            </ol>
        </nav>
    </div>
</section>

<section class="py-5">
    <div class="container" style="max-width: 900px;">
        <div class="glass-card rounded-4 border-0 p-4 p-md-5 text-center">
            <div class="bg-emerald-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-4 shadow-sm" style="width: 80px; height: 80px;">
                <i class="bi bi-person-check text-emerald-600" style="font-size: 2rem;"></i>
            </div>
            <h3 class="fw-bold text-gray-800 mb-2">Xin chào, {{ auth()->user()->name }}!</h3>
            <p class="text-gray-500 mb-4">Bạn đã đăng nhập thành công. Hãy bắt đầu mua sắm!</p>
            <div class="d-flex flex-wrap justify-content-center gap-3">
                <a href="{{ route('products.index') }}" class="btn text-white rounded-pill px-4 py-2 fw-medium shadow" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="bi bi-grid me-2"></i>Xem sản phẩm
                </a>
                <a href="{{ route('cart.index') }}" class="btn btn-outline-secondary rounded-pill px-4 py-2 fw-medium">
                    <i class="bi bi-cart3 me-2"></i>Giỏ hàng
                </a>
                <a href="{{ route('profile.edit') }}" class="btn btn-outline-secondary rounded-pill px-4 py-2 fw-medium">
                    <i class="bi bi-person-gear me-2"></i>Hồ sơ
                </a>
            </div>
        </div>
    </div>
</section>
@endsection
