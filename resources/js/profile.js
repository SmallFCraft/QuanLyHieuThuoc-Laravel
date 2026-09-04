/**
 * Trang tài khoản: điều hướng tab, kiểm tra tại chỗ, nháp form, ảnh đại diện.
 *
 * Gom từ các khối <script> rải trong từng partial. Mỗi phần tự kiểm tra phần tử
 * của mình tồn tại rồi mới chạy, nên file này nạp chung với app.js mà không lỗi
 * trên các trang khác.
 */
const DRAFT_DEBOUNCE_MS = 600;

function lang(key, fallback) {
    return window.PharmacyProfileLang?.[key] ?? fallback;
}

/* ---------- Điều hướng tab qua hash ---------- */

function activateTab(hash) {
    const tabs = document.querySelectorAll(`a[data-bs-toggle="pill"][href="${hash}"]`);

    if (!tabs.length) {
        return false;
    }

    // Sidebar và dải tab mobile là hai bộ link cho cùng một tab: kích hoạt bộ đang
    // hiển thị, Bootstrap sẽ đồng bộ phần còn lại qua target chung.
    const visible = [...tabs].find((tab) => tab.offsetParent !== null) ?? tabs[0];

    window.bootstrap?.Tab?.getOrCreateInstance(visible).show();

    return true;
}

function activateTabFromHash() {
    const hash = window.location.hash;

    if (!hash) {
        return;
    }

    // #order-23 tới từ email và từ tab tổng quan: mở tab đơn hàng rồi cuộn tới đơn đó.
    if (hash.startsWith('#order-')) {
        activateTab('#orders');

        // Chờ Bootstrap hiện xong panel, nếu không getBoundingClientRect trả về 0.
        window.requestAnimationFrame(() => {
            const order = document.querySelector(hash);

            if (!order) {
                return;
            }

            order.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'center',
            });

            order.classList.add('is-highlighted');
            window.setTimeout(() => order.classList.remove('is-highlighted'), 2000);
        });

        return;
    }

    activateTab(hash);
}

function initTabs() {
    if (!document.querySelector('.profile-page')) {
        return;
    }

    activateTabFromHash();

    document.querySelectorAll('a[data-bs-toggle="pill"]').forEach((tab) => {
        tab.addEventListener('shown.bs.tab', (event) => {
            const href = event.target.getAttribute('href');

            if (href) {
                // replaceState, không pushState: mỗi lần đổi tab thêm một mục vào
                // history khiến nút back phải bấm nhiều lần mới rời được trang.
                window.history.replaceState(null, '', href);
            }

            // Dải tab mobile cuộn ngang, tab vừa chọn có thể đang nằm ngoài khung.
            document
                .querySelector(`.profile-tabbar__link[href="${href}"]`)
                ?.scrollIntoView({ inline: 'center', block: 'nearest' });
        });
    });

    window.addEventListener('hashchange', activateTabFromHash);

    // Link "Xem tất cả" trong tab tổng quan trỏ sang tab khác.
    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('[data-profile-goto-tab]');

        if (!link) {
            return;
        }

        event.preventDefault();
        activateTab(link.dataset.profileGotoTab);
        window.history.replaceState(null, '', link.dataset.profileGotoTab);
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('[data-profile-goto-order]');

        if (!link) {
            return;
        }

        event.preventDefault();
        window.location.hash = `#order-${link.dataset.profileGotoOrder}`;
    });
}

/* ---------- Kiểm tra tại chỗ ---------- */

const VALIDATORS = {
    required: (value) => value.trim() !== '',
    email: (value) => value.trim() === '' || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value.trim()),
    'phone-vn': (value) => value.trim() === '' || /^0[0-9]{9}$/.test(value.trim()),
    'past-date': (value) => {
        if (value.trim() === '') {
            return true;
        }

        const picked = new Date(`${value}T00:00:00`);

        return !Number.isNaN(picked.valueOf()) && picked <= new Date();
    },
};

function setFieldError(field, message) {
    const target = document.querySelector(`[data-error-for="${field.name}"]`);

    field.classList.toggle('is-invalid', Boolean(message));
    field.classList.toggle('is-valid', !message && field.value.trim() !== '');
    field.setAttribute('aria-invalid', message ? 'true' : 'false');

    if (target) {
        target.textContent = message ?? '';
    }
}

/** Trả về true khi hợp lệ. Kiểm khi blur, không phải mỗi lần gõ. */
function validateField(field) {
    const rule = field.dataset.validate;
    const check = rule ? VALIDATORS[rule] : null;

    if (!check) {
        return true;
    }

    const valid = check(field.value);

    setFieldError(field, valid ? null : field.dataset.message || '');

    return valid;
}

/* ---------- Nháp form ---------- */

function readDraft(key) {
    try {
        const raw = window.localStorage.getItem(key);

        return raw ? JSON.parse(raw) : null;
    } catch (error) {
        return null;
    }
}

function initProfileForm() {
    const form = document.querySelector('[data-profile-form]');

    if (!form) {
        return;
    }

    const key = form.dataset.draftKey;
    const fields = [...form.querySelectorAll('input[name], select[name], textarea[name]')]
        .filter((field) => !['_token', '_method'].includes(field.name));
    const discardButton = form.querySelector('[data-draft-discard]');

    fields.forEach((field) => {
        field.addEventListener('blur', () => validateField(field));

        // Khi đã báo lỗi thì xoá lỗi ngay lúc người dùng sửa, không đợi blur lần nữa.
        field.addEventListener('input', () => {
            if (field.classList.contains('is-invalid')) {
                validateField(field);
            }
        });
    });

    const draft = key ? readDraft(key) : null;

    if (draft && typeof draft === 'object') {
        let restored = false;

        fields.forEach((field) => {
            const saved = draft[field.name];

            // Chỉ đổ lại giá trị khác với dữ liệu server: nháp trùng server thì không
            // có gì để "phục hồi" và thông báo sẽ gây nhầm lẫn.
            if (typeof saved === 'string' && saved !== field.value) {
                field.value = saved;
                restored = true;
            }
        });

        if (restored) {
            discardButton?.classList.remove('d-none');

            const note = document.createElement('div');
            note.className = 'profile-flash profile-flash--info mb-3';
            note.setAttribute('role', 'status');
            note.innerHTML = '<i class="bi bi-info-circle-fill" aria-hidden="true"></i>';
            note.appendChild(document.createTextNode(` ${lang('draftRestored', 'Đã phục hồi nội dung bạn nhập dở trước đó.')}`));
            form.prepend(note);
        }
    }

    let draftTimer = null;

    const saveDraft = () => {
        if (!key) {
            return;
        }

        const payload = {};
        fields.forEach((field) => {
            payload[field.name] = field.value;
        });

        try {
            window.localStorage.setItem(key, JSON.stringify(payload));
            discardButton?.classList.remove('d-none');
        } catch (error) {
            // Hết quota hoặc chế độ riêng tư: bỏ qua, form vẫn submit bình thường.
        }
    };

    form.addEventListener('input', () => {
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(saveDraft, DRAFT_DEBOUNCE_MS);
    });

    discardButton?.addEventListener('click', () => {
        if (key) {
            window.localStorage.removeItem(key);
        }

        // Nạp lại để lấy đúng giá trị server, thay vì tự đoán giá trị ban đầu.
        window.location.reload();
    });

    form.addEventListener('submit', (event) => {
        const invalid = fields.filter((field) => !validateField(field));

        if (invalid.length > 0) {
            event.preventDefault();
            // Đưa con trỏ về ô lỗi đầu tiên — WCAG focus management.
            invalid[0].focus();
            invalid[0].scrollIntoView({ block: 'center', behavior: 'auto' });

            return;
        }

        // Submit thật rồi mới xoá nháp: nếu server trả lỗi validate, giá trị vẫn về
        // qua old(), nên không cần giữ nháp nữa.
        if (key) {
            window.localStorage.removeItem(key);
        }

        const submit = form.querySelector('[data-profile-submit]');

        if (submit) {
            submit.disabled = true;
            submit.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>');
        }
    });
}

/* ---------- Ảnh đại diện ---------- */

function initAvatar() {
    const form = document.querySelector('[data-avatar-form]');
    const input = form?.querySelector('[data-avatar-input]');
    const trigger = document.querySelector('[data-avatar-trigger]');

    if (!form || !input || !trigger) {
        return;
    }

    trigger.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        const file = input.files?.[0];

        if (!file) {
            return;
        }

        // Kiểm ở client trước để không phải tải cả file lên rồi mới bị từ chối.
        // Ngưỡng lấy từ data-max-size-kb, cùng nguồn config với server.
        const maxKb = Number(input.dataset.maxSizeKb || 5120);
        const allowed = ['image/jpeg', 'image/jpg', 'image/png'];

        if (!allowed.includes(file.type)) {
            window.alert(input.dataset.badTypeMessage || 'Định dạng ảnh không hỗ trợ.');
            input.value = '';

            return;
        }

        if (file.size > maxKb * 1024) {
            window.alert(input.dataset.tooLargeMessage || 'Ảnh quá lớn.');
            input.value = '';

            return;
        }

        form.submit();
    });

    // Xoá ảnh là hành động không hoàn tác được: hỏi lại trước khi gửi.
    document.querySelector('[data-avatar-remove-form]')?.addEventListener('submit', (event) => {
        const button = event.target.querySelector('[data-confirm]');

        if (button && !window.confirm(button.dataset.confirm)) {
            event.preventDefault();
        }
    });
}

/* ---------- Mật khẩu ---------- */

function initPasswordControls() {
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const field = document.getElementById(button.dataset.passwordToggle);

            if (!field) {
                return;
            }

            const revealing = field.type === 'password';

            field.type = revealing ? 'text' : 'password';
            button.setAttribute('aria-pressed', revealing ? 'true' : 'false');

            const label = revealing
                ? lang('passwordHide', 'Ẩn mật khẩu')
                : lang('passwordShow', 'Hiện mật khẩu');

            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);

            const icon = button.querySelector('i');
            icon?.classList.toggle('bi-eye', !revealing);
            icon?.classList.toggle('bi-eye-slash', revealing);
        });
    });

    const password = document.getElementById('password');
    const track = document.querySelector('[data-password-strength-track]');
    const bar = document.querySelector('[data-password-strength-bar]');
    const text = document.querySelector('[data-password-strength-text]');

    if (!password || !track || !bar || !text) {
        return;
    }

    password.addEventListener('input', () => {
        const value = password.value;

        if (value === '') {
            track.hidden = true;
            text.textContent = '';

            return;
        }

        // Bốn tiêu chí của messages.profile.new_password_hint: độ dài, chữ hoa,
        // chữ thường, số. Không phải điểm entropy, chỉ phản chiếu đúng quy tắc server.
        const score = [
            value.length >= 8,
            /[A-Z]/.test(value),
            /[a-z]/.test(value),
            /[0-9]/.test(value),
        ].filter(Boolean).length;

        const labels = [
            lang('strengthWeak', 'Mật khẩu yếu'),
            lang('strengthWeak', 'Mật khẩu yếu'),
            lang('strengthFair', 'Mật khẩu trung bình'),
            lang('strengthGood', 'Mật khẩu khá'),
            lang('strengthStrong', 'Mật khẩu mạnh'),
        ];

        track.hidden = false;
        bar.style.width = `${score * 25}%`;
        text.textContent = labels[score];
    });
}

/* ---------- Xoá tài khoản ---------- */

function initDeleteAccount() {
    const trigger = document.querySelector('[data-delete-account-trigger]');
    const form = document.getElementById('deleteAccountForm');
    const strings = window.PharmacyProfileLang?.deleteAccount;

    if (!trigger || !form || !strings) {
        return;
    }

    trigger.addEventListener('click', () => {
        if (!window.Swal?.fire) {
            // Không có SweetAlert thì vẫn phải xác nhận — đây là hành động không
            // hoàn tác được, không được phép gửi đi im lặng.
            if (window.confirm(strings.title)) {
                form.submit();
            }

            return;
        }

        window.Swal.fire({
            title: strings.title,
            html: `<p class="mb-2">${strings.intro}</p><ul class="text-start">${strings.items.map((item) => `<li>${item}</li>`).join('')}</ul><p class="text-danger fw-bold">${strings.warning}</p>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#b91c1c',
            cancelButtonColor: '#6b7280',
            confirmButtonText: strings.confirm,
            cancelButtonText: strings.cancel,
            reverseButtons: true,
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('.profile-page')) {
        return;
    }

    initTabs();
    initProfileForm();
    initAvatar();
    initPasswordControls();
    initDeleteAccount();
});
