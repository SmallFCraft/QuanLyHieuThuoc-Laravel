/**
 * Search Autocomplete Component
 * Provides real-time search suggestions for product search
 *
 * Hai trang thai, mot panel: chua go du 2 ky tu thi hien xu huong / lich su / danh
 * muc / san pham xem nhieu (nguon la <template id="search-panel-data"> nhung san
 * trong trang), go roi thi hien goi y tu /api/search/suggestions. Cung mot container
 * cho ca hai, vi hai overlay tranh nhau mot o input se de len nhau.
 */

import { readHistory, pushHistory, removeHistory, clearHistory, initSearchHistoryCapture, MAX_VISIBLE } from './search-history';

/** Chuoi giao dien den tu Blade, khong hardcode trong JS. */
function lang(key, fallback = '') {
    return window.PharmacySearchLang?.[key] ?? fallback;
}

class SearchAutocomplete {
    constructor(inputElement, options = {}) {
        this.input = inputElement;
        this.options = {
            minChars: 2,
            debounceDelay: 300,
            maxResults: 8,
            suggestionsUrl: '/api/search/suggestions',
            ...options
        };

        this.debounceTimer = null;
        this.abortController = null;
        this.suggestionsContainer = null;
        this.currentFocus = -1;

        this.handleInputBound = (event) => this.handleInput(event);
        this.handleKeydownBound = (event) => this.handleKeydown(event);
        this.handleFocusBound = (event) => this.handleFocus(event);
        this.handleDocumentClickBound = (event) => this.handleDocumentClick(event);
        this.handlePanelClickBound = (event) => this.handlePanelClick(event);
        this.positionSuggestionsBound = () => this.positionSuggestions();

        this.init();
    }

    init() {
        this.createSuggestionsContainer();

        this.input.addEventListener('input', this.handleInputBound);
        this.input.addEventListener('keydown', this.handleKeydownBound);
        this.input.addEventListener('focus', this.handleFocusBound);
        document.addEventListener('click', this.handleDocumentClickBound);
    }

    createSuggestionsContainer() {
        this.suggestionsContainer = document.createElement('div');
        this.suggestionsContainer.className = 'search-suggestions';
        this.suggestionsContainer.style.cssText = `
            position: fixed;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            max-height: 500px;
            overflow-y: auto;
            z-index: 9999;
            display: none;
        `;

        document.body.appendChild(this.suggestionsContainer);
        this.positionSuggestions();

        // Nut xoa lich su nam trong panel do JS dung lai moi lan mo, nen bat su kien
        // o container thay vi gan listener cho tung nut.
        this.suggestionsContainer.addEventListener('click', this.handlePanelClickBound);

        window.addEventListener('scroll', this.positionSuggestionsBound, { passive: true });
        window.addEventListener('resize', this.positionSuggestionsBound, { passive: true });
        // Header thu lai khi cuon qua 64px (header-scroll.js), lam form.bottom dich len
        // ~4px — panel dang mo phai duoc dat lai chu khong lo lung lech cho.
        document.addEventListener('pharmacy:header-state', this.positionSuggestionsBound);
    }

    positionSuggestions() {
        if (!this.suggestionsContainer || !this.input?.isConnected) {
            return;
        }

        const form = this.input.closest('form') || this.input.parentElement;

        if (!form) {
            return;
        }

        const formRect = form.getBoundingClientRect();

        // Rong theo o tim kiem, nhung khong hep hon 320px va khong tran viewport: o nam
        // tren hang header nen o 375px no chi con 194px — vua cho mot dong chu, khong
        // vua cho ten thuoc hay hai hang pill. GUTTER giu panel khong dan vao mep.
        const GUTTER = 12;
        const MIN_WIDTH = 320;
        const available = window.innerWidth - GUTTER * 2;
        const width = Math.min(Math.max(formRect.width, MIN_WIDTH), available);
        // Neo mep phai cua o roi kep lai: panel mo rong ve ben trai, phia co cho.
        const left = Math.min(Math.max(GUTTER, formRect.right - width), window.innerWidth - GUTTER - width);

        // Dai "nghe duoc" cua voice search chiem ~40px ngay duoi form khi no dang
        // hien, nen panel phai xuong duoi no thay vi de len.
        const status = document.querySelector('.voice-status[data-visible]');
        const offset = status ? status.getBoundingClientRect().height + 12 : 0;

        this.suggestionsContainer.style.top = `${formRect.bottom + 8 + offset}px`;
        this.suggestionsContainer.style.left = `${left}px`;
        this.suggestionsContainer.style.width = `${width}px`;
    }

    handleInput(event) {
        const query = event.target.value.trim();

        clearTimeout(this.debounceTimer);

        if (query.length < this.options.minChars) {
            this.abortController?.abort();
            this.renderPanel();
            return;
        }

        this.debounceTimer = setTimeout(() => {
            this.fetchSuggestions(query);
        }, this.options.debounceDelay);
    }

    handleKeydown(event) {
        const items = this.suggestionsContainer?.querySelectorAll('.suggestion-item') ?? [];

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.currentFocus++;
            this.setActive(items);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.currentFocus--;
            this.setActive(items);
        } else if (event.key === 'Enter') {
            if (this.currentFocus > -1 && items[this.currentFocus]) {
                event.preventDefault();
                items[this.currentFocus].click();
            }
        } else if (event.key === 'Escape') {
            this.hideSuggestions();
        }
    }

    handleFocus(event) {
        const query = event.target.value.trim();

        if (query.length >= this.options.minChars) {
            this.fetchSuggestions(query);
            return;
        }

        this.renderPanel();
    }

    handleDocumentClick(event) {
        if (!this.suggestionsContainer) {
            return;
        }

        if (!this.input.contains(event.target) && !this.suggestionsContainer.contains(event.target)) {
            this.hideSuggestions();
        }
    }

    /**
     * Xoa mot muc hoac ca lich su. Chi khoi lich su duoc ve lai — ve lai ca panel se
     * lam mat vi tri cuon va nhap nhay phan xu huong dung yen.
     */
    handlePanelClick(event) {
        const clear = event.target.closest('[data-history-clear]');

        if (clear) {
            event.preventDefault();
            clearHistory();
            this.renderPanel();
            return;
        }

        const remove = event.target.closest('[data-history-remove]');

        if (remove) {
            // Nut X nam trong pill la <a>: khong chan thi trang dieu huong luon.
            event.preventDefault();
            event.stopPropagation();
            removeHistory(remove.dataset.historyRemove);
            this.renderPanel();
            return;
        }

        // Bam mot pill xu huong la dieu huong bang link, khong phai submit form, nen
        // listener submit o search-history.js khong thay — ghi lich su tai day.
        const trending = event.target.closest('[data-search-term]');

        if (trending) {
            pushHistory(trending.dataset.searchTerm);
        }
    }

    /**
     * Panel luc o con trong: lich su cua khach truoc, roi xu huong / danh muc / san
     * pham xem nhieu nhung san trong trang.
     */
    renderPanel() {
        const template = document.getElementById('search-panel-data');
        const history = readHistory();

        if (!template && history.length === 0) {
            this.hideSuggestions();
            return;
        }

        this.suggestionsContainer.innerHTML = '';

        if (history.length > 0) {
            this.suggestionsContainer.appendChild(this.buildHistorySection(history));
        }

        if (template) {
            // cloneNode: template dung lai cho moi o tim kiem tren trang, nen khong
            // duoc rut node goc ra.
            this.suggestionsContainer.appendChild(template.content.cloneNode(true));
        }

        this.showSuggestions();
        this.currentFocus = -1;
    }

    buildHistorySection(history) {
        const section = document.createElement('section');
        section.className = 'search-panel-section';

        const head = document.createElement('div');
        head.className = 'search-panel-head';

        const heading = document.createElement('h3');
        heading.className = 'search-panel-heading';
        heading.innerHTML = `<i class="bi bi-clock-history text-secondary" aria-hidden="true"></i>${this.escapeHtml(lang('history', 'Tìm kiếm gần đây'))}`;

        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'search-panel-clear';
        clear.dataset.historyClear = 'true';
        clear.textContent = lang('historyClear', 'Xoá tất cả');

        head.append(heading, clear);

        const pills = document.createElement('div');
        pills.className = 'search-panel-pills';

        history.slice(0, MAX_VISIBLE).forEach((term) => {
            const pill = document.createElement('a');
            pill.className = 'search-panel-pill search-panel-pill--history';
            pill.href = `/search?q=${encodeURIComponent(term)}`;
            pill.textContent = term;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'search-panel-pill__remove';
            remove.dataset.historyRemove = term;
            remove.setAttribute('aria-label', `${lang('historyRemove', 'Xoá từ khoá này')}: ${term}`);
            remove.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>';

            pill.appendChild(remove);
            pills.appendChild(pill);
        });

        section.append(head, pills);

        return section;
    }

    setActive(items) {
        if (!items.length) {
            return;
        }

        items.forEach((item) => item.classList.remove('active'));

        if (this.currentFocus >= items.length) {
            this.currentFocus = 0;
        }

        if (this.currentFocus < 0) {
            this.currentFocus = items.length - 1;
        }

        items[this.currentFocus].classList.add('active');
        items[this.currentFocus].scrollIntoView({ block: 'nearest' });
    }

    async fetchSuggestions(query) {
        this.abortController?.abort();
        this.abortController = new AbortController();

        try {
            const response = await fetch(
                `${this.options.suggestionsUrl}?q=${encodeURIComponent(query)}`,
                {
                    headers: {
                        Accept: 'application/json'
                    },
                    signal: this.abortController.signal
                }
            );

            const data = await response.json();

            if (this.input.value.trim() !== query) {
                return;
            }

            this.renderSuggestions(data, query);
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error('Error fetching suggestions:', error);
            }
        }
    }

    renderSuggestions(data, query) {
        const { products = [], categories = [] } = data ?? {};

        if (!products.length && !categories.length) {
            this.renderEmpty(query);
            return;
        }

        let html = '';

        if (categories.length > 0) {
            html += '<div class="suggestion-section">';
            html += `<div class="suggestion-header">${this.escapeHtml(lang('suggestionCategories', 'Danh mục'))}</div>`;
            categories.forEach((category) => {
                html += `
                    <a href="${this.escapeAttribute(category.url)}" class="suggestion-item category-item">
                        <i class="bi bi-folder text-emerald-600"></i>
                        <span>${this.highlightMatch(category.name, query)}</span>
                    </a>
                `;
            });
            html += '</div>';
        }

        if (products.length > 0) {
            html += '<div class="suggestion-section">';
            html += `<div class="suggestion-header">${this.escapeHtml(lang('suggestionProducts', 'Sản phẩm'))}</div>`;
            products.forEach((product) => {
                html += `
                    <a href="${this.escapeAttribute(product.url)}" class="suggestion-item product-item">
                        <div class="product-image">
                            ${product.image_url
                                ? `<img src="${this.escapeAttribute(product.image_url)}" alt="${this.escapeAttribute(product.name)}" onerror="this.classList.add('d-none'); this.nextElementSibling.classList.remove('d-none');"><i class="bi bi-capsule text-gray-300 d-none" aria-hidden="true"></i>`
                                : '<i class="bi bi-capsule text-gray-300" aria-hidden="true"></i>'
                            }
                            <span class="visually-hidden">Không có hình ảnh sản phẩm</span>
                        </div>
                        <div class="product-info">
                            <div class="product-name">${this.highlightMatch(product.name, query)}</div>
                            <div class="product-meta">
                                <span class="product-category">${this.escapeHtml(product.category)}</span>
                                <span class="product-price">${this.escapeHtml(product.price)}</span>
                            </div>
                        </div>
                    </a>
                `;
            });
            html += '</div>';
        }

        html += `
            <div class="suggestion-footer">
                <a href="/search?q=${encodeURIComponent(query)}" class="view-all-link">
                    ${this.escapeHtml(lang('viewAll', 'Xem tất cả kết quả cho ":term"').replace(':term', query))} <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        `;

        this.suggestionsContainer.innerHTML = html;
        this.showSuggestions();
        this.currentFocus = -1;
    }

    /**
     * Khong co ket qua: neu ro tu khoa nao khong tim thay, roi hien lai xu huong de
     * khach co duong di tiep thay vi mot ngo cut.
     */
    renderEmpty(query) {
        const template = document.getElementById('search-panel-data');

        this.suggestionsContainer.innerHTML = `
            <div class="suggestion-empty">
                <i class="bi bi-search text-gray-300"></i>
                <p>${this.escapeHtml(lang('noResults', 'Không tìm thấy kết quả cho ":term"').replace(':term', query))}</p>
                <span class="suggestion-empty__hint">${this.escapeHtml(lang('noResultsHint', ''))}</span>
            </div>
        `;

        const trending = template?.content.querySelector('[data-panel-trending]');

        if (trending) {
            this.suggestionsContainer.appendChild(trending.cloneNode(true));
        }

        this.showSuggestions();
        this.currentFocus = -1;
    }

    highlightMatch(text, query) {
        const value = String(text ?? '');

        if (!query) {
            return this.escapeHtml(value);
        }

        const escapedQuery = String(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${escapedQuery})`, 'gi');
        let lastIndex = 0;
        let html = '';
        let match;

        while ((match = regex.exec(value)) !== null) {
            html += this.escapeHtml(value.slice(lastIndex, match.index));
            html += `<mark>${this.escapeHtml(match[0])}</mark>`;
            lastIndex = match.index + match[0].length;
        }

        html += this.escapeHtml(value.slice(lastIndex));

        return html;
    }

    escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[character]));
    }

    escapeAttribute(value) {
        return this.escapeHtml(value);
    }

    showSuggestions() {
        this.positionSuggestions();
        this.suggestionsContainer.style.display = 'block';
    }

    hideSuggestions() {
        if (this.suggestionsContainer) {
            this.suggestionsContainer.style.display = 'none';
        }

        this.currentFocus = -1;
    }

    destroy() {
        clearTimeout(this.debounceTimer);
        this.abortController?.abort();

        this.input.removeEventListener('input', this.handleInputBound);
        this.input.removeEventListener('keydown', this.handleKeydownBound);
        this.input.removeEventListener('focus', this.handleFocusBound);
        document.removeEventListener('click', this.handleDocumentClickBound);
        window.removeEventListener('scroll', this.positionSuggestionsBound);
        window.removeEventListener('resize', this.positionSuggestionsBound);
        document.removeEventListener('pharmacy:header-state', this.positionSuggestionsBound);
        this.suggestionsContainer?.removeEventListener('click', this.handlePanelClickBound);

        if (this.suggestionsContainer?.parentNode) {
            this.suggestionsContainer.parentNode.removeChild(this.suggestionsContainer);
        }

        if (this.input.__searchAutocomplete === this) {
            delete this.input.__searchAutocomplete;
        }

        this.suggestionsContainer = null;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    initSearchHistoryCapture();

    const instances = [];
    // Both input types: the storefront boxes are type="search" (native clear button,
    // Enter handling), while other search boxes are still type="text".
    const searchInputs = document.querySelectorAll('input[name="q"][type="search"], input[name="q"][type="text"]');

    searchInputs.forEach((input) => {
        if (input.__searchAutocomplete instanceof SearchAutocomplete) {
            return;
        }

        const instance = new SearchAutocomplete(input);
        input.__searchAutocomplete = instance;
        instances.push(instance);
    });

    window.addEventListener('pagehide', () => {
        instances.forEach((instance) => instance.destroy());
    }, { once: true });
});

export default SearchAutocomplete;
