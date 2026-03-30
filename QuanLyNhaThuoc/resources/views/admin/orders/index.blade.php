@extends('layouts.admin')

@section('title', 'Quản Lý Đơn Hàng')
@section('page-title', 'Quản Lý Đơn Hàng')

@section('content')
<div class="container-fluid">
    <div class="glass-card">
        <!-- Status Filter -->
        <div class="mb-4">
            <h6 class="mb-3">Lọc theo trạng thái:</h6>
            <div class="btn-group" role="group">
                <a href="{{ route('admin.orders.index') }}" 
                   class="btn btn-sm {{ !request('status') ? 'btn-primary' : 'btn-outline-primary' }}">
                    Tất cả
                </a>
                <a href="{{ route('admin.orders.index', ['status' => 'Pending']) }}" 
                   class="btn btn-sm {{ request('status') == 'Pending' ? 'btn-warning' : 'btn-outline-warning' }}">
                    Pending
                </a>
                <a href="{{ route('admin.orders.index', ['status' => 'Delivering']) }}" 
                   class="btn btn-sm {{ request('status') == 'Delivering' ? 'btn-info' : 'btn-outline-info' }}">
                    Delivering
                </a>
                <a href="{{ route('admin.orders.index', ['status' => 'Completed']) }}" 
                   class="btn btn-sm {{ request('status') == 'Completed' ? 'btn-success' : 'btn-outline-success' }}">
                    Completed
                </a>
                <a href="{{ route('admin.orders.index', ['status' => 'Cancelled']) }}" 
                   class="btn btn-sm {{ request('status') == 'Cancelled' ? 'btn-danger' : 'btn-outline-danger' }}">
                    Cancelled
                </a>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="table-responsive">
            <table id="ordersTable" class="table table-hover">
                <thead>
                    <tr>
                        <th>Mã ĐH</th>
                        <th>Khách hàng</th>
                        <th>Tổng tiền</th>
                        <th>Trạng thái</th>
                        <th>Ngày đặt</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $order)
                    <tr>
                        <td>#{{ $order->id }}</td>
                        <td>{{ $order->user->name }}</td>
                        <td class="fw-bold">{{ $order->formatted_total }}</td>
                        <td>
                            <span class="badge bg-{{ 
                                $order->status == 'Pending' ? 'warning' : 
                                ($order->status == 'Delivering' ? 'info' : 
                                ($order->status == 'Completed' ? 'success' : 'danger')) 
                            }}">
                                {{ $order->status }}
                            </span>
                        </td>
                        <td>{{ $order->formatted_created_at }}</td>
                        <td>
                            <a href="{{ route('admin.orders.show', $order->id) }}" 
                               class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i> Xem
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#ordersTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/vi.json'
            },
            order: [[0, 'desc']],
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
    });
</script>
@endpush
