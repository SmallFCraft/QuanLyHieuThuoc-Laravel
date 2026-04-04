<!-- Order History Section -->
<div class="glass-card rounded-4 border-0 p-4 p-md-5">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="bg-emerald-100 text-emerald-600 rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 icon-circle-md">
                <i class="bi bi-bag-check-fill fs-3"></i>
            </div>
            <div>
                <h5 class="fw-bold text-gray-900 mb-1">{{ __('messages.profile.orders_title') }}</h5>
                <p class="text-gray-500 small mb-0">{{ __('messages.profile.orders_desc') }}</p>
            </div>
        </div>
    </div>

    @php
        $orders = auth()->user()->orders()->with('items.product')->latest()->paginate(5);
    @endphp

    @forelse($orders as $order)
        <div class="glass-card-sm mb-3" id="order-{{ $order->id }}">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <div class="d-flex align-items-start gap-3">
                        <div class="bg-emerald-50 rounded-3 p-2 flex-shrink-0">
                            <i class="bi bi-receipt fs-4 text-emerald-600"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                                <h6 class="fw-bold text-gray-900 mb-0">{{ __('messages.profile.order_prefix') }}{{ $order->id }}</h6>
                                @if($order->status == 'Pending')
                                    <span class="badge-glass-warning rounded-pill px-2 py-1 small">
                                        <i class="bi bi-clock me-1"></i>{{ order_status_text($order->status) }}
                                    </span>
                                @elseif($order->status == 'Delivering')
                                    <span class="badge-glass-info rounded-pill px-2 py-1 small">
                                        <i class="bi bi-truck me-1"></i>{{ order_status_text($order->status) }}
                                    </span>
                                @elseif($order->status == 'Completed')
                                    <span class="badge-glass-success rounded-pill px-2 py-1 small">
                                        <i class="bi bi-check-circle me-1"></i>{{ order_status_text($order->status) }}
                                    </span>
                                @else
                                    <span class="badge-glass-danger rounded-pill px-2 py-1 small">
                                        <i class="bi bi-x-circle me-1"></i>{{ order_status_text($order->status) }}
                                    </span>
                                @endif
                            </div>
                            <p class="text-gray-500 small mb-2">
                                <i class="bi bi-calendar3 me-1"></i>{{ $order->created_at->format('d/m/Y H:i') }}
                            </p>
                            <p class="text-gray-600 mb-0 small">
                                {{ $order->items->count() }} {{ __('messages.profile.product_count') }}
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <p class="fw-bold text-emerald-700 fs-5 mb-2">{{ format_currency_vi($order->total_amount) }}</p>
                    <button class="btn btn-glass btn-sm rounded-pill px-3" 
                       data-bs-toggle="modal" data-bs-target="#orderModal{{ $order->id }}">
                        <i class="bi bi-eye me-1"></i>{{ __('messages.profile.view_details') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Order Detail Modal -->
        @push('modals')
        <div class="modal fade modal-glass" id="orderModal{{ $order->id }}" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg modal-fullscreen-sm-down">
                <div class="modal-content glass-card border-0 shadow-lg rounded-4 overflow-hidden">
                    <div class="modal-header border-bottom-0 pb-0 pt-3 pt-md-4 px-3 px-md-5">
                        <h5 class="modal-title fw-bold text-gray-900 d-flex align-items-center">
                            <i class="bi bi-receipt text-emerald-600 me-2 fs-5"></i>
                            <span class="d-none d-sm-inline">{{ __('messages.profile.order_details_title') }}{{ $order->id }}</span>
                            <span class="d-sm-none">{{ __('messages.profile.order_prefix') }}{{ $order->id }}</span>
                        </h5>
                        <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="{{ __('messages.profile.close') }}"></button>
                    </div>
                    <div class="modal-body p-3 p-md-5 pt-3">
                        
                        <!-- Order Information Grid -->
                        <div class="row g-2 g-md-3 mb-3 mb-md-4">
                            <div class="col-6 col-sm-6">
                                <div class="rounded-3 rounded-md-4 p-2 p-md-3 h-100 border border-emerald-100" style="background: rgba(16,185,129,0.04);">
                                    <small class="text-gray-500 d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-calendar3 text-emerald-500 me-1"></i>{{ __('messages.profile.order_date') }}</small>
                                    <span class="fw-bold text-gray-800 d-block" style="font-size: 0.85rem;">{{ $order->created_at->format('d/m/Y') }}</span>
                                    <span class="text-gray-600 d-none d-md-inline" style="font-size: 0.85rem;">{{ $order->created_at->format('H:i') }}</span>
                                </div>
                            </div>
                            <div class="col-6 col-sm-6">
                                <div class="rounded-3 rounded-md-4 p-2 p-md-3 h-100 border border-amber-100" style="background: rgba(245,158,11,0.04);">
                                    <small class="text-gray-500 d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-info-circle text-amber-500 me-1"></i>{{ __('messages.profile.order_status') }}</small>
                                    <div class="mt-1">
                                        @if($order->status == 'Pending')
                                            <span class="badge-glass-warning rounded-pill px-2 py-1" style="font-size: 0.7rem;">
                                                <i class="bi bi-clock me-1"></i>{{ order_status_text($order->status) }}
                                            </span>
                                        @elseif($order->status == 'Delivering')
                                            <span class="badge-glass-info rounded-pill px-2 py-1" style="font-size: 0.7rem;">
                                                <i class="bi bi-truck me-1"></i>{{ order_status_text($order->status) }}
                                            </span>
                                        @elseif($order->status == 'Completed')
                                            <span class="badge-glass-success rounded-pill px-2 py-1" style="font-size: 0.7rem;">
                                                <i class="bi bi-check-circle me-1"></i>{{ order_status_text($order->status) }}
                                            </span>
                                        @else
                                            <span class="badge-glass-danger rounded-pill px-2 py-1" style="font-size: 0.7rem;">
                                                <i class="bi bi-x-circle me-1"></i>{{ order_status_text($order->status) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <div class="rounded-3 rounded-md-4 p-2 p-md-3 border border-emerald-100" style="background: rgba(16,185,129,0.04);">
                                    <small class="text-gray-500 d-block mb-1 mb-md-2" style="font-size: 0.7rem;"><i class="bi bi-geo-alt text-emerald-500 me-1"></i>{{ __('messages.profile.shipping_address') }}</small>
                                    <p class="fw-medium text-gray-800 mb-0 ms-3 ms-md-4" style="font-size: 0.85rem;">{{ $order->shipping_address }}</p>
                                </div>
                            </div>
                            
                            @if($order->shipping_phone)
                            <div class="col-12 col-sm-6">
                                <div class="rounded-3 rounded-md-4 p-2 p-md-3 h-100 border border-emerald-100" style="background: rgba(16,185,129,0.04);">
                                    <small class="text-gray-500 d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-telephone text-emerald-500 me-1"></i>{{ __('messages.profile.phone') }}</small>
                                    <p class="fw-bold text-gray-800 mb-0" style="font-size: 0.85rem;">{{ $order->shipping_phone }}</p>
                                </div>
                            </div>
                            @endif
                            
                            @if($order->delivery_time)
                            <div class="col-12 col-sm-6">
                                <div class="rounded-3 rounded-md-4 p-2 p-md-3 h-100 border border-emerald-100" style="background: rgba(16,185,129,0.04);">
                                    <small class="text-gray-500 d-block mb-1" style="font-size: 0.7rem;"><i class="bi bi-clock-history text-emerald-500 me-1"></i>{{ __('messages.profile.desired_time') }}</small>
                                    <p class="fw-bold text-emerald-700 mb-0" style="font-size: 0.85rem;">
                                        @switch($order->delivery_time)
                                            @case('morning') {{ __('messages.profile.morning') }} @break
                                            @case('afternoon') {{ __('messages.profile.afternoon') }} @break
                                            @case('evening') {{ __('messages.profile.evening') }} @break
                                            @default {{ __('messages.profile.anytime') }}
                                        @endswitch
                                    </p>
                                </div>
                            </div>
                            @endif
                            
                            @if($order->order_notes)
                            <div class="col-12">
                                <div class="rounded-3 rounded-md-4 p-2 p-md-3 border border-indigo-100" style="background: rgba(99,102,241,0.04);">
                                    <small class="text-gray-500 d-block mb-1 mb-md-2" style="font-size: 0.7rem;"><i class="bi bi-journal-text text-indigo-500 me-1"></i>{{ __('messages.profile.notes') }}</small>
                                    <p class="fw-medium text-gray-700 fst-italic mb-0 ms-3 ms-md-4" style="font-size: 0.85rem;">"{{ $order->order_notes }}"</p>
                                </div>
                            </div>
                            @endif
                        </div>

                        <!-- Product List -->
                        <h6 class="fw-bold mb-2 mb-md-3 mt-3 mt-md-4 text-gray-800 d-flex align-items-center" style="font-size: 0.95rem;">
                            <i class="bi bi-box-seam text-emerald-600 me-2"></i>{{ __('messages.profile.purchased_products') }}
                        </h6>
                        <div class="rounded-3 rounded-md-4 border overflow-hidden mb-3 mb-md-4 shadow-sm" style="border-color: rgba(16,185,129,0.15) !important;">
                            @foreach($order->items as $item)
                                <div class="d-flex align-items-center gap-2 gap-md-3 p-2 p-md-3 {{ !$loop->last ? 'border-bottom' : '' }}" style="border-color: rgba(16,185,129,0.1) !important; background: rgba(255,255,255,0.7);">
                                    <div class="bg-white rounded-2 rounded-md-3 overflow-hidden flex-shrink-0 border" style="width: 48px; height: 48px; border-color: rgba(0,0,0,0.05) !important;">
                                        @if($item->product && $item->product->image)
                                            <img src="{{ asset('storage/' . $item->product->image) }}" 
                                                 alt="{{ $item->product->name }}" 
                                                 class="w-100 h-100 object-fit-contain p-1">
                                        @else
                                            <div class="w-100 h-100 d-flex align-items-center justify-content-center">
                                                <i class="bi bi-capsule text-gray-300 fs-6"></i>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex-grow-1 min-w-0">
                                        <p class="fw-bold text-gray-800 mb-1 text-truncate" style="font-size: 0.85rem;">{{ $item->product ? $item->product->name : __('messages.profile.product_not_exist') }}</p>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="badge bg-gray-100 text-gray-600 rounded-pill fw-normal" style="font-size: 0.7rem;">{{ format_currency_vi($item->unit_price) }}</span>
                                            <span class="text-gray-400 small">×</span>
                                            <span class="fw-bold text-gray-700" style="font-size: 0.8rem;">{{ $item->quantity }}</span>
                                        </div>
                                    </div>
                                    <div class="text-end flex-shrink-0">
                                        <p class="fw-bold text-emerald-700 mb-0" style="font-size: 0.9rem;">{{ format_currency_vi($item->unit_price * $item->quantity) }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <!-- Total Block -->
                        <div class="rounded-3 rounded-md-4 p-3 p-md-4 d-flex justify-content-between align-items-center mt-2 shadow-sm" style="background: linear-gradient(135deg, rgba(16,185,129,0.08) 0%, rgba(5,150,105,0.15) 100%); border: 1px solid rgba(16,185,129,0.2);">
                            <span class="fw-bold text-emerald-900" style="font-size: 1rem;">{{ __('messages.profile.total_payment') }}</span>
                            <span class="fw-bold text-emerald-700" style="font-size: 1.25rem;">{{ format_currency_vi($order->total_amount) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endpush
    @empty
        <div class="text-center py-5">
            <div class="bg-gray-50 rounded-circle d-inline-flex align-items-center justify-content-center mb-3" 
                 style="width: 80px; height: 80px;">
                <i class="bi bi-bag-x text-gray-400 fs-1"></i>
            </div>
            <h6 class="fw-bold text-gray-700 mb-2">{{ __('messages.profile.no_orders') }}</h6>
            <p class="text-gray-500 mb-3">{{ __('messages.profile.no_orders_desc') }}</p>
            <a href="{{ route('products.index') }}" class="btn btn-glass-primary rounded-pill px-4">
                <i class="bi bi-cart-plus me-2"></i>{{ __('messages.profile.shop_now') }}
            </a>
        </div>
    @endforelse

    @if($orders->hasPages())
        <div class="mt-4">
            {{ $orders->links() }}
        </div>
    @endif
</div>
