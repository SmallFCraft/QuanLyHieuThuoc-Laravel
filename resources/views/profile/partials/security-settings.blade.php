<!-- Security Settings Section -->
<!-- Change Password -->
<div class="glass-card rounded-4 border-0 p-4 p-md-5 mb-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="bg-emerald-100 text-emerald-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 icon-circle-md">
            <i class="bi bi-shield-lock-fill fs-3"></i>
        </div>
        <div>
            <h5 class="fw-bold text-gray-900 mb-0">{{ __('messages.profile.change_password') }}</h5>
            <p class="text-gray-500 small mb-0">{{ __('messages.profile.change_password_desc') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('password.update') }}" id="passwordForm">
        @csrf
        @method('put')

        <div class="mb-3">
            <label for="current_password" class="form-label fw-medium text-gray-700">
                <i class="bi bi-lock text-emerald-600 me-1"></i>{{ __('messages.profile.current_password') }}
            </label>
            <div class="input-group">
                <input type="password" 
                       class="form-control rounded-start-3 shadow-none @error('current_password', 'updatePassword') is-invalid @enderror"
                       id="current_password" 
                       name="current_password"
                       placeholder="{{ __('messages.profile.current_password_placeholder') }}">
                <button class="btn btn-outline-secondary rounded-end-3" type="button" onclick="togglePassword('current_password')">
                    <i class="bi bi-eye" id="current_password_icon"></i>
                </button>
                @error('current_password', 'updatePassword')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="mb-3">
            <label for="password" class="form-label fw-medium text-gray-700">
                <i class="bi bi-key text-emerald-600 me-1"></i>{{ __('messages.profile.new_password') }}
            </label>
            <div class="input-group">
                <input type="password" 
                       class="form-control rounded-start-3 shadow-none @error('password', 'updatePassword') is-invalid @enderror"
                       id="password" 
                       name="password"
                       placeholder="{{ __('messages.profile.new_password_placeholder') }}">
                <button class="btn btn-outline-secondary rounded-end-3" type="button" onclick="togglePassword('password')">
                    <i class="bi bi-eye" id="password_icon"></i>
                </button>
                @error('password', 'updatePassword')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <small class="text-gray-500">{{ __('messages.profile.new_password_hint') }}</small>
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label fw-medium text-gray-700">
                <i class="bi bi-check-circle text-emerald-600 me-1"></i>{{ __('messages.profile.confirm_password') }}
            </label>
            <div class="input-group">
                <input type="password" 
                       class="form-control rounded-start-3 shadow-none"
                       id="password_confirmation" 
                       name="password_confirmation"
                       placeholder="{{ __('messages.profile.confirm_password_placeholder') }}">
                <button class="btn btn-outline-secondary rounded-end-3" type="button" onclick="togglePassword('password_confirmation')">
                    <i class="bi bi-eye" id="password_confirmation_icon"></i>
                </button>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-glass-primary rounded-pill px-4 py-2 fw-medium">
                <i class="bi bi-key me-1"></i>{{ __('messages.profile.update_password') }}
            </button>
            
            @if (session('status') === 'password-updated')
                <span class="text-success small fw-medium animate-fade-in">
                    <i class="bi bi-check-circle-fill me-1"></i>{{ __('messages.profile.password_updated') }}
                </span>
            @endif
        </div>
    </form>
</div>

<!-- Security Tips -->
<div class="alert-glass-info p-4 rounded-4 mb-4">
    <h6 class="fw-bold text-gray-900 mb-3">
        <i class="bi bi-lightbulb text-warning me-2"></i>{{ __('messages.profile.security_tips') }}
    </h6>
    <ul class="list-unstyled mb-0">
        <li class="mb-2">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span class="text-gray-700">{{ __('messages.profile.security_tip_1') }}</span>
        </li>
        <li class="mb-2">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span class="text-gray-700">{{ __('messages.profile.security_tip_2') }}</span>
        </li>
        <li class="mb-2">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span class="text-gray-700">{{ __('messages.profile.security_tip_3') }}</span>
        </li>
        <li class="mb-0">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span class="text-gray-700">{{ __('messages.profile.security_tip_4') }}</span>
        </li>
    </ul>
</div>
