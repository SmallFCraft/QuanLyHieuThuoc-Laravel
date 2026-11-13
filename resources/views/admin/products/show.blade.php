@extends('layouts.admin')

@section('title', 'Chi tiết sản phẩm')
@section('page-title', 'Chi tiết sản phẩm')
@section('page-subtitle', $sanPham->ten)

@section('content')
<div class="container-fluid px-0">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('admin.bang-dieu-khien') }}">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="{{ route('admin.san-pham.index') }}">Sản phẩm</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $sanPham->display_code }}</li>
        </ol>
    </nav>

    <div class="row g-3 g-xl-4">
        <div class="col-lg-4">
            <section class="admin-card h-100">
                @if($sanPham->image_url)
                    <img src="{{ $sanPham->image_url }}" alt="Ảnh {{ $sanPham->ten }}" class="admin-product-media">
                @else
                    <div class="admin-product-placeholder">
                        <div class="text-center">
                            <i class="bi bi-capsule fs-1 d-block mb-2" aria-hidden="true"></i>
                            <span>Chưa có ảnh sản phẩm</span>
                        </div>
                    </div>
                @endif

                <div class="mt-3">
                    <h2 class="h5 fw-bold mb-1">{{ $sanPham->ten }}</h2>
                    <div class="text-secondary small">Mã sản phẩm {{ $sanPham->display_code }}</div>
                </div>

                <div class="d-grid gap-2 mt-4">
                    <a href="{{ route('admin.san-pham.edit', $sanPham->ma_sp) }}" class="btn btn-warning">
                        <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Chỉnh sửa
                    </a>
                    <a href="{{ route('admin.san-pham.index') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Quay lại danh sách
                    </a>
                </div>
            </section>
        </div>

        <div class="col-lg-8">
            <div class="row g-3 mb-3">
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card">
                        <p class="stat-label">Giá bán</p>
                        <div class="stat-value">{{ $sanPham->formatted_price }}</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card {{ $sanPham->isLowStock() ? 'expiring' : 'revenue' }}">
                        <p class="stat-label">Tồn kho</p>
                        <div class="stat-value">{{ $sanPham->so_luong_ton }}</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card orders">
                        <p class="stat-label">Ngưỡng cảnh báo</p>
                        <div class="stat-value">{{ $sanPham->muc_canh_bao }}</div>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="stat-card {{ $sanPham->isExpired() ? 'expiring' : ($sanPham->isExpiringSoon(30) ? 'orders' : 'lowstock') }}">
                        <p class="stat-label">Hạn dùng</p>
                        <div class="stat-value" style="font-size:1.1rem;">{{ $sanPham->formatted_expiration_date }}</div>
                    </div>
                </div>
            </div>

            <section class="admin-card mb-3">
                <div class="admin-card__header">
                    <div>
                        <h2 class="admin-card__title">Thông tin vận hành</h2>
                        <p class="admin-card__description">Danh mục, lô hàng và trạng thái dùng trong quản lý kho.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="text-secondary small">Danh mục</div>
                            <div class="fw-semibold">{{ $sanPham->danhMuc->ten ?? 'Chưa phân loại' }}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="text-secondary small">Số lô</div>
                            <div class="fw-semibold">{{ $sanPham->so_lo }}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="text-secondary small">Trạng thái tồn kho</div>
                            @if($sanPham->isLowStock())
                                <span class="badge text-bg-danger">Tồn kho thấp</span>
                            @else
                                <span class="badge text-bg-success">Bình thường</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="text-secondary small">Trạng thái hạn dùng</div>
                            @if($sanPham->isExpired())
                                <span class="badge text-bg-danger">Đã hết hạn</span>
                            @elseif($sanPham->isExpiringSoon(30))
                                <span class="badge text-bg-warning">Sắp hết hạn</span>
                            @else
                                <span class="badge text-bg-success">Còn hạn</span>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="admin-card">
                <div class="admin-card__header">
                    <div>
                        <h2 class="admin-card__title">Mô tả chi tiết</h2>
                        <p class="admin-card__description">Thông tin hiển thị cho nhân viên khi kiểm tra sản phẩm.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <h3 class="h6 fw-bold">Thành phần</h3>
                        <div class="text-secondary">{!! $sanPham->thanh_phan ?: '<em>Chưa có thông tin.</em>' !!}</div>
                    </div>
                    <div class="col-md-6">
                        <h3 class="h6 fw-bold">Hướng dẫn sử dụng</h3>
                        <div class="text-secondary">{!! $sanPham->cach_dung ?: '<em>Chưa có thông tin.</em>' !!}</div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
