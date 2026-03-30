@extends('layouts.admin')

@section('title', 'Chi tiết đơn hàng #' . $order->id)
@section('page-title', 'Chi tiết đơn hàng #' . $order->id)
@section('page-subtitle', 'Quản lý và cập nhật trạng thái đơn hàng')

@section('content')
<div class="container-fluid px-0">
    <div class="row g-4 mb-4">
        <!-- Order Information -->
        <div class="col-md-6">
            <div class="glass-card h-100">
                <h5 class="fw-bold mb-4"><i class="bi bi-receipt text-emerald-600 me-2"></i>Thông tin đơn hàng</h5>
                <table class="table table-borderless mb-0">
                    <tr>
                        <td class="text-gray-500 ps-0" style="width: 140px;">Mã đơn hàng</td>
                        <td class="fw-bold">#{{ $order->id }}</td>
                    </tr>
                    <tr>
                        <td class="text-gray-500 ps-0">Ngày đặt</td>
                        <td class="fw-medium">{{ $order->formatted_created_at }}</td>
                    </tr>
                    <tr>
                        <td class="text-gray-500 ps-0">Trạng thái</td>
                        <td>
                            @if($order->status == 'Pending')
                                <span class="badge bg-warning bg-opacity-10 text-warning-emphasis rounded-pill px-3 py-2"><i class="bi bi-clock me-1"></i>Đang chờ</span>
                            @elseif($order->status == 'Delivering')
                                <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2"><i class="bi bi-truck me-1"></i>Đang giao</span>
                            @elseif($order->status == 'Completed')
                                <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2"><i class="bi bi-check-circle me-1"></i>Hoàn thành</span>
                            @else
                                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2"><i class="bi bi-x-circle me-1"></i>Đã hủy</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="text-gray-500 ps-0">Tổng tiền</td>
                        <td class="fw-bold text-emerald-700 fs-5">{{ $order->formatted_total }}</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Customer Information -->
        <div class="col-md-6">
            <div class="glass-card h-100">
                <h5 class="fw-bold mb-4"><i class="bi bi-person text-emerald-600 me-2"></i>Thông tin khách hàng</h5>
                <table class="table table-borderless mb-0">
                    <tr>
                        <td class="text-gray-500 ps-0" style="width: 140px;">Khách hàng</td>
                        <td class="fw-medium">{{ $order->user->name }}</td>
                    </tr>
                    <tr>
                        <td class="text-gray-500 ps-0">Email</td>
                        <td class="fw-medium">{{ $order->user->email }}</td>
                    </tr>
                    <tr>
                        <td class="text-gray-500 ps-0">Điện thoại</td>
                        <td class="fw-medium">{{ $order->shipping_phone ?? 'Chưa cung cấp' }}</td>
                    </tr>
                    <tr>
                        <td class="text-gray-500 ps-0">Địa chỉ</td>
                        <td class="fw-medium">{{ $order->shipping_address }}</td>
                    </tr>
                    @if($order->delivery_time)
                    <tr>
                        <td class="text-gray-500 ps-0">Thời gian giao</td>
                        <td class="fw-medium">
                            @switch($order->delivery_time)
                                @case('morning')
                                    <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3 py-2">
                                        <i class="bi bi-sunrise me-1"></i>Buổi sáng (8h - 12h)
                                    </span>
                                    @break
                                @case('afternoon')
                                    <span class="badge bg-warning bg-opacity-10 text-warning-emphasis rounded-pill px-3 py-2">
                                        <i class="bi bi-sun me-1"></i>Buổi chiều (13h - 17h)
                                    </span>
                                    @break
                                @case('evening')
                                    <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2">
                                        <i class="bi bi-moon-stars me-1"></i>Buổi tối (17h - 20h)
                                    </span>
                                    @break
                                @default
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3 py-2">
                                        <i class="bi bi-clock me-1"></i>Bất kỳ lúc nào
                                    </span>
                            @endswitch
                        </td>
                    </tr>
                    @endif
                    @if($order->order_notes)
                    <tr>
                        <td class="text-gray-500 ps-0">Ghi chú</td>
                        <td class="fw-medium fst-italic text-gray-700">"{{ $order->order_notes }}"</td>
                    </tr>
                    @endif
                </table>
            </div>
        </div>
    </div>

    <!-- Order Items -->
    <div class="glass-card mb-4">
        <h5 class="fw-bold mb-4"><i class="bi bi-box-seam text-emerald-600 me-2"></i>Chi tiết sản phẩm</h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-gray-600 fw-medium">Sản phẩm</th>
                        <th class="text-gray-600 fw-medium">Số lô</th>
                        <th class="text-gray-600 fw-medium">Đơn giá</th>
                        <th class="text-gray-600 fw-medium">Số lượng</th>
                        <th class="text-gray-600 fw-medium">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $item)
                        <tr>
                            <td class="fw-medium">{{ $item->product->name }}</td>
                            <td class="text-gray-500 small">{{ $item->batch_number }}</td>
                            <td>{{ number_format($item->unit_price, 0, ',', '.') }} ₫</td>
                            <td>{{ $item->quantity }}</td>
                            <td class="fw-bold">{{ $item->formatted_subtotal }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td colspan="4" class="text-end fw-bold">Tổng cộng:</td>
                        <td class="fw-bold text-emerald-700 fs-5">{{ $order->formatted_total }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Actions -->
    <div class="d-flex flex-wrap gap-3 align-items-center">
        @if($order->status != 'Completed' && $order->status != 'Cancelled')
            <form action="{{ route('admin.orders.updateStatus', $order->id) }}" method="POST" class="d-flex gap-2 align-items-center">
                @csrf
                @method('PATCH')
                <select name="status" class="form-select rounded-3 shadow-none" style="width: auto;">
                    @if($order->status == 'Pending')
                        <option value="Delivering">Chuyển sang Đang giao</option>
                        <option value="Cancelled">Hủy đơn hàng</option>
                    @elseif($order->status == 'Delivering')
                        <option value="Completed">Hoàn thành</option>
                    @endif
                </select>
                <button type="submit" class="btn text-white rounded-pill px-4 fw-medium shadow-sm" style="background: linear-gradient(135deg, #10b981, #059669);">
                    <i class="bi bi-arrow-clockwise me-1"></i>Cập nhật
                </button>
            </form>
        @endif

        @if($order->status == 'Pending')
            <form action="{{ route('admin.orders.cancel', $order->id) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-danger rounded-pill px-4 fw-medium" onclick="return confirm('Bạn có chắc chắn muốn hủy đơn hàng này?')">
                    <i class="bi bi-x-lg me-1"></i>Hủy đơn hàng
                </button>
            </form>
        @endif

        <a href="{{ route('admin.orders.index') }}" class="btn btn-outline-secondary rounded-pill px-4 fw-medium ms-auto">
            <i class="bi bi-arrow-left me-1"></i>Quay lại
        </a>
    </div>
</div>
@endsection
