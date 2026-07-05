<div class="glass-card rounded-4 border-0 p-4 p-md-5" data-profile-favorites>
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="profile-favorites__header-icon rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 icon-circle-md">
                <i class="bi bi-heart-fill fs-3"></i>
            </div>
            <div>
                <h5 class="fw-bold text-gray-900 mb-1">{{ __('messages.profile.favorites_title') }}</h5>
                <p class="text-gray-500 small mb-0">{{ __('messages.profile.favorites_desc') }}</p>
            </div>
        </div>
        <span class="profile-favorites__count rounded-pill px-3 py-2">
            <i class="bi bi-heart-pulse me-1"></i>
            <span data-favorites-count>{{ $favoriteItems->total() }}</span>
        </span>
    </div>

    @if($favoriteItems->isNotEmpty())
        <div class="row g-3 g-xl-4">
            @foreach($favoriteItems as $favorite)
                @php($product = $favorite->product)
                @continue(!$product)

                <div class="col-md-6 col-xl-4" data-favorite-item data-product-id="{{ $product->MaSP }}">
                    <x-product-card
                        :product="$product"
                        card-class="profile-favorite-card"
                        media-class="profile-favorite-card__media"
                        body-class="profile-favorite-card__body"
                        icon-size="2.5rem"
                        :show-cart="$product->stock_quantity > 0"
                    >
                        <x-slot:topAction>
                            <button type="button"
                                    class="profile-favorite-card__remove store-product-card__icon-action"
                                    data-profile-favorite-remove
                                    data-product-id="{{ $product->MaSP }}"
                                    aria-label="{{ __('messages.profile.remove_favorite') }}: {{ $product->name }}"
                                    title="{{ __('messages.profile.remove_favorite') }}">
                                <i class="bi bi-heart-fill"></i>
                            </button>
                        </x-slot:topAction>
                    </x-product-card>
                </div>
            @endforeach
        </div>

        @if($favoriteItems->hasPages())
            <div class="mt-4 d-flex justify-content-center">
                {{ $favoriteItems->fragment('favorites')->links() }}
            </div>
        @endif
    @else
        <div class="profile-favorites-empty text-center py-5">
            <div class="profile-favorites-empty__icon d-inline-flex align-items-center justify-content-center rounded-circle mb-3">
                <i class="bi bi-heart"></i>
            </div>
            <h6 class="fw-bold text-gray-800 mb-2">{{ __('messages.profile.favorites_empty_title') }}</h6>
            <p class="text-gray-500 mb-4">{{ __('messages.profile.favorites_empty_desc') }}</p>
            <a href="{{ route('products.index') }}" class="btn btn-glass-primary rounded-pill px-4">
                <i class="bi bi-search me-2"></i>{{ __('messages.profile.shop_now') }}
            </a>
        </div>
    @endif
</div>

@once
    @push('styles')
    <style>
    .profile-favorites__header-icon {
        color: #be123c;
        background: linear-gradient(135deg, rgba(255, 228, 230, 0.95), rgba(236, 253, 245, 0.9));
        border: 1px solid rgba(244, 63, 94, 0.14);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
    }

    .profile-favorites__count {
        color: #047857;
        background: rgba(236, 253, 245, 0.9);
        border: 1px solid rgba(16, 185, 129, 0.18);
        font-weight: 700;
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .profile-favorites-empty__icon {
        width: 82px;
        height: 82px;
        color: #be123c;
        background: linear-gradient(135deg, rgba(255, 228, 230, 0.96), rgba(236, 253, 245, 0.9));
        border: 1px solid rgba(244, 63, 94, 0.14);
        font-size: 2rem;
    }

    </style>
    @endpush
@endonce
