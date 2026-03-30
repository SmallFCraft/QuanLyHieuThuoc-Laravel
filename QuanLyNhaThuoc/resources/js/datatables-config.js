/**
 * DataTables Configuration with Vietnamese Localization
 * Glassmorphism Styling for Pharmacy Management System
 */

import $ from 'jquery';
import 'datatables.net-bs5';
import 'datatables.net-buttons-bs5';
import 'datatables.net-responsive-bs5';

// Vietnamese language configuration
const vietnameseLanguage = {
    "sProcessing": "Đang xử lý...",
    "sLengthMenu": "Hiển thị _MENU_ mục",
    "sZeroRecords": "Không tìm thấy dữ liệu",
    "sInfo": "Hiển thị _START_ đến _END_ trong tổng số _TOTAL_ mục",
    "sInfoEmpty": "Hiển thị 0 đến 0 trong tổng số 0 mục",
    "sInfoFiltered": "(lọc từ _MAX_ mục)",
    "sSearch": "Tìm kiếm:",
    "sUrl": "",
    "oPaginate": {
        "sFirst": "Đầu",
        "sPrevious": "Trước",
        "sNext": "Sau",
        "sLast": "Cuối"
    },
    "oAria": {
        "sSortAscending": ": Sắp xếp tăng dần",
        "sSortDescending": ": Sắp xếp giảm dần"
    }
};

// Default DataTable configuration
const defaultConfig = {
    language: vietnameseLanguage,
    responsive: true,
    pageLength: 10,
    lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
    dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
         '<"row"<"col-sm-12"tr>>' +
         '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
    order: [[0, 'desc']],
    columnDefs: [
        {
            targets: 'no-sort',
            orderable: false
        }
    ]
};

/**
 * Initialize Product DataTable
 * @param {string} selector - Table selector
 * @param {object} options - Additional options
 * @returns {DataTable} - DataTable instance
 */
export function initProductTable(selector = '#productsTable', options = {}) {
    return $(selector).DataTable({
        ...defaultConfig,
        ...options,
        columns: [
            { data: 'id', title: 'ID' },
            { data: 'name', title: 'Tên sản phẩm' },
            { data: 'category', title: 'Danh mục' },
            { data: 'price', title: 'Giá (₫)' },
            { data: 'stock_quantity', title: 'Tồn kho' },
            { data: 'expiration_date', title: 'Hạn sử dụng' },
            { data: 'batch_number', title: 'Số lô' },
            { 
                data: 'actions', 
                title: 'Thao tác',
                className: 'no-sort text-center',
                orderable: false
            }
        ],
        createdRow: function(row, data) {
            // Highlight expired products
            if (data.is_expired) {
                $(row).addClass('table-danger');
            }
            // Highlight expiring soon products
            else if (data.is_expiring_soon) {
                $(row).addClass('table-warning');
            }
            // Highlight low stock products
            if (data.is_low_stock) {
                $(row).find('td:eq(4)').addClass('text-danger fw-bold');
            }
        }
    });
}

/**
 * Initialize Order DataTable
 * @param {string} selector - Table selector
 * @param {object} options - Additional options
 * @returns {DataTable} - DataTable instance
 */
export function initOrderTable(selector = '#ordersTable', options = {}) {
    return $(selector).DataTable({
        ...defaultConfig,
        ...options,
        columns: [
            { data: 'id', title: 'Mã ĐH' },
            { data: 'user_name', title: 'Khách hàng' },
            { data: 'total_amount', title: 'Tổng tiền (₫)' },
            { data: 'status', title: 'Trạng thái' },
            { data: 'created_at', title: 'Ngày đặt' },
            { 
                data: 'actions', 
                title: 'Thao tác',
                className: 'no-sort text-center',
                orderable: false
            }
        ],
        createdRow: function(row, data) {
            // Add status badge styling
            const statusCell = $(row).find('td:eq(3)');
            let badgeClass = 'badge-glass';
            
            switch(data.status) {
                case 'Pending':
                    badgeClass += ' badge-glass-warning';
                    break;
                case 'Delivering':
                    badgeClass += ' badge-glass-info';
                    break;
                case 'Completed':
                    badgeClass += ' badge-glass-success';
                    break;
                case 'Cancelled':
                    badgeClass += ' badge-glass-danger';
                    break;
            }
            
            statusCell.html(`<span class="${badgeClass}">${data.status_text}</span>`);
        }
    });
}

/**
 * Initialize User DataTable
 * @param {string} selector - Table selector
 * @param {object} options - Additional options
 * @returns {DataTable} - DataTable instance
 */
export function initUserTable(selector = '#usersTable', options = {}) {
    return $(selector).DataTable({
        ...defaultConfig,
        ...options,
        columns: [
            { data: 'id', title: 'ID' },
            { data: 'name', title: 'Họ tên' },
            { data: 'email', title: 'Email' },
            { data: 'role', title: 'Vai trò' },
            { data: 'status', title: 'Trạng thái' },
            { data: 'created_at', title: 'Ngày tạo' },
            { 
                data: 'actions', 
                title: 'Thao tác',
                className: 'no-sort text-center',
                orderable: false
            }
        ],
        createdRow: function(row, data) {
            // Add role badge
            const roleCell = $(row).find('td:eq(3)');
            const roleClass = data.role === 'Administrator' ? 'badge-glass-primary' : 'badge-glass';
            roleCell.html(`<span class="badge-glass ${roleClass}">${data.role_text}</span>`);
            
            // Add status badge
            const statusCell = $(row).find('td:eq(4)');
            const statusClass = data.status === 'active' ? 'badge-glass-success' : 'badge-glass-danger';
            statusCell.html(`<span class="badge-glass ${statusClass}">${data.status_text}</span>`);
        }
    });
}

/**
 * Initialize Inventory DataTable
 * @param {string} selector - Table selector
 * @param {object} options - Additional options
 * @returns {DataTable} - DataTable instance
 */
export function initInventoryTable(selector = '#inventoryTable', options = {}) {
    return $(selector).DataTable({
        ...defaultConfig,
        ...options,
        order: [[5, 'asc']], // Sort by expiration date (FEFO)
        columns: [
            { data: 'id', title: 'ID' },
            { data: 'name', title: 'Tên sản phẩm' },
            { data: 'batch_number', title: 'Số lô' },
            { data: 'stock_quantity', title: 'Tồn kho' },
            { data: 'reorder_point', title: 'Điểm đặt lại' },
            { data: 'expiration_date', title: 'Hạn sử dụng' },
            { 
                data: 'actions', 
                title: 'Thao tác',
                className: 'no-sort text-center',
                orderable: false
            }
        ],
        createdRow: function(row, data) {
            // Highlight expired products (red)
            if (data.is_expired) {
                $(row).addClass('table-danger');
            }
            // Highlight expiring soon products (yellow)
            else if (data.is_expiring_soon) {
                $(row).addClass('table-warning');
            }
            
            // Highlight low stock (red text)
            if (data.is_low_stock) {
                $(row).find('td:eq(3)').addClass('text-danger fw-bold');
            }
        }
    });
}

/**
 * Initialize Category DataTable
 * @param {string} selector - Table selector
 * @param {object} options - Additional options
 * @returns {DataTable} - DataTable instance
 */
export function initCategoryTable(selector = '#categoriesTable', options = {}) {
    return $(selector).DataTable({
        ...defaultConfig,
        ...options,
        columns: [
            { data: 'id', title: 'ID' },
            { data: 'name', title: 'Tên danh mục' },
            { data: 'product_count', title: 'Số sản phẩm' },
            { data: 'created_at', title: 'Ngày tạo' },
            { 
                data: 'actions', 
                title: 'Thao tác',
                className: 'no-sort text-center',
                orderable: false
            }
        ]
    });
}

/**
 * Initialize Contact Inquiry DataTable
 * @param {string} selector - Table selector
 * @param {object} options - Additional options
 * @returns {DataTable} - DataTable instance
 */
export function initContactTable(selector = '#contactsTable', options = {}) {
    return $(selector).DataTable({
        ...defaultConfig,
        ...options,
        columns: [
            { data: 'id', title: 'ID' },
            { data: 'name', title: 'Họ tên' },
            { data: 'email', title: 'Email' },
            { data: 'phone', title: 'Điện thoại' },
            { data: 'status', title: 'Trạng thái' },
            { data: 'created_at', title: 'Ngày gửi' },
            { 
                data: 'actions', 
                title: 'Thao tác',
                className: 'no-sort text-center',
                orderable: false
            }
        ],
        createdRow: function(row, data) {
            // Add status badge
            const statusCell = $(row).find('td:eq(4)');
            let badgeClass = 'badge-glass';
            
            switch(data.status) {
                case 'new':
                    badgeClass += ' badge-glass-warning';
                    break;
                case 'read':
                    badgeClass += ' badge-glass-info';
                    break;
                case 'responded':
                    badgeClass += ' badge-glass-success';
                    break;
            }
            
            statusCell.html(`<span class="${badgeClass}">${data.status_text}</span>`);
        }
    });
}

/**
 * Reload DataTable data
 * @param {DataTable} table - DataTable instance
 */
export function reloadTable(table) {
    if (table) {
        table.ajax.reload(null, false);
    }
}

/**
 * Destroy DataTable instance
 * @param {DataTable} table - DataTable instance
 */
export function destroyTable(table) {
    if (table) {
        table.destroy();
    }
}

// Export jQuery for use in other modules
export { $ };
