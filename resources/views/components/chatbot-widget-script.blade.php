<script>
function chatbotWidget() {
    return {
        isOpen: false,
        messages: [],
        inputText: '',
        isLoading: false,
        showDisclaimer: false,
        disclaimerAccepted: false,
        cart: { items: [], total: 0, count: 0 },
        showCartDetail: false,
        showCheckout: false,
        isPlacingOrder: false,
        attachedImage: null,
        imagePreviewUrl: null,
        checkoutForm: {
            customer_name: '',
            shipping_phone: '',
            shipping_address: '',
            delivery_time: 'anytime',
            order_notes: ''
        },
        
        init() {
            this.disclaimerAccepted = localStorage.getItem('chatbot_disclaimer_accepted') === 'true';
            this.loadHistory();
            this.loadCart();
            
            // Load user data from data attributes
            const container = this.$el;
            if (container) {
                this.checkoutForm.customer_name = container.dataset.userName || '';
                this.checkoutForm.shipping_phone = container.dataset.userPhone || '';
                this.checkoutForm.shipping_address = container.dataset.userAddress || '';
            }
            
            // Setup paste event listener for images
            this.setupPasteListener();
        },
        
        setupPasteListener() {
            // Listen for paste events on the entire widget
            document.addEventListener('paste', (e) => {
                // Only handle paste when chatbot is open
                if (!this.isOpen) return;
                
                const items = e.clipboardData?.items;
                if (!items) return;
                
                // Look for image in clipboard
                for (let i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf('image') !== -1) {
                        e.preventDefault();
                        const blob = items[i].getAsFile();
                        this.handlePastedImage(blob);
                        break;
                    }
                }
            });
        },
        
        handlePastedImage(file) {
            if (!file) return;
            
            // Validate file size
            if (file.size > 3 * 1024 * 1024) {
                this.messages.push({
                    role: 'assistant',
                    content: 'Dạ, kích thước ảnh hơi lớn (vượt quá 3MB). Anh/chị vui lòng chọn ảnh nhẹ hơn giúp em nhé.',
                    isSystemMessage: true
                });
                this.scrollToBottom();
                return;
            }
            
            // If already has an image, replace it
            if (this.attachedImage) {
                this.clearAttachedImage();
            }
            
            // Store image and create preview
            this.attachedImage = file;
            this.imagePreviewUrl = URL.createObjectURL(file);
            
            // Don't show notification message, just show the preview
            // User can see the preview in the input area
        },
        
        toggleWidget() {
            this.isOpen = !this.isOpen;
            
            if (this.isOpen && !this.disclaimerAccepted) {
                this.showDisclaimer = true;
            }
            
            if (this.isOpen) {
                this.$nextTick(() => {
                    this.scrollToBottom();
                });
            }
        },
        
        acceptDisclaimer() {
            this.disclaimerAccepted = true;
            this.showDisclaimer = false;
            localStorage.setItem('chatbot_disclaimer_accepted', 'true');
        },
        
        async sendMessage() {
            if ((!this.inputText.trim() && !this.attachedImage) || this.isLoading) return;
            
            const userMessage = this.inputText.trim();
            const hasImage = !!this.attachedImage;
            
            // Client-side validation for text-only messages
            if (!hasImage && userMessage.length < 3) {
                this.messages.push({
                    role: 'assistant',
                    content: 'Dạ, anh/chị vui lòng nhập câu hỏi cụ thể hơn một chút giúp em nhé (ít nhất 3 ký tự).',
                    validation_error: true
                });
                this.scrollToBottom();
                return;
            }
            
            this.isLoading = true;
            
            // If there's an image, send it with optional text
            if (hasImage) {
                await this.sendImageWithText(userMessage);
            } else {
                // Text-only message
                this.inputText = '';
                
                this.messages.push({
                    role: 'user',
                    content: userMessage
                });
                
                this.scrollToBottom();
                
                try {
                    const response = await fetch('/api/chatbot/message', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            message: userMessage,
                            context: this.getContext()
                        })
                    });
                    
                    const data = await response.json();
                    
                    // Handle authentication required
                    if (response.status === 401 && data.require_login) {
                        this.messages.push({
                            role: 'assistant',
                            content: '🔐 ' + data.error + '\n\nĐể sử dụng chatbot, anh/chị vui lòng đăng nhập hoặc đăng ký tài khoản nhé.',
                            isSystemMessage: true,
                            showAuthButtons: true,
                            loginUrl: data.login_url,
                            registerUrl: '/register'
                        });
                        
                        this.isLoading = false;
                        this.scrollToBottom();
                        return;
                    }
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    if (data.success) {
                        this.messages.push({
                            role: 'assistant',
                            content: data.response,
                            products: data.products || [],
                            show_disclaimer: data.show_disclaimer || false,
                            disclaimer: data.disclaimer
                        });
                        
                        if (data.show_disclaimer && !this.disclaimerAccepted) {
                            this.showDisclaimer = true;
                        }
                    } else {
                        this.messages.push({
                            role: 'assistant',
                            content: data.response || data.error || 'Dạ, em gặp chút lỗi khi xử lý. Anh/chị thử lại giúp em nhé.',
                            validation_error: data.validation_error || false
                        });
                    }
                } catch (error) {
                    console.error('Error:', error);
                    this.messages.push({
                        role: 'assistant',
                        content: 'Dạ, em đang không kết nối được với cửa hàng. Anh/chị vui lòng kiểm tra mạng và thử lại sau nhé.'
                    });
                } finally {
                    this.isLoading = false;
                    this.scrollToBottom();
                }
            }
        },
        
        async sendImageWithText(text) {
            const formData = new FormData();
            formData.append('image', this.attachedImage);
            formData.append('type', 'prescription');
            if (text) {
                formData.append('message', text);
            }
            
            // Create a stable blob URL for the message
            const stableImageUrl = this.imagePreviewUrl;
            
            // Show user message with image preview
            this.messages.push({
                role: 'user',
                content: text || 'Đã gửi hình ảnh',
                hasImage: true,
                imageUrl: stableImageUrl
            });
            
            // Clear input but keep the blob URL for display
            this.inputText = '';
            const imageToSend = this.attachedImage;
            this.attachedImage = null;
            this.imagePreviewUrl = null;
            
            this.scrollToBottom();
            
            try {
                const response = await fetch('/api/chatbot/upload-image', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: formData
                });
                
                // Log response for debugging
                console.log('Upload response status:', response.status);
                
                // Handle authentication required
                if (response.status === 401) {
                    const errorData = await response.json();
                    if (errorData.require_login) {
                        this.messages.push({
                            role: 'assistant',
                            content: '🔐 ' + errorData.error + '\n\nĐể tải ảnh lên, anh/chị vui lòng đăng nhập hoặc đăng ký tài khoản nhé.',
                            isSystemMessage: true,
                            showAuthButtons: true,
                            loginUrl: errorData.login_url,
                            registerUrl: '/register'
                        });
                        
                        this.isLoading = false;
                        this.scrollToBottom();
                        return;
                    }
                }
                
                // Handle rate limit error
                if (response.status === 429) {
                    const retryAfter = response.headers.get('Retry-After') || '60';
                    this.messages.push({
                        role: 'assistant',
                        content: `⏱️ Dạ, hiện tại em đang tiếp nhận hơi nhiều thông tin một lúc. Anh/chị vui lòng đợi khoảng ${retryAfter} giây rồi gửi lại giúp em nhé.\n\n💡 Trong lúc chờ, anh/chị có thể nhập tên thuốc trực tiếp để em hỗ trợ ngay ạ.`
                    });
                    this.isLoading = false;
                    this.scrollToBottom();
                    return;
                }
                
                // Handle bad request
                if (response.status === 400) {
                    const errorData = await response.json();
                    console.error('400 Bad Request:', errorData);
                    this.messages.push({
                        role: 'assistant',
                        content: (errorData.error || errorData.message || 'Dạ, em gặp chút lỗi khi đọc ảnh. Anh/chị vui lòng thử lại sau nhé.')
                    });
                    this.isLoading = false;
                    this.scrollToBottom();
                    return;
                }
                
                const data = await response.json();
                
                if (data.success && data.recognized_products && data.recognized_products.length > 0) {
                    const matchedCount = data.recognized_products.filter(p => p.matched).length;
                    const unmatchedCount = data.recognized_products.filter(p => !p.matched).length;
                    
                    let message = `✅ Đã nhận dạng được ${data.recognized_products.length} sản phẩm từ hình ảnh.\n\n`;
                    
                    if (matchedCount > 0) {
                        message += `📦 Tìm thấy ${matchedCount} sản phẩm trong kho.\n`;
                    }
                    if (unmatchedCount > 0) {
                        message += `⚠️ ${unmatchedCount} sản phẩm không tìm thấy trong hệ thống.\n`;
                    }
                    
                    // Show matched products
                    const matchedProducts = data.recognized_products
                        .filter(p => p.matched)
                        .map(p => p.product);
                    
                    this.messages.push({
                        role: 'assistant',
                        content: message,
                        products: matchedProducts
                    });
                    
                    // Show unmatched products if any
                    if (unmatchedCount > 0) {
                        const unmatchedList = data.recognized_products
                            .filter(p => !p.matched)
                            .map(p => `• ${p.parsed_name}`)
                            .join('\n');
                        
                        this.messages.push({
                            role: 'assistant',
                            content: `Các sản phẩm không tìm thấy:\n${unmatchedList}\n\nVui lòng kiểm tra lại tên sản phẩm hoặc liên hệ nhà thuốc để được hỗ trợ.`
                        });
                    }
                    
                    await this.loadCart();
                } else {
                    // No products recognized or error
                    const errorMsg = data.error || data.description || 'Không thể nhận dạng sản phẩm từ hình ảnh.';
                    
                    // Check for quota/rate limit errors
                    if (errorMsg.includes('giới hạn') || errorMsg.includes('quota') || errorMsg.includes('đợi')) {
                        this.messages.push({
                            role: 'assistant',
                            content: `⏱️ ${errorMsg}\n\n💡 Trong lúc chờ, bạn có thể:\n• Nhập tên sản phẩm trực tiếp vào chat\n• Sử dụng chức năng tìm kiếm sản phẩm\n• Duyệt danh mục sản phẩm trên trang chủ`
                        });
                    } else {
                        this.messages.push({
                            role: 'assistant',
                            content: errorMsg + '\n\n💡 Gợi ý:\n• Đảm bảo hình ảnh rõ ràng, không bị mờ\n• Chụp thẳng góc, tránh nghiêng\n• Hình ảnh phải là đơn thuốc hoặc hóa đơn\n\nAnh/chị có thể thử lại với ảnh rõ hơn hoặc gửi tên sản phẩm trực tiếp ạ.'
                        });
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                this.messages.push({
                    role: 'assistant',
                    content: 'Dạ, em gặp sự cố khi tải ảnh lên. Anh/chị kiểm tra lại mạng và thử lại giúp em nhé.'
                });
            } finally {
                // Clean up blob URL after a delay to ensure it's displayed
                setTimeout(() => {
                    if (stableImageUrl) {
                        URL.revokeObjectURL(stableImageUrl);
                    }
                }, 5000);
                
                this.isLoading = false;
                this.scrollToBottom();
            }
        },
        
        async addToCart(product, quantity = 1) {
            try {
                const response = await fetch('/api/chatbot/cart/add', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        product_id: product.id,
                        quantity: quantity
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.cart = data.cart;
                    this.messages.push({
                        role: 'assistant',
                        content: data.message
                    });
                    
                    // Update navbar cart badge
                    this.updateNavbarCartBadge(data.cart.count);
                } else {
                    this.messages.push({
                        role: 'assistant',
                        content: data.message || 'Không thể thêm sản phẩm vào giỏ hàng.'
                    });
                }
                
                this.scrollToBottom();
            } catch (error) {
                console.error('Add to cart error:', error);
                this.messages.push({
                    role: 'assistant',
                    content: 'Lỗi khi thêm sản phẩm. Vui lòng thử lại.'
                });
            }
        },
        
        async loadCart() {
            try {
                const response = await fetch('/api/chatbot/cart', {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success) {
                        this.cart = data.cart;
                        this.updateNavbarCartBadge(data.cart.count);
                    }
                }
            } catch (error) {
                console.error('Load cart error:', error);
            }
        },
        
        async updateQuantity(productId, newQuantity) {
            try {
                const response = await fetch('/api/chatbot/cart/update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        product_id: productId,
                        quantity: Math.max(0, newQuantity)
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.cart = data.cart;
                    this.updateNavbarCartBadge(data.cart.count);
                } else {
                    alert(data.message || 'Không thể cập nhật giỏ hàng.');
                }
            } catch (error) {
                console.error('Update quantity error:', error);
            }
        },
        
        updateNavbarCartBadge(count) {
            const badge = document.getElementById('cart-nav-badge');
            if (badge) {
                if (count > 0) {
                    badge.textContent = count;
                    badge.classList.remove('d-none');
                    badge.setAttribute('aria-hidden', 'false');
                } else {
                    badge.textContent = '';
                    badge.classList.add('d-none');
                    badge.setAttribute('aria-hidden', 'true');
                }
            }
        },
        
        showCheckoutForm() {
            this.showCheckout = true;
            this.showCartDetail = false;
        },
        
        async placeOrder() {
            if (this.isPlacingOrder) return;
            
            this.isPlacingOrder = true;
            
            try {
                const response = await fetch('/api/chatbot/place-order', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(this.checkoutForm)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Create success message with order details and link
                    const orderDetailsUrl = `/profile#order-${data.order.id}`;
                    const successMessage = `✅ ${data.message}\n\n` +
                        `📦 Mã đơn hàng: #${data.order.id}\n` +
                        `💰 Tổng tiền: ${this.formatCurrency(data.order.total_amount)}\n` +
                        `📋 Trạng thái: ${this.getStatusText(data.order.status)}\n\n` +
                        `👉 [Xem chi tiết đơn hàng](${orderDetailsUrl})`;
                    
                    this.messages.push({
                        role: 'assistant',
                        content: successMessage,
                        isHtml: true
                    });
                    
                    // Reset
                    this.cart = { items: [], total: 0, count: 0 };
                    this.updateNavbarCartBadge(0);
                    this.showCheckout = false;
                    this.checkoutForm = {
                        customer_name: '',
                        shipping_phone: '',
                        shipping_address: '',
                        delivery_time: 'anytime',
                        order_notes: ''
                    };
                    
                    this.scrollToBottom();
                } else {
                    alert(data.message || 'Không thể đặt hàng. Vui lòng thử lại.');
                }
            } catch (error) {
                console.error('Place order error:', error);
                alert('Lỗi khi đặt hàng. Vui lòng thử lại.');
            } finally {
                this.isPlacingOrder = false;
            }
        },
        
        async handleImageUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            if (!['image/jpeg', 'image/jpg', 'image/png'].includes(file.type)) {
                alert('Chỉ hỗ trợ file JPG, JPEG, PNG');
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                alert('Kích thước file không được vượt quá 5MB');
                return;
            }
            
            // Store image and create preview
            this.attachedImage = file;
            this.imagePreviewUrl = URL.createObjectURL(file);
            
            // Clear file input
            event.target.value = '';
        },
        
        clearAttachedImage() {
            if (this.imagePreviewUrl) {
                URL.revokeObjectURL(this.imagePreviewUrl);
            }
            this.attachedImage = null;
            this.imagePreviewUrl = null;
        },
        
        formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND'
            }).format(amount);
        },
        
        getStatusText(status) {
            const statusMap = {
                'Pending': 'Chờ xử lý',
                'Delivering': 'Đang giao hàng',
                'Completed': 'Hoàn thành',
                'Cancelled': 'Đã hủy'
            };
            return statusMap[status] || status;
        },
        
        formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('vi-VN');
        },
        
        parseMarkdown(text) {
            if (!text) return '';
            
            let html = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            
            // Parse markdown links [text](url) BEFORE other replacements
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" class="text-emerald-600 hover:text-emerald-700 fw-medium text-decoration-underline" target="_blank" rel="noopener">$1</a>');
            
            html = html.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
            html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
            html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
            html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');
            html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/(?<!\\)\*([^*\n]+)\*/g, '<em>$1</em>');
            html = html.replace(/^---$/gm, '<hr>');
            html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
            html = html.replace(/^[\*\-] (.+)$/gm, '<li>$1</li>');
            html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
            html = html.replace(/((?:<li>.*<\/li>\n?)+)/g, '<ul>$1</ul>');
            html = html.replace(/\n/g, '<br>');
            html = html.replace(/<\/(h[1-6]|ul|ol|pre|blockquote|hr)><br>/g, '</$1>');
            html = html.replace(/<br><(h[1-6]|ul|ol|pre|blockquote)/g, '<$1');
            html = html.replace(/<br><br><br>/g, '<br><br>');
            html = html.replace(/<\/li><br><li>/g, '</li><li>');
            html = html.replace(/<ul><br>/g, '<ul>');
            html = html.replace(/<br><\/ul>/g, '</ul>');
            
            return html;
        },
        
        scrollToBottom() {
            this.$nextTick(() => {
                const container = this.$refs.messagesContainer;
                if (container) {
                    container.scrollTop = container.scrollHeight;
                }
            });
        },
        
        getContext() {
            return {
                previous_messages: this.messages.slice(-10)
            };
        },
        
        async loadHistory() {
            try {
                const response = await fetch('/api/chatbot/history', {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.messages) {
                        this.messages = data.messages;
                        this.$nextTick(() => {
                            this.scrollToBottom();
                        });
                    }
                }
            } catch (e) {
                console.error('Failed to load history:', e);
            }
        },
        
        saveHistory() {
            // History is now saved automatically to database via API
            // This method kept for compatibility but does nothing
        },
        
        useSuggestion(text) {
            this.inputText = text;
            // Focus vào input để người dùng có thể chỉnh sửa trước khi gửi
            this.$nextTick(() => {
                const input = this.$el.querySelector('.input-message');
                if (input) {
                    input.focus();
                }
            });
        }
    }
}
</script>
