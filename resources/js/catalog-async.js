/**
 * Bo loc danh muc va phan trang catalog khong tai lai trang.
 *
 * Truoc day moi lan chon danh muc la mot lan tai lai trang: nguoi dung bi dua ve
 * dau trang va phai cuon xuong lai de chon danh muc ke tiep. Gio chi khoi
 * [data-catalog-results] duoc thay, vi tri cuon giu nguyen.
 *
 * Progressive enhancement: moi danh muc va so trang van la <a href> that. Khong co
 * JS thi trinh duyet dieu huong nhu cu va khong nhanh nao o day chay.
 */
const RESULTS_SELECTOR = '[data-catalog-results]';
const TOTAL_SELECTOR = '[data-catalog-total]';
const FILTER_LINK_SELECTOR = '.catalog-filter-link, .nav-category-link';
// Paginator mac dinh cua Laravel la nav[role="navigation"] khong co class .pagination;
// bat ca hai de viec doi theme phan trang khong am tham tat tinh nang nay.
const PAGINATION_SELECTOR = `${RESULTS_SELECTOR} nav[role="navigation"], ${RESULTS_SELECTOR} .pagination`;

function getResults() {
    return document.querySelector(RESULTS_SELECTOR);
}

/** Chi nhan link cung origin tro ve chinh trang catalog — link ngoai di duong thuong. */
function isCatalogUrl(url) {
    return url.origin === window.location.origin && url.pathname.startsWith('/products');
}

function setBusy(container, busy) {
    container.setAttribute('aria-busy', busy ? 'true' : 'false');
    container.classList.toggle('is-loading', busy);
}

/**
 * Dong bo trang thai dang chon tren sidebar, dropdown header va dong tom tat mobile.
 * Server khong render lai phan nay (no nam ngoai khoi ket qua) nen client phai tu bu.
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

/** Tren mobile panel danh muc la <details> mo ra che het man hinh — chon xong thi dong. */
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

        // Con so o hero nam ngoai khoi ket qua nen server khong render lai no; bo qua
        // la man hinh co hai tong so da nhau — "263 san pham" trong khoi, "3132" o
        // tieu de ma screen reader vua doc.
        const total = document.querySelector(TOTAL_SELECTOR);

        if (total && Number.isFinite(data.total)) {
            total.textContent = data.total;
        }

        if (push) {
            window.history.pushState({ catalogUrl: url }, '', url);
        }

        markActiveCategory(url);

        // The san pham moi can gan lai nut yeu thich va ap lai trang thai da luu.
        document.dispatchEvent(new CustomEvent('pharmacy:content-updated', {
            detail: { container },
        }));

        // Chi cuon khi dau khoi da troi len tren khung nhin, va chi voi phan trang —
        // doi danh muc thi giu nguyen cho dung, do la ca ly do co file nay.
        if (scrollToTop && container.getBoundingClientRect().top < 0) {
            container.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'start',
            });
        }

        return true;
    } catch (error) {
        // That bai thi tra ve dieu huong thuong, nguoi dung van toi duoc noi can toi.
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

// Nut back/forward: URL da doi bang pushState nen phai tu nap lai khoi ket qua.
window.addEventListener('popstate', () => {
    if (getResults()) {
        loadResults(window.location.href, { push: false });
    }
});
