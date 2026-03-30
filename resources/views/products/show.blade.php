@extends('layouts.app')

@section('title', $product->name . ' - Nhà Thuốc Sinh Thái')

@section('content')
<!-- Breadcrumb -->
<section class="py-3" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 50%, #f8fafc 100%);">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none">Trang chủ</a></li>
                <li class="breadcrumb-item"><a href="{{ route('products.index') }}" class="text-emerald-600 text-decoration-none">Sản phẩm</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $product->name }}</li>
            </ol>
        </nav>
    </div>
</section>

<section class="py-5">
    <div class="container">
        <div class="row g-5">
            <!-- Product Image -->
            <div class="col-lg-5">
                <div class="glass-card rounded-4 p-4 border-0 position-sticky" style="top: 100px;">
                    <div class="bg-gray-50 rounded-4 overflow-hidden" style="aspect-ratio: 1/1;">
                        @if($product->image)
                            <img src="{{ asset('storage/' . $product->image) }}" 
                                 alt="{{ $product->name }}" 
                                 class="w-100 h-100 object-fit-contain p-3">
                        @else
                            <div class="w-100 h-100 d-flex align-items-center justify-content-center">
                                <i class="bi bi-capsule text-gray-300" style="font-size: 6rem;"></i>
                            </div>
                        @endif
                    </div>
                    
                    <!-- Status Badge -->
                    <div class="mt-3 d-flex gap-2">
                        @if($product->stock_quantity > 0)
                            <span class="badge bg-emerald-100 text-emerald-800 rounded-pill px-3 py-2">
                                <i class="bi bi-check-circle me-1"></i>Còn hàng
                            </span>
                        @else
                            <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2">
                                <i class="bi bi-x-circle me-1"></i>Hết hàng
                            </span>
                        @endif
                        <span class="badge bg-blue-100 text-blue-800 rounded-pill px-3 py-2">
                            <i class="bi bi-tag me-1"></i>{{ $product->category->name }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Product Details -->
            <div class="col-lg-7">
                <h1 class="fw-bold text-gray-900 mb-3 display-6">{{ $product->name }}</h1>
                
                <!-- Price -->
                <div class="d-flex align-items-baseline gap-3 mb-4">
                    <span class="display-5 fw-bold text-emerald-700">{{ $product->formatted_price }}</span>
                    <span class="text-muted text-decoration-line-through fs-4">{{ number_format($product->price * 1.1, 0, ',', '.') }} ₫</span>
                    <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2 fw-medium">-10%</span>
                </div>

                <!-- Info Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background: rgba(16,185,129,0.08);">
                            <i class="bi bi-shield-check text-emerald-600 fs-4 d-block mb-1"></i>
                            <small class="text-gray-600 fw-medium">Chính hãng</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background: rgba(59,130,246,0.08);">
                            <i class="bi bi-truck text-blue-600 fs-4 d-block mb-1"></i>
                            <small class="text-gray-600 fw-medium">Giao nhanh</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background: rgba(245,158,11,0.08);">
                            <i class="bi bi-arrow-repeat text-warning fs-4 d-block mb-1"></i>
                            <small class="text-gray-600 fw-medium">Đổi trả 30 ngày</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="rounded-3 p-3 text-center" style="background: rgba(139,92,246,0.08);">
                            <i class="bi bi-headset text-purple-600 fs-4 d-block mb-1"></i>
                            <small class="text-gray-600 fw-medium">Tư vấn 24/7</small>
                        </div>
                    </div>
                </div>

                <!-- Add to Cart -->
                @if($product->stock_quantity > 0)
                    <form action="{{ route('cart.add') }}" method="POST" class="glass-card rounded-4 p-4 border-0 mb-4 js-add-to-cart-form">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <label class="fw-medium text-gray-700">Số lượng:</label>
                            <div class="input-group" style="width: 140px;">
                                <button type="button" class="btn btn-outline-secondary rounded-start-pill" onclick="changeQty(-1)">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <input type="number" name="quantity" id="qty-input" value="1" min="1" max="{{ $product->stock_quantity }}"
                                       class="form-control text-center border-secondary shadow-none">
                                <button type="button" class="btn btn-outline-secondary rounded-end-pill" onclick="changeQty(1)">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                            <small class="text-muted">Còn {{ $product->stock_quantity }} sản phẩm</small>
                        </div>
                        <div class="d-flex gap-3 mt-4">
                            <button type="submit" class="btn btn-lg flex-grow-1 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <i class="bi bi-cart-plus me-2"></i>Thêm vào giỏ hàng
                            </button>
                        </div>
                    </form>
                @else
                    <div class="glass-card rounded-4 p-4 border-0 mb-4 text-center">
                        <i class="bi bi-box-seam text-danger fs-1 d-block mb-2"></i>
                        <p class="fw-bold text-danger mb-1">Sản phẩm đã hết hàng</p>
                        <p class="text-muted small mb-0">Vui lòng liên hệ để đặt trước hoặc quay lại sau.</p>
                    </div>
                @endif

                <!-- Product Info Tabs -->
                <div class="glass-card rounded-4 border-0 p-0 overflow-hidden">
                    <ul class="nav nav-tabs border-0 px-4 pt-3" id="productTab" role="tablist">
                        @if($product->ingredients)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active border-0 fw-medium text-gray-600 px-3 pb-3" id="ingredients-tab" data-bs-toggle="tab" data-bs-target="#ingredients" type="button" role="tab" style="border-bottom: 3px solid transparent !important;">
                                <i class="bi bi-list-check me-1"></i>Thành phần
                            </button>
                        </li>
                        @endif
                        @if($product->usage)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link {{ !$product->ingredients ? 'active' : '' }} border-0 fw-medium text-gray-600 px-3 pb-3" id="usage-tab" data-bs-toggle="tab" data-bs-target="#usage" type="button" role="tab" style="border-bottom: 3px solid transparent !important;">
                                <i class="bi bi-capsule me-1"></i>Công dụng
                            </button>
                        </li>
                        @endif
                        <li class="nav-item" role="presentation">
                            <button class="nav-link {{ !$product->ingredients && !$product->usage ? 'active' : '' }} border-0 fw-medium text-gray-600 px-3 pb-3" id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab" style="border-bottom: 3px solid transparent !important;">
                                <i class="bi bi-info-circle me-1"></i>Chi tiết
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content p-4" id="productTabContent">
                        @if($product->ingredients)
                        <div class="tab-pane fade show active" id="ingredients" role="tabpanel">
                            <p class="text-gray-600 lh-lg mb-0">{{ $product->ingredients }}</p>
                        </div>
                        @endif
                        @if($product->usage)
                        <div class="tab-pane fade {{ !$product->ingredients ? 'show active' : '' }}" id="usage" role="tabpanel">
                            <p class="text-gray-600 lh-lg mb-0">{{ $product->usage }}</p>
                        </div>
                        @endif
                        <div class="tab-pane fade {{ !$product->ingredients && !$product->usage ? 'show active' : '' }}" id="details" role="tabpanel">
                            <table class="table table-borderless mb-0">
                                <tr>
                                    <td class="text-gray-500 ps-0" style="width: 160px;"><i class="bi bi-calendar-event me-2"></i>Hạn sử dụng</td>
                                    <td class="fw-medium">{{ $product->formatted_expiration_date }}</td>
                                </tr>
                                <tr>
                                    <td class="text-gray-500 ps-0"><i class="bi bi-box me-2"></i>Tồn kho</td>
                                    <td class="fw-medium">{{ $product->stock_quantity }} sản phẩm</td>
                                </tr>
                                <tr>
                                    <td class="text-gray-500 ps-0"><i class="bi bi-bookmark me-2"></i>Danh mục</td>
                                    <td class="fw-medium">{{ $product->category->name }}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Back Link -->
        <div class="mt-5 pt-3">
            <a href="{{ route('products.index') }}" class="btn btn-outline-secondary rounded-pill px-4">
                <i class="bi bi-arrow-left me-2"></i>Quay lại danh sách sản phẩm
            </a>
        </div>
    </div>
</section>

@push('scripts')
<script>
function changeQty(delta) {
    const input = document.getElementById('qty-input');
    let val = parseInt(input.value) + delta;
    val = Math.max(parseInt(input.min), Math.min(parseInt(input.max), val));
    input.value = val;
}
</script>
@endpush

<style>
.nav-tabs .nav-link.active { color: #059669 !important; border-bottom: 3px solid #059669 !important; }
.nav-tabs .nav-link:hover { color: #059669; }
</style>
@endsection
