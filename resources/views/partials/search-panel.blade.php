{{--
    Du lieu cho panel tim kiem, nhung mot lan moi trang.

    <template> chu khong phai <div>: noi dung khong duoc render ra layout — no la
    nguon de resources/js/search-autocomplete.js clone vao panel khi khach focus vao
    o tim kiem. Khong co JS thi <template> khong hien gi va o tim kiem van submit
    binh thuong.

    Nhung thay vi goi them mot endpoint: panel mo la hien ngay khong cho mang, khong
    them mot bucket throttle, va moi chu do khach go (tu khoa xu huong) da duoc Blade
    escape o day thay vi noi chuoi trong JS.
--}}
<template id="search-panel-data">
    @if($searchTrending->isNotEmpty())
        <section class="search-panel-section" data-panel-trending>
            <h3 class="search-panel-heading">
                <i class="bi bi-fire text-danger" aria-hidden="true"></i>{{ __('messages.search_trending') }}
            </h3>
            <div class="search-panel-pills">
                @foreach($searchTrending as $term)
                    <a class="search-panel-pill" href="{{ route('san-pham.tim-kiem', ['q' => $term]) }}" data-search-term="{{ $term }}">{{ $term }}</a>
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
                    <a class="search-panel-pill search-panel-pill--muted" href="{{ route('san-pham.index', ['category' => $category->ma_dm]) }}">{{ $category->ten }}</a>
                @endforeach
            </div>
        </section>
    @endif

    @if($searchPanelProducts->isNotEmpty())
        <section class="search-panel-section">
            <h3 class="search-panel-heading">
                <i class="bi bi-star-fill text-warning" aria-hidden="true"></i>{{ __('messages.search_most_viewed') }}
            </h3>
            @foreach($searchPanelProducts as $sanPham)
                {{-- suggestion-item: dung lai class ma dieu huong ban phim cua panel
                     da bat, nen mui ten len/xuong di qua ca ba san pham nay. --}}
                <a class="suggestion-item product-item" href="{{ route('san-pham.show', $sanPham->ma_sp) }}">
                    <div class="product-image" data-product-image-container style="position: relative;">
                        <x-product-image :src="$sanPham->image_url" :alt="$sanPham->ten" icon-size="1.5rem" />
                    </div>
                    <div class="product-info">
                        <div class="product-name">{{ $sanPham->ten }}</div>
                        <div class="product-meta">
                            <span class="product-price">{{ $sanPham->formatted_price }}</span>
                        </div>
                    </div>
                </a>
            @endforeach
        </section>
    @endif
</template>
