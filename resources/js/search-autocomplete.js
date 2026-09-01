/**
 * Search Autocomplete Component
 * Provides real-time search suggestions for product search
 *
 * Hai trạng thái, một panel: chưa gõ đủ 2 ký tự thì hiện xu hướng / lịch sử / danh
 * mục / sản phẩm xem nhiều (nguồn là <template id="search-panel-data"> nhúng sẵn
 * trong trang), gõ rồi thì hiện gợi ý từ /api/search/suggestions. Cùng một container
 * cho cả hai, vì hai overlay tranh nhau một ô input sẽ đè lên nhau.
 */

import { readHistory, pushHistory, removeHistory, clearHistory, initSearchHistoryCapture, MAX_VISIBLE } from './search-history';

/** Chuỗi giao diện đến từ Blade, không hardcode trong JS. */
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

        // Nút xoá lịch sử nằm trong panel do JS dựng lại mỗi lần mở, nên bắt sự kiện
        // ở container thay vì gắn listener cho từng nút.
        this.suggestionsContainer.addEventListener('click', this.handlePanelClickBound);

        window.addEventListener('scroll', this.positionSuggestionsBound, { passive: true });
        window.addEventListener('resize', this.positionSuggestionsBound, { passive: true });
        // Header thu lại khi cuộn quá 64px (header-scroll.js), làm form.bottom dịch lên
        // ~4px — panel đang mở phải được đặt lại chứ không lơ lửng lệch chỗ.
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

        // Rộng theo ô tìm kiếm, nhưng không hẹp hơn 320px và không tràn viewport: ô nằm
        // trên hàng header nên ở 375px nó chỉ còn 194px — vừa cho một dòng chữ, không
        // vừa cho tên thuốc hay hai hàng pill. GUTTER giữ panel không dán vào mép.
        const GUTTER = 12;
        const MIN_WIDTH = 320;
        const available = window.innerWidth - GUTTER * 2;
        const width = Math.min(Math.max(formRect.width, MIN_WIDTH), available);
        // Neo mép phải của ô rồi kẹp lại: panel mở rộng về bên trái, phía có chỗ.
        const left = Math.min(Math.max(GUTTER, formRect.right - width), window.innerWidth - GUTTER - width);

        // Dải "nghe được" của voice search chiếm ~40px ngay dưới form khi nó đang
        // hiện, nên panel phải xuống dưới nó thay vì đè lên.
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
     * Xoá một mục hoặc cả lịch sử. Chỉ khối lịch sử được vẽ lại — vẽ lại cả panel sẽ
     * làm mất vị trí cuộn và nhấp nháy phần xu hướng đứng yên.
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
            // Nút X nằm trong pill là <a>: không chặn thì trang điều hướng luôn.
            event.preventDefault();
            event.stopPropagation();
            removeHistory(remove.dataset.historyRemove);
            this.renderPanel();
            return;
        }

        // Bấm một pill xu hướng là điều hướng bằng link, không phải submit form, nên
        // listener submit ở search-history.js không thấy — ghi lịch sử tại đây.
        const trending = event.target.closest('[data-search-term]');

        if (trending) {
            pushHistory(trending.dataset.searchTerm);
        }
    }

    /**
     * Panel lúc ô còn trống: lịch sử của khách trước, rồi xu hướng / danh mục / sản
     * phẩm xem nhiều nhúng sẵn trong trang.
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
            // cloneNode: template dùng lại cho mọi ô tìm kiếm trên trang, nên không
            // được rút node gốc ra.
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
     * Không có kết quả: nêu rõ từ khoá nào không tìm thấy, rồi hiện lại xu hướng để
     * khách có đường đi tiếp thay vì một ngõ cụt.
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
