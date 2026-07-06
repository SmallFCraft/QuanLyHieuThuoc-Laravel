@extends('layouts.admin')

@section('title', 'Quản lý sản phẩm')
@section('page-title', 'Quản lý sản phẩm')
@section('page-subtitle', 'Theo dõi danh mục, giá bán, tồn kho và hạn dùng')

@section('content')
<div class="container-fluid px-0">
    <section class="admin-card">
        <div class="admin-card__header">
            <div>
                <h2 class="admin-card__title">
                    <i class="bi bi-box-seam me-2 text-success" aria-hidden="true"></i>Danh sách sản phẩm
                </h2>
                <p class="admin-card__description">Dữ liệu tải theo trang để giữ bảng nhanh khi danh mục lớn.</p>
            </div>
            <a href="{{ route('admin.products.create') }}" class="btn btn-success">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Thêm sản phẩm
            </a>
        </div>

        <div class="admin-toolbar">
            <div class="flex-grow-1" style="max-width: 360px;">
                <label for="categoryFilter" class="form-label">
                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Lọc theo danh mục
                </label>
                <select id="categoryFilter" class="form-select">
                    <option value="">Tất cả danh mục</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->MaDM }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" id="resetFilterBtn" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Đặt lại bộ lọc
            </button>
        </div>

        <div class="admin-table-wrap admin-desktop-table">
            <table id="productsTable" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>Mã</th>
                        <th>Ảnh</th>
                        <th>Tên sản phẩm</th>
                        <th>Danh mục</th>
                        <th>Giá bán</th>
                        <th>Tồn kho</th>
                        <th>Hạn dùng</th>
                        <th>Số lô</th>
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="admin-mobile-list" id="productsMobileList" aria-live="polite"></div>
    </section>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('DOMContentLoaded', function () {
    window.adminProductImageFallback = function() {
        return `<div class="admin-product-thumb admin-product-thumb--empty" role="img" aria-label="Ảnh sản phẩm mặc định">
            <i class="bi bi-capsule" aria-hidden="true"></i>
        </div>`;
    };

    const table = $('#productsTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        ajax: {
            url: '{{ route('admin.products.data') }}',
            data: function (d) {
                d.MaDM = $('#categoryFilter').val();
            }
        },
        columns: [
            { data: 'display_code', name: 'product_code' },
            { data: 'image_url', name: 'image', orderable: false, searchable: false },
            { data: 'name', name: 'name' },
            { data: 'category', name: 'category.name' },
            { data: 'formatted_price', name: 'price' },
            { data: 'stock_quantity', name: 'stock_quantity' },
            { data: 'formatted_expiration_date', name: 'expiration_date' },
            { data: 'batch_number', name: 'batch_number' },
            { data: 'MaSP', name: 'actions', orderable: false, searchable: false, className: 'text-center' }
        ],
        columnDefs: [
            {
                targets: 0,
                render: data => `<span class="fw-semibold">${data}</span>`
            },
            {
                targets: 1,
                render: function(data, type, row) {
                    if (data) {
                        return `<img src="${data}" alt="Ảnh ${row.name}" class="admin-product-thumb" onerror="this.outerHTML=window.adminProductImageFallback();">`;
                    }
                    return window.adminProductImageFallback();
                }
            },
            {
                targets: 2,
                render: data => `<strong>${data}</strong>`
            },
            {
                targets: 3,
                render: data => `<span class="badge text-bg-success bg-opacity-75">${data || 'Chưa phân loại'}</span>`
            },
            {
                targets: 5,
                render: function(data, type, row) {
                    const badgeClass = row.is_low_stock ? 'text-bg-danger' : 'text-bg-success';
                    const icon = row.is_low_stock ? '<i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>' : '';
                    return `<span class="badge ${badgeClass}">${icon}${data}</span>`;
                }
            },
            {
                targets: 6,
                render: function(data, type, row) {
                    if (row.is_expired) return `<span class="text-danger fw-bold">${data}</span>`;
                    if (row.is_expiring_soon) return `<span class="text-warning-emphasis fw-bold">${data}</span>`;
                    return data;
                }
            },
            {
                targets: 8,
                render: function(data) {
                    const showUrl = `{{ url('/admin/products') }}/${data}`;
                    const editUrl = `{{ url('/admin/products') }}/${data}/edit`;
                    return `<div class="btn-group" role="group" aria-label="Thao tác sản phẩm #${data}">
                        <a href="${showUrl}" class="btn btn-sm btn-outline-info" aria-label="Xem chi tiết sản phẩm #${data}"><i class="bi bi-eye" aria-hidden="true"></i></a>
                        <a href="${editUrl}" class="btn btn-sm btn-outline-warning" aria-label="Chỉnh sửa sản phẩm #${data}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                        <button type="button" onclick="deleteProduct(${data})" class="btn btn-sm btn-outline-danger" aria-label="Xóa sản phẩm #${data}"><i class="bi bi-trash" aria-hidden="true"></i></button>
                    </div>`;
                }
            }
        ],
        language: window.AdminUI.dtLanguage,
        order: [[0, 'desc']],
        pageLength: 25,
        dom: '<"row align-items-center mb-3 g-2"<"col-md-4"l><"col-md-4"B><"col-md-4"f>>rt<"row align-items-center mt-3 g-2"<"col-md-6"i><"col-md-6"p>>',
        buttons: [
            window.AdminUI.excelButton({
                url: '{{ route('admin.products.export') }}',
                params: (dt) => ({
                    MaDM: $('#categoryFilter').val(),
                    q: dt.search(),
                }),
            })
        ],
        drawCallback: function(settings) {
            const rows = settings.json?.data || [];
            renderMobileProducts(rows);
        }
    });

    $('#categoryFilter').on('change', () => table.draw());
    $('#resetFilterBtn').on('click', function() {
        $('#categoryFilter').val('');
        table.search('');
        table.draw();
    });
});

function renderMobileProducts(rows) {
    const root = document.getElementById('productsMobileList');
    if (!root) return;

    if (!rows.length) {
        root.innerHTML = '<div class="admin-empty-state">Không có sản phẩm phù hợp.</div>';
        return;
    }

    root.innerHTML = rows.map(row => {
        const stockClass = row.is_low_stock ? 'text-bg-danger' : 'text-bg-success';
        const expClass = row.is_expired ? 'text-danger' : (row.is_expiring_soon ? 'text-warning-emphasis' : 'text-secondary');
        return `<article class="admin-mobile-item">
            <div class="d-flex gap-3">
                ${row.image_url ? `<img src="${row.image_url}" alt="Ảnh ${row.name}" class="rounded border flex-shrink-0" width="58" height="58" style="object-fit:cover;">` : '<div class="rounded border bg-light flex-shrink-0 d-flex align-items-center justify-content-center" style="width:58px;height:58px;"><i class="bi bi-capsule text-secondary" aria-hidden="true"></i></div>'}
                <div class="min-w-0 flex-grow-1">
                    <div class="d-flex justify-content-between gap-2">
                        <strong>${row.name}</strong>
                        <span class="small text-secondary">${row.display_code}</span>
                    </div>
                    <div class="small text-secondary mt-1">${row.category || 'Chưa phân loại'} · ${row.batch_number}</div>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <span class="badge text-bg-light">${row.formatted_price}</span>
                        <span class="badge ${stockClass}">Tồn: ${row.stock_quantity}</span>
                        <span class="small ${expClass}">HSD: ${row.formatted_expiration_date}</span>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <a href="{{ url('/admin/products') }}/${row.MaSP}" class="btn btn-sm btn-outline-info flex-fill">Xem</a>
                <a href="{{ url('/admin/products') }}/${row.MaSP}/edit" class="btn btn-sm btn-outline-warning flex-fill">Sửa</a>
                <button type="button" onclick="deleteProduct(${row.MaSP})" class="btn btn-sm btn-outline-danger flex-fill">Xóa</button>
            </div>
        </article>`;
    }).join('');
}

function deleteProduct(productId) {
    window.AdminUI.confirmDelete({
        title: 'Xác nhận xóa',
        text: 'Bạn có chắc muốn xóa sản phẩm này? Thao tác này có thể ảnh hưởng đến các đơn hàng cũ.',
        confirmButtonText: 'Xóa sản phẩm'
    }).then((result) => {
        if (!result.isConfirmed) return;

        $.ajax({
            url: `{{ url('/admin/products') }}/${productId}`,
            type: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                _method: 'DELETE'
            },
            success: function(response) {
                window.AdminUI.toast('success', response.message || 'Đã xóa sản phẩm');
                $('#productsTable').DataTable().ajax.reload(null, false);
            },
            error: function(xhr) {
                const message = xhr.responseJSON?.message || 'Không thể xóa sản phẩm. Hãy thử lại.';
                Swal.fire('Lỗi', message, 'error');
            }
        });
    });
}

window.deleteProduct = deleteProduct;
</script>
@endpush
