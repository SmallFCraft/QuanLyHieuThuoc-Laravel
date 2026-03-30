/**
 * Add-to-cart via fetch: no full page reload; updates navbar badge and shows toast.
 */
function cartAddCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function cartAddFirstValidationMessage(errors) {
    if (!errors || typeof errors !== 'object') {
        return null;
    }
    for (const key of Object.keys(errors)) {
        const arr = errors[key];
        if (Array.isArray(arr) && arr.length > 0) {
            return arr[0];
        }
    }
    return null;
}

function cartAddUpdateBadge(count) {
    const badge = document.getElementById('cart-nav-badge');
    const safe = Math.max(0, Number.parseInt(String(count), 10) || 0);

    if (badge) {
        if (safe > 0) {
            badge.textContent = String(safe);
            badge.classList.remove('d-none');
            badge.setAttribute('aria-hidden', 'false');
        } else {
            badge.textContent = '';
            badge.classList.add('d-none');
            badge.setAttribute('aria-hidden', 'true');
        }
    }
}

/** Đồng bộ badge navbar giỏ hàng (thêm/xóa/sửa trên trang cart) */
window.cartNavbarSetCount = cartAddUpdateBadge;

function cartAddToast(icon, title, text) {
    if (typeof Swal === 'undefined' || !Swal.fire) {
        if (text) {
            window.alert(text);
        }
        return;
    }
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon,
        title,
        text,
        showConfirmButton: false,
        timer: icon === 'error' ? 4000 : 3000,
        timerProgressBar: true,
        background: '#ffffff',
        customClass: { popup: 'rounded-4 shadow-sm border border-emerald-100' },
    });
}

document.addEventListener('submit', async (event) => {
    const form = event.target.closest?.('.js-add-to-cart-form');
    if (!form || form.tagName !== 'FORM') {
        return;
    }

    event.preventDefault();

    const submitBtn = form.querySelector('[type="submit"]');
    const endpoint = form.getAttribute('action');
    if (!endpoint) {
        return;
    }

    if (submitBtn) {
        submitBtn.disabled = true;
    }

    try {
        const body = new FormData(form);
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': cartAddCsrfToken(),
            },
            body,
            credentials: 'same-origin',
        });

        const data = await response.json().catch(() => ({}));

        if (response.ok && data.success) {
            cartAddUpdateBadge(data.cart_count ?? 0);
            cartAddToast('success', 'Thành công!', data.message || 'Đã thêm vào giỏ hàng.');
        } else {
            const fromValidation = cartAddFirstValidationMessage(data.errors);
            const msg =
                data.message ||
                fromValidation ||
                (response.status === 419 ? 'Phiên làm việc hết hạn. Vui lòng tải lại trang.' : 'Không thể thêm vào giỏ hàng.');
            cartAddToast('error', 'Lỗi phát sinh', msg);
        }
    } catch {
        cartAddToast('error', 'Lỗi phát sinh', 'Không kết nối được máy chủ. Vui lòng thử lại.');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
        }
    }
});
