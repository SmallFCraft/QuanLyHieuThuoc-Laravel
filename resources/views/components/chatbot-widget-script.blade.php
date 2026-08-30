<script>
function chatbotWidget() {
    // Deliberately outside the returned state: Alpine wraps that object in a reactive
    // Proxy, so a turn read back from `this` is never identical to the one created here
    // — the identity check in finishRequest() always failed and left isLoading stuck on
    // after the answer had already been rendered. An AbortController reached through the
    // Proxy is also the wrong receiver for abort().
    let activeTurn = null;
    let turnSeq = 0;

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
        currentTurnId: null,
        pendingJobId: null,
        /**
         * Deadline for one whole turn, sync fallback included: the primary and fallback
         * providers get AI_TIMEOUT each, and the browser spends ~12s proving no worker
         * exists before it retries inline. Past this the wait is a hang, not a slow model.
         */
        requestTimeoutMs: 90000,
        attachedImage: null,
        imagePreviewUrl: null,
        isAuthenticated: false,
        sessionId: null,
        sessionCopied: false,
        sessionCopyTimer: null,
        lastUserMessage: '',
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
            
            // Load user data from data attributes
            const container = this.$el;
            if (container) {
                this.isAuthenticated = container.dataset.authenticated === 'true';
                this.checkoutForm.customer_name = container.dataset.userName || '';
                this.checkoutForm.shipping_phone = container.dataset.userPhone || '';
                this.checkoutForm.shipping_address = container.dataset.userAddress || '';
            }

            if (this.isAuthenticated) {
                this.loadHistory();
                this.loadCart();
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

            if (this.currentTurn) {
                window.clearTimeout(this.currentTurn.timeoutId);
                this.currentTurn.controller.abort();
                this.currentTurn = null;
            }

            if (this.copyFeedbackTimer) {
                window.clearTimeout(this.copyFeedbackTimer);
                this.copyFeedbackTimer = null;
            }

            if (this.sessionCopyTimer) {
                window.clearTimeout(this.sessionCopyTimer);
                this.sessionCopyTimer = null;
            }

            this.revokeTimers.forEach((timer) => window.clearTimeout(timer));
            this.revokeTimers = [];

            this.objectUrls.forEach((url) => URL.revokeObjectURL(url));
            this.objectUrls = [];
        },

        pushMessage(message) {
            const nextMessages = [...this.messages, this.normalizeMessageTimestamp(message)];
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
                ? messages.map((message) => this.normalizeMessageTimestamp(message)).slice(-this.maxMessages)
                : [];
        },

        normalizeMessageTimestamp(message) {
            const normalized = { ...(message || {}) };

            if (!normalized.created_at && !normalized.createdAt) {
                normalized.created_at = new Date().toISOString();
            }

            return normalized;
        },

        shouldShowInlineDisclaimer(message, index) {
            if (!message || message.role === 'user') {
                return false;
            }

            let latestAssistantIndex = -1;
            let hasDisclaimer = false;

            for (let i = this.messages.length - 1; i >= 0; i--) {
                const entry = this.messages[i];
                if (!entry || entry.role === 'user') {
                    continue;
                }

                if (latestAssistantIndex === -1) {
                    latestAssistantIndex = i;
                }

                if (entry.show_disclaimer) {
                    hasDisclaimer = true;
                    break;
                }
            }

            return hasDisclaimer && latestAssistantIndex === index;
        },

        getInlineDisclaimerText(message) {
            for (let i = this.messages.length - 1; i >= 0; i--) {
                const entry = this.messages[i];
                if (entry && entry.role !== 'user' && entry.show_disclaimer) {
                    return entry.disclaimer || message?.disclaimer || '';
                }
            }

            return message?.disclaimer || '';
        },

        trackObjectUrl(url) {
            if (url && !this.objectUrls.includes(url)) {
                this.objectUrls.push(url);
            }

            return url;
        },

        revokeObjectUrl(url) {
            if (!url) return;

            // History supplies an http(s) URL for past uploads; only the live turn's
            // preview is a blob that has to be released.
            if (String(url).startsWith('blob:')) {
                URL.revokeObjectURL(url);
            }

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

        setSessionId(sessionId) {
            this.sessionId = sessionId || null;
            this.sessionCopied = false;
        },

        displaySessionId() {
            if (!this.sessionId) {
                return '';
            }

            return `CHAT-${String(this.sessionId).slice(0, 8).toUpperCase()}`;
        },

        async copySessionId() {
            if (!this.sessionId) {
                return;
            }

            const copied = await this.copyTextToClipboard(this.sessionId);
            if (!copied) {
                return;
            }

            this.sessionCopied = true;
            if (this.sessionCopyTimer) {
                window.clearTimeout(this.sessionCopyTimer);
            }
            this.sessionCopyTimer = window.setTimeout(() => {
                this.sessionCopied = false;
                this.sessionCopyTimer = null;
            }, 1800);
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

            const turn = this.beginRequest();

            // If there's an image, send it with optional text
            if (hasImage) {
                await this.sendImageWithText(userMessage, turn);
            } else {
                // Text-only message
                this.inputText = '';
                this.lastUserMessage = userMessage;
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
                        signal: turn.controller.signal,
                        body: JSON.stringify({
                            message: userMessage,
                            context: this.getContext()
                        })
                    });

                    let data = await response.json().catch(() => ({}));
                    if (Object.prototype.hasOwnProperty.call(data, 'session_id')) {
                        this.setSessionId(data.session_id);
                    }

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

                    // A worker took the turn: the answer arrives by polling instead of
                    // in this response, which is what keeps the rest of the site usable
                    // while the model is thinking.
                    if (response.status === 202 && data.queued && data.job_id) {
                        this.pendingJobId = data.job_id;
                        data = await this.awaitJobResult(data.job_id, userMessage, turn);
                    }

                    // Nothing to render: stopRequest() already closed the turn.
                    if (turn.stopped || data?.cancelled) {
                        return;
                    }

                    if (!response.ok && response.status !== 202) {
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
                            disclaimer: data.disclaimer,
                            triage_handoff: data.triage_handoff || null,
                            showRetry: data.provider_unavailable === true
                        });

                    } else {
                        this.pushMessage({
                            role: 'assistant',
                            content: data.response || data.error || 'Dạ, em gặp chút lỗi khi xử lý. Anh/chị thử lại giúp em nhé.',
                            validation_error: data.validation_error || false,
                            // A rejected message is the customer's to fix; anything else
                            // failed on our side, so offer the retry that already exists.
                            showRetry: !data.validation_error
                        });
                    }
                } catch (error) {
                    // The customer's own stop is not a failure, and it has already put
                    // its own note in the thread.
                    if (turn.stopped) {
                        return;
                    }

                    if (turn.timedOut) {
                        this.pushMessage({
                            role: 'assistant',
                            content: 'Dạ, em chờ phản hồi quá lâu nên đã dừng yêu cầu này. Anh/chị thử lại giúp em nhé.',
                            showRetry: true
                        });
                        return;
                    }

                    console.error('Error:', error);
                    this.pushMessage({
                        role: 'assistant',
                        content: 'Dạ, em đang không kết nối được với cửa hàng. Anh/chị vui lòng kiểm tra mạng và thử lại sau nhé.',
                        showRetry: true
                    });
                } finally {
                    this.finishRequest(turn);
                    this.scrollToBottom();
                }
            }
        },

        /**
         * A turn is in flight from here until finishRequest().
         *
         * The turn lives in a closure variable, not in Alpine state: reactive state is
         * Proxy-wrapped, so `this.currentTurn !== turn` was always true and the widget
         * never left the loading state. `currentTurnId` is the only part exposed to the
         * template, and it is a plain number, which survives the Proxy unchanged.
         *
         * The AbortController is what the stop button and the watchdog both pull on: a
         * hung provider used to leave the widget spinning with no way out, because the
         * fetch had no signal and no deadline of its own.
         */
        beginRequest() {
            const turn = {
                id: ++turnSeq,
                controller: new AbortController(),
                stopped: false,
                timedOut: false,
                timeoutId: null
            };

            turn.timeoutId = window.setTimeout(() => {
                turn.timedOut = true;

                // The worker keeps going after the browser gives up, and its answer would
                // land in `chat_messages` — a reply to a question the customer was already
                // told had failed. Retiring the job first is what prevents that.
                if (this.pendingJobId) {
                    this.cancelPendingJob(this.pendingJobId);
                }

                turn.controller.abort();
            }, this.requestTimeoutMs);

            activeTurn = turn;
            this.currentTurnId = turn.id;
            this.pendingJobId = null;
            this.isLoading = true;

            return turn;
        },

        finishRequest(turn) {
            if (turn?.timeoutId) {
                window.clearTimeout(turn.timeoutId);
                turn.timeoutId = null;
            }

            // A newer turn already owns the widget; leave its state alone.
            if (activeTurn !== turn) {
                return;
            }

            activeTurn = null;
            this.currentTurnId = null;
            this.pendingJobId = null;
            this.isLoading = false;
        },

        /**
         * Withdraw the turn the customer is waiting on.
         *
         * Aborting the fetch only stops the browser listening; the worker would still
         * finish and write its answer to `chat_messages`, so the reply would reappear on
         * the next reload. Retiring the job server-side is what makes the stop stick.
         */
        stopRequest() {
            const turn = activeTurn;

            if (!this.isLoading || turn === null) {
                return;
            }

            turn.stopped = true;

            const jobId = this.pendingJobId;
            turn.controller.abort();

            if (jobId) {
                this.cancelPendingJob(jobId);
            }

            this.pushMessage({
                role: 'assistant',
                content: 'Đã dừng yêu cầu. Anh/chị có thể gửi câu hỏi khác ạ.',
                isSystemMessage: true
            });

            this.finishRequest(turn);
            this.scrollToBottom();
        },

        async cancelPendingJob(jobId) {
            try {
                await fetch(`/api/ai-jobs/${jobId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });
            } catch (error) {
                // The abort already ended the wait for the customer. A failed cancel only
                // means the worker's answer lands in history, so it must not raise here.
                console.error('Cancel job error:', error);
            }
        },
        
        /**
         * Wait for a queued turn by polling, and fall back to a synchronous request
         * when no worker ever picks it up.
         *
         * Backoff keeps the poll cheap for a slow model (a 45s answer costs ~20
         * requests, not 45). The stranded check is the difference between "nobody
         * started queue:work" and "the model is slow": only the former is retried
         * inline, so a slow answer is never sent to the provider twice.
         */
        async awaitJobResult(jobId, userMessage, turn) {
            const delays = [700, 1000, 1500, 2000, 2500];
            let attempt = 0;
            const signal = turn.controller.signal;

            while (attempt < 180) {
                await new Promise((resolve) => setTimeout(resolve, delays[Math.min(attempt, delays.length - 1)]));
                attempt++;

                if (signal.aborted) {
                    return this.haltedResult(turn);
                }

                let pollResponse;
                try {
                    pollResponse = await fetch(`/api/ai-jobs/${jobId}`, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal
                    });
                } catch (error) {
                    // A dropped poll is retried; an abort is not — otherwise the loop
                    // would spin at full speed on a signal that never clears.
                    if (signal.aborted) {
                        return this.haltedResult(turn);
                    }

                    continue;
                }

                const state = await pollResponse.json().catch(() => ({}));

                if (pollResponse.status === 404) {
                    return { success: false, error: 'Dạ, yêu cầu này không còn hiệu lực. Anh/chị gửi lại giúp em nhé.' };
                }

                if (state.status === 'done' || state.status === 'failed') {
                    return state;
                }

                // Stopped from another tab, or our own DELETE landed first.
                if (state.status === 'cancelled') {
                    return { success: false, cancelled: true };
                }

                if (state.stranded) {
                    return await this.sendMessageSynchronously(userMessage, jobId, signal);
                }
            }

            return { success: false, error: 'Dạ, em xử lý lâu hơn dự kiến. Anh/chị thử gửi lại giúp em nhé.' };
        },

        /**
         * Why the wait ended. A watchdog timeout is a failure the customer must see;
         * their own stop already has its note in the thread.
         */
        haltedResult(turn) {
            if (turn.timedOut) {
                throw new Error('Chatbot request timed out');
            }

            return { success: false, cancelled: true };
        },

        async sendMessageSynchronously(userMessage, abandonJobId, signal) {
            const response = await fetch('/api/chatbot/message', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                signal,
                body: JSON.stringify({
                    message: userMessage,
                    context: this.getContext(),
                    sync: true,
                    abandon_job_id: abandonJobId
                })
            });

            return await response.json().catch(() => ({
                success: false,
                error: 'Dạ, em đang không kết nối được với cửa hàng. Anh/chị thử lại sau nhé.'
            }));
        },

        async retryLastMessage() {
            if (this.isLoading || !this.lastUserMessage) {
                return;
            }

            // Only the newest failed reply should offer a retry affordance.
            this.messages = this.messages.map((entry) => (
                entry && entry.showRetry ? { ...entry, showRetry: false } : entry
            ));

            this.inputText = this.lastUserMessage;
            await this.sendMessage();
        },

        async sendImageWithText(text, turn) {
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
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    signal: turn.controller.signal,
                    body: formData
                });

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
                    this.scrollToBottom();
                    return;
                }

                // Handle bad request
                if (response.status === 400) {
                    const errorData = await response.json().catch(() => ({}));
                    this.pushMessage({
                        role: 'assistant',
                        content: (errorData.error || errorData.message || 'Dạ, em gặp chút lỗi khi đọc ảnh. Anh/chị vui lòng thử lại sau nhé.')
                    });
                    this.scrollToBottom();
                    return;
                }
                
                const data = await response.json();
                if (Object.prototype.hasOwnProperty.call(data, 'session_id')) {
                    this.setSessionId(data.session_id);
                }
                
                if (data.success && data.recognized_products && data.recognized_products.length > 0) {
                    const matchedCount = data.recognized_products.filter(p => p.matched).length;
                    const unmatchedCount = data.recognized_products.filter(p => !p.matched).length;
                    const matchedProducts = data.recognized_products
                        .filter(p => p.matched)
                        .map(p => p.product);
                    const serverAnswer = (data.response || '').trim();

                    // The server answered the question typed with the image.
                    if (serverAnswer !== '') {
                        this.pushMessage({
                            role: 'assistant',
                            content: serverAnswer,
                            products: matchedProducts,
                            show_disclaimer: data.show_disclaimer || false,
                            disclaimer: data.disclaimer
                        });
                    } else if (matchedCount > 0) {
                        // Only show the summary bubble when there are matched products to display.
                        // When everything is unmatched, the detail bubble below already conveys
                        // the same information (avoids the redundant "✅ Đã nhận dạng / ⚠️ không tìm thấy" message).
                        let message = `✅ Đã nhận dạng được ${data.recognized_products.length} sản phẩm từ hình ảnh.\n\n`;
                        message += `📦 Tìm thấy ${matchedCount} sản phẩm trong kho.\n`;
                        if (unmatchedCount > 0) {
                            message += `⚠️ ${unmatchedCount} sản phẩm không tìm thấy trong hệ thống.\n`;
                        }

                        this.pushMessage({
                            role: 'assistant',
                            content: message,
                            products: matchedProducts
                        });
                    }
                    
                    // Show unmatched products if any (with suggestions when available).
                    // When the server already answered the question, keep only the
                    // concrete suggestion list so the two bubbles don't repeat each other.
                    if (unmatchedCount > 0) {
                        const unmatched = data.recognized_products.filter(p => !p.matched);
                        const hasAnySuggestion = unmatched.some(
                            p => Array.isArray(p.suggestions) && p.suggestions.length > 0
                        );

                        if (serverAnswer === '' || hasAnySuggestion) {
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

                            const footer = hasAnySuggestion
                                ? '\n\nAnh/chị có thể chọn một trong các sản phẩm gợi ý phía trên, hoặc liên hệ nhà thuốc nếu cần hỗ trợ thêm nhé.'
                                : '\n\nVui lòng kiểm tra lại tên sản phẩm hoặc liên hệ nhà thuốc để được hỗ trợ.';

                            this.pushMessage({
                                role: 'assistant',
                                content: `Các sản phẩm không tìm thấy:\n\n${blocks.join('\n\n')}${footer}`
                            });
                        }
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
                if (turn.stopped) {
                    return;
                }

                if (turn.timedOut) {
                    this.pushMessage({
                        role: 'assistant',
                        content: 'Dạ, em chờ phản hồi quá lâu nên đã dừng việc đọc ảnh này. Anh/chị thử lại giúp em nhé.'
                    });
                    return;
                }

                console.error('Error:', error);
                this.pushMessage({
                    role: 'assistant',
                    content: 'Dạ, em gặp sự cố khi tải ảnh lên. Anh/chị kiểm tra lại mạng và thử lại giúp em nhé.'
                });
            } finally {
                this.scheduleObjectUrlCleanup(stableImageUrl);

                this.finishRequest(turn);
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
            if (!this.isAuthenticated) {
                return;
            }

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

        formatMessageTime(value) {
            if (!value) {
                return '';
            }

            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return '';
            }

            return date.toLocaleString('vi-VN', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        async copyMessage(content, index) {
            const text = String(content ?? '').trim();
            if (!text) return;

            const copied = await this.copyTextToClipboard(text);

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

        async copyTextToClipboard(text) {
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

            return copied;
        },

        parseMarkdown(text) {
            return window.ChatbotMarkdownRenderer?.parse
                ? window.ChatbotMarkdownRenderer.parse(text)
                : this.parseMarkdownLegacy(text);
        },

        decorateMarkdownHtml(html) {
            return window.ChatbotMarkdownRenderer?.decorate
                ? window.ChatbotMarkdownRenderer.decorate(html)
                : String(html || '');
        },

        escapeHtml(value) {
            return window.ChatbotMarkdownRenderer?.escapeHtml
                ? window.ChatbotMarkdownRenderer.escapeHtml(value)
                : String(value ?? '').replace(/[&<>"']/g, (character) => ({
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
            return window.ChatbotMarkdownRenderer?.normalizeMarkdownUrl
                ? window.ChatbotMarkdownRenderer.normalizeMarkdownUrl(value)
                : '';
        },

        parseMarkdownLegacy(text) {
            return window.ChatbotMarkdownRenderer?.parseLegacy
                ? window.ChatbotMarkdownRenderer.parseLegacy(window.ChatbotMarkdownRenderer.normalizeText(text))
                : this.escapeHtml(text);
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
            if (!this.isAuthenticated) {
                return;
            }

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
                    if (Object.prototype.hasOwnProperty.call(data, 'session_id')) {
                        this.setSessionId(data.session_id);
                    }
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
