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
                    // product_code, not batch_number: the batch is an internal
                    // inventory record and search engines index this markup.
                    'sku' => $product->product_code ?: (string) $product->MaSP,
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

        <script>
            window.PharmacyFavorites = {
                authenticated: @json(auth()->check()),
                idsUrl: @json(route('favorites.ids')),
                sectionUrl: @json(route('favorites.section')),
                syncUrl: @json(route('favorites.sync')),
                toggleUrlTemplate: @json(route('favorites.toggle', ['product' => '__PRODUCT_ID__'])),
                destroyUrlTemplate: @json(route('favorites.destroy', ['product' => '__PRODUCT_ID__'])),
                loginUrl: @json(route('login')),
            };

            // Single source of truth for warning copy: the SweetAlert confirmation and
            // the drug-warnings component must not drift apart on a label edit.
            // (Do not write the component tag here — Blade compiles tags inside comments.)
            window.PharmacyDrugSafety = {
                severityLabels: {
                    major: @json(__('messages.drug_safety.severity_major')),
                    moderate: @json(__('messages.drug_safety.severity_moderate')),
                    minor: @json(__('messages.drug_safety.severity_minor')),
                },
                actionLabel: @json(__('messages.drug_safety.action_label')),
                blockTitle: @json(__('messages.drug_safety.block_title')),
                confirmButton: @json(__('messages.drug_safety.confirm_button')),
                cancelButton: @json(__('messages.drug_safety.cancel_button')),
                softMessage: @json(__('messages.drug_safety.soft_message')),
            };
        </script>

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
            .nav-categories-menu {
                width: min(360px, calc(100vw - 32px));
                min-width: min(360px, calc(100vw - 32px));
                max-width: calc(100vw - 32px);
                padding: 0.85rem;
                border: 1px solid rgba(16, 185, 129, 0.16);
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(20px);
                box-shadow: 0 24px 48px rgba(15, 23, 42, 0.12);
                box-sizing: border-box;
                overflow-x: hidden;
            }
            .nav-categories-head {
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 0.75rem;
                padding: 0 0.4rem 0.5rem;
            }
            .nav-categories-label {
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #059669;
            }
            .nav-categories-total {
                flex-shrink: 0;
                font-size: 0.72rem;
                font-weight: 700;
                color: #9ca3af;
            }
            .nav-categories-search {
                position: relative;
                margin-bottom: 0.5rem;
            }
            .nav-categories-search i {
                position: absolute;
                top: 50%;
                left: 0.7rem;
                transform: translateY(-50%);
                color: #059669;
                font-size: 0.85rem;
                pointer-events: none;
            }
            .nav-categories-search input {
                width: 100%;
                min-height: 40px;
                padding: 0.4rem 0.65rem 0.4rem 2.1rem;
                border-radius: 0.65rem;
                border: 1px solid rgba(21, 128, 61, 0.16);
                background: rgba(255, 255, 255, 0.96);
                font-size: 0.87rem;
                color: #1f2937;
            }
            .nav-categories-search input:focus {
                outline: none;
                border-color: rgba(16, 185, 129, 0.5);
                box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.16);
            }
            /* The crawled catalog carries 51 categories, so only the list scrolls —
               the label and the filter box stay put while it does. */
            .nav-categories-list {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.15rem 0.4rem;
                max-height: min(58vh, 420px);
                overflow-y: auto;
                overscroll-behavior: contain;
                scrollbar-width: thin;
                scrollbar-color: rgba(16, 185, 129, 0.34) transparent;
                margin-right: -0.3rem;
                padding-right: 0.3rem;
            }
            .nav-categories-list::-webkit-scrollbar {
                width: 6px;
            }
            .nav-categories-list::-webkit-scrollbar-thumb {
                border-radius: 999px;
                background: rgba(16, 185, 129, 0.34);
            }
            .nav-categories-empty {
                display: none;
                grid-column: 1 / -1;
                margin: 0;
                padding: 0.8rem 0.5rem;
                font-size: 0.84rem;
                color: #6b7280;
            }
            .nav-categories-list.is-empty .nav-categories-empty {
                display: block;
            }
/* NAV-CHUNK-3 */
            /* Same stock-depth bar as the catalog sidebar: drawn as the row's own
               background, so it costs no height. Replaces 36px icon tiles that all
               showed the same fallback glyph — no crawled category carries an icon. */
            .nav-category-link {
                position: relative;
                isolation: isolate;
                display: flex;
                align-items: center;
                gap: 0.4rem;
                min-height: 34px;
                padding: 0.35rem 0.5rem;
                border-radius: 0.5rem;
                border: 1px solid transparent;
                color: #111827;
                text-decoration: none;
                white-space: normal;
            }
            .nav-category-link::before {
                content: '';
                position: absolute;
                inset: 1px;
                z-index: -1;
                width: var(--depth, 0%);
                border-radius: 0.45rem;
                background: linear-gradient(90deg, rgba(16, 185, 129, 0.19), rgba(16, 185, 129, 0.07));
                transition: opacity 0.16s ease;
            }
            .nav-category-link:hover,
            .nav-category-link:focus-visible {
                background: rgba(236, 253, 245, 0.9);
                border-color: rgba(16, 185, 129, 0.22);
                color: #047857;
            }
            .nav-category-link.is-active {
                border-color: transparent;
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                color: #ffffff;
            }
            .nav-category-link.is-active::before {
                opacity: 0;
            }
            .nav-category-link[hidden] {
                display: none;
            }
            .nav-category-title {
                flex: 1;
                min-width: 0;
                font-size: 0.82rem;
                font-weight: 600;
                line-height: 1.25;
                color: inherit;
            }
            .nav-category-count {
                flex-shrink: 0;
                font-size: 0.72rem;
                font-weight: 700;
                font-variant-numeric: tabular-nums;
                color: #6b7280;
            }
            .nav-category-link:hover .nav-category-count,
            .nav-category-link:focus-visible .nav-category-count {
                color: #047857;
            }
            .nav-category-link.is-active .nav-category-count {
                color: rgba(255, 255, 255, 0.92);
            }
            .nav-categories-cta-count {
                flex-shrink: 0;
                font-size: 0.8rem;
                font-weight: 700;
                font-variant-numeric: tabular-nums;
                color: #6b7280;
            }
            .nav-categories-cta:hover .nav-categories-cta-count,
            .nav-categories-cta:focus .nav-categories-cta-count {
                color: #047857;
            }
            /* The nav rows are their own hover treatment; the generic dropdown-item
               slide would fight the depth bar underneath them. */
            .nav-category-link:hover,
            .nav-category-link:focus {
                transform: none;
            }
            @media (max-width: 991px) {
                /* Inside the collapsed burger menu the dropdown is in flow, so it gets
                   a tighter cap and stays single-column at the narrowest widths. */
                .nav-categories-menu {
                    padding: 0.7rem;
                }
                .nav-categories-list {
                    max-height: min(46vh, 320px);
                }
            }
            @media (max-width: 400px) {
                .nav-categories-list {
                    grid-template-columns: minmax(0, 1fr);
                }
            }
            @media (prefers-reduced-motion: reduce) {
                .nav-category-link,
                .nav-category-link::before {
                    transition: none;
                }
            }
            .nav-categories-divider {
                margin: 0.75rem 0 0.35rem;
                border-color: rgba(16, 185, 129, 0.12);
            }
            .nav-categories-cta {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.55rem;
                min-height: 46px;
                border-radius: 1rem;
                font-weight: 700;
                font-size: 0.95rem;
                color: #059669;
                background: linear-gradient(135deg, rgba(236, 253, 245, 0.95) 0%, rgba(209, 250, 229, 0.88) 100%);
                border: 1px solid rgba(16, 185, 129, 0.18);
            }
            .nav-categories-cta:hover,
            .nav-categories-cta:focus {
                color: #047857;
                background: linear-gradient(135deg, rgba(209, 250, 229, 1) 0%, rgba(167, 243, 208, 0.92) 100%);
                transform: translateY(-1px);
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

            /* Ô tìm kiếm duy nhất của app, nằm giữa logo và burger ở mọi khổ. Không giới
               hạn trên: dưới lg nó lấy hết chỗ còn lại, trên lg dừng ở 500px để nav link
               còn chỗ. */
            .header-search { min-width: 0; }
            .header-search input {
                height: 42px;
                background: rgba(16, 185, 129, 0.08);
                transition: background 0.3s ease, box-shadow 0.2s ease;
            }
            .header-search input:focus {
                background: #fff;
                box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
            }

            /* Thanh header. Trước đây là inline style; chuyển ra class vì inline thắng
               stylesheet, nên không thể override theo trạng thái cuộn. */
            .site-header {
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                border-bottom: 1px solid rgba(16, 185, 129, 0.15);
                box-shadow: 0 2px 20px rgba(0, 0, 0, 0.05);
                z-index: 1050;
                transition: box-shadow 0.26s ease-out, background-color 0.26s ease-out,
                            padding-top 0.26s cubic-bezier(0.4, 0, 0.2, 1),
                            padding-bottom 0.26s cubic-bezier(0.4, 0, 0.2, 1);
            }

            /* Cuộn quá 64px thì thanh nổi rõ hơn khỏi nội dung phía sau.
               resources/js/header-scroll.js đặt data-header-scrolled trên <html>; ở đây
               chỉ có CSS, không JS nào chạy theo từng frame cuộn.

               Hai khai báo này chỉ paint, không layout, nên áp cho mọi khổ. Phần nhường
               chỗ ngang thì chỉ dưới lg — xem khối media dưới. */
            :root[data-header-scrolled] .site-header {
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(22px);
                -webkit-backdrop-filter: blur(22px);
                box-shadow: 0 6px 28px rgba(0, 0, 0, 0.1);
            }

            @media (prefers-reduced-motion: reduce) {
                /* Trạng thái vẫn đổi, chỉ bỏ phần chuyển động. */
                .site-header,
                .navbar-brand,
                .header-search,
                .header-search input {
                    transition: none;
                }
            }

            /* Mobile Styles (< 992px) */
            @media (max-width: 991px) {
                .navbar { min-height: 56px; }
                .navbar-brand { font-size: 1.1rem; }
                .navbar-brand img { height: 36px !important; }

                /* Cuộn quá 64px: logo nhường hết chỗ ngang cho ô tìm kiếm và thanh gọn
                   lại. Chỉ dưới lg — trên desktop ô đã dừng ở 500px và còn nav link bên
                   phải, nên bỏ logo ở đó là mất thương hiệu mà không đổi được gì.

                   Nav là position: fixed nên đổi padding của nó không đẩy nội dung
                   trang: main giữ nguyên padding-top: 86px. Reflow gói trong ba phần tử
                   của thanh, một lần mỗi lần vượt ngưỡng — không phải mỗi frame cuộn. */
                /* transition KHÔNG khai lại ở đây: khối reduced-motion ở trên cùng
                   specificity nhưng đứng trước trong source order, nên khai lại là ghi
                   đè nó — đo 2026-09-02 ở 375px, prefers-reduced-motion vẫn thấy
                   padding-top trong transition-property. Chuyển động thật thì đã bị
                   quy tắc `*` toàn cục trong storefront.css:2140 ép về 0.01ms, nên đây
                   là dọn một khai báo tự mâu thuẫn, không phải sửa một lỗi thấy được.
                   Khai báo gốc ở trên đã phủ khổ này. */
                :root[data-header-scrolled] .site-header {
                    padding-top: 0.35rem;
                    padding-bottom: 0.35rem;
                }

                /* max-width, không phải display: none — 0 là trạng thái animate được,
                   còn display thì nhảy. visibility: hidden để logo rời tab order và a11y
                   tree, chứ không thành một link vô hình vẫn bấm được. */
                .navbar-brand {
                    max-width: 120px;
                    transition: max-width 0.26s cubic-bezier(0.4, 0, 0.2, 1),
                                opacity 0.18s ease-out,
                                margin-right 0.26s cubic-bezier(0.4, 0, 0.2, 1),
                                visibility 0.26s;
                }
                :root[data-header-scrolled] .navbar-brand {
                    max-width: 0;
                    margin-right: 0 !important;
                    opacity: 0;
                    visibility: hidden;
                }
                .header-search {
                    transition: margin-left 0.26s cubic-bezier(0.4, 0, 0.2, 1);
                }
                :root[data-header-scrolled] .header-search {
                    margin-left: 0 !important;
                }
                
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
                .nav-categories-menu {
                    padding: 0.65rem;
                    margin-top: 0.35rem !important;
                    min-width: 0 !important;
                    width: 100%;
                    max-width: 100%;
                    box-sizing: border-box;
                }
                .nav-category-link {
                    min-height: 52px;
                    padding: 0.75rem 0.85rem;
                    gap: 0.75rem;
                }
                .nav-category-icon {
                    width: 38px;
                    height: 38px;
                    border-radius: 12px;
                }
                .nav-category-title {
                    font-size: 0.95rem;
                }
                .nav-category-meta {
                    font-size: 0.75rem;
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
                .header-search { max-width: 500px; }
                .header-search input { height: 44px; }
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
        {{-- Mốc đo cho resources/js/header-scroll.js: khi khối này trôi hết lên trên
             viewport thì scrollY đã vượt 64px và header chuyển sang trạng thái gọn.
             absolute + aria-hidden nên nó không chiếm chỗ và không có gì để đọc.
             Cao 64px chứ không phải 1px: IntersectionObserver lấy mẫu theo frame nên
             một phần tử 1px bị bỏ lỡ ở fractional pixel (zoom, DPR lẻ). --}}
        <div data-header-sentinel aria-hidden="true" style="position: absolute; top: 0; left: 0; width: 1px; height: 64px; pointer-events: none;"></div>

        <!-- Navigation -->
        <nav class="navbar navbar-expand-lg fixed-top site-header">
            <div class="container-fluid px-3 px-lg-4 d-flex flex-nowrap align-items-center justify-content-between">
                <!-- Branding -->
                <a class="navbar-brand d-flex align-items-center overflow-hidden flex-shrink-0" href="{{ route('home') }}" style="white-space: nowrap;">
                    {{-- logo-nav.png, not logo.png: the source file is 2816x1536 (1,516 KB)
                         and rendered 115x63 here, which was 51% of the page's bytes. --}}
                    <img src="{{ asset('images/logo-nav.png') }}" alt="Logo" class="me-2 shadow-sm flex-shrink-0" width="176" height="96" style="height: 40px; width: auto; object-fit: contain; transition: transform 0.3s ease;">
                    {{-- Dưới lg chỉ còn logo: dải 320-991px phải nhường hết chỗ ngang cho ô
                         tìm kiếm, vốn là lối vào chính của cả trang. --}}
                    <span class="text-emerald-700 text-truncate d-none d-lg-inline fw-bold" style="font-size: 1.15rem;">{{ __('messages.app_name') }}</span>
                </a>

                {{-- Ô tìm kiếm duy nhất của cả app, ngay trên hàng header ở mọi khổ màn hình.
                     Trước đây có ba ô — header, trang chủ, trang catalog — cùng post một `q`,
                     phải có JS ẩn bớt cho khỏi hiện hai ô một lúc. --}}
                <div class="header-search flex-grow-1 mx-2 mx-lg-4">
                    <form action="{{ route('products.search') }}" method="GET" class="w-100 position-relative" role="search" aria-label="{{ __('messages.search_placeholder') }}">
                        <label for="header-search" class="visually-hidden">{{ __('messages.search_placeholder') }}</label>
                        <input type="search" id="header-search" name="q" value="{{ request('q') }}" class="form-control rounded-pill border-0 ps-4 pe-5 shadow-sm" placeholder="{{ __('messages.search_mobile_placeholder') }}" autocomplete="off">
                        {{-- Icon kính lúp và nút micro dùng CHUNG ô 48px của `pe-5`, chỉ một
                             cái hiện. voice-search.js đặt data-voice-ready trên <html> khi
                             browser có SpeechRecognition; CSS đổi chỗ theo đó. Nhờ vậy lòng
                             trong ô không đổi một pixel — search-placeholder.js đo chỗ đó để
                             chọn bộ chuỗi, và ở 375px nó chỉ còn ~122px. --}}
                        <i class="bi bi-search header-search-icon text-emerald-600" aria-hidden="true"></i>
                        @if(config('search.voice.enabled', true))
                            <button type="button" class="header-search-mic" data-voice-search
                                    aria-label="{{ __('messages.voice_start') }}">
                                <i class="bi bi-mic" aria-hidden="true"></i>
                            </button>
                        @endif
                    </form>
                </div>

                <!-- Mobile Toggle Button -->
                <button class="navbar-toggler rounded-3 flex-shrink-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation" style="border-width: 1.5px;">
                    <i class="bi bi-list fs-4 text-emerald-600" style="font-weight: bold;"></i>
                </button>

                <!-- Navbar Menu -->
                <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
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
                            @php
                                $activeCategoryId = request('category');
                                $activeCategoryRoute = request()->route('MaDM');

                                if ($activeCategoryId === null && $activeCategoryRoute !== null) {
                                    $activeCategoryId = is_object($activeCategoryRoute)
                                        ? ($activeCategoryRoute->MaDM ?? null)
                                        : $activeCategoryRoute;
                                }
                            @endphp
                            <ul class="dropdown-menu nav-categories-menu rounded-4 mt-2" data-catalog-filter>
                                @php
                                    $navMaxCount = max(1, (int) $navCategories->max('products_count'));
                                    $navTotal = (int) $navCategories->sum('products_count');
                                @endphp
                                <li class="list-unstyled">
                                    <div class="nav-categories-head">
                                        <span class="nav-categories-label">{{ __('messages.categories') }}</span>
                                        <span class="nav-categories-total">{{ $navCategories->count() }}</span>
                                    </div>
                                </li>
                                @if($navCategories->isNotEmpty())
                                    <li class="list-unstyled">
                                        <div class="nav-categories-search">
                                            <i class="bi bi-search" aria-hidden="true"></i>
                                            <label for="nav-category-search" class="visually-hidden">{{ __('messages.filter_categories') }}</label>
                                            <input type="search" id="nav-category-search" autocomplete="off"
                                                   placeholder="{{ __('messages.filter_categories') }}"
                                                   data-catalog-filter-search>
                                        </div>
                                    </li>
                                @endif
                                <li class="list-unstyled">
                                    <div class="nav-categories-list" data-catalog-filter-list>
                                        @forelse($navCategories as $cat)
                                            @php
                                                $isActiveCategory = $activeCategoryId !== null && (string) $activeCategoryId === (string) $cat->MaDM;
                                            @endphp
                                            <a
                                                class="dropdown-item nav-category-link {{ $isActiveCategory ? 'is-active' : '' }}"
                                                href="{{ route('products.index', ['category' => $cat->MaDM]) }}"
                                                style="--depth: {{ round($cat->products_count / $navMaxCount * 100) }}%"
                                                data-folded="{{ \App\Support\VietnameseText::fold($cat->name) }}"
                                                @if($isActiveCategory) aria-current="page" @endif
                                            >
                                                <span class="nav-category-title">{{ $cat->name }}</span>
                                                <span class="nav-category-count">{{ $cat->products_count }}</span>
                                            </a>
                                        @empty
                                            <span class="px-3 py-2 text-muted small text-center">{{ __('messages.loading_categories') }}</span>
                                        @endforelse
                                        <p class="nav-categories-empty" data-catalog-filter-empty>{{ __('messages.no_category_match') }}</p>
                                    </div>
                                </li>
                                <li><hr class="dropdown-divider nav-categories-divider"></li>
                                <li>
                                    <a class="dropdown-item nav-categories-cta" href="{{ route('products.index') }}">
                                        <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i>
                                        <span>{{ __('messages.all_products') }}</span>
                                        <span class="nav-categories-cta-count">{{ $navTotal }}</span>
                                    </a>
                                </li>
                            </ul>
                        </li>

                        <!-- Health Triage Link -->
                        <li class="nav-item">
                            <a class="nav-link text-gray-700 {{ request()->routeIs('health.*') ? 'active' : '' }}" href="{{ route('health.check') }}">
                                <i class="bi bi-clipboard2-pulse me-1" aria-hidden="true"></i>{{ __('messages.health.nav') }}
                            </a>
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
                                        <x-user-avatar :user="auth()->user()" :size="36" ring />
                                        <span class="position-absolute bottom-0 end-0 bg-success rounded-circle border border-2 border-white" style="width: 10px; height: 10px;" aria-hidden="true"></span>
                                    </div>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg rounded-4 mt-2 p-2" style="min-width: 260px; background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(20px);">
                                    <!-- User Info Header -->
                                    <div class="px-3 py-3 mb-2 rounded-3" style="background: rgba(16, 185, 129, 0.08);">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <x-user-avatar :user="auth()->user()" :size="40" ring />
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
            {{-- Flashed drug warnings render wherever the user was sent back to. Scoping
                 this to the cart page meant a warning raised from a product listing was
                 flashed, consumed and never shown. --}}
            @if(session('drug_warnings') && !request()->routeIs('cart.index', 'checkout.index'))
                <div class="container pt-3">
                    <div class="glass-card rounded-4 border-0 p-3 p-md-4">
                        <x-drug-warnings :warnings="session('drug_warnings')" compact />
                    </div>
                </div>
            @endif

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
                                    <img src="{{ asset('images/logo-nav.png') }}" alt="Logo" class="me-2" width="176" height="96" style="height: 40px; width: auto;">
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
                                        {{-- h2, not h6: these are the footer's own top-level
                                             headings, and jumping h2 -> h6 broke the outline.
                                             The .h6 class keeps the visual size. --}}
                                        <h2 class="h6 fw-bold mb-3 text-gray-800 d-flex align-items-center">
                                            <i class="bi bi-grid-fill text-emerald-600 me-2"></i>
                                            {{ __('messages.categories') }}
                                        </h2>
                                        <ul class="list-unstyled mb-0">
                                            @forelse($footerCategories as $fCat)
                                            <li class="{{ !$loop->last ? 'mb-2' : 'mb-0' }}">
                                                <a href="{{ route('products.index', ['category' => $fCat->MaDM]) }}" class="text-gray-600 text-decoration-none d-flex align-items-center small hover-text-emerald-600 transition-all">
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
                                        {{-- h2, not h6: these are the footer's own top-level
                                             headings, and jumping h2 -> h6 broke the outline.
                                             The .h6 class keeps the visual size. --}}
                                        <h2 class="h6 fw-bold mb-3 text-gray-800 d-flex align-items-center">
                                            <i class="bi bi-headset text-emerald-600 me-2"></i>
                                            {{ __('messages.contact') }}
                                        </h2>
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
                                        {{-- h2, not h6: these are the footer's own top-level
                                             headings, and jumping h2 -> h6 broke the outline.
                                             The .h6 class keeps the visual size. --}}
                                        <h2 class="h6 fw-bold mb-3 text-gray-800 d-flex align-items-center">
                                            <i class="bi bi-geo-alt-fill text-emerald-600 me-2"></i>
                                            {{ __('messages.contact') }}
                                        </h2>
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
                                    Developed by <span class="fw-semibold text-emerald-600">Phạm Trường Huy</span> - MSSV: 2351220234
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
        
        <!-- SweetAlert2 comes from the Vite bundle (resources/js/app.js sets window.Swal) -->
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

        {{-- Nguồn cho panel tìm kiếm + chuỗi giao diện của nó. Một lần mỗi trang: cả
             ba ô tìm kiếm dùng chung một <template>. --}}
        @include('partials.search-panel')

        <script>
            window.PharmacySearchLang = {
                placeholders: @json(array_values(config('search.placeholder_rotation', []))),
                placeholdersWide: @json(array_values(config('search.placeholder_rotation_wide', []))),
                history: @json(__('messages.search_history')),
                historyClear: @json(__('messages.search_history_clear')),
                historyRemove: @json(__('messages.search_history_remove')),
                suggestionCategories: @json(__('messages.search_suggestion_categories')),
                suggestionProducts: @json(__('messages.search_suggestion_products')),
                viewAll: @json(__('messages.search_view_all')),
                noResults: @json(__('messages.search_no_results')),
                noResultsHint: @json(__('messages.search_no_results_hint')),
            };
        </script>

        @if(config('search.voice.enabled', true))
            <script>
                window.PharmacyVoiceLang = {
                    url: @json(route('voice.normalize')),
                    start: @json(__('messages.voice_start')),
                    listening: @json(__('messages.voice_listening')),
                    working: @json(__('messages.voice_working')),
                    heard: @json(__('messages.voice_heard')),
                    undo: @json(__('messages.voice_undo')),
                    lowConfidence: @json(__('messages.voice_low_confidence')),
                    errors: {
                        'not-allowed': @json(__('messages.voice_error_not_allowed')),
                        'service-not-allowed': @json(__('messages.voice_error_not_allowed')),
                        'no-speech': @json(__('messages.voice_error_no_speech')),
                        network: @json(__('messages.voice_error_network')),
                        timeout: @json(__('messages.voice_error_timeout')),
                        generic: @json(__('messages.voice_error_generic')),
                    },
                };
            </script>
        @endif

        @stack('modals')
        @stack('scripts')
    </body>
</html>
