@extends('layouts.app')

@section('title', __('messages.product_page_title'))

@push('styles')
<style>
    /* The crawled catalog carries 51 categories. Listed flat, the panel ran
       3,898px tall — on mobile that is 3,898px of filter above the first product.
       So: the list scrolls inside a fixed frame, and collapses on small screens. */
    .catalog-sidebar-card {
        top: 100px;
    }

    .catalog-filter__head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.8rem;
    }

    .catalog-filter__title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: #111827;
    }

    .catalog-filter__total {
        flex-shrink: 0;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #6b7280;
    }

    .catalog-filter__search {
        position: relative;
        margin-bottom: 0.6rem;
    }

    .catalog-filter__search i {
        position: absolute;
        top: 50%;
        left: 0.75rem;
        transform: translateY(-50%);
        color: #059669;
        font-size: 0.9rem;
        pointer-events: none;
    }

    .catalog-filter__search input {
        width: 100%;
        min-height: 42px;
        padding: 0.45rem 0.7rem 0.45rem 2.2rem;
        border-radius: 0.7rem;
        border: 1px solid rgba(21, 128, 61, 0.16);
        background: rgba(255, 255, 255, 0.94);
        font-size: 0.9rem;
        color: #1f2937;
    }

    .catalog-filter__search input:focus {
        outline: none;
        border-color: rgba(16, 185, 129, 0.5);
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.16);
    }
    .catalog-filter-list {
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
        max-height: calc(100vh - 268px);
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: rgba(16, 185, 129, 0.34) transparent;
        margin-right: -0.35rem;
        padding-right: 0.35rem;
    }

    .catalog-filter-list::-webkit-scrollbar {
        width: 6px;
    }

    .catalog-filter-list::-webkit-scrollbar-thumb {
        border-radius: 999px;
        background: rgba(16, 185, 129, 0.34);
    }

    /* Each row is its own stacking context so the stock bar can sit behind the
       label without an extra wrapper element. */
    .catalog-filter-link {
        position: relative;
        isolation: isolate;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        min-height: 38px;
        padding: 0.4rem 0.6rem;
        border-radius: 0.55rem;
        border: 1px solid transparent;
        color: #1f2937;
        text-decoration: none;
        transition: background-color 0.16s ease, color 0.16s ease, border-color 0.16s ease;
    }

    /* Stock depth, drawn as the row's own background: 263 products reads as a full
       bar, 1 product as a sliver. Replaces 51 identical fallback icons — not one
       crawled category carries an icon of its own. */
    .catalog-filter-link::before {
        content: '';
        position: absolute;
        inset: 1px 1px 1px 1px;
        z-index: -1;
        width: var(--depth, 0%);
        border-radius: 0.5rem;
        background: linear-gradient(90deg, rgba(16, 185, 129, 0.19), rgba(16, 185, 129, 0.07));
        transition: width 0.2s ease, opacity 0.16s ease;
    }

    .catalog-filter-link:hover,
    .catalog-filter-link:focus-visible {
        color: #065f46;
        background: rgba(236, 253, 245, 0.85);
        border-color: rgba(16, 185, 129, 0.22);
    }

    .catalog-filter-link.is-active {
        color: #ffffff;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        box-shadow: 0 8px 18px rgba(5, 150, 105, 0.22);
    }

    .catalog-filter-link.is-active::before {
        opacity: 0;
    }

    .catalog-filter-label {
        flex: 1;
        min-width: 0;
        font-size: 0.9rem;
        font-weight: 600;
        line-height: 1.3;
    }

    .catalog-filter-count {
        flex-shrink: 0;
        font-size: 0.78rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        color: #6b7280;
    }

    .catalog-filter-link:hover .catalog-filter-count,
    .catalog-filter-link:focus-visible .catalog-filter-count {
        color: #047857;
    }

    .catalog-filter-link.is-active .catalog-filter-count {
        color: rgba(255, 255, 255, 0.92);
    }

    .catalog-filter-link.is-all {
        margin-bottom: 0.3rem;
        padding-bottom: 0.55rem;
        border-bottom: 1px solid rgba(21, 128, 61, 0.12);
        border-radius: 0.55rem;
        font-weight: 700;
    }

    .catalog-filter-link.is-all .catalog-filter-label {
        font-weight: 700;
    }

    .catalog-filter__empty {
        display: none;
        padding: 0.9rem 0.6rem;
        font-size: 0.86rem;
        color: #6b7280;
    }

    .catalog-filter-list.is-empty .catalog-filter__empty {
        display: block;
    }

    .catalog-filter-link[hidden] {
        display: none;
    }
    /* Mobile: the panel collapses to a single row. A <details> element carries the
       open/close state natively, so the filter works with JavaScript off. */
    .catalog-filter__toggle {
        display: none;
    }

    /* Desktop has the room, so the panel is always expanded and <details> carries no
       state there — the summary is hidden, leaving nothing to toggle it. Engines gate
       closed content two ways: newer ones on ::details-content, older ones on the
       children, so both are unlocked. */
    @media (min-width: 992px) {
        .catalog-filter > .catalog-filter__body {
            display: block;
        }

        .catalog-filter::details-content {
            content-visibility: visible;
            block-size: auto;
        }
    }

    /* 992-1199px: col-lg-3 chỉ còn 192px, padding 1.5rem của .p-4 ăn 48px nên ô
       lọc còn 120px và placeholder bị cắt. */
    @media (min-width: 992px) and (max-width: 1199.98px) {
        .catalog-sidebar-card {
            padding: 0.85rem !important;
        }

        .catalog-filter__search i {
            left: 0.6rem;
        }

        .catalog-filter__search input {
            padding-left: 2rem;
            padding-right: 0.5rem;
        }
    }

    @media (max-width: 991px) {
        .catalog-sidebar-card {
            top: 0;
            position: static !important;
            padding: 0.85rem !important;
        }

        .catalog-filter__toggle {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            min-height: 48px;
            padding: 0.4rem 0.2rem;
            cursor: pointer;
            list-style: none;
            font-weight: 700;
            color: #111827;
        }

        .catalog-filter__toggle::-webkit-details-marker {
            display: none;
        }

        .catalog-filter__toggle-current {
            flex: 1;
            min-width: 0;
            overflow: hidden;
            font-size: 0.9rem;
            font-weight: 600;
            color: #047857;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .catalog-filter__toggle-chevron {
            flex-shrink: 0;
            color: #059669;
            transition: transform 0.18s ease;
        }

        .catalog-filter[open] .catalog-filter__toggle-chevron {
            transform: rotate(180deg);
        }

        .catalog-filter__head {
            display: none;
        }

        .catalog-filter__body {
            padding-top: 0.7rem;
            margin-top: 0.5rem;
            border-top: 1px solid rgba(21, 128, 61, 0.12);
        }

        /* Two columns: 51 rows in one column still meant a long scroll. */
        .catalog-filter-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.2rem 0.5rem;
            max-height: 52vh;
        }

        .catalog-filter-link.is-all,
        .catalog-filter__empty {
            grid-column: 1 / -1;
        }

        .catalog-filter-label {
            font-size: 0.84rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .catalog-filter-link,
        .catalog-filter-link::before,
        .catalog-filter__toggle-chevron {
            transition: none;
        }
    }
</style>
@endpush

@section('content')
<div class="public-catalog-page">
<!-- Page Header -->
<section class="catalog-hero py-4">
    <div class="container">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
            <div>
                <h1 class="fw-bold text-gray-900 mb-1">{{ __('messages.products') }}</h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0 small">
                        <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none">{{ __('messages.home') }}</a></li>
                        <li class="breadcrumb-item active" aria-current="page">{{ __('messages.products') }}</li>
                    </ol>
                </nav>
            </div>
            <div class="catalog-hero-metrics order-md-2">
                <span><i class="bi bi-capsule-pill"></i> <span data-catalog-total>{{ $products->total() }}</span> {{ mb_strtolower(__('messages.products')) }}</span>
                <span><i class="bi bi-shield-check"></i> Chính hãng</span>
                <span><i class="bi bi-lightning-charge"></i> Lọc nhanh</span>
            </div>
        </div>
    </div>
</section>

<section class="catalog-shell py-5">
    <div class="container">
        @php
            $activeCategoryId = request('category');
            $activeCategoryRoute = request()->route('MaDM');

            if ($activeCategoryId === null && $activeCategoryRoute !== null) {
                $activeCategoryId = is_object($activeCategoryRoute)
                    ? ($activeCategoryRoute->MaDM ?? null)
                    : $activeCategoryRoute;
            }
        @endphp
        <div class="row g-4">
            <!-- Sidebar Filters -->
            <div class="col-lg-3">
                @php
                    $activeCategory = $categories->firstWhere(
                        fn ($cat) => $activeCategoryId !== null && (string) $activeCategoryId === (string) $cat->MaDM
                    );
                    $maxCategoryCount = max(1, (int) $categories->max('products_count'));
                    // Summed from the counts already loaded, not $products->total(): that
                    // one reflects the active filter, so the "all" row read 226 while
                    // sitting next to the category that holds those same 226.
                    $catalogTotal = (int) $categories->sum('products_count');
                @endphp
                <details class="glass-card catalog-sidebar-card catalog-filter rounded-4 p-4 border-0 mb-4 position-sticky" data-catalog-filter>
                    <summary class="catalog-filter__toggle">
                        <i class="bi bi-funnel text-emerald-600" aria-hidden="true"></i>
                        <span>{{ __('messages.categories') }}</span>
                        <span class="catalog-filter__toggle-current">{{ $activeCategory->name ?? __('messages.all') }}</span>
                        <i class="bi bi-chevron-down catalog-filter__toggle-chevron" aria-hidden="true"></i>
                    </summary>

                    <div class="catalog-filter__body">
                        <div class="catalog-filter__head">
                            <h2 class="catalog-filter__title">
                                <i class="bi bi-funnel text-emerald-600" aria-hidden="true"></i>{{ __('messages.categories') }}
                            </h2>
                            <span class="catalog-filter__total">{{ $categories->count() }}</span>
                        </div>

                        <div class="catalog-filter__search">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <label for="catalog-filter-search" class="visually-hidden">{{ __('messages.filter_categories') }}</label>
                            <input type="search" id="catalog-filter-search" autocomplete="off"
                                   placeholder="{{ __('messages.filter_categories') }}"
                                   data-catalog-filter-search>
                        </div>

                        <div class="catalog-filter-list" data-catalog-filter-list>
                            <a href="{{ route('products.index') }}"
                               class="catalog-filter-link is-all {{ $activeCategoryId === null ? 'is-active' : '' }}"
                               @if($activeCategoryId === null) aria-current="page" @endif>
                                <span class="catalog-filter-label">{{ __('messages.all') }}</span>
                                <span class="catalog-filter-count">{{ $catalogTotal }}</span>
                            </a>
                            @foreach($categories as $cat)
                                @php
                                    $isActiveCategory = $activeCategoryId !== null && (string) $activeCategoryId === (string) $cat->MaDM;
                                    $depth = round($cat->products_count / $maxCategoryCount * 100);
                                @endphp
                                <a href="{{ route('products.index', ['category' => $cat->MaDM]) }}"
                                   class="catalog-filter-link {{ $isActiveCategory ? 'is-active' : '' }}"
                                   style="--depth: {{ $depth }}%"
                                   data-folded="{{ \App\Support\VietnameseText::fold($cat->name) }}"
                                   @if($isActiveCategory) aria-current="page" @endif>
                                    <span class="catalog-filter-label">{{ $cat->name }}@if($loop->index < 3)<i class="bi bi-fire category-flame" aria-hidden="true"></i>@endif</span>
                                    <span class="catalog-filter-count">{{ $cat->products_count }}</span>
                                </a>
                            @endforeach
                            <p class="catalog-filter__empty" data-catalog-filter-empty>{{ __('messages.no_category_match') }}</p>
                        </div>
                    </div>
                </details>
            </div>

            <!-- Products Grid -->
            <div class="col-lg-9">
                {{-- data-catalog-results: catalog-async.js thay đúng khối này khi người
                     dùng chọn danh mục hoặc đổi trang, nên vị trí cuộn không đổi. --}}
                <div data-catalog-results aria-live="polite">
                    @include('products.partials.results')
                </div>
            </div>
        </div>
    </div>
</section>
</div>
@endsection
