@extends('layouts.admin')

@section('title', 'Quản lý tồn kho')
@section('page-title', 'Quản lý tồn kho')
@section('page-subtitle', 'FEFO, cảnh báo tồn thấp và hạn dùng')

@section('content')
@php
    $activeStatus = $status ?? request('status');
@endphp

<div class="container-fluid px-0">
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <a href="{{ route('admin.inventory.index') }}" class="text-decoration-none text-reset">
                <div class="metric-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">{{ __('messages.admin.inventory.total_products') }}</div>
                            <div class="value">{{ $summary['total_products'] ?? 0 }}</div>
                        </div>
                        <div class="icon"><i class="bi bi-boxes" aria-hidden="true"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="{{ route('admin.inventory.index', ['status' => 'low_stock']) }}" class="text-decoration-none text-reset">
                <div class="metric-card {{ $activeStatus === 'low_stock' ? 'border-danger' : '' }}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">{{ __('messages.admin.inventory.low_stock_label') }}</div>
                            <div class="value">{{ $summary['low_stock'] ?? 0 }}</div>
                        </div>
                        <div class="icon" style="background:#fef2f2;color:#b91c1c;"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="{{ route('admin.inventory.index', ['status' => 'expiring', 'days' => $days ?? 30]) }}" class="text-decoration-none text-reset">
                <div class="metric-card {{ $activeStatus === 'expiring' ? 'border-warning' : '' }}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">{{ __('messages.admin.inventory.expiring_soon_label') }}</div>
                            <div class="value">{{ $summary['expiring_soon'] ?? 0 }}</div>
                        </div>
                        <div class="icon" style="background:#fff7ed;color:#b45309;"><i class="bi bi-calendar-x" aria-hidden="true"></i></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="{{ route('admin.inventory.index', ['status' => 'out_of_stock']) }}" class="text-decoration-none text-reset">
                <div class="metric-card {{ $activeStatus === 'out_of_stock' ? 'border-secondary' : '' }}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">{{ __('messages.admin.inventory.out_of_stock') }}</div>
                            <div class="value">{{ $summary['out_of_stock'] ?? 0 }}</div>
                        </div>
                        <div class="icon" style="background:#f1f5f9;color:#475569;"><i class="bi bi-x-circle" aria-hidden="true"></i></div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <section class="admin-card">
        <div class="admin-card__header">
            <div>
                <h2 class="admin-card__title">{{ __('messages.admin.inventory.inventory_list') }}</h2>
                <p class="admin-card__description">
                    @if($activeStatus === 'low_stock')
                        Đang lọc sản phẩm tồn kho thấp.
                    @elseif($activeStatus === 'expiring')
                        Đang lọc sản phẩm sắp hết hạn trong {{ $days ?? 30 }} ngày.
                    @elseif($activeStatus === 'out_of_stock')
                        Đang lọc sản phẩm hết hàng.
                    @elseif($activeStatus === 'expired')
                        Đang lọc sản phẩm đã hết hạn.
                    @else
                        Sắp xếp theo hạn dùng gần nhất để hỗ trợ FEFO.
                    @endif
                </p>
            </div>
            @if($activeStatus)
                <a href="{{ route('admin.inventory.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Xóa lọc
                </a>
            @endif
        </div>

        <div class="admin-toolbar">
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('admin.inventory.index', ['status' => 'low_stock']) }}" class="btn btn-outline-danger {{ $activeStatus === 'low_stock' ? 'active' : '' }}">Tồn thấp</a>
                <a href="{{ route('admin.inventory.index', ['status' => 'expiring', 'days' => 30]) }}" class="btn btn-outline-warning {{ $activeStatus === 'expiring' ? 'active' : '' }}">Sắp hết hạn</a>
                <a href="{{ route('admin.inventory.index', ['status' => 'out_of_stock']) }}" class="btn btn-outline-secondary {{ $activeStatus === 'out_of_stock' ? 'active' : '' }}">Hết hàng</a>
            </div>
            <a href="{{ route('admin.products.create') }}" class="btn btn-success">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Thêm sản phẩm
            </a>
        </div>

        <div class="admin-table-wrap admin-desktop-table">
            <table id="inventoryTable" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>Mã</th>
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
                        @php
                            $statusText = __('messages.admin.inventory.status_normal');
                            $statusClass = 'text-bg-success';
                            if ($product->isExpired()) {
                                $statusText = __('messages.admin.inventory.status_expired');
                                $statusClass = 'text-bg-danger';
                            } elseif ($product->isExpiringSoon(30)) {
                                $statusText = __('messages.admin.inventory.status_expiring_soon');
                                $statusClass = 'text-bg-warning';
                            } elseif ($product->isLowStock()) {
                                $statusText = __('messages.admin.inventory.status_low_stock');
                                $statusClass = 'text-bg-info';
                            }
                        @endphp
                        <tr>
                            <td><span class="fw-semibold">{{ $product->display_code }}</span></td>
                            <td>{{ $product->name }}</td>
                            <td>{{ $product->category->name ?? 'Chưa phân loại' }}</td>
                            <td>{{ $product->batch_number }}</td>
                            <td><span class="badge {{ $product->isLowStock() ? 'text-bg-danger' : 'text-bg-success' }}">{{ $product->stock_quantity }}</span></td>
                            <td>{{ $product->reorder_point }}</td>
                            <td>{{ $product->expiration_date->format('d/m/Y') }}</td>
                            <td><span class="badge {{ $statusClass }}">{{ $statusText }}</span></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" type="button"
                                    data-product-id="{{ $product->MaSP }}"
                                    data-product-name="{{ $product->name }}"
                                    data-product-stock="{{ $product->stock_quantity }}"
                                    onclick="adjustInventory(this)">
                                    <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>{{ __('messages.admin.inventory.adjust') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="admin-mobile-list">
            @forelse($products as $product)
                <article class="admin-mobile-item">
                    <div class="d-flex justify-content-between gap-3">
                        <div>
                            <strong>{{ $product->name }}</strong>
                            <div class="small text-secondary">{{ $product->display_code }} · {{ $product->category->name ?? 'Chưa phân loại' }}</div>
                            <div class="small text-secondary">Lô {{ $product->batch_number }} · HSD {{ $product->expiration_date->format('d/m/Y') }}</div>
                        </div>
                        <span class="badge {{ $product->isLowStock() ? 'text-bg-danger' : 'text-bg-success' }} align-self-start">{{ $product->stock_quantity }}</span>
                    </div>
                    <button class="btn btn-sm btn-outline-primary w-100 mt-3" type="button"
                        data-product-id="{{ $product->MaSP }}"
                        data-product-name="{{ $product->name }}"
                        data-product-stock="{{ $product->stock_quantity }}"
                        onclick="adjustInventory(this)">
                        <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>{{ __('messages.admin.inventory.adjust') }}
                    </button>
                </article>
            @empty
                <div class="admin-empty-state">Không có sản phẩm phù hợp.</div>
            @endforelse
        </div>

        <div class="admin-mobile-pagination mt-3">
            {{ $products->links() }}
        </div>
    </section>
</div>

<div class="modal fade" id="adjustInventoryModal" tabindex="-1" aria-labelledby="adjustInventoryTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="adjustInventoryForm">
                @csrf
                <div class="modal-header">
                    <h2 class="modal-title h5" id="adjustInventoryTitle">{{ __('messages.admin.inventory.adjust_stock_title') }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="MaSP" id="adjust_MaSP">
                    <div class="mb-3">
                        <label class="form-label" for="adjust_product_name">{{ __('messages.admin.inventory.product') }}</label>
                        <input type="text" id="adjust_product_name" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="adjust_current_stock">{{ __('messages.admin.inventory.current_stock') }}</label>
                        <input type="text" id="adjust_current_stock" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="adjustment">{{ __('messages.admin.inventory.adjust_quantity') }} <span class="text-danger">*</span></label>
                        <input type="number" name="adjustment" id="adjustment" class="form-control" inputmode="numeric" placeholder="VD: 10 hoặc -5" required>
                        <div class="form-text">{{ __('messages.admin.inventory.adjust_hint') }}</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="reason">{{ __('messages.admin.inventory.reason') }} <span class="text-danger">*</span></label>
                        <textarea name="reason" id="reason" class="form-control" rows="3" maxlength="255" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('messages.admin.inventory.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('messages.admin.inventory.submit_adjust') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('DOMContentLoaded', function() {
    $('#inventoryTable').DataTable({
        language: window.AdminUI.dtLanguage,
        order: [[6, 'asc']],
        pageLength: 20,
        responsive: true,
        dom: '<"row align-items-center mb-3 g-2"<"col-md-4"l><"col-md-4"B><"col-md-4"f>>rt<"row align-items-center mt-3 g-2"<"col-md-6"i><"col-md-6"p>>',
        buttons: [
            window.AdminUI.excelButton({
                url: '{{ route('admin.inventory.export') }}',
                params: (dt) => ({
                    status: @json($activeStatus),
                    days: @json($days ?? 30),
                    q: dt.search(),
                }),
            })
        ]
    });

    $('#adjustInventoryForm').on('submit', function(e) {
        e.preventDefault();
        const submitButton = this.querySelector('button[type="submit"]');
        submitButton.disabled = true;

        $.ajax({
            url: '{{ route("admin.inventory.adjust") }}',
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                bootstrap.Modal.getInstance(document.getElementById('adjustInventoryModal'))?.hide();
                window.AdminUI.toast('success', response.message || 'Đã điều chỉnh tồn kho');
                setTimeout(() => location.reload(), 700);
            },
            error: function(xhr) {
                let message = xhr.responseJSON?.message || 'Có lỗi xảy ra';
                if (xhr.responseJSON?.errors) {
                    message = Object.values(xhr.responseJSON.errors).flat().join('<br>');
                }
                Swal.fire({ title: '{{ __('messages.admin.inventory.error') }}', html: message, icon: 'error' });
            },
            complete: function() {
                submitButton.disabled = false;
            }
        });
    });
});

function adjustInventory(button) {
    const productId = button.dataset.productId;
    const productName = button.dataset.productName;
    const currentStock = button.dataset.productStock;

    $('#adjust_MaSP').val(productId);
    $('#adjust_product_name').val(productName);
    $('#adjust_current_stock').val(currentStock);
    $('#adjustment').val('');
    $('#reason').val('');
    new bootstrap.Modal(document.getElementById('adjustInventoryModal')).show();
}

window.adjustInventory = adjustInventory;
</script>
@endpush
