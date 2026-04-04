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
                <div class="label">{{ __('messages.admin.inventory.total_products') }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <div class="icon text-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div class="value">{{ $summary['low_stock'] ?? 0 }}</div>
                <div class="label">{{ __('messages.admin.inventory.low_stock_label') }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <div class="icon text-warning">
                    <i class="bi bi-calendar-x"></i>
                </div>
                <div class="value">{{ $summary['expiring_soon'] ?? 0 }}</div>
                <div class="label">{{ __('messages.admin.inventory.expiring_soon_label') }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="metric-card">
                <div class="icon text-secondary">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div class="value">{{ $summary['out_of_stock'] ?? 0 }}</div>
                <div class="label">{{ __('messages.admin.inventory.out_of_stock') }}</div>
            </div>
        </div>
    </div>

    <!-- Inventory Table -->
    <div class="glass-card">
        <h5 class="mb-4">{{ __('messages.admin.inventory.inventory_list') }}</h5>
        
        <div class="table-responsive">
            <table id="inventoryTable" class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>{{ __('messages.admin.inventory.product_name') }}</th>
                        <th>{{ __('messages.admin.inventory.category') }}</th>
                        <th>{{ __('messages.admin.inventory.batch_number') }}</th>
                        <th>{{ __('messages.admin.inventory.stock') }}</th>
                        <th>{{ __('messages.admin.inventory.reorder_point') }}</th>
                        <th>{{ __('messages.admin.inventory.expiration_date') }}</th>
                        <th>{{ __('messages.admin.inventory.status') }}</th>
                        <th>{{ __('messages.admin.inventory.action') }}</th>
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
                                <span class="badge bg-danger">{{ __('messages.admin.inventory.status_expired') }}</span>
                            @elseif($product->isExpiringSoon(30))
                                <span class="badge bg-warning">{{ __('messages.admin.inventory.status_expiring_soon') }}</span>
                            @elseif($product->isLowStock())
                                <span class="badge bg-info">{{ __('messages.admin.inventory.status_low_stock') }}</span>
                            @else
                                <span class="badge bg-success">{{ __('messages.admin.inventory.status_normal') }}</span>
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
                    <h5 class="modal-title">{{ __('messages.admin.inventory.adjust_stock_title') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="product_id" id="adjust_product_id">
                    <div class="mb-3">
                        <label class="form-label">{{ __('messages.admin.inventory.product') }}</label>
                        <input type="text" id="adjust_product_name" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('messages.admin.inventory.current_stock') }}</label>
                        <input type="text" id="adjust_current_stock" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('messages.admin.inventory.adjust_quantity') }} <span class="text-danger">*</span></label>
                        <input type="number" name="adjustment" class="form-control" placeholder="Nhập số dương để tăng, số âm để giảm" required>
                        <small class="text-muted">{{ __('messages.admin.inventory.adjust_hint') }}</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('messages.admin.inventory.reason') }} <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('messages.admin.inventory.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('messages.admin.inventory.submit_adjust') }}</button>
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
                processing: "{{ __('messages.admin.inventory.processing') }}",
                search: "{{ __('messages.admin.inventory.search') }}",
                lengthMenu: "{{ __('messages.admin.inventory.lengthMenu') }}",
                info: "{{ __('messages.admin.inventory.info') }}",
                infoEmpty: "{{ __('messages.admin.inventory.infoEmpty') }}",
                infoFiltered: "{{ __('messages.admin.inventory.infoFiltered') }}",
                loadingRecords: "{{ __('messages.admin.inventory.loadingRecords') }}",
                zeroRecords: "{{ __('messages.admin.inventory.zeroRecords') }}",
                emptyTable: "{{ __('messages.admin.inventory.emptyTable') }}",
                paginate: { first: "{{ __('messages.admin.inventory.first') }}", previous: "{{ __('messages.admin.inventory.previous') }}", next: "{{ __('messages.admin.inventory.next') }}", last: "{{ __('messages.admin.inventory.last') }}" },
                aria: { sortAscending: "{{ __('messages.admin.inventory.sortAscending') }}", sortDescending: "{{ __('messages.admin.inventory.sortDescending') }}" }
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
                        title: '{{ __('messages.admin.inventory.success') }}',
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
                        title: '{{ __('messages.admin.inventory.error') }}',
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
