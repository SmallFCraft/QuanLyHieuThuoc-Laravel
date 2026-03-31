@extends('layouts.app')

@section('title', 'Giỏ hàng - Nhà Thuốc Sinh Thái')

@push('styles')
<style>
    .cart-item {
        transition: all 0.3s ease;
    }
    .cart-item.removing {
        opacity: 0;
        transform: translateX(-20px);
    }
    .quantity-input::-webkit-inner-spin-button,
    .quantity-input::-webkit-outer-spin-button {
        opacity: 1;
    }
    .loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
        border-radius: 1rem;
    }
</style>
@endpush

@section('content')
<!-- Page Header -->
<section class="py-4" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 50%, #f8fafc 100%);">
    <div class="container">
        <h1 class="fw-bold text-gray-900 mb-1">Giỏ Hàng</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none">Trang chủ</a></li>
                <li class="breadcrumb-item active" aria-current="page">Giỏ hàng</li>
            </ol>
        </nav>
    </div>
</section>

<section class="py-5">
    <div class="container">
        @if(empty($cartItems))
            <!-- Empty Cart -->
            <div class="glass-card rounded-4 border-0 text-center py-5 px-4">
                <div class="bg-emerald-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width:100px; height:100px;">
                    <i class="bi bi-cart-x text-emerald-500" style="font-size: 2.5rem;"></i>
                </div>
                <h3 class="fw-bold text-gray-800 mb-2">Giỏ hàng trống</h3>
                <p class="text-gray-500 mb-4">Bạn chưa thêm sản phẩm nào vào giỏ hàng.</p>
                <a href="{{ route('products.index') }}" class="btn btn-glass-success rounded-pill px-5 py-3 fw-medium shadow-sm border-0">
                    <i class="bi bi-cart-plus me-2"></i>Bắt đầu mua sắm
                </a>
            </div>
        @else
            <div class="row g-4">
                <!-- Cart Items -->
                <div class="col-lg-8">
                    <div class="glass-card rounded-4 border-0 p-0 overflow-hidden">
                        <div class="p-4 border-bottom" style="border-color: rgba(0,0,0,0.06) !important;">
                            <h5 class="fw-bold mb-0">
                                <i class="bi bi-cart3 text-emerald-600 me-2"></i>
                                Sản phẩm trong giỏ (<span id="cart-count">{{ count($cartItems) }}</span>)
                            </h5>
                        </div>
                        
                        <div id="cart-items-container">
                            @foreach($cartItems as $item)
                                <div class="cart-item d-flex align-items-center gap-3 p-4 border-bottom flex-wrap position-relative" 
                                     style="border-color: rgba(0,0,0,0.04) !important;" 
                                     data-product-id="{{ $item['product']->id }}"
                                     data-price="{{ $item['product']->price }}"
                                     data-max-stock="{{ $item['product']->stock_quantity }}">
                                    
                                    <!-- Image -->
                                    <div class="bg-gray-50 rounded-3 overflow-hidden flex-shrink-0" style="width: 90px; height: 90px;">
                                        @if($item['product']->image)
                                            <img src="{{ asset('storage/' . $item['product']->image) }}" 
                                                 alt="{{ $item['product']->name }}" 
                                                 class="w-100 h-100 object-fit-contain p-1">
                                        @else
                                            <div class="w-100 h-100 d-flex align-items-center justify-content-center">
                                                <i class="bi bi-capsule text-gray-300" style="font-size: 2.5rem;"></i>
                                            </div>
                                        @endif
                                    </div>
                                    
                                    <!-- Info -->
                                    <div class="flex-grow-1 min-w-0">
                                        <h6 class="fw-bold text-gray-800 mb-1 text-truncate">{{ $item['product']->name }}</h6>
                                        <p class="text-emerald-600 small mb-1 fw-medium">{{ $item['product']->category->name }}</p>
                                        <p class="text-gray-500 small mb-0">{{ $item['product']->formatted_price }} / sản phẩm</p>
                                    </div>
                                    
                                    <!-- Quantity Controls -->
                                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                        <div class="input-group" style="width: 140px;">
                                            <button class="btn btn-outline-secondary btn-sm quantity-decrease" type="button">
                                                <i class="bi bi-dash"></i>
                                            </button>
                                            <input type="number" 
                                                   class="form-control form-control-sm text-center quantity-input shadow-none border-gray-200" 
                                                   value="{{ $item['quantity'] }}" 
                                                   min="1" 
                                                   max="{{ $item['product']->stock_quantity }}"
                                                   style="font-size: 0.9rem;">
                                            <button class="btn btn-outline-secondary btn-sm quantity-increase" type="button">
                                                <i class="bi bi-plus"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Subtotal -->
                                    <div class="text-end flex-shrink-0" style="min-width: 120px;">
                                        <p class="fw-bold text-emerald-700 mb-0 item-subtotal">
                                            {{ number_format($item['subtotal'], 0, ',', '.') }} ₫
                                        </p>
                                    </div>

                                    <!-- Remove -->
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-circle remove-item flex-shrink-0" 
                                            style="width:36px; height:36px;" title="Xóa">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        
                        <div class="p-4">
                            <a href="{{ route('products.index') }}" class="text-emerald-600 text-decoration-none fw-medium">
                                <i class="bi bi-arrow-left me-2"></i>Tiếp tục mua sắm
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="col-lg-4">
                    <div class="glass-card rounded-4 border-0 position-sticky" style="top: 100px;">
                        <h5 class="fw-bold mb-4">
                            <i class="bi bi-receipt text-emerald-600 me-2"></i>Tóm tắt đơn hàng
                        </h5>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-gray-500">Tạm tính</span>
                            <span class="fw-medium" id="subtotal-amount">{{ number_format($total, 0, ',', '.') }} ₫</span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-gray-500">Phí vận chuyển</span>
                            <span class="text-emerald-600 fw-medium">Miễn phí</span>
                        </div>
                        
                        <hr class="my-3" style="border-color: rgba(0,0,0,0.06);">
                        
                        <div class="d-flex justify-content-between mb-4">
                            <span class="fw-bold fs-5">Tổng cộng</span>
                            <span class="fw-bold fs-4 text-emerald-700" id="total-amount">{{ number_format($total, 0, ',', '.') }} ₫</span>
                        </div>
                        
                        @auth
                            <a href="{{ route('checkout.index') }}" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <i class="bi bi-credit-card me-2"></i>Thanh toán
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Đăng nhập để thanh toán
                            </a>
                        @endauth
                        
                        <div class="text-center mt-4">
                            <div class="d-flex justify-content-center gap-3 text-gray-400 small">
                                <span><i class="bi bi-shield-check me-1"></i>An toàn</span>
                                <span><i class="bi bi-lock me-1"></i>Bảo mật</span>
                                <span><i class="bi bi-truck me-1"></i>Nhanh chóng</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</section>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Format number to VND
    function formatVND(number) {
        return new Intl.NumberFormat('vi-VN').format(number) + ' ₫';
    }
    
    // Calculate totals
    function calculateTotals() {
        let subtotal = 0;
        
        document.querySelectorAll('.cart-item').forEach(item => {
            const price = parseFloat(item.dataset.price);
            const quantity = parseInt(item.querySelector('.quantity-input').value);
            const itemSubtotal = price * quantity;
            
            // Update item subtotal
            item.querySelector('.item-subtotal').textContent = formatVND(itemSubtotal);
            subtotal += itemSubtotal;
        });
        
        // Update subtotal and total
        document.getElementById('subtotal-amount').textContent = formatVND(subtotal);
        document.getElementById('total-amount').textContent = formatVND(subtotal);
        
        const lineCount = document.querySelectorAll('.cart-item').length;
        const cartCountEl = document.getElementById('cart-count');
        if (cartCountEl) {
            cartCountEl.textContent = lineCount;
        }

        if (typeof window.cartNavbarSetCount === 'function') {
            window.cartNavbarSetCount(lineCount);
        }
    }
    
    // Update quantity via AJAX
    function updateQuantity(productId, quantity, itemElement) {
        const maxStock = parseInt(itemElement.dataset.maxStock);
        
        if (quantity < 1) {
            quantity = 1;
        }
        if (quantity > maxStock) {
            Swal.fire({
                icon: 'warning',
                title: 'Vượt quá tồn kho',
                text: `Chỉ còn ${maxStock} sản phẩm trong kho`,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
            quantity = maxStock;
        }
        
        // Show loading
        const loadingOverlay = document.createElement('div');
        loadingOverlay.className = 'loading-overlay';
        loadingOverlay.innerHTML = '<div class="spinner-border text-emerald-600" role="status"></div>';
        itemElement.style.position = 'relative';
        itemElement.appendChild(loadingOverlay);
        
        fetch(`/cart/update/${productId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ 
                quantity: quantity
            })
        })
        .then(response => response.json())
        .then(data => {
            loadingOverlay.remove();
            
            if (data.success) {
                itemElement.querySelector('.quantity-input').value = quantity;
                calculateTotals();
                
                Swal.fire({
                    icon: 'success',
                    title: 'Đã cập nhật',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Lỗi',
                    text: data.message || 'Không thể cập nhật số lượng',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000
                });
            }
        })
        .catch(error => {
            loadingOverlay.remove();
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Lỗi',
                text: 'Có lỗi xảy ra, vui lòng thử lại',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
        });
    }
    
    // Remove item via AJAX
    function removeItem(productId, itemElement) {
        Swal.fire({
            title: 'Xác nhận xóa?',
            text: 'Bạn có chắc muốn xóa sản phẩm này khỏi giỏ hàng?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Xóa',
            cancelButtonText: 'Hủy'
        }).then((result) => {
            if (result.isConfirmed) {
                itemElement.classList.add('removing');
                
                fetch(`/cart/remove/${productId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({})
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        setTimeout(() => {
                            itemElement.remove();
                            calculateTotals();
                            
                            // Check if cart is empty
                            if (document.querySelectorAll('.cart-item').length === 0) {
                                location.reload();
                            }
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'Đã xóa',
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 2000
                            });
                        }, 300);
                    } else {
                        itemElement.classList.remove('removing');
                        Swal.fire({
                            icon: 'error',
                            title: 'Lỗi',
                            text: data.message || 'Không thể xóa sản phẩm',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 3000
                        });
                    }
                })
                .catch(error => {
                    itemElement.classList.remove('removing');
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Lỗi',
                        text: 'Có lỗi xảy ra, vui lòng thử lại',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000
                    });
                });
            }
        });
    }
    
    // Quantity controls
    document.querySelectorAll('.cart-item').forEach(item => {
        const productId = item.dataset.productId;
        const quantityInput = item.querySelector('.quantity-input');
        const decreaseBtn = item.querySelector('.quantity-decrease');
        const increaseBtn = item.querySelector('.quantity-increase');
        const removeBtn = item.querySelector('.remove-item');
        
        // Decrease quantity
        decreaseBtn.addEventListener('click', function() {
            const currentQty = parseInt(quantityInput.value);
            if (currentQty > 1) {
                updateQuantity(productId, currentQty - 1, item);
            }
        });
        
        // Increase quantity
        increaseBtn.addEventListener('click', function() {
            const currentQty = parseInt(quantityInput.value);
            updateQuantity(productId, currentQty + 1, item);
        });
        
        // Manual input change
        let inputTimeout;
        quantityInput.addEventListener('input', function() {
            clearTimeout(inputTimeout);
            inputTimeout = setTimeout(() => {
                const newQty = parseInt(this.value) || 1;
                updateQuantity(productId, newQty, item);
            }, 800);
        });
        
        // Remove item
        removeBtn.addEventListener('click', function() {
            removeItem(productId, item);
        });
    });
});
</script>
@endpush
@endsection
