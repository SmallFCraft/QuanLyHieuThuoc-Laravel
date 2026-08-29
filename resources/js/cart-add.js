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

/**
 * Copy and colours come from the server (window.PharmacyDrugSafety and the
 * .drug-warning* classes shipped by <x-drug-warnings>), so this dialog cannot
 * drift from the on-page warning block when a label or palette changes.
 */
const CART_ADD_SEVERITY_ICONS = {
    major: 'bi-exclamation-octagon-fill',
    moderate: 'bi-exclamation-triangle-fill',
    minor: 'bi-info-circle-fill',
};

function cartAddSafetyText(key, fallback) {
    return window.PharmacyDrugSafety?.[key] ?? fallback;
}

function cartAddSeverityLabel(severity) {
    return window.PharmacyDrugSafety?.severityLabels?.[severity]
        ?? window.PharmacyDrugSafety?.severityLabels?.minor
        ?? 'Thông tin';
}

function cartAddEscape(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
}

function cartAddWarningsHtml(warnings) {
    const actionLabel = cartAddEscape(cartAddSafetyText('actionLabel', 'Nên làm'));

    return (Array.isArray(warnings) ? warnings : [])
        .map((warning) => {
            const severity = CART_ADD_SEVERITY_ICONS[warning?.severity] ? warning.severity : 'minor';
            const action = warning?.action
                ? `<p class="drug-warning__action" style="margin:6px 0 0;"><strong>${actionLabel}:</strong> ${cartAddEscape(warning.action)}</p>`
                : '';

            return `
                <div class="drug-warning drug-warning--${severity}" style="text-align:left;margin-bottom:8px;">
                    <span class="drug-warning__badge">
                        <i class="bi ${CART_ADD_SEVERITY_ICONS[severity]}" aria-hidden="true"></i>${cartAddEscape(cartAddSeverityLabel(severity))}
                    </span>
                    <p class="drug-warning__title" style="margin:6px 0 4px;">${cartAddEscape(warning?.title)}</p>
                    <p class="drug-warning__message" style="margin:0;">${cartAddEscape(warning?.message)}</p>
                    ${action}
                </div>`;
        })
        .join('');
}

/**
 * Blocking warnings need an explicit acknowledgement before the item is added,
 * so the retry is a second deliberate request carrying confirm_warning=1.
 */
async function cartAddConfirmWarnings(warnings) {
    if (typeof Swal === 'undefined' || !Swal.fire) {
        return window.confirm('Sản phẩm này có cảnh báo an toàn thuốc. Anh/chị vẫn muốn mua?');
    }

    const result = await Swal.fire({
        title: cartAddSafetyText('blockTitle', 'Cảnh báo an toàn thuốc'),
        html: `<div class="drug-warnings drug-warnings--compact">${cartAddWarningsHtml(warnings)}</div>`,
        icon: 'warning',
        showCancelButton: true,
        focusCancel: true,
        confirmButtonText: cartAddSafetyText('confirmButton', 'Tôi hiểu và vẫn muốn mua'),
        cancelButtonText: cartAddSafetyText('cancelButton', 'Để em chọn thuốc khác'),
        confirmButtonColor: '#B91C1C',
        cancelButtonColor: '#6B7280',
        width: 640,
        customClass: { popup: 'rounded-4' },
    });

    return result.isConfirmed === true;
}

/**
 * Non-blocking warnings still need severity, message and action visible. A toast
 * would truncate them, so use the same warning block markup as the page.
 */
function cartAddShowSoftWarnings(warnings) {
    if (typeof Swal === 'undefined' || !Swal.fire) {
        cartAddToast('warning', 'Lưu ý về thuốc', warnings[0]?.title || '');
        return;
    }

    Swal.fire({
        title: cartAddSafetyText('blockTitle', 'Cảnh báo an toàn thuốc'),
        html: `
            <p style="margin:0 0 12px;color:#4B5563;font-size:0.9375rem;">${cartAddEscape(cartAddSafetyText('softMessage', ''))}</p>
            <div class="drug-warnings drug-warnings--compact">${cartAddWarningsHtml(warnings)}</div>`,
        icon: 'info',
        confirmButtonText: 'Em đã hiểu',
        confirmButtonColor: '#047857',
        width: 640,
        customClass: { popup: 'rounded-4' },
    });
}

async function cartAddPost(endpoint, body) {
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

    return { response, data };
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
        let { response, data } = await cartAddPost(endpoint, new FormData(form));

        if (data.requires_confirmation === true) {
            if (!(await cartAddConfirmWarnings(data.drug_warnings))) {
                return;
            }

            const confirmedBody = new FormData(form);
            confirmedBody.set('confirm_warning', '1');
            ({ response, data } = await cartAddPost(endpoint, confirmedBody));
        }

        if (response.ok && data.success) {
            cartAddUpdateBadge(data.cart_count ?? 0);
            cartAddToast('success', 'Thành công!', data.message || 'Đã thêm vào giỏ hàng.');

            // Non-blocking warnings: show every one with its severity and action,
            // not just the first title.
            if (Array.isArray(data.drug_warnings) && data.drug_warnings.length > 0) {
                cartAddShowSoftWarnings(data.drug_warnings);
            }
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
