<!-- Order History Section -->
<div class="glass-card rounded-4 border-0 p-4 p-md-5">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-emerald-100 text-emerald-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 icon-circle-md">
                <i class="bi bi-bag-check-fill fs-3"></i>
            </div>
            <div>
                <h5 class="fw-bold text-gray-900 mb-1">Lịch Sử Đơn Hàng</h5>
                <p class="text-gray-500 small mb-0">Quản lý và theo dõi đơn hàng của bạn</p>
            </div>
        </div>
    </div>

    @php
        $orders = auth()->user()->orders()->with('items.product')->latest()->paginate(5);
    @endphp

    @forelse($orders as $order)
        <div class="glass-card-sm mb-3">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <div class="d-flex align-items-start gap-3">
                        <div class="bg-emerald-50 rounded-3 p-2 flex-shrink-0">
                            <i class="bi bi-receipt fs-4 text-emerald-600"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                <h6 class="fw-bold text-gray-900 mb-0">Đơn hàng #{{ $order->id }}</h6>
                                @if($order->status == 'Pending')
                                    <span class="badge-glass-warning rounded-pill px-2 py-1 small">
                                        <i class="bi bi-clock me-1"></i>Chờ xử lý
                                    </span>
                                @elseif($order->status == 'Delivering')
                                    <span class="badge-glass-info rounded-pill px-2 py-1 small">
                                        <i class="bi bi-truck me-1"></i>Đang giao
                                    </span>
                                @elseif($order->status == 'Completed')
                                    <span class="badge-glass-success rounded-pill px-2 py-1 small">
                                        <i class="bi bi-check-circle me-1"></i>Hoàn thành
                                    </span>
                                @else
                                    <span class="badge-glass-danger rounded-pill px-2 py-1 small">
                                        <i class="bi bi-x-circle me-1"></i>Đã hủy
                                    </span>
                                @endif
                            </div>
                            <p class="text-gray-500 small mb-2">
                                <i class="bi bi-calendar3 me-1"></i>{{ $order->created_at->format('d/m/Y H:i') }}
                            </p>
                            <p class="text-gray-600 mb-0 small">
                                {{ $order->items->count() }} sản phẩm
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <p class="fw-bold text-emerald-700 fs-5 mb-2">{{ format_currency_vi($order->total_amount) }}</p>
                    <button class="btn btn-glass btn-sm rounded-pill px-3" 
                       data-bs-toggle="modal" data-bs-target="#orderModal{{ $order->id }}">
                        <i class="bi bi-eye me-1"></i>Chi tiết
                    </button>
                </div>
            </div>
        </div>

        <!-- Order Detail Modal -->
        <div class="modal fade modal-glass" id="orderModal{{ $order->id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold">Chi tiết đơn hàng #{{ $order->id }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <small class="text-gray-500 d-block mb-1">Ngày đặt</small>
                                <p class="fw-medium mb-0">{{ $order->created_at->format('d/m/Y H:i') }}</p>
                            </div>
                            <div class="col-6">
                                <small class="text-gray-500 d-block mb-1">Trạng thái</small>
                                <p class="fw-medium mb-0">{{ order_status_text($order->status) }}</p>
                            </div>
                            <div class="col-12">
                                <small class="text-gray-500 d-block mb-1">Địa chỉ giao hàng</small>
                                <p class="fw-medium mb-0">{{ $order->shipping_address }}</p>
                            </div>
                        </div>

                        <h6 class="fw-bold mb-3">Sản phẩm</h6>
                        @foreach($order->items as $item)
                            <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
                                <div class="bg-gray-50 rounded-3 overflow-hidden flex-shrink-0" style="width: 60px; height: 60px;">
                                    @if($item->product && $item->product->image)
                                        <img src="{{ asset('storage/' . $item->product->image) }}" 
                                             alt="{{ $item->product->name }}" 
                                             class="w-100 h-100 object-fit-cover">
                                    @else
                                        <div class="w-100 h-100 d-flex align-items-center justify-content-center">
                                            <i class="bi bi-capsule text-gray-300 fs-4"></i>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-grow-1">
                                    <p class="fw-medium mb-1">{{ $item->product ? $item->product->name : 'Sản phẩm không tồn tại' }}</p>
                                    <small class="text-gray-500">{{ format_currency_vi($item->unit_price) }} × {{ $item->quantity }}</small>
                                </div>
                                <p class="fw-bold text-emerald-700 mb-0">{{ format_currency_vi($item->unit_price * $item->quantity) }}</p>
                            </div>
                        @endforeach

                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <span class="fw-bold fs-5">Tổng cộng</span>
                            <span class="fw-bold fs-4 text-emerald-700">{{ format_currency_vi($order->total_amount) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="text-center py-5">
            <div class="bg-gray-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" 
                 style="width: 80px; height: 80px;">
                <i class="bi bi-bag-x text-gray-400 fs-1"></i>
            </div>
            <h6 class="fw-bold text-gray-700 mb-2">Chưa có đơn hàng nào</h6>
            <p class="text-gray-500 mb-3">Bạn chưa thực hiện đơn hàng nào</p>
            <a href="{{ route('products.index') }}" class="btn btn-glass-primary rounded-pill px-4">
                <i class="bi bi-cart-plus me-2"></i>Mua sắm ngay
            </a>
        </div>
    @endforelse

    @if($orders->hasPages())
        <div class="mt-4">
            {{ $orders->links() }}
        </div>
    @endif
</div>
