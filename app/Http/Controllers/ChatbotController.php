<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use App\Services\AiJobService;
use App\Services\DrugSafetyService;
use App\Services\ImageRecognitionService;
use App\Services\AuditLogService;
use App\Models\AiJob;
use App\Models\ChatbotImage;
use App\Models\ChatMessage;
use App\Models\Order;
use App\Support\CartLine;
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
     * Hand a chatbot turn to a queue worker and answer immediately.
     *
     * The AI call used to run inline, holding a PHP process for up to AI_TIMEOUT
     * plus the fallback provider. With a single-process dev server that blocks
     * every other page, so one customer waiting on the bot froze the whole site.
     *
     * The response shape depends on the queue driver, deliberately:
     *  - a worker is running  -> 202 with a job id to poll;
     *  - the `sync` driver    -> the job already finished, so the full answer is
     *                            returned here exactly as before.
     */
    public function sendMessage(Request $request, AiJobService $aiJobs): JsonResponse
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
        $userId = (int) auth()->id();
        $sessionId = $this->getSessionId();

        // The browser asks for this when polling showed no worker ever picked the
        // previous job up. The stranded job is retired first so a late worker
        // cannot post a second answer for the same question.
        $sync = $request->boolean('sync');

        if ($sync) {
            $this->abandonStrandedJob($request, $aiJobs, $userId);
        }

        $job = $aiJobs->dispatchChat(
            $userId,
            $validated['message'],
            $this->buildContext($request, $userId, $sessionId),
            $sessionId,
            $sync
        );

        if ($job->status === 'done') {
            return response()->json(array_merge((array) $job->result, [
                'session_id' => $sessionId,
            ]));
        }

        if ($job->status === 'failed') {
            return response()->json([
                'success' => false,
                'error' => 'Dạ, hiện tại em không thể xử lý tin nhắn này. Anh/chị vui lòng thử lại sau giây lát nhé.',
                'session_id' => $sessionId,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'queued' => true,
            'job_id' => $job->uuid,
            'poll_url' => route('ai-jobs.poll', $job->uuid),
            'session_id' => $sessionId,
        ], 202);
    }

    /**
     * Progress of any queued AI job — a chat turn or a triage advice rewrite.
     *
     * The uuid is a public identifier, not a capability token: AiJobService scopes
     * every lookup to the signed-in customer, so another user's job is a 404.
     */
    public function pollJob(Request $request, AiJobService $aiJobs, string $uuid): JsonResponse
    {
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'error' => 'Vui lòng đăng nhập để sử dụng chatbot.',
                'require_login' => true,
                'login_url' => route('login'),
            ], 401);
        }

        $state = $aiJobs->poll($uuid, (int) auth()->id());

        if ($state === null) {
            return response()->json([
                'success' => false,
                'error' => 'Không tìm thấy yêu cầu này.',
            ], 404);
        }

        if ($state['status'] === 'done') {
            return response()->json(array_merge((array) $state['result'], [
                'status' => 'done',
                'session_id' => session('chat_session_id'),
            ]));
        }

        if ($state['status'] === 'failed') {
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'error' => 'Dạ, hiện tại em không thể xử lý tin nhắn này. Anh/chị vui lòng thử lại sau giây lát nhé.',
            ]);
        }

        // The customer pressed stop. Not an error: the browser already removed the
        // pending bubble, so it only needs to know it can stop polling.
        if ($state['status'] === 'cancelled') {
            return response()->json([
                'success' => false,
                'status' => 'cancelled',
                'cancelled' => true,
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $state['status'],
            'stranded' => $state['stranded'],
            'elapsed_ms' => $state['elapsed_ms'],
        ]);
    }

    /**
     * Stop a turn the customer no longer wants.
     *
     * Aborting the browser's fetch alone would leave the worker running and its answer
     * landing in `chat_messages` — the customer would find a reply to a question they
     * cancelled on their next reload. Retiring the row is what makes the stop real.
     */
    public function cancelJob(Request $request, AiJobService $aiJobs, string $uuid): JsonResponse
    {
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'error' => 'Vui lòng đăng nhập để sử dụng chatbot.',
                'require_login' => true,
                'login_url' => route('login'),
            ], 401);
        }

        // Scoped to the signed-in customer: the uuid is a public identifier, so without
        // this check anyone could kill another customer's turn.
        $job = AiJob::where('uuid', $uuid)->where('MaKH', (int) auth()->id())->first();

        if ($job === null) {
            return response()->json([
                'success' => false,
                'error' => 'Không tìm thấy yêu cầu này.',
            ], 404);
        }

        $aiJobs->cancel($job);

        return response()->json([
            'success' => true,
            'status' => $job->refresh()->status,
        ]);
    }

    protected function abandonStrandedJob(Request $request, AiJobService $aiJobs, int $userId): void
    {
        $uuid = trim((string) $request->input('abandon_job_id'));

        if ($uuid === '') {
            return;
        }

        $stranded = AiJob::where('uuid', $uuid)->where('MaKH', $userId)->first();

        if ($stranded !== null) {
            $aiJobs->abandon($stranded, 'no worker picked the job up, ran synchronously');
        }
    }

    /**
     * Conversation context for the AI turn.
     *
     * Session id and history come from the server, never from the request body:
     * a client-supplied session id would let one customer read another's thread.
     *
     * Both lists must stay sequential lists in chronological order. reverse() alone
     * keeps the descending keys, which json_encode turns into an object, and the
     * `ai_jobs.payload` json column returns object keys sorted ascending - the queue
     * worker then handed the model the whole conversation newest-first, so a short
     * follow-up ("có ổn không?") was answered against the oldest message in the
     * window instead of the newest.
     *
     * @return array<string, mixed>
     */
    protected function buildContext(Request $request, int $userId, string $sessionId): array
    {
        $context = (array) $request->input('context', []);

        $previousMessages = ChatMessage::where('MaKH', $userId)
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'desc')
            ->orderBy('MaTN', 'desc')
            ->limit(10)
            ->get()
            ->reverse()
            ->values()
            ->map(function ($msg) {
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
            ->values()
            ->map(function ($msg) {
                return [
                    'role' => $msg->role,
                    'content' => $msg->content,
                ];
            })
            ->toArray();

        $context['previous_messages'] = $previousMessages;
        $context['name_memory_messages'] = $nameMemoryMessages;
        $context['recent_image_products'] = $this->extractRecentImageProducts($previousMessages);
        $context['session_id'] = $sessionId;
        $context['current_session_id'] = $sessionId;

        return $context;
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
        
        $maxSizeKb = (int) config('chatbot.images.max_size', 5120);
        $allowedTypes = implode(',', (array) config('chatbot.images.allowed_types', ['jpeg', 'jpg', 'png']));

        $validated = $request->validate([
            'image' => "required|image|mimes:{$allowedTypes}|max:{$maxSizeKb}",
            'type' => 'nullable|in:prescription,receipt',
            'message' => 'nullable|string|max:1000'
        ], [
            'image.required' => 'Vui lòng chọn hình ảnh',
            'image.image' => 'File phải là hình ảnh',
            'image.mimes' => 'Chỉ hỗ trợ định dạng JPEG, PNG, JPG',
            'image.max' => 'Kích thước file không được vượt quá ' . round($maxSizeKb / 1024, 1) . 'MB'
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
                            // The file was stored before analysis ran, so a rejected image
                            // still has bytes to show after a reload.
                            'image_id' => $this->imageRecognitionService->lastStoredImageId(),
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
                        // Lets chat history re-render the upload after a reload. The live
                        // bubble shows a blob: URL, which dies with the page.
                        'image_id' => $result['image_id'] ?? $this->imageRecognitionService->lastStoredImageId(),
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
                // Flat catalog rows, the same shape the text path stores and the widget
                // renders. Storing matchProducts()'s wrapper here made every product card
                // read undefined after a reload and show "0 đ" with a placeholder icon.
                'products' => $this->normalizeProductsForWidget($recognizedProducts),
                'metadata' => [
                    'draft_order' => $draftOrder,
                    'ocr_text' => $result['ocr_text'] ?? null,
                    'disclaimer' => $imageAnswer['disclaimer'] ?? null,
                    'debug' => $imageAnswer['debug'] ?? null,
                    // Parsed names, confidence and the unmatched entries with their
                    // suggestions: diagnostics for the admin session trace, not render data.
                    'image_recognition' => $recognizedProducts,
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
                            'image_id' => $this->imageRecognitionService->lastStoredImageId(),
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
                ->values();

            $imageUrls = $this->resolveHistoryImageUrls($messages, (int) $userId);

            $messages = $messages
                ->map(function ($msg) use ($imageUrls) {
                    $imageId = data_get($msg->metadata, 'image_upload.image_id');
                    $imageUrl = $imageId === null ? null : ($imageUrls[(int) $imageId] ?? null);

                    return [
                        'role' => $msg->role,
                        'content' => $msg->content,
                        // Normalised on read too: sessions written before the shape was
                        // fixed still hold matchProducts()'s wrapper.
                        'products' => $this->normalizeProductsForWidget($msg->products),
                        'categories' => $msg->metadata['categories'] ?? [],
                        'triage_handoff' => $msg->metadata['triage_handoff'] ?? null,
                        'show_disclaimer' => $msg->show_disclaimer,
                        'disclaimer' => $msg->metadata['disclaimer'] ?? null,
                        // Absent when retention already removed the file, so the bubble
                        // degrades to text instead of a broken-image icon.
                        'hasImage' => $imageUrl !== null,
                        'imageUrl' => $imageUrl,
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
     * Signed-free URLs for the uploads referenced by this page of history, keyed by
     * MaAnh. One query for the whole page, and only rows that still have their file:
     * retention deletes images after chatbot.images.cleanup_after hours.
     *
     * @param \Illuminate\Support\Collection<int, ChatMessage> $messages
     * @return array<int, string>
     */
    protected function resolveHistoryImageUrls($messages, int $userId): array
    {
        $ids = $messages
            ->map(fn ($msg) => data_get($msg->metadata, 'image_upload.image_id'))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return ChatbotImage::whereIn('MaAnh', $ids)
            ->where('MaKH', $userId)
            ->get(['MaAnh'])
            ->mapWithKeys(fn (ChatbotImage $image) => [
                (int) $image->MaAnh => route('chatbot.images.show', ['image' => $image->MaAnh]),
            ])
            ->all();
    }

    /**
     * Serve one of the customer's own chatbot uploads.
     *
     * Scoped to the owner, not the session: history survives a new chat session, and a
     * sequential MaAnh would otherwise let any signed-in user page through every
     * prescription image on the system.
     */
    public function image(ChatbotImage $image)
    {
        abort_if((int) $image->MaKH !== (int) auth()->id(), 404);

        $path = $image->file_path ?: $image->image_path;
        abort_if(!$path || !Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $image->mime_type ?: 'image/jpeg',
        ]);

        // setPrivate() rather than a Cache-Control header: BinaryFileResponse recomputes
        // the directive and turns a plain max-age into `public`, which would let a shared
        // proxy cache someone's prescription photo.
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }

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
    public function addToCart(Request $request, DrugSafetyService $drugSafety): JsonResponse
    {
        $validated = $request->validate([
            'MaSP' => 'required|integer|exists:products,MaSP',
            'quantity' => 'nullable|integer|min:1|max:99',
            'confirm_warning' => 'nullable|boolean',
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
                    'message' => __('messages.cart.stock_remaining', ['count' => $product->stock_quantity])
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

            // Same drug-safety gate as CartController::add. This endpoint writes the
            // same session cart, so skipping the check here would make the block
            // bypassable through the chat widget.
            $userId = (int) auth()->id();
            $confirmed = $request->boolean('confirm_warning');
            $safety = $drugSafety->checkAddition($product, CartLine::rowsFrom($cart), $userId);

            if ($safety['blocking'] && ! $confirmed) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.drug_safety.blocked_message'),
                    'requires_confirmation' => true,
                    'drug_warnings' => $safety['warnings'],
                ], 409);
            }

            $cart[$product->MaSP] = $newQty;
            session()->put('cart', $cart);

            if ($safety['blocking'] && $confirmed) {
                $drugSafety->logOverride($userId, $product, $safety['warnings']);
            }

            // Build cart summary for response
            $cartSummary = $this->getCartSummary();

            return response()->json([
                'success' => true,
                'message' => "Đã thêm {$quantity} x {$product->name} vào giỏ hàng.",
                'cart' => $cartSummary,
                'requires_confirmation' => false,
                'drug_warnings' => $safety['warnings'],
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
                    'message' => __('messages.cart.stock_remaining', ['count' => $product->stock_quantity])
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
                    CartLine::rowsFrom($cart),
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
                app(AuditLogService::class)->log(
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

            // The recognition wrapper, not the rendered rows: the follow-up path needs
            // parsed_name and the unmatched entries, which flattening drops.
            $recognition = $message['metadata']['image_recognition'] ?? [];
            if (is_array($recognition) && !empty($recognition)) {
                return $recognition;
            }

            $products = $message['products'] ?? [];
            if (is_array($products) && !empty($products)) {
                return $products;
            }
        }

        return [];
    }

    /**
     * The flat catalog rows the widget renders (`product.name`, `product.price`,
     * `product.image_url`), from either shape ever stored on a message.
     *
     * matchProducts() returns a wrapper — `{parsed_name, matched, product:{...}}` — while
     * the text path stores the rows directly. Storing the wrapper for image turns made
     * every card read undefined and render "0 đ" with a placeholder icon, so this runs on
     * write AND on read: sessions from before the fix still hold the wrapper.
     *
     * @param mixed $products
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeProductsForWidget($products): array
    {
        if (!is_array($products)) {
            return [];
        }

        $rows = [];

        foreach ($products as $item) {
            if (!is_array($item)) {
                continue;
            }

            $isWrapper = array_key_exists('product', $item) || array_key_exists('matched', $item);

            if (!$isWrapper) {
                $rows[] = $item;
                continue;
            }

            // Unmatched entries have no catalog row to render; their parsed names and
            // suggestions are already in the reply text.
            if (($item['matched'] ?? false) !== true) {
                continue;
            }

            $product = $item['product'] ?? null;
            if (is_array($product) && !empty($product)) {
                $rows[] = $product;
            }
        }

        return $rows;
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
