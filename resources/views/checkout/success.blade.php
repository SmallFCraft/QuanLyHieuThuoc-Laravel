@extends('layouts.app')

@section('title', 'Đặt hàng thành công - Nhà Thuốc Sinh Thái')

@section('content')
<!-- Progress Steps -->
<section class="py-4 bg-white border-bottom">
    <div class="container">
        <div class="d-flex justify-content-center gap-5">
            <div class="text-center">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-1 text-white" style="width:32px; height:32px; background:#10b981;">
                    <i class="bi bi-check"></i>
                </div>
                <p class="small mb-0 text-emerald-700 fw-medium">Giỏ hàng</p>
            </div>
            <div class="d-flex align-items-center text-emerald-300">───</div>
            <div class="text-center">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-1 text-white" style="width:32px; height:32px; background:#10b981;">
                    <i class="bi bi-check"></i>
                </div>
                <p class="small mb-0 text-emerald-700 fw-medium">Thanh toán</p>
            </div>
            <div class="d-flex align-items-center text-emerald-300">───</div>
            <div class="text-center">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-1 text-white fw-bold" style="width:32px; height:32px; background:#10b981;">
                    <i class="bi bi-check"></i>
                </div>
                <p class="small mb-0 text-emerald-700 fw-medium">Hoàn tất</p>
            </div>
        </div>
    </div>
</section>

<section class="py-5">
    <div class="container" style="max-width: 800px;">
        <!-- Success Message -->
        <div class="text-center mb-5">
            <div class="bg-emerald-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-4 shadow-sm" style="width:100px; height:100px;">
                <i class="bi bi-check-lg text-emerald-600" style="font-size: 3rem;"></i>
            </div>
            <h2 class="display-6 fw-bold text-emerald-700 mb-2">Đặt hàng thành công!</h2>
            <p class="text-gray-500 lead">Cảm ơn bạn đã tin tưởng. Đơn hàng đang được xử lý.</p>
        </div>

        <!-- Order Info -->
        <div class="glass-card rounded-4 border-0 p-4 p-md-5 mb-4">
            <h5 class="fw-bold mb-4 text-gray-900">
                <i class="bi bi-receipt text-emerald-600 me-2"></i>Thông tin đơn hàng
            </h5>
            
            <div class="row g-3 mb-4">
                <div class="col-sm-6">
                    <div class="rounded-3 p-3" style="background: rgba(16,185,129,0.06);">
                        <small class="text-gray-500 d-block mb-1">Mã đơn hàng</small>
                        <span class="fw-bold text-gray-800">#{{ $order->id }}</span>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="rounded-3 p-3" style="background: rgba(16,185,129,0.06);">
                        <small class="text-gray-500 d-block mb-1">Ngày đặt</small>
                        <span class="fw-bold text-gray-800">{{ $order->formatted_created_at }}</span>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="rounded-3 p-3" style="background: rgba(245,158,11,0.06);">
                        <small class="text-gray-500 d-block mb-1">Trạng thái</small>
                        <span class="badge bg-warning bg-opacity-10 text-warning-emphasis rounded-pill px-3 py-2">
                            <i class="bi bi-clock-history me-1"></i>{{ $order->status }}
                        </span>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="rounded-3 p-3" style="background: rgba(16,185,129,0.06);">
                        <small class="text-gray-500 d-block mb-1">Tổng tiền</small>
                        <span class="fw-bold text-emerald-700 fs-5">{{ $order->formatted_total }}</span>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <small class="text-gray-500 d-block mb-1"><i class="bi bi-geo-alt me-1"></i>Địa chỉ giao hàng</small>
                <p class="fw-medium text-gray-800 mb-0">{{ $order->shipping_address }}</p>
            </div>
            <div class="mb-3">
                <small class="text-gray-500 d-block mb-1"><i class="bi bi-telephone me-1"></i>Số điện thoại</small>
                <p class="fw-medium text-gray-800 mb-0">{{ $order->shipping_phone }}</p>
            </div>
            
            @if($order->delivery_time)
            <div class="mb-3">
                <small class="text-gray-500 d-block mb-1"><i class="bi bi-clock me-1"></i>Thời gian giao hàng mong muốn</small>
                <p class="fw-medium text-gray-800 mb-0">
                    @switch($order->delivery_time)
                        @case('morning') Buổi sáng (8h - 12h) @break
                        @case('afternoon') Buổi chiều (13h - 17h) @break
                        @case('evening') Buổi tối (17h - 20h) @break
                        @default Bất kỳ lúc nào
                    @endswitch
                </p>
            </div>
            @endif

            @if($order->order_notes)
            <div>
                <small class="text-gray-500 d-block mb-1"><i class="bi bi-journal-text me-1"></i>Ghi chú đơn hàng</small>
                <p class="fw-medium text-gray-800 mb-0">{{ $order->order_notes }}</p>
            </div>
            @endif
        </div>

        <!-- Order Items -->
        <div class="glass-card rounded-4 border-0 p-4 p-md-5 mb-4">
            <h5 class="fw-bold mb-4 text-gray-900">
                <i class="bi bi-bag-check text-emerald-600 me-2"></i>Chi tiết đơn hàng
            </h5>
            
            @foreach($order->items as $item)
                <div class="d-flex justify-content-between align-items-center py-3 border-bottom" style="border-color: rgba(0,0,0,0.06) !important;">
                    <div>
                        <p class="fw-medium text-gray-800 mb-0">{{ $item->product->name }}</p>
                        <small class="text-gray-400">{{ number_format($item->unit_price, 0, ',', '.') }}₫ × {{ $item->quantity }}</small>
                    </div>
                    <span class="fw-bold text-gray-700">{{ $item->formatted_subtotal }}</span>
                </div>
            @endforeach
            
            <div class="d-flex justify-content-between align-items-center pt-4 mt-2">
                <span class="fw-bold fs-5 text-gray-800">Tổng cộng</span>
                <span class="fw-bold fs-4 text-emerald-700">{{ $order->formatted_total }}</span>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="text-center">
            <a href="{{ route('products.index') }}" class="btn btn-lg text-white fw-bold rounded-pill shadow px-5" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                <i class="bi bi-cart-plus me-2"></i>Tiếp tục mua sắm
            </a>
        </div>
    </div>
</section>
@endsection
