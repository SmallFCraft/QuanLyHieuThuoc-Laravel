<!-- Chatbot Widget Component -->
<div x-data="chatbotWidget()" x-init="init()" @open-chatbot.window="if(!isOpen) toggleWidget()" class="chatbot-widget-container" :class="{ 'chatbot-active': isOpen }" data-user-name="{{ auth()->check() ? auth()->user()->name : '' }}" data-user-phone="{{ auth()->check() ? auth()->user()->phone : '' }}" data-user-email="{{ auth()->check() ? auth()->user()->email : '' }}" data-user-address="{{ auth()->check() ? auth()->user()->address : '' }}">
    <!-- Floating Button with Label -->
    <div x-show="!isOpen" class="chatbot-toggle-wrapper">
        <div class="chatbot-float-label" x-data="{ showLabel: true }" x-init="setTimeout(() => showLabel = false, 8000)" x-show="showLabel" x-transition:leave="transition ease-in duration-300" x-transition:leave-start="opacity-100 transform translate-x-0" x-transition:leave-end="opacity-0 transform translate-x-4">
            <span>Trợ lý AI 💬</span>
            <div class="float-label-arrow"></div>
        </div>
        <button 
            @click="toggleWidget()"
            class="chatbot-toggle-btn"
            aria-label="Mở trợ lý AI"
        >
            <span class="chatbot-pulse-ring"></span>
            <svg style="width: 28px; height: 28px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
            </svg>
        </button>
    </div>

    <!-- Chat Window -->
    <div 
        x-show="isOpen"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 transform scale-95"
        x-transition:enter-end="opacity-100 transform scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 transform scale-100"
        x-transition:leave-end="opacity-0 transform scale-95"
        class="chatbot-window"
        :class="{ 'checkout-mode': showCheckout }"
        style="display: none;"
    >
        <!-- Header -->
        <div class="chatbot-header">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-emerald-500 shadow-md border border-emerald-300/50 rounded-full flex items-center justify-center text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                    </svg>
                </div>
                <div class="ml-3">
                    <h3 class="font-semibold text-white">Trợ Lý AI Medicare Pharmacy</h3>
                    <p class="text-xs text-emerald-50">Luôn sẵn sàng hỗ trợ kịp thời cho bạn</p>
                </div>
            </div>
            <button @click="toggleWidget()" class="text-white hover:text-emerald-50">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <!-- Disclaimer Modal -->
        <div x-show="showDisclaimer" class="chatbot-disclaimer" style="display: none;">
            <div class="p-4 bg-yellow-50 border-l-4 border-yellow-400">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-yellow-800">Lưu ý quan trọng</h3>
                        <div class="mt-2 text-sm text-yellow-700">
                            <p>Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ trước khi sử dụng thuốc.</p>
                        </div>
                        <div class="mt-4">
                            <button @click="acceptDisclaimer()" class="bg-yellow-400 hover:bg-yellow-500 text-yellow-900 px-4 py-2 rounded text-sm font-medium">
                                Đã hiểu
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages Container -->
        <div class="chatbot-messages" x-ref="messagesContainer">
            <!-- Welcome Message (shown when no messages) -->
            <div x-show="messages.length === 0 && !isLoading" class="chatbot-welcome">
                <div class="welcome-icon">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"></path>
                    </svg>
                </div>
                <h4 class="welcome-title">Xin chào! 👋</h4>
                <p class="welcome-subtitle">Tôi là trợ lý AI của <strong>Medicare Pharmacy</strong>. Tôi có thể giúp bạn:</p>
                <div class="welcome-features">
                    <div class="feature-item">
                        <span class="feature-emoji">💊</span>
                        <span>Tìm kiếm & tư vấn thuốc</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-emoji">📋</span>
                        <span>Nhận dạng đơn thuốc từ ảnh</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-emoji">🛒</span>
                        <span>Đặt hàng nhanh qua chat</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-emoji">❓</span>
                        <span>Giải đáp thắc mắc sức khỏe</span>
                    </div>
                </div>
                <p class="welcome-hint">Hãy thử một trong các gợi ý bên dưới:</p>
                <div class="suggestion-chips">
                    <button @click="useSuggestion('Tôi cần tìm thuốc giảm đau')" class="suggestion-chip">
                        💊 Tìm thuốc giảm đau
                    </button>
                    <button @click="useSuggestion('Có thuốc Paracetamol không?')" class="suggestion-chip">
                        🔍 Hỏi thuốc Paracetamol
                    </button>
                    <button @click="useSuggestion('Thuốc nào tốt cho cảm cúm?')" class="suggestion-chip">
                        🤒 Thuốc trị cảm cúm
                    </button>
                    <button @click="useSuggestion('Tư vấn vitamin tăng sức đề kháng')" class="suggestion-chip">
                        💪 Tư vấn vitamin
                    </button>
                </div>
            </div>

            <template x-for="(message, index) in messages" :key="index">
                <div :class="message.role === 'user' ? 'message-user' : 'message-assistant' + (message.isSystemMessage ? ' system-message' : '')">
                    <div class="message-content">
                        <!-- User message with optional image -->
                        <template x-if="message.role === 'user'">
                            <div>
                                <template x-if="message.hasImage && message.imageUrl">
                                    <div class="message-image-preview">
                                        <img :src="message.imageUrl" alt="Uploaded image" style="max-width: 150px; max-height: 100px; border-radius: 6px; margin-bottom: 6px; object-fit: cover; display: block;">
                                    </div>
                                </template>
                                <div x-text="message.content" x-show="message.content"></div>
                            </div>
                        </template>
                        
                        <!-- Assistant message -->
                        <div x-show="message.role !== 'user'" class="markdown-body" x-html="parseMarkdown(message.content)"></div>
                        
                        <!-- Products Display -->
                        <template x-if="message.products && message.products.length > 0">
                            <div class="mt-3 space-y-2">
                                <template x-for="product in message.products" :key="product.id">
                                    <div class="product-card">
                                        <img :src="product.image_url || '/images/no-image.png'" :alt="product.name" class="product-image">
                                        <div class="product-info">
                                            <h4 class="product-name" x-text="product.name"></h4>
                                            <p class="product-price" x-text="formatCurrency(product.price)"></p>
                                            <p class="product-stock">Còn <span x-text="product.stock_quantity"></span> sản phẩm</p>
                                            <template x-if="product.expiration_date">
                                                <p class="product-expiry" x-text="'HSD: ' + formatDate(product.expiration_date)"></p>
                                            </template>
                                            <div class="flex items-center gap-2 mt-2">
                                                <button @click="addToCart(product, 1)" class="btn-add-cart flex-1">
                                                    Thêm vào giỏ
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <!-- Disclaimer -->
                        <template x-if="message.show_disclaimer">
                            <div class="mt-2 text-xs text-yellow-600 bg-yellow-50 p-2 rounded" style="display: flex; gap: 4px; align-items: flex-start;">
                                <span>⚠️</span>
                                <span x-text="message.disclaimer || 'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.'"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Loading Indicator -->
            <div x-show="isLoading" class="message-assistant">
                <div class="message-content">
                    <div class="flex space-x-2">
                        <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce"></div>
                        <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
                        <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cart Preview -->
        <div x-show="cart && cart.count > 0" class="chatbot-cart-preview" style="display: none;">
            <div style="padding: 10px 14px; background: rgba(255, 255, 255, 0.65); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-top: 1px solid rgba(255, 255, 255, 0.8);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                    <h4 style="font-weight: 600; color: #065f46; font-size: 0.8rem; margin: 0;">
                        Giỏ hàng (<span x-text="cart.count"></span>)
                    </h4>
                    <button @click="showCartDetail = !showCartDetail" style="color: #047857; font-size: 0.7rem; text-decoration: none; background: none; border: none; cursor: pointer; padding: 0;">
                        <span x-text="showCartDetail ? 'Thu gọn' : 'Xem chi tiết'"></span>
                    </button>
                </div>
                
                <!-- Cart Items Detail -->
                <div x-show="showCartDetail" style="display: none; margin-bottom: 8px; max-height: 140px; overflow-y: auto;">
                    <template x-for="item in cart.items" :key="item.product_id">
                        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 0.75rem; background: rgba(255, 255, 255, 0.7); border: 1px solid rgba(255,255,255,0.8); padding: 6px 8px; border-radius: 4px; margin-bottom: 6px;">
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 500; color: #111827; margin-bottom: 0px; font-size: 0.75rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="item.name"></div>
                                <div style="color: #6b7280; font-size: 0.65rem;" x-text="formatCurrency(item.price) + ' x ' + item.quantity"></div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 4px; margin-left: 6px; flex-shrink: 0;">
                                <button @click="updateQuantity(item.product_id, item.quantity - 1)" style="width: 20px; height: 20px; background: #e5e7eb; border: none; border-radius: 4px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; color: #374151;">-</button>
                                <span style="width: 20px; text-align: center; font-weight: 500; font-size: 0.7rem;" x-text="item.quantity"></span>
                                <button @click="updateQuantity(item.product_id, item.quantity + 1)" style="width: 20px; height: 20px; background: #e5e7eb; border: none; border-radius: 4px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; color: #374151;">+</button>
                            </div>
                        </div>
                    </template>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-top: 1px solid #6ee7b7; margin-bottom: 8px;">
                    <span style="font-weight: 600; color: #065f46; font-size: 0.8rem;">Tổng:</span>
                    <span style="font-weight: 700; color: #065f46; font-size: 0.95rem;" x-text="formatCurrency(cart.total)"></span>
                </div>
                
                <button @click="showCheckoutForm()" style="width: 100%; background: #059669; color: white; padding: 8px 12px; border: none; border-radius: 6px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: background 0.2s;">
                    Đặt hàng ngay
                </button>
            </div>
        </div>
        
        <!-- Checkout Form Modal -->
        <div x-show="showCheckout" class="chatbot-checkout-modal" style="display: none; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: #ffffff; z-index: 100; overflow-y: auto;">
            <div style="padding: 16px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; position: sticky; top: 0; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(8px); padding-bottom: 12px; border-bottom: 1px solid #e5e7eb; z-index: 10;">
                    <h4 style="font-weight: 600; color: #111827; font-size: 1rem; margin: 0;">Thông tin giao hàng</h4>
                    <button @click="showCheckout = false" style="color: #6b7280; background: none; border: none; cursor: pointer; padding: 4px;">
                        <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form @submit.prevent="placeOrder()" style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 500; color: #374151; margin-bottom: 6px;">Họ và tên <span style="color: #ef4444;">*</span></label>
                        <input type="text" x-model="checkoutForm.customer_name" required style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.875rem; outline: none;" placeholder="Nguyễn Văn A" onfocus="this.style.borderColor='#10b981'" onblur="this.style.borderColor='#d1d5db'">
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 500; color: #374151; margin-bottom: 6px;">Số điện thoại <span style="color: #ef4444;">*</span></label>
                        <input type="tel" x-model="checkoutForm.shipping_phone" required pattern="^(0|\+84)[0-9]{9}$" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.875rem; outline: none;" placeholder="0912345678" onfocus="this.style.borderColor='#10b981'" onblur="this.style.borderColor='#d1d5db'">
                        <small style="color: #6b7280; font-size: 0.7rem;">10 số, bắt đầu bằng 0</small>
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 500; color: #374151; margin-bottom: 6px;">Địa chỉ giao hàng <span style="color: #ef4444;">*</span></label>
                        <textarea x-model="checkoutForm.shipping_address" required rows="3" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.875rem; outline: none; resize: vertical; min-height: 70px;" placeholder="Số nhà, đường, phường, quận, thành phố" onfocus="this.style.borderColor='#10b981'" onblur="this.style.borderColor='#d1d5db'"></textarea>
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 500; color: #374151; margin-bottom: 6px;">Thời gian giao hàng</label>
                        <select x-model="checkoutForm.delivery_time" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.875rem; outline: none; background: white; cursor: pointer;" onfocus="this.style.borderColor='#10b981'" onblur="this.style.borderColor='#d1d5db'">
                            <option value="anytime">Bất kỳ lúc nào</option>
                            <option value="morning">Buổi sáng (8h-12h)</option>
                            <option value="afternoon">Buổi chiều (13h-17h)</option>
                            <option value="evening">Buổi tối (18h-21h)</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 0.8rem; font-weight: 500; color: #374151; margin-bottom: 6px;">Ghi chú (tùy chọn)</label>
                        <textarea x-model="checkoutForm.order_notes" rows="2" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.875rem; outline: none; resize: vertical; min-height: 60px;" placeholder="Ghi chú thêm cho đơn hàng..." onfocus="this.style.borderColor='#10b981'" onblur="this.style.borderColor='#d1d5db'"></textarea>
                    </div>
                    
                    <div style="padding-top: 14px; border-top: 2px solid #e5e7eb; margin-top: 6px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                            <span style="font-weight: 600; color: #111827; font-size: 0.9rem;">Tổng thanh toán:</span>
                            <span style="font-weight: 700; font-size: 1.25rem; color: #059669;" x-text="formatCurrency(cart.total)"></span>
                        </div>
                        
                        <button type="submit" :disabled="isPlacingOrder" 
                                class="checkout-submit-btn"
                                :class="{ 'is-loading': isPlacingOrder }"
                                style="width: 100%; color: white; background: #059669; padding: 12px 16px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 0.9rem; transition: background 0.2s;">
                            <span x-show="!isPlacingOrder">
                                Xác nhận đặt hàng
                            </span>
                            <span x-show="isPlacingOrder" style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                                <svg class="spin-animation" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" stroke-dasharray="32" stroke-dashoffset="0" opacity="0.25"></circle>
                                    <path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                                </svg>
                                Đang xử lý...
                            </span>
                        </button>
                        


                        
                        <div style="text-align: center; margin-top: 12px;">
                            <div style="display: flex; align-items: center; justify-content: center; gap: 6px; color: #6b7280; font-size: 0.7rem;">
                                <svg style="width: 14px; height: 14px; color: #10b981;" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                </svg>
                                <span>Thanh toán an toàn & bảo mật</span>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Input Area -->
        <div class="chatbot-input">
            <!-- Image Preview -->
            <div x-show="imagePreviewUrl" class="image-preview-container" style="display: none;">
                <div class="image-preview-wrapper">
                    <img :src="imagePreviewUrl" alt="Preview" class="image-preview">
                    <button @click="clearAttachedImage()" class="btn-remove-image" title="Xóa ảnh">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div class="input-row">
                <input 
                    type="file" 
                    x-ref="imageInput" 
                    @change="handleImageUpload($event)" 
                    accept="image/jpeg,image/jpg,image/png"
                    class="hidden"
                >
                
                <button @click="$refs.imageInput.click()" class="btn-upload" title="Đính kèm hình ảnh" :class="{ 'has-image': attachedImage }">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </button>

                <input 
                    type="text" 
                    x-model="inputText" 
                    @keydown.enter="sendMessage()"
                    :placeholder="attachedImage ? 'Thêm mô tả cho ảnh (tùy chọn)...' : 'Nhập tin nhắn...'"
                    class="input-message"
                    :disabled="isLoading"
                >

                <button @click="sendMessage()" :disabled="isLoading || (!inputText.trim() && !attachedImage)" class="btn-send">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
[x-cloak] { display: none !important; }

.chatbot-widget-container,
.chatbot-widget-container * {
    box-sizing: border-box;
}

.chatbot-widget-container {
    position: fixed;
    bottom: 30px;
    right: 30px;
    z-index: 1040;
    min-height: 64px;
    min-width: 64px;
    pointer-events: none;
}

.chatbot-widget-container > * {
    pointer-events: auto;
}

.chatbot-widget-container.chatbot-active {
    z-index: 1045;
}

.chatbot-toggle-wrapper {
    position: absolute;
    bottom: 0;
    right: 0;
    display: flex;
    align-items: center;
    gap: 0;
}

/* Floating Label */
.chatbot-float-label {
    position: absolute;
    right: 74px;
    background: linear-gradient(135deg, #10b981 0%, #047857 100%);
    color: white;
    padding: 8px 16px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    white-space: nowrap;
    box-shadow: 0 4px 20px rgba(16, 185, 129, 0.35);
    animation: labelSlideIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) both, labelFloat 3s ease-in-out 0.6s infinite;
    pointer-events: none;
}

.float-label-arrow {
    position: absolute;
    right: -6px;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 0;
    border-top: 6px solid transparent;
    border-bottom: 6px solid transparent;
    border-left: 6px solid #059669;
}

@keyframes labelSlideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes labelFloat {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-3px); }
}

/* Toggle Button */
.chatbot-toggle-btn {
    position: relative;
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: linear-gradient(135deg, #10b981 0%, #047857 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 32px rgba(16, 185, 129, 0.35);
    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    border: 2px solid rgba(255, 255, 255, 0.4);
    animation: btnBounce 2.5s ease-in-out infinite;
    cursor: pointer;
}

@keyframes btnBounce {
    0%, 100% { transform: translateY(0); }
    15% { transform: translateY(-10px); }
    30% { transform: translateY(0); }
    42% { transform: translateY(-5px); }
    54% { transform: translateY(0); }
}

.chatbot-toggle-btn:hover {
    animation: none;
    transform: scale(1.1) translateY(-4px);
    box-shadow: 0 14px 40px rgba(16, 185, 129, 0.5);
    background: linear-gradient(135deg, #34d399 0%, #059669 100%);
}

/* Pulse Ring */
.chatbot-pulse-ring {
    position: absolute;
    top: -4px;
    left: -4px;
    right: -4px;
    bottom: -4px;
    border-radius: 50%;
    border: 2px solid rgba(16, 185, 129, 0.6);
    animation: pulseRing 2s ease-out infinite;
}

@keyframes pulseRing {
    0% {
        transform: scale(1);
        opacity: 0.8;
    }
    70% {
        transform: scale(1.5);
        opacity: 0;
    }
    100% {
        transform: scale(1.5);
        opacity: 0;
    }
}

.chatbot-window {
    width: 380px;
    height: 620px;
    background: rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(20px) saturate(140%);
    -webkit-backdrop-filter: blur(20px) saturate(140%);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.8);
    box-shadow: 0 16px 40px rgba(16, 185, 129, 0.15), inset 0 1px 0 rgba(255, 255, 255, 1);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    max-height: calc(100vh - 120px);
    max-width: calc(100vw - 60px);
}

.chatbot-window.checkout-mode {
    height: auto;
    max-height: calc(100vh - 120px);
}

.chatbot-header {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.85) 0%, rgba(4, 120, 87, 0.85) 100%);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid rgba(255, 255, 255, 0.2);
}

.chatbot-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px 24px;
    background: transparent;
}

.chatbot-messages::-webkit-scrollbar {
    width: 6px;
}
.chatbot-messages::-webkit-scrollbar-thumb {
    background: rgba(16, 185, 129, 0.2);
    border-radius: 10px;
}
.chatbot-messages::-webkit-scrollbar-thumb:hover {
    background: rgba(16, 185, 129, 0.4);
}

.message-user, .message-assistant {
    margin-bottom: 16px;
    display: flex;
}

.message-user {
    justify-content: flex-end;
}

.message-user .message-content {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    padding: 12px 16px;
    border-radius: 20px 20px 4px 20px;
    max-width: 80%;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}

.message-assistant .message-content {
    background: rgba(255, 255, 255, 0.85);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.6);
    color: #1f2937;
    padding: 14px 18px;
    border-radius: 20px 20px 20px 4px;
    max-width: 85%;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
}

.message-assistant.system-message .message-content {
    background: rgba(243, 244, 246, 0.6);
    color: #6b7280;
    font-size: 13px;
    font-style: italic;
    padding: 10px 14px;
    border-color: rgba(255, 255, 255, 0.4);
}

.chatbot-widget-container .product-card {
    display: flex;
    gap: 12px;
    padding: 12px;
    background: rgba(255, 255, 255, 0.7);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.8);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    transition: transform 0.2s, box-shadow 0.2s;
}

.chatbot-widget-container .product-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.1);
    background: rgba(255, 255, 255, 0.85);
}

.product-image {
    width: 64px;
    height: 64px;
    object-fit: cover;
    border-radius: 8px;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
}

.product-info {
    flex: 1;
}

.product-name {
    font-weight: 600;
    font-size: 14px;
    color: #111827;
    margin-bottom: 4px;
    line-height: 1.3;
}

.product-price {
    color: #059669;
    font-weight: 700;
    font-size: 15px;
    margin-bottom: 4px;
}

.product-stock, .product-expiry {
    font-size: 11px;
    color: #6b7280;
    margin-bottom: 2px;
}

.btn-add-cart {
    margin-top: 8px;
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s;
    border: 1px solid rgba(16, 185, 129, 0.2);
}

.btn-add-cart:hover {
    background: #10b981;
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
}

.chatbot-input {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 16px 20px;
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-top: 1px solid rgba(255, 255, 255, 0.6);
}

.image-preview-container {
    padding: 6px;
    background: #f0fdf4;
    border-radius: 6px;
    border: 1px dashed #86efac;
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.image-preview-wrapper {
    position: relative;
    display: inline-block;
}

.image-preview {
    max-width: 120px;
    max-height: 80px;
    border-radius: 4px;
    object-fit: cover;
    display: block;
}

.btn-remove-image {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 20px;
    height: 20px;
    background: #ef4444;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    transition: background 0.2s;
    cursor: pointer;
}

.btn-remove-image:hover {
    background: #dc2626;
}

.btn-remove-image svg {
    width: 12px;
    height: 12px;
}

.input-row {
    display: flex;
    gap: 10px;
    align-items: center;
    width: 100%;
}

.input-message {
    flex: 1;
    min-width: 0;
    width: 100%;
    padding: 12px 18px;
    border: 1px solid rgba(16, 185, 129, 0.2);
    border-radius: 24px;
    outline: none;
    font-size: 14px;
    background: rgba(255, 255, 255, 0.8);
    transition: all 0.2s;
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.02);
}

.input-message:focus {
    border-color: #10b981;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15), inset 0 2px 4px rgba(0, 0, 0, 0.02);
}

.btn-upload, .btn-send {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    flex-shrink: 0;
}

.btn-upload {
    background: rgba(243, 244, 246, 0.8);
    color: #4b5563;
    border: 1px solid rgba(229, 231, 235, 1);
}

.btn-upload:hover {
    background: #e5e7eb;
    transform: scale(1.05);
}

.btn-upload.has-image {
    background: rgba(16, 185, 129, 0.15);
    color: #059669;
    border-color: rgba(16, 185, 129, 0.3);
}

.btn-send {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-send:hover:not(:disabled) {
    transform: scale(1.08);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
}

.btn-send:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    background: #9ca3af;
    box-shadow: none;
    transform: none;
}

.checkout-submit-btn:hover:not(:disabled) {
    background: #047857 !important;
}

.checkout-submit-btn:disabled {
    opacity: 0.6;
    background: #9ca3af !important;
    cursor: not-allowed;
}

.spin-animation {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.markdown-body {
    font-size: 14px;
    line-height: 1.6;
    word-break: break-word;
}
.markdown-body p { margin-bottom: 8px; }
.markdown-body p:last-child { margin-bottom: 0; }
.markdown-body strong { font-weight: 700; color: #1a202c; }
.markdown-body em { font-style: italic; }
.markdown-body h1, .markdown-body h2, .markdown-body h3,
.markdown-body h4, .markdown-body h5, .markdown-body h6 { font-weight: 700; margin-top: 12px; margin-bottom: 6px; line-height: 1.3; }
.markdown-body h1 { font-size: 18px; }
.markdown-body h2 { font-size: 16px; }
.markdown-body h3 { font-size: 15px; }
.markdown-body ul, .markdown-body ol { margin: 6px 0; padding-left: 20px; }
.markdown-body ul { list-style-type: disc; }
.markdown-body ol { list-style-type: decimal; }
.markdown-body li { margin-bottom: 4px; }
.markdown-body li > ul, .markdown-body li > ol { margin-top: 4px; margin-bottom: 0; }
.markdown-body code { background: rgba(0, 0, 0, 0.06); padding: 2px 5px; border-radius: 3px; font-size: 13px; font-family: 'Courier New', monospace; }
.markdown-body pre { background: #2d3748; color: #e2e8f0; padding: 10px; border-radius: 6px; overflow-x: auto; margin: 8px 0; }
.markdown-body pre code { background: none; padding: 0; color: inherit; }
.markdown-body blockquote { border-left: 3px solid #10b981; padding-left: 10px; margin: 8px 0; color: #4a5568; font-style: italic; }
.markdown-body hr { border: none; border-top: 1px solid #e2e8f0; margin: 10px 0; }
.markdown-body a { color: #10b981; text-decoration: underline; }

/* Welcome Message Styles */
.chatbot-welcome {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 20px 10px 10px;
    animation: welcomeFadeIn 0.5s ease-out;
}

@keyframes welcomeFadeIn {
    from {
        opacity: 0;
        transform: translateY(15px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.welcome-icon {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.15) 0%, rgba(4, 120, 87, 0.1) 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 14px;
    color: #059669;
    animation: welcomePulse 2s ease-in-out infinite;
}

@keyframes welcomePulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

.welcome-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #111827;
    margin-bottom: 6px;
}

.welcome-subtitle {
    font-size: 0.82rem;
    color: #4b5563;
    margin-bottom: 14px;
    line-height: 1.5;
    max-width: 280px;
}

.welcome-features {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    margin-bottom: 16px;
}

.feature-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 14px;
    background: rgba(255, 255, 255, 0.7);
    border: 1px solid rgba(16, 185, 129, 0.12);
    border-radius: 10px;
    font-size: 0.8rem;
    color: #374151;
    transition: all 0.2s;
}

.feature-item:hover {
    background: rgba(16, 185, 129, 0.06);
    border-color: rgba(16, 185, 129, 0.25);
}

.feature-emoji {
    font-size: 1.1rem;
    flex-shrink: 0;
}

.welcome-hint {
    font-size: 0.75rem;
    color: #9ca3af;
    margin-bottom: 10px;
    font-style: italic;
}

.suggestion-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: center;
    width: 100%;
}

.suggestion-chip {
    padding: 8px 14px;
    background: rgba(16, 185, 129, 0.08);
    border: 1px solid rgba(16, 185, 129, 0.2);
    border-radius: 20px;
    font-size: 0.78rem;
    color: #047857;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    white-space: nowrap;
}

.suggestion-chip:hover {
    background: #10b981;
    color: white;
    border-color: #10b981;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
}

.suggestion-chip:active {
    transform: translateY(0) scale(0.97);
}

@media (max-width: 768px) {
    .chatbot-widget-container { 
        bottom: 80px; 
        right: 16px; 
    }
    .chatbot-widget-container.chatbot-active {
        bottom: 0;
        right: 0;
        width: 100%;
        display: flex;
        justify-content: flex-end;
    }
    .chatbot-window { 
        width: 100%; 
        height: calc(100vh - 60px); 
        height: calc(100dvh - 60px); 
        max-height: 100%; 
        max-width: 100%;
        border-radius: 24px 24px 0 0;
        border-bottom: none;
        border-left: none;
        border-right: none;
    }
    .chatbot-window.checkout-mode {
        height: calc(100vh - 60px);
        height: calc(100dvh - 60px);
    }
    .chatbot-toggle-btn { 
        width: 56px; 
        height: 56px; 
    }
    .product-card { 
        flex-direction: row; 
        align-items: stretch;
    }
    .product-image { 
        width: 80px; 
        height: 80px; 
    }
}
</style>



@include('components.chatbot-widget-script')
