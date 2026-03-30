<!-- Account Settings Section -->
<!-- Notification Preferences -->
<div class="glass-card rounded-4 border-0 p-4 p-md-5 mb-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="bg-emerald-100 text-emerald-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 icon-circle-md">
            <i class="bi bi-bell-fill fs-3"></i>
        </div>
        <div>
            <h5 class="fw-bold text-gray-900 mb-0">Thông Báo</h5>
            <p class="text-gray-500 small mb-0">Quản lý cách bạn nhận thông báo</p>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center py-3 border-bottom">
        <div>
            <p class="fw-medium text-gray-900 mb-1">Thông báo đơn hàng</p>
            <small class="text-gray-500">Nhận thông báo về trạng thái đơn hàng</small>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="orderNotif" checked>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center py-3 border-bottom">
        <div>
            <p class="fw-medium text-gray-900 mb-1">Khuyến mãi & Ưu đãi</p>
            <small class="text-gray-500">Nhận thông tin về chương trình khuyến mãi</small>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="promoNotif" checked>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center py-3">
        <div>
            <p class="fw-medium text-gray-900 mb-1">Tin tức sức khỏe</p>
            <small class="text-gray-500">Nhận bài viết về sức khỏe và chăm sóc</small>
        </div>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="healthNotif">
        </div>
    </div>
</div>

<!-- Account Information -->
<div class="glass-card rounded-4 border-0 p-4 p-md-5 mb-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="bg-gray-100 text-gray-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 icon-circle-md">
            <i class="bi bi-info-circle-fill fs-3"></i>
        </div>
        <div>
            <h5 class="fw-bold text-gray-900 mb-0">Thông Tin Tài Khoản</h5>
            <p class="text-gray-500 small mb-0">Chi tiết về tài khoản của bạn</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="p-3 rounded-3 bg-emerald-50">
                <small class="text-gray-500 d-block mb-1">Vai trò</small>
                <p class="fw-medium text-gray-900 mb-0">
                    <span class="badge bg-emerald-100 text-emerald-800 rounded-pill px-3 py-1">
                        {{ ucfirst($user->role) }}
                    </span>
                </p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 rounded-3 bg-emerald-50">
                <small class="text-gray-500 d-block mb-1">Trạng thái</small>
                <p class="fw-medium text-gray-900 mb-0">
                    <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-1">
                        <i class="bi bi-check-circle me-1"></i>{{ ucfirst($user->status) }}
                    </span>
                </p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 rounded-3 bg-emerald-50">
                <small class="text-gray-500 d-block mb-1">Ngày tham gia</small>
                <p class="fw-medium text-gray-900 mb-0">{{ $user->created_at->format('d/m/Y') }}</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 rounded-3 bg-emerald-50">
                <small class="text-gray-500 d-block mb-1">Cập nhật lần cuối</small>
                <p class="fw-medium text-gray-900 mb-0">{{ $user->updated_at->format('d/m/Y') }}</p>
            </div>
        </div>
    </div>
</div>

<!-- Delete Account -->
<div class="glass-card rounded-4 border-0 p-4 p-md-5 border-start border-danger border-3">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 icon-circle-md">
            <i class="bi bi-exclamation-triangle-fill fs-3"></i>
        </div>
        <div>
            <h5 class="fw-bold text-gray-900 mb-0">Vùng Nguy Hiểm</h5>
            <p class="text-gray-500 small mb-0">Hành động không thể hoàn tác</p>
        </div>
    </div>

    <div class="alert-glass-danger mb-4 p-3 rounded-3">
        <i class="bi bi-shield-exclamation me-2"></i>
        <strong>Cảnh báo:</strong> Xóa tài khoản sẽ xóa vĩnh viễn tất cả dữ liệu của bạn bao gồm đơn hàng, lịch sử mua hàng và không thể khôi phục.
    </div>

    <form method="POST" action="{{ route('profile.destroy') }}" id="deleteAccountForm">
        @csrf
        @method('delete')

        <div class="mb-4">
            <label for="delete_password" class="form-label fw-medium text-gray-700">
                Nhập mật khẩu để xác nhận xóa tài khoản
            </label>
            <input type="password" 
                   class="form-control rounded-3 shadow-none @error('password', 'userDeletion') is-invalid @enderror"
                   id="delete_password" 
                   name="password" 
                   placeholder="Mật khẩu hiện tại">
            @error('password', 'userDeletion')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <button type="button" class="btn btn-danger rounded-pill px-4 py-2 fw-medium" onclick="confirmDelete()">
            <i class="bi bi-trash3 me-1"></i>Xóa tài khoản vĩnh viễn
        </button>
    </form>
</div>

@push('scripts')
<script>
function confirmDelete() {
    Swal.fire({
        title: 'Xác nhận xóa tài khoản?',
        html: '<p class="mb-2">Tất cả dữ liệu sẽ bị xóa vĩnh viễn bao gồm:</p>' +
              '<ul class="text-start">' +
              '<li>Thông tin cá nhân</li>' +
              '<li>Lịch sử đơn hàng</li>' +
              '<li>Địa chỉ giao hàng</li>' +
              '<li>Tất cả dữ liệu liên quan</li>' +
              '</ul>' +
              '<p class="text-danger fw-bold">Hành động này không thể hoàn tác!</p>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<i class="bi bi-trash3 me-1"></i>Xóa tài khoản',
        cancelButtonText: 'Hủy bỏ',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('deleteAccountForm').submit();
        }
    });
}

// Save notification preferences
document.querySelectorAll('.form-check-input').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        // Here you can add AJAX call to save preferences
        console.log(this.id + ' changed to: ' + this.checked);
    });
});
</script>
@endpush
