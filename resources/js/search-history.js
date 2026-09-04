/**
 * Lịch sử tìm kiếm, lưu ở localStorage.
 *
 * Cố ý không gửi lên server: từ khoá khách gõ là dữ liệu riêng, và lưu ở máy thì
 * tính năng chạy cho cả khách chưa đăng nhập mà không cần thêm bảng nào. Đổi lại,
 * lịch sử không theo khách sang máy khác — đó là đánh đổi có chủ ý.
 */
const KEY = 'pharmacy.search_history';

// Đổi version khi hình dạng dữ liệu đổi: bản cũ bị xoá thay vì đọc sai.
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
        // localStorage bị chặn (chế độ riêng tư, cookie bị tắt) hoặc JSON hỏng:
        // lịch sử là tiện ích, không được làm chết ô tìm kiếm.
        return [];
    }
}

function write(values) {
    try {
        localStorage.setItem(KEY, JSON.stringify({ version: VERSION, values }));
    } catch {
        // Hết quota hoặc bị chặn — bỏ qua.
    }
}

/** Từ khoá vừa tìm lên đầu; trùng thì di chuyển chứ không thêm dòng thứ hai. */
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
 * Ghi lại từ khoá khi khách thực sự tìm — submit form, không phải mỗi keystroke.
 *
 * Bắt ở capture phase trên document: cả ba ô tìm kiếm (trang chủ, header, burger)
 * dùng cùng một form action, nên một listener là đủ và không cần sửa Blade.
 */
export function initSearchHistoryCapture() {
    document.addEventListener('submit', (event) => {
        const input = event.target?.querySelector?.('input[name="q"]');

        if (input) {
            pushHistory(input.value);
        }
    }, true);
}
