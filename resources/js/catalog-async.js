/**
 * Bộ lọc danh mục và phân trang catalog không tải lại trang.
 *
 * Trước đây mỗi lần chọn danh mục là một lần tải lại trang: người dùng bị đưa về
 * đầu trang và phải cuộn xuống lại để chọn danh mục kế tiếp. Giờ chỉ khối
 * [data-catalog-results] được thay, vị trí cuộn giữ nguyên.
 *
 * Progressive enhancement: mọi danh mục và số trang vẫn là <a href> thật. Không có
 * JS thì trình duyệt điều hướng như cũ và không nhánh nào ở đây chạy.
 */
const RESULTS_SELECTOR = '[data-catalog-results]';
const TOTAL_SELECTOR = '[data-catalog-total]';
const FILTER_LINK_SELECTOR = '.catalog-filter-link, .nav-category-link';
// Paginator mặc định của Laravel là nav[role="navigation"] không có class .pagination;
// bắt cả hai để việc đổi theme phân trang không âm thầm tắt tính năng này.
const PAGINATION_SELECTOR = `${RESULTS_SELECTOR} nav[role="navigation"], ${RESULTS_SELECTOR} .pagination`;

function getResults() {
    return document.querySelector(RESULTS_SELECTOR);
}

/** Chỉ nhận link cùng origin trỏ về chính trang catalog — link ngoài đi đường thường. */
function isCatalogUrl(url) {
    return url.origin === window.location.origin && url.pathname.startsWith('/products');
}

function setBusy(container, busy) {
    container.setAttribute('aria-busy', busy ? 'true' : 'false');
    container.classList.toggle('is-loading', busy);
}

/**
 * Đồng bộ trạng thái đang chọn trên sidebar, dropdown header và dòng tóm tắt mobile.
 * Server không render lại phần này (nó nằm ngoài khối kết quả) nên client phải tự bù.
 */
function markActiveCategory(url) {
    const activeId = new URL(url).searchParams.get('category');

    document.querySelectorAll(FILTER_LINK_SELECTOR).forEach((link) => {
        const linkId = new URL(link.href, window.location.origin).searchParams.get('category');
        const isActive = String(linkId ?? '') === String(activeId ?? '');

        link.classList.toggle('is-active', isActive);

        if (isActive) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });

    const activeLink = document.querySelector(`.catalog-filter-link.is-active`);
    const summary = document.querySelector('.catalog-filter__toggle-current');

    if (summary && activeLink) {
        summary.textContent = activeLink.querySelector('.catalog-filter-label')?.textContent?.trim() ?? summary.textContent;
    }
}

/** Trên mobile panel danh mục là <details> mở ra che hết màn hình — chọn xong thì đóng. */
function collapseMobilePanel() {
    if (window.matchMedia('(min-width: 992px)').matches) {
        return;
    }

    const panel = document.querySelector('details[data-catalog-filter]');

    if (panel?.open) {
        panel.open = false;
    }
}

async function loadResults(url, { push = true, scrollToTop = false } = {}) {
    const container = getResults();

    if (!container) {
        return false;
    }

    setBusy(container, true);

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Catalog request failed: ${response.status}`);
        }

        const data = await response.json();
        const html = String(data.html || '').trim();

        if (!html) {
            throw new Error('Catalog request returned no markup');
        }

        container.innerHTML = html;

        // Con số ở hero nằm ngoài khối kết quả nên server không render lại nó; bỏ qua
        // là màn hình có hai tổng số đá nhau — "263 sản phẩm" trong khối, "3132" ở
        // tiêu đề mà screen reader vừa đọc.
        const total = document.querySelector(TOTAL_SELECTOR);

        if (total && Number.isFinite(data.total)) {
            total.textContent = data.total;
        }

        if (push) {
            window.history.pushState({ catalogUrl: url }, '', url);
        }

        markActiveCategory(url);

        // Thẻ sản phẩm mới cần gắn lại nút yêu thích và áp lại trạng thái đã lưu.
        document.dispatchEvent(new CustomEvent('pharmacy:content-updated', {
            detail: { container },
        }));

        // Chỉ cuộn khi đầu khối đã trôi lên trên khung nhìn, và chỉ với phân trang —
        // đổi danh mục thì giữ nguyên chỗ đứng, đó là cả lý do có file này.
        if (scrollToTop && container.getBoundingClientRect().top < 0) {
            container.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'start',
            });
        }

        return true;
    } catch (error) {
        // Thất bại thì trả về điều hướng thường, người dùng vẫn tới được nơi cần tới.
        window.location.href = url;
        return false;
    } finally {
        setBusy(container, false);
    }
}

document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    const link = event.target.closest?.('a[href]');

    if (!link || !getResults()) {
        return;
    }

    const url = new URL(link.href, window.location.origin);

    if (!isCatalogUrl(url)) {
        return;
    }

    const isFilter = link.matches(FILTER_LINK_SELECTOR);
    const isPagination = Boolean(link.closest(PAGINATION_SELECTOR));

    if (!isFilter && !isPagination) {
        return;
    }

    event.preventDefault();

    if (isFilter) {
        collapseMobilePanel();
    }

    loadResults(url.toString(), { scrollToTop: isPagination });
});

// Nút back/forward: URL đã đổi bằng pushState nên phải tự nạp lại khối kết quả.
window.addEventListener('popstate', () => {
    if (getResults()) {
        loadResults(window.location.href, { push: false });
    }
});
