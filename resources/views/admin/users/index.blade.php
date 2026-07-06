@extends('layouts.admin')

@section('title', 'Quản lý người dùng')
@section('page-title', 'Quản lý người dùng')
@section('page-subtitle', 'Quản trị tài khoản và quyền truy cập')

@section('content')
<div class="container-fluid px-0">
    <section class="admin-card">
        <div class="admin-card__header">
            <div>
                <h2 class="admin-card__title">Danh sách người dùng</h2>
                <p class="admin-card__description">Theo dõi vai trò, trạng thái và ngày tạo tài khoản.</p>
            </div>
            <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Thêm người dùng
            </button>
        </div>

        <div class="admin-table-wrap admin-desktop-table">
            <table id="usersTable" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Tên</th>
                        <th>Email</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                        <th>Ngày tạo</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                    <tr data-user-id="{{ $user->MaKH }}" data-user-name="{{ $user->name }}" data-user-email="{{ $user->email }}" data-user-role="{{ $user->role }}" data-user-status="{{ $user->status }}">
                        <td><span class="fw-semibold">#{{ $user->MaKH }}</span></td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td><span class="badge {{ $user->role === 'Administrator' ? 'text-bg-danger' : 'text-bg-primary' }}">{{ $user->role }}</span></td>
                        <td><span class="badge {{ $user->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $user->status === 'active' ? 'Hoạt động' : 'Vô hiệu hóa' }}</span></td>
                        <td>{{ $user->created_at->format('d/m/Y H:i') }}</td>
                        <td>
                            <div class="btn-group" role="group" aria-label="Thao tác người dùng #{{ $user->MaKH }}">
                                <button class="btn btn-sm btn-outline-warning" type="button" onclick="editUser(this.closest('tr'))" aria-label="Sửa người dùng {{ $user->name }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                </button>
                                <button class="btn btn-sm {{ $user->status === 'active' ? 'btn-outline-secondary' : 'btn-outline-success' }}" type="button" onclick="toggleStatus({{ $user->MaKH }}, '{{ $user->status }}')" aria-label="{{ $user->status === 'active' ? 'Vô hiệu hóa' : 'Kích hoạt' }} người dùng {{ $user->name }}">
                                    <i class="bi bi-{{ $user->status === 'active' ? 'x-circle' : 'check-circle' }}" aria-hidden="true"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="admin-mobile-list">
            @forelse($users as $user)
                <article class="admin-mobile-item">
                    <div class="d-flex justify-content-between gap-3">
                        <div class="min-w-0">
                            <strong>{{ $user->name }}</strong>
                            <div class="small text-secondary">{{ $user->email }}</div>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <span class="badge {{ $user->role === 'Administrator' ? 'text-bg-danger' : 'text-bg-primary' }}">{{ $user->role }}</span>
                                <span class="badge {{ $user->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $user->status === 'active' ? 'Hoạt động' : 'Vô hiệu hóa' }}</span>
                            </div>
                        </div>
                        <span class="small text-secondary">#{{ $user->MaKH }}</span>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-sm btn-outline-warning flex-fill" type="button"
                            data-user-id="{{ $user->MaKH }}"
                            data-user-name="{{ $user->name }}"
                            data-user-email="{{ $user->email }}"
                            data-user-role="{{ $user->role }}"
                            onclick="editUser(this)">
                            Sửa
                        </button>
                        <button class="btn btn-sm {{ $user->status === 'active' ? 'btn-outline-secondary' : 'btn-outline-success' }} flex-fill" type="button" onclick="toggleStatus({{ $user->MaKH }}, '{{ $user->status }}')">
                            {{ $user->status === 'active' ? 'Vô hiệu hóa' : 'Kích hoạt' }}
                        </button>
                    </div>
                </article>
            @empty
                <div class="admin-empty-state">Chưa có người dùng.</div>
            @endforelse
        </div>
    </section>
</div>

<div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.users.store') }}">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title h5" id="createUserTitle">Thêm người dùng mới</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="create_name">Tên <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="create_name" class="form-control" autocomplete="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="create_email">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="create_email" class="form-control" autocomplete="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="create_password">Mật khẩu <span class="text-danger">*</span></label>
                        <input type="password" name="password" id="create_password" class="form-control" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="create_password_confirmation">Xác nhận mật khẩu <span class="text-danger">*</span></label>
                        <input type="password" name="password_confirmation" id="create_password_confirmation" class="form-control" autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="create_role">Vai trò <span class="text-danger">*</span></label>
                        <select name="role" id="create_role" class="form-select" required>
                            <option value="User">User</option>
                            <option value="Administrator">Administrator</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Tạo người dùng</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editUserForm">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h2 class="modal-title h5" id="editUserTitle">Chỉnh sửa người dùng</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="edit_name">Tên <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" autocomplete="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_email">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="edit_email" class="form-control" autocomplete="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_password">Mật khẩu mới</label>
                        <input type="password" name="password" id="edit_password" class="form-control" autocomplete="new-password">
                        <div class="form-text">Để trống nếu không đổi mật khẩu.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_password_confirmation">Xác nhận mật khẩu mới</label>
                        <input type="password" name="password_confirmation" id="edit_password_confirmation" class="form-control" autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_role">Vai trò <span class="text-danger">*</span></label>
                        <select name="role" id="edit_role" class="form-select" required>
                            <option value="User">User</option>
                            <option value="Administrator">Administrator</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Cập nhật</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('DOMContentLoaded', function() {
    $('#usersTable').DataTable({
        language: window.AdminUI.dtLanguage,
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true,
        dom: '<"row align-items-center mb-3 g-2"<"col-md-4"l><"col-md-4"B><"col-md-4"f>>rt<"row align-items-center mt-3 g-2"<"col-md-6"i><"col-md-6"p>>',
        buttons: [
            window.AdminUI.excelButton({
                url: '{{ route('admin.users.export') }}',
                params: (dt) => ({
                    q: dt.search(),
                }),
            })
        ]
    });
});

function editUser(source) {
    const data = source.dataset.userId ? source.dataset : source.dataset;
    const userId = data.userId;
    $('#edit_name').val(data.userName);
    $('#edit_email').val(data.userEmail);
    $('#edit_role').val(data.userRole);
    $('#edit_password').val('');
    $('#edit_password_confirmation').val('');
    $('#editUserForm').attr('action', `{{ url('/admin/users') }}/${userId}`);
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

function toggleStatus(userId, currentStatus) {
    const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
    const actionText = newStatus === 'active' ? 'kích hoạt' : 'vô hiệu hóa';

    Swal.fire({
        title: 'Xác nhận',
        text: `Bạn có chắc muốn ${actionText} người dùng này?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Xác nhận',
        cancelButtonText: 'Hủy'
    }).then((result) => {
        if (!result.isConfirmed) return;

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `{{ url('/admin/users') }}/${userId}/status`;
        form.innerHTML = `
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <input type="hidden" name="_method" value="PATCH">
            <input type="hidden" name="status" value="${newStatus}">
        `;
        document.body.appendChild(form);
        form.submit();
    });
}

window.editUser = editUser;
window.toggleStatus = toggleStatus;
</script>
@endpush
