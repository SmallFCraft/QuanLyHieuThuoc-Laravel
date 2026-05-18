@extends('layouts.admin')

@section('title', 'Quản Lý Người Dùng')
@section('page-title', 'Quản Lý Người Dùng')

@section('content')
<div class="container-fluid">
    <div class="glass-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="mb-0">Danh Sách Người Dùng</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                <i class="bi bi-plus-circle"></i> Thêm Người Dùng
            </button>
        </div>

        <div class="table-responsive">
            <table id="usersTable" class="table table-hover">
                <thead>
                    <tr>
                        <th>MaKH</th>
                        <th>Tên</th>
                        <th>Email</th>
                        <th>Vai Trò</th>
                        <th>Trạng Thái</th>
                        <th>Ngày Tạo</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                    <tr>
                        <td>{{ $user->MaKH }}</td>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>
                            <span class="badge bg-{{ $user->role === 'Administrator' ? 'danger' : 'primary' }}">
                                {{ $user->role }}
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-{{ $user->status === 'active' ? 'success' : 'secondary' }}">
                                {{ $user->status === 'active' ? 'Hoạt động' : 'Vô hiệu hóa' }}
                            </span>
                        </td>
                        <td>{{ $user->created_at->format('d/m/Y H:i') }}</td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editUser({{ $user->MaKH }})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-{{ $user->status === 'active' ? 'secondary' : 'success' }}" 
                                    onclick="toggleStatus({{ $user->MaKH }}, '{{ $user->status }}')">
                                <i class="bi bi-{{ $user->status === 'active' ? 'x-circle' : 'check-circle' }}"></i>
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Create User Modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.users.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Thêm Người Dùng Mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tên <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mật Khẩu <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Xác Nhận Mật Khẩu <span class="text-danger">*</span></label>
                        <input type="password" name="password_confirmation" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Vai Trò <span class="text-danger">*</span></label>
                        <select name="role" class="form-select" required>
                            <option value="User">User</option>
                            <option value="Administrator">Administrator</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Tạo Người Dùng</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editUserForm">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Chỉnh Sửa Người Dùng</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edit_MaKH">
                    <div class="mb-3">
                        <label class="form-label">Tên <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mật Khẩu Mới (để trống nếu không đổi)</label>
                        <input type="password" name="password" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Xác Nhận Mật Khẩu Mới</label>
                        <input type="password" name="password_confirmation" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Vai Trò <span class="text-danger">*</span></label>
                        <select name="role" id="edit_role" class="form-select" required>
                            <option value="User">User</option>
                            <option value="Administrator">Administrator</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Cập Nhật</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection


@push('scripts')
<script>
    // Initialize DataTable
    $(document).ready(function() {
        $('#usersTable').DataTable({
            language: {
                processing: "Đang xử lý...",
                search: "Tìm kiếm:",
                lengthMenu: "Hiển thị _MENU_ mục",
                info: "Hiển thị _START_ đến _END_ của _TOTAL_ mục",
                infoEmpty: "Hiển thị 0 đến 0 của 0 mục",
                infoFiltered: "(được lọc từ _MAX_ mục)",
                loadingRecords: "Đang tải...",
                zeroRecords: "Không tìm thấy kết quả",
                emptyTable: "Không có dữ liệu trong bảng",
                paginate: { first: "Đầu", previous: "Trước", next: "Tiếp", last: "Cuối" },
                aria: { sortAscending: ": sắp xếp tăng dần", sortDescending: ": sắp xếp giảm dần" }
            },
            order: [[0, 'desc']],
            pageLength: 25,
            responsive: true,
            dom: 'Bfrtip',
            buttons: [
                {
                    extend: 'csv',
                    text: '<i class="bi bi-file-earmark-csv"></i> CSV',
                    className: 'btn btn-sm btn-success'
                },
                {
                    extend: 'excel',
                    text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                    className: 'btn btn-sm btn-success'
                }
            ]
        });
    });

    // Edit user function
    function editUser(userId) {
        // Fetch user data
        const row = $(`#usersTable tbody tr:has(td:first:contains(${userId}))`);
        const name = row.find('td:eq(1)').text();
        const email = row.find('td:eq(2)').text();
        const role = row.find('td:eq(3) span').text().trim();

        // Populate form
        $('#edit_MaKH').val(userId);
        $('#edit_name').val(name);
        $('#edit_email').val(email);
        $('#edit_role').val(role);
        $('#editUserForm').attr('action', `/admin/users/${userId}`);

        // Show modal
        $('#editUserModal').modal('show');
    }

    // Toggle status function
    function toggleStatus(userId, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        const message = newStatus === 'active' ? 'kích hoạt' : 'vô hiệu hóa';

        Swal.fire({
            title: 'Xác nhận',
            text: `Bạn có chắc muốn ${message} người dùng này?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Xác nhận',
            cancelButtonText: 'Hủy',
            background: 'rgba(255, 255, 255, 0.9)',
            backdrop: 'rgba(0, 0, 0, 0.4)'
        }).then((result) => {
            if (result.isConfirmed) {
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `/admin/users/${userId}/status`;

                const csrfToken = document.createElement('input');
                csrfToken.type = 'hidden';
                csrfToken.name = '_token';
                csrfToken.value = '{{ csrf_token() }}';

                const methodField = document.createElement('input');
                methodField.type = 'hidden';
                methodField.name = '_method';
                methodField.value = 'PATCH';

                const statusField = document.createElement('input');
                statusField.type = 'hidden';
                statusField.name = 'status';
                statusField.value = newStatus;

                form.appendChild(csrfToken);
                form.appendChild(methodField);
                form.appendChild(statusField);
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
</script>
@endpush
