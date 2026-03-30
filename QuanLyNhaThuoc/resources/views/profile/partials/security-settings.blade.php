<!-- Security Settings Section -->
<!-- Change Password -->
<div class="glass-card rounded-4 border-0 p-4 p-md-5 mb-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="bg-emerald-100 text-emerald-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 icon-circle-md">
            <i class="bi bi-shield-lock-fill fs-3"></i>
        </div>
        <div>
            <h5 class="fw-bold text-gray-900 mb-0">Đổi Mật Khẩu</h5>
            <p class="text-gray-500 small mb-0">Sử dụng mật khẩu mạnh để bảo vệ tài khoản</p>
        </div>
    </div>

    <form method="POST" action="{{ route('password.update') }}" id="passwordForm">
        @csrf
        @method('put')

        <div class="mb-3">
            <label for="current_password" class="form-label fw-medium text-gray-700">
                <i class="bi bi-lock text-emerald-600 me-1"></i>Mật khẩu hiện tại
            </label>
            <div class="input-group">
                <input type="password" 
                       class="form-control rounded-start-3 shadow-none @error('current_password', 'updatePassword') is-invalid @enderror"
                       id="current_password" 
                       name="current_password"
                       placeholder="Nhập mật khẩu hiện tại">
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
                <i class="bi bi-key text-emerald-600 me-1"></i>Mật khẩu mới
            </label>
            <div class="input-group">
                <input type="password" 
                       class="form-control rounded-start-3 shadow-none @error('password', 'updatePassword') is-invalid @enderror"
                       id="password" 
                       name="password"
                       placeholder="Nhập mật khẩu mới">
                <button class="btn btn-outline-secondary rounded-end-3" type="button" onclick="togglePassword('password')">
                    <i class="bi bi-eye" id="password_icon"></i>
                </button>
                @error('password', 'updatePassword')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <small class="text-gray-500">Tối thiểu 8 ký tự, bao gồm chữ hoa, chữ thường và số</small>
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label fw-medium text-gray-700">
                <i class="bi bi-check-circle text-emerald-600 me-1"></i>Xác nhận mật khẩu mới
            </label>
            <div class="input-group">
                <input type="password" 
                       class="form-control rounded-start-3 shadow-none"
                       id="password_confirmation" 
                       name="password_confirmation"
                       placeholder="Nhập lại mật khẩu mới">
                <button class="btn btn-outline-secondary rounded-end-3" type="button" onclick="togglePassword('password_confirmation')">
                    <i class="bi bi-eye" id="password_confirmation_icon"></i>
                </button>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <button type="submit" class="btn btn-glass-primary rounded-pill px-4 py-2 fw-medium">
                <i class="bi bi-key me-1"></i>Cập nhật mật khẩu
            </button>
            
            @if (session('status') === 'password-updated')
                <span class="text-success small fw-medium animate-fade-in">
                    <i class="bi bi-check-circle-fill me-1"></i>Đã cập nhật mật khẩu!
                </span>
            @endif
        </div>
    </form>
</div>

<!-- Security Tips -->
<div class="alert-glass-info p-4 rounded-4 mb-4">
    <h6 class="fw-bold text-gray-900 mb-3">
        <i class="bi bi-lightbulb text-warning me-2"></i>Mẹo bảo mật
    </h6>
    <ul class="list-unstyled mb-0">
        <li class="mb-2">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span class="text-gray-700">Sử dụng mật khẩu dài ít nhất 12 ký tự</span>
        </li>
        <li class="mb-2">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span class="text-gray-700">Kết hợp chữ hoa, chữ thường, số và ký tự đặc biệt</span>
        </li>
        <li class="mb-2">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span class="text-gray-700">Không sử dụng thông tin cá nhân dễ đoán</span>
        </li>
        <li class="mb-0">
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <span class="text-gray-700">Thay đổi mật khẩu định kỳ mỗi 3-6 tháng</span>
        </li>
    </ul>
</div>
