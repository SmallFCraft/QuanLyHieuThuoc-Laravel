/**
 * Trang thai cuon cua header: logo nhuong cho cho o tim kiem khi khach cuon xuong.
 *
 * Co y khong co listener scroll nao. Mot sentinel cao 64px o dau <body> lam moc, va
 * IntersectionObserver chi goi lai dung luc moc do vuot qua mep tren viewport — hai
 * lan cho mot chuyen cuon xuong roi len, khong phai moi frame. Toan bo phan chuyen
 * trang thai nam o CSS (:root[data-header-scrolled] trong layouts/app.blade.php).
 *
 * Khong theo chieu cuon: cuon len giua trang khong mang logo tro lai. Ngon tay re qua
 * lai quanh nguong se bat/tat lien tuc, va mot header nhap nhay giua luc dang doc la
 * nhieu chu khong phai tinh nang.
 *
 * Khong co JS thi thuoc tinh khong bao gio duoc dat, header dung o trang thai day du
 * va moi thu van dung duoc.
 */
const STATE_ATTRIBUTE = 'data-header-scrolled';

function initHeaderScroll() {
    const sentinel = document.querySelector('[data-header-sentinel]');

    if (!sentinel || !('IntersectionObserver' in window)) {
        return;
    }

    const root = document.documentElement;

    const observer = new IntersectionObserver(([entry]) => {
        // isIntersecting false = sentinel da troi het len tren viewport = scrollY > 64.
        const scrolled = !entry.isIntersecting;

        if (root.hasAttribute(STATE_ATTRIBUTE) === scrolled) {
            return;
        }

        root.toggleAttribute(STATE_ATTRIBUTE, scrolled);
    });

    observer.observe(sentinel);

    // Panel goi y la position: fixed, dat theo rect cua form. Thanh header thu lai lam
    // form.bottom dich len ~4px, nen panel dang mo phai duoc dat lai. Phat su kien sau
    // khi transition xong thay vi trong callback tren: luc callback chay kich thuoc moi
    // chua co. Cung khuon voi pharmacy:content-updated cua catalog-async.js.
    document.querySelector('.site-header')?.addEventListener('transitionend', (event) => {
        if (event.propertyName === 'padding-top' && event.target === event.currentTarget) {
            document.dispatchEvent(new CustomEvent('pharmacy:header-state', {
                detail: { scrolled: root.hasAttribute(STATE_ATTRIBUTE) },
            }));
        }
    });
}

document.addEventListener('DOMContentLoaded', initHeaderScroll);
