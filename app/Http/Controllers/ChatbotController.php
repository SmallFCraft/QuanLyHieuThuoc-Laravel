<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use App\Services\ImageRecognitionService;
use App\Services\AuditLogService;
use App\Models\ChatbotImage;
use App\Models\ChatMessage;
use App\Models\Order;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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
        
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:2|max:1000',
            'context' => 'nullable|array'
        ], [
            'message.required' => 'Vui lòng nhập tin nhắn',
            'message.min' => 'Tin nhắn phải có ít nhất 2 ký tự',
            'message.max' => 'Tin nhắn không được vượt quá 1000 ký tự'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'validation_error' => true,
                'error' => (string) $validator->errors()->first('message'),
                'errors' => $validator->errors(),
            ]);
        }

        $validated = $validator->validated();
        $sessionId = null;

        try {
            $userId = auth()->id();
            $message = $validated['message'];
            $context = $validated['context'] ?? [];
            $userMessageSaved = false;
            
            // Get or create session ID
            $sessionId = $this->getSessionId();
            
            // Load previous messages from database
            $previousMessages = ChatMessage::where('MaKH', $userId)
                ->where('session_id', $sessionId)
                ->orderBy('created_at', 'desc')
                ->orderBy('MaTN', 'desc')
                ->limit(10)
                ->get()
                ->reverse()
                ->map(function($msg) {
                    return [
                        'role' => $msg->role,
                        'content' => $this->formatMessageContentForAiContext($msg),
                        'products' => $msg->products,
                        'metadata' => $msg->metadata,
                    ];
                })
                ->toArray();

            $nameMemoryMessages = ChatMessage::where('MaKH', $userId)
                ->where('session_id', $sessionId)
                ->where('role', 'user')
                ->orderBy('created_at', 'desc')
                ->orderBy('MaTN', 'desc')
                ->limit(100)
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
            $context['name_memory_messages'] = $nameMemoryMessages;
            $context['recent_image_products'] = $this->extractRecentImageProducts($previousMessages);
            $context['session_id'] = $sessionId;
            $context['current_session_id'] = $sessionId;
            
            // Process message through ChatbotService
            $response = $this->chatbotService->processMessage($message, $userId, $context);
            
            // Save messages to database if not a validation error
            if (!isset($response['validation_error'])) {
                // Save user message
                ChatMessage::create([
                    'MaKH' => $userId,
                    'session_id' => $sessionId,
                    'role' => 'user',
                    'content' => $message,
                    'products' => null,
                    'metadata' => null,
                    'show_disclaimer' => false
                ]);
                $userMessageSaved = true;
                
                // Save assistant response if successful
                if ($response['success']) {
                    ChatMessage::create([
                        'MaKH' => $userId,
                        'session_id' => $sessionId,
                        'role' => 'assistant',
                        'content' => $response['response'],
                        'products' => $response['products'] ?? null,
                        'metadata' => [
                            'explanations' => $response['explanations'] ?? [],
                            'categories' => $response['categories'] ?? [],
                            'disclaimer' => $response['disclaimer'] ?? null,
                            'response_type' => $response['response_type'] ?? null,
                            'debug' => $response['debug'] ?? null,
                        ],
                        'show_disclaimer' => $response['show_disclaimer'] ?? false
                    ]);
                }
            }
            
            return response()->json(array_merge($response, [
                'session_id' => $sessionId,
            ]));
            
        } catch (\Exception $e) {
            if ($sessionId) {
                if (! $userMessageSaved) {
                    ChatMessage::create([
                        'MaKH' => auth()->id(),
                        'session_id' => $sessionId,
                        'role' => 'user',
                        'content' => $validated['message'],
                        'products' => null,
                        'metadata' => null,
                        'show_disclaimer' => false,
                    ]);
                }

                ChatMessage::create([
                    'MaKH' => auth()->id(),
                    'session_id' => $sessionId,
                    'role' => 'system',
                    'content' => 'Không thể xử lý tin nhắn do lỗi hệ thống.',
                    'products' => null,
                    'metadata' => [
                        'error' => $e->getMessage(),
                    ],
                    'show_disclaimer' => false,
                ]);
            }

            Log::error('Chatbot message processing failed', [
                'MaKH' => auth()->id(),
                'session_id' => $sessionId,
                'message' => $validated['message'],
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Dạ, hiện tại em không thể xử lý tin nhắn này. Anh/chị vui lòng thử lại sau giây lát nhé.',
                'session_id' => $sessionId,
            ] + ($sessionId ? ['session_id' => $sessionId] : []), 500);
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
            'type' => 'nullable|in:prescription,receipt',
            'message' => 'nullable|string|max:1000'
        ], [
            'image.required' => 'Vui lòng chọn hình ảnh',
            'image.image' => 'File phải là hình ảnh',
            'image.mimes' => 'Chỉ hỗ trợ định dạng JPEG, PNG, JPG',
            'image.max' => 'Kích thước file không được vượt quá 5MB'
        ]);

        $sessionId = null;
        $userImageMessage = null;

        try {
            $userId = auth()->id();
            $image = $request->file('image');
            $type = $validated['type'] ?? 'prescription';
            $imageMessage = trim((string) ($validated['message'] ?? ''));
            $sessionId = $this->getSessionId();

            $userImageMessage = ChatMessage::create([
                'MaKH' => $userId,
                'session_id' => $sessionId,
                'role' => 'user',
                'content' => $imageMessage !== '' ? $imageMessage : 'Đã gửi hình ảnh',
                'products' => null,
                'metadata' => [
                    'image_upload' => [
                        'type' => $type,
                        'status' => 'processing',
                    ],
                ],
                'show_disclaimer' => false,
            ]);
            
            // Process image based on type
            if ($type === 'prescription') {
                $result = $this->imageRecognitionService->processPrescription($image, $userId);
            } else {
                $result = $this->imageRecognitionService->processReceipt($image, $userId);
            }

            if (!$result['success']) {
                $userImageMessage->update([
                    'metadata' => [
                        'image_upload' => [
                            'type' => $type,
                            'status' => 'failed',
                            'error' => $result['error'] ?? null,
                        ],
                    ],
                ]);

                return response()->json(array_merge($result, [
                    'session_id' => $sessionId,
                ]), 400);
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

            $userImageMessage->update([
                'metadata' => [
                    'image_upload' => [
                        'type' => $type,
                        'status' => 'processed',
                        'recognized_count' => count($recognizedProducts),
                        'ocr_text' => $result['ocr_text'] ?? null,
                    ],
                ],
            ]);
            
            // Answer the caption ("đây là thuốc gì?") from what the image says,
            // falling back to the deterministic recognition summary.
            $imageAnswer = $this->chatbotService->answerImageQuestion(
                $imageMessage,
                (string) ($result['ocr_text'] ?? ''),
                $result['parsed_products'] ?? [],
                $recognizedProducts
            );
            $messageContent = trim((string) ($imageAnswer['response'] ?? '')) !== ''
                ? $imageAnswer['response']
                : $this->buildImageRecognitionMessageContent($recognizedProducts);

            ChatMessage::create([
                'MaKH' => $userId,
                'session_id' => $sessionId,
                'role' => 'assistant',
                'content' => $messageContent,
                'products' => $recognizedProducts,
                'metadata' => [
                    'draft_order' => $draftOrder,
                    'ocr_text' => $result['ocr_text'] ?? null,
                    'disclaimer' => $imageAnswer['disclaimer'] ?? null,
                    'debug' => $imageAnswer['debug'] ?? null,
                ],
                'show_disclaimer' => (bool) ($imageAnswer['show_disclaimer'] ?? false)
            ]);

            return response()->json([
                'success' => true,
                'response' => $messageContent,
                'show_disclaimer' => (bool) ($imageAnswer['show_disclaimer'] ?? false),
                'disclaimer' => $imageAnswer['disclaimer'] ?? null,
                'recognized_products' => $recognizedProducts,
                'draft_order' => $draftOrder,
                'ocr_text' => $result['ocr_text'] ?? null,
                'processing_time_ms' => $result['processing_time_ms'] ?? null,
                'session_id' => $sessionId,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Image upload processing failed', [
                'MaKH' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            if ($userImageMessage) {
                $userImageMessage->update([
                    'metadata' => [
                        'image_upload' => [
                            'type' => $validated['type'] ?? 'prescription',
                            'status' => 'failed',
                            'error' => $e->getMessage(),
                        ],
                    ],
                ]);
            }
            
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
            $sessionId = session('chat_session_id');
            $limit = max(1, min($request->integer('limit', self::MAX_HISTORY_MESSAGES), self::MAX_HISTORY_MESSAGES));

            if (!$sessionId) {
                return response()->json([
                    'success' => true,
                    'messages' => [],
                    'count' => 0,
                    'session_id' => null,
                ]);
            }
            
            $messages = ChatMessage::where('MaKH', $userId)
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
            
            $count = count($messages);

            return response()->json([
                'success' => true,
                'messages' => $messages,
                'count' => $count,
                'session_id' => $count > 0 ? $sessionId : null,
            ]);
        } catch (\Exception $e) {
            Log::error('Get chat history failed', [
                'MaKH' => auth()->id(),
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
                ChatMessage::where('MaKH', $userId)
                    ->where('session_id', $sessionId)
                    ->delete();

                ChatbotImage::where('MaKH', $userId)
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
                'message' => 'Lịch sử trò chuyện đã được xóa',
                'session_id' => null,
            ]);
        } catch (\Exception $e) {
            Log::error('Clear chat history failed', [
                'MaKH' => auth()->id(),
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
            'MaSP' => 'required|integer|exists:products,MaSP',
            'quantity' => 'nullable|integer|min:1|max:99'
        ], [
            'MaSP.required' => 'Vui lòng chọn sản phẩm.',
            'MaSP.exists' => 'Sản phẩm không tồn tại.',
            'quantity.min' => 'Số lượng phải ít nhất là 1.',
            'quantity.max' => 'Số lượng tối đa là 99.'
        ]);

        try {
            $product = \App\Models\Product::findOrFail($validated['MaSP']);
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
            $currentQty = $cart[$product->MaSP] ?? 0;
            $newQty = $currentQty + $quantity;

            if ($newQty > $product->stock_quantity) {
                return response()->json([
                    'success' => false,
                    'message' => "Số lượng vượt quá tồn kho. Đã có {$currentQty} trong giỏ, còn lại {$product->stock_quantity}."
                ], 422);
            }

            $cart[$product->MaSP] = $newQty;
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
                'MaSP' => $validated['MaSP']
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
            'MaSP' => 'required|integer|exists:products,MaSP',
            'quantity' => 'required|integer|min:0|max:99'
        ]);

        try {
            $product = \App\Models\Product::findOrFail($validated['MaSP']);
            $quantity = $validated['quantity'];
            
            $cart = session()->get('cart', []);
            
            // Remove if quantity is 0
            if ($quantity === 0) {
                unset($cart[$product->MaSP]);
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
            
            $cart[$product->MaSP] = $quantity;
            session()->put('cart', $cart);
            
            return response()->json([
                'success' => true,
                'message' => 'Đã cập nhật giỏ hàng.',
                'cart' => $this->getCartSummary()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Update cart item failed', [
                'error' => $e->getMessage(),
                'MaSP' => $validated['MaSP']
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

        $userId = (int) auth()->id();
        $lock = Cache::lock($this->checkoutLockKey($userId), 10);

        try {
            return $lock->block(3, function () use ($validated, $userId) {
                $cart = session()->get('cart', []);

                if (empty($cart)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Giỏ hàng trống. Vui lòng thêm sản phẩm trước khi đặt hàng.'
                    ], 422);
                }

                $completedKey = $this->completedCheckoutKey($userId, $cart);
                $completedOrderId = Cache::get($completedKey);

                if ($completedOrderId) {
                    $completedOrder = Order::where('MaKH', $userId)->find($completedOrderId);

                    if ($completedOrder) {
                        session()->forget('cart');

                        return response()->json([
                            'success' => true,
                            'message' => 'Đơn hàng đã được xử lý.',
                            'order' => $this->formatOrderPayload($completedOrder)
                        ]);
                    }

                    Cache::forget($completedKey);
                }

                // Use existing CheckoutService for transaction-safe order creation
                $checkoutService = app(\App\Services\CheckoutService::class);
                $order = $checkoutService->processCheckout(
                    $this->normalizeCartItems($cart),
                    $userId,
                    [
                        'address' => $validated['shipping_address'],
                        'phone' => $validated['shipping_phone'],
                        'customer_name' => $validated['customer_name'],
                        'delivery_time' => $validated['delivery_time'] ?? null,
                        'notes' => $validated['order_notes'] ?? null
                    ]
                );

                Cache::put($completedKey, $order->MaDH, now()->addMinutes(10));
                session()->forget('cart');

                // Log audit
                AuditLogService::log(
                    action: 'chatbot_order_placed',
                    modelType: Order::class,
                    modelId: $order->MaDH,
                    oldValues: null,
                    newValues: [
                        'MaKH' => $userId,
                        'source' => 'chatbot',
                        'MaDH' => $order->MaDH,
                        'total_amount' => $order->total_amount
                    ]
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Đặt hàng thành công!',
                    'order' => $this->formatOrderPayload($order)
                ]);
            });

        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn hàng đang được xử lý. Vui lòng chờ trong giây lát.'
            ], 429);
        } catch (\Exception $e) {
            Log::error('Chatbot place order failed', [
                'MaKH' => $userId,
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

        $products = \App\Models\Product::whereIn('MaSP', array_keys($cart))
            ->notExpired()
            ->get()
            ->keyBy('MaSP');

        $items = [];
        $total = 0;

        foreach ($cart as $productId => $quantity) {
            if (isset($products[$productId])) {
                $product = $products[$productId];
                $subtotal = $product->price_vnd * $quantity;
                $total += $subtotal;
                $items[] = [
                    'MaSP' => $product->MaSP,
                    'name' => $product->name,
                    'price' => $product->price_vnd,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'stock_quantity' => $product->stock_quantity,
                    'image_url' => $product->image_url
                ];
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'count' => count($items)
        ];
    }

    private function checkoutLockKey(int $userId): string
    {
        return "checkout:user:{$userId}";
    }

    private function completedCheckoutKey(int $userId, array $cart): string
    {
        return "checkout:completed:{$userId}:" . $this->cartHash($cart);
    }

    private function cartHash(array $cart): string
    {
        ksort($cart);

        return hash('sha256', json_encode($cart));
    }

    private function normalizeCartItems(array $cart): array
    {
        $cartItems = [];

        foreach ($cart as $productId => $quantity) {
            $cartItems[] = [
                'MaSP' => (int) $productId,
                'quantity' => (int) $quantity
            ];
        }

        return $cartItems;
    }

    private function formatOrderPayload(Order $order): array
    {
        return [
            'id' => $order->MaDH,
            'total_amount' => $order->total_amount,
            'status' => $order->status,
            'created_at' => $order->created_at->format('d/m/Y H:i')
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

    protected function formatMessageContentForAiContext(ChatMessage $message): string
    {
        $content = trim((string) $message->content);
        $productNames = $this->extractProductNamesFromRecognitionPayload($message->products ?? []);

        if ($message->role === 'assistant' && !empty($productNames)) {
            $content .= "\n\nSản phẩm đã nhận dạng từ ảnh: " . implode(', ', $productNames) . '.';
            $content .= "\nNếu người dùng hỏi \"thuốc đó\", \"thuốc này\", \"sản phẩm đó\" hoặc \"sản phẩm này\", hãy hiểu là đang hỏi về các sản phẩm này.";
        }

        return trim($content);
    }

    protected function extractRecentImageProducts(array $messages): array
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index];
            if (!is_array($message) || ($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            $products = $message['products'] ?? [];
            if (is_array($products) && !empty($products)) {
                return $products;
            }
        }

        return [];
    }

    protected function buildImageRecognitionMessageContent(array $recognizedProducts): string
    {
        $count = count($recognizedProducts);
        $productNames = $this->extractProductNamesFromRecognitionPayload($recognizedProducts);
        $matchedCount = count(array_filter(
            $recognizedProducts,
            fn ($item): bool => is_array($item) && ($item['matched'] ?? false) === true
        ));
        $content = "Đã nhận dạng được {$count} sản phẩm từ hình ảnh.";

        if (!empty($productNames)) {
            $content .= "\nSản phẩm đã nhận dạng: " . implode(', ', $productNames) . '.';
        }

        if ($matchedCount > 0) {
            $content .= "\nNếu anh/chị hỏi \"thuốc đó\" hoặc \"sản phẩm đó\", em sẽ hiểu là đang hỏi về sản phẩm này.";
        } elseif ($count > 0) {
            $content .= "\nHiện em chưa thấy sản phẩm nào khớp trong kho, em chỉ có thể gợi ý sản phẩm tương tự để anh/chị tham khảo.";
        }

        return $content;
    }

    protected function extractProductNamesFromRecognitionPayload($products): array
    {
        if (!is_array($products)) {
            return [];
        }

        $names = [];
        foreach ($products as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = $item['product'] ?? null;
            $name = is_array($product)
                ? trim((string) ($product['name'] ?? ''))
                : trim((string) ($item['parsed_name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
