<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Quản lý nhà thuốc') - Admin</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo-icon.svg') }}?v={{ filemtime(public_path('images/logo-icon.svg')) }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v={{ filemtime(public_path('favicon.ico')) }}">
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
    @stack('styles')
</head>
<body class="admin-body">
    <div class="admin-shell">
        <div class="admin-overlay" id="sidebarOverlay" aria-hidden="true"></div>

        <aside class="admin-sidebar" id="adminSidebar" aria-label="Điều hướng quản trị">
            <div class="admin-sidebar__brand">
                <img src="{{ asset('images/logo-icon.svg') }}" alt="" width="40" height="40">
                <div>
                    <p class="admin-sidebar__brand-title">Nhà Thuốc</p>
                    <span class="admin-sidebar__brand-subtitle">Bảng điều khiển</span>
                </div>
            </div>

            <ul class="admin-nav">
                <li class="admin-nav__label">Tổng quan</li>
                <li>
                    <a href="{{ route('admin.dashboard') }}" class="admin-nav__link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>
                        <i class="bi bi-grid-1x2-fill" aria-hidden="true"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <li class="admin-nav__label">Quản lý</li>
                <li>
                    <a href="{{ route('admin.users.index') }}" class="admin-nav__link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif>
                        <i class="bi bi-people-fill" aria-hidden="true"></i>
                        <span>Người dùng</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.products.index') }}" class="admin-nav__link {{ request()->routeIs('admin.products.*') ? 'active' : '' }}" @if(request()->routeIs('admin.products.*')) aria-current="page" @endif>
                        <i class="bi bi-box-seam-fill" aria-hidden="true"></i>
                        <span>Sản phẩm</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.orders.index') }}" class="admin-nav__link {{ request()->routeIs('admin.orders.*') ? 'active' : '' }}" @if(request()->routeIs('admin.orders.*')) aria-current="page" @endif>
                        <i class="bi bi-bag-check-fill" aria-hidden="true"></i>
                        <span>Đơn hàng</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.categories.index') }}" class="admin-nav__link {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}" @if(request()->routeIs('admin.categories.*')) aria-current="page" @endif>
                        <i class="bi bi-tags-fill" aria-hidden="true"></i>
                        <span>Danh mục</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.inventory.index') }}" class="admin-nav__link {{ request()->routeIs('admin.inventory.*') ? 'active' : '' }}" @if(request()->routeIs('admin.inventory.*')) aria-current="page" @endif>
                        <i class="bi bi-boxes" aria-hidden="true"></i>
                        <span>Tồn kho</span>
                    </a>
                </li>

                <li class="admin-nav__label">Hệ thống</li>
                <li>
                    <a href="{{ route('admin.chatSessions.index') }}" class="admin-nav__link {{ request()->routeIs('admin.chatSessions.*') ? 'active' : '' }}" @if(request()->routeIs('admin.chatSessions.*')) aria-current="page" @endif>
                        <i class="bi bi-chat-dots-fill" aria-hidden="true"></i>
                        <span>Phiên chat AI</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.triageReports.index') }}" class="admin-nav__link {{ request()->routeIs('admin.triageReports.*') ? 'active' : '' }}" @if(request()->routeIs('admin.triageReports.*')) aria-current="page" @endif>
                        <i class="bi bi-clipboard2-pulse-fill" aria-hidden="true"></i>
                        <span>Báo cáo sàng lọc</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('home') }}" class="admin-nav__link">
                        <i class="bi bi-house-door" aria-hidden="true"></i>
                        <span>Về trang chủ</span>
                    </a>
                </li>
            </ul>
        </aside>

        <main class="admin-main">
            <header class="admin-topbar">
                <div class="d-flex align-items-center gap-3 min-w-0">
                    <button class="btn btn-light admin-icon-button d-lg-none" id="sidebarToggle" type="button" aria-label="Mở menu quản trị" aria-controls="adminSidebar" aria-expanded="false">
                        <i class="bi bi-list fs-5" aria-hidden="true"></i>
                    </button>
                    <div class="min-w-0">
                        <h1 class="admin-page-title">@yield('page-title', 'Dashboard')</h1>
                        <p class="admin-page-subtitle">@yield('page-subtitle', now()->locale('vi')->translatedFormat('l, d/m/Y'))</p>
                    </div>
                </div>

                <div class="dropdown flex-shrink-0">
                    <button class="btn btn-light d-flex align-items-center gap-2 px-2 px-md-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="https://ui-avatars.com/api/?name={{ urlencode(Auth::user()->name) }}&background=0f766e&color=fff&rounded=true&size=32" alt="Avatar {{ Auth::user()->name }}" class="rounded-circle" width="32" height="32">
                        <span class="fw-semibold d-none d-md-inline">{{ Auth::user()->name }}</span>
                        <i class="bi bi-chevron-down small text-secondary" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li class="px-3 py-2 border-bottom">
                            <small class="text-secondary">Đăng nhập với</small>
                            <p class="mb-0 fw-semibold small">{{ Auth::user()->email }}</p>
                        </li>
                        <li>
                            <a class="dropdown-item py-2" href="{{ route('profile.edit') }}">
                                <i class="bi bi-person me-2 text-secondary" aria-hidden="true"></i>Hồ sơ
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item py-2 text-danger">
                                    <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Đăng xuất
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </header>

            <div class="admin-content">
                @if(session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="status">
                        <i class="bi bi-check-circle me-2" aria-hidden="true"></i>{{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Đóng"></button>
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-circle me-2" aria-hidden="true"></i>{{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Đóng"></button>
                    </div>
                @endif

                @yield('content')
            </div>
        </main>
    </div>

    @stack('scripts')
</body>
</html>
