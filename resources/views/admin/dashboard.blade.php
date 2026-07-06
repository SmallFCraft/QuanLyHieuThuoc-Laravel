@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page-title', 'Bảng điều khiển')
@section('page-subtitle', 'Tổng quan hoạt động kinh doanh')

@section('content')
<div class="container-fluid px-0">
    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card revenue">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <p class="stat-label">{{ __('messages.admin.dashboard.monthly_revenue') }}</p>
                        <h2 class="stat-value">{{ number_format($monthlyRevenue, 0, ',', '.') }}₫</h2>
                    </div>
                    <div class="stat-icon">
                        <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card orders">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <p class="stat-label">{{ __('messages.admin.dashboard.pending_orders') }}</p>
                        <h2 class="stat-value">{{ $orderCounts['Pending'] ?? 0 }}</h2>
                    </div>
                    <div class="stat-icon" style="background:#fff7ed;color:#b45309;">
                        <i class="bi bi-clock-history" aria-hidden="true"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card expiring">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <p class="stat-label">{{ __('messages.admin.dashboard.expiring_soon') }}</p>
                        <h2 class="stat-value">{{ $expiringProductsCount }}</h2>
                    </div>
                    <div class="stat-icon" style="background:#fef2f2;color:#b91c1c;">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card lowstock">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <p class="stat-label">{{ __('messages.admin.dashboard.low_stock') }}</p>
                        <h2 class="stat-value">{{ $lowStockCount }}</h2>
                    </div>
                    <div class="stat-icon" style="background:#eff6ff;color:#1d4ed8;">
                        <i class="bi bi-box-seam" aria-hidden="true"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 g-xl-4 mb-4">
        <div class="col-xl-8">
            <section class="chart-card h-100" aria-labelledby="revenueChartTitle">
                <div class="admin-card__header">
                    <div>
                        <h2 class="admin-card__title" id="revenueChartTitle">
                            <i class="bi bi-bar-chart-line text-success me-2" aria-hidden="true"></i>{{ __('messages.admin.dashboard.revenue_30_days') }}
                        </h2>
                        <p class="admin-card__description" id="revenueSummary">Đang tải dữ liệu doanh thu.</p>
                    </div>
                </div>
                <div class="chart-shell">
                    <div class="chart-status" data-chart-status="revenue">Đang tải biểu đồ...</div>
                    <canvas id="revenueChart" height="110" aria-label="{{ __('messages.admin.dashboard.revenue_30_days') }}" role="img"></canvas>
                </div>
            </section>
        </div>

        <div class="col-xl-4">
            <section class="chart-card h-100" aria-labelledby="orderStatusChartTitle">
                <div class="admin-card__header">
                    <div>
                        <h2 class="admin-card__title" id="orderStatusChartTitle">
                            <i class="bi bi-pie-chart text-success me-2" aria-hidden="true"></i>{{ __('messages.admin.dashboard.order_distribution') }}
                        </h2>
                        <p class="admin-card__description" id="orderStatusSummary">Đang tải phân bố đơn hàng.</p>
                    </div>
                </div>
                <div class="chart-shell">
                    <div class="chart-status" data-chart-status="orderStatus">Đang tải biểu đồ...</div>
                    <canvas id="orderStatusChart" aria-label="{{ __('messages.admin.dashboard.order_distribution') }}" role="img"></canvas>
                </div>
            </section>
        </div>
    </div>

    <section class="chart-card" aria-labelledby="topProductsChartTitle">
        <div class="admin-card__header">
            <div>
                <h2 class="admin-card__title" id="topProductsChartTitle">
                    <i class="bi bi-trophy text-success me-2" aria-hidden="true"></i>{{ __('messages.admin.dashboard.top_10_products') }}
                </h2>
                <p class="admin-card__description" id="topProductsSummary">Đang tải sản phẩm bán chạy.</p>
            </div>
        </div>
        <div class="chart-shell">
            <div class="chart-status" data-chart-status="topProducts">Đang tải biểu đồ...</div>
            <canvas id="topProductsChart" height="90" aria-label="{{ __('messages.admin.dashboard.top_10_products') }}" role="img"></canvas>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script type="module">
document.addEventListener('DOMContentLoaded', function () {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    const hideStatus = (name) => {
        const status = document.querySelector(`[data-chart-status="${name}"]`);
        if (status) status.classList.add('d-none');
    };

    const showError = (name, message) => {
        const status = document.querySelector(`[data-chart-status="${name}"]`);
        if (status) {
            status.classList.remove('d-none');
            status.textContent = message;
        }
    };

    fetch('{{ route("admin.dashboard.revenue") }}')
        .then(response => {
            if (!response.ok) throw new Error('Revenue chart request failed');
            return response.json();
        })
        .then(data => {
            hideStatus('revenue');
            const total = (data.data || []).reduce((sum, value) => sum + Number(value || 0), 0);
            document.getElementById('revenueSummary').textContent = `Tổng doanh thu 30 ngày: ${total.toLocaleString('vi-VN')}₫.`;
            const ctx = document.getElementById('revenueChart').getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            gradient.addColorStop(0, 'rgba(15, 118, 110, 0.24)');
            gradient.addColorStop(1, 'rgba(15, 118, 110, 0.02)');

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Doanh thu',
                        data: data.data,
                        borderColor: '#0f766e',
                        backgroundColor: gradient,
                        tension: 0.36,
                        fill: true,
                        borderWidth: 2.5,
                        pointRadius: 0,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: context => `Doanh thu: ${context.parsed.y.toLocaleString('vi-VN')}₫`
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: value => value >= 1000000 ? `${(value / 1000000).toFixed(1)}M` : value.toLocaleString('vi-VN')
                            }
                        },
                        x: { ticks: { maxRotation: 0 } }
                    }
                }
            });
        })
        .catch(() => showError('revenue', 'Không thể tải dữ liệu doanh thu.'));

    fetch('{{ route("admin.dashboard.orderStatus") }}')
        .then(response => {
            if (!response.ok) throw new Error('Order status chart request failed');
            return response.json();
        })
        .then(data => {
            hideStatus('orderStatus');
            const total = (data.data || []).reduce((sum, value) => sum + Number(value || 0), 0);
            document.getElementById('orderStatusSummary').textContent = `Tổng đơn trong biểu đồ: ${total.toLocaleString('vi-VN')}.`;
            new Chart(document.getElementById('orderStatusChart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: data.labels,
                    datasets: [{
                        data: data.data,
                        backgroundColor: ['#d97706', '#2563eb', '#0f766e', '#b91c1c'],
                        borderColor: '#fff',
                        borderWidth: 3,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '64%',
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: context => {
                                    const value = Number(context.parsed || 0);
                                    const percent = total ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${context.label}: ${value} (${percent}%)`;
                                }
                            }
                        }
                    }
                }
            });
        })
        .catch(() => showError('orderStatus', 'Không thể tải phân bố đơn hàng.'));

    fetch('{{ route("admin.dashboard.topProducts") }}')
        .then(response => {
            if (!response.ok) throw new Error('Top products chart request failed');
            return response.json();
        })
        .then(data => {
            hideStatus('topProducts');
            const topName = data.labels?.[0] || 'Chưa có dữ liệu';
            const topValue = data.data?.[0] || 0;
            document.getElementById('topProductsSummary').textContent = `Bán chạy nhất: ${topName} (${topValue} sản phẩm).`;
            new Chart(document.getElementById('topProductsChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: '{{ __('messages.admin.dashboard.sales_quantity') }}',
                        data: data.data,
                        backgroundColor: 'rgba(15, 118, 110, 0.72)',
                        borderColor: '#0f766e',
                        borderWidth: 1,
                        borderRadius: 6,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { ticks: { maxRotation: 45 } }
                    }
                }
            });
        })
        .catch(() => showError('topProducts', 'Không thể tải sản phẩm bán chạy.'));
});
</script>
@endpush
