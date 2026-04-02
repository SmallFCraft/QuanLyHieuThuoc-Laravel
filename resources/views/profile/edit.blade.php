@extends('layouts.app')

@section('title', 'Hồ sơ cá nhân - Nhà Thuốc Sinh Thái')

@section('content')
<!-- Page Header -->
<section class="py-4 bg-emerald-50">
    <div class="container">
        <h1 class="fw-bold text-gray-900 mb-1">Tài Khoản Của Tôi</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none">Trang chủ</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tài khoản</li>
            </ol>
        </nav>
    </div>
</section>

<section class="py-4 py-md-5">
    <div class="container">
        <div class="row g-4">
            <!-- Sidebar Navigation (Desktop) / Tabs (Mobile) -->
            <div class="col-lg-3">
                <!-- User Card -->
                <div class="glass-card rounded-4 border-0 p-4 mb-4 text-center">
                    <div class="position-relative d-inline-block mb-3">
                        <div class="avatar-ring position-absolute top-50 start-50 translate-middle rounded-circle" 
                             style="width: 90px; height: 90px; background: rgba(16,185,129,0.15);"></div>
                        <div class="bg-emerald-500 rounded-circle d-flex align-items-center justify-content-center mx-auto position-relative" 
                             style="width: 80px; height: 80px;">
                            <span class="text-white fw-bold fs-3">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                        </div>
                        <div class="position-absolute bottom-0 end-0 bg-success rounded-circle border border-3 border-white d-flex align-items-center justify-content-center" 
                             style="width: 28px; height: 28px;">
                            <i class="bi bi-check-lg text-white" style="font-size: 0.75rem;"></i>
                        </div>
                    </div>
                    <h6 class="fw-bold text-gray-900 mb-1">{{ $user->name }}</h6>
                    <p class="text-gray-500 small mb-2">{{ $user->email }}</p>
                    <span class="badge bg-emerald-100 text-emerald-800 rounded-pill px-3 py-2 mt-1" style="font-size: 0.8rem;">
                        <i class="bi bi-shield-check me-1"></i>{{ ucfirst($user->role) }}
                    </span>
                    
                    <!-- Quick Stats -->
                    <div class="row g-2 mt-3 pt-3 border-top">
                        <div class="col-6">
                            <div class="text-center">
                                <h6 class="fw-bold text-emerald-600 mb-0">{{ auth()->user()->orders()->count() }}</h6>
                                <small class="text-gray-500">Đơn hàng</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <h6 class="fw-bold text-emerald-600 mb-0">{{ auth()->user()->orders()->where('status', 'Completed')->count() }}</h6>
                                <small class="text-gray-500">Hoàn thành</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Menu (Desktop) -->
                <div class="glass-card rounded-4 border-0 p-3 d-none d-lg-block">
                    <nav class="nav flex-column gap-1">
                        <a href="#profile-info" class="nav-link profile-nav-link active rounded-3 px-3 py-2" data-bs-toggle="pill">
                            <i class="bi bi-person me-2"></i>Thông tin cá nhân
                        </a>
                        <a href="#orders" class="nav-link profile-nav-link rounded-3 px-3 py-2" data-bs-toggle="pill">
                            <i class="bi bi-bag me-2"></i>Đơn hàng của tôi
                        </a>
                        <a href="#security" class="nav-link profile-nav-link rounded-3 px-3 py-2" data-bs-toggle="pill">
                            <i class="bi bi-shield-lock me-2"></i>Bảo mật
                        </a>
                        <a href="#settings" class="nav-link profile-nav-link rounded-3 px-3 py-2" data-bs-toggle="pill">
                            <i class="bi bi-gear me-2"></i>Cài đặt
                        </a>
                    </nav>
                </div>

                <!-- Mobile Tabs -->
                <div class="d-lg-none mb-3">
                    <ul class="nav nav-pills nav-fill glass-card rounded-4 border-0 p-2" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active rounded-3 py-2" data-bs-toggle="pill" href="#profile-info">
                                <i class="bi bi-person d-block fs-5"></i>
                                <small class="d-none d-sm-inline">Hồ sơ</small>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link rounded-3 py-2" data-bs-toggle="pill" href="#orders">
                                <i class="bi bi-bag d-block fs-5"></i>
                                <small class="d-none d-sm-inline">Đơn hàng</small>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link rounded-3 py-2" data-bs-toggle="pill" href="#security">
                                <i class="bi bi-shield-lock d-block fs-5"></i>
                                <small class="d-none d-sm-inline">Bảo mật</small>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link rounded-3 py-2" data-bs-toggle="pill" href="#settings">
                                <i class="bi bi-gear d-block fs-5"></i>
                                <small class="d-none d-sm-inline">Cài đặt</small>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-lg-9">
                <div class="tab-content">
                    <!-- Profile Information Tab -->
                    <div class="tab-pane fade show active" id="profile-info">
                        @include('profile.partials.profile-information')
                    </div>

                    <!-- Orders Tab -->
                    <div class="tab-pane fade" id="orders">
                        @include('profile.partials.order-history')
                    </div>

                    <!-- Security Tab -->
                    <div class="tab-pane fade" id="security">
                        @include('profile.partials.security-settings')
                    </div>

                    <!-- Settings Tab -->
                    <div class="tab-pane fade" id="settings">
                        @include('profile.partials.account-settings')
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

@push('styles')
<style>
/* Profile Navigation */
.profile-nav-link {
    color: #6b7280;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-weight: 500;
    position: relative;
    overflow: hidden;
}
.profile-nav-link::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    height: 100%;
    width: 3px;
    background: #10b981;
    transform: scaleY(0);
    transition: transform 0.3s ease;
}
.profile-nav-link:hover {
    background: rgba(16, 185, 129, 0.08);
    color: #059669;
    transform: translateX(4px);
}
.profile-nav-link:hover::before {
    transform: scaleY(1);
}
.profile-nav-link.active {
    background: rgba(16, 185, 129, 0.15);
    color: #059669 !important;
    font-weight: 600;
}
.profile-nav-link.active::before {
    transform: scaleY(1);
}
.nav-pills .nav-link {
    transition: all 0.3s ease;
}
.nav-pills .nav-link.active {
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
    font-weight: 600;
}
.nav-pills .nav-link:not(.active):hover {
    background: rgba(16, 185, 129, 0.08);
}

/* Avatar Animation */
@keyframes pulse-ring {
    0% {
        transform: scale(0.95);
        opacity: 1;
    }
    50% {
        transform: scale(1.05);
        opacity: 0.7;
    }
    100% {
        transform: scale(0.95);
        opacity: 1;
    }
}
.avatar-ring {
    animation: pulse-ring 3s ease-in-out infinite;
}

/* Tab Content Animation */
.tab-pane {
    animation: fadeInUp 0.4s ease-out;
}
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Fade In Animation */
@keyframes fadeIn {
    from { 
        opacity: 0; 
        transform: translateY(-10px); 
    }
    to { 
        opacity: 1; 
        transform: translateY(0); 
    }
}
.animate-fade-in {
    animation: fadeIn 0.3s ease-in;
}

/* Icon Sizes */
.icon-circle-sm {
    width: 48px;
    height: 48px;
}
.icon-circle-md {
    width: 56px;
    height: 56px;
}

/* Input Focus Effects */
.input-group-text {
    transition: all 0.3s ease;
}
.form-control:focus + .input-group-text,
.input-group-text + .form-control:focus {
    border-color: #10b981;
}

/* Mobile Responsive */
@media (max-width: 991.98px) {
    .profile-nav-link {
        text-align: center;
    }
    .nav-pills .nav-link {
        font-size: 0.75rem;
    }
}

/* Smooth Scroll */
html {
    scroll-behavior: smooth;
}
</style>
@endpush

@push('scripts')
<script>
(function() {
    'use strict';
    
    // Function to activate tab based on hash
    function activateTabFromHash() {
        const hash = window.location.hash;
        if (hash) {
            // Check if hash is for a specific order (e.g., #order-23)
            if (hash.startsWith('#order-')) {
                // Activate the orders tab
                const ordersTab = document.querySelector('a[data-bs-toggle="pill"][href="#orders"]');
                if (ordersTab) {
                    ordersTab.click();
                    
                    // Wait for tab to be shown, then scroll to the order
                    setTimeout(() => {
                        const orderElement = document.querySelector(hash);
                        if (orderElement) {
                            orderElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            // Add highlight effect
                            orderElement.style.transition = 'all 0.3s ease';
                            orderElement.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.3)';
                            setTimeout(() => {
                                orderElement.style.boxShadow = '';
                            }, 2000);
                        }
                    }, 300);
                }
            } else {
                // Find the tab link with matching href
                const tab = document.querySelector(`a[data-bs-toggle="pill"][href="${hash}"]`);
                if (tab) {
                    // Trigger click event to activate the tab
                    tab.click();
                }
            }
        }
    }
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        // Activate tab on page load
        activateTabFromHash();
        
        // Update URL hash when tab changes
        document.querySelectorAll('a[data-bs-toggle="pill"]').forEach(tab => {
            tab.addEventListener('shown.bs.tab', function (e) {
                const href = e.target.getAttribute('href');
                if (href) {
                    history.pushState(null, null, href);
                }
            });
        });
        
        // Listen for hash changes
        window.addEventListener('hashchange', activateTabFromHash);
    }
    
    // Toggle password visibility
    window.togglePassword = function(fieldId) {
        const field = document.getElementById(fieldId);
        const icon = document.getElementById(fieldId + '_icon');
        
        if (field && icon) {
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        }
    };
})();
</script>
@endpush
@endsection
