<x-guest-layout>
    <div class="text-center mb-4">
        <h4 class="fw-bold text-gray-800 mb-1">{{ __('messages.auth.register.title') }}</h4>
        <p class="text-gray-500 small mb-0">{{ __('messages.auth.register.welcome') }}</p>
    </div>

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Name -->
        <div class="mb-3">
            <label for="name" class="form-label fw-medium text-gray-700 small">{{ __('messages.auth.register.fullname') }}</label>
            <div class="input-group">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-person"></i></span>
                <input type="text" class="form-control bg-gray-50 border-start-0 shadow-none @error('name') is-invalid @enderror"
                       id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name"
                       placeholder="Nguyễn Văn A">
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <!-- Email Address -->
        <div class="mb-3">
            <label for="email" class="form-label fw-medium text-gray-700 small">{{ __('messages.auth.email_address') }}</label>
            <div class="input-group">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control bg-gray-50 border-start-0 shadow-none @error('email') is-invalid @enderror"
                       id="email" name="email" value="{{ old('email') }}" required autocomplete="username"
                       placeholder="email@example.com">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <!-- Password -->
        <div class="mb-3">
            <label for="password" class="form-label fw-medium text-gray-700 small">{{ __('messages.auth.password') }}</label>
            <div class="input-group">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control bg-gray-50 border-start-0 shadow-none @error('password') is-invalid @enderror"
                       id="password" name="password" required autocomplete="new-password"
                       placeholder="••••••••">
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <!-- Confirm Password -->
        <div class="mb-4">
            <label for="password_confirmation" class="form-label fw-medium text-gray-700 small">{{ __('messages.auth.confirm_password.title') }}</label>
            <div class="input-group">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control bg-gray-50 border-start-0 shadow-none @error('password_confirmation') is-invalid @enderror"
                       id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                       placeholder="••••••••">
                @error('password_confirmation')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <button type="submit" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
            <i class="bi bi-person-plus me-2"></i>{{ __('messages.auth.register.submit_btn') }}
        </button>
    </form>

    <div class="text-center mt-4">
        <p class="text-gray-500 small mb-0">
            {{ __('messages.auth.register.already_have_account') }}
            <a href="{{ route('login') }}" class="text-emerald-600 fw-medium text-decoration-none">{{ __('messages.auth.register.login_now') }}</a>
        </p>
    </div>
</x-guest-layout>
