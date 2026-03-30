@extends('layouts.admin')

@section('title', 'Quản Lý Sản Phẩm')
@section('page-title', 'Quản Lý Sản Phẩm')

@section('content')
<div class="container-fluid">
    <div class="glass-card mb-4 border-success border-opacity-25">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
            <h5 class="mb-0 text-success fw-bold"><i class="bi bi-box-seam me-2"></i>Danh Sách Sản Phẩm</h5>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.products.create') }}" class="btn btn-success bg-gradient text-white border-0 shadow-sm">
                    <i class="bi bi-plus-circle me-1"></i> Thêm Sản Phẩm
                </a>
            </div>
        </div>

        <hr class="text-success opacity-25 my-4">

        <!-- Filters Section -->
        <div class="row mb-4 bg-success bg-opacity-10 p-3 rounded-3 border border-success border-opacity-25">
            <div class="col-md-4">
                <label for="categoryFilter" class="form-label text-success fw-medium"><i class="bi bi-funnel me-1"></i>Lọc theo Danh Mục</label>
                <select id="categoryFilter" class="form-select border-success border-opacity-25 shadow-sm">
                    <option value="">Tất cả danh mục</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-8 d-flex align-items-end justify-content-end mt-3 mt-md-0">
                <button type="button" id="resetFilterBtn" class="btn btn-outline-success shadow-sm">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Đặt lại bộc lọc
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table id="productsTable" class="table table-hover align-middle w-100">
                <thead class="bg-success bg-opacity-10">
                    <tr>
                        <th class="text-success">ID</th>
                        <th class="text-success">Hình Ảnh</th>
                        <th class="text-success">Tên Sản Phẩm</th>
                        <th class="text-success">Danh Mục</th>
                        <th class="text-success">Giá Bán</th>
                        <th class="text-success">Tồn Kho</th>
                        <th class="text-success">Hạn Sử Dụng</th>
                        <th class="text-success">Số Lô</th>
                        <th class="text-success text-center">Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- DataTable content injected via AJAX -->
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
    /* DataTables Pagination & Glassmorphism adaptations */
    .dataTables_wrapper .dataTables_paginate .paginate_button.current, 
    .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
        background: linear-gradient(135deg, #10b981, #059669) !important;
        color: white !important;
        border: none !important;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        border-radius: 8px;
        border: 1px solid transparent;
        transition: all 0.2s ease;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: #ecfdf5 !important;
        border: 1px solid #a7f3d0 !important;
        color: #064e3b !important;
    }
    .dataTables_wrapper .dataTables_filter input {
        border: 1px solid #6ee7b7;
        border-radius: 8px;
        padding: 6px 12px;
        outline: none;
        transition: box-shadow 0.2s;
    }
    .dataTables_wrapper .dataTables_filter input:focus {
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
    }
    .dataTables_wrapper .dataTables_length select {
        border: 1px solid #6ee7b7;
        border-radius: 8px;
        padding: 4px 8px;
    }
    table.dataTable.dtr-inline.collapsed>tbody>tr>td.dtr-control:before, 
    table.dataTable.dtr-inline.collapsed>tbody>tr>th.dtr-control:before {
        background-color: #10b981;
    }
</style>
@endpush

@push('scripts')
<script>
    $(document).ready(function() {
        var table = $('#productsTable').DataTable({
            processing: true,
            serverSide: true,
            responsive: true,
            ajax: {
                url: '{{ route('admin.products.data') }}',
                data: function (d) {
                    d.category_id = $('#categoryFilter').val();
                }
            },
            columns: [
                { data: 'id', name: 'id' },
                { data: 'image_url', name: 'image', orderable: false, searchable: false },
                { data: 'name', name: 'name' },
                { data: 'category', name: 'category.name' },
                { data: 'formatted_price', name: 'price' },
                { data: 'stock_quantity', name: 'stock_quantity' },
                { data: 'formatted_expiration_date', name: 'expiration_date' },
                { data: 'batch_number', name: 'batch_number' },
                { data: 'id', name: 'actions', orderable: false, searchable: false, className: 'text-center' }
            ],
            columnDefs: [
                {
                    // ID column
                    targets: 0,
                    render: function(data) {
                        return '#' + data;
                    }
                },
                {
                    // Image column
                    targets: 1,
                    render: function(data, type, row) {
                        if (data) {
                            return '<img src="' + data + '" alt="' + row.name + '" class="rounded-3 shadow-sm border border-success border-opacity-25" style="width: 45px; height: 45px; object-fit: cover;">';
                        }
                        return '<div class="bg-gray-50 rounded-3 d-flex align-items-center justify-content-center border border-success border-opacity-25" style="width: 45px; height: 45px;"><i class="bi bi-capsule text-gray-300"></i></div>';
                    }
                },
                {
                    // Name column
                    targets: 2,
                    render: function(data) {
                        return '<strong class="text-success text-opacity-75">' + data + '</strong>';
                    }
                },
                {
                    // Category column
                    targets: 3,
                    render: function(data) {
                        return '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25">' + data + '</span>';
                    }
                },
                {
                    // Price column
                    targets: 4,
                    render: function(data) {
                        return '<span class="fw-bold fw-medium" style="color: #047857;">' + data + '</span>';
                    }
                },
                {
                    // Stock quantity column
                    targets: 5,
                    render: function(data, type, row) {
                        if (row.is_low_stock) {
                            return '<span class="badge bg-danger rounded-pill px-2 py-1"><i class="bi bi-exclamation-triangle me-1"></i>' + data + '</span>';
                        }
                        return '<span class="badge bg-success rounded-pill px-2 py-1">' + data + '</span>';
                    }
                },
                {
                    // Expiration date column
                    targets: 6,
                    render: function(data, type, row) {
                        var className = 'text-dark';
                        if (row.is_expired) {
                            className = 'text-danger fw-bold';
                        } else if (row.is_expiring_soon) {
                            className = 'text-warning fw-bold';
                        }
                        return '<span class="' + className + '">' + data + '</span>';
                    }
                },
                {
                    // Batch number column
                    targets: 7,
                    render: function(data) {
                        return '<small class="text-muted">' + data + '</small>';
                    }
                },
                {
                    // Actions column
                    targets: 8,
                    render: function(data, type, row) {
                        return '<div class="btn-group" role="group">' +
                            '<a href="/admin/products/' + data + '" class="btn btn-sm btn-outline-info" title="Xem chi tiết"><i class="bi bi-eye"></i></a>' +
                            '<a href="/admin/products/' + data + '/edit" class="btn btn-sm btn-outline-warning" title="Chỉnh sửa"><i class="bi bi-pencil"></i></a>' +
                            '<button onclick="deleteProduct(' + data + ')" class="btn btn-sm btn-outline-danger" title="Xóa"><i class="bi bi-trash"></i></button>' +
                            '</div>';
                    }
                }
            ],
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
            dom: '<"row align-items-center mb-3"<"col-sm-12 col-md-4"l><"col-sm-12 col-md-4 text-center"B><"col-sm-12 col-md-4"f>>rt<"row align-items-center mt-3"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>',
            buttons: [
                {
                    extend: 'csv',
                    text: '<i class="bi bi-filetype-csv me-1"></i> Xuất CSV',
                    className: 'btn btn-sm btn-outline-success shadow-sm rounded-pill px-3'
                },
                {
                    extend: 'excel',
                    text: '<i class="bi bi-file-earmark-spreadsheet me-1"></i> Xuất Excel',
                    className: 'btn btn-sm btn-outline-success shadow-sm rounded-pill px-3 ms-2'
                }
            ],
            drawCallback: function() {
                $('.dataTables_paginate > .pagination').addClass('justify-content-end mb-0');
            }
        });

        // Trigger filter reload
        $('#categoryFilter').on('change', function() {
            table.draw();
        });

        // Reset filter
        $('#resetFilterBtn').on('click', function() {
            $('#categoryFilter').val('');
            table.search(''); // reset search
            table.draw();
        });
    });

    function deleteProduct(productId) {
        Swal.fire({
            title: 'Xác nhận xóa',
            text: 'Bạn có chắc muốn xóa sản phẩm này? Thao tác này có thể ảnh hưởng đến các đơn hàng cũ.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Có, Xóa',
            cancelButtonText: 'Hủy',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            background: 'rgba(255, 255, 255, 0.95)',
            backdrop: 'rgba(0, 0, 0, 0.4)'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: `/admin/products/${productId}`,
                    type: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        _method: 'DELETE'
                    },
                    success: function(response) {
                        Swal.fire({
                            title: 'Thành công!',
                            text: response.message,
                            icon: 'success',
                            confirmButtonColor: '#10b981',
                            background: 'rgba(255, 255, 255, 0.95)'
                        }).then(() => {
                            $('#productsTable').DataTable().ajax.reload(null, false);
                        });
                    },
                    error: function(xhr) {
                        Swal.fire({
                            title: 'Lỗi!',
                            text: 'Không thể xóa sản phẩm. Hãy thử lại.',
                            icon: 'error',
                            confirmButtonColor: '#10b981',
                            background: 'rgba(255, 255, 255, 0.95)'
                        });
                    }
                });
            }
        });
    }
</script>
@endpush
