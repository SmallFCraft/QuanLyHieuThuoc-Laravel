<x-guest-layout>
    <div class="text-center mb-4">
        <h4 class="fw-bold text-gray-800 mb-1">Cài lại mật khẩu</h4>
        <p class="text-gray-500 small mb-0">Thiết lập mật khẩu mới cho tài khoản của bạn.</p>
    </div>

    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <!-- Password Reset Token -->
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <!-- Email Address -->
        <div class="mb-3">
            <label for="email" class="form-label fw-medium text-gray-700 small">Email</label>
            <div class="input-group">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-envelope"></i></span>
                <input type="email" class="form-control bg-gray-50 border-start-0 shadow-none @error('email') is-invalid @enderror"
                       id="email" name="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="username"
                       placeholder="email@example.com">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <!-- Password -->
        <div class="mb-3">
            <label for="password" class="form-label fw-medium text-gray-700 small">Mật khẩu mới</label>
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
            <label for="password_confirmation" class="form-label fw-medium text-gray-700 small">Xác nhận mật khẩu</label>
            <div class="input-group">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-lock-check"></i></span>
                <input type="password" class="form-control bg-gray-50 border-start-0 shadow-none @error('password_confirmation') is-invalid @enderror"
                       id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                       placeholder="••••••••">
                @error('password_confirmation')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <button type="submit" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
            Cập nhật mật khẩu
        </button>
    </form>
</x-guest-layout>
