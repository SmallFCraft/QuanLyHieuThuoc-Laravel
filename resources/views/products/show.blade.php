@extends('layouts.app')

@section('title', $product->name . ' - Nhà Thuốc Sinh Thái')

@php
    $discountPrice = $product->price_vnd * 1.1;
    $isLowStock = $product->stock_quantity > 0 && $product->stock_quantity <= $product->reorder_point;
    $hasIngredients = filled($product->ingredients);
    $hasUsage = filled($product->usage);
@endphp

@section('content')
<div class="product-detail-page" data-product-id="{{ $product->MaSP }}">
    <section class="product-detail-hero product-detail-hero--rich">
        <div class="container position-relative">
            <nav aria-label="breadcrumb" class="product-detail-breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('home') }}">{{ __('messages.home') }}</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('products.index') }}">{{ __('messages.products') }}</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $product->name }}</li>
                </ol>
            </nav>

            <div class="row g-4 g-xl-5 align-items-start product-detail-main">
                <div class="col-lg-5">
                    <div class="product-media-stack">
                        <div class="product-media-card">
                            <div class="product-image-main" data-product-image-container>
                                <x-product-image
                                    :src="$product->image_url"
                                    :alt="$product->name"
                                    class="w-100 h-100 object-fit-cover product-main-img"
                                    id="mainProductImage"
                                    icon-size="4rem"
                                    loading="eager"
                                />
                                @auth
                                    <button class="product-favorite-media-button" type="button"
                                            data-favorite-toggle
                                            data-product-id="{{ $product->MaSP }}"
                                            data-favorite-label="{{ __('messages.save_favorite') }}"
                                            data-saved-label="{{ __('messages.saved_favorite') }}"
                                            aria-pressed="false"
                                            aria-label="{{ __('messages.save_favorite') }}"
                                            title="{{ __('messages.save_favorite') }}">
                                        <i class="bi bi-heart"></i>
                                    </button>
                                @endauth
                                @if($product->image_url)
                                    <button class="product-zoom-button" type="button"
                                            onclick="toggleImageZoom()"
                                            title="{{ __('messages.zoom_image') }}"
                                            aria-label="{{ __('messages.zoom_image') }}"
                                            data-product-image-action>
                                        <i class="bi bi-zoom-in"></i>
                                    </button>
                                @endif
                            </div>
                        </div>

                        <div class="product-mini-metrics">
                            <div>
                                <i class="bi bi-eye"></i>
                                <span>{{ number_format($product->views_count, 0, ',', '.') }}</span>
                                <small>lượt xem</small>
                            </div>
                            <div>
                                <i class="bi bi-box-seam"></i>
                                <span>{{ $product->stock_quantity }}</span>
                                <small>{{ __('messages.product_stock') }}</small>
                            </div>
                            <div>
                                <i class="bi bi-calendar2-check"></i>
                                <span>{{ $product->formatted_expiration_date }}</span>
                                <small>{{ __('messages.product_expiration') }}</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="product-summary-panel">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <span class="product-status-chip product-status-chip--trust">
                                <i class="bi bi-patch-check-fill"></i>{{ __('messages.product_detail_badge') }}
                            </span>
                            <a href="{{ route('products.category', $product->MaDM) }}" class="product-status-chip product-status-chip--link">
                                <i class="bi bi-bookmark"></i>{{ $product->category->name }}
                            </a>
                            @if($product->stock_quantity > 0)
                                <span class="product-status-chip {{ $isLowStock ? 'product-status-chip--warning' : 'product-status-chip--success' }}">
                                    <i class="bi {{ $isLowStock ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' }}"></i>
                                    {{ $isLowStock ? __('messages.low_stock') : __('messages.in_stock') }}
                                </span>
                            @else
                                <span class="product-status-chip product-status-chip--danger">
                                    <i class="bi bi-x-circle-fill"></i>{{ __('messages.out_of_stock') }}
                                </span>
                            @endif
                        </div>

                        <h1 class="product-detail-title">{{ $product->name }}</h1>
                        <p class="product-detail-subtitle">{{ __('messages.product_detail_subtitle') }}</p>

                        <div class="product-price-card">
                            <div>
                                <span class="product-price-label">{{ __('messages.product_price') }}</span>
                                <div class="product-price-row">
                                    <strong>{{ $product->formatted_price }}</strong>
                                    <span>{{ format_currency_vi($discountPrice) }}</span>
                                    <em>-10%</em>
                                </div>
                                <p><i class="bi bi-info-circle"></i>{{ __('messages.vat_included') }}</p>
                            </div>
                            <div class="product-stock-summary {{ $product->stock_quantity > 0 ? ($isLowStock ? 'is-low' : 'is-ready') : 'is-out' }}">
                                <span class="product-stock-summary__icon">
                                    <i class="bi {{ $product->stock_quantity > 0 ? ($isLowStock ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill') : 'bi-x-circle-fill' }}"></i>
                                </span>
                                <div>
                                    <small>{{ __('messages.stock_status') }}</small>
                                    <strong>{{ $product->stock_quantity > 0 ? ($isLowStock ? __('messages.low_stock') : __('messages.in_stock')) : __('messages.out_of_stock') }}</strong>
                                    <em>
                                        @if($product->stock_quantity > 0)
                                            {{ __('messages.products_left', ['count' => number_format($product->stock_quantity, 0, ',', '.')]) }}
                                        @else
                                            {{ __('messages.contact_to_preorder') }}
                                        @endif
                                    </em>
                                </div>
                            </div>
                        </div>

                        <div class="product-insight-grid">
                            <div class="product-insight-card">
                                <i class="bi bi-shield-check"></i>
                                <div>
                                    <strong>{{ __('messages.authentic') }}</strong>
                                    <span>100% authentic</span>
                                </div>
                            </div>
                            <div class="product-insight-card">
                                <i class="bi bi-truck"></i>
                                <div>
                                    <strong>{{ __('messages.fast_delivery') }}</strong>
                                    <span>{{ __('messages.fast_delivery_time') }}</span>
                                </div>
                            </div>
                            <div class="product-insight-card">
                                <i class="bi bi-calendar-heart"></i>
                                <div>
                                    <strong>{{ __('messages.expiry_priority') }}</strong>
                                    <span>{{ __('messages.expiry_priority_desc') }}</span>
                                </div>
                            </div>
                        </div>

                        @if($product->stock_quantity > 0)
                            <form action="{{ route('cart.add') }}" method="POST" class="product-buy-panel js-add-to-cart-form" id="buy-panel">
                                @csrf
                                <input type="hidden" name="MaSP" value="{{ $product->MaSP }}">

                                <div class="product-quantity-row">
                                    <div>
                                        <label for="qty-input">{{ __('messages.cart.quantity') }}</label>
                                        <span>{!! __('messages.products_left', ['count' => '<strong>' . $product->stock_quantity . '</strong>']) !!}</span>
                                    </div>
                                    <div class="product-qty-control">
                                        <button type="button" onclick="changeQty(-1)" aria-label="Giảm số lượng">
                                            <i class="bi bi-dash"></i>
                                        </button>
                                        <input type="number" name="quantity" id="qty-input" value="1" min="1" max="{{ $product->stock_quantity }}" data-unit-price="{{ $product->price_vnd }}">
                                        <button type="button" onclick="changeQty(1)" aria-label="Tăng số lượng">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="product-total-row">
                                    <span>{{ __('messages.estimated_total') }}</span>
                                    <strong id="estimated-total">{{ $product->formatted_price }}</strong>
                                </div>

                                <div class="product-action-grid">
                                    <button type="submit" class="product-primary-action">
                                        <i class="bi bi-cart-plus"></i>{{ __('messages.add_to_cart') }}
                                    </button>
                                    <button type="button" class="product-icon-action" onclick="shareProduct()" title="{{ __('messages.share_product') }}">
                                        <i class="bi bi-share"></i>
                                        <span>{{ __('messages.share_product') }}</span>
                                    </button>
                                    <button type="button" class="product-icon-action" onclick="openProductAssistant()" title="{{ __('messages.advisor_cta') }}">
                                        <i class="bi bi-headset"></i>
                                        <span>{{ __('messages.advisor_cta') }}</span>
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="product-out-panel">
                                <i class="bi bi-box-seam"></i>
                                <div>
                                    <h2>{{ __('messages.temporary_out_of_stock') }}</h2>
                                    <p>{{ __('messages.contact_to_preorder') }}</p>
                                </div>
                                <button type="button" onclick="openProductAssistant()">
                                    <i class="bi bi-bell"></i>{{ __('messages.notify_when_available') }}
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="product-detail-shell py-4 py-lg-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="product-info-card d-none d-md-block">
                        <ul class="nav product-info-tabs" id="productTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">
                                    <i class="bi bi-grid"></i>{{ __('messages.product_overview') }}
                                </button>
                            </li>
                            @if($hasIngredients)
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="ingredients-tab" data-bs-toggle="tab" data-bs-target="#ingredients" type="button" role="tab">
                                        <i class="bi bi-list-check"></i>{{ __('messages.product_ingredients') }}
                                    </button>
                                </li>
                            @endif
                            @if($hasUsage)
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="usage-tab" data-bs-toggle="tab" data-bs-target="#usage" type="button" role="tab">
                                        <i class="bi bi-capsule"></i>{{ __('messages.product_usage') }}
                                    </button>
                                </li>
                            @endif
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="safety-tab" data-bs-toggle="tab" data-bs-target="#safety" type="button" role="tab">
                                    <i class="bi bi-heart-pulse"></i>{{ __('messages.safety_note') }}
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content product-info-content" id="productTabContent">
                            <div class="tab-pane fade show active" id="overview" role="tabpanel">
                                <div class="product-spec-grid">
                                    <div>
                                        <span>{{ __('messages.product_id') }}</span>
                                        <strong>#{{ $product->MaSP }}</strong>
                                    </div>
                                    <div>
                                        <span>{{ __('messages.product_category') }}</span>
                                        <strong>{{ $product->category->name }}</strong>
                                    </div>
                                    <div>
                                        <span>{{ __('messages.product_stock') }}</span>
                                        <strong>{{ __('messages.products_left', ['count' => $product->stock_quantity]) }}</strong>
                                    </div>
                                    <div>
                                        <span>{{ __('messages.product_expiration') }}</span>
                                        <strong>{{ $product->formatted_expiration_date }}</strong>
                                    </div>
                                    @if($product->batch_number)
                                        <div>
                                            <span>{{ __('messages.product_batch') }}</span>
                                            <strong>{{ $product->batch_number }}</strong>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if($hasIngredients)
                                <div class="tab-pane fade" id="ingredients" role="tabpanel">
                                    <div class="product-long-text">{{ $product->ingredients }}</div>
                                </div>
                            @endif

                            @if($hasUsage)
                                <div class="tab-pane fade" id="usage" role="tabpanel">
                                    <div class="product-long-text">{{ $product->usage }}</div>
                                </div>
                            @endif

                            <div class="tab-pane fade" id="safety" role="tabpanel">
                                <div class="product-safety-note">
                                    <i class="bi bi-info-circle"></i>
                                    <p>{{ __('messages.safety_note_desc') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="product-mobile-accordion d-md-none">
                        <details open>
                            <summary>{{ __('messages.product_overview') }}</summary>
                            <div class="product-spec-grid">
                                <div><span>{{ __('messages.product_id') }}</span><strong>#{{ $product->MaSP }}</strong></div>
                                <div><span>{{ __('messages.product_stock') }}</span><strong>{{ __('messages.products_left', ['count' => $product->stock_quantity]) }}</strong></div>
                                <div><span>{{ __('messages.product_expiration') }}</span><strong>{{ $product->formatted_expiration_date }}</strong></div>
                            </div>
                        </details>
                        <details>
                            <summary>{{ __('messages.product_ingredients') }}</summary>
                            <div class="product-long-text">{{ $product->ingredients ?: __('messages.not_available') }}</div>
                        </details>
                        <details>
                            <summary>{{ __('messages.product_usage') }}</summary>
                            <div class="product-long-text">{{ $product->usage ?: __('messages.not_available') }}</div>
                        </details>
                        <details>
                            <summary>{{ __('messages.safety_note') }}</summary>
                            <div class="product-safety-note">
                                <i class="bi bi-info-circle"></i>
                                <p>{{ __('messages.safety_note_desc') }}</p>
                            </div>
                        </details>
                    </div>
                </div>

                <div class="col-lg-4">
                    <aside class="product-service-panel">
                        <h2>{{ __('messages.service_commitments') }}</h2>
                        <div class="product-service-list">
                            <div>
                                <i class="bi bi-truck"></i>
                                <div>
                                    <strong>{{ __('messages.shipping_info') }}</strong>
                                    <span>{{ __('messages.delivery_time_estimate') }}</span>
                                </div>
                            </div>
                            <div>
                                <i class="bi bi-arrow-repeat"></i>
                                <div>
                                    <strong>{{ __('messages.return_policy_title') }}</strong>
                                    <span>{{ __('messages.return_within_30_days') }}</span>
                                </div>
                            </div>
                            <div>
                                <i class="bi bi-shield-check"></i>
                                <div>
                                    <strong>{{ __('messages.authentic') }}</strong>
                                    <span>{{ __('messages.product_intact') }}</span>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>

            <section class="product-related-section">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 mb-3">
                    <div>
                        <span>{{ __('messages.related_products_desc') }}</span>
                        <h2>{{ __('messages.related_products') }}</h2>
                    </div>
                    <a href="{{ route('products.category', $product->MaDM) }}" class="product-text-link">
                        {{ __('messages.home_view_all') }} <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                @if(($relatedProducts ?? collect())->isNotEmpty())
                    <div class="row g-3 g-md-4">
                        @foreach($relatedProducts as $related)
                            <div class="col-12 col-sm-6 col-xl-3">
                                <x-product-card
                                    :product="$related"
                                    icon-size="3rem"
                                />
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="product-empty-related">
                        <i class="bi bi-box2"></i>{{ __('messages.no_related_products') }}
                    </div>
                @endif
            </section>

            <div class="mt-4 pt-2">
                <a href="{{ route('products.index') }}" class="product-back-link">
                    <i class="bi bi-arrow-left"></i>{{ __('messages.back_to_products') }}
                </a>
            </div>
        </div>
    </section>

    @if($product->stock_quantity > 0)
        <div class="sticky-cart-bar d-lg-none">
            <div>
                <strong>{{ $product->formatted_price }}</strong>
                <span>{{ __('messages.products_left', ['count' => $product->stock_quantity]) }}</span>
            </div>
            <button onclick="scrollToBuyPanel()" class="btn">
                <i class="bi bi-cart-plus"></i>{{ __('messages.buy_now') }}
            </button>
        </div>
    @endif

    <div class="modal fade" id="imageZoomModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-transparent border-0">
                <div class="modal-body product-zoom-modal-body p-0 position-relative bg-white rounded-4 overflow-hidden" data-product-image-container>
                    <button type="button" class="btn-close position-absolute top-0 end-0 m-3 z-3"
                            data-bs-dismiss="modal" aria-label="Close"></button>
                    <x-product-image
                        :src="$product->image_url"
                        :alt="$product->name"
                        class="w-100 h-100 object-fit-contain rounded-4"
                        icon-size="4rem"
                        loading="eager"
                    />
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const productDetailState = {
    id: @json((string) $product->MaSP),
    name: @json($product->name),
    shareUrl: @json(route('products.show', $product->MaSP)),
    copiedLabel: @json(__('messages.copied_link')),
};

function formatVnd(value) {
    return new Intl.NumberFormat('vi-VN', {
        style: 'currency',
        currency: 'VND',
        maximumFractionDigits: 0,
    }).format(value);
}

function updateEstimatedTotal() {
    const input = document.getElementById('qty-input');
    const target = document.getElementById('estimated-total');

    if (!input || !target) {
        return;
    }

    const quantity = Number(input.value || 1);
    const unitPrice = Number(input.dataset.unitPrice || 0);
    target.textContent = formatVnd(quantity * unitPrice);
}

function changeQty(delta) {
    const input = document.getElementById('qty-input');

    if (!input) {
        return;
    }

    const min = Number(input.min || 1);
    const max = Number(input.max || min);
    const nextValue = Math.max(min, Math.min(max, Number(input.value || min) + delta));
    input.value = nextValue;
    updateEstimatedTotal();
}

function toggleImageZoom() {
    const modalElement = document.getElementById('imageZoomModal');
    if (!modalElement || !window.bootstrap?.Modal) {
        return;
    }

    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

async function copyProductLink() {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(productDetailState.shareUrl);
    }
}

async function shareProduct() {
    try {
        if (navigator.share) {
            await navigator.share({
                title: productDetailState.name,
                url: productDetailState.shareUrl,
            });
            return;
        }

        await copyProductLink();
        if (window.Swal?.fire) {
            window.Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: productDetailState.copiedLabel,
                showConfirmButton: false,
                timer: 1800,
            });
        }
    } catch (error) {
        if (error?.name !== 'AbortError') {
            console.warn('Unable to share product link', error);
        }
    }
}

function openProductAssistant() {
    window.dispatchEvent(new CustomEvent('open-chatbot'));
}

function scrollToBuyPanel() {
    document.getElementById('buy-panel')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('qty-input')?.addEventListener('input', updateEstimatedTotal);
    updateEstimatedTotal();
});
</script>
@endpush
