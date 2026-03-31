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
        this.suggestionsContainer = null;
        this.currentFocus = -1;
        
        this.init();
    }
    
    init() {
        // Create suggestions container
        this.createSuggestionsContainer();
        
        // Bind events
        this.input.addEventListener('input', (e) => this.handleInput(e));
        this.input.addEventListener('keydown', (e) => this.handleKeydown(e));
        this.input.addEventListener('focus', (e) => this.handleFocus(e));
        
        // Close suggestions when clicking outside
        document.addEventListener('click', (e) => {
            if (!this.input.contains(e.target) && !this.suggestionsContainer.contains(e.target)) {
                this.hideSuggestions();
            }
        });
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
        
        // Append to body for highest stacking context
        document.body.appendChild(this.suggestionsContainer);
        
        // Position suggestions below the form
        this.positionSuggestions();
        
        // Reposition on scroll and resize
        window.addEventListener('scroll', () => this.positionSuggestions(), { passive: true });
        window.addEventListener('resize', () => this.positionSuggestions(), { passive: true });
    }
    
    positionSuggestions() {
        const form = this.input.closest('form') || this.input.parentElement;
        const formRect = form.getBoundingClientRect();
        
        // Calculate position relative to viewport (fixed positioning)
        const top = formRect.bottom + 8;
        const left = formRect.left;
        const width = formRect.width;
        
        this.suggestionsContainer.style.top = `${top}px`;
        this.suggestionsContainer.style.left = `${left}px`;
        this.suggestionsContainer.style.width = `${width}px`;
    }
    
    handleInput(e) {
        const query = e.target.value.trim();
        
        clearTimeout(this.debounceTimer);
        
        if (query.length < this.options.minChars) {
            this.hideSuggestions();
            return;
        }
        
        this.debounceTimer = setTimeout(() => {
            this.fetchSuggestions(query);
        }, this.options.debounceDelay);
    }
    
    handleKeydown(e) {
        const items = this.suggestionsContainer.querySelectorAll('.suggestion-item');
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.currentFocus++;
            this.setActive(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            this.currentFocus--;
            this.setActive(items);
        } else if (e.key === 'Enter') {
            if (this.currentFocus > -1 && items[this.currentFocus]) {
                e.preventDefault();
                items[this.currentFocus].click();
            }
        } else if (e.key === 'Escape') {
            this.hideSuggestions();
        }
    }
    
    handleFocus(e) {
        const query = e.target.value.trim();
        if (query.length >= this.options.minChars) {
            this.fetchSuggestions(query);
        }
    }
    
    setActive(items) {
        if (!items.length) return;
        
        // Remove active class from all
        items.forEach(item => item.classList.remove('active'));
        
        // Wrap around
        if (this.currentFocus >= items.length) this.currentFocus = 0;
        if (this.currentFocus < 0) this.currentFocus = items.length - 1;
        
        // Add active class
        items[this.currentFocus].classList.add('active');
        items[this.currentFocus].scrollIntoView({ block: 'nearest' });
    }
    
    async fetchSuggestions(query) {
        try {
            const response = await fetch(`${this.options.suggestionsUrl}?q=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            this.renderSuggestions(data, query);
        } catch (error) {
            console.error('Error fetching suggestions:', error);
        }
    }
    
    renderSuggestions(data, query) {
        const { products, categories } = data;
        
        if (!products.length && !categories.length) {
            this.renderEmpty(query);
            return;
        }
        
        let html = '';
        
        // Render categories
        if (categories.length > 0) {
            html += '<div class="suggestion-section">';
            html += '<div class="suggestion-header">Danh mục</div>';
            categories.forEach(category => {
                html += `
                    <a href="${category.url}" class="suggestion-item category-item">
                        <i class="bi bi-folder text-emerald-600"></i>
                        <span>${this.highlightMatch(category.name, query)}</span>
                    </a>
                `;
            });
            html += '</div>';
        }
        
        // Render products
        if (products.length > 0) {
            html += '<div class="suggestion-section">';
            html += '<div class="suggestion-header">Sản phẩm</div>';
            products.forEach(product => {
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
        
        // Add "View all results" link
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
        const regex = new RegExp(`(${query})`, 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }
    
    showSuggestions() {
        this.positionSuggestions(); // Recalculate position before showing
        this.suggestionsContainer.style.display = 'block';
    }
    
    hideSuggestions() {
        this.suggestionsContainer.style.display = 'none';
        this.currentFocus = -1;
    }
}

// Auto-initialize on all search inputs
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = document.querySelectorAll('input[name="q"][type="text"]');
    
    searchInputs.forEach(input => {
        new SearchAutocomplete(input);
    });
});

// Export for use in other modules
export default SearchAutocomplete;
