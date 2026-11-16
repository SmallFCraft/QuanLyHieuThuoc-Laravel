@extends('layouts.app')

@section('title', $sanPham->ten . ' - Nhà Thuốc Sinh Thái')

@php
    $giaSoSanh = $sanPham->price_vnd * 1.1;
    $conHang = $sanPham->so_luong_ton > 0;
    $hasIngredients = filled($sanPham->thanh_phan);
    $hasUsage = filled($sanPham->cach_dung);
@endphp

@section('content')
<div class="product-detail-page" data-product-id="{{ $sanPham->ma_sp }}">
    <section class="product-detail-hero product-detail-hero--rich">
        <div class="container position-relative">
            <nav aria-label="breadcrumb" class="product-detail-breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('trang-chu') }}">{{ __('messages.home') }}</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('san-pham.index') }}">{{ __('messages.products') }}</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $sanPham->ten }}</li>
                </ol>
            </nav>

            <div class="row g-4 g-xl-5 align-items-start product-detail-main">
                <div class="col-lg-5">
                    <div class="product-media-stack">
                        <div class="product-media-card">
                            <div class="product-image-main" data-product-image-container>
                                <x-product-image
                                    :src="$sanPham->image_url"
                                    :alt="$sanPham->ten"
                                    class="w-100 h-100 object-fit-cover product-main-img"
                                    id="mainProductImage"
                                    icon-size="4rem"
                                    loading="eager"
                                />
                                @auth
                                    <button class="product-favorite-media-button" type="button"
                                            data-favorite-toggle
                                            data-product-id="{{ $sanPham->ma_sp }}"
                                            data-favorite-label="{{ __('messages.save_favorite') }}"
                                            data-saved-label="{{ __('messages.saved_favorite') }}"
                                            aria-pressed="false"
                                            aria-label="{{ __('messages.save_favorite') }}"
                                            title="{{ __('messages.save_favorite') }}">
                                        <i class="bi bi-heart"></i>
                                    </button>
                                @endauth
                                @if($sanPham->image_url)
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
                                <span>{{ number_format($sanPham->luot_xem, 0, ',', '.') }}</span>
                                <small>lượt xem</small>
                            </div>
                            @if($sanPham->shelf_life_text)
                                <div>
                                    <i class="bi bi-calendar2-check"></i>
                                    <span>{{ $sanPham->shelf_life_text }}</span>
                                    <small>{{ __('messages.shelf_life_label') }}</small>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="product-summary-panel">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <span class="product-status-chip product-status-chip--trust">
                                <i class="bi bi-patch-check-fill"></i>{{ __('messages.product_detail_badge') }}
                            </span>
                            <a href="{{ route('san-pham.danh-muc', $sanPham->ma_dm) }}" class="product-status-chip product-status-chip--link">
                                <i class="bi bi-bookmark"></i>{{ $sanPham->danhMuc->ten }}
                            </a>
                            @if($conHang)
                                <span class="product-status-chip product-status-chip--success">
                                    <i class="bi bi-check-circle-fill"></i>
                                    {{ __('messages.in_stock') }}
                                </span>
                            @else
                                <span class="product-status-chip product-status-chip--danger">
                                    <i class="bi bi-x-circle-fill"></i>{{ __('messages.out_of_stock') }}
                                </span>
                            @endif
                        </div>

                        <h1 class="product-detail-title">{{ $sanPham->ten }}</h1>
                        <p class="product-detail-subtitle">{{ __('messages.product_detail_subtitle') }}</p>

                        <div class="product-price-card">
                            <div>
                                <span class="product-price-label">{{ __('messages.product_price') }}</span>
                                <div class="product-price-row">
                                    <strong>{{ $sanPham->formatted_price }}</strong>
                                    <span>{{ format_currency_vi($giaSoSanh) }}</span>
                                    <em>-10%</em>
                                </div>
                                <p><i class="bi bi-info-circle"></i>{{ __('messages.vat_included') }}</p>
                            </div>
                            <div class="product-stock-summary {{ $conHang ? 'is-ready' : 'is-out' }}">
                                <span class="product-stock-summary__icon">
                                    <i class="bi {{ $conHang ? 'bi-check-circle-fill' : 'bi-x-circle-fill' }}"></i>
                                </span>
                                <div>
                                    <small>{{ __('messages.stock_status') }}</small>
                                    <strong>{{ $conHang ? __('messages.in_stock') : __('messages.out_of_stock') }}</strong>
                                    <em>
                                        @if($conHang)
                                            {{ __('messages.ready_to_ship') }}
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

                        @if($conHang)
                            <form action="{{ route('gio-hang.them') }}" method="POST" class="product-buy-panel js-add-to-cart-form" id="buy-panel">
                                @csrf
                                <input type="hidden" name="MaSP" value="{{ $sanPham->ma_sp }}">

                                <div class="product-quantity-row">
                                    <div>
                                        <label for="qty-input">{{ __('messages.cart.quantity') }}</label>
                                    </div>
                                    <div class="product-qty-control">
                                        <button type="button" onclick="changeQty(-1)" aria-label="Giảm số lượng">
                                            <i class="bi bi-dash"></i>
                                        </button>
                                        {{-- max still bounds the input so the cart cannot be handed an
                                             unfillable quantity; the ceiling itself is not shown. --}}
                                        <input type="number" name="quantity" id="qty-input" value="1" min="1" max="{{ $sanPham->so_luong_ton }}" data-unit-price="{{ $sanPham->price_vnd }}">
                                        <button type="button" onclick="changeQty(1)" aria-label="Tăng số lượng">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="product-total-row">
                                    <span>{{ __('messages.estimated_total') }}</span>
                                    <strong id="estimated-total">{{ $sanPham->formatted_price }}</strong>
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
                                        <strong>{{ $sanPham->display_code }}</strong>
                                    </div>
                                    <div>
                                        <span>{{ __('messages.product_category') }}</span>
                                        <strong>{{ $sanPham->danhMuc->ten }}</strong>
                                    </div>
                                    <div>
                                        <span>{{ __('messages.stock_status') }}</span>
                                        <strong>{{ $conHang ? __('messages.in_stock') : __('messages.out_of_stock') }}</strong>
                                    </div>
                                    @if($sanPham->shelf_life_text)
                                        <div>
                                            <span>{{ __('messages.shelf_life_label') }}</span>
                                            <strong>{{ $sanPham->shelf_life_text }}</strong>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if($hasIngredients)
                                <div class="tab-pane fade" id="ingredients" role="tabpanel">
                                    <div class="product-long-text">{{ $sanPham->thanh_phan }}</div>
                                </div>
                            @endif

                            @if($hasUsage)
                                <div class="tab-pane fade" id="usage" role="tabpanel">
                                    <div class="product-long-text">{{ $sanPham->cach_dung }}</div>
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
                                <div><span>{{ __('messages.product_id') }}</span><strong>{{ $sanPham->display_code }}</strong></div>
                                <div><span>{{ __('messages.stock_status') }}</span><strong>{{ $conHang ? __('messages.in_stock') : __('messages.out_of_stock') }}</strong></div>
                                @if($sanPham->shelf_life_text)
                                    <div><span>{{ __('messages.shelf_life_label') }}</span><strong>{{ $sanPham->shelf_life_text }}</strong></div>
                                @endif
                            </div>
                        </details>
                        <details>
                            <summary>{{ __('messages.product_ingredients') }}</summary>
                            <div class="product-long-text">{{ $sanPham->thanh_phan ?: __('messages.not_available') }}</div>
                        </details>
                        <details>
                            <summary>{{ __('messages.product_usage') }}</summary>
                            <div class="product-long-text">{{ $sanPham->cach_dung ?: __('messages.not_available') }}</div>
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
                    <a href="{{ route('san-pham.danh-muc', $sanPham->ma_dm) }}" class="product-text-link">
                        {{ __('messages.home_view_all') }} <i class="bi bi-arrow-right"></i>
                    </a>
                </div>

                @if(($sanPhamLienQuan ?? collect())->isNotEmpty())
                    <div class="row g-3 g-md-4">
                        @foreach($sanPhamLienQuan as $sanPhamLienQuan)
                            <div class="col-12 col-sm-6 col-xl-3">
                                <x-product-card
                                    :san-pham="$sanPhamLienQuan"
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
                <a href="{{ route('san-pham.index') }}" class="product-back-link">
                    <i class="bi bi-arrow-left"></i>{{ __('messages.back_to_products') }}
                </a>
            </div>
        </div>
    </section>

    @if($conHang)
        <div class="sticky-cart-bar d-lg-none">
            <div>
                <strong>{{ $sanPham->formatted_price }}</strong>
                <span>{{ __('messages.in_stock') }}</span>
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
                        :src="$sanPham->image_url"
                        :alt="$sanPham->ten"
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
    id: @json((string) $sanPham->ma_sp),
    name: @json($sanPham->ten),
    shareUrl: @json(route('san-pham.show', $sanPham->ma_sp)),
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
