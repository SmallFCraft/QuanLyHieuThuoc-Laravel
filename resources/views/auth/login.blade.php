<x-guest-layout>
    <div class="text-center mb-4">
        <h4 class="fw-bold text-gray-800 mb-1">{{ __('messages.auth.login.title') }}</h4>
        <p class="text-gray-500 small mb-0">{{ __('messages.auth.login.welcome') }}</p>
    </div>

    <!-- Session Status -->
    @if (session('status'))
        <div class="alert alert-success rounded-3 border-0 small py-2 mb-3" style="background: #ecfdf5; color: #065f46;">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email -->
        <div class="mb-3">
            <label for="email" class="form-label fw-medium text-gray-700 small">{{ __('messages.auth.email_address') }}</label>
            <div class="input-group">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control bg-gray-50 border-start-0 shadow-none @error('email') is-invalid @enderror"
                       id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       placeholder="email@example.com">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <!-- Password -->
        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <label for="password" class="form-label fw-medium text-gray-700 small mb-0">{{ __('messages.auth.password') }}</label>
                @if (Route::has('password.request'))
                    <a class="text-emerald-600 small text-decoration-none" href="{{ route('password.request') }}">
                        {{ __('messages.auth.login.forgot_password') }}
                    </a>
                @endif
            </div>
            <div class="input-group mt-1">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-lock"></i></span>
                <input type="password" class="form-control bg-gray-50 border-start-0 shadow-none @error('password') is-invalid @enderror"
                       id="password" name="password" required autocomplete="current-password"
                       placeholder="••••••••">
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <!-- Remember Me -->
        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" id="remember_me" name="remember"
                   style="border-color: #d1d5db;">
            <label class="form-check-label small text-gray-600" for="remember_me">
                {{ __('messages.auth.login.remember_me') }}
            </label>
        </div>

        <button type="submit" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
            <i class="bi bi-box-arrow-in-right me-2"></i>{{ __('messages.auth.login.submit_btn') }}
        </button>
    </form>

    <div class="text-center mt-4">
        <p class="text-gray-500 small mb-0">
            {{ __('messages.auth.login.no_account') }}
            <a href="{{ route('register') }}" class="text-emerald-600 fw-medium text-decoration-none">{{ __('messages.auth.login.register_now') }}</a>
        </p>
    </div>
</x-guest-layout>
