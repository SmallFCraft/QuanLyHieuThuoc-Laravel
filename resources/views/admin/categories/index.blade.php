@extends('layouts.admin')

@section('title', 'Quản Lý Danh Mục')
@section('page-title', 'Quản Lý Danh Mục')

@section('content')
<div class="container-fluid">
    <div class="glass-card">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="mb-0">Danh Sách Danh Mục</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                <i class="bi bi-plus-circle"></i> Thêm Danh Mục
            </button>
        </div>

        <div class="table-responsive">
            <table id="categoriesTable" class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tên Danh Mục</th>
                        <th>Mô Tả</th>
                        <th>Số Sản Phẩm</th>
                        <th>Ngày Tạo</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($categories as $category)
                    <tr>
                        <td>{{ $category->id }}</td>
                        <td>{{ $category->name }}</td>
                        <td>{{ $category->description ?? '-' }}</td>
                        <td><span class="badge bg-info">{{ $category->products_count }}</span></td>
                        <td>{{ $category->created_at->format('d/m/Y') }}</td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editCategory({{ $category->id }})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-danger" onclick="deleteCategory({{ $category->id }})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Create Category Modal -->
<div class="modal fade" id="createCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="createCategoryForm">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Thêm Danh Mục Mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tên Danh Mục <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mô Tả</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Tạo Danh Mục</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="editCategoryForm">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Chỉnh Sửa Danh Mục</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edit_category_id">
                    <div class="mb-3">
                        <label class="form-label">Tên Danh Mục <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mô Tả</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
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
    $(document).ready(function() {
        $('#categoriesTable').DataTable({
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
            order: [[1, 'asc']],
            pageLength: 25,
            responsive: true
        });

        // Create category
        $('#createCategoryForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: '{{ route("admin.categories.store") }}',
                type: 'POST',
                data: $(this).serialize(),
                success: function(response) {
                    $('#createCategoryModal').modal('hide');
                    Swal.fire({
                        title: 'Thành công!',
                        text: response.message,
                        icon: 'success',
                        background: 'rgba(255, 255, 255, 0.9)'
                    }).then(() => location.reload());
                },
                error: function(xhr) {
                    let errors = xhr.responseJSON.errors;
                    let errorMsg = Object.values(errors).flat().join('<br>');
                    Swal.fire({
                        title: 'Lỗi!',
                        html: errorMsg,
                        icon: 'error',
                        background: 'rgba(255, 255, 255, 0.9)'
                    });
                }
            });
        });

        // Edit category
        $('#editCategoryForm').on('submit', function(e) {
            e.preventDefault();
            const categoryId = $('#edit_category_id').val();
            $.ajax({
                url: `/admin/categories/${categoryId}`,
                type: 'PUT',
                data: $(this).serialize(),
                success: function(response) {
                    $('#editCategoryModal').modal('hide');
                    Swal.fire({
                        title: 'Thành công!',
                        text: response.message,
                        icon: 'success',
                        background: 'rgba(255, 255, 255, 0.9)'
                    }).then(() => location.reload());
                },
                error: function(xhr) {
                    let errors = xhr.responseJSON.errors;
                    let errorMsg = Object.values(errors).flat().join('<br>');
                    Swal.fire({
                        title: 'Lỗi!',
                        html: errorMsg,
                        icon: 'error',
                        background: 'rgba(255, 255, 255, 0.9)'
                    });
                }
            });
        });
    });

    function editCategory(categoryId) {
        const row = $(`#categoriesTable tbody tr:has(td:first:contains(${categoryId}))`);
        const name = row.find('td:eq(1)').text();
        const description = row.find('td:eq(2)').text();

        $('#edit_category_id').val(categoryId);
        $('#edit_name').val(name);
        $('#edit_description').val(description === '-' ? '' : description);
        $('#editCategoryModal').modal('show');
    }

    function deleteCategory(categoryId) {
        Swal.fire({
            title: 'Xác nhận xóa',
            text: 'Bạn có chắc muốn xóa danh mục này?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Xóa',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#dc3545',
            background: 'rgba(255, 255, 255, 0.9)',
            backdrop: 'rgba(0, 0, 0, 0.4)'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: `/admin/categories/${categoryId}`,
                    type: 'DELETE',
                    success: function(response) {
                        Swal.fire({
                            title: 'Thành công!',
                            text: response.message,
                            icon: 'success',
                            background: 'rgba(255, 255, 255, 0.9)'
                        }).then(() => location.reload());
                    },
                    error: function(xhr) {
                        Swal.fire({
                            title: 'Lỗi!',
                            text: xhr.responseJSON.message,
                            icon: 'error',
                            background: 'rgba(255, 255, 255, 0.9)'
                        });
                    }
                });
            }
        });
    }
</script>
@endpush
