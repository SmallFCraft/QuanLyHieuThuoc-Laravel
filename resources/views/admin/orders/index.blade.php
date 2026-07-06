@extends('layouts.admin')

@section('title', 'Quản lý đơn hàng')
@section('page-title', 'Quản lý đơn hàng')
@section('page-subtitle', 'Theo dõi trạng thái xử lý và giao hàng')

@section('content')
@php
    $statuses = [
        '' => ['label' => __('messages.admin.orders.all'), 'class' => 'primary'],
        'Pending' => ['label' => 'Chờ xử lý', 'class' => 'warning'],
        'Delivering' => ['label' => 'Đang giao', 'class' => 'info'],
        'Completed' => ['label' => 'Hoàn thành', 'class' => 'success'],
        'Cancelled' => ['label' => 'Đã hủy', 'class' => 'danger'],
    ];
@endphp

<div class="container-fluid px-0">
    <section class="admin-card">
        <div class="admin-card__header">
            <div>
                <h2 class="admin-card__title">Danh sách đơn hàng</h2>
                <p class="admin-card__description">Lọc nhanh theo trạng thái để xử lý đơn đang chờ.</p>
            </div>
        </div>

        <div class="admin-toolbar">
            <div class="d-flex flex-wrap gap-2" role="group" aria-label="{{ __('messages.admin.orders.filter_by_status') }}">
                @foreach($statuses as $value => $meta)
                    <a href="{{ $value === '' ? route('admin.orders.index') : route('admin.orders.index', ['status' => $value]) }}"
                       class="btn btn-outline-{{ $meta['class'] }} {{ (string) request('status') === (string) $value ? 'active' : '' }}">
                        {{ $meta['label'] }}
                    </a>
                @endforeach
            </div>
        </div>

        <div class="admin-table-wrap admin-desktop-table">
            <table id="ordersTable" class="table table-hover align-middle w-100">
                <thead>
                    <tr>
                        <th>{{ __('messages.admin.orders.order_id') }}</th>
                        <th>{{ __('messages.admin.orders.customer') }}</th>
                        <th>{{ __('messages.admin.orders.total_amount') }}</th>
                        <th>{{ __('messages.admin.orders.status') }}</th>
                        <th>{{ __('messages.admin.orders.order_date') }}</th>
                        <th>{{ __('messages.admin.orders.action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $order)
                        @php
                            $statusClass = match ($order->status) {
                                'Pending' => 'text-bg-warning',
                                'Delivering' => 'text-bg-info',
                                'Completed' => 'text-bg-success',
                                default => 'text-bg-danger',
                            };
                        @endphp
                        <tr>
                            <td><span class="fw-semibold">#{{ $order->MaDH }}</span></td>
                            <td>{{ $order->user->name }}</td>
                            <td class="fw-bold">{{ $order->formatted_total }}</td>
                            <td><span class="badge {{ $statusClass }}">{{ $statuses[$order->status]['label'] ?? $order->status }}</span></td>
                            <td>{{ $order->formatted_created_at }}</td>
                            <td>
                                <a href="{{ route('admin.orders.show', $order->MaDH) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye me-1" aria-hidden="true"></i>Xem
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="admin-mobile-list">
            @forelse($orders as $order)
                @php
                    $statusClass = match ($order->status) {
                        'Pending' => 'text-bg-warning',
                        'Delivering' => 'text-bg-info',
                        'Completed' => 'text-bg-success',
                        default => 'text-bg-danger',
                    };
                @endphp
                <article class="admin-mobile-item">
                    <div class="d-flex justify-content-between gap-3">
                        <div>
                            <strong>#{{ $order->MaDH }} · {{ $order->user->name }}</strong>
                            <div class="small text-secondary">{{ $order->formatted_created_at }}</div>
                            <div class="fw-bold mt-1">{{ $order->formatted_total }}</div>
                        </div>
                        <span class="badge {{ $statusClass }} align-self-start">{{ $statuses[$order->status]['label'] ?? $order->status }}</span>
                    </div>
                    <a href="{{ route('admin.orders.show', $order->MaDH) }}" class="btn btn-sm btn-outline-primary w-100 mt-3">Xem chi tiết</a>
                </article>
            @empty
                <div class="admin-empty-state">Không có đơn hàng phù hợp.</div>
            @endforelse
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('DOMContentLoaded', function() {
    $('#ordersTable').DataTable({
        language: window.AdminUI.dtLanguage,
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true,
        dom: '<"row align-items-center mb-3 g-2"<"col-md-4"l><"col-md-4"B><"col-md-4"f>>rt<"row align-items-center mt-3 g-2"<"col-md-6"i><"col-md-6"p>>',
        buttons: [
            window.AdminUI.excelButton({
                url: '{{ route('admin.orders.export') }}',
                params: (dt) => ({
                    status: @json(request('status')),
                    q: dt.search(),
                }),
            })
        ]
    });
});
</script>
@endpush
