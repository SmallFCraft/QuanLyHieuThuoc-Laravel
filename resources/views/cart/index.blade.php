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
    .cart-product-link {
        color: #1f2937;
        text-decoration: none;
        transition: color 0.2s ease;
    }
    .cart-product-link:hover,
    .cart-product-link:focus-visible {
        color: #059669;
    }
    .quantity-input::-webkit-inner-spin-button,
    .quantity-input::-webkit-outer-spin-button {
        opacity: 1;
    }
</style>
@endpush

@section('content')
<!-- Page Header -->
<section class="py-4" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 50%, #f8fafc 100%);">
    <div class="container">
        <h1 class="fw-bold text-gray-900 mb-1">{{ __('messages.cart.title') }}</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none">{{ __('messages.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('messages.cart.title') }}</li>
            </ol>
        </nav>
    </div>
</section>

<section class="py-5">
    <div class="container">
        @if(session('drug_warnings'))
            <div class="glass-card rounded-4 border-0 p-3 p-md-4 mb-4">
                <x-drug-warnings :warnings="session('drug_warnings')">
                    @if(session('drug_warning_product'))
                        {{-- Same request as before plus confirm_warning=1, so the override is
                             an explicit act by the customer, not a retry side effect. --}}
                        <form action="{{ route('cart.add') }}" method="POST" class="d-flex flex-wrap gap-2 mt-3">
                            @csrf
                            <input type="hidden" name="MaSP" value="{{ session('drug_warning_product') }}">
                            <input type="hidden" name="quantity" value="{{ session('drug_warning_quantity', 1) }}">
                            <input type="hidden" name="confirm_warning" value="1">
                            <button type="submit" class="btn text-white fw-semibold rounded-pill px-4"
                                    style="min-height:44px; background:#B91C1C;">
                                <i class="bi bi-check2-circle me-2" aria-hidden="true"></i>{{ __('messages.drug_safety.confirm_button') }}
                            </button>
                            <a href="{{ route('products.index') }}" class="btn btn-outline-secondary fw-semibold rounded-pill px-4 d-inline-flex align-items-center"
                               style="min-height:44px;">
                                {{ __('messages.drug_safety.cancel_button') }}
                            </a>
                        </form>
                    @endif
                </x-drug-warnings>
            </div>
        @endif

        @if(empty($cartItems))
            <!-- Empty Cart -->
            <div class="glass-card rounded-4 border-0 text-center py-5 px-4">
                <div class="bg-emerald-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width:100px; height:100px;">
                    <i class="bi bi-cart-x text-emerald-500" style="font-size: 2.5rem;"></i>
                </div>
                <h3 class="fw-bold text-gray-800 mb-2">{{ __('messages.cart.empty_title') }}</h3>
                <p class="text-gray-500 mb-4">{{ __('messages.cart.empty_message') }}</p>
                <a href="{{ route('products.index') }}" class="btn btn-glass-success rounded-pill px-5 py-3 fw-medium shadow-sm border-0">
                    <i class="bi bi-cart-plus me-2"></i>{{ __('messages.cart.start_shopping') }}
                </a>
            </div>
        @else
            <div class="row g-4" id="cart-content">
                <!-- Cart Items -->
                <div class="col-lg-8">
                    <div class="glass-card rounded-4 border-0 p-0 overflow-hidden">
                        <div class="p-4 border-bottom" style="border-color: rgba(0,0,0,0.06) !important;">
                            <h5 class="fw-bold mb-0">
                                <i class="bi bi-cart3 text-emerald-600 me-2"></i>
                                {{ __('messages.cart.items_count') }} (<span id="cart-count">{{ count($cartItems) }}</span>)
                            </h5>
                        </div>
                        
                        <div id="cart-items-container">
                            @foreach($cartItems as $item)
                                <div class="cart-item d-flex align-items-center gap-3 p-4 border-bottom flex-wrap position-relative" 
                                     style="border-color: rgba(0,0,0,0.04) !important;" 
                                     data-product-id="{{ $item['product']->MaSP }}"
                                     data-price="{{ $item['product']->price_vnd }}"
                                     data-max-stock="{{ $item['product']->stock_quantity }}">
                                    
                                    <!-- Image -->
                                    <div class="bg-gray-50 rounded-3 overflow-hidden flex-shrink-0 position-relative" style="width: 90px; height: 90px;" data-product-image-container>
                                        <x-product-image
                                            :src="$item['product']->image_url"
                                            :alt="$item['product']->name"
                                            class="w-100 h-100 object-fit-contain p-1"
                                            icon-size="2.5rem"
                                        />
                                    </div>
                                    
                                    <!-- Info -->
                                    <div class="flex-grow-1 min-w-0">
                                        <h6 class="fw-bold text-gray-800 mb-1 text-truncate">
                                            <a href="{{ route('products.show', $item['product']->MaSP) }}" class="cart-product-link">
                                                {{ $item['product']->name }}
                                            </a>
                                        </h6>
                                        <p class="text-emerald-600 small mb-1 fw-medium">{{ $item['product']->category->name }}</p>
                                        <p class="text-gray-500 small mb-0">{{ $item['product']->formatted_price }} / {{ __('messages.cart.per_item') }}</p>
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
                                            style="width:36px; height:36px;" title="{{ __('messages.cart.remove') }}">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        
                        <div class="p-4">
                            <a href="{{ route('products.index') }}" class="text-emerald-600 text-decoration-none fw-medium">
                                <i class="bi bi-arrow-left me-2"></i>{{ __('messages.cart.continue_shopping') }}
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Order Summary -->
                <div class="col-lg-4">
                    <div class="glass-card rounded-4 border-0 position-sticky" style="top: 100px;">
                        <h5 class="fw-bold mb-4">
                            <i class="bi bi-receipt text-emerald-600 me-2"></i>{{ __('messages.cart.order_summary') }}
                        </h5>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-gray-500">{{ __('messages.cart.subtotal') }}</span>
                            <span class="fw-medium" id="subtotal-amount">{{ number_format($total, 0, ',', '.') }} ₫</span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-gray-500">{{ __('messages.cart.shipping_fee') }}</span>
                            <span class="text-emerald-600 fw-medium">{{ __('messages.cart.free_shipping') }}</span>
                        </div>
                        
                        <hr class="my-3" style="border-color: rgba(0,0,0,0.06);">
                        
                        <div class="d-flex justify-content-between mb-4">
                            <span class="fw-bold fs-5">{{ __('messages.cart.total') }}</span>
                            <span class="fw-bold fs-4 text-emerald-700" id="total-amount">{{ number_format($total, 0, ',', '.') }} ₫</span>
                        </div>
                        
                        @auth
                            <a href="{{ route('checkout.index') }}" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <i class="bi bi-credit-card me-2"></i>{{ __('messages.cart.checkout_btn') }}
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                <i class="bi bi-box-arrow-in-right me-2"></i>{{ __('messages.cart.login_to_checkout') }}
                            </a>
                        @endauth
                        
                        <div class="text-center mt-4">
                            <div class="d-flex justify-content-center gap-3 text-gray-400 small">
                                <span><i class="bi bi-shield-check me-1"></i>{{ __('messages.cart.safe') }}</span>
                                <span><i class="bi bi-lock me-1"></i>{{ __('messages.cart.secure') }}</span>
                                <span><i class="bi bi-truck me-1"></i>{{ __('messages.cart.fast') }}</span>
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
        return new Intl.NumberFormat('vi-VN', {
            maximumFractionDigits: 0,
            minimumFractionDigits: 0
        }).format(Math.round(Number(number) || 0)) + ' ₫';
    }
    
    // Calculate totals
    function calculateTotals() {
        let subtotal = 0;
        
        document.querySelectorAll('.cart-item').forEach(item => {
            const price = Math.round(Number(item.dataset.price) || 0);
            const quantity = parseInt(item.querySelector('.quantity-input').value);
            const itemSubtotal = price * quantity;
            
            // Update item subtotal
            item.querySelector('.item-subtotal').textContent = formatVND(itemSubtotal);
            subtotal += itemSubtotal;
        });
        
        // Update subtotal and total when the summary panel is still mounted.
        const subtotalAmountEl = document.getElementById('subtotal-amount');
        const totalAmountEl = document.getElementById('total-amount');
        if (subtotalAmountEl) {
            subtotalAmountEl.textContent = formatVND(subtotal);
        }
        if (totalAmountEl) {
            totalAmountEl.textContent = formatVND(subtotal);
        }
        
        const lineCount = document.querySelectorAll('.cart-item').length;
        const cartCountEl = document.getElementById('cart-count');
        if (cartCountEl) {
            cartCountEl.textContent = lineCount;
        }

        if (typeof window.cartNavbarSetCount === 'function') {
            window.cartNavbarSetCount(lineCount);
        }
    }

    function renderEmptyCartState() {
        const cartContent = document.getElementById('cart-content');
        if (!cartContent) {
            return;
        }

        cartContent.innerHTML = `
            <div class="col-12">
                <div class="glass-card rounded-4 border-0 text-center py-5 px-4">
                    <div class="bg-emerald-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width:100px; height:100px;">
                        <i class="bi bi-cart-x text-emerald-500" style="font-size: 2.5rem;"></i>
                    </div>
                    <h3 class="fw-bold text-gray-800 mb-2">{{ __('messages.cart.empty_title') }}</h3>
                    <p class="text-gray-500 mb-4">{{ __('messages.cart.empty_message') }}</p>
                    <a href="{{ route('products.index') }}" class="btn btn-glass-success rounded-pill px-5 py-3 fw-medium shadow-sm border-0">
                        <i class="bi bi-cart-plus me-2"></i>{{ __('messages.cart.start_shopping') }}
                    </a>
                </div>
            </div>
        `;
    }

    function showCartError(message) {
        Swal.fire({
            icon: 'error',
            title: '{{ __("messages.cart.js.error_title") }}',
            text: message || '{{ __("messages.cart.js.generic_error") }}',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000
        });
    }
    
    // Update quantity via AJAX
    function updateQuantity(productId, quantity, itemElement) {
        const maxStock = parseInt(itemElement.dataset.maxStock);
        const quantityInput = itemElement.querySelector('.quantity-input');
        const previousQuantity = parseInt(quantityInput.dataset.lastQuantity || quantityInput.value) || 1;
        
        if (quantity < 1) {
            quantityInput.value = previousQuantity;
            return;
        }
        if (quantity > maxStock) {
            Swal.fire({
                icon: 'warning',
                title: '{{ __("messages.cart.js.exceed_stock_title") }}',
                text: '{{ __("messages.cart.js.exceed_stock_msg", ["max" => ""]) }}'.replace(' ', '') + maxStock + ' {{ __("messages.cart.per_item") }}',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
            quantity = maxStock;
        }

        quantityInput.value = quantity;
        calculateTotals();
        
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
            if (data.success) {
                quantityInput.dataset.lastQuantity = quantity;
                calculateTotals();
            } else {
                quantityInput.value = previousQuantity;
                calculateTotals();
                showCartError(data.message || '{{ __("messages.cart.js.update_error") }}');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            quantityInput.value = previousQuantity;
            calculateTotals();
            showCartError('{{ __("messages.cart.js.generic_error") }}');
        });
    }
    
    // Remove item via AJAX
    function removeItem(productId, itemElement) {
        Swal.fire({
            title: '{{ __("messages.cart.js.confirm_remove_title") }}',
            text: '{{ __("messages.cart.js.confirm_remove_msg") }}',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: '{{ __("messages.cart.js.btn_remove") }}',
            cancelButtonText: '{{ __("messages.cart.js.btn_cancel") }}'
        }).then((result) => {
            if (result.isConfirmed) {
                if (itemElement.dataset.removing === 'true') {
                    return;
                }

                const parentElement = itemElement.parentElement;
                const nextElement = itemElement.nextElementSibling;
                const restoreItem = () => {
                    itemElement.classList.remove('removing');
                    delete itemElement.dataset.removing;

                    if (parentElement && !itemElement.isConnected) {
                        parentElement.insertBefore(itemElement, nextElement);
                    }

                    calculateTotals();
                };

                itemElement.dataset.removing = 'true';
                itemElement.classList.add('removing');

                setTimeout(() => {
                    if (itemElement.dataset.removing === 'true') {
                        itemElement.remove();
                        calculateTotals();
                    }
                }, 180);
                
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
                        if (itemElement.isConnected) {
                            itemElement.remove();
                            calculateTotals();
                        }

                        if (document.querySelectorAll('.cart-item').length === 0) {
                            renderEmptyCartState();
                        }
                    } else {
                        restoreItem();
                        showCartError(data.message || '{{ __("messages.cart.js.remove_error") }}');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    restoreItem();
                    showCartError('{{ __("messages.cart.js.generic_error") }}');
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
        quantityInput.dataset.lastQuantity = quantityInput.value;
        
        // Decrease quantity
        decreaseBtn.addEventListener('click', function() {
            const currentQty = parseInt(quantityInput.value);
            if (currentQty > 1) {
                updateQuantity(productId, currentQty - 1, item);
            } else {
                removeItem(productId, item);
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
