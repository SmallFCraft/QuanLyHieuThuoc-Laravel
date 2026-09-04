{{--
    Dữ liệu cho panel tìm kiếm, nhúng một lần mỗi trang.

    <template> chứ không phải <div>: nội dung không được render ra layout — nó là
    nguồn để resources/js/search-autocomplete.js clone vào panel khi khách focus vào
    ô tìm kiếm. Không có JS thì <template> không hiện gì và ô tìm kiếm vẫn submit
    bình thường.

    Nhúng thay vì gọi thêm một endpoint: panel mở là hiện ngay không chờ mạng, không
    thêm một bucket throttle, và mọi chữ do khách gõ (từ khoá xu hướng) đã được Blade
    escape ở đây thay vì nối chuỗi trong JS.
--}}
<template id="search-panel-data">
    @if($searchTrending->isNotEmpty())
        <section class="search-panel-section" data-panel-trending>
            <h3 class="search-panel-heading">
                <i class="bi bi-fire text-danger" aria-hidden="true"></i>{{ __('messages.search_trending') }}
            </h3>
            <div class="search-panel-pills">
                @foreach($searchTrending as $term)
                    <a class="search-panel-pill" href="{{ route('products.search', ['q' => $term]) }}" data-search-term="{{ $term }}">{{ $term }}</a>
                @endforeach
            </div>
        </section>
    @endif

    @if($navCategories->isNotEmpty())
        <section class="search-panel-section">
            <h3 class="search-panel-heading">
                <i class="bi bi-grid text-success" aria-hidden="true"></i>{{ __('messages.search_top_categories') }}
            </h3>
            <div class="search-panel-pills">
                @foreach($navCategories->take(4) as $category)
                    <a class="search-panel-pill search-panel-pill--muted" href="{{ route('products.index', ['category' => $category->MaDM]) }}">{{ $category->name }}</a>
                @endforeach
            </div>
        </section>
    @endif

    @if($searchPanelProducts->isNotEmpty())
        <section class="search-panel-section">
            <h3 class="search-panel-heading">
                <i class="bi bi-star-fill text-warning" aria-hidden="true"></i>{{ __('messages.search_most_viewed') }}
            </h3>
            @foreach($searchPanelProducts as $product)
                {{-- suggestion-item: dùng lại class mà điều hướng bàn phím của panel
                     đã bắt, nên mũi tên lên/xuống đi qua cả ba sản phẩm này. --}}
                <a class="suggestion-item product-item" href="{{ route('products.show', $product->MaSP) }}">
                    <div class="product-image" data-product-image-container style="position: relative;">
                        <x-product-image :src="$product->image_url" :alt="$product->name" icon-size="1.5rem" />
                    </div>
                    <div class="product-info">
                        <div class="product-name">{{ $product->name }}</div>
                        <div class="product-meta">
                            <span class="product-price">{{ $product->formatted_price }}</span>
                        </div>
                    </div>
                </a>
            @endforeach
        </section>
    @endif
</template>
