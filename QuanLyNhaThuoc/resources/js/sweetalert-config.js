/**
 * SweetAlert2 Configuration with Glassmorphism Styling
 * Vietnamese Localization for Pharmacy Management System
 */

import Swal from 'sweetalert2';

// Custom Glassmorphism theme for SweetAlert2
const glassTheme = {
    popup: 'glass-card',
    backdrop: 'backdrop-blur-sm',
    confirmButton: 'btn-glass-primary',
    cancelButton: 'btn-glass',
    denyButton: 'btn-glass-danger',
};

/**
 * Show confirmation dialog for delete operations
 * @param {string} title - Dialog title
 * @param {string} text - Dialog message
 * @param {string} confirmButtonText - Confirm button text (default: "Xóa")
 * @returns {Promise} - Resolves with result
 */
export function confirmDelete(title = 'Bạn có chắc chắn?', text = 'Bạn sẽ không thể hoàn tác hành động này!', confirmButtonText = 'Xóa') {
    return Swal.fire({
        title: title,
        text: text,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: confirmButtonText,
        cancelButtonText: 'Hủy',
        customClass: glassTheme,
        buttonsStyling: false,
        reverseButtons: true,
        focusCancel: true,
    });
}

/**
 * Show success message
 * @param {string} title - Success title
 * @param {string} text - Success message
 * @param {number} timer - Auto-close timer in ms (default: 2000)
 */
export function showSuccess(title = 'Thành công!', text = '', timer = 2000) {
    return Swal.fire({
        title: title,
        text: text,
        icon: 'success',
        timer: timer,
        showConfirmButton: false,
        customClass: glassTheme,
        toast: true,
        position: 'top-end',
    });
}

/**
 * Show error message
 * @param {string} title - Error title
 * @param {string} text - Error message
 */
export function showError(title = 'Lỗi!', text = 'Đã xảy ra lỗi. Vui lòng thử lại.') {
    return Swal.fire({
        title: title,
        text: text,
        icon: 'error',
        confirmButtonText: 'Đóng',
        customClass: glassTheme,
        buttonsStyling: false,
    });
}

/**
 * Show warning message
 * @param {string} title - Warning title
 * @param {string} text - Warning message
 */
export function showWarning(title = 'Cảnh báo!', text = '') {
    return Swal.fire({
        title: title,
        text: text,
        icon: 'warning',
        confirmButtonText: 'Đã hiểu',
        customClass: glassTheme,
        buttonsStyling: false,
    });
}

/**
 * Show info message
 * @param {string} title - Info title
 * @param {string} text - Info message
 */
export function showInfo(title = 'Thông tin', text = '') {
    return Swal.fire({
        title: title,
        text: text,
        icon: 'info',
        confirmButtonText: 'Đóng',
        customClass: glassTheme,
        buttonsStyling: false,
    });
}

/**
 * Show loading indicator
 * @param {string} title - Loading title
 */
export function showLoading(title = 'Đang xử lý...') {
    return Swal.fire({
        title: title,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        customClass: glassTheme,
        didOpen: () => {
            Swal.showLoading();
        },
    });
}

/**
 * Close any open SweetAlert2 dialog
 */
export function closeAlert() {
    Swal.close();
}

/**
 * Confirm order cancellation
 * @param {string} orderNumber - Order number
 * @returns {Promise}
 */
export function confirmOrderCancellation(orderNumber) {
    return Swal.fire({
        title: 'Hủy đơn hàng?',
        text: `Bạn có chắc chắn muốn hủy đơn hàng ${orderNumber}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Hủy đơn hàng',
        cancelButtonText: 'Không',
        customClass: glassTheme,
        buttonsStyling: false,
        reverseButtons: true,
    });
}

/**
 * Confirm inventory adjustment
 * @param {string} productName - Product name
 * @param {number} currentQty - Current quantity
 * @param {number} newQty - New quantity
 * @returns {Promise}
 */
export function confirmInventoryAdjustment(productName, currentQty, newQty) {
    return Swal.fire({
        title: 'Xác nhận điều chỉnh tồn kho',
        html: `
            <p><strong>Sản phẩm:</strong> ${productName}</p>
            <p><strong>Số lượng hiện tại:</strong> ${currentQty}</p>
            <p><strong>Số lượng mới:</strong> ${newQty}</p>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Xác nhận',
        cancelButtonText: 'Hủy',
        customClass: glassTheme,
        buttonsStyling: false,
    });
}

/**
 * Show checkout success with order details
 * @param {string} orderNumber - Order number
 * @param {string} totalAmount - Total amount formatted
 */
export function showCheckoutSuccess(orderNumber, totalAmount) {
    return Swal.fire({
        title: 'Đặt hàng thành công!',
        html: `
            <p>Mã đơn hàng: <strong>${orderNumber}</strong></p>
            <p>Tổng tiền: <strong>${totalAmount}</strong></p>
            <p>Chúng tôi sẽ liên hệ với bạn sớm nhất.</p>
        `,
        icon: 'success',
        confirmButtonText: 'Đóng',
        customClass: glassTheme,
        buttonsStyling: false,
    });
}

/**
 * Show product added to cart notification
 * @param {string} productName - Product name
 */
export function showAddedToCart(productName) {
    return Swal.fire({
        title: 'Đã thêm vào giỏ hàng!',
        text: productName,
        icon: 'success',
        timer: 1500,
        showConfirmButton: false,
        customClass: glassTheme,
        toast: true,
        position: 'top-end',
    });
}

/**
 * Show expired product warning
 * @param {string} productName - Product name
 * @param {string} expirationDate - Expiration date
 */
export function showExpiredProductWarning(productName, expirationDate) {
    return Swal.fire({
        title: 'Sản phẩm đã hết hạn!',
        html: `
            <p><strong>${productName}</strong></p>
            <p>Hạn sử dụng: ${expirationDate}</p>
            <p>Không thể thêm vào giỏ hàng.</p>
        `,
        icon: 'error',
        confirmButtonText: 'Đóng',
        customClass: glassTheme,
        buttonsStyling: false,
    });
}

/**
 * Show insufficient stock warning
 * @param {string} productName - Product name
 * @param {number} available - Available quantity
 * @param {number} requested - Requested quantity
 */
export function showInsufficientStock(productName, available, requested) {
    return Swal.fire({
        title: 'Không đủ hàng!',
        html: `
            <p><strong>${productName}</strong></p>
            <p>Số lượng có sẵn: ${available}</p>
            <p>Số lượng yêu cầu: ${requested}</p>
        `,
        icon: 'warning',
        confirmButtonText: 'Đóng',
        customClass: glassTheme,
        buttonsStyling: false,
    });
}

// Export default Swal instance for custom usage
export default Swal;
