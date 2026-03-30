@extends('layouts.admin')

@section('title', 'Quản Lý Tồn Kho')
@section('page-title', 'Quản Lý Tồn Kho')

@section('content')
<div class="container-fluid">
    <!-- Summary Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="metric-card">
                <div class="icon text-primary">
                    <i class="bi bi-boxes"></i>
                </div>
                <div class="value">{{ $summary['total_products'] ?? 0 }}</div>
                <div class="label">Tổng Sản Phẩm</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <div class="icon text-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div class="value">{{ $summary['low_stock'] ?? 0 }}</div>
                <div class="label">Tồn Kho Thấp</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <div class="icon text-warning">
                    <i class="bi bi-calendar-x"></i>
                </div>
                <div class="value">{{ $summary['expiring_soon'] ?? 0 }}</div>
                <div class="label">Sắp Hết Hạn</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <div class="icon text-secondary">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div class="value">{{ $summary['out_of_stock'] ?? 0 }}</div>
                <div class="label">Hết Hàng</div>
            </div>
        </div>
    </div>

    <!-- Inventory Table -->
    <div class="glass-card">
        <h5 class="mb-4">Danh Sách Tồn Kho (FEFO)</h5>
        
        <div class="table-responsive">
            <table id="inventoryTable" class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tên Sản Phẩm</th>
                        <th>Danh Mục</th>
                        <th>Số Lô</th>
                        <th>Tồn Kho</th>
                        <th>Điểm Đặt Lại</th>
                        <th>Hạn Sử Dụng</th>
                        <th>Trạng Thái</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $product)
                    <tr class="{{ $product->isExpired() ? 'table-danger' : ($product->isExpiringSoon(30) ? 'table-warning' : '') }}">
                        <td>{{ $product->id }}</td>
                        <td>{{ $product->name }}</td>
                        <td>{{ $product->category->name }}</td>
                        <td>{{ $product->batch_number }}</td>
                        <td><span class="badge bg-{{ $product->stock_quantity > $product->reorder_point ? 'success' : 'danger' }}">{{ $product->stock_quantity }}</span></td>
                        <td>{{ $product->reorder_point }}</td>
                        <td>{{ $product->expiration_date->format('d/m/Y') }}</td>
                        <td>
                            @if($product->isExpired())
                                <span class="badge bg-danger">Đã hết hạn</span>
                            @elseif($product->isExpiringSoon(30))
                                <span class="badge bg-warning">Sắp hết hạn</span>
                            @elseif($product->isLowStock())
                                <span class="badge bg-info">Tồn kho thấp</span>
                            @else
                                <span class="badge bg-success">Bình thường</span>
                            @endif
                        </td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="adjustInventory({{ $product->id }})">
                                <i class="bi bi-pencil-square"></i> Điều chỉnh
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Adjust Inventory Modal -->
<div class="modal fade" id="adjustInventoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="adjustInventoryForm">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Điều Chỉnh Tồn Kho</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="product_id" id="adjust_product_id">
                    <div class="mb-3">
                        <label class="form-label">Sản Phẩm</label>
                        <input type="text" id="adjust_product_name" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tồn Kho Hiện Tại</label>
                        <input type="text" id="adjust_current_stock" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Số Lượng Điều Chỉnh <span class="text-danger">*</span></label>
                        <input type="number" name="adjustment" class="form-control" placeholder="Nhập số dương để tăng, số âm để giảm" required>
                        <small class="text-muted">Ví dụ: +10 để thêm 10, -5 để giảm 5</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lý Do <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary">Điều Chỉnh</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#inventoryTable').DataTable({
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
            order: [[6, 'asc']], // Sort by expiration date (FEFO)
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

        // Adjust inventory form submission
        $('#adjustInventoryForm').on('submit', function(e) {
            e.preventDefault();
            $.ajax({
                url: '{{ route("admin.inventory.adjust") }}',
                type: 'POST',
                data: $(this).serialize(),
                success: function(response) {
                    $('#adjustInventoryModal').modal('hide');
                    Swal.fire({
                        title: 'Thành công!',
                        text: response.message,
                        icon: 'success',
                        background: 'rgba(255, 255, 255, 0.9)'
                    }).then(() => location.reload());
                },
                error: function(xhr) {
                    let message = xhr.responseJSON.message || 'Có lỗi xảy ra';
                    if (xhr.responseJSON.errors) {
                        message = Object.values(xhr.responseJSON.errors).flat().join('<br>');
                    }
                    Swal.fire({
                        title: 'Lỗi!',
                        html: message,
                        icon: 'error',
                        background: 'rgba(255, 255, 255, 0.9)'
                    });
                }
            });
        });
    });

    function adjustInventory(productId) {
        const row = $(`#inventoryTable tbody tr:has(td:first:contains(${productId}))`);
        const productName = row.find('td:eq(1)').text();
        const currentStock = row.find('td:eq(4) span').text();

        $('#adjust_product_id').val(productId);
        $('#adjust_product_name').val(productName);
        $('#adjust_current_stock').val(currentStock);
        $('#adjustInventoryModal').modal('show');
    }
</script>
@endpush
