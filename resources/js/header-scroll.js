/**
 * Trạng thái cuộn của header: logo nhường chỗ cho ô tìm kiếm khi khách cuộn xuống.
 *
 * Cố ý không có listener scroll nào. Một sentinel cao 64px ở đầu <body> làm mốc, và
 * IntersectionObserver chỉ gọi lại đúng lúc mốc đó vượt qua mép trên viewport — hai
 * lần cho một chuyến cuộn xuống rồi lên, không phải mỗi frame. Toàn bộ phần chuyển
 * trạng thái nằm ở CSS (:root[data-header-scrolled] trong layouts/app.blade.php).
 *
 * Không theo chiều cuộn: cuộn lên giữa trang không mang logo trở lại. Ngón tay rê qua
 * lại quanh ngưỡng sẽ bật/tắt liên tục, và một header nhấp nháy giữa lúc đang đọc là
 * nhiễu chứ không phải tính năng.
 *
 * Không có JS thì thuộc tính không bao giờ được đặt, header đứng ở trạng thái đầy đủ
 * và mọi thứ vẫn dùng được.
 */
const STATE_ATTRIBUTE = 'data-header-scrolled';

function initHeaderScroll() {
    const sentinel = document.querySelector('[data-header-sentinel]');

    if (!sentinel || !('IntersectionObserver' in window)) {
        return;
    }

    const root = document.documentElement;

    const observer = new IntersectionObserver(([entry]) => {
        // isIntersecting false = sentinel đã trôi hết lên trên viewport = scrollY > 64.
        const scrolled = !entry.isIntersecting;

        if (root.hasAttribute(STATE_ATTRIBUTE) === scrolled) {
            return;
        }

        root.toggleAttribute(STATE_ATTRIBUTE, scrolled);
    });

    observer.observe(sentinel);

    // Panel gợi ý là position: fixed, đặt theo rect của form. Thanh header thu lại làm
    // form.bottom dịch lên ~4px, nên panel đang mở phải được đặt lại. Phát sự kiện sau
    // khi transition xong thay vì trong callback trên: lúc callback chạy kích thước mới
    // chưa có. Cùng khuôn với pharmacy:content-updated của catalog-async.js.
    document.querySelector('.site-header')?.addEventListener('transitionend', (event) => {
        if (event.propertyName === 'padding-top' && event.target === event.currentTarget) {
            document.dispatchEvent(new CustomEvent('pharmacy:header-state', {
                detail: { scrolled: root.hasAttribute(STATE_ATTRIBUTE) },
            }));
        }
    });
}

document.addEventListener('DOMContentLoaded', initHeaderScroll);
