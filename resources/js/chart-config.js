/**
 * Chart.js Configuration with Glassmorphism Styling
 * Vietnamese Localization for Pharmacy Management System
 */

import {
    Chart,
    LineController,
    BarController,
    PieController,
    DoughnutController,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler
} from 'chart.js';

// Register Chart.js components
Chart.register(
    LineController,
    BarController,
    PieController,
    DoughnutController,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

// Glassmorphism color palette
const colors = {
    primary: 'rgba(102, 126, 234, 0.8)',
    primaryLight: 'rgba(102, 126, 234, 0.2)',
    secondary: 'rgba(118, 75, 162, 0.8)',
    success: 'rgba(39, 174, 96, 0.8)',
    warning: 'rgba(243, 156, 18, 0.8)',
    danger: 'rgba(231, 76, 60, 0.8)',
    info: 'rgba(52, 152, 219, 0.8)',
    purple: 'rgba(155, 89, 182, 0.8)',
    teal: 'rgba(26, 188, 156, 0.8)',
    orange: 'rgba(230, 126, 34, 0.8)',
    pink: 'rgba(233, 30, 99, 0.8)',
};

// Default chart options with Glassmorphism styling
const defaultOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: true,
            position: 'bottom',
            labels: {
                color: '#2c3e50',
                font: {
                    size: 12,
                    family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                },
                padding: 15,
                usePointStyle: true,
            }
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#2c3e50',
            bodyColor: '#2c3e50',
            borderColor: 'rgba(255, 255, 255, 0.18)',
            borderWidth: 1,
            padding: 12,
            displayColors: true,
            callbacks: {
                label: function(context) {
                    let label = context.dataset.label || '';
                    if (label) {
                        label += ': ';
                    }
                    if (context.parsed.y !== null) {
                        label += new Intl.NumberFormat('vi-VN').format(context.parsed.y);
                    }
                    return label;
                }
            }
        }
    },
    animation: {
        duration: 1000,
        easing: 'easeInOutQuart'
    }
};

/**
 * Initialize Revenue Line Chart
 * @param {string} canvasId - Canvas element ID
 * @param {Array} labels - Date labels
 * @param {Array} data - Revenue data
 * @returns {Chart} - Chart instance
 */
export function initRevenueChart(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Doanh thu (₫)',
                data: data,
                borderColor: colors.primary,
                backgroundColor: colors.primaryLight,
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6,
                pointBackgroundColor: colors.primary,
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
            }]
        },
        options: {
            ...defaultOptions,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#7f8c8d',
                        callback: function(value) {
                            return new Intl.NumberFormat('vi-VN').format(value) + ' ₫';
                        }
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.2)',
                        drawBorder: false,
                    }
                },
                x: {
                    ticks: {
                        color: '#7f8c8d',
                    },
                    grid: {
                        display: false,
                    }
                }
            },
            plugins: {
                ...defaultOptions.plugins,
                title: {
                    display: true,
                    text: 'Doanh thu 30 ngày gần đây',
                    color: '#2c3e50',
                    font: {
                        size: 16,
                        weight: 'bold'
                    },
                    padding: 20
                }
            }
        }
    });
}

/**
 * Initialize Order Status Pie Chart
 * @param {string} canvasId - Canvas element ID
 * @param {Object} data - Order status data {Pending, Delivering, Completed, Cancelled}
 * @returns {Chart} - Chart instance
 */
export function initOrderStatusChart(canvasId, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const statusLabels = {
        'Pending': 'Chờ xử lý',
        'Delivering': 'Đang giao',
        'Completed': 'Hoàn thành',
        'Cancelled': 'Đã hủy'
    };

    const labels = Object.keys(data).map(key => statusLabels[key] || key);
    const values = Object.values(data);

    return new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: [
                    colors.warning,
                    colors.info,
                    colors.success,
                    colors.danger
                ],
                borderColor: '#fff',
                borderWidth: 2,
            }]
        },
        options: {
            ...defaultOptions,
            plugins: {
                ...defaultOptions.plugins,
                title: {
                    display: true,
                    text: 'Phân bố trạng thái đơn hàng',
                    color: '#2c3e50',
                    font: {
                        size: 16,
                        weight: 'bold'
                    },
                    padding: 20
                },
                tooltip: {
                    ...defaultOptions.plugins.tooltip,
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

/**
 * Initialize Top Products Bar Chart
 * @param {string} canvasId - Canvas element ID
 * @param {Array} products - Array of {name, quantity} objects
 * @returns {Chart} - Chart instance
 */
export function initTopProductsChart(canvasId, products) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const labels = products.map(p => p.name);
    const data = products.map(p => p.quantity);

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Số lượng bán',
                data: data,
                backgroundColor: colors.success,
                borderColor: colors.success,
                borderWidth: 1,
                borderRadius: 8,
            }]
        },
        options: {
            ...defaultOptions,
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        color: '#7f8c8d',
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.2)',
                        drawBorder: false,
                    }
                },
                y: {
                    ticks: {
                        color: '#7f8c8d',
                    },
                    grid: {
                        display: false,
                    }
                }
            },
            plugins: {
                ...defaultOptions.plugins,
                title: {
                    display: true,
                    text: 'Top 10 sản phẩm bán chạy',
                    color: '#2c3e50',
                    font: {
                        size: 16,
                        weight: 'bold'
                    },
                    padding: 20
                }
            }
        }
    });
}

/**
 * Initialize Monthly Revenue Bar Chart
 * @param {string} canvasId - Canvas element ID
 * @param {Array} labels - Month labels
 * @param {Array} data - Revenue data
 * @returns {Chart} - Chart instance
 */
export function initMonthlyRevenueChart(canvasId, labels, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Doanh thu (₫)',
                data: data,
                backgroundColor: colors.primary,
                borderColor: colors.primary,
                borderWidth: 1,
                borderRadius: 8,
            }]
        },
        options: {
            ...defaultOptions,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#7f8c8d',
                        callback: function(value) {
                            return new Intl.NumberFormat('vi-VN', {
                                notation: 'compact',
                                compactDisplay: 'short'
                            }).format(value) + ' ₫';
                        }
                    },
                    grid: {
                        color: 'rgba(255, 255, 255, 0.2)',
                        drawBorder: false,
                    }
                },
                x: {
                    ticks: {
                        color: '#7f8c8d',
                    },
                    grid: {
                        display: false,
                    }
                }
            },
            plugins: {
                ...defaultOptions.plugins,
                title: {
                    display: true,
                    text: 'Doanh thu theo tháng',
                    color: '#2c3e50',
                    font: {
                        size: 16,
                        weight: 'bold'
                    },
                    padding: 20
                }
            }
        }
    });
}

/**
 * Initialize Category Distribution Doughnut Chart
 * @param {string} canvasId - Canvas element ID
 * @param {Array} categories - Array of {name, count} objects
 * @returns {Chart} - Chart instance
 */
export function initCategoryChart(canvasId, categories) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const labels = categories.map(c => c.name);
    const data = categories.map(c => c.count);
    const backgroundColors = [
        colors.primary,
        colors.success,
        colors.warning,
        colors.danger,
        colors.info,
        colors.purple,
        colors.teal,
        colors.orange,
        colors.pink,
        colors.secondary
    ];

    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: backgroundColors.slice(0, data.length),
                borderColor: '#fff',
                borderWidth: 2,
            }]
        },
        options: {
            ...defaultOptions,
            plugins: {
                ...defaultOptions.plugins,
                title: {
                    display: true,
                    text: 'Phân bố sản phẩm theo danh mục',
                    color: '#2c3e50',
                    font: {
                        size: 16,
                        weight: 'bold'
                    },
                    padding: 20
                }
            }
        }
    });
}

/**
 * Destroy chart instance
 * @param {Chart} chart - Chart instance
 */
export function destroyChart(chart) {
    if (chart) {
        chart.destroy();
    }
}

/**
 * Update chart data
 * @param {Chart} chart - Chart instance
 * @param {Array} labels - New labels
 * @param {Array} data - New data
 */
export function updateChartData(chart, labels, data) {
    if (chart) {
        chart.data.labels = labels;
        chart.data.datasets[0].data = data;
        chart.update();
    }
}

export default Chart;
