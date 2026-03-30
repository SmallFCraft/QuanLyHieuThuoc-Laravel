<x-guest-layout>
    <div class="text-center mb-4">
        <h4 class="fw-bold text-gray-800 mb-1">Xác nhận mật khẩu</h4>
        <p class="text-gray-500 small mb-0">Vui lòng xác thực mật khẩu để tiếp tục.</p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <!-- Password -->
        <div class="mb-4">
            <label for="password" class="form-label fw-medium text-gray-700 small">Mật khẩu</label>
            <div class="input-group">
                <span class="input-group-text bg-gray-50 border-end-0 text-gray-400"><i class="bi bi-lock-fill"></i></span>
                <input type="password" class="form-control bg-gray-50 border-start-0 shadow-none @error('password') is-invalid @enderror"
                       id="password" name="password" required autocomplete="current-password"
                       placeholder="••••••••">
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <button type="submit" class="btn btn-lg w-100 text-white fw-bold rounded-pill shadow" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
            Xác nhận
        </button>
    </form>
</x-guest-layout>
