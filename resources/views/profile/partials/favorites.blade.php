<div class="profile-card" data-profile-favorites>
    <div class="profile-card__head">
        <span class="profile-card__icon profile-card__icon--rose"><i class="bi bi-heart-fill"></i></span>
        <div class="flex-grow-1">
            <h2 class="profile-card__title">{{ __('messages.profile.favorites_title') }}</h2>
            <p class="profile-card__subtitle">{{ __('messages.profile.favorites_desc') }}</p>
        </div>
        <span class="profile-favorites__count rounded-pill px-3 py-2">
            <i class="bi bi-heart-pulse me-1" aria-hidden="true"></i>
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
            <a href="{{ route('products.index') }}" class="btn profile-btn-primary">
                <i class="bi bi-search me-2" aria-hidden="true"></i>{{ __('messages.profile.shop_now') }}
            </a>
        </div>
    @endif
</div>
