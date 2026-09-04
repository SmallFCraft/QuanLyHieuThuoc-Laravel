<div class="profile-card">
    <div class="profile-card__head">
        <span class="profile-card__icon"><i class="bi bi-shield-lock-fill"></i></span>
        <div>
            <h2 class="profile-card__title">{{ __('messages.profile.change_password') }}</h2>
            <p class="profile-card__subtitle">{{ __('messages.profile.change_password_desc') }}</p>
        </div>
    </div>

    @if (session('status') === 'password-updated')
        <div class="profile-flash mb-4" role="status">
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <span>{{ __('messages.profile.password_updated') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" id="passwordForm">
        @csrf
        @method('put')

        @foreach([
            ['id' => 'current_password', 'label' => __('messages.profile.current_password'), 'icon' => 'lock', 'placeholder' => __('messages.profile.current_password_placeholder'), 'autocomplete' => 'current-password', 'hint' => null],
            ['id' => 'password', 'label' => __('messages.profile.new_password'), 'icon' => 'key', 'placeholder' => __('messages.profile.new_password_placeholder'), 'autocomplete' => 'new-password', 'hint' => __('messages.profile.new_password_hint')],
            ['id' => 'password_confirmation', 'label' => __('messages.profile.confirm_password'), 'icon' => 'check-circle', 'placeholder' => __('messages.profile.confirm_password_placeholder'), 'autocomplete' => 'new-password', 'hint' => null],
        ] as $field)
            <div class="mb-3">
                <label for="{{ $field['id'] }}" class="form-label form-label--required">
                    <i class="bi bi-{{ $field['icon'] }}" aria-hidden="true"></i>{{ $field['label'] }}
                </label>
                <div class="input-group">
                    {{-- Không có icon dẫn đầu: nhãn phía trên đã mang đúng icon đó, và
                         41px của input-group-text làm placeholder bị cắt ở 320px. --}}
                    <input type="password"
                           class="form-control @error($field['id'], 'updatePassword') is-invalid @enderror"
                           id="{{ $field['id'] }}"
                           name="{{ $field['id'] }}"
                           autocomplete="{{ $field['autocomplete'] }}"
                           placeholder="{{ $field['placeholder'] }}"
                           style="border-radius: var(--profile-radius-sm) 0 0 var(--profile-radius-sm); border-left-width: 1px;">
                    {{-- Nút hiện/ẩn mật khẩu: aria-pressed để screen reader biết đang ở
                         trạng thái nào, không chỉ dựa vào hình con mắt. --}}
                    <button class="btn profile-btn-ghost" type="button"
                            data-password-toggle="{{ $field['id'] }}"
                            aria-pressed="false"
                            aria-label="{{ __('messages.profile.password_show') }}"
                            title="{{ __('messages.profile.password_show') }}"
                            style="border-radius: 0 var(--profile-radius-sm) var(--profile-radius-sm) 0; padding-inline: 0.9rem;">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
                @error($field['id'], 'updatePassword')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                @if($field['hint'])
                    <span class="profile-field__hint">{{ $field['hint'] }}</span>
                @endif
                @if($field['id'] === 'password')
                    {{-- Chỉ báo độ mạnh mật khẩu, cập nhật khi người dùng nhập. Kèm chữ,
                         vì một thanh màu không nói được gì với screen reader. --}}
                    <div class="profile-progress mt-2" data-password-strength-track hidden>
                        <div class="profile-progress__bar" data-password-strength-bar style="width: 0"></div>
                    </div>
                    <span class="profile-field__hint" data-password-strength-text aria-live="polite"></span>
                @endif
            </div>
        @endforeach

        <button type="submit" class="btn profile-btn-primary mt-2">
            <i class="bi bi-key me-1" aria-hidden="true"></i>{{ __('messages.profile.update_password') }}
        </button>
    </form>
</div>

<div class="profile-card">
    <div class="profile-card__head">
        <span class="profile-card__icon"><i class="bi bi-lightbulb-fill"></i></span>
        <div>
            <h2 class="profile-card__title">{{ __('messages.profile.security_tips') }}</h2>
        </div>
    </div>

    <ul class="profile-overview-list">
        @foreach(['security_tip_1', 'security_tip_2', 'security_tip_3', 'security_tip_4'] as $tip)
            <li class="profile-overview-item">
                <span class="profile-overview-item__icon"><i class="bi bi-check-lg"></i></span>
                <span class="profile-overview-item__body">
                    <span class="text-gray-700 small">{{ __("messages.profile.{$tip}") }}</span>
                </span>
            </li>
        @endforeach
    </ul>
</div>
