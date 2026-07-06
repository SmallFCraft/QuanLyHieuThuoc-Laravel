@extends('layouts.app')

@section('title', __('messages.product_page_title'))

@push('styles')
<style>
    .catalog-sidebar-card {
        top: 100px;
    }

    .catalog-filter-list {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .catalog-filter-link {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        min-height: 56px;
        padding: 0.85rem 1rem;
        border-radius: 1rem;
        border: 1px solid transparent;
        background: rgba(255, 255, 255, 0.9);
        color: #1f2937;
        text-decoration: none;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease, color 0.18s ease, border-color 0.18s ease;
    }

    .catalog-filter-link:hover,
    .catalog-filter-link:focus {
        color: #065f46;
        background: linear-gradient(135deg, rgba(236, 253, 245, 0.96) 0%, rgba(209, 250, 229, 0.72) 100%);
        border-color: rgba(16, 185, 129, 0.18);
        box-shadow: 0 14px 28px rgba(16, 185, 129, 0.12);
        transform: translateY(-1px);
    }

    .catalog-filter-link:hover .catalog-filter-label,
    .catalog-filter-link:focus .catalog-filter-label {
        color: #065f46;
    }

    .catalog-filter-link.is-active {
        color: #ffffff;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        border-color: transparent;
        box-shadow: 0 16px 32px rgba(5, 150, 105, 0.24);
    }

    .catalog-filter-link.is-active .catalog-filter-label {
        color: #ffffff;
    }

    .catalog-filter-link.is-active .catalog-filter-count {
        background: rgba(255, 255, 255, 0.92);
        color: #047857;
    }

    .catalog-filter-link.is-active .catalog-filter-icon {
        background: rgba(255, 255, 255, 0.18);
        color: #ffffff;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.16);
    }

    .catalog-filter-icon {
        width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border-radius: 14px;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.16) 0%, rgba(16, 185, 129, 0.08) 100%);
        color: #059669;
        box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.08);
    }

    .catalog-filter-icon i {
        font-size: 1rem;
    }

    .catalog-filter-label {
        flex: 1;
        min-width: 0;
        font-size: 1rem;
        font-weight: 600;
        line-height: 1.35;
        color: #1f2937;
        transition: color 0.18s ease;
    }

    .catalog-filter-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2rem;
        height: 2rem;
        padding: 0 0.65rem;
        border-radius: 999px;
        background: #f3f4f6;
        color: #4b5563;
        font-size: 0.85rem;
        font-weight: 700;
        line-height: 1;
        flex-shrink: 0;
        transition: background-color 0.18s ease, color 0.18s ease;
    }

    .catalog-filter-link:hover .catalog-filter-count,
    .catalog-filter-link:focus .catalog-filter-count {
        background: rgba(16, 185, 129, 0.12);
        color: #047857;
    }

    .catalog-filter-link.is-active:hover .catalog-filter-count,
    .catalog-filter-link.is-active:focus .catalog-filter-count {
        background: rgba(255, 255, 255, 0.92);
        color: #047857;
    }

    .catalog-filter-link.is-all .catalog-filter-label {
        font-weight: 700;
    }

    @media (max-width: 991px) {
        .catalog-sidebar-card {
            top: 0;
            position: static !important;
        }

        .catalog-filter-link {
            min-height: 52px;
            padding: 0.75rem 0.9rem;
            gap: 0.75rem;
        }

        .catalog-filter-icon {
            width: 36px;
            height: 36px;
            border-radius: 12px;
        }

        .catalog-filter-label {
            font-size: 0.95rem;
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
                <span><i class="bi bi-capsule-pill"></i> {{ $products->total() }} {{ mb_strtolower(__('messages.products')) }}</span>
                <span><i class="bi bi-shield-check"></i> Chính hãng</span>
                <span><i class="bi bi-lightning-charge"></i> Lọc nhanh</span>
            </div>
            <!-- Search -->
            <form action="{{ route('products.search') }}" method="GET" class="catalog-search-form d-flex gap-2" style="max-width: 400px; width: 100%;">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 rounded-start-pill text-gray-400"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" placeholder="{{ __('messages.search_product_placeholder') }}" 
                           value="{{ $searchTerm ?? '' }}"
                           class="form-control border-start-0 rounded-end-pill shadow-none ps-0"
                           autocomplete="off"
                           style="border-color: #e5e7eb;">
                </div>
                <button type="submit" class="btn btn-glass-success rounded-pill px-4 shadow-sm fw-medium border-0">
                    {{ __('messages.search') }}
                </button>
            </form>
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
                <div class="glass-card catalog-sidebar-card rounded-4 p-4 border-0 mb-4 position-sticky">
                    <h5 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="bi bi-funnel text-emerald-600"></i> {{ __('messages.categories') }}
                    </h5>
                    <div class="catalog-filter-list">
                        <a href="{{ route('products.index') }}" 
                           class="catalog-filter-link is-all {{ $activeCategoryId === null ? 'is-active' : '' }}">
                            <span class="catalog-filter-icon" aria-hidden="true">
                                <i class="bi bi-grid-3x3-gap-fill"></i>
                            </span>
                            <span class="catalog-filter-label">{{ __('messages.all') }}</span>
                        </a>
                        @foreach($categories as $cat)
                            @php
                                $rawIcon = trim((string) ($cat->icon ?? ''));
                                $iconName = \Illuminate\Support\Str::startsWith($rawIcon, 'bi-')
                                    ? \Illuminate\Support\Str::after($rawIcon, 'bi-')
                                    : ($rawIcon !== '' && !\Illuminate\Support\Str::startsWith($rawIcon, 'fa-') ? $rawIcon : 'tag');
                                $isActiveCategory = $activeCategoryId !== null && (string) $activeCategoryId === (string) $cat->MaDM;
                            @endphp
                            <a href="{{ route('products.index', ['category' => $cat->MaDM]) }}"
                               class="catalog-filter-link {{ $isActiveCategory ? 'is-active' : '' }}"
                               @if($isActiveCategory) aria-current="page" @endif>
                                <span class="catalog-filter-icon" aria-hidden="true">
                                    <i class="bi bi-{{ $iconName }}"></i>
                                </span>
                                <span class="catalog-filter-label">{{ $cat->name }}</span>
                                <span class="catalog-filter-count">{{ $cat->products_count }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Products Grid -->
            <div class="col-lg-9">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <p class="text-gray-500 mb-0 small">
                        {!! __('messages.showing_results', ['count' => '<strong class="text-gray-800">' . $products->count() . '</strong>', 'total' => '<strong class="text-gray-800">' . $products->total() . '</strong>']) !!}
                    </p>
                </div>

                <div class="row g-4">
                    @forelse($products as $product)
                        <div class="col-sm-6 col-xl-4">
                            <x-product-card
                                :product="$product"
                                card-class="card border-0 rounded-4 shadow-sm overflow-hidden catalog-product-card bg-white"
                                media-class="catalog-product-media bg-gray-50"
                                body-class="card-body p-4"
                            />
                        </div>
                    @empty
                        <div class="col-12">
                            <div class="glass-card text-center py-5 rounded-4 border-0">
                                <div class="bg-emerald-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px; height:80px;">
                                    <i class="bi bi-search text-emerald-500 fs-1"></i>
                                </div>
                                <h5 class="fw-bold text-gray-700">{{ __('messages.no_product_found') }}</h5>
                                <p class="text-muted mb-0">{{ __('messages.try_changing_filters') }}</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                <!-- Pagination -->
                <div class="mt-5 d-flex justify-content-center">
                    {{ $products->links() }}
                </div>
            </div>
        </div>
    </div>
</section>
</div>
@endsection
