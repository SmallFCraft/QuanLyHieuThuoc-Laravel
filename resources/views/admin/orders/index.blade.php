@extends('layouts.admin')

@section('title', 'Quản Lý Đơn Hàng')
@section('page-title', 'Quản Lý Đơn Hàng')

@section('content')
<div class="container-fluid">
    <div class="glass-card">
        <!-- Status Filter -->
        <div class="mb-4">
            <h6 class="mb-3">{{ __('messages.admin.orders.filter_by_status') }}</h6>
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
                    <tr>
                        <td>#{{ $order->MaDH }}</td>
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
                            <a href="{{ route('admin.orders.show', $order->MaDH) }}"
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
