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
        maxMessages: 50,
        pasteListener: null,
        objectUrls: [],
        revokeTimers: [],
        copiedMessageIndex: null,
        copyFeedbackTimer: null,
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

            this.$nextTick(() => {
                this.resizeMessageInput();
            });
        },

        handleInputKeydown(event) {
            if (event.key !== 'Enter' || event.isComposing) {
                return;
            }

            if (event.shiftKey) {
                this.$nextTick(() => {
                    this.resizeMessageInput(event.target);
                });
                return;
            }

            event.preventDefault();
            this.sendMessage();
        },

        resizeMessageInput(element = null) {
            const input = element ?? this.$refs.messageInput;
            if (!input) {
                return;
            }

            input.style.height = 'auto';
            input.style.height = `${Math.min(input.scrollHeight, 120)}px`;
        },
        
        setupPasteListener() {
            if (this.pasteListener) return;

            // Listen for paste events on the entire widget
            this.pasteListener = (e) => {
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
            };

            document.addEventListener('paste', this.pasteListener);
        },

        destroy() {
            if (this.pasteListener) {
                document.removeEventListener('paste', this.pasteListener);
                this.pasteListener = null;
            }

            if (this.copyFeedbackTimer) {
                window.clearTimeout(this.copyFeedbackTimer);
                this.copyFeedbackTimer = null;
            }

            this.revokeTimers.forEach((timer) => window.clearTimeout(timer));
            this.revokeTimers = [];

            this.objectUrls.forEach((url) => URL.revokeObjectURL(url));
            this.objectUrls = [];
        },

        pushMessage(message) {
            const nextMessages = [...this.messages, message];
            const overflow = nextMessages.length - this.maxMessages;

            if (overflow > 0) {
                nextMessages.slice(0, overflow).forEach((entry) => {
                    if (entry?.imageUrl) {
                        this.revokeObjectUrl(entry.imageUrl);
                    }
                });
            }

            this.messages = nextMessages.slice(-this.maxMessages);
        },

        setMessages(messages) {
            this.messages.forEach((entry) => {
                if (entry?.imageUrl) {
                    this.revokeObjectUrl(entry.imageUrl);
                }
            });

            this.messages = Array.isArray(messages)
                ? messages.slice(-this.maxMessages)
                : [];
        },

        trackObjectUrl(url) {
            if (url && !this.objectUrls.includes(url)) {
                this.objectUrls.push(url);
            }

            return url;
        },

        revokeObjectUrl(url) {
            if (!url) return;

            URL.revokeObjectURL(url);
            this.objectUrls = this.objectUrls.filter((item) => item !== url);
        },

        scheduleObjectUrlCleanup(url, delay = 5000) {
            if (!url) return;

            const timer = window.setTimeout(() => {
                this.revokeObjectUrl(url);
                this.revokeTimers = this.revokeTimers.filter((item) => item !== timer);
            }, delay);

            this.revokeTimers.push(timer);
        },
        
        handlePastedImage(file) {
            if (!file) return;
            
            // Validate file size
            if (file.size > 3 * 1024 * 1024) {
                this.pushMessage({
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
            this.imagePreviewUrl = this.trackObjectUrl(URL.createObjectURL(file));
            
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
            if (!hasImage && userMessage.length < 2) {
                this.pushMessage({
                    role: 'assistant',
                    content: 'Dạ, anh/chị vui lòng nhập câu hỏi cụ thể hơn một chút giúp em nhé (ít nhất 2 ký tự).',
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
                this.$nextTick(() => {
                    this.resizeMessageInput();
                });
                
                this.pushMessage({
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

                    const data = await response.json().catch(() => ({}));
                    
                    // Handle authentication required
                    if (response.status === 401 && data.require_login) {
                        this.pushMessage({
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

                    if (response.status === 422 || data.validation_error) {
                        const validationMessage = data.error
                            || data?.errors?.message?.[0]
                            || data?.message
                            || data?.response
                            || 'Dạ, tin nhắn chưa hợp lệ. Anh/chị vui lòng nhập lại giúp em nhé.';

                        this.pushMessage({
                            role: 'assistant',
                            content: validationMessage,
                            validation_error: true
                        });

                        return;
                    }

                    if (!response.ok) {
                        const serverError = data.error || data.message || `HTTP error! status: ${response.status}`;
                        throw new Error(serverError);
                    }
                    
                    if (data.success) {
                        this.pushMessage({
                            role: 'assistant',
                            content: data.response,
                            products: data.products || [],
                            categories: data.categories || [],
                            show_disclaimer: data.show_disclaimer || false,
                            disclaimer: data.disclaimer
                        });
                        
                        if (data.show_disclaimer && !this.disclaimerAccepted) {
                            this.showDisclaimer = true;
                        }
                    } else {
                        this.pushMessage({
                            role: 'assistant',
                            content: data.response || data.error || 'Dạ, em gặp chút lỗi khi xử lý. Anh/chị thử lại giúp em nhé.',
                            validation_error: data.validation_error || false
                        });
                    }
                } catch (error) {
                    console.error('Error:', error);
                    this.pushMessage({
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
            this.pushMessage({
                role: 'user',
                content: text || 'Đã gửi hình ảnh',
                hasImage: true,
                imageUrl: stableImageUrl
            });
            
            // Clear input but keep the blob URL for display
            this.inputText = '';
            this.attachedImage = null;
            this.imagePreviewUrl = null;
            this.$nextTick(() => {
                this.resizeMessageInput();
            });
            
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
                        this.pushMessage({
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
                    this.pushMessage({
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
                    this.pushMessage({
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

                    // Only show the summary bubble when there are matched products to display.
                    // When everything is unmatched, the detail bubble below already conveys
                    // the same information (avoids the redundant "✅ Đã nhận dạng / ⚠️ không tìm thấy" message).
                    if (matchedCount > 0) {
                        let message = `✅ Đã nhận dạng được ${data.recognized_products.length} sản phẩm từ hình ảnh.\n\n`;
                        message += `📦 Tìm thấy ${matchedCount} sản phẩm trong kho.\n`;
                        if (unmatchedCount > 0) {
                            message += `⚠️ ${unmatchedCount} sản phẩm không tìm thấy trong hệ thống.\n`;
                        }

                        const matchedProducts = data.recognized_products
                            .filter(p => p.matched)
                            .map(p => p.product);

                        this.pushMessage({
                            role: 'assistant',
                            content: message,
                            products: matchedProducts
                        });
                    }
                    
                    // Show unmatched products if any (with suggestions when available)
                    if (unmatchedCount > 0) {
                        const unmatched = data.recognized_products.filter(p => !p.matched);

                        const blocks = unmatched.map(p => {
                            const suggestions = Array.isArray(p.suggestions) ? p.suggestions : [];
                            if (suggestions.length === 0) {
                                return `• ${p.parsed_name}`;
                            }
                            const suggestionLines = suggestions
                                .map(s => `    - ${s.name} — ${this.formatCurrency(s.price)}`)
                                .join('\n');
                            return `• ${p.parsed_name}\n  Gợi ý sản phẩm tương tự:\n${suggestionLines}`;
                        });

                        const hasAnySuggestion = unmatched.some(
                            p => Array.isArray(p.suggestions) && p.suggestions.length > 0
                        );
                        const footer = hasAnySuggestion
                            ? '\n\nAnh/chị có thể chọn một trong các sản phẩm gợi ý phía trên, hoặc liên hệ nhà thuốc nếu cần hỗ trợ thêm nhé.'
                            : '\n\nVui lòng kiểm tra lại tên sản phẩm hoặc liên hệ nhà thuốc để được hỗ trợ.';

                        this.pushMessage({
                            role: 'assistant',
                            content: `Các sản phẩm không tìm thấy:\n\n${blocks.join('\n\n')}${footer}`
                        });
                    }
                    
                    await this.loadCart();
                } else {
                    // No products recognized or error
                    const errorMsg = data.error || data.description || 'Không thể nhận dạng sản phẩm từ hình ảnh.';
                    
                    // Check for quota/rate limit errors
                    if (errorMsg.includes('giới hạn') || errorMsg.includes('quota') || errorMsg.includes('đợi')) {
                        this.pushMessage({
                            role: 'assistant',
                            content: `⏱️ ${errorMsg}\n\n💡 Trong lúc chờ, bạn có thể:\n• Nhập tên sản phẩm trực tiếp vào chat\n• Sử dụng chức năng tìm kiếm sản phẩm\n• Duyệt danh mục sản phẩm trên trang chủ`
                        });
                    } else {
                        this.pushMessage({
                            role: 'assistant',
                            content: errorMsg + '\n\n💡 Gợi ý:\n• Đảm bảo hình ảnh rõ ràng, không bị mờ\n• Chụp thẳng góc, tránh nghiêng\n• Hình ảnh phải là đơn thuốc hoặc hóa đơn\n\nAnh/chị có thể thử lại với ảnh rõ hơn hoặc gửi tên sản phẩm trực tiếp ạ.'
                        });
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                this.pushMessage({
                    role: 'assistant',
                    content: 'Dạ, em gặp sự cố khi tải ảnh lên. Anh/chị kiểm tra lại mạng và thử lại giúp em nhé.'
                });
            } finally {
                this.scheduleObjectUrlCleanup(stableImageUrl);

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
                        MaSP: product.id,
                        quantity: quantity
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    this.cart = data.cart;
                    this.pushMessage({
                        role: 'assistant',
                        content: data.message
                    });
                    
                    // Update navbar cart badge
                    this.updateNavbarCartBadge(data.cart.count);
                } else {
                    this.pushMessage({
                        role: 'assistant',
                        content: data.message || 'Không thể thêm sản phẩm vào giỏ hàng.'
                    });
                }
                
                this.scrollToBottom();
            } catch (error) {
                console.error('Add to cart error:', error);
                this.pushMessage({
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
                        MaSP: productId,
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
                    
                    this.pushMessage({
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

            if (this.attachedImage) {
                this.clearAttachedImage();
            }
            
            // Store image and create preview
            this.attachedImage = file;
            this.imagePreviewUrl = this.trackObjectUrl(URL.createObjectURL(file));
            
            // Clear file input
            event.target.value = '';
        },
        
        clearAttachedImage() {
            if (this.imagePreviewUrl) {
                this.revokeObjectUrl(this.imagePreviewUrl);
            }
            this.attachedImage = null;
            this.imagePreviewUrl = null;
        },
        
        formatCurrency(amount) {
            return new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND',
                maximumFractionDigits: 0,
                minimumFractionDigits: 0
            }).format(Math.round(Number(amount) || 0));
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

        async copyMessage(content, index) {
            const text = String(content ?? '').trim();
            if (!text) return;

            let copied = false;

            if (navigator.clipboard && window.isSecureContext) {
                try {
                    await navigator.clipboard.writeText(text);
                    copied = true;
                } catch (error) {
                    copied = false;
                }
            }

            if (!copied) {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                textarea.style.pointerEvents = 'none';
                textarea.style.top = '-1000px';
                document.body.appendChild(textarea);
                textarea.select();
                textarea.setSelectionRange(0, textarea.value.length);

                try {
                    copied = document.execCommand('copy');
                } catch (error) {
                    copied = false;
                }

                document.body.removeChild(textarea);
            }

            if (!copied) return;

            this.copiedMessageIndex = index;
            if (this.copyFeedbackTimer) {
                window.clearTimeout(this.copyFeedbackTimer);
            }
            this.copyFeedbackTimer = window.setTimeout(() => {
                this.copiedMessageIndex = null;
                this.copyFeedbackTimer = null;
            }, 1800);
        },

        parseMarkdown(text) {
            if (!text) return '';

            const rawText = String(text);
            const normalizedText = rawText.replace(
                /(?:https?:\/\/|www\.)[^\s<)]+/g,
                (url, offset, source) => {
                    const prefix = offset >= 2 ? source.slice(offset - 2, offset) : '';
                    if (prefix === '](') {
                        return url;
                    }

                    const href = url.startsWith('www.') ? `https://${url}` : url;
                    return `[${url}](${href})`;
                }
            );

            if (
                window.marked
                && typeof window.marked.parse === 'function'
                && window.DOMPurify
                && typeof window.DOMPurify.sanitize === 'function'
            ) {
                try {
                    const html = window.marked.parse(normalizedText, {
                        gfm: true,
                        breaks: true
                    });

                    return this.decorateMarkdownHtml(html);
                } catch (error) {
                    console.warn('Marked parse failed, fallback to legacy parser', error);
                }
            }

            return this.parseMarkdownLegacy(normalizedText);
        },

        decorateMarkdownHtml(html) {
            let safeHtml = String(html || '');

            safeHtml = window.DOMPurify.sanitize(safeHtml, {
                USE_PROFILES: { html: true },
                ADD_ATTR: ['target', 'rel', 'class']
            });

            const container = document.createElement('div');
            container.innerHTML = safeHtml;

            container.querySelectorAll('a').forEach((link) => {
                const textValue = (link.textContent || '').trim();
                const normalized = textValue.toLowerCase();
                const isDetailLink = /^(xem chi tiết|xem chi tiet|mở sản phẩm|mo san pham|xem danh mục|xem danh muc|mở liên kết|mo lien ket)$/i.test(normalized);

                const href = this.normalizeMarkdownUrl(link.getAttribute('href') || '');

                if (!href) {
                    link.replaceWith(document.createTextNode(link.textContent || ''));
                    return;
                }

                link.setAttribute('href', href);
                link.setAttribute('target', '_blank');
                link.setAttribute('rel', 'noopener noreferrer');

                if (isDetailLink) {
                    link.className = 'chatbot-link-btn';
                } else {
                    link.classList.add('chatbot-inline-link');
                }
            });

            return container.innerHTML;
        },

        escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (character) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character]));
        },

        escapeAttribute(value) {
            return this.escapeHtml(value);
        },

        normalizeMarkdownUrl(value) {
            const rawUrl = String(value ?? '')
                .trim()
                .replace(/&amp;/g, '&')
                .replace(/&quot;/g, '"')
                .replace(/&#039;/g, "'");

            if (!rawUrl) {
                return '';
            }

            if (/[\s"'<>]/.test(rawUrl)) {
                return '';
            }

            if (rawUrl.startsWith('www.')) {
                return `https://${rawUrl}`;
            }

            if (/^(https?:\/\/|\/|#)/i.test(rawUrl)) {
                return rawUrl;
            }

            return '';
        },

        parseMarkdownLegacy(text) {
            let html = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, linkText, url) => {
                const normalizedLinkText = String(linkText).trim().toLowerCase();
                const isDetailLink = /^(xem chi tiết|xem chi tiet|mở sản phẩm|mo san pham|xem danh mục|xem danh muc|mở liên kết|mo lien ket)$/i.test(normalizedLinkText);
                const linkClass = isDetailLink ? 'chatbot-link-btn' : 'chatbot-inline-link';
                const href = this.normalizeMarkdownUrl(url);

                if (!href) {
                    return linkText;
                }

                return `<a href="${this.escapeAttribute(href)}" class="${linkClass}" target="_blank" rel="noopener noreferrer">${linkText}</a>`;
            });

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
                const response = await fetch(`/api/chatbot/history?limit=${this.maxMessages}`, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });
                
                if (response.ok) {
                    const data = await response.json();
                    if (data.success && data.messages) {
                        this.setMessages(data.messages);
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
                    this.resizeMessageInput(input);
                    input.focus();
                }
            });
        }
    }
}
</script>
