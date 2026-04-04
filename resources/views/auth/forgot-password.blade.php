<x-guest-layout>
    <div class="text-center mb-4">
        <h4 class="fw-bold text-gray-800 mb-1">{{ __('messages.auth.forgot_password.title') }}</h4>
        <p class="text-gray-500 small mb-0">{{ __('messages.auth.forgot_password.description') }}</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success rounded-3 border-0 small py-2 mb-3" style="background: #ecfdf5; color: #065f46;">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <!-- Email Address -->
        <div class="mb-4">
            <label for="email" class="form-label fw-medium text-gray-700 small">{{ __('messages.auth.forgot_password.email_label') }}</label>
            <div class="input-group">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control bg-gray-50 border-start-0 shadow-none @error('email') is-invalid @enderror"
                       id="email" name="email" value="{{ old('email') }}" required autofocus
                       placeholder="email@example.com">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <button type="submit" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
            {{ __('messages.auth.forgot_password.submit_btn') }}
        </button>
    </form>

    <div class="text-center mt-4">
        <a href="{{ route('login') }}" class="text-gray-500 small text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>{{ __('messages.auth.forgot_password.back_to_login') }}
        </a>
    </div>
</x-guest-layout>
