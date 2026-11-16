@props([
    'sanPham',
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
    $detailUrl = route('san-pham.show', $sanPham->ma_sp);
    $detailText = $detailLabel ?? __('messages.view_details');
    $fitClass = $imageFit === 'contain' ? 'object-fit-contain p-2' : 'object-fit-cover';
    $categoryName = $sanPham->danhMuc?->ten ?? __('messages.product_category');
    $price = $sanPham->formatted_price ?? format_currency_vi($sanPham->price_vnd);
    $canShowFavorite = $showFavorite && auth()->check();
@endphp

<div {{ $attributes->class(['product-card store-product-card h-100', $cardClass]) }}>
    <div class="store-product-card__media {{ $mediaClass }}" data-product-image-container>
        <a href="{{ $detailUrl }}"
           class="store-product-card__image-link"
           aria-label="{{ $detailText }}: {{ $sanPham->ten }}">
            <x-product-image
                :src="$sanPham->image_url"
                :alt="$sanPham->ten"
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
                            data-product-id="{{ $sanPham->ma_sp }}"
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
            <h3 class="store-product-card__title" title="{{ $sanPham->ten }}">{{ $sanPham->ten }}</h3>
        </a>
        <p class="store-product-card__category">{{ $categoryName }}</p>

        @if(isset($meta) || $showMeta)
            <div class="store-product-card__meta">
                @isset($meta)
                    {{ $meta }}
                @else
                    {{-- Availability only. Exact stock counts and batch expiry dates are
                         internal figures; pharmacy storefronts state neither. --}}
                    <span class="{{ $sanPham->so_luong_ton > 0 ? 'is-available' : 'is-unavailable' }}">
                        <i class="bi {{ $sanPham->so_luong_ton > 0 ? 'bi-check-circle' : 'bi-x-circle' }}"></i>
                        {{ $sanPham->so_luong_ton > 0 ? __('messages.in_stock') : __('messages.out_of_stock') }}
                    </span>
                    @if($sanPham->shelf_life_text)
                        <span>
                            <i class="bi bi-calendar2-check"></i>
                            {{ __('messages.shelf_life') }} {{ $sanPham->shelf_life_text }}
                        </span>
                    @endif
                @endisset
            </div>
        @endif

        <div class="store-product-card__footer">
            <div class="store-product-card__price-wrap">
                @if($showComparePrice)
                    <span class="store-product-card__compare-price">
                        {{ format_currency_vi($sanPham->price_vnd * 1.1) }}
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
                       aria-label="{{ $detailText }}: {{ $sanPham->ten }}"
                       title="{{ $detailText }}">
                        <i class="bi bi-eye"></i>
                    </a>
                @endif

                @if($showCart)
                    @if($sanPham->so_luong_ton > 0)
                        <form action="{{ route('gio-hang.them') }}" method="POST" class="d-inline js-add-to-cart-form">
                            @csrf
                            <input type="hidden" name="MaSP" value="{{ $sanPham->ma_sp }}">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit"
                                    class="btn store-product-card__icon-action store-product-card__cart-action"
                                    aria-label="{{ __('messages.add_to_cart') }}: {{ $sanPham->ten }}"
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
