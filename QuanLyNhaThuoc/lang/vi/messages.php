<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Messages Language Lines
    |--------------------------------------------------------------------------
    */

    // Navigation
    'home' => 'Trang chủ',
    'products' => 'Sản phẩm',
    'cart' => 'Giỏ hàng',
    'contact' => 'Liên hệ',
    'dashboard' => 'Bảng điều khiển',
    'orders' => 'Đơn hàng',
    'inventory' => 'Tồn kho',
    'categories' => 'Danh mục',
    'users' => 'Người dùng',
    'reports' => 'Báo cáo',
    
    // Actions
    'add' => 'Thêm',
    'edit' => 'Sửa',
    'delete' => 'Xóa',
    'save' => 'Lưu',
    'cancel' => 'Hủy',
    'search' => 'Tìm kiếm',
    'filter' => 'Lọc',
    'export' => 'Xuất',
    'import' => 'Nhập',
    'view' => 'Xem',
    'update' => 'Cập nhật',
    'create' => 'Tạo mới',
    'back' => 'Quay lại',
    'submit' => 'Gửi',
    'confirm' => 'Xác nhận',
    'close' => 'Đóng',
    
    // Product
    'product_name' => 'Tên sản phẩm',
    'product_price' => 'Giá',
    'product_stock' => 'Tồn kho',
    'product_expiration' => 'Hạn sử dụng',
    'product_batch' => 'Số lô',
    'product_category' => 'Danh mục',
    'product_ingredients' => 'Thành phần',
    'product_usage' => 'Cách sử dụng',
    'product_image' => 'Hình ảnh',
    'add_to_cart' => 'Thêm vào giỏ',
    'buy_now' => 'Mua ngay',
    'out_of_stock' => 'Hết hàng',
    'in_stock' => 'Còn hàng',
    'low_stock' => 'Sắp hết',
    'expired' => 'Đã hết hạn',
    'expiring_soon' => 'Sắp hết hạn',
    
    // Order
    'order_number' => 'Mã đơn hàng',
    'order_date' => 'Ngày đặt',
    'order_status' => 'Trạng thái',
    'order_total' => 'Tổng tiền',
    'order_items' => 'Sản phẩm',
    'order_pending' => 'Chờ xử lý',
    'order_delivering' => 'Đang giao',
    'order_completed' => 'Hoàn thành',
    'order_cancelled' => 'Đã hủy',
    'shipping_address' => 'Địa chỉ giao hàng',
    'shipping_phone' => 'Số điện thoại',
    
    // Cart
    'cart_empty' => 'Giỏ hàng trống',
    'cart_total' => 'Tổng cộng',
    'cart_subtotal' => 'Tạm tính',
    'cart_quantity' => 'Số lượng',
    'cart_remove' => 'Xóa',
    'cart_update' => 'Cập nhật',
    'proceed_to_checkout' => 'Thanh toán',
    'continue_shopping' => 'Tiếp tục mua sắm',
    
    // Dashboard
    'total_revenue' => 'Tổng doanh thu',
    'total_orders' => 'Tổng đơn hàng',
    'expiring_products' => 'Sản phẩm sắp hết hạn',
    'low_stock_products' => 'Sản phẩm sắp hết',
    'revenue_chart' => 'Biểu đồ doanh thu',
    'order_status_chart' => 'Phân bố đơn hàng',
    'top_products_chart' => 'Sản phẩm bán chạy',
    
    // User
    'user_name' => 'Họ tên',
    'user_email' => 'Email',
    'user_role' => 'Vai trò',
    'user_status' => 'Trạng thái',
    'user_active' => 'Hoạt động',
    'user_inactive' => 'Không hoạt động',
    'role_administrator' => 'Quản trị viên',
    'role_user' => 'Người dùng',
    
    // Success Messages
    'success_created' => 'Tạo mới thành công!',
    'success_updated' => 'Cập nhật thành công!',
    'success_deleted' => 'Xóa thành công!',
    'success_added_to_cart' => 'Đã thêm vào giỏ hàng!',
    'success_order_placed' => 'Đặt hàng thành công!',
    'success_order_cancelled' => 'Hủy đơn hàng thành công!',
    'success_inventory_adjusted' => 'Điều chỉnh tồn kho thành công!',
    
    // Error Messages
    'error_general' => 'Đã xảy ra lỗi. Vui lòng thử lại.',
    'error_not_found' => 'Không tìm thấy dữ liệu.',
    'error_unauthorized' => 'Bạn không có quyền truy cập.',
    'error_validation' => 'Dữ liệu không hợp lệ.',
    'error_expired_product' => 'Sản phẩm đã hết hạn.',
    'error_insufficient_stock' => 'Không đủ hàng trong kho.',
    'error_invalid_status_transition' => 'Không thể chuyển trạng thái này.',
    
    // Confirmation Messages
    'confirm_delete' => 'Bạn có chắc chắn muốn xóa?',
    'confirm_cancel_order' => 'Bạn có chắc chắn muốn hủy đơn hàng?',
    'confirm_logout' => 'Bạn có chắc chắn muốn đăng xuất?',
    
    // Contact
    'contact_name' => 'Họ tên',
    'contact_email' => 'Email',
    'contact_phone' => 'Số điện thoại',
    'contact_message' => 'Tin nhắn',
    'contact_submit' => 'Gửi tin nhắn',
    'contact_success' => 'Cảm ơn bạn đã liên hệ. Chúng tôi sẽ phản hồi sớm nhất.',
    
    // Inventory
    'inventory_adjustment' => 'Điều chỉnh tồn kho',
    'inventory_reason' => 'Lý do',
    'inventory_current_qty' => 'Số lượng hiện tại',
    'inventory_new_qty' => 'Số lượng mới',
    'inventory_adjust' => 'Điều chỉnh',
    
    // Pagination
    'showing' => 'Hiển thị',
    'to' => 'đến',
    'of' => 'trong tổng số',
    'entries' => 'mục',
    
    // Date & Time
    'date_format' => 'd/m/Y',
    'datetime_format' => 'd/m/Y H:i',
    'today' => 'Hôm nay',
    'yesterday' => 'Hôm qua',
    'this_week' => 'Tuần này',
    'this_month' => 'Tháng này',
    'this_year' => 'Năm nay',
    
    // Currency
    'currency_symbol' => '₫',
    'currency_format' => ':amount ₫',
    
    // Misc
    'loading' => 'Đang tải...',
    'no_data' => 'Không có dữ liệu',
    'select_option' => 'Chọn...',
    'all' => 'Tất cả',
    'none' => 'Không có',
    'yes' => 'Có',
    'no' => 'Không',
    'optional' => 'Tùy chọn',
    'required' => 'Bắt buộc',
];
