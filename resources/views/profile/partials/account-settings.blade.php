<div class="profile-card">
    <div class="profile-card__head">
        <span class="profile-card__icon"><i class="bi bi-bell-fill"></i></span>
        <div>
            <h2 class="profile-card__title">{{ __('messages.profile.notifications') }}</h2>
            <p class="profile-card__subtitle">{{ __('messages.profile.notifications_desc') }}</p>
        </div>
    </div>

    {{-- ponytail: các công tắc này chưa có cột lưu trong DB, nên trạng thái không
         sống qua lần tải trang. Đánh dấu disabled + nhãn "sắp có" thay vì để chúng
         trông như đã lưu được — trước đây bật/tắt chỉ ghi console.log.
         Nâng cấp khi có bảng user_notification_preferences. --}}
    @foreach([
        ['id' => 'orderNotif', 'label' => __('messages.profile.order_notif'), 'desc' => __('messages.profile.order_notif_desc'), 'on' => true],
        ['id' => 'promoNotif', 'label' => __('messages.profile.promo_notif'), 'desc' => __('messages.profile.promo_notif_desc'), 'on' => true],
        ['id' => 'healthNotif', 'label' => __('messages.profile.health_notif'), 'desc' => __('messages.profile.health_notif_desc'), 'on' => false],
    ] as $index => $pref)
        <div class="d-flex justify-content-between align-items-center gap-3 py-3 {{ $index < 2 ? 'border-bottom' : '' }}">
            <div>
                <label class="fw-medium text-gray-900 mb-1 d-block" for="{{ $pref['id'] }}">{{ $pref['label'] }}</label>
                <small class="text-gray-500">{{ $pref['desc'] }}</small>
            </div>
            <div class="form-check form-switch flex-shrink-0">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="{{ $pref['id'] }}" @checked($pref['on']) disabled
                       aria-describedby="{{ $pref['id'] }}-note">
            </div>
        </div>
    @endforeach

    <p id="orderNotif-note" class="profile-field__hint mb-0 mt-2">{{ __('messages.profile.notifications_soon') }}</p>
</div>

<div class="profile-card">
    <div class="profile-card__head">
        <span class="profile-card__icon"><i class="bi bi-info-circle-fill"></i></span>
        <div>
            <h2 class="profile-card__title">{{ __('messages.profile.account_info') }}</h2>
            <p class="profile-card__subtitle">{{ __('messages.profile.account_info_desc') }}</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="p-3 rounded-3 bg-emerald-50">
                <small class="text-gray-500 d-block mb-1">{{ __('messages.profile.role') }}</small>
                <span class="badge bg-emerald-100 text-emerald-800 rounded-pill px-3 py-1">{{ role_text($user->role) }}</span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 rounded-3 bg-emerald-50">
                <small class="text-gray-500 d-block mb-1">{{ __('messages.profile.status_label') }}</small>
                <span class="badge {{ $user->status === 'active' ? 'bg-success' : 'bg-secondary' }} bg-opacity-10 {{ $user->status === 'active' ? 'text-success' : 'text-secondary' }} rounded-pill px-3 py-1">
                    <i class="bi {{ $user->status === 'active' ? 'bi-check-circle' : 'bi-pause-circle' }} me-1" aria-hidden="true"></i>{{ $user->status === 'active' ? __('messages.auth.status_active') : __('messages.auth.status_inactive') }}
                </span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 rounded-3 bg-emerald-50">
                <small class="text-gray-500 d-block mb-1">{{ __('messages.profile.joined_date_label') }}</small>
                <p class="fw-medium text-gray-900 mb-0">{{ $user->created_at->format('d/m/Y') }}</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 rounded-3 bg-emerald-50">
                <small class="text-gray-500 d-block mb-1">{{ __('messages.profile.last_updated_label') }}</small>
                <p class="fw-medium text-gray-900 mb-0">{{ $user->updated_at->format('d/m/Y') }}</p>
            </div>
        </div>
    </div>
</div>

<div class="profile-card profile-danger-zone">
    <div class="profile-card__head">
        <span class="profile-card__icon profile-card__icon--danger"><i class="bi bi-exclamation-triangle-fill"></i></span>
        <div>
            <h2 class="profile-card__title">{{ __('messages.profile.danger_zone') }}</h2>
            <p class="profile-card__subtitle">{{ __('messages.profile.danger_zone_desc') }}</p>
        </div>
    </div>

    <div class="alert-glass-danger mb-4 p-3 rounded-3">
        <i class="bi bi-shield-exclamation me-2" aria-hidden="true"></i>
        <strong>{{ __('messages.profile.delete_warning_title') }}</strong> {{ __('messages.profile.delete_warning_desc') }}
    </div>

    <form method="POST" action="{{ route('profile.destroy') }}" id="deleteAccountForm">
        @csrf
        @method('delete')

        <div class="mb-4">
            <label for="delete_password" class="form-label">
                <i class="bi bi-lock" aria-hidden="true"></i>{{ __('messages.profile.delete_password_label') }}
            </label>
            <input type="password"
                   class="form-control @error('password', 'userDeletion') is-invalid @enderror"
                   id="delete_password"
                   name="password"
                   autocomplete="current-password"
                   placeholder="{{ __('messages.profile.delete_password_placeholder') }}">
            @error('password', 'userDeletion')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="button" class="btn profile-btn-danger" data-delete-account-trigger>
            <i class="bi bi-trash3 me-1" aria-hidden="true"></i>{{ __('messages.profile.delete_account_btn') }}
        </button>
    </form>
</div>
