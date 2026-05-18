@extends('layouts.admin')

@section('title', 'Thêm Sản Phẩm')
@section('page-title', 'Thêm Sản Phẩm Mới')
@section('page-subtitle', 'Điền đầy đủ thông tin để thêm sản phẩm')

@section('content')
<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Sản Phẩm</a></li>
                    <li class="breadcrumb-item active">Thêm Mới</li>
                </ol>
            </nav>

            <form action="{{ route('admin.products.store') }}" method="POST" enctype="multipart/form-data" id="createProductForm">
                @csrf

                <div class="row g-4">
                    <!-- Left Column: Main Info -->
                    <div class="col-lg-8">
                        <div class="glass-card mb-4">
                            <h6 class="fw-bold mb-4" style="color: #064e3b;"><i class="bi bi-info-circle me-2"></i>Thông Tin Cơ Bản</h6>

                            <!-- Product Name -->
                            <div class="mb-3">
                                <label for="name" class="form-label fw-medium">Tên Sản Phẩm <span class="text-danger">*</span></label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                    id="name" name="name" value="{{ old('name') }}"
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
                                        <option value="{{ $category->MaDM }}" {{ old('MaDM') == $category->MaDM ? 'selected' : '' }}>
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
                                            id="price" name="price" value="{{ old('price') }}"
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
                                            id="stock_quantity" name="stock_quantity" value="{{ old('stock_quantity', 0) }}"
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
                                        id="batch_number" name="batch_number" value="{{ old('batch_number') }}"
                                        placeholder="VD: LOT-2024-001" required>
                                    @error('batch_number')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="reorder_point" class="form-label fw-medium">Ngưỡng Cảnh Báo Tồn Kho</label>
                                    <input type="number" class="form-control @error('reorder_point') is-invalid @enderror"
                                        id="reorder_point" name="reorder_point" value="{{ old('reorder_point', 10) }}"
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
                                    id="expiration_date" name="expiration_date" value="{{ old('expiration_date') }}" required>
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
                                    placeholder="Liệt kê các thành phần chính của sản phẩm...">{{ old('ingredients') }}</textarea>
                                @error('ingredients')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-0">
                                <label for="usage" class="form-label fw-medium">Hướng Dẫn Sử Dụng</label>
                                <textarea class="form-control summernote @error('usage') is-invalid @enderror"
                                    id="usage" name="usage" rows="4"
                                    placeholder="Hướng dẫn liều dùng, cách sử dụng...">{{ old('usage') }}</textarea>
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
                                <div id="imagePreviewContainer" class="mb-3" style="display: none;">
                                    <img id="imagePreview" src="#" alt="Preview"
                                        class="img-fluid rounded-3" style="max-height: 200px; object-fit: cover; width: 100%;">
                                </div>
                                <div id="imagePlaceholder" class="border rounded-3 d-flex align-items-center justify-content-center"
                                    style="height: 160px; background: #f8fafc; border-style: dashed !important; cursor: pointer; transition: 0.3s;"
                                    onclick="document.getElementById('image').click()"
                                    ondragover="event.preventDefault(); this.style.backgroundColor='#d1fae5';"
                                    ondragleave="event.preventDefault(); this.style.backgroundColor='#f8fafc';"
                                    ondrop="event.preventDefault(); this.style.backgroundColor='#f8fafc'; document.getElementById('image').files = event.dataTransfer.files; previewImage(document.getElementById('image'));">
                                    <div class="text-center text-muted">
                                        <i class="bi bi-cloud-upload fs-2 d-block mb-2" style="color: #10b981;"></i>
                                        <small>Nhấn hoặc Kéo thả ảnh vào đây</small>
                                    </div>
                                </div>
                            </div>

                            <input type="file" class="form-control @error('image') is-invalid @enderror"
                                id="image" name="image" accept="image/*"
                                onchange="previewImage(this)">
                            <small class="text-muted">Định dạng: JPG, PNG, GIF. Tối đa 2MB</small>
                            @error('image')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Actions Card -->
                        <div class="glass-card">
                            <h6 class="fw-bold mb-4" style="color: #064e3b;"><i class="bi bi-gear me-2"></i>Thao Tác</h6>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-plus-circle me-2"></i>Thêm Sản Phẩm
                                </button>
                                <a href="{{ route('admin.products.index') }}" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-2"></i>Quay Lại
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('styles')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/summernote@0.9.0/dist/summernote-lite.min.css" rel="stylesheet">
<style>
    .form-control:focus, .form-select:focus {
        border-color: #10b981;
        box-shadow: 0 0 0 0.2rem rgba(16, 185, 129, 0.15);
    }
    .btn-primary {
        background: linear-gradient(135deg, #059669, #10b981);
        border: none;
    }
    .btn-primary:hover {
        background: linear-gradient(135deg, #047857, #059669);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
    }
    .input-group-text {
        background: #ecfdf5;
        border-color: #d1fae5;
        color: #059669;
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.9.0/dist/summernote-lite.min.js"></script>
<script>
    $(document).ready(function() {
        // Initialize Select2
        $('.select2-basic').select2({
            width: '100%',
            placeholder: "-- Chọn danh mục --",
            allowClear: true
        });

        // Initialize Summernote
        $('.summernote').summernote({
            height: 150,
            toolbar: [
                ['style', ['bold', 'italic', 'underline', 'clear']],
                ['font', ['strikethrough', 'superscript', 'subscript']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['view', ['fullscreen', 'codeview']]
            ],
            lang: 'vi-VN' // Set language to Vietnamese if available, or fallbacks
        });
    });
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
</script>
@endpush
