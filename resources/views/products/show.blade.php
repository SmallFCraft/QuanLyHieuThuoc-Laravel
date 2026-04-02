@extends('layouts.app')

@section('title', $product->name . ' - Nhà Thuốc Sinh Thái')

@section('content')
<!-- Breadcrumb -->
<section class="py-3" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 50%, #f8fafc 100%);">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none hover:text-emerald-700">Trang chủ</a></li>
                <li class="breadcrumb-item"><a href="{{ route('products.index') }}" class="text-emerald-600 text-decoration-none hover:text-emerald-700">Sản phẩm</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $product->name }}</li>
            </ol>
        </nav>
    </div>
</section>

<section class="py-4 py-lg-5">
    <div class="container">
        <div class="row g-4 g-lg-5">
            <!-- Product Image Gallery -->
            <div class="col-lg-5">
                <div class="position-sticky" style="top: 100px;">
                    <!-- Main Image -->
                    <div class="glass-card rounded-4 p-3 p-md-4 border-0 mb-3">
                        <div class="product-image-main bg-white rounded-3 overflow-hidden position-relative" style="aspect-ratio: 1/1;">
                            @if($product->image)
                                <img src="{{ asset('storage/' . $product->image) }}" 
                                     alt="{{ $product->name }}" 
                                     class="w-100 h-100 object-fit-contain p-2 product-main-img"
                                     id="mainProductImage">
                                <button class="btn btn-light btn-sm rounded-circle position-absolute top-0 end-0 m-3 shadow-sm" 
                                        onclick="toggleImageZoom()" 
                                        title="Phóng to ảnh">
                                    <i class="bi bi-zoom-in"></i>
                                </button>
                            @else
                                <div class="w-100 h-100 d-flex align-items-center justify-content-center bg-light">
                                    <i class="bi bi-capsule text-gray-300" style="font-size: 4rem;"></i>
                                </div>
                            @endif
                        </div>
                    </div>
                    
                    <!-- Status Badges -->
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @if($product->stock_quantity > 0)
                            <span class="badge-glass-success rounded-pill px-3 py-2 d-inline-flex align-items-center">
                                <i class="bi bi-check-circle me-1" style="font-size: 0.9rem;"></i>
                                <span class="fw-medium">Còn hàng</span>
                            </span>
                        @else
                            <span class="badge-glass-danger rounded-pill px-3 py-2 d-inline-flex align-items-center">
                                <i class="bi bi-x-circle me-1" style="font-size: 0.9rem;"></i>
                                <span class="fw-medium">Hết hàng</span>
                            </span>
                        @endif
                        <span class="badge-glass-info rounded-pill px-3 py-2 d-inline-flex align-items-center">
                            <i class="bi bi-tag me-1" style="font-size: 0.9rem;"></i>
                            <span class="fw-medium">{{ $product->category->name }}</span>
                        </span>
                    </div>

                    <!-- Quick Info Cards - Desktop Only -->
                    <div class="row g-2 d-none d-lg-flex">
                        <div class="col-6">
                            <div class="glass-card-sm rounded-3 text-center p-3 h-100">
                                <i class="bi bi-shield-check text-emerald-600 d-block mb-2" style="font-size: 1.5rem;"></i>
                                <small class="text-gray-700 fw-medium d-block">Chính hãng</small>
                                <small class="text-gray-500" style="font-size: 0.75rem;">100% authentic</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="glass-card-sm rounded-3 text-center p-3 h-100">
                                <i class="bi bi-truck text-blue-600 d-block mb-2" style="font-size: 1.5rem;"></i>
                                <small class="text-gray-700 fw-medium d-block">Giao nhanh</small>
                                <small class="text-gray-500" style="font-size: 0.75rem;">2-3 ngày</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="glass-card-sm rounded-3 text-center p-3 h-100">
                                <i class="bi bi-arrow-repeat text-warning d-block mb-2" style="font-size: 1.5rem;"></i>
                                <small class="text-gray-700 fw-medium d-block">Đổi trả</small>
                                <small class="text-gray-500" style="font-size: 0.75rem;">Trong 30 ngày</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="glass-card-sm rounded-3 text-center p-3 h-100">
                                <i class="bi bi-headset text-purple-600 d-block mb-2" style="font-size: 1.5rem;"></i>
                                <small class="text-gray-700 fw-medium d-block">Hỗ trợ</small>
                                <small class="text-gray-500" style="font-size: 0.75rem;">24/7</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Details -->
            <div class="col-lg-7">
                <h1 class="fw-bold text-gray-900 mb-3" style="font-size: clamp(1.5rem, 4vw, 2.5rem); line-height: 1.2;">
                    {{ $product->name }}
                </h1>
                
                <!-- Rating & Reviews -->
                <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
                    <div class="d-flex align-items-center">
                        <div class="text-warning me-2">
                            <i class="bi bi-star-fill"></i>
                            <i class="bi bi-star-fill"></i>
                            <i class="bi bi-star-fill"></i>
                            <i class="bi bi-star-fill"></i>
                            <i class="bi bi-star-half"></i>
                        </div>
                        <span class="text-gray-600 small">4.5/5</span>
                    </div>
                    <span class="text-gray-400">|</span>
                    <a href="#reviews" class="text-emerald-600 text-decoration-none small hover:text-emerald-700">
                        <i class="bi bi-chat-dots me-1"></i>128 đánh giá
                    </a>
                    <span class="text-gray-400">|</span>
                    <span class="text-gray-600 small">
                        <i class="bi bi-eye me-1"></i>{{ number_format($product->views_count, 0, ',', '.') }} lượt xem
                    </span>
                </div>
                
                <!-- Price -->
                <div class="glass-card rounded-4 p-3 p-md-4 border-0 mb-4">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <span class="fw-bold text-emerald-700" style="font-size: clamp(1.75rem, 5vw, 2.5rem);">
                            {{ $product->formatted_price }}
                        </span>
                        <span class="text-muted text-decoration-line-through fs-5">
                            {{ number_format($product->price * 1.1, 0, ',', '.') }} ₫
                        </span>
                        <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2 fw-medium">
                            <i class="bi bi-tag-fill me-1"></i>-10%
                        </span>
                    </div>
                    <p class="text-gray-600 small mb-0 mt-2">
                        <i class="bi bi-info-circle me-1"></i>Giá đã bao gồm VAT
                    </p>
                </div>

                <!-- Quick Info - Mobile Only -->
                <div class="row g-2 mb-4 d-lg-none">
                    <div class="col-6">
                        <div class="glass-card-sm rounded-3 text-center p-2">
                            <i class="bi bi-shield-check text-emerald-600 d-block mb-1" style="font-size: 1.25rem;"></i>
                            <small class="text-gray-700 fw-medium d-block" style="font-size: 0.8rem;">Chính hãng</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="glass-card-sm rounded-3 text-center p-2">
                            <i class="bi bi-truck text-blue-600 d-block mb-1" style="font-size: 1.25rem;"></i>
                            <small class="text-gray-700 fw-medium d-block" style="font-size: 0.8rem;">Giao nhanh</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="glass-card-sm rounded-3 text-center p-2">
                            <i class="bi bi-arrow-repeat text-warning d-block mb-1" style="font-size: 1.25rem;"></i>
                            <small class="text-gray-700 fw-medium d-block" style="font-size: 0.8rem;">Đổi trả 30 ngày</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="glass-card-sm rounded-3 text-center p-2">
                            <i class="bi bi-headset text-purple-600 d-block mb-1" style="font-size: 1.25rem;"></i>
                            <small class="text-gray-700 fw-medium d-block" style="font-size: 0.8rem;">Hỗ trợ 24/7</small>
                        </div>
                    </div>
                </div>

                <!-- Product Specifications -->
                <div class="glass-card rounded-4 p-3 p-md-4 border-0 mb-4">
                    <h6 class="fw-bold text-gray-800 mb-3">
                        <i class="bi bi-info-circle-fill text-emerald-600 me-2"></i>Thông tin sản phẩm
                    </h6>
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-calendar-event text-emerald-600 me-2 mt-1"></i>
                                <div>
                                    <small class="text-gray-500 d-block" style="font-size: 0.75rem;">Hạn sử dụng</small>
                                    <span class="fw-medium text-gray-800 small">{{ $product->formatted_expiration_date }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-box text-blue-600 me-2 mt-1"></i>
                                <div>
                                    <small class="text-gray-500 d-block" style="font-size: 0.75rem;">Tồn kho</small>
                                    <span class="fw-medium text-gray-800 small">{{ $product->stock_quantity }} sản phẩm</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-bookmark text-purple-600 me-2 mt-1"></i>
                                <div>
                                    <small class="text-gray-500 d-block" style="font-size: 0.75rem;">Danh mục</small>
                                    <span class="fw-medium text-gray-800 small">{{ $product->category->name }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add to Cart -->
                @if($product->stock_quantity > 0)
                    <form action="{{ route('cart.add') }}" method="POST" class="glass-card rounded-4 p-3 p-md-4 border-0 mb-4 js-add-to-cart-form">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        
                        <div class="mb-3">
                            <label class="fw-medium text-gray-700 mb-2 d-block">Số lượng:</label>
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <div class="input-group" style="max-width: 150px;">
                                    <button type="button" class="btn btn-outline-emerald-600 rounded-start-pill px-3" onclick="changeQty(-1)">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <input type="number" name="quantity" id="qty-input" value="1" min="1" max="{{ $product->stock_quantity }}"
                                           class="form-control text-center border-emerald-200 shadow-none fw-medium">
                                    <button type="button" class="btn btn-outline-emerald-600 rounded-end-pill px-3" onclick="changeQty(1)">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                                <small class="text-gray-600">
                                    <i class="bi bi-box-seam me-1"></i>Còn <span class="fw-medium text-emerald-600">{{ $product->stock_quantity }}</span> sản phẩm
                                </small>
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2 gap-md-3">
                            <button type="submit" class="btn btn-lg flex-grow-1 text-white fw-bold rounded-pill shadow-sm hover-lift" 
                                    style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <i class="bi bi-cart-plus me-2"></i>
                                <span class="d-none d-sm-inline">Thêm vào giỏ hàng</span>
                                <span class="d-inline d-sm-none">Thêm vào giỏ</span>
                            </button>
                            <button type="button" class="btn btn-lg btn-outline-emerald-600 rounded-pill px-3 px-md-4" title="Yêu thích">
                                <i class="bi bi-heart"></i>
                            </button>
                        </div>
                    </form>
                @else
                    <div class="glass-card rounded-4 p-4 border-0 mb-4 text-center">
                        <i class="bi bi-box-seam text-danger d-block mb-3" style="font-size: 3rem;"></i>
                        <h6 class="fw-bold text-danger mb-2">Sản phẩm tạm hết hàng</h6>
                        <p class="text-gray-600 small mb-3">Vui lòng liên hệ để đặt trước hoặc quay lại sau.</p>
                        <button class="btn btn-outline-emerald-600 rounded-pill px-4">
                            <i class="bi bi-bell me-2"></i>Nhận thông báo khi có hàng
                        </button>
                    </div>
                @endif

                <!-- Product Info Tabs -->
                <div class="glass-card rounded-4 border-0 p-0 overflow-hidden">
                    <ul class="nav nav-tabs border-0 px-3 px-md-4 pt-3 gap-1" id="productTab" role="tablist">
                        @if($product->ingredients)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active border-0 fw-medium text-gray-600 px-3 pb-3 rounded-top" 
                                    id="ingredients-tab" data-bs-toggle="tab" data-bs-target="#ingredients" type="button" role="tab">
                                <i class="bi bi-list-check me-1"></i>
                                <span class="d-none d-sm-inline">Thành phần</span>
                            </button>
                        </li>
                        @endif
                        @if($product->usage)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link {{ !$product->ingredients ? 'active' : '' }} border-0 fw-medium text-gray-600 px-3 pb-3 rounded-top" 
                                    id="usage-tab" data-bs-toggle="tab" data-bs-target="#usage" type="button" role="tab">
                                <i class="bi bi-capsule me-1"></i>
                                <span class="d-none d-sm-inline">Công dụng</span>
                            </button>
                        </li>
                        @endif
                        <li class="nav-item" role="presentation">
                            <button class="nav-link {{ !$product->ingredients && !$product->usage ? 'active' : '' }} border-0 fw-medium text-gray-600 px-3 pb-3 rounded-top" 
                                    id="details-tab" data-bs-toggle="tab" data-bs-target="#details" type="button" role="tab">
                                <i class="bi bi-info-circle me-1"></i>
                                <span class="d-none d-sm-inline">Chi tiết</span>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content p-3 p-md-4" id="productTabContent">
                        @if($product->ingredients)
                        <div class="tab-pane fade show active" id="ingredients" role="tabpanel">
                            <p class="text-gray-700 lh-lg mb-0">{{ $product->ingredients }}</p>
                        </div>
                        @endif
                        @if($product->usage)
                        <div class="tab-pane fade {{ !$product->ingredients ? 'show active' : '' }}" id="usage" role="tabpanel">
                            <p class="text-gray-700 lh-lg mb-0">{{ $product->usage }}</p>
                        </div>
                        @endif
                        <div class="tab-pane fade {{ !$product->ingredients && !$product->usage ? 'show active' : '' }}" id="details" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="d-flex align-items-start p-3 rounded-3" style="background: rgba(16,185,129,0.05);">
                                        <i class="bi bi-calendar-event text-emerald-600 me-3 mt-1"></i>
                                        <div class="flex-grow-1">
                                            <small class="text-gray-500 d-block mb-1">Hạn sử dụng</small>
                                            <span class="fw-medium text-gray-800">{{ $product->formatted_expiration_date }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex align-items-start p-3 rounded-3" style="background: rgba(59,130,246,0.05);">
                                        <i class="bi bi-box text-blue-600 me-3 mt-1"></i>
                                        <div class="flex-grow-1">
                                            <small class="text-gray-500 d-block mb-1">Số lượng tồn kho</small>
                                            <span class="fw-medium text-gray-800">{{ $product->stock_quantity }} sản phẩm</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="d-flex align-items-start p-3 rounded-3" style="background: rgba(139,92,246,0.05);">
                                        <i class="bi bi-bookmark text-purple-600 me-3 mt-1"></i>
                                        <div class="flex-grow-1">
                                            <small class="text-gray-500 d-block mb-1">Danh mục sản phẩm</small>
                                            <span class="fw-medium text-gray-800">{{ $product->category->name }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Information Section -->
        <div class="row g-4 mt-4">
            <!-- Customer Reviews -->
            <div class="col-lg-8">
                <div class="glass-card rounded-4 p-4 border-0" id="reviews">
                    <h5 class="fw-bold text-gray-800 mb-4">
                        <i class="bi bi-chat-dots-fill text-emerald-600 me-2"></i>Đánh giá từ khách hàng
                    </h5>
                    
                    <!-- Rating Summary -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-4 text-center">
                            <div class="display-4 fw-bold text-emerald-600 mb-2">4.5</div>
                            <div class="text-warning mb-2">
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-fill"></i>
                                <i class="bi bi-star-half"></i>
                            </div>
                            <p class="text-gray-600 small mb-0">128 đánh giá</p>
                        </div>
                        <div class="col-md-8">
                            <div class="d-flex align-items-center mb-2">
                                <span class="text-gray-600 small me-2" style="width: 60px;">5 sao</span>
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar bg-emerald-600" style="width: 75%"></div>
                                </div>
                                <span class="text-gray-600 small ms-2">75%</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <span class="text-gray-600 small me-2" style="width: 60px;">4 sao</span>
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar bg-emerald-600" style="width: 15%"></div>
                                </div>
                                <span class="text-gray-600 small ms-2">15%</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <span class="text-gray-600 small me-2" style="width: 60px;">3 sao</span>
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar bg-emerald-600" style="width: 7%"></div>
                                </div>
                                <span class="text-gray-600 small ms-2">7%</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <span class="text-gray-600 small me-2" style="width: 60px;">2 sao</span>
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar bg-warning" style="width: 2%"></div>
                                </div>
                                <span class="text-gray-600 small ms-2">2%</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="text-gray-600 small me-2" style="width: 60px;">1 sao</span>
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar bg-danger" style="width: 1%"></div>
                                </div>
                                <span class="text-gray-600 small ms-2">1%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Sample Reviews -->
                    <div class="border-top pt-4">
                        <div class="mb-4 pb-4 border-bottom">
                            <div class="d-flex align-items-start gap-3 mb-2">
                                <div class="rounded-circle bg-emerald-100 d-flex align-items-center justify-content-center" 
                                     style="width: 40px; height: 40px; flex-shrink: 0;">
                                    <i class="bi bi-person-fill text-emerald-600"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <h6 class="fw-bold mb-0">Nguyễn Văn A</h6>
                                        <small class="text-gray-500">2 ngày trước</small>
                                    </div>
                                    <div class="text-warning small mb-2">
                                        <i class="bi bi-star-fill"></i>
                                        <i class="bi bi-star-fill"></i>
                                        <i class="bi bi-star-fill"></i>
                                        <i class="bi bi-star-fill"></i>
                                        <i class="bi bi-star-fill"></i>
                                    </div>
                                    <p class="text-gray-700 mb-0">Sản phẩm chất lượng, giao hàng nhanh. Đóng gói cẩn thận. Sẽ ủng hộ shop lâu dài!</p>
                                </div>
                            </div>
                        </div>
                        <div class="mb-4 pb-4 border-bottom">
                            <div class="d-flex align-items-start gap-3 mb-2">
                                <div class="rounded-circle bg-blue-100 d-flex align-items-center justify-content-center" 
                                     style="width: 40px; height: 40px; flex-shrink: 0;">
                                    <i class="bi bi-person-fill text-blue-600"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <h6 class="fw-bold mb-0">Trần Thị B</h6>
                                        <small class="text-gray-500">1 tuần trước</small>
                                    </div>
                                    <div class="text-warning small mb-2">
                                        <i class="bi bi-star-fill"></i>
                                        <i class="bi bi-star-fill"></i>
                                        <i class="bi bi-star-fill"></i>
                                        <i class="bi bi-star-fill"></i>
                                        <i class="bi bi-star"></i>
                                    </div>
                                    <p class="text-gray-700 mb-0">Thuốc hiệu quả, giá cả hợp lý. Nhân viên tư vấn nhiệt tình.</p>
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-outline-emerald-600 rounded-pill px-4 w-100">
                            <i class="bi bi-arrow-down-circle me-2"></i>Xem thêm đánh giá
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sidebar Info -->
            <div class="col-lg-4">
                <!-- Delivery Info -->
                <div class="glass-card rounded-4 p-4 border-0 mb-4">
                    <h6 class="fw-bold text-gray-800 mb-3">
                        <i class="bi bi-truck text-emerald-600 me-2"></i>Thông tin vận chuyển
                    </h6>
                    <div class="mb-3">
                        <div class="d-flex align-items-start mb-2">
                            <i class="bi bi-check-circle-fill text-emerald-600 me-2 mt-1"></i>
                            <small class="text-gray-700">Miễn phí vận chuyển cho đơn hàng từ 500.000₫</small>
                        </div>
                        <div class="d-flex align-items-start mb-2">
                            <i class="bi bi-check-circle-fill text-emerald-600 me-2 mt-1"></i>
                            <small class="text-gray-700">Giao hàng trong 2-3 ngày làm việc</small>
                        </div>
                        <div class="d-flex align-items-start">
                            <i class="bi bi-check-circle-fill text-emerald-600 me-2 mt-1"></i>
                            <small class="text-gray-700">Kiểm tra hàng trước khi thanh toán</small>
                        </div>
                    </div>
                </div>

                <!-- Return Policy -->
                <div class="glass-card rounded-4 p-4 border-0">
                    <h6 class="fw-bold text-gray-800 mb-3">
                        <i class="bi bi-arrow-repeat text-warning me-2"></i>Chính sách đổi trả
                    </h6>
                    <div class="mb-3">
                        <div class="d-flex align-items-start mb-2">
                            <i class="bi bi-check-circle-fill text-warning me-2 mt-1"></i>
                            <small class="text-gray-700">Đổi trả trong vòng 30 ngày</small>
                        </div>
                        <div class="d-flex align-items-start mb-2">
                            <i class="bi bi-check-circle-fill text-warning me-2 mt-1"></i>
                            <small class="text-gray-700">Sản phẩm còn nguyên tem, chưa sử dụng</small>
                        </div>
                        <div class="d-flex align-items-start">
                            <i class="bi bi-check-circle-fill text-warning me-2 mt-1"></i>
                            <small class="text-gray-700">Hoàn tiền 100% nếu lỗi từ nhà sản xuất</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Back Link -->
        <div class="mt-4 pt-3">
            <a href="{{ route('products.index') }}" class="btn btn-outline-emerald-600 rounded-pill px-4">
                <i class="bi bi-arrow-left me-2"></i>Quay lại danh sách sản phẩm
            </a>
        </div>
    </div>
</section>

<!-- Sticky Add to Cart Bar (Mobile) -->
@if($product->stock_quantity > 0)
<div class="sticky-cart-bar d-lg-none glass-card border-top shadow-lg" style="position: fixed; bottom: 0; left: 0; right: 0; z-index: 1000; padding: 12px 16px;">
    <div class="d-flex align-items-center gap-3">
        <div class="flex-grow-1">
            <div class="fw-bold text-emerald-700" style="font-size: 1.25rem;">{{ $product->formatted_price }}</div>
            <small class="text-gray-600">Còn {{ $product->stock_quantity }} sản phẩm</small>
        </div>
        <button onclick="document.querySelector('.js-add-to-cart-form').scrollIntoView({behavior: 'smooth', block: 'center'})" 
                class="btn btn-emerald-600 text-white fw-bold rounded-pill px-4 shadow-sm">
            <i class="bi bi-cart-plus me-1"></i>Mua ngay
        </button>
    </div>
</div>
@endif

<!-- Image Zoom Modal -->
<div class="modal fade" id="imageZoomModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-body p-0 position-relative">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3 z-3" 
                        data-bs-dismiss="modal" aria-label="Close"></button>
                <img src="{{ $product->image ? asset('storage/' . $product->image) : '' }}" 
                     alt="{{ $product->name }}" 
                     class="w-100 rounded-4">
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function changeQty(delta) {
    const input = document.getElementById('qty-input');
    let val = parseInt(input.value) + delta;
    val = Math.max(parseInt(input.min), Math.min(parseInt(input.max), val));
    input.value = val;
}

function toggleImageZoom() {
    const modal = new bootstrap.Modal(document.getElementById('imageZoomModal'));
    modal.show();
}

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});
</script>
@endpush

<style>
/* Tab Styling */
.nav-tabs .nav-link {
    border-bottom: 3px solid transparent !important;
    transition: all 0.3s ease;
}

.nav-tabs .nav-link.active { 
    color: #059669 !important; 
    border-bottom: 3px solid #059669 !important;
    background: rgba(16, 185, 129, 0.05);
}

.nav-tabs .nav-link:hover { 
    color: #059669;
    background: rgba(16, 185, 129, 0.03);
}

/* Button Styling */
.btn-outline-emerald-600 {
    border-color: #059669;
    color: #059669;
}

.btn-outline-emerald-600:hover {
    background: #059669;
    border-color: #059669;
    color: white;
}

.btn-emerald-600 {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border: none;
}

.btn-emerald-600:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
}

/* Image Zoom Effect */
.product-main-img {
    transition: transform 0.3s ease;
    cursor: zoom-in;
}

.product-main-img:hover {
    transform: scale(1.05);
}

/* Sticky Cart Bar Animation */
.sticky-cart-bar {
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        transform: translateY(100%);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

/* Progress Bar */
.progress {
    background: rgba(0, 0, 0, 0.05);
    border-radius: 999px;
}

.progress-bar {
    border-radius: 999px;
}

.bg-emerald-600 {
    background-color: #059669 !important;
}

/* Responsive Adjustments */
@media (max-width: 991.98px) {
    .sticky-cart-bar {
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }
}

@media (max-width: 575.98px) {
    .nav-tabs .nav-link {
        font-size: 0.875rem;
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
    }
}

/* Hover Effects */
.hover-lift {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.hover-lift:hover {
    transform: translateY(-2px);
}

/* Badge Glass Variants */
.badge-glass-success {
    background: rgba(16, 185, 129, 0.2);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: #047857;
}

.badge-glass-danger {
    background: rgba(239, 68, 68, 0.2);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #dc2626;
}

.badge-glass-info {
    background: rgba(59, 130, 246, 0.2);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: #2563eb;
}

/* Input Group Styling */
.input-group .form-control:focus {
    border-color: #10b981;
    box-shadow: 0 0 0 0.2rem rgba(16, 185, 129, 0.15);
}

.border-emerald-200 {
    border-color: rgba(16, 185, 129, 0.3) !important;
}
</style>
@endsection
