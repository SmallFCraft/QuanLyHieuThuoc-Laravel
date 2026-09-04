@extends('layouts.app')

@section('title', __('messages.profile.title') . ' - ' . __('messages.app_name'))

@push('scripts')
{{-- Chuỗi cho profile.js. Nằm ở đây chứ không hard-code trong JS, để bản dịch vẫn
     đi qua lang/vi/profile.php như phần còn lại của trang. --}}
@php
    // Mảng dựng ngoài @json: Blade không phân tích được mảng nhiều dòng bên trong
    // một directive và báo "Unclosed '['".
    $deleteConfirmItems = [
        __('messages.profile.js.delete_confirm_li1'),
        __('messages.profile.js.delete_confirm_li2'),
        __('messages.profile.js.delete_confirm_li3'),
        __('messages.profile.js.delete_confirm_li4'),
    ];
@endphp
<script>
    window.PharmacyProfileLang = {
        draftRestored: @json(__('messages.profile.draft_restored')),
        passwordShow: @json(__('messages.profile.password_show')),
        passwordHide: @json(__('messages.profile.password_hide')),
        strengthWeak: @json(__('messages.profile.password_strength_weak')),
        strengthFair: @json(__('messages.profile.password_strength_fair')),
        strengthGood: @json(__('messages.profile.password_strength_good')),
        strengthStrong: @json(__('messages.profile.password_strength_strong')),
        deleteAccount: {
            title: @json(__('messages.profile.js.delete_confirm_title')),
            intro: @json(__('messages.profile.js.delete_confirm_text1')),
            items: @json($deleteConfirmItems),
            warning: @json(__('messages.profile.js.delete_confirm_warning')),
            confirm: @json(__('messages.profile.js.delete_btn')),
            cancel: @json(__('messages.profile.js.cancel_btn')),
        },
    };
</script>
@endpush

@section('content')
<div class="profile-page">
    <section class="py-4 bg-emerald-50">
        <div class="container">
            <h1 class="fw-bold text-gray-900 mb-1">{{ __('messages.profile.title') }}</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('home') }}" class="text-emerald-600 text-decoration-none">{{ __('messages.home') }}</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ __('messages.profile.breadcrumb_account') }}</li>
                </ol>
            </nav>
        </div>
    </section>

    <section class="py-4 py-md-5">
        <div class="container">
            @php
                $tabs = [
                    ['id' => 'overview', 'icon' => 'speedometer2', 'label' => __('messages.profile.nav_overview'), 'short' => __('messages.profile.mobile_overview')],
                    ['id' => 'profile-info', 'icon' => 'person', 'label' => __('messages.profile.nav_info'), 'short' => __('messages.profile.mobile_info')],
                    ['id' => 'orders', 'icon' => 'bag', 'label' => __('messages.profile.nav_orders'), 'short' => __('messages.profile.mobile_orders')],
                    ['id' => 'favorites', 'icon' => 'heart', 'label' => __('messages.profile.nav_favorites'), 'short' => __('messages.profile.mobile_favorites')],
                    ['id' => 'security', 'icon' => 'shield-lock', 'label' => __('messages.profile.nav_security'), 'short' => __('messages.profile.mobile_security')],
                    ['id' => 'health-profile', 'icon' => 'heart-pulse', 'label' => __('messages.health_profile.nav'), 'short' => __('messages.health_profile.mobile_nav')],
                    ['id' => 'settings', 'icon' => 'gear', 'label' => __('messages.profile.nav_settings'), 'short' => __('messages.profile.mobile_settings')],
                ];
            @endphp

            @if(session('status') === 'avatar-updated' || session('status') === 'avatar-removed')
                <div class="profile-flash mb-3" role="status">
                    <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                    <span>{{ session('status') === 'avatar-updated' ? __('messages.profile.avatar_updated') : __('messages.profile.avatar_removed') }}</span>
                </div>
            @endif

            <div class="row g-4">
                <div class="col-lg-3">
                    @include('profile.partials.user-card')

                    {{-- Sidebar dọc từ lg trở lên; dưới đó là dải tab cuộn ngang bên dưới.
                         role="tablist" bắt buộc: Bootstrap Tab tìm container qua
                         closest('.list-group, .nav, [role="tablist"]') và im lặng không
                         làm gì nếu không thấy. --}}
                    <div class="profile-card d-none d-lg-block" style="padding: 0.75rem;">
                        <nav class="profile-nav" role="tablist" aria-label="{{ __('messages.profile.title') }}">
                            @foreach($tabs as $index => $tab)
                                <a href="#{{ $tab['id'] }}"
                                   class="profile-nav-link {{ $index === 0 ? 'active' : '' }}"
                                   data-bs-toggle="pill"
                                   role="tab"
                                   aria-selected="{{ $index === 0 ? 'true' : 'false' }}">
                                    <i class="bi bi-{{ $tab['icon'] }}" aria-hidden="true"></i>
                                    <span>{{ $tab['label'] }}</span>
                                    @if($tab['id'] === 'favorites')
                                        <span class="profile-nav-link__badge" data-favorites-count>{{ $favoriteItems->total() }}</span>
                                    @elseif($tab['id'] === 'orders' && $orderCounts->sum() > 0)
                                        <span class="profile-nav-link__badge">{{ $orderCounts->sum() }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </nav>
                    </div>

                    <div class="profile-card d-lg-none" style="padding: 0;">
                        <div class="profile-tabbar" role="tablist" aria-label="{{ __('messages.profile.title') }}">
                            @foreach($tabs as $index => $tab)
                                <a href="#{{ $tab['id'] }}"
                                   class="profile-tabbar__link {{ $index === 0 ? 'active' : '' }}"
                                   data-bs-toggle="pill"
                                   role="tab"
                                   aria-selected="{{ $index === 0 ? 'true' : 'false' }}">
                                    <i class="bi bi-{{ $tab['icon'] }}" aria-hidden="true"></i>
                                    <span>{{ $tab['short'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="col-lg-9">
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="overview" role="tabpanel">
                            @include('profile.partials.overview')
                        </div>

                        <div class="tab-pane fade" id="profile-info" role="tabpanel">
                            @include('profile.partials.profile-information')
                        </div>

                        <div class="tab-pane fade" id="orders" role="tabpanel">
                            @include('profile.partials.order-history')
                        </div>

                        <div class="tab-pane fade" id="favorites" role="tabpanel">
                            @include('profile.partials.favorites')
                        </div>

                        <div class="tab-pane fade" id="security" role="tabpanel">
                            @include('profile.partials.security-settings')
                        </div>

                        <div class="tab-pane fade" id="health-profile" role="tabpanel">
                            @include('profile.partials.health-profile')
                        </div>

                        <div class="tab-pane fade" id="settings" role="tabpanel">
                            @include('profile.partials.account-settings')
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection
