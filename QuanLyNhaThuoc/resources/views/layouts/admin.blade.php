<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Quản Lý Nhà Thuốc') - Admin</title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    
    <style>
        :root {
            --sidebar-width: 270px;
            --sidebar-collapsed: 80px;
            --primary: #059669;
            --primary-light: #10b981;
            --primary-bg: #ecfdf5;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            min-height: 100vh;
        }

        /* Sidebar */
        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, #064e3b 0%, #065f46 50%, #047857 100%);
            padding: 0;
            overflow-y: auto;
            z-index: 1040;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 4px 0 24px rgba(0, 0, 0, 0.15);
        }

        .sidebar-brand {
            padding: 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-brand h5 {
            color: #fff;
            font-weight: 700;
            font-size: 1.1rem;
            margin: 0;
        }

        .sidebar-brand .brand-icon {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #6ee7b7;
        }

        .sidebar-menu {
            list-style: none;
            padding: 16px 12px;
            margin: 0;
        }

        .sidebar-menu .menu-label {
            text-transform: uppercase;
            font-size: 0.65rem;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.4);
            letter-spacing: 1.5px;
            padding: 12px 16px 8px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 11px 16px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.2s ease;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 2px;
        }

        .sidebar-menu a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .sidebar-menu a i {
            margin-right: 12px;
            font-size: 1.15rem;
            width: 22px;
            text-align: center;
        }

        /* Main Content */
        .admin-main {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Top Bar */
        .admin-topbar {
            background: #fff;
            padding: 16px 30px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

        /* Content Area */
        .admin-content {
            padding: 30px;
        }

        /* Stat Cards */
        .stat-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #f1f5f9;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            border-radius: 4px 0 0 4px;
        }

        .stat-card.revenue::before { background: linear-gradient(180deg, #10b981, #059669); }
        .stat-card.orders::before { background: linear-gradient(180deg, #f59e0b, #d97706); }
        .stat-card.expiring::before { background: linear-gradient(180deg, #ef4444, #dc2626); }
        .stat-card.lowstock::before { background: linear-gradient(180deg, #3b82f6, #2563eb); }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.08);
        }

        .stat-card .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        .stat-card .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e293b;
        }

        .stat-card .stat-label {
            font-size: 0.8rem;
            color: #94a3b8;
            font-weight: 500;
        }

        /* Chart Card */
        .chart-card {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #f1f5f9;
        }

        .chart-card h5 {
            font-weight: 700;
            color: #1e293b;
            font-size: 1rem;
        }

        /* Glass Card override for admin */
        .glass-card {
            background: #fff;
            backdrop-filter: none;
            border-radius: 16px;
            border: 1px solid #f1f5f9;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            padding: 24px;
        }

        /* Responsive */
        @media (max-width: 991px) {
            .admin-sidebar {
                transform: translateX(-100%);
            }
            .admin-sidebar.show {
                transform: translateX(0);
            }
            .admin-main {
                margin-left: 0;
            }
            .sidebar-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 1035;
                display: none;
            }
            .sidebar-overlay.show {
                display: block;
            }
        }
    </style>

    @stack('styles')
</head>
<body>
    <!-- Sidebar Overlay (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-brand d-flex align-items-center gap-3">
            <img src="{{ asset('images/logo.png') }}" alt="Logo" style="height: 40px; width: auto; object-fit: contain;">
            <div>
                <h5 class="mb-0">Nhà Thuốc</h5>
                <small class="text-white-50" style="font-size: 0.7rem;">Bảng Điều Khiển</small>
            </div>
        </div>
        <ul class="sidebar-menu">
            <li class="menu-label">Tổng quan</li>
            <li>
                <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-grid-1x2-fill"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <li class="menu-label">Quản lý</li>
            <li>
                <a href="{{ route('admin.products.index') }}" class="{{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
                    <i class="bi bi-box-seam-fill"></i>
                    <span>Sản Phẩm</span>
                </a>
            </li>
            <li>
                <a href="{{ route('admin.orders.index') }}" class="{{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
                    <i class="bi bi-bag-check-fill"></i>
                    <span>Đơn Hàng</span>
                </a>
            </li>
            <li>
                <a href="{{ route('admin.categories.index') }}" class="{{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
                    <i class="bi bi-tags-fill"></i>
                    <span>Danh Mục</span>
                </a>
            </li>
            <li>
                <a href="{{ route('admin.inventory.index') }}" class="{{ request()->routeIs('admin.inventory.*') ? 'active' : '' }}">
                    <i class="bi bi-boxes"></i>
                    <span>Tồn Kho</span>
                </a>
            </li>
            
            <li class="menu-label">Hệ thống</li>
            <li>
                <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                    <i class="bi bi-people-fill"></i>
                    <span>Người Dùng</span>
                </a>
            </li>
            <li>
                <a href="{{ route('home') }}">
                    <i class="bi bi-house-door"></i>
                    <span>Về Trang Chủ</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="admin-main">
        <!-- Top Bar -->
        <div class="admin-topbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-sm btn-light d-lg-none rounded-circle" id="sidebarToggle" style="width:40px; height:40px;">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div>
                    <h5 class="mb-0 fw-bold text-gray-800">@yield('page-title', 'Dashboard')</h5>
                    <small class="text-gray-400">@yield('page-subtitle', date('l, d/m/Y'))</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown">
                    <button class="btn btn-light rounded-pill d-flex align-items-center gap-2 shadow-sm border-0 px-3" type="button" data-bs-toggle="dropdown">
                        <img src="https://ui-avatars.com/api/?name={{ urlencode(Auth::user()->name) }}&background=059669&color=fff&rounded=true&size=28" alt="Avatar" class="rounded-circle">
                        <span class="fw-medium text-gray-700 d-none d-md-inline">{{ Auth::user()->name }}</span>
                        <i class="bi bi-chevron-down small text-gray-400"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg rounded-3" style="min-width: 200px;">
                        <li class="px-3 py-2 border-bottom">
                            <small class="text-gray-400">Đăng nhập với</small>
                            <p class="mb-0 fw-medium text-gray-700 small">{{ Auth::user()->email }}</p>
                        </li>
                        <li><a class="dropdown-item py-2" href="{{ route('profile.edit') }}"><i class="bi bi-person me-2 text-gray-400"></i>Hồ sơ</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item py-2 text-danger"><i class="bi bi-box-arrow-right me-2"></i>Đăng xuất</button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Page Content -->
        <div class="admin-content">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm" role="alert" style="background: #ecfdf5; color: #065f46;">
                    <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm" role="alert">
                    <i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            @yield('content')
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Sidebar toggle for mobile
        const sidebarToggle = document.getElementById('sidebarToggle');
        const adminSidebar = document.getElementById('adminSidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        sidebarToggle?.addEventListener('click', function() {
            adminSidebar.classList.toggle('show');
            sidebarOverlay.classList.toggle('show');
        });

        sidebarOverlay?.addEventListener('click', function() {
            adminSidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
        });

        // CSRF token for AJAX requests
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
    </script>

    @stack('scripts')
</body>
</html>
