/**
 * Search Autocomplete Component
 * Provides real-time search suggestions for product search
 */

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

        window.addEventListener('scroll', this.positionSuggestionsBound, { passive: true });
        window.addEventListener('resize', this.positionSuggestionsBound, { passive: true });
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

        this.suggestionsContainer.style.top = `${formRect.bottom + 8}px`;
        this.suggestionsContainer.style.left = `${formRect.left}px`;
        this.suggestionsContainer.style.width = `${formRect.width}px`;
    }

    handleInput(event) {
        const query = event.target.value.trim();

        clearTimeout(this.debounceTimer);

        if (query.length < this.options.minChars) {
            this.hideSuggestions();
            this.abortController?.abort();
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
        }
    }

    handleDocumentClick(event) {
        if (!this.suggestionsContainer) {
            return;
        }

        if (!this.input.contains(event.target) && !this.suggestionsContainer.contains(event.target)) {
            this.hideSuggestions();
        }
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
        const { products, categories } = data;

        if (!products.length && !categories.length) {
            this.renderEmpty(query);
            return;
        }

        let html = '';

        if (categories.length > 0) {
            html += '<div class="suggestion-section">';
            html += '<div class="suggestion-header">Danh mục</div>';
            categories.forEach((category) => {
                html += `
                    <a href="${category.url}" class="suggestion-item category-item">
                        <i class="bi bi-folder text-emerald-600"></i>
                        <span>${this.highlightMatch(category.name, query)}</span>
                    </a>
                `;
            });
            html += '</div>';
        }

        if (products.length > 0) {
            html += '<div class="suggestion-section">';
            html += '<div class="suggestion-header">Sản phẩm</div>';
            products.forEach((product) => {
                html += `
                    <a href="${product.url}" class="suggestion-item product-item">
                        <div class="product-image">
                            ${product.image_url
                                ? `<img src="${product.image_url}" alt="${product.name}">`
                                : '<i class="bi bi-capsule text-gray-300"></i>'
                            }
                        </div>
                        <div class="product-info">
                            <div class="product-name">${this.highlightMatch(product.name, query)}</div>
                            <div class="product-meta">
                                <span class="product-category">${product.category}</span>
                                <span class="product-price">${product.price}</span>
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
                    Xem tất cả kết quả cho "${query}" <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        `;

        this.suggestionsContainer.innerHTML = html;
        this.showSuggestions();
        this.currentFocus = -1;
    }

    renderEmpty(query) {
        this.suggestionsContainer.innerHTML = `
            <div class="suggestion-empty">
                <i class="bi bi-search text-gray-300"></i>
                <p>Không tìm thấy kết quả cho "${query}"</p>
            </div>
        `;
        this.showSuggestions();
    }

    highlightMatch(text, query) {
        if (!query) {
            return text;
        }

        const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${escapedQuery})`, 'gi');

        return text.replace(regex, '<mark>$1</mark>');
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
    const instances = [];
    const searchInputs = document.querySelectorAll('input[name="q"][type="text"]');

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
