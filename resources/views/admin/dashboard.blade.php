@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page-title', 'Bảng Điều Khiển')
@section('page-subtitle', 'Tổng quan hoạt động kinh doanh')

@section('content')
<div class="container-fluid px-0">
    <!-- Stat Cards Row -->
    <div class="row g-4 mb-4">
        <!-- Monthly Revenue -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card revenue">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="stat-label mb-1">{{ __('messages.admin.dashboard.monthly_revenue') }}</p>
                        <h3 class="stat-value mb-0">{{ number_format($monthlyRevenue, 0, ',', '.') }}₫</h3>
                    </div>
                    <div class="stat-icon" style="background: #ecfdf5; color: #059669;">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Orders -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card orders">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="stat-label mb-1">{{ __('messages.admin.dashboard.pending_orders') }}</p>
                        <h3 class="stat-value mb-0">{{ $orderCounts['Pending'] }}</h3>
                    </div>
                    <div class="stat-icon" style="background: #fffbeb; color: #d97706;">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Expiring Products -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card expiring">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="stat-label mb-1">{{ __('messages.admin.dashboard.expiring_soon') }}</p>
                        <h3 class="stat-value mb-0">{{ $expiringProductsCount }}</h3>
                    </div>
                    <div class="stat-icon" style="background: #fef2f2; color: #dc2626;">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card lowstock">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="stat-label mb-1">{{ __('messages.admin.dashboard.low_stock') }}</p>
                        <h3 class="stat-value mb-0">{{ $lowStockCount }}</h3>
                    </div>
                    <div class="stat-icon" style="background: #eff6ff; color: #2563eb;">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <!-- Daily Revenue Chart -->
        <div class="col-xl-8">
            <div class="chart-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0"><i class="bi bi-bar-chart-line text-emerald-600 me-2"></i>{{ __('messages.admin.dashboard.revenue_30_days') }}</h5>
                </div>
                <canvas id="revenueChart" height="85"></canvas>
            </div>
        </div>

        <!-- Order Status Chart -->
        <div class="col-xl-4">
            <div class="chart-card h-100">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0"><i class="bi bi-pie-chart text-emerald-600 me-2"></i>{{ __('messages.admin.dashboard.order_distribution') }}</h5>
                </div>
                <canvas id="orderStatusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Top Products Chart -->
    <div class="row g-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0"><i class="bi bi-trophy text-emerald-600 me-2"></i>{{ __('messages.admin.dashboard.top_10_products') }}</h5>
                </div>
                <canvas id="topProductsChart" height="65"></canvas>
            </div>
        </div>
    </div>
</div>
@endsection


@push('scripts')
<script>
    // Green-themed Chart Defaults
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.pointStyleWidth = 12;

    // Revenue Chart
    fetch('{{ route("admin.dashboard.revenue") }}')
        .then(response => response.json())
        .then(data => {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(16, 185, 129, 0.25)');
            gradient.addColorStop(1, 'rgba(16, 185, 129, 0.01)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Doanh Thu (₫)',
                        data: data.data,
                        borderColor: '#10b981',
                        backgroundColor: gradient,
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2.5,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 0,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 12,
                            cornerRadius: 10,
                            titleFont: { weight: '600' },
                            callbacks: {
                                label: function(context) {
                                    return 'Doanh thu: ' + context.parsed.y.toLocaleString('vi-VN') + ' ₫';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f1f5f9', drawBorder: false },
                            border: { display: false },
                            ticks: {
                                color: '#94a3b8',
                                font: { size: 11 },
                                callback: function(value) {
                                    if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                                    if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                                    return value;
                                }
                            }
                        },
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: { color: '#94a3b8', maxRotation: 0, font: { size: 11 } }
                        }
                    }
                }
            });
        });


    // Order Status Chart
    fetch('{{ route("admin.dashboard.orderStatus") }}')
        .then(response => response.json())
        .then(data => {
            const ctx = document.getElementById('orderStatusChart').getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: data.labels,
                    datasets: [{
                        data: data.data,
                        backgroundColor: [
                            '#fbbf24',   // Pending - Amber
                            '#3b82f6',   // Delivering - Blue
                            '#10b981',   // Completed - Green
                            '#ef4444'    // Cancelled - Red
                        ],
                        borderColor: '#fff',
                        borderWidth: 3,
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 16,
                                color: '#64748b',
                                font: { size: 12 }
                            }
                        }
                    }
                }
            });
        });


    // Top Products Chart
    fetch('{{ route("admin.dashboard.topProducts") }}')
        .then(response => response.json())
        .then(data => {
            const ctx = document.getElementById('topProductsChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: '{{ __('messages.admin.dashboard.sales_quantity') }}',
                        data: data.data,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: '#10b981',
                        borderWidth: 1,
                        borderRadius: 8,
                        borderSkipped: false,
                        barThickness: 32
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            padding: 12,
                            cornerRadius: 10,
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f1f5f9', drawBorder: false },
                            border: { display: false },
                            ticks: { stepSize: 1, color: '#94a3b8', font: { size: 11 } }
                        },
                        x: {
                            grid: { display: false },
                            border: { display: false },
                            ticks: { color: '#64748b', maxRotation: 45, font: { size: 11 } }
                        }
                    }
                }
            });
        });
</script>
@endpush
