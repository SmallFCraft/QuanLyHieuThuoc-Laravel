<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use App\Services\ImageRecognitionService;
use App\Services\AuditLogService;
use App\Models\ChatbotImage;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChatbotController extends Controller
{
    private const MAX_HISTORY_MESSAGES = 50;

    protected ChatbotService $chatbotService;
    protected ImageRecognitionService $imageRecognitionService;

    public function __construct(
        ChatbotService $chatbotService,
        ImageRecognitionService $imageRecognitionService
    ) {
        $this->chatbotService = $chatbotService;
        $this->imageRecognitionService = $imageRecognitionService;
    }

    /**
     * Send a message to the chatbot and receive AI response.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendMessage(Request $request): JsonResponse
    {
        // Check authentication first
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'error' => 'Vui lòng đăng nhập để sử dụng chatbot.',
                'require_login' => true,
                'login_url' => route('login')
            ], 401);
        }
        
        $validated = $request->validate([
            'message' => 'required|string|min:2|max:1000',
            'context' => 'nullable|array'
        ], [
            'message.required' => 'Vui lòng nhập tin nhắn',
            'message.min' => 'Tin nhắn phải có ít nhất 2 ký tự',
            'message.max' => 'Tin nhắn không được vượt quá 1000 ký tự'
        ]);

        try {
            $userId = auth()->id();
            $message = $validated['message'];
            $context = $validated['context'] ?? [];
            
            // Get or create session ID
            $sessionId = $this->getSessionId();
            
            // Load previous messages from database
            $previousMessages = ChatMessage::where('user_id', $userId)
                ->where('session_id', $sessionId)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->reverse()
                ->map(function($msg) {
                    return [
                        'role' => $msg->role,
                        'content' => $msg->content
                    ];
                })
                ->toArray();
            
            $context['previous_messages'] = $previousMessages;
            
            // Process message through ChatbotService
            $response = $this->chatbotService->processMessage($message, $userId, $context);
            
            // Save messages to database if not a validation error
            if (!isset($response['validation_error'])) {
                // Save user message
                ChatMessage::create([
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'role' => 'user',
                    'content' => $message,
                    'products' => null,
                    'metadata' => null,
                    'show_disclaimer' => false
                ]);
                
                // Save assistant response if successful
                if ($response['success']) {
                    ChatMessage::create([
                        'user_id' => $userId,
                        'session_id' => $sessionId,
                        'role' => 'assistant',
                        'content' => $response['response'],
                        'products' => $response['products'] ?? null,
                        'metadata' => [
                            'explanations' => $response['explanations'] ?? [],
                            'categories' => $response['categories'] ?? [],
                            'disclaimer' => $response['disclaimer'] ?? null,
                        ],
                        'show_disclaimer' => $response['show_disclaimer'] ?? false
                    ]);
                }
            }
            
            return response()->json($response);
            
        } catch (\Exception $e) {
            Log::error('Chatbot message processing failed', [
                'user_id' => auth()->id(),
                'message' => $validated['message'],
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Dạ, hiện tại em không thể xử lý tin nhắn này. Anh/chị vui lòng thử lại sau giây lát nhé.'
            ], 500);
        }
    }

    /**
     * Upload and process prescription or receipt image.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadImage(Request $request): JsonResponse
    {
        // Check authentication first
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'error' => 'Vui lòng đăng nhập để tải ảnh lên.',
                'require_login' => true,
                'login_url' => route('login')
            ], 401);
        }
        
        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:5120', // 5MB
            'type' => 'nullable|in:prescription,receipt'
        ], [
            'image.required' => 'Vui lòng chọn hình ảnh',
            'image.image' => 'File phải là hình ảnh',
            'image.mimes' => 'Chỉ hỗ trợ định dạng JPEG, PNG, JPG',
            'image.max' => 'Kích thước file không được vượt quá 5MB'
        ]);

        try {
            $userId = auth()->id();
            $image = $request->file('image');
            $type = $validated['type'] ?? 'prescription';
            
            // Process image based on type
            if ($type === 'prescription') {
                $result = $this->imageRecognitionService->processPrescription($image, $userId);
            } else {
                $result = $this->imageRecognitionService->processReceipt($image, $userId);
            }
            
            if (!$result['success']) {
                return response()->json($result, 400);
            }
            
            // Create draft order from recognized products
            $recognizedProducts = $result['recognized_products'] ?? [];
            $draftOrder = null;
            
            if (!empty($recognizedProducts)) {
                $draftOrderResult = $this->chatbotService->createDraftOrder($recognizedProducts, $userId);
                
                if ($draftOrderResult['success']) {
                    $draftOrder = $draftOrderResult['draft_order'];
                }
            }
            
            // Get or create session ID
            $sessionId = $this->getSessionId();
            
            // Save assistant message to database
            $messageContent = "Đã nhận dạng được " . count($recognizedProducts) . " sản phẩm từ hình ảnh.";
            ChatMessage::create([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'role' => 'assistant',
                'content' => $messageContent,
                'products' => $recognizedProducts,
                'metadata' => [
                    'draft_order' => $draftOrder,
                    'ocr_text' => $result['ocr_text'] ?? null
                ],
                'show_disclaimer' => false
            ]);
            
            return response()->json([
                'success' => true,
                'recognized_products' => $recognizedProducts,
                'draft_order' => $draftOrder,
                'ocr_text' => $result['ocr_text'] ?? null,
                'processing_time_ms' => $result['processing_time_ms'] ?? null
            ]);
            
        } catch (\Exception $e) {
            Log::error('Image upload processing failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Dạ, em gặp chút sự cố khi tải ảnh lên. Anh/chị vui lòng kiểm tra lại kết nối mạng hoặc thử lại với ảnh khác giúp em nhé.'
            ], 500);
        }
    }

    /**
     * Retrieve chat history for current session.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getHistory(Request $request): JsonResponse
    {
        try {
            $userId = auth()->id();
            $sessionId = $this->getSessionId();
            $limit = max(1, min($request->integer('limit', self::MAX_HISTORY_MESSAGES), self::MAX_HISTORY_MESSAGES));
            
            $messages = ChatMessage::where('user_id', $userId)
                ->where('session_id', $sessionId)
                ->latest('created_at')
                ->limit($limit)
                ->get([
                    'role',
                    'content',
                    'products',
                    'metadata',
                    'show_disclaimer',
                    'created_at',
                ])
                ->reverse()
                ->values()
                ->map(function($msg) {
                    return [
                        'role' => $msg->role,
                        'content' => $msg->content,
                        'products' => $msg->products,
                        'categories' => $msg->metadata['categories'] ?? [],
                        'show_disclaimer' => $msg->show_disclaimer,
                        'disclaimer' => $msg->metadata['disclaimer'] ?? null,
                        'created_at' => $msg->created_at->format('Y-m-d H:i:s')
                    ];
                })
                ->toArray();
            
            return response()->json([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages),
                'session_id' => $sessionId
            ]);
        } catch (\Exception $e) {
            Log::error('Get chat history failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'messages' => [],
                'count' => 0
            ]);
        }
    }

    /**
     * Clear chat history for current session.
     *
     * @param Request $request
     * @return JsonResponse
     */
    /**
     * Clear chat history for current session.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function clearHistory(Request $request): JsonResponse
    {
        try {
            $userId = auth()->id();
            $sessionId = session('chat_session_id');
            
            if ($sessionId) {
                ChatMessage::where('user_id', $userId)
                    ->where('session_id', $sessionId)
                    ->delete();

                ChatbotImage::where('user_id', $userId)
                    ->where('session_id', $sessionId)
                    ->chunkById(100, function ($images) {
                        foreach ($images as $image) {
                            $path = $image->file_path ?? $image->image_path;

                            if ($path && Storage::disk('local')->exists($path)) {
                                Storage::disk('local')->delete($path);
                            }

                            $image->forceDelete();
                        }
                    });
            }

            session()->forget(['chat_session_id', 'chatbot.messages']);
            
            return response()->json([
                'success' => true,
                'message' => 'Lịch sử trò chuyện đã được xóa'
            ]);
        } catch (\Exception $e) {
            Log::error('Clear chat history failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa lịch sử'
            ], 500);
        }
    }

    /**
     * Add product to cart from chatbot.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function addToCart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'nullable|integer|min:1|max:99'
        ], [
            'product_id.required' => 'Vui lòng chọn sản phẩm.',
            'product_id.exists' => 'Sản phẩm không tồn tại.',
            'quantity.min' => 'Số lượng phải ít nhất là 1.',
            'quantity.max' => 'Số lượng tối đa là 99.'
        ]);

        try {
            $product = \App\Models\Product::findOrFail($validated['product_id']);
            $quantity = $validated['quantity'] ?? 1;

            // Validate product
            if ($product->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sản phẩm đã hết hạn.'
                ], 422);
            }

            if ($product->stock_quantity < $quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Không đủ hàng. Chỉ còn {$product->stock_quantity} sản phẩm."
                ], 422);
            }

            // Add to session cart (same cart used by CartController)
            $cart = session()->get('cart', []);
            $currentQty = $cart[$product->id] ?? 0;
            $newQty = $currentQty + $quantity;

            if ($newQty > $product->stock_quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Số lượng vượt quá tồn kho. Đã có {$currentQty} trong giỏ, còn lại {$product->stock_quantity}."
                ], 422);
            }

            $cart[$product->id] = $newQty;
            session()->put('cart', $cart);

            // Build cart summary for response
            $cartSummary = $this->getCartSummary();

            return response()->json([
                'success' => true,
                'message' => "Đã thêm {$quantity} x {$product->name} vào giỏ hàng.",
                'cart' => $cartSummary
            ]);

        } catch (\Exception $e) {
            Log::error('Chatbot add to cart failed', [
                'error' => $e->getMessage(),
                'product_id' => $validated['product_id']
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể thêm sản phẩm. Vui lòng thử lại.'
            ], 500);
        }
    }
    
    /**
     * Get current cart summary.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCart(Request $request): JsonResponse
    {
        try {
            $cartSummary = $this->getCartSummary();
            
            return response()->json([
                'success' => true,
                'cart' => $cartSummary
            ]);
        } catch (\Exception $e) {
            Log::error('Get cart failed', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => 'Không thể tải giỏ hàng.'
            ], 500);
        }
    }
    
    /**
     * Update product quantity in cart.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateCartItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:0|max:99'
        ]);

        try {
            $product = \App\Models\Product::findOrFail($validated['product_id']);
            $quantity = $validated['quantity'];
            
            $cart = session()->get('cart', []);
            
            // Remove if quantity is 0
            if ($quantity === 0) {
                unset($cart[$product->id]);
                session()->put('cart', $cart);
                
                return response()->json([
                    'success' => true,
                    'message' => "Đã xóa {$product->name} khỏi giỏ hàng.",
                    'cart' => $this->getCartSummary()
                ]);
            }
            
            // Validate stock
            if ($product->stock_quantity < $quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Không đủ hàng. Chỉ còn {$product->stock_quantity} sản phẩm."
                ], 422);
            }
            
            $cart[$product->id] = $quantity;
            session()->put('cart', $cart);
            
            return response()->json([
                'success' => true,
                'message' => 'Đã cập nhật giỏ hàng.',
                'cart' => $this->getCartSummary()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Update cart item failed', [
                'error' => $e->getMessage(),
                'product_id' => $validated['product_id']
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Không thể cập nhật giỏ hàng.'
            ], 500);
        }
    }

    /**
     * Place order directly from chatbot.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function placeOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shipping_address' => 'required|string|max:500',
            'shipping_phone' => ['required', 'regex:/^(\\+84|0)[0-9]{9}$/'],
            'customer_name' => 'required|string|max:255',
            'delivery_time' => 'nullable|string|in:anytime,morning,afternoon,evening',
            'order_notes' => 'nullable|string|max:1000'
        ], [
            'shipping_address.required' => 'Địa chỉ giao hàng là bắt buộc.',
            'shipping_phone.required' => 'Số điện thoại là bắt buộc.',
            'shipping_phone.regex' => 'Số điện thoại không hợp lệ (10 số, bắt đầu bằng 0 hoặc +84).',
            'customer_name.required' => 'Họ và tên là bắt buộc.'
        ]);

        try {
            $cart = session()->get('cart', []);

            if (empty($cart)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Giỏ hàng trống. Vui lòng thêm sản phẩm trước khi đặt hàng.'
                ], 422);
            }

            // Prepare cart items for CheckoutService
            $cartItems = [];
            foreach ($cart as $productId => $quantity) {
                $cartItems[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity
                ];
            }

            // Use existing CheckoutService for transaction-safe order creation
            $checkoutService = app(\App\Services\CheckoutService::class);
            $order = $checkoutService->processCheckout(
                $cartItems,
                auth()->id(),
                [
                    'address' => $validated['shipping_address'],
                    'phone' => $validated['shipping_phone'],
                    'customer_name' => $validated['customer_name'],
                    'delivery_time' => $validated['delivery_time'] ?? null,
                    'notes' => $validated['order_notes'] ?? null
                ]
            );

            // Clear cart after successful order
            session()->forget('cart');

            // Log audit
            AuditLogService::log(
                action: 'chatbot_order_placed',
                modelType: \App\Models\Order::class,
                modelId: $order->id,
                oldValues: null,
                newValues: [
                    'user_id' => auth()->id(),
                    'source' => 'chatbot',
                    'order_id' => $order->id,
                    'total_amount' => $order->total_amount
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Đặt hàng thành công!',
                'order' => [
                    'id' => $order->id,
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'created_at' => $order->created_at->format('d/m/Y H:i')
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Chatbot place order failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get cart summary with product details.
     *
     * @return array
     */
    protected function getCartSummary(): array
    {
        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return ['items' => [], 'total' => 0, 'count' => 0];
        }

        $products = \App\Models\Product::whereIn('id', array_keys($cart))
            ->notExpired()
            ->get()
            ->keyBy('id');

        $items = [];
        $total = 0;

        foreach ($cart as $productId => $quantity) {
            if (isset($products[$productId])) {
                $product = $products[$productId];
                $subtotal = $product->price * $quantity;
                $total += $subtotal;
                $items[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'stock_quantity' => $product->stock_quantity,
                    'image_url' => $product->image ? asset('storage/' . $product->image) : null
                ];
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'count' => count($items)
        ];
    }
    
    /**
     * Add message to session storage.
     *
     * @param array $message
     * @return void
     */
    protected function addMessageToSession(array $message): void
    {
        $messages = session('chatbot.messages', []);
        $messages[] = $message;
        
        if (count($messages) > self::MAX_HISTORY_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_HISTORY_MESSAGES);
        }
        
        session(['chatbot.messages' => $messages]);
    }
    
    /**
     * Get or create session ID for current user.
     *
     * @return string
     */
    protected function getSessionId(): string
    {
        $sessionId = session('chat_session_id');
        
        if (!$sessionId) {
            $sessionId = Str::uuid()->toString();
            session(['chat_session_id' => $sessionId]);
        }
        
        return $sessionId;
    }
}
