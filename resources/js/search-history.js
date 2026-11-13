/**
 * Lich su tim kiem, luu o localStorage.
 *
 * Co y khong gui len server: tu khoa khach go la du lieu rieng, va luu o may thi
 * tinh nang chay cho ca khach chua dang nhap ma khong can them bang nao. Doi lai,
 * lich su khong theo khach sang may khac — do la danh doi co chu y.
 */
const KEY = 'pharmacy.search_history';

// Doi version khi hinh dang du lieu doi: ban cu bi xoa thay vi doc sai.
const VERSION = 'v1';

const MAX_STORED = 8;

export const MAX_VISIBLE = 5;

export function readHistory() {
    try {
        const parsed = JSON.parse(localStorage.getItem(KEY) ?? 'null');

        if (parsed?.version !== VERSION || !Array.isArray(parsed.values)) {
            return [];
        }

        return parsed.values.filter((value) => typeof value === 'string' && value !== '');
    } catch {
        // localStorage bi chan (che do rieng tu, cookie bi tat) hoac JSON hong:
        // lich su la tien ich, khong duoc lam chet o tim kiem.
        return [];
    }
}

function write(values) {
    try {
        localStorage.setItem(KEY, JSON.stringify({ version: VERSION, values }));
    } catch {
        // Het quota hoac bi chan — bo qua.
    }
}

/** Tu khoa vua tim len dau; trung thi di chuyen chu khong them dong thu hai. */
export function pushHistory(term) {
    const value = String(term ?? '').trim().replace(/\s+/g, ' ');

    if (value === '' || value.length > 60) {
        return;
    }

    const lower = value.toLocaleLowerCase('vi');
    const values = [value, ...readHistory().filter((item) => item.toLocaleLowerCase('vi') !== lower)];

    write(values.slice(0, MAX_STORED));
}

export function removeHistory(term) {
    write(readHistory().filter((item) => item !== term));
}

export function clearHistory() {
    write([]);
}

/**
 * Ghi lai tu khoa khi khach thuc su tim — submit form, khong phai moi keystroke.
 *
 * Bat o capture phase tren document: ca ba o tim kiem (trang chu, header, burger)
 * dung cung mot form action, nen mot listener la du va khong can sua Blade.
 */
export function initSearchHistoryCapture() {
    document.addEventListener('submit', (event) => {
        const input = event.target?.querySelector?.('input[name="q"]');

        if (input) {
            pushHistory(input.value);
        }
    }, true);
}
