<!DOCTYPE html>
<html lang="vi">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $appName = config('app.name', 'Nhà Thuốc Sinh Thái');
            $defaultDescription = __('messages.slogan');

            if (request()->routeIs('products.show') && isset($product)) {
                $defaultDescription = $product->name;

                if (!empty($product->ingredients)) {
                    $defaultDescription .= '. Thành phần: ' . $product->ingredients;
                } elseif (!empty($product->usage)) {
                    $defaultDescription .= '. Công dụng: ' . $product->usage;
                }

                $defaultDescription .= '. Mua tại ' . $appName . '.';
            } elseif (request()->routeIs('products.search') && !empty($searchTerm)) {
                $defaultDescription = 'Kết quả tìm kiếm cho "' . $searchTerm . '" tại ' . $appName . '. Khám phá dược phẩm và sản phẩm chăm sóc sức khỏe chính hãng.';
            } elseif (request()->routeIs('products.category') && isset($category)) {
                $defaultDescription = 'Khám phá danh mục ' . $category->name . ' tại ' . $appName . ' với các sản phẩm chính hãng, thông tin rõ ràng và hỗ trợ tư vấn tận tâm.';
            } elseif (request()->routeIs('products.index')) {
                $defaultDescription = 'Khám phá danh mục thuốc, dược phẩm và sản phẩm chăm sóc sức khỏe tại ' . $appName . '. Nguồn gốc minh bạch, hỗ trợ tư vấn nhanh chóng.';
            } elseif (request()->routeIs('contact')) {
                $defaultDescription = 'Liên hệ ' . $appName . ' để được hỗ trợ về sản phẩm, đơn hàng và tư vấn chăm sóc sức khỏe.';
            }

            $metaTitle = trim(strip_tags($__env->yieldContent('title', $appName)));
            $metaDescription = trim(strip_tags($__env->yieldContent('meta_description', $defaultDescription)));
            $metaDescription = \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($metaDescription), 160, '...');
            $metaKeywords = trim(strip_tags($__env->yieldContent(
                'meta_keywords',
                'nhà thuốc, dược phẩm, thuốc chính hãng, chăm sóc sức khỏe, thực phẩm chức năng, Medicare Pharmacy'
            )));

            $metaImage = trim($__env->yieldContent(
                'meta_image',
                request()->routeIs('products.show') && isset($product) && !empty($product->image_url)
                    ? $product->image_url
                    : asset('images/logo.png')
            ));

            $metaType = trim($__env->yieldContent(
                'meta_type',
                request()->routeIs('products.show') ? 'product' : 'website'
            ));

            $defaultRobots = request()->routeIs([
                'admin.*',
                'cart.*',
                'checkout.*',
                'profile.*',
                'login',
                'register',
                'password.*',
                'verification.*',
                'products.search',
            ]) ? 'noindex, nofollow' : 'index, follow';

            $metaRobots = trim($__env->yieldContent('meta_robots', $defaultRobots));
            $metaCanonical = trim($__env->yieldContent(
                'meta_canonical',
                request()->fullUrlWithoutQuery(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'])
            ));
            $metaLocale = app()->getLocale() === 'vi' ? 'vi_VN' : app()->getLocale();
            $metaImageAlt = trim($__env->yieldContent(
                'meta_image_alt',
                request()->routeIs('products.show') && isset($product)
                    ? $product->name . ' - ' . $appName
                    : $appName . ' logo'
            ));

            $organizationSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $appName,
                'url' => config('app.url'),
                'logo' => asset('images/logo.png'),
                'image' => asset('images/logo.png'),
                'email' => 'cskh@nhathuocsinhthai.vn',
                'telephone' => '19008888',
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => 'Lê Văn Hiến, Ngũ Hành Sơn',
                    'addressLocality' => 'Đà Nẵng',
                    'addressCountry' => 'VN',
                ],
                'contactPoint' => [
                    '@type' => 'ContactPoint',
                    'contactType' => 'customer support',
                    'telephone' => '19008888',
                    'email' => 'cskh@nhathuocsinhthai.vn',
                    'availableLanguage' => ['vi', 'en'],
                ],
            ];

            $websiteSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'WebSite',
                'name' => $appName,
                'url' => route('home'),
                'inLanguage' => app()->getLocale(),
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => route('products.search') . '?q={search_term_string}',
                    'query-input' => 'required name=search_term_string',
                ],
            ];

            $productSchema = null;
            if (request()->routeIs('products.show') && isset($product)) {
                $productSchema = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $product->name,
                    'description' => $metaDescription,
                    'image' => [$metaImage],
                    'sku' => $product->batch_number ?: (string) $product->id,
                    'category' => $product->category->name ?? null,
                    'brand' => [
                        '@type' => 'Brand',
                        'name' => $appName,
                    ],
                    'offers' => [
                        '@type' => 'Offer',
                        'priceCurrency' => 'VND',
                        'price' => $product->price_vnd,
                        'availability' => $product->stock_quantity > 0
                            ? 'https://schema.org/InStock'
                            : 'https://schema.org/OutOfStock',
                        'url' => request()->url(),
                    ],
                ];
            }

            $jsonLdOptions = JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_APOS
                | JSON_HEX_AMP
                | JSON_HEX_QUOT;
        @endphp

        <title>{{ $metaTitle }}</title>
        <meta name="description" content="{{ $metaDescription }}">
        <meta name="keywords" content="{{ $metaKeywords }}">
        <meta name="author" content="{{ $appName }}">
        <meta name="robots" content="{{ $metaRobots }}">
        <meta name="theme-color" content="#10b981">
        <meta name="format-detection" content="telephone=no">
        <meta name="application-name" content="{{ $appName }}">

        <link rel="canonical" href="{{ $metaCanonical }}">
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/logo.png') }}">
        <link rel="image_src" href="{{ $metaImage }}">

        <meta property="og:locale" content="{{ $metaLocale }}">
        <meta property="og:site_name" content="{{ $appName }}">
        <meta property="og:type" content="{{ $metaType }}">
        <meta property="og:title" content="{{ $metaTitle }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ $metaCanonical }}">
        <meta property="og:image" content="{{ $metaImage }}">
        <meta property="og:image:alt" content="{{ $metaImageAlt }}">
        <meta property="og:image:width" content="2816">
        <meta property="og:image:height" content="1536">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $metaTitle }}">
        <meta name="twitter:description" content="{{ $metaDescription }}">
        <meta name="twitter:image" content="{{ $metaImage }}">
        <meta name="twitter:image:alt" content="{{ $metaImageAlt }}">

        <meta itemprop="name" content="{{ $metaTitle }}">
        <meta itemprop="description" content="{{ $metaDescription }}">
        <meta itemprop="image" content="{{ $metaImage }}">

        <script type="application/ld+json">{!! json_encode($organizationSchema, $jsonLdOptions) !!}</script>
        <script type="application/ld+json">{!! json_encode($websiteSchema, $jsonLdOptions) !!}</script>
        @if($productSchema)
            <script type="application/ld+json">{!! json_encode($productSchema, $jsonLdOptions) !!}</script>
        @endif
        @stack('meta')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
        
        <!-- Bootstrap Icons -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        
        <style>
            body { font-family: 'Inter', sans-serif; }
            
            /* Custom 5-column grid for categories */
            @media (min-width: 1200px) {
                .col-xl-2-4 {
                    flex: 0 0 auto;
                    width: 20%;
                }
            }
            
            /* Navbar Base Styles */
            .navbar { min-height: 64px; }
            .navbar-brand { font-size: 1.25rem; font-weight: 700; }
            .nav-link.active { font-weight: 600; color: #059669 !important; background-color: rgba(16, 185, 129, 0.1); }
            .navbar-nav .nav-link { 
                border-radius: 8px; 
                padding: 8px 16px; 
                transition: all 0.3s ease;
                font-size: 0.95rem;
            }
            .navbar-nav .nav-link:hover { background-color: rgba(16, 185, 129, 0.1); color: #059669; }
            
            /* Dropdown Menu Styles */
            .dropdown-menu { animation: fadeInDown 0.3s ease-out; }
            @keyframes fadeInDown {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .dropdown-item {
                transition: all 0.2s ease;
            }
            .dropdown-item:hover {
                background-color: rgba(16, 185, 129, 0.08);
                color: #059669;
                transform: translateX(4px);
            }
            .dropdown-item:hover i {
                color: #059669 !important;
            }
            .dropdown-item.text-danger:hover {
                background-color: rgba(239, 68, 68, 0.08);
                color: #dc2626 !important;
            }
            .dropdown-item.text-danger:hover i {
                color: #dc2626 !important;
            }
            
            /* Fix Tailwind and Bootstrap .collapse conflict */
            .navbar-collapse.collapse.show { visibility: visible !important; display: block; }
            .navbar-collapse.collapsing { visibility: visible !important; }
            
            /* Mobile Styles (< 992px) */
            @media (max-width: 991px) {
                .navbar { min-height: 56px; }
                .navbar-brand { font-size: 1.1rem; }
                .navbar-brand img { height: 36px !important; }
                
                /* Beautiful Hamburger Toggle */
                .navbar-toggler { 
                    padding: 8px 10px;
                    border: 1px solid rgba(16, 185, 129, 0.2);
                    background: rgba(255, 255, 255, 0.7) !important;
                    backdrop-filter: blur(8px);
                    -webkit-backdrop-filter: blur(8px);
                    border-radius: 12px;
                    transition: all 0.3s ease;
                }
                .navbar-toggler:focus { box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }
                .navbar-toggler[aria-expanded="true"] {
                    background: rgba(16, 185, 129, 0.1) !important;
                    border-color: rgba(16, 185, 129, 0.3);
                }
                
                /* Floating Glassmorphism Mobile Menu */
                .navbar-collapse, .navbar-collapse.collapsing { 
                    position: absolute;
                    top: calc(100% + 10px);
                    left: 12px;
                    right: 12px;
                    background: rgba(255, 255, 255, 0.95);
                    backdrop-filter: blur(20px);
                    -webkit-backdrop-filter: blur(20px);
                    padding: 1.25rem;
                    border-radius: 20px;
                    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.05), inset 0 0 0 1px rgba(255,255,255,0.8);
                    border: 1px solid rgba(16, 185, 129, 0.1);
                    z-index: 1050;
                    transform-origin: top center;
                    animation: popDown 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
                    max-height: calc(100vh - 100px);
                    overflow-y: auto;
                    overflow-x: hidden;
                }
                
                .navbar-collapse.collapsing {
                    height: auto !important;
                    transition: none !important;
                }
                
                @keyframes popDown {
                    from { opacity: 0; transform: translateY(-15px) scale(0.95); }
                    to { opacity: 1; transform: translateY(0) scale(1); }
                }

                .navbar-nav .nav-item { margin: 0.15rem 0; }
                .navbar-nav .nav-link { 
                    padding: 14px 16px;
                    font-size: 1rem;
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                }
                .navbar-nav .nav-link i { margin-right: 12px; font-size: 1.25rem; width: 24px; text-align: center; }
                
                .mobile-search { 
                    margin-bottom: 1rem;
                    width: 100%;
                }
                .mobile-search input {
                    width: 100%;
                    height: 50px;
                    border-radius: 16px;
                    background: rgba(16, 185, 129, 0.06);
                    border: 1px solid rgba(16, 185, 129, 0.1);
                    font-size: 1rem;
                }
                .mobile-search input:focus {
                    background: #fff;
                    border-color: #10b981;
                    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
                }
                
                /* Adjust dropdown in mobile */
                .dropdown-menu {
                    position: static !important;
                    transform: none !important;
                    width: 100% !important;
                    min-width: auto !important;
                    background: transparent !important;
                    border: none !important;
                    box-shadow: none !important;
                    padding-left: 0.5rem;
                    padding-right: 0.5rem;
                    margin-top: 0.5rem !important;
                    margin-bottom: 0.5rem !important;
                    backdrop-filter: none !important;
                }
                .dropdown-menu .dropdown-item {
                    border-radius: 10px;
                    padding: 12px 16px;
                    white-space: normal; /* prevent text from pushing offscreen */
                }
            }
            
            /* Desktop Styles (>= 992px) */
            @media (min-width: 992px) {
                .navbar-expand-lg .navbar-collapse {
                    display: flex !important;
                    flex-basis: auto;
                    visibility: visible !important;
                }
                .navbar-brand img { height: 44px !important; }
                .desktop-search { max-width: 500px; }
                .desktop-search input { height: 44px; }
                .navbar-nav { gap: 0.25rem; }
                .navbar-nav .nav-link { font-size: 0.95rem; }
            }
            
            /* Tablet Styles (768px - 991px) */
            @media (min-width: 768px) and (max-width: 991px) {
                .navbar-brand { font-size: 1.15rem; }
                .navbar-brand img { height: 40px !important; }
            }
            
            /* Cart Badge */
            .cart-badge {
                position: absolute;
                top: -4px;
                right: -8px;
                min-width: 20px;
                height: 20px;
                padding: 0 6px;
                font-size: 0.7rem;
                line-height: 20px;
            }
            
            /* Account avatar in mobile */
            @media (max-width: 991px) {
                .nav-item.dropdown .nav-link.d-flex {
                    justify-content: flex-start !important;
                    background: transparent !important;
                    padding: 12px 16px !important;
                }
                .nav-item.dropdown .nav-link.d-flex img {
                    margin-right: 12px;
                }
                .nav-item.dropdown .nav-link.d-flex::after {
                    margin-left: auto;
                    align-self: center;
                }
                .cart-mobile-text { display: inline !important; }
            }
        </style>
        @stack('styles')
    </head>
    <body class="bg-gray-50 text-gray-800">
        <!-- Navigation -->
        <nav class="navbar navbar-expand-lg fixed-top" style="background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(16px); border-bottom: 1px solid rgba(16, 185, 129, 0.15); box-shadow: 0 2px 20px rgba(0, 0, 0, 0.05); z-index: 1050;">
            <div class="container-fluid px-3 px-lg-4 d-flex flex-nowrap align-items-center justify-content-between">
                <!-- Branding -->
                <a class="navbar-brand d-flex align-items-center overflow-hidden" href="{{ route('home') }}" style="max-width: 70vw; white-space: nowrap;">
                    <img src="{{ asset('images/logo.png') }}" alt="Logo" class="me-2 shadow-sm flex-shrink-0" style="height: 40px; width: auto; object-fit: contain; transition: transform 0.3s ease;">
                    <span class="text-emerald-700 text-truncate d-none d-sm-inline fw-bold" style="font-size: 1.15rem;">{{ __('messages.app_name') }}</span>
                    <span class="text-emerald-700 text-truncate d-inline d-sm-none fw-bold" style="font-size: 1.1rem;">{{ __('messages.app_name_short') }}</span>
                </a>
                
                <!-- Desktop Search -->
                <div class="d-none d-lg-flex flex-grow-1 mx-4 desktop-search">
                    <form action="{{ route('products.search') }}" method="GET" class="w-100 position-relative">
                        <input type="text" name="q" class="form-control rounded-pill border-0 ps-4 pe-5 shadow-sm" placeholder="{{ __('messages.search_placeholder') }}" autocomplete="off" style="background: rgba(16, 185, 129, 0.08); transition: all 0.3s ease;" onfocus="this.style.background='rgba(16, 185, 129, 0.12)'">
                        <i class="bi bi-search position-absolute top-50 end-0 translate-middle-y me-3 text-emerald-600"></i>
                    </form>
                </div>
                
                <!-- Mobile Toggle Button -->
                <button class="navbar-toggler rounded-3 flex-shrink-0 ms-2" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation" style="border-width: 1.5px;">
                    <i class="bi bi-list fs-4 text-emerald-600" style="font-weight: bold;"></i>
                </button>
                
                <!-- Navbar Menu -->
                <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                    <!-- Mobile Search -->
                    <div class="d-lg-none mobile-search">
                        <form action="{{ route('products.search') }}" method="GET" class="position-relative">
                            <input type="text" name="q" class="form-control rounded-pill border-0 ps-4 pe-5 shadow-sm" placeholder="{{ __('messages.search_mobile_placeholder') }}" autocomplete="off" style="background: rgba(16, 185, 129, 0.08);">
                            <i class="bi bi-search position-absolute top-50 end-0 translate-middle-y me-3 text-emerald-600"></i>
                        </form>
                    </div>
                    
                    <ul class="navbar-nav align-items-lg-center">
                        <!-- Home Link -->
                        <li class="nav-item">
                            <a class="nav-link text-gray-700 {{ request()->routeIs('home') ? 'active' : '' }}" href="{{ route('home') }}">
                                <i class="bi bi-house-door me-1"></i>{{ __('messages.home') }}
                            </a>
                        </li>
                        
                        <!-- Categories Dropdown -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle text-gray-700" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-grid me-1"></i>{{ __('messages.categories') }}
                            </a>
                            <ul class="dropdown-menu border-0 shadow-lg rounded-4 p-3 mt-2" style="min-width: 280px; background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(20px);">
                                <div class="row g-2">
                                    @forelse($navCategories as $cat)
                                        <div class="col-12">
                                            <li>
                                                <a class="dropdown-item rounded-3 py-2 px-3 d-flex align-items-center justify-content-between" href="{{ route('products.index', ['category' => $cat->id]) }}" style="transition: all 0.2s;">
                                                    <span>{{ $cat->name }}</span>
                                                    <i class="bi bi-chevron-right small text-gray-300"></i>
                                                </a>
                                            </li>
                                        </div>
                                    @empty
                                        <li class="px-3 py-2 text-muted small text-center">{{ __('messages.loading_categories') }}</li>
                                    @endforelse
                                </div>
                                <li><hr class="dropdown-divider my-2"></li>
                                <li>
                                    <a class="dropdown-item rounded-3 text-center fw-bold text-emerald-600" href="{{ route('products.index') }}">
                                        {{ __('messages.all_products') }}
                                    </a>
                                </li>
                            </ul>
                        </li>

                        <!-- Support Link -->
                        <li class="nav-item">
                            <a class="nav-link text-gray-700 {{ request()->routeIs('contact') ? 'active' : '' }}" href="{{ route('contact') }}">
                                <i class="bi bi-headset me-1"></i>{{ __('messages.contact') }}
                            </a>
                        </li>

                        <!-- Divider (Desktop Only) -->
                        <li class="d-none d-lg-block mx-2" style="width: 1px; height: 30px; background-color: rgba(16, 185, 129, 0.15);"></li>
                        
                        <!-- Cart -->
                        <li class="nav-item">
                            <a class="nav-link text-gray-700 position-relative {{ request()->routeIs('cart.*') ? 'active' : '' }}" href="{{ route('cart.index') }}">
                                <i class="bi bi-cart3 fs-5 me-1"></i>
                                <span class="d-lg-none">{{ __('messages.cart.title') }}</span>
                                @php $cartCount = session('cart') ? count(session('cart')) : 0; @endphp
                                <span id="cart-nav-badge" class="cart-badge badge rounded-pill bg-danger border border-white @if($cartCount === 0) d-none @endif" aria-hidden="{{ $cartCount === 0 ? 'true' : 'false' }}">{{ $cartCount > 0 ? $cartCount : '' }}</span>
                            </a>
                        </li>

                        @auth
                            <li class="nav-item dropdown">
                                <a class="nav-link d-flex align-items-center justify-content-center p-2 rounded-circle hover-scale-105" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('messages.account_menu') }}" style="background: rgba(16, 185, 129, 0.08); transition: all 0.3s ease;">
                                    <div class="position-relative">
                                        <img src="https://ui-avatars.com/api/?name={{ urlencode(auth()->user()->name) }}&background=10b981&color=fff&rounded=true&size=32" alt="" class="rounded-circle border border-2 border-white shadow-sm" style="width: 36px; height: 36px;" width="36" height="36">
                                        <span class="position-absolute bottom-0 end-0 bg-success rounded-circle border border-2 border-white" style="width: 10px; height: 10px;" aria-hidden="true"></span>
                                    </div>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg rounded-4 mt-2 p-2" style="min-width: 260px; background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(20px);">
                                    <!-- User Info Header -->
                                    <div class="px-3 py-3 mb-2 rounded-3" style="background: rgba(16, 185, 129, 0.08);">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <img src="https://ui-avatars.com/api/?name={{ urlencode(auth()->user()->name) }}&background=10b981&color=fff&rounded=true&size=40" alt="Avatar" class="rounded-circle border border-2 border-white shadow-sm" style="width: 40px; height: 40px;">
                                            <div class="flex-grow-1 overflow-hidden">
                                                <p class="mb-0 fw-bold text-gray-900 text-truncate">{{ auth()->user()->name }}</p>
                                                <p class="mb-0 small text-gray-600 text-truncate">{{ auth()->user()->email }}</p>
                                            </div>
                                        </div>
                                        <span class="badge bg-emerald-100 text-emerald-800 rounded-pill px-2 py-1 small">
                                            <i class="bi bi-shield-check me-1"></i>{{ auth()->user()->role === 'Administrator' ? __('messages.role_administrator') : __('messages.role_user') }}
                                        </span>
                                    </div>
                                    
                                    @if(auth()->user()->role === 'Administrator')
                                        <li>
                                            <a class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center gap-2" href="{{ route('admin.dashboard') }}" style="transition: all 0.2s;">
                                                <i class="bi bi-speedometer2 text-emerald-600"></i>
                                                <span class="fw-medium">{{ __('messages.dashboard') }}</span>
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider my-2"></li>
                                    @endif
                                    
                                    <li>
                                        <a class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center gap-2" href="{{ route('profile.edit') }}" style="transition: all 0.2s;">
                                            <i class="bi bi-person text-gray-500"></i>
                                            <span>{{ __('messages.my_profile') }}</span>
                                        </a>
                                    </li>
                                    
                                    <li>
                                        <a class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center gap-2" href="{{ route('profile.edit') }}#orders" style="transition: all 0.2s;">
                                            <i class="bi bi-bag text-gray-500"></i>
                                            <span>{{ __('messages.my_orders') }}</span>
                                        </a>
                                    </li>
                                    
                                    <li>
                                        <a class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center gap-2" href="{{ route('profile.edit') }}#settings" style="transition: all 0.2s;">
                                            <i class="bi bi-gear text-gray-500"></i>
                                            <span>{{ __('messages.settings') }}</span>
                                        </a>
                                    </li>
                                    
                                    <li><hr class="dropdown-divider my-2"></li>
                                    
                                    <li>
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button type="submit" class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center gap-2 text-danger" style="transition: all 0.2s;">
                                                <i class="bi bi-box-arrow-right"></i>
                                                <span class="fw-medium">{{ __('messages.logout') }}</span>
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </li>
                        @else
                            <li class="nav-item">
                                <a class="nav-link text-gray-700" href="{{ route('login') }}">
                                    <i class="bi bi-box-arrow-in-right me-1 d-lg-none"></i>{{ __('messages.login') }}
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="btn btn-sm btn-emerald text-white rounded-pill px-4 py-2 fw-medium shadow-sm" href="{{ route('register') }}" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                                    {{ __('messages.register_now') }}
                                </a>
                            </li>
                        @endauth
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Page Content -->
        <main class="w-100" style="padding-top: 86px; min-height: calc(100vh - 250px);">
            @yield('content')
            {{ $slot ?? '' }}
        </main>

        <!-- Footer -->
        <footer class="position-relative mt-5 overflow-hidden">
            <!-- Grid Background Pattern -->
            <div class="position-absolute top-0 start-0 w-100 h-100" style="z-index: 0;">
                <svg class="w-100 h-100" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <pattern id="footer-grid" width="40" height="40" patternUnits="userSpaceOnUse">
                            <path d="M 40 0 L 0 0 0 40" fill="none" stroke="rgba(16, 185, 129, 0.08)" stroke-width="1"/>
                        </pattern>
                        <linearGradient id="footer-gradient" x1="0%" y1="0%" x2="0%" y2="100%">
                            <stop offset="0%" style="stop-color:rgba(240, 253, 244, 0.95);stop-opacity:1" />
                            <stop offset="100%" style="stop-color:rgba(236, 253, 245, 0.98);stop-opacity:1" />
                        </linearGradient>
                    </defs>
                    <rect width="100%" height="100%" fill="url(#footer-gradient)"/>
                    <rect width="100%" height="100%" fill="url(#footer-grid)"/>
                </svg>
            </div>
            
            <!-- Decorative Blobs -->
            <div class="position-absolute" style="top: -50px; right: -50px; width: 200px; height: 200px; background: radial-gradient(circle, rgba(16, 185, 129, 0.15) 0%, transparent 70%); border-radius: 50%; z-index: 0;"></div>
            <div class="position-absolute" style="bottom: -30px; left: -30px; width: 150px; height: 150px; background: radial-gradient(circle, rgba(16, 185, 129, 0.1) 0%, transparent 70%); border-radius: 50%; z-index: 0;"></div>
            
            <div class="container position-relative" style="z-index: 1;">
                <!-- Main Footer Content -->
                <div class="pt-5 pb-4">
                    <div class="row g-4 mb-4">
                        <!-- Brand Column -->
                        <div class="col-lg-4 col-md-6">
                            <div class="glass-card p-4 rounded-4 border-0 h-100" style="background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(20px); box-shadow: 0 8px 32px rgba(16, 185, 129, 0.1);">
                                <a class="d-flex align-items-center mb-3 text-decoration-none" href="{{ route('home') }}">
                                    <img src="{{ asset('images/logo.png') }}" alt="Logo" class="me-2" style="height: 40px; width: auto;">
                                    <span class="fs-5 fw-bold text-emerald-800">{{ __('messages.app_name') }}</span>
                                </a>
                                <p class="text-gray-600 mb-4 small lh-lg">
                                    {{ __('messages.slogan') }}
                                </p>
                                <div class="d-flex gap-2">
                                    <a href="#" class="btn btn-sm rounded-circle shadow-sm" style="width:40px; height:40px; display:flex; align-items:center; justify-content:center; background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2);" title="Facebook">
                                        <i class="bi bi-facebook"></i>
                                    </a>
                                    <a href="#" class="btn btn-sm rounded-circle shadow-sm" style="width:40px; height:40px; display:flex; align-items:center; justify-content:center; background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2);" title="Twitter">
                                        <i class="bi bi-twitter"></i>
                                    </a>
                                    <a href="#" class="btn btn-sm rounded-circle shadow-sm" style="width:40px; height:40px; display:flex; align-items:center; justify-content:center; background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2);" title="Instagram">
                                        <i class="bi bi-instagram"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Links Columns -->
                        <div class="col-lg-8 col-md-6">
                            <div class="row g-3 h-100">
                                <!-- Danh mục -->
                                <div class="col-md-4 col-6">
                                    <div class="glass-card p-4 rounded-4 border-0 h-100" style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(20px); box-shadow: 0 4px 24px rgba(16, 185, 129, 0.08);">
                                        <h6 class="fw-bold mb-3 text-gray-800 d-flex align-items-center">
                                            <i class="bi bi-grid-fill text-emerald-600 me-2"></i>
                                            {{ __('messages.categories') }}
                                        </h6>
                                        <ul class="list-unstyled mb-0">
                                            @forelse($footerCategories as $fCat)
                                            <li class="{{ !$loop->last ? 'mb-2' : 'mb-0' }}">
                                                <a href="{{ route('products.index', ['category' => $fCat->id]) }}" class="text-gray-600 text-decoration-none d-flex align-items-center small hover-text-emerald-600 transition-all">
                                                    <i class="bi bi-chevron-right text-emerald-500 me-1" style="font-size: 0.7rem;"></i>
                                                    {{ $fCat->name }}
                                                </a>
                                            </li>
                                            @empty
                                            <li class="mb-0">
                                                <a href="{{ route('products.index') }}" class="text-gray-600 text-decoration-none d-flex align-items-center small hover-text-emerald-600 transition-all">
                                                    <i class="bi bi-chevron-right text-emerald-500 me-1" style="font-size: 0.7rem;"></i>
                                                    {{ __('messages.all_products') }}
                                                </a>
                                            </li>
                                            @endforelse
                                        </ul>
                                    </div>
                                </div>
                                
                                <!-- Hỗ trợ -->
                                <div class="col-md-4 col-6">
                                    <div class="glass-card p-4 rounded-4 border-0 h-100" style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(20px); box-shadow: 0 4px 24px rgba(16, 185, 129, 0.08);">
                                        <h6 class="fw-bold mb-3 text-gray-800 d-flex align-items-center">
                                            <i class="bi bi-headset text-emerald-600 me-2"></i>
                                            {{ __('messages.contact') }}
                                        </h6>
                                        <ul class="list-unstyled mb-0">
                                            <li class="mb-2">
                                                <a href="#" class="text-gray-600 text-decoration-none d-flex align-items-center small hover-text-emerald-600 transition-all">
                                                    <i class="bi bi-chevron-right text-emerald-500 me-1" style="font-size: 0.7rem;"></i>
                                                    {{ __('messages.buying_policy') }}
                                                </a>
                                            </li>
                                            <li class="mb-2">
                                                <a href="#" class="text-gray-600 text-decoration-none d-flex align-items-center small hover-text-emerald-600 transition-all">
                                                    <i class="bi bi-chevron-right text-emerald-500 me-1" style="font-size: 0.7rem;"></i>
                                                    {{ __('messages.return_policy') }}
                                                </a>
                                            </li>
                                            <li class="mb-2">
                                                <a href="#" class="text-gray-600 text-decoration-none d-flex align-items-center small hover-text-emerald-600 transition-all">
                                                    <i class="bi bi-chevron-right text-emerald-500 me-1" style="font-size: 0.7rem;"></i>
                                                    {{ __('messages.privacy_policy') }}
                                                </a>
                                            </li>
                                            <li class="mb-0">
                                                <a href="{{ route('contact') }}" class="text-gray-600 text-decoration-none d-flex align-items-center small hover-text-emerald-600 transition-all">
                                                    <i class="bi bi-chevron-right text-emerald-500 me-1" style="font-size: 0.7rem;"></i>
                                                    {{ __('messages.contact_now') }}
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <!-- Liên hệ -->
                                <div class="col-md-4 col-12">
                                    <div class="glass-card p-4 rounded-4 border-0 h-100" style="background: rgba(255, 255, 255, 0.6); backdrop-filter: blur(20px); box-shadow: 0 4px 24px rgba(16, 185, 129, 0.08);">
                                        <h6 class="fw-bold mb-3 text-gray-800 d-flex align-items-center">
                                            <i class="bi bi-geo-alt-fill text-emerald-600 me-2"></i>
                                            {{ __('messages.contact') }}
                                        </h6>
                                        <ul class="list-unstyled mb-0">
                                            <li class="mb-3 d-flex align-items-start">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-2" style="width: 28px; height: 28px; background: rgba(16, 185, 129, 0.15);">
                                                    <i class="bi bi-geo-alt-fill text-emerald-600" style="font-size: 0.8rem;"></i>
                                                </div>
                                                <span class="text-gray-600 small">{{ __('messages.footer.address', ['default' => 'Lê Văn Hiến, Ngũ Hành Sơn, Đà Nẵng']) }}</span>
                                            </li>
                                            <li class="mb-3 d-flex align-items-center">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-2" style="width: 28px; height: 28px; background: rgba(16, 185, 129, 0.15);">
                                                    <i class="bi bi-telephone-fill text-emerald-600" style="font-size: 0.8rem;"></i>
                                                </div>
                                                <span class="text-gray-600 small">1900 8888 ({{ __('messages.free_call') }})</span>
                                            </li>
                                            <li class="mb-0 d-flex align-items-center">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 me-2" style="width: 28px; height: 28px; background: rgba(16, 185, 129, 0.15);">
                                                    <i class="bi bi-envelope-fill text-emerald-600" style="font-size: 0.8rem;"></i>
                                                </div>
                                                <span class="text-gray-600 small">cskh@nhathuocsinhthai.vn</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer Bottom -->
                <div class="py-4 border-top" style="border-color: rgba(16, 185, 129, 0.15) !important;">
                    <div class="glass-card px-4 py-3 rounded-4 border-0" style="background: rgba(255, 255, 255, 0.5); backdrop-filter: blur(20px);">
                        <div class="row align-items-center g-3">
                            <div class="col-md-6 text-center text-md-start">
                                <p class="mb-0 text-gray-600 small">
                                    &copy; {{ date('Y') }} <span class="fw-semibold text-emerald-700">{{ __('messages.app_name') }}</span>. All rights reserved.
                                </p>
                            </div>
                            <div class="col-md-6 text-center text-md-end">
                                <p class="mb-0 text-gray-500 small">
                                    Developed by <span class="fw-semibold text-emerald-600">Phạm Trường Huy</span> - ĐAPTTKHTTT
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
        
        <!-- SweetAlert2 -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            /* Position toast below navbar */
            .swal2-container.swal2-top-end {
                top: 80px !important; /* Navbar height (64px) + spacing (16px) */
            }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Flash messages rendering
                @if(session('success'))
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: '{{ __('messages.success') }}',
                        text: '{{ session('success') }}',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                        background: '#ffffff',
                        customClass: { popup: 'rounded-4 shadow-sm border border-emerald-100' }
                    });
                @endif
                @if(session('error'))
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'error',
                        title: '{{ __('messages.error_occurred') }}',
                        text: '{{ session('error') }}',
                        showConfirmButton: false,
                        timer: 4000,
                        timerProgressBar: true,
                        background: '#ffffff',
                        customClass: { popup: 'rounded-4 shadow-sm border border-red-100' }
                    });
                @endif
                
                // Auto close mobile navbar on click outside
                document.addEventListener('click', function(event) {
                    const navbarCollapse = document.getElementById('navbarNav');
                    const navbarToggler = document.querySelector('.navbar-toggler');
                    
                    if (navbarCollapse && navbarCollapse.classList.contains('show')) {
                        const isClickInside = navbarCollapse.contains(event.target) || navbarToggler.contains(event.target);
                        if (!isClickInside) {
                            navbarToggler.click(); // Trigger close
                        }
                    }
                });
            });
        </script>
        
        <!-- Chatbot Widget (Available for all users, login required to use) -->
        <x-chatbot-widget />
        
        @stack('modals')
        @stack('scripts')
    </body>
</html>
