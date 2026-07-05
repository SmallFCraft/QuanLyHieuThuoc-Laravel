@props([
    'product',
    'imageFit' => 'cover',
    'showCart' => true,
    'showDetailAction' => true,
    'showFavorite' => true,
    'showMeta' => true,
    'showComparePrice' => false,
    'cardClass' => '',
    'mediaClass' => '',
    'bodyClass' => '',
    'imageClass' => '',
    'iconClass' => 'text-secondary opacity-25',
    'iconSize' => '4rem',
    'detailLabel' => null,
])

@php
    $detailUrl = route('products.show', $product->MaSP);
    $detailText = $detailLabel ?? __('messages.view_details');
    $fitClass = $imageFit === 'contain' ? 'object-fit-contain p-2' : 'object-fit-cover';
    $categoryName = $product->category?->name ?? __('messages.product_category');
    $price = $product->formatted_price ?? format_currency_vi($product->price_vnd);
    $canShowFavorite = $showFavorite && auth()->check();
@endphp

<div {{ $attributes->class(['product-card store-product-card h-100', $cardClass]) }}>
    <div class="store-product-card__media {{ $mediaClass }}" data-product-image-container>
        <a href="{{ $detailUrl }}"
           class="store-product-card__image-link"
           aria-label="{{ $detailText }}: {{ $product->name }}">
            <x-product-image
                :src="$product->image_url"
                :alt="$product->name"
                class="w-100 h-100 {{ $fitClass }} store-product-card__image {{ $imageClass }}"
                :icon-class="$iconClass"
                :icon-size="$iconSize"
            />
        </a>

        @isset($badges)
            <div class="store-product-card__badges">
                {{ $badges }}
            </div>
        @endisset

        @if(isset($topAction) || $canShowFavorite)
            <div class="store-product-card__top-action">
                @isset($topAction)
                    {{ $topAction }}
                @else
                    <button type="button"
                            class="btn btn-light btn-sm store-product-card__icon-action store-product-card__favorite-action"
                            data-favorite-toggle
                            data-product-id="{{ $product->MaSP }}"
                            data-favorite-label="{{ __('messages.save_favorite') }}"
                            data-saved-label="{{ __('messages.saved_favorite') }}"
                            aria-pressed="false"
                            aria-label="{{ __('messages.save_favorite') }}"
                            title="{{ __('messages.save_favorite') }}">
                        <i class="bi bi-heart"></i>
                    </button>
                @endisset
            </div>
        @endif
    </div>

    <div class="store-product-card__body {{ $bodyClass }}">
        <a href="{{ $detailUrl }}" class="text-decoration-none">
            <h3 class="store-product-card__title" title="{{ $product->name }}">{{ $product->name }}</h3>
        </a>
        <p class="store-product-card__category">{{ $categoryName }}</p>

        @if(isset($meta) || $showMeta)
            <div class="store-product-card__meta">
                @isset($meta)
                    {{ $meta }}
                @else
                    <span class="{{ $product->stock_quantity > 0 ? 'is-available' : 'is-unavailable' }}">
                        <i class="bi {{ $product->stock_quantity > 0 ? 'bi-check-circle' : 'bi-x-circle' }}"></i>
                        {{ $product->stock_quantity > 0 ? __('messages.profile.available_now') : __('messages.profile.unavailable_now') }}
                    </span>
                    <span>
                        <i class="bi bi-calendar2-check"></i>
                        {{ __('messages.profile.expires_on') }} {{ $product->formatted_expiration_date }}
                    </span>
                @endisset
            </div>
        @endif

        <div class="store-product-card__footer">
            <div class="store-product-card__price-wrap">
                @if($showComparePrice)
                    <span class="store-product-card__compare-price">
                        {{ format_currency_vi($product->price_vnd * 1.1) }}
                    </span>
                @endif
                <span class="store-product-card__price">{{ $price }}</span>
            </div>

            <div class="store-product-card__actions">
                @isset($footerActions)
                    {{ $footerActions }}
                @endisset

                @if($showDetailAction)
                    <a href="{{ $detailUrl }}"
                       class="btn btn-light btn-sm store-product-card__icon-action store-product-card__detail-action"
                       aria-label="{{ $detailText }}: {{ $product->name }}"
                       title="{{ $detailText }}">
                        <i class="bi bi-eye"></i>
                    </a>
                @endif

                @if($showCart)
                    @if($product->stock_quantity > 0)
                        <form action="{{ route('cart.add') }}" method="POST" class="d-inline js-add-to-cart-form">
                            @csrf
                            <input type="hidden" name="MaSP" value="{{ $product->MaSP }}">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit"
                                    class="btn store-product-card__icon-action store-product-card__cart-action"
                                    aria-label="{{ __('messages.add_to_cart') }}: {{ $product->name }}"
                                    title="{{ __('messages.add_to_cart') }}">
                                <i class="bi bi-cart-plus"></i>
                            </button>
                        </form>
                    @else
                        <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2">
                            {{ __('messages.out_of_stock') }}
                        </span>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
