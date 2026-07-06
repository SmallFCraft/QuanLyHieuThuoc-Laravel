@extends('layouts.app')

@section('title', 'Thanh toán - Nhà Thuốc Sinh Thái')

@push('styles')
<style>
    .checkout-step {
        transition: all 0.3s ease;
    }
    .checkout-step.active .step-circle {
        transform: scale(1.1);
    }
    .step-line {
        height: 2px;
        background: linear-gradient(90deg, #10b981 0%, #d1d5db 100%);
        flex: 1;
        margin: 0 0.5rem;
    }
    .payment-option {
        cursor: pointer;
        transition: all 0.3s ease;
        border: 2px solid transparent;
    }
    .payment-option:hover {
        border-color: #10b981;
        transform: translateY(-2px);
    }
    .payment-option.selected {
        border-color: #10b981;
        background: rgba(16, 185, 129, 0.08);
    }
    .order-item {
        transition: all 0.2s ease;
    }
    .order-item:hover {
        background: rgba(0, 0, 0, 0.02);
    }
    @media (max-width: 991px) {
        .checkout-sticky {
            position: relative !important;
            top: 0 !important;
        }
    }
    .form-control:focus, .form-select:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 0.2rem rgba(16, 185, 129, 0.15);
    }
    .delivery-time-badge {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        color: #92400e;
    }
</style>
@endpush

@section('content')
<!-- Page Header -->
<section class="py-4" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 50%, #f8fafc 100%);">
    <div class="container">
        <h1 class="fw-bold text-gray-900 mb-1">{{ __('messages.checkout.title') }}</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none">{{ __('messages.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('cart.index') }}" class="text-emerald-600 text-decoration-none">{{ __('messages.checkout.step_cart') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ __('messages.checkout.breadcrumb_checkout') }}</li>
            </ol>
        </nav>
    </div>
</section>

<!-- Progress Steps -->
<section class="py-3 py-md-4 bg-white border-bottom">
    <div class="container">
        <div class="d-flex justify-content-center align-items-center">
            <!-- Step 1 -->
            <div class="checkout-step text-center">
                <div class="step-circle rounded-circle d-inline-flex align-items-center justify-content-center mb-2 text-white" style="width:40px; height:40px; background:#10b981;">
                    <i class="bi bi-check fs-5"></i>
                </div>
                <p class="small mb-0 text-emerald-700 fw-medium d-none d-md-block">{{ __('messages.checkout.step_cart') }}</p>
            </div>
            
            <div class="step-line d-none d-md-block"></div>
            <div class="d-md-none mx-2 text-gray-300">─</div>
            
            <!-- Step 2 -->
            <div class="checkout-step active text-center">
                <div class="step-circle rounded-circle d-inline-flex align-items-center justify-content-center mb-2 text-white fw-bold" style="width:40px; height:40px; background:#10b981;">
                    2
                </div>
                <p class="small mb-0 text-emerald-700 fw-medium d-none d-md-block">{{ __('messages.checkout.step_checkout') }}</p>
            </div>
            
            <div class="step-line d-none d-md-block" style="background: linear-gradient(90deg, #d1d5db 0%, #d1d5db 100%);"></div>
            <div class="d-md-none mx-2 text-gray-300">─</div>
            
            <!-- Step 3 -->
            <div class="checkout-step text-center">
                <div class="step-circle rounded-circle d-inline-flex align-items-center justify-content-center mb-2 text-gray-400 border border-2 border-gray-300 fw-bold" style="width:40px; height:40px;">
                    3
                </div>
                <p class="small mb-0 text-gray-400 d-none d-md-block">{{ __('messages.checkout.step_complete') }}</p>
            </div>
        </div>
    </div>
</section>

<section class="py-4 py-md-5">
    <div class="container">
        <div class="row g-3 g-md-4">
            <!-- Shipping Information Form -->
            <div class="col-lg-7">
                <div class="glass-card rounded-4 border-0 p-3 p-md-4 mb-3 mb-md-4">
                    <h5 class="fw-bold mb-1 text-gray-900">
                        <i class="bi bi-person-circle text-emerald-600 me-2"></i>{{ __('messages.checkout.shipping_info') }}
                    </h5>
                    <p class="text-gray-500 mb-3 mb-md-4 small">{{ __('messages.checkout.shipping_info_desc') }}</p>
                    
                    <form action="{{ route('checkout.process') }}" method="POST" id="checkoutForm">
                        @csrf
                        
                        <!-- Customer Name -->
                        <div class="mb-3">
                            <label for="customer_name" class="form-label fw-medium text-gray-700 small">
                                {{ __('messages.checkout.customer_name') }} <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input type="text" name="customer_name" id="customer_name" required
                                       value="{{ old('customer_name', auth()->user()->name) }}"
                                       class="form-control bg-gray-50 border-start-0 shadow-none @error('customer_name') is-invalid @enderror"
                                       placeholder="{{ __('messages.checkout.customer_name_placeholder') }}">
                                @error('customer_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <!-- Phone Number -->
                        <div class="mb-3">
                            <label for="shipping_phone" class="form-label fw-medium text-gray-700 small">
                                {{ __('messages.checkout.phone') }} <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400">
                                    <i class="bi bi-telephone"></i>
                                </span>
                                <input type="tel" name="shipping_phone" id="shipping_phone" required
                                       value="{{ old('shipping_phone', auth()->check() ? auth()->user()->phone : '') }}"
                                       class="form-control bg-gray-50 border-start-0 shadow-none @error('shipping_phone') is-invalid @enderror"
                                       placeholder="0901234567">
                                @error('shipping_phone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="mb-3">
                            <label for="customer_email" class="form-label fw-medium text-gray-700 small">
                                Email
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400">
                                    <i class="bi bi-envelope"></i>
                                </span>
                                <input type="email" name="customer_email" id="customer_email"
                                       value="{{ old('customer_email', auth()->user()->email) }}"
                                       class="form-control bg-gray-50 border-start-0 shadow-none"
                                       placeholder="email@example.com">
                            </div>
                            <small class="text-gray-500">{{ __('messages.checkout.email_desc') }}</small>
                        </div>

                        <!-- Shipping Address -->
                        <div class="mb-4">
                            <label for="shipping_address" class="form-label fw-medium text-gray-700 small">
                                {{ __('messages.checkout.shipping_address') }} <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400 align-items-start pt-2">
                                    <i class="bi bi-geo-alt"></i>
                                </span>
                                <textarea name="shipping_address" id="shipping_address" rows="3" required
                                          class="form-control bg-gray-50 border-start-0 shadow-none @error('shipping_address') is-invalid @enderror"
                                          placeholder="{{ __('messages.checkout.shipping_address_placeholder') }}">{{ old('shipping_address', auth()->check() ? auth()->user()->address : '') }}</textarea>
                                @error('shipping_address')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <!-- Delivery Time -->
                        <div class="mb-4">
                            <label class="form-label fw-medium text-gray-700 small">
                                {{ __('messages.checkout.delivery_time') }}
                            </label>
                            <select name="delivery_time" class="form-select bg-gray-50 shadow-none">
                                <option value="anytime">{{ __('messages.checkout.delivery_anytime') }}</option>
                                <option value="morning">{{ __('messages.checkout.delivery_morning') }}</option>
                                <option value="afternoon">{{ __('messages.checkout.delivery_afternoon') }}</option>
                                <option value="evening">{{ __('messages.checkout.delivery_evening') }}</option>
                            </select>
                        </div>

                        <!-- Order Notes -->
                        <div class="mb-4">
                            <label for="order_notes" class="form-label fw-medium text-gray-700 small">
                                {{ __('messages.checkout.order_notes') }}
                            </label>
                            <textarea name="order_notes" id="order_notes" rows="2"
                                      class="form-control bg-gray-50 shadow-none"
                                      placeholder="{{ __('messages.checkout.order_notes_placeholder') }}">{{ old('order_notes') }}</textarea>
                        </div>
                    </form>
                </div>

                <!-- Payment Method -->
                <div class="glass-card rounded-4 border-0 p-3 p-md-4">
                    <h5 class="fw-bold mb-3 text-gray-900">
                        <i class="bi bi-credit-card text-emerald-600 me-2"></i>{{ __('messages.checkout.payment_method') }}
                    </h5>
                    
                    <div class="row g-3">
                        <!-- COD Payment -->
                        <div class="col-12">
                            <div class="payment-option selected rounded-3 p-3 d-flex align-items-center gap-3" data-payment="cod">
                                <div class="flex-shrink-0">
                                    <div class="rounded-circle bg-emerald-50 d-flex align-items-center justify-content-center" style="width:48px; height:48px;">
                                        <i class="bi bi-cash-coin text-emerald-600 fs-4"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1">
                                    <p class="fw-bold mb-1">{{ __('messages.checkout.cod') }}</p>
                                    <small class="text-gray-500">{{ __('messages.checkout.cod_desc') }}</small>
                                </div>
                                <i class="bi bi-check-circle-fill text-emerald-600 fs-4"></i>
                            </div>
                        </div>

                        <!-- Bank Transfer (Coming Soon) -->
                        <div class="col-12">
                            <div class="payment-option rounded-3 p-3 d-flex align-items-center gap-3 opacity-50" style="cursor: not-allowed;">
                                <div class="flex-shrink-0">
                                    <div class="rounded-circle bg-gray-100 d-flex align-items-center justify-content-center" style="width:48px; height:48px;">
                                        <i class="bi bi-bank text-gray-400 fs-4"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1">
                                    <p class="fw-bold mb-1">{{ __('messages.checkout.bank_transfer') }}</p>
                                    <small class="text-gray-500">{{ __('messages.checkout.coming_soon') }}</small>
                                </div>
                                <span class="badge bg-gray-200 text-gray-600">{{ __('messages.checkout.coming_soon_badge') }}</span>
                            </div>
                        </div>

                        <!-- E-Wallet (Coming Soon) -->
                        <div class="col-12">
                            <div class="payment-option rounded-3 p-3 d-flex align-items-center gap-3 opacity-50" style="cursor: not-allowed;">
                                <div class="flex-shrink-0">
                                    <div class="rounded-circle bg-gray-100 d-flex align-items-center justify-content-center" style="width:48px; height:48px;">
                                        <i class="bi bi-wallet2 text-gray-400 fs-4"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1">
                                    <p class="fw-bold mb-1">{{ __('messages.checkout.e_wallet') }}</p>
                                    <small class="text-gray-500">{{ __('messages.checkout.coming_soon') }}</small>
                                </div>
                                <span class="badge bg-gray-200 text-gray-600">{{ __('messages.checkout.coming_soon_badge') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="col-lg-5">
                <div class="glass-card rounded-4 border-0 p-3 p-md-4 checkout-sticky" style="top: 100px;">
                    <h5 class="fw-bold mb-3">
                        <i class="bi bi-receipt text-emerald-600 me-2"></i>{{ __('messages.checkout.your_order') }}
                    </h5>
                    
                    <!-- Delivery Info Badge -->
                    <div class="delivery-time-badge rounded-3 p-2 mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-truck fs-5"></i>
                        <div class="small">
                            <strong>{{ __('messages.checkout.standard_delivery') }}</strong>
                            <div class="text-gray-700">{{ __('messages.checkout.delivery_est') }}</div>
                        </div>
                    </div>

                    <!-- Order Items -->
                    <div class="mb-3" style="max-height: 300px; overflow-y: auto;">
                        @foreach($cartItems as $item)
                            <div class="order-item d-flex align-items-center gap-3 mb-2 pb-2 border-bottom rounded-2 p-2" style="border-color: rgba(0,0,0,0.06) !important;">
                                <div class="bg-gray-50 rounded-3 overflow-hidden flex-shrink-0 position-relative" style="width: 56px; height: 56px;" data-product-image-container>
                                    <x-product-image
                                        :src="$item['product']->image_url"
                                        :alt="$item['product']->name"
                                        class="w-100 h-100 object-fit-contain p-1"
                                        icon-size="1.5rem"
                                    />
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <p class="fw-medium text-gray-800 mb-0 small text-truncate">{{ $item['product']->name }}</p>
                                    <small class="text-gray-400">{{ $item['product']->formatted_price }} × {{ $item['quantity'] }}</small>
                                </div>
                                <span class="fw-bold text-gray-700 flex-shrink-0 small">
                                    {{ number_format($item['subtotal'], 0, ',', '.') }}₫
                                </span>
                            </div>
                        @endforeach
                    </div>

                    <!-- Price Summary -->
                    <div class="border-top pt-3" style="border-color: rgba(0,0,0,0.06) !important;">
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-gray-500">{{ __('messages.cart.subtotal') }} ({{ count($cartItems) }} {{ __('messages.cart.per_item') }})</span>
                            <span class="fw-medium">{{ number_format($total, 0, ',', '.') }} ₫</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-gray-500">{{ __('messages.cart.shipping_fee') }}</span>
                            <span class="text-emerald-600 fw-medium">{{ __('messages.cart.free_shipping') }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 small">
                            <span class="text-gray-500">{{ __('messages.checkout.discount') }}</span>
                            <span class="text-gray-500">0 ₫</span>
                        </div>
                        
                        <hr class="my-3" style="border-color: rgba(0,0,0,0.06);">
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="fw-bold fs-6">{{ __('messages.cart.total') }}</span>
                            <span class="fw-bold fs-4 text-emerald-700">{{ number_format($total, 0, ',', '.') }} ₫</span>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" form="checkoutForm" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow mb-3" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                            <i class="bi bi-check-circle me-2"></i>{{ __('messages.checkout.confirm_order') }}
                        </button>

                        <!-- Security Badges -->
                        <div class="text-center">
                            <div class="d-flex justify-content-center gap-3 text-gray-400 small flex-wrap">
                                <span><i class="bi bi-shield-check me-1"></i>{{ __('messages.cart.safe') }}</span>
                                <span><i class="bi bi-lock me-1"></i>{{ __('messages.cart.secure') }}</span>
                                <span><i class="bi bi-truck me-1"></i>{{ __('messages.checkout.fast_delivery') }}</span>
                            </div>
                        </div>

                        <!-- Back to Cart -->
                        <div class="mt-3 text-center">
                            <a href="{{ route('cart.index') }}" class="text-emerald-600 text-decoration-none fw-medium small">
                                <i class="bi bi-arrow-left me-1"></i>{{ __('messages.checkout.back_to_cart') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Payment method selection
    const paymentOptions = document.querySelectorAll('.payment-option:not([style*="cursor: not-allowed"])');
    
    paymentOptions.forEach(option => {
        option.addEventListener('click', function() {
            paymentOptions.forEach(opt => opt.classList.remove('selected'));
            this.classList.add('selected');
        });
    });

    // Form validation
    const form = document.getElementById('checkoutForm');
    form.addEventListener('submit', function(e) {
        const phone = document.getElementById('shipping_phone').value;
        const phoneRegex = /^(\+84|0)[0-9]{9}$/;
        
        if (!phoneRegex.test(phone)) {
            e.preventDefault();
            alert('{{ __('messages.checkout.js.invalid_phone') }}');
            return false;
        }
    });
});
</script>
@endpush
@endsection
