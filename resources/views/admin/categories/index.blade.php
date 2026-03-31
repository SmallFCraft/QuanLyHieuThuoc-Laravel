@extends('layouts.admin')

@section('title', 'Quản lý danh mục')

@section('content')
<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800">Quản lý danh mục</h1>
            <p class="text-muted small mb-0">Quản lý các danh mục sản phẩm</p>
        </div>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="openCreateModal()">
            <i class="bi bi-plus-circle me-2"></i>Thêm danh mục
        </button>
    </div>

    <!-- Categories Grid -->
    <div class="row g-4">
        @forelse($categories as $category)
            <div class="col-md-6 col-lg-4 col-xl-3">
                <div class="card border-0 shadow-sm h-100 hover-lift">
                    <div class="card-body text-center p-4">
                        <!-- Icon -->
                        <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; background: linear-gradient(135deg, rgba(16,185,129,0.15) 0%, rgba(16,185,129,0.08) 100%);">
                            <i class="bi bi-{{ $category->icon ?? 'grid-fill' }} text-success" style="font-size: 2.5rem;"></i>
                        </div>
                        
                        <!-- Name -->
                        <h5 class="fw-bold text-dark mb-2">{{ $category->name }}</h5>
                        
                        <!-- Description -->
                        @if($category->description)
                            <p class="text-muted small mb-3">{{ Str::limit($category->description, 80) }}</p>
                        @endif
                        
                        <!-- Stats -->
                        <div class="d-flex justify-content-center gap-3 mb-3">
                            <div class="text-center">
                                <div class="fw-bold text-success fs-5">{{ $category->products_count }}</div>
                                <div class="text-muted small">Sản phẩm</div>
                            </div>
                        </div>
                        
                        <!-- Actions -->
                        <div class="d-flex gap-2 justify-content-center">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openEditModal({{ $category->id }}, '{{ $category->name }}', '{{ $category->icon }}', '{{ addslashes($category->description) }}')" title="Sửa">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCategory({{ $category->id }})" title="Xóa">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="text-center py-5">
                    <i class="bi bi-folder2-open text-muted" style="font-size: 4rem;"></i>
                    <h5 class="text-muted mt-3">Chưa có danh mục nào</h5>
                    <p class="text-muted">Nhấn nút "Thêm danh mục" để tạo danh mục mới</p>
                </div>
            </div>
        @endforelse
    </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Thêm danh mục</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="categoryForm">
                <div class="modal-body">
                    <input type="hidden" id="categoryId">
                    
                    <div class="mb-3">
                        <label for="categoryName" class="form-label">Tên danh mục <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="categoryName" required>
                        <div class="invalid-feedback"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="categoryIcon" class="form-label">Icon Bootstrap</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="bi bi-grid-fill" id="iconPreview"></i>
                            </span>
                            <input type="text" class="form-control" id="categoryIcon" placeholder="Ví dụ: heart-pulse-fill">
                        </div>
                        <small class="text-muted">Xem danh sách icon tại <a href="https://icons.getbootstrap.com/" target="_blank">Bootstrap Icons</a></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="categoryDescription" class="form-label">Mô tả</label>
                        <textarea class="form-control" id="categoryDescription" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-success">Lưu</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Icon preview
document.getElementById('categoryIcon').addEventListener('input', function(e) {
    const iconName = e.target.value || 'grid-fill';
    document.getElementById('iconPreview').className = `bi bi-${iconName}`;
});

// Open create modal
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Thêm danh mục';
    document.getElementById('categoryForm').reset();
    document.getElementById('categoryId').value = '';
    document.getElementById('iconPreview').className = 'bi bi-grid-fill';
    document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
}

// Open edit modal
function openEditModal(id, name, icon, description) {
    document.getElementById('modalTitle').textContent = 'Sửa danh mục';
    document.getElementById('categoryId').value = id;
    document.getElementById('categoryName').value = name;
    document.getElementById('categoryIcon').value = icon || '';
    document.getElementById('categoryDescription').value = description || '';
    document.getElementById('iconPreview').className = `bi bi-${icon || 'grid-fill'}`;
    document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

// Submit form
document.getElementById('categoryForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const id = document.getElementById('categoryId').value;
    const url = id ? `/admin/categories/${id}` : '/admin/categories';
    const method = id ? 'PUT' : 'POST';
    
    const data = {
        name: document.getElementById('categoryName').value,
        icon: document.getElementById('categoryIcon').value,
        description: document.getElementById('categoryDescription').value,
        _token: '{{ csrf_token() }}'
    };
    
    if (method === 'PUT') {
        data._method = 'PUT';
    }
    
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            Swal.fire({
                icon: 'success',
                title: 'Thành công',
                text: result.message,
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        } else {
            // Show validation errors
            if (result.errors) {
                Object.keys(result.errors).forEach(key => {
                    const input = document.getElementById('category' + key.charAt(0).toUpperCase() + key.slice(1));
                    if (input) {
                        input.classList.add('is-invalid');
                        input.nextElementSibling.textContent = result.errors[key][0];
                    }
                });
            }
        }
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Lỗi',
            text: 'Có lỗi xảy ra. Vui lòng thử lại.'
        });
    }
});

// Delete category
async function deleteCategory(id) {
    const result = await Swal.fire({
        title: 'Xác nhận xóa',
        text: 'Bạn có chắc chắn muốn xóa danh mục này?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Xóa',
        cancelButtonText: 'Hủy'
    });
    
    if (result.isConfirmed) {
        try {
            const response = await fetch(`/admin/categories/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Đã xóa',
                    text: data.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Lỗi',
                    text: data.message
                });
            }
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Lỗi',
                text: 'Có lỗi xảy ra. Vui lòng thử lại.'
            });
        }
    }
}
</script>
@endpush
