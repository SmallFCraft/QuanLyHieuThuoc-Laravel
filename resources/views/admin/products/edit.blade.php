@extends('layouts.admin')

@section('title', 'Chỉnh Sửa Sản Phẩm')
@section('page-title', 'Chỉnh Sửa Sản Phẩm')
@section('page-subtitle', $product->name)

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Sản Phẩm</a></li>
                    <li class="breadcrumb-item active">Chỉnh Sửa</li>
                </ol>
            </nav>

            <form action="{{ route('admin.products.update', $product->MaSP) }}" method="POST" enctype="multipart/form-data" id="editProductForm">
                @csrf
                @method('PUT')

                <div class="row g-4">
                    <!-- Left Column: Main Info -->
                    <div class="col-lg-8">
                        <div class="glass-card mb-4">
                            <h6 class="fw-bold mb-4" style="color: #064e3b;"><i class="bi bi-info-circle me-2"></i>Thông Tin Cơ Bản</h6>

                            <!-- Product Name -->
                            <div class="mb-3">
                                <label for="name" class="form-label fw-medium">Tên Sản Phẩm <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                    id="name" name="name" value="{{ old('name', $product->name) }}"
                                    placeholder="Nhập tên thuốc / sản phẩm..." required>
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Category -->
                            <div class="mb-3">
                                <label for="MaDM" class="form-label fw-medium">Danh Mục <span class="text-danger">*</span></label>
                                <select class="form-select select2-basic @error('MaDM') is-invalid @enderror"
                                    id="MaDM" name="MaDM" required>
                                    <option value="">-- Chọn danh mục --</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->MaDM }}"
                                            {{ old('MaDM', $product->MaDM) == $category->MaDM ? 'selected' : '' }}>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('MaDM')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Price & Stock Row -->
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="price" class="form-label fw-medium">Giá Bán (VNĐ) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-currency-exchange"></i></span>
                                        <input type="number" class="form-control @error('price') is-invalid @enderror"
                                            id="price" name="price" value="{{ old('price', $product->price_vnd) }}"
                                            placeholder="0" min="0" step="1" required>
                                        @error('price')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label for="stock_quantity" class="form-label fw-medium">Số Lượng Tồn Kho <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-boxes"></i></span>
                                        <input type="number" class="form-control @error('stock_quantity') is-invalid @enderror"
                                            id="stock_quantity" name="stock_quantity"
                                            value="{{ old('stock_quantity', $product->stock_quantity) }}"
                                            min="0" required>
                                        @error('stock_quantity')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <!-- Batch Number & Reorder Point -->
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="batch_number" class="form-label fw-medium">Số Lô <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('batch_number') is-invalid @enderror"
                                        id="batch_number" name="batch_number"
                                        value="{{ old('batch_number', $product->batch_number) }}"
                                        placeholder="VD: LOT-2024-001" required>
                                    @error('batch_number')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="reorder_point" class="form-label fw-medium">Ngưỡng Cảnh Báo Tồn Kho</label>
                                    <input type="number" class="form-control @error('reorder_point') is-invalid @enderror"
                                        id="reorder_point" name="reorder_point"
                                        value="{{ old('reorder_point', $product->reorder_point) }}"
                                        min="0">
                                    @error('reorder_point')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <!-- Expiration Date -->
                            <div class="mb-3">
                                <label for="expiration_date" class="form-label fw-medium">Hạn Sử Dụng <span class="text-danger">*</span></label>
                                <input type="date" class="form-control @error('expiration_date') is-invalid @enderror"
                                    id="expiration_date" name="expiration_date"
                                    value="{{ old('expiration_date', $product->expiration_date->format('Y-m-d')) }}" required>
                                @error('expiration_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <!-- Description Section -->
                        <div class="glass-card">
                            <h6 class="fw-bold mb-4" style="color: #064e3b;"><i class="bi bi-file-text me-2"></i>Mô Tả Chi Tiết</h6>

                            <div class="mb-3">
                                <label for="ingredients" class="form-label fw-medium">Thành Phần</label>
                                <textarea class="form-control summernote @error('ingredients') is-invalid @enderror"
                                    id="ingredients" name="ingredients" rows="4"
                                    placeholder="Liệt kê các thành phần chính của sản phẩm...">{{ old('ingredients', $product->ingredients) }}</textarea>
                                @error('ingredients')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-0">
                                <label for="usage" class="form-label fw-medium">Hướng Dẫn Sử Dụng</label>
                                <textarea class="form-control summernote @error('usage') is-invalid @enderror"
                                    id="usage" name="usage" rows="4"
                                    placeholder="Hướng dẫn liều dùng, cách sử dụng...">{{ old('usage', $product->usage) }}</textarea>
                                @error('usage')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Image & Actions -->
                    <div class="col-lg-4">
                        <!-- Image Upload -->
                        <div class="glass-card mb-4">
                            <h6 class="fw-bold mb-4" style="color: #064e3b;"><i class="bi bi-image me-2"></i>Hình Ảnh Sản Phẩm</h6>

                            <div class="text-center mb-3">
                                <div id="imagePreviewContainer" class="mb-3" style="{{ $product->image_url ? '' : 'display: none;' }}">
                                    <img id="imagePreview"
                                        src="{{ $product->image_url ?? '#' }}"
                                        alt="Preview"
                                        class="img-fluid rounded-3"
                                        style="max-height: 200px; object-fit: cover; width: 100%;"
                                        onerror="this.parentElement.style.display='none'; const placeholder = document.getElementById('imagePlaceholder'); placeholder.style.display='flex'; document.getElementById('imagePlaceholderIcon').className='bi bi-capsule fs-2 d-block mb-2 text-gray-300'; document.getElementById('imagePlaceholderText').textContent='Ảnh hiện tại không khả dụng. Nhấn để chọn ảnh mới';">
                                </div>
                                <div id="imagePlaceholder" class="border rounded-3 d-flex align-items-center justify-content-center"
                                    style="height: 160px; background: #f8fafc; border-style: dashed !important; cursor: pointer; transition: 0.3s; {{ $product->image_url ? 'display: none;' : '' }}"
                                    onclick="document.getElementById('image').click()"
                                    ondragover="event.preventDefault(); this.style.backgroundColor='#d1fae5';"
                                    ondragleave="event.preventDefault(); this.style.backgroundColor='#f8fafc';"
                                    ondrop="event.preventDefault(); this.style.backgroundColor='#f8fafc'; document.getElementById('image').files = event.dataTransfer.files; previewImage(document.getElementById('image'));">
                                    <div class="text-center text-muted">
                                        <i id="imagePlaceholderIcon" class="bi bi-cloud-upload fs-2 d-block mb-2" style="color: #10b981;" aria-hidden="true"></i>
                                        <small id="imagePlaceholderText">Nhấn hoặc Kéo thả ảnh vào đây</small>
                                    </div>
                                </div>
                            </div>

                            @if($product->image_url)
                                <div class="mb-2">
                                    <small class="text-muted"><i class="bi bi-check-circle text-success me-1"></i>Đã có ảnh. Chọn ảnh mới để thay thế.</small>
                                </div>
                            @endif

                            <input type="file" class="form-control @error('image') is-invalid @enderror"
                                id="image" name="image" accept="image/*"
                                onchange="previewImage(this)">
                            <small class="text-muted">Định dạng: JPG, PNG, GIF. Tối đa 2MB</small>
                            @error('image')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Product Info Summary -->
                        <div class="glass-card mb-4">
                            <h6 class="fw-bold mb-3" style="color: #064e3b;"><i class="bi bi-clock-history me-2"></i>Thông Tin Khác</h6>
                            <div class="small text-muted">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Mã sản phẩm:</span>
                                    <span class="fw-medium text-dark">{{ $product->display_code }}</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Ngày tạo:</span>
                                    <span class="fw-medium text-dark">{{ $product->created_at->format('d/m/Y') }}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Cập nhật lần cuối:</span>
                                    <span class="fw-medium text-dark">{{ $product->updated_at->format('d/m/Y') }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Actions Card -->
                        <div class="glass-card">
                            <h6 class="fw-bold mb-4" style="color: #064e3b;"><i class="bi bi-gear me-2"></i>Thao Tác</h6>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-save me-2"></i>Lưu Thay Đổi
                                </button>
                                <a href="{{ route('admin.products.index') }}" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-2"></i>Quay Lại
                                </a>
                                <hr class="my-1">
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDelete({{ $product->MaSP }})">
                                    <i class="bi bi-trash me-2"></i>Xóa Sản Phẩm Này
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Hidden Delete Form -->
            <form id="deleteForm" action="{{ route('admin.products.destroy', $product->MaSP) }}" method="POST" style="display: none;">
                @csrf
                @method('DELETE')
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
    function previewImage(input) {
        const preview = document.getElementById('imagePreview');
        const previewContainer = document.getElementById('imagePreviewContainer');
        const placeholder = document.getElementById('imagePlaceholder');

        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                previewContainer.style.display = 'block';
                placeholder.style.display = 'none';
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function confirmDelete(productId) {
        Swal.fire({
            title: 'Xác nhận xóa',
            text: 'Bạn có chắc muốn xóa sản phẩm này? Hành động này không thể hoàn tác.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Xóa',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#dc3545',
            background: 'rgba(255, 255, 255, 0.95)',
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: `/admin/products/${productId}`,
                    type: 'DELETE',
                    success: function(response) {
                        Swal.fire({
                            title: 'Đã xóa!',
                            text: response.message,
                            icon: 'success',
                        }).then(() => {
                            window.location.href = '{{ route('admin.products.index') }}';
                        });
                    },
                    error: function() {
                        Swal.fire('Lỗi!', 'Không thể xóa sản phẩm.', 'error');
                    }
                });
            }
        });
    }

    window.previewImage = previewImage;
    window.confirmDelete = confirmDelete;
</script>
@endpush
