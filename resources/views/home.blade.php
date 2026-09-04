@extends('layouts.app')

@section('title', __('messages.app_name') . ' - ' . __('messages.home_subtitle'))

@section('content')
<div class="home-page">
    <!-- Hero -->
    <section class="home-hero-wrap mb-4 mb-md-5">
        <div class="position-absolute top-0 start-0 w-100 h-100 z-n1" style="background: linear-gradient(165deg, rgba(240,253,244,0.88) 0%, rgba(255,255,255,0.72) 42%, rgba(236,253,245,0.85) 100%);"></div>
        <div class="home-hero-blob home-hero-blob--1" aria-hidden="true"></div>
        <div class="home-hero-blob home-hero-blob--2" aria-hidden="true"></div>
        <div class="home-hero-blob home-hero-blob--3 d-none d-md-block" aria-hidden="true"></div>

        <div class="container position-relative py-4 py-md-5 my-lg-4">
            <div class="row align-items-center g-4 g-lg-5">
                <div class="col-lg-6 text-center text-lg-start fade-in">
                    <span class="badge rounded-pill px-3 py-2 fw-medium border mb-3" style="background: rgba(255,255,255,0.65); backdrop-filter: blur(8px); color: #047857; border-color: rgba(16,185,129,0.25) !important;">
                        <i class="bi bi-patch-check-fill text-success me-1"></i> {{ __('messages.home_hero_badge') }}
                    </span>
                    <h1 class="home-title fw-bold mb-3 text-dark">
                        {{ __('messages.home_hero_title1') }}<br>
                        <span class="text-transparent bg-clip-text d-inline-block" style="background-image: linear-gradient(90deg, #10b981, #34d399); -webkit-background-clip: text; background-clip: text;">{{ __('messages.home_hero_title2') }}</span>
                    </h1>
                    <p class="home-lead mb-4 text-secondary mx-auto mx-lg-0" style="max-width: 32rem;">
                        {{ __('messages.home_hero_desc') }}
                    </p>

                    <div class="d-flex flex-column flex-sm-row gap-2 gap-sm-3 justify-content-center justify-content-lg-start mb-4">
                        <a href="{{ route('products.index') }}" class="btn btn-glass-success btn-lg px-4 px-sm-5 py-2 py-sm-3 rounded-pill fw-semibold shadow-sm d-inline-flex align-items-center justify-content-center">
                            <i class="bi bi-bag-heart me-2"></i>{{ __('messages.home_shop_now') }}
                        </a>
                        <a href="{{ route('contact') }}" class="btn btn-lg px-4 px-sm-5 py-2 py-sm-3 rounded-pill fw-semibold d-inline-flex align-items-center justify-content-center home-glass-panel text-success border-0">
                            <i class="bi bi-chat-dots me-2"></i>{{ __('messages.home_get_advice') }}
                        </a>
                    </div>

                    <div class="d-flex flex-wrap justify-content-center justify-content-lg-start gap-3 gap-md-4 pt-3 home-trust-strip border-top" style="border-color: rgba(16,185,129,0.15) !important;">
                        <div class="d-flex align-items-center gap-2 text-secondary">
                            <i class="bi bi-truck text-success fs-5"></i>
                            <span>{{ __('messages.home_fast_delivery') }}</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-secondary">
                            <i class="bi bi-shield-check text-success fs-5"></i>
                            <span>{{ __('messages.home_genuine_products') }}</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-secondary">
                            <i class="bi bi-headset text-success fs-5"></i>
                            <span>{{ __('messages.home_support_247') }}</span>
                        </div>
                    </div>

                    <div class="home-stat-row mt-3 mt-md-4">
                        <div class="home-glass-panel home-stat-card rounded-4">
                            <span class="home-stat-value">{{ number_format($sellableProductCount, 0, ',', '.') }}</span>
                            <span class="home-stat-label">{{ __('messages.home_stat_products_label') }}</span>
                        </div>
                        <div class="home-glass-panel home-stat-card rounded-4">
                            <span class="home-stat-value">{{ number_format($sellableCategoryCount, 0, ',', '.') }}</span>
                            <span class="home-stat-label">{{ __('messages.home_stat_categories_label') }}</span>
                        </div>
                        <div class="home-glass-panel home-stat-card rounded-4">
                            <span class="home-stat-value">{{ __('messages.home_stat_fefo_value') }}</span>
                            <span class="home-stat-label">{{ __('messages.home_stat_fefo_label') }}</span>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 fade-in" style="animation-delay: 0.12s;">
                    <div class="home-visual-board">
                        <div class="home-visual-frame">
                            <img src="https://images.unsplash.com/photo-1576602976047-174e57a47881?auto=format&fit=crop&q=80&w=900&h=620" alt="Đội ngũ nhà thuốc" class="w-100 h-100 object-fit-cover">
                            <div class="home-visual-badge">
                                <span class="home-visual-pulse" aria-hidden="true"></span>
                                <div>
                                    <strong>{{ __('messages.home_support_247') }}</strong>
                                    <span>{{ __('messages.home_get_advice') }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="home-visual-feature-grid">
                            <div class="home-visual-feature">
                                <i class="bi bi-shield-check"></i>
                                <div>
                                    <strong>{{ __('messages.home_quality_badge') }}</strong>
                                    <span>{{ __('messages.home_quality_desc') }}</span>
                                </div>
                            </div>
                            <div class="home-visual-feature">
                                <i class="bi bi-truck"></i>
                                <div>
                                    <strong>{{ __('messages.home_fast_delivery') }}</strong>
                                    <span>{{ __('messages.home_genuine_products') }}</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Danh mục -->
    @if($categories->isNotEmpty())
    <section class="home-categories-section pb-4 pb-md-5 position-relative overflow-hidden" id="danh-muc">
        <div class="home-section-mesh home-section-mesh--subtle" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 mb-3 mb-md-4">
                <div>
                    <span class="text-success fw-bold text-uppercase small" style="letter-spacing: 0.06em;">{{ __('messages.home_explore') }}</span>
                    <h2 class="h4 fw-bold text-dark mb-0 mt-1">{{ __('messages.home_categories') }}</h2>
                </div>
                <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-success rounded-pill px-3 flex-shrink-0 home-section-link">
                    <span>{{ __('messages.home_view_all') }}</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
            <div class="row g-3 g-lg-4">
                @foreach($categories as $category)
                    <div class="col-6 col-md-4 col-lg-3 col-xl-2-4">
                        <a href="{{ route('products.category', $category->MaDM) }}" class="home-category-card text-decoration-none">
                            <div class="home-category-icon">
                                <i class="bi bi-{{ $category->icon ?? 'grid-fill' }}" style="font-size: 1.5rem;"></i>
                            </div>
                            <span class="fw-semibold text-truncate d-block text-dark mb-1" title="{{ $category->name }}">{{ $category->name }}</span>
                            <span class="home-category-count">{{ $category->products_count }} {{ mb_strtolower(__('messages.products')) }}</span>
                            <i class="bi bi-arrow-up-right home-category-arrow" aria-hidden="true"></i>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    <!-- Sản phẩm mới ra mắt -->
    <section class="py-4 py-md-5 position-relative overflow-hidden" id="moi-ra-mat">
        <div class="home-section-mesh" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
                <div class="text-center text-sm-start w-100 w-sm-auto">
                    <span class="text-success fw-bold text-uppercase small">{{ __('messages.home_recently_updated') }}</span>
                    <h2 class="h3 fw-bold text-dark mt-1 mb-0">{{ __('messages.home_new_products') }}</h2>
                    <p class="text-muted small mb-0 mt-1 d-none d-md-block">{{ __('messages.home_new_products_desc') }}</p>
                </div>
                <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-success rounded-pill px-3 flex-shrink-0 home-section-link">
                    <span>{{ __('messages.home_view_all') }}</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

            <div class="row g-3 g-md-4">
                @forelse($newProducts as $product)
                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                        <x-product-card
                            :product="$product"
                            card-class="home-product-card border-0"
                            media-class="home-product-media"
                            body-class="home-product-body"
                            :show-detail-action="true"
                            :show-compare-price="true"
                            detail-label="{{ __('messages.home_view_details') }}"
                        >
                            <x-slot:badges>
                                <span class="badge bg-info bg-opacity-10 text-info border border-info-subtle px-2 py-1 rounded-pill">
                                    <i class="bi bi-stars me-1"></i>{{ __('messages.home_new_badge') }}
                                </span>
                                @if($product->stock_quantity == 0)
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-2 py-1 rounded-pill">
                                        <i class="bi bi-x-circle me-1"></i>{{ __('messages.out_of_stock') }}
                                    </span>
                                @endif
                            </x-slot:badges>
                        </x-product-card>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="text-center py-5 glass-card rounded-4">
                            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; background: rgba(16,185,129,0.12);">
                                <i class="bi bi-box2 text-success fs-1"></i>
                            </div>
                            <h3 class="h5 fw-bold text-dark">{{ __('messages.home_no_new_products') }}</h3>
                            <p class="text-muted mb-0 px-3">{{ __('messages.home_system_updating') }}</p>
                        </div>
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    <!-- Sản phẩm nổi bật (theo lượt xem) -->
    <section class="py-4 py-md-5 position-relative overflow-hidden" id="noi-bat">
        <div class="home-section-mesh" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center mb-4 gap-3">
                <div class="text-center text-sm-start w-100 w-sm-auto">
                    <span class="text-success fw-bold text-uppercase small">{{ __('messages.home_most_viewed') }}</span>
                    <h2 class="h3 fw-bold text-dark mt-1 mb-0">{{ __('messages.home_featured_products') }}</h2>
                    <p class="text-muted small mb-0 mt-1 d-none d-md-block">{{ __('messages.home_featured_desc') }}</p>
                </div>
                <a href="{{ route('products.index') }}" class="btn btn-sm btn-outline-success rounded-pill px-3 flex-shrink-0 home-section-link">
                    <span>{{ __('messages.home_view_all') }}</span><i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </div>

            <div class="row g-3 g-md-4">
                @forelse($featuredProducts as $product)
                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                        <x-product-card
                            :product="$product"
                            card-class="home-product-card border-0"
                            media-class="home-product-media"
                            body-class="home-product-body"
                            :show-detail-action="true"
                            :show-compare-price="true"
                            detail-label="{{ __('messages.home_view_details') }}"
                        >
                            <x-slot:badges>
                                @if($product->views_count > 0)
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success-subtle px-2 py-1 rounded-pill">
                                        <i class="bi bi-eye-fill me-1"></i>{{ __('messages.home_views_count', ['count' => number_format($product->views_count)]) }}
                                    </span>
                                @endif
                                @if($product->stock_quantity == 0)
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle px-2 py-1 rounded-pill">
                                        <i class="bi bi-x-circle me-1"></i>{{ __('messages.out_of_stock') }}
                                    </span>
                                @endif
                            </x-slot:badges>
                        </x-product-card>
                    </div>
                @empty
                    <div class="col-12">
                        <div class="text-center py-5 glass-card rounded-4">
                            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; background: rgba(16,185,129,0.12);">
                                <i class="bi bi-box2 text-success fs-1"></i>
                            </div>
                            <h3 class="h5 fw-bold text-dark">{{ __('messages.home_no_featured') }}</h3>
                            <p class="text-muted mb-0 px-3">{{ __('messages.home_explore_more_desc') }}</p>
                            <a href="{{ route('products.index') }}" class="btn btn-success rounded-pill mt-3 px-4" style="background: #10b981; border-color: #10b981;">{{ __('messages.home_go_to_store') }}</a>
                        </div>
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    <!-- Vì sao chọn chúng tôi -->
    <section class="py-4 py-md-5 position-relative overflow-hidden">
        <div class="home-section-mesh" aria-hidden="true"></div>
        <div class="container position-relative py-2">
            <div class="text-center mb-4 mb-md-5">
                <span class="text-success fw-bold text-uppercase small">{{ __('messages.home_why_choose_us') }}</span>
                <h2 class="h3 fw-bold text-dark mt-1">{{ __('messages.home_why_choose_us_subtitle') }}</h2>
            </div>
            <div class="row g-3 g-md-4">
                <div class="col-md-4">
                    <div class="home-reason-card h-100 p-4 p-md-5 rounded-4 border-0 hover-lift">
                        <div class="home-reason-icon mb-3">
                            <i class="bi bi-shield-check fs-2"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-2 text-dark">{{ __('messages.home_reason1_title') }}</h3>
                        <p class="text-secondary mb-0 small">{{ __('messages.home_reason1_desc') }}</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="home-reason-card h-100 p-4 p-md-5 rounded-4 border-0 hover-lift">
                        <div class="home-reason-icon mb-3">
                            <i class="bi bi-truck fs-2"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-2 text-dark">{{ __('messages.home_reason2_title') }}</h3>
                        <p class="text-secondary mb-0 small">{{ __('messages.home_reason2_desc') }}</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="home-reason-card h-100 p-4 p-md-5 rounded-4 border-0 hover-lift">
                        <div class="home-reason-icon mb-3">
                            <i class="bi bi-heart-pulse fs-2"></i>
                        </div>
                        <h3 class="h5 fw-bold mb-2 text-dark">{{ __('messages.home_reason3_title') }}</h3>
                        <p class="text-secondary mb-0 small">{{ __('messages.home_reason3_desc') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Quy trình -->
    <section class="py-4 py-md-5 position-relative overflow-hidden">
        <div class="home-section-mesh home-section-mesh--subtle" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="text-center mb-4 mb-md-5">
                <span class="text-success fw-bold text-uppercase small">{{ __('messages.home_few_steps') }}</span>
                <h2 class="h3 fw-bold text-dark mt-1">{{ __('messages.home_simple_shopping') }}</h2>
            </div>
            <div class="row g-3 g-md-4 home-process-grid">
                <div class="col-md-4">
                    <div class="home-glass-panel home-step-card h-100 rounded-4">
                        <span class="home-step-num">1</span>
                        <h3 class="h6 fw-bold text-dark">{{ __('messages.home_step1_title') }}</h3>
                        <p class="small text-secondary mb-0">{{ __('messages.home_step1_desc') }}</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="home-glass-panel home-step-card h-100 rounded-4">
                        <span class="home-step-num">2</span>
                        <h3 class="h6 fw-bold text-dark">{{ __('messages.home_step2_title') }}</h3>
                        <p class="small text-secondary mb-0">{{ __('messages.home_step2_desc') }}</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="home-glass-panel home-step-card h-100 rounded-4">
                        <span class="home-step-num">3</span>
                        <h3 class="h6 fw-bold text-dark">{{ __('messages.home_step3_title') }}</h3>
                        <p class="small text-secondary mb-0">{{ __('messages.home_step3_desc') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ (details/summary: hiển thị nội dung ổn định + glass rõ nét) -->
    <section class="home-faq-section py-4 py-md-5 position-relative overflow-hidden">
        <div class="home-faq-section__mesh" aria-hidden="true"></div>
        <div class="container position-relative" style="z-index: 1;">
            <div class="row justify-content-center">
                <div class="col-lg-9 col-xl-8">
                    <div class="text-center mb-4">
                        <span class="text-success fw-bold text-uppercase small" style="letter-spacing: 0.06em;">{{ __('messages.home_faq') }}</span>
                        <h2 class="h3 fw-bold text-dark mt-1">{{ __('messages.home_useful_info') }}</h2>
                    </div>
                    <div class="home-faq-stack d-flex flex-column gap-3">
                        <details class="home-faq-item">
                            <summary class="home-faq-summary">{{ __('messages.home_faq1_q') }}</summary>
                            <div class="home-faq-body">
                                {{ __('messages.home_faq1_a') }}
                            </div>
                        </details>
                        <details class="home-faq-item">
                            <summary class="home-faq-summary">{{ __('messages.home_faq2_q') }}</summary>
                            <div class="home-faq-body">
                                {{ __('messages.home_faq2_a') }}
                            </div>
                        </details>
                        <details class="home-faq-item">
                            <summary class="home-faq-summary">{{ __('messages.home_faq3_q') }}</summary>
                            <div class="home-faq-body">
                                {{ __('messages.home_faq3_a_part1') }} <a href="{{ route('contact') }}" class="text-success fw-semibold text-decoration-none">{{ __('messages.home_faq3_a_contact') }}</a> {{ __('messages.home_faq3_a_part2') }}
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="py-4 py-md-5 mb-4 mb-md-5 position-relative overflow-hidden">
        <div class="home-section-mesh home-section-mesh--subtle" aria-hidden="true"></div>
        <div class="container position-relative">
            <div class="home-cta-gradient home-cta-glass rounded-4 p-4 p-md-5 position-relative overflow-hidden">
                <div class="position-absolute top-0 end-0 rounded-circle bg-white opacity-25" style="width: 200px; height: 200px; transform: translate(35%, -35%);" aria-hidden="true"></div>
                <div class="position-absolute bottom-0 start-0 rounded-circle bg-white opacity-20" style="width: 140px; height: 140px; transform: translate(-30%, 30%);" aria-hidden="true"></div>
                <div class="row align-items-center position-relative gy-4">
                    <div class="col-lg-8 text-center text-lg-start home-cta-content">
                        <h2 class="home-cta-heading fw-bold text-white mb-2" style="text-shadow: 0 1px 2px rgba(0,0,0,0.08);">{{ __('messages.home_cta_heading') }}</h2>
                        <p class="text-white mb-0 opacity-90 pe-lg-4">
                            {{ __('messages.home_cta_desc') }}
                        </p>
                        <div class="home-cta-points">
                            <span><i class="bi bi-shield-check"></i>{{ __('messages.home_genuine_products') }}</span>
                            <span><i class="bi bi-truck"></i>{{ __('messages.home_fast_delivery') }}</span>
                            <span><i class="bi bi-headset"></i>{{ __('messages.home_support_247') }}</span>
                        </div>
                    </div>
                    <div class="col-lg-4 text-center text-lg-end">
                        @guest
                            <a href="{{ route('register') }}" class="btn btn-light btn-lg text-success fw-bold px-4 px-lg-5 py-3 rounded-pill shadow">
                                {{ __('messages.home_create_account') }}
                            </a>
                        @else
                            <a href="{{ route('products.index') }}" class="btn btn-light btn-lg text-success fw-bold px-4 px-lg-5 py-3 rounded-pill shadow">
                                {{ __('messages.home_continue_shopping') }}
                            </a>
                        @endguest
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection
