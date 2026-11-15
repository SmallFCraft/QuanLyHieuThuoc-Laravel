<?php

namespace App\Http\Controllers;

use App\Services\ChatbotService;
use App\Services\AiJobService;
use App\Services\AnToanThuocService;
use App\Services\NhanDienHinhAnhService;
use App\Services\NhatKyKiemToanService;
use App\Models\AiJob;
use App\Models\AnhChatbot;
use App\Models\TinNhanChat;
use App\Models\DonHang;
use App\Support\CartLine;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class TroLyChatbotController extends Controller
{
    private const MAX_HISTORY_MESSAGES = 50;

    protected ChatbotService $chatbotService;
    protected NhanDienHinhAnhService $nhanDienHinhAnhService;

    public function __construct(
        ChatbotService $chatbotService,
        NhanDienHinhAnhService $nhanDienHinhAnhService
    ) {
        $this->chatbotService = $chatbotService;
        $this->nhanDienHinhAnhService = $nhanDienHinhAnhService;
    }

    /**
     * Giao một lượt chatbot cho worker hàng đợi và trả lời ngay.
     *
     * Lúc trước lời gọi AI chạy trực tiếp, giữ một process PHP tối đa AI_TIMEOUT
     * cộng nửa nhà cung cấp dự phòng. Server dev chỉ có một process nên điều này
     * chặn mọi trang khác: một khách chờ bot trả lời là cả site bị đóng băng.
     *
     * Hình dạng response phụ thuộc vào queue driver, có chủ ý:
     *  - worker đang chạy       -> 202 kèm job id để poll;
     *  - driver `sync`          -> job đã chạy xong, trả về câu trả lời đầy đủ
     *                              ngay tại đây giống hệt như trước.
     */
    public function sendMessage(Request $request, AiJobService $aiJobs): JsonResponse
    {
        // Kiểm tra xác thực trước
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

        // Browser gọi yêu cầu này khi polling cho thấy không worker nào bao giờ
        // nhận job trước đó. Job bị bỏ leo được hưu trí trước để worker muộn
        // không thể đăng một câu trả lời thứ hai cho cùng câu hỏi.
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
            'poll_url' => route('cong-viec-ai.kiem-tra', $job->uuid),
            'session_id' => $sessionId,
        ], 202);
    }

    /**
     * Tiến độ của bất kỳ job AI nào trong hàng đợi — lượt chat hoặc viết lại lời khuyên sàng lọc.
     *
     * uuid là identifier công khai, không phải capability token: AiJobService giới
     * hạn mỗi truy vấn vào khách đang đăng nhập, nên job của người khác là 404.
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

        // Khách đã nhấn dừng. Không phải lỗi: browser đã gỡ bỏ bong bóng chat đang chờ,
        // nên chỉ cần biết rằng nó có thể dừng polling.
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
     * Dừng một lượt mà khách không còn muốn nữa.
     *
     * Chỉ abort fetch ở browser sẽ để worker chạy tiếp và câu trả lời rơi vào
     * `chat_messages` — khách sẽ thấy câu trả lời cho câu hỏi họ đã huỷ ở lần
     * tải lại sau. Hưu trí dòng bản ghi mới là cách làm cho lệnh dừng có thật.
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

        // Giới hạn vào khách đang đăng nhập: uuid là identifier công khai, nên không
        // có check này bất kỳ ai cũng có thể hạ lượt chat của khách khác.
        $job = AiJob::where('uuid', $uuid)->where('ma_nd', (int) auth()->id())->first();

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

        $stranded = AiJob::where('uuid', $uuid)->where('ma_nd', $userId)->first();

        if ($stranded !== null) {
            $aiJobs->abandon($stranded, 'no worker picked the job up, ran synchronously');
        }
    }

    /**
     * Ngữ cảnh hội thoại cho lượt AI.
     *
     * Session id và lịch sử đến từ server, không bao giờ từ request body:
     * session id do client cung cấp sẽ cho một khách đọc thread của khách khác.
     *
     * Cả hai danh sách phải giữ nguyên là danh sách liên tục theo thứ tự thời gian.
     * Chỉ reverse() một mình giữ lại các key giảm dần, mà json_encode biến thành
     * object, và cột json `ai_jobs.payload` trả về các key object sắp tăng dần -
     * worker hàng đợi khi đó giao cho model cả cuộc trò chuyện mới nhất trước,
     * nên một câu hỏi theo ngắn ("có ổn không?") được trả lời dựa trên tin nhắn
     * cũ nhất trong cửa sổ thay vì tin nhắn mới nhất.
     *
     * @return array<string, mixed>
     */
    protected function buildContext(Request $request, int $userId, string $sessionId): array
    {
        $context = (array) $request->input('context', []);

        $previousMessages = TinNhanChat::where('ma_nd', $userId)
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'desc')
            ->orderBy('ma_tn', 'desc')
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

        $nameMemoryMessages = TinNhanChat::where('ma_nd', $userId)
            ->where('session_id', $sessionId)
            ->where('role', 'user')
            ->orderBy('created_at', 'desc')
            ->orderBy('ma_tn', 'desc')
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
     * Tải lên và xử lý ảnh đơn thuốc hoặc hoá đơn.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadImage(Request $request): JsonResponse
    {
        // Kiểm tra xác thực trước
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
            $anh = $request->file('image');
            $type = $validated['type'] ?? 'prescription';
            $imageMessage = trim((string) ($validated['message'] ?? ''));
            $sessionId = $this->getSessionId();

            $userImageMessage = TinNhanChat::create([
                'ma_nd' => $userId,
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
            
            // Xử lý ảnh theo loại
            if ($type === 'prescription') {
                $result = $this->nhanDienHinhAnhService->xuLyDonThuoc($anh, $userId);
            } else {
                $result = $this->nhanDienHinhAnhService->xuLyHoaDon($anh, $userId);
            }

            if (!$result['success']) {
                $userImageMessage->update([
                    'metadata' => [
                        'image_upload' => [
                            'type' => $type,
                            'status' => 'failed',
                            'error' => $result['error'] ?? null,
                            // File đã được lưu trước khi phân tích chạy, nên ảnh bị từ chối
                            // vẫn còn dữ liệu để hiển thị sau khi tải lại trang.
                            'image_id' => $this->nhanDienHinhAnhService->maAnhLuuCuoi(),
                        ],
                    ],
                ]);

                return response()->json(array_merge($result, [
                    'session_id' => $sessionId,
                ]), 400);
            }
            
            // Tạo đơn hàng nhập từ các sản phẩm đã nhận diện
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
                        // Cho lịch sử chat render lại ảnh tải lên sau khi tải lại trang.
                        // Bong bóng chat trực tuyến hiện blob: URL, chết mất cùng với trang.
                        'image_id' => $result['image_id'] ?? $this->nhanDienHinhAnhService->maAnhLuuCuoi(),
                    ],
                ],
            ]);

            // Trả lời caption ("đây là thuốc gì?") dựa trên nội dung ảnh đọc được,
            // quay về tóm tắt nhận diện xác định khi không có câu trả lời.
            $imageAnswer = $this->chatbotService->answerImageQuestion(
                $imageMessage,
                (string) ($result['ocr_text'] ?? ''),
                $result['parsed_products'] ?? [],
                $recognizedProducts
            );
            $messageContent = trim((string) ($imageAnswer['response'] ?? '')) !== ''
                ? $imageAnswer['response']
                : $this->buildImageRecognitionMessageContent($recognizedProducts);

            TinNhanChat::create([
                'ma_nd' => $userId,
                'session_id' => $sessionId,
                'role' => 'assistant',
                'content' => $messageContent,
                // Dòng catalog phẳng, cùng hình dạng mà luồng văn bản lưu và widget
                // render. Lưu wrapper của khopSanPham() ở đây đã làm mỗi card sản phẩm
                // đọc undefined sau khi tải lại và hiện "0 đ" kèm icon placeholder.
                'products' => $this->normalizeProductsForWidget($recognizedProducts),
                'metadata' => [
                    'draft_order' => $draftOrder,
                    'ocr_text' => $result['ocr_text'] ?? null,
                    'disclaimer' => $imageAnswer['disclaimer'] ?? null,
                    'debug' => $imageAnswer['debug'] ?? null,
                    // Tên đã phân tích, confidence và các dòng không khớp kèm gợi ý:
                    // dữ liệu chẩn đoán cho trace session quản trị, không phải dữ liệu render.
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
                'ma_nd' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            if ($userImageMessage) {
                $userImageMessage->update([
                    'metadata' => [
                        'image_upload' => [
                            'type' => $validated['type'] ?? 'prescription',
                            'status' => 'failed',
                            'error' => $e->getMessage(),
                            'image_id' => $this->nhanDienHinhAnhService->maAnhLuuCuoi(),
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
     * Lấy lịch sử chat cho session hiện tại.
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
            
            $cacTinNhan = TinNhanChat::where('ma_nd', $userId)
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

            $imageUrls = $this->resolveHistoryImageUrls($cacTinNhan, (int) $userId);

            $cacTinNhan = $cacTinNhan
                ->map(function ($msg) use ($imageUrls) {
                    $imageId = data_get($msg->metadata, 'image_upload.image_id');
                    $imageUrl = $imageId === null ? null : ($imageUrls[(int) $imageId] ?? null);

                    return [
                        'role' => $msg->role,
                        'content' => $msg->content,
                        // Chuẩn hoá cả khi đọc: các session ghi trước khi hình dạng được
                        // sửa vẫn giữ wrapper của khopSanPham().
                        'products' => $this->normalizeProductsForWidget($msg->products),
                        'categories' => $msg->metadata['categories'] ?? [],
                        'triage_handoff' => $msg->metadata['triage_handoff'] ?? null,
                        'show_disclaimer' => $msg->show_disclaimer,
                        'disclaimer' => $msg->metadata['disclaimer'] ?? null,
                        // Không có khi retention đã xoá file, nên bong chat hạ cấp thành
                        // văn bản thay vì icon ảnh lỗi.
                        'hasImage' => $imageUrl !== null,
                        'imageUrl' => $imageUrl,
                        'created_at' => $msg->created_at->format('Y-m-d H:i:s')
                    ];
                })
                ->toArray();
            
            $count = count($cacTinNhan);

            return response()->json([
                'success' => true,
                'messages' => $cacTinNhan,
                'count' => $count,
                'session_id' => $count > 0 ? $sessionId : null,
            ]);
        } catch (\Exception $e) {
            Log::error('Get chat history failed', [
                'ma_nd' => auth()->id(),
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
     * URL không cần chữ ký cho các ảnh tải lên được trang lịch sử này tham chiếu, key theo
     * MaAnh. Một truy vấn cho cả trang, và chỉ những dòng vẫn còn file: retention
     * xoá ảnh sau chatbot.images.cleanup_after giờ.
     *
     * @param \Illuminate\Support\Collection<int, TinNhanChat> $cacTinNhan
     * @return array<int, string>
     */
    protected function resolveHistoryImageUrls($cacTinNhan, int $userId): array
    {
        $ids = $cacTinNhan
            ->map(fn ($msg) => data_get($msg->metadata, 'image_upload.image_id'))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return AnhChatbot::whereIn('ma_anh', $ids)
            ->where('ma_nd', $userId)
            ->get(['ma_anh'])
            ->mapWithKeys(fn (AnhChatbot $anh) => [
                (int) $anh->ma_anh => route('chatbot.anh.show', ['image' => $anh->ma_anh]),
            ])
            ->all();
    }

    /**
     * Phục vụ một trong những ảnh chatbot mà khách tự tải lên.
     *
     * Giới hạn vào chủ sở hữu, không phải session: lịch sử sống sót qua session
     * chat mới, và MaAnh liên tục nếu không sẽ cho bất kỳ người đăng nhập nào
     * xem lẫn lượt mọi ảnh đơn thuốc trên hệ thống.
     */
    public function image(AnhChatbot $image)
    {
        abort_if((int) $image->ma_nd !== (int) auth()->id(), 404);

        $path = $image->file_path ?: $image->image_path;
        abort_if(!$path || !Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $image->mime_type ?: 'image/jpeg',
        ]);

        // setPrivate() thay vì header Cache-Control: BinaryFileResponse tính lại
        // directive và biến max-age đơn giản thành `public`, cho phép proxy dùng chung
        // cache ảnh đơn thuốc của ai đó.
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }

    /**
     * Xoá lịch sử chat cho session hiện tại.
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
                TinNhanChat::where('ma_nd', $userId)
                    ->where('session_id', $sessionId)
                    ->delete();

                AnhChatbot::where('ma_nd', $userId)
                    ->where('session_id', $sessionId)
                    ->chunkById(100, function ($cacAnh) {
                        foreach ($cacAnh as $anh) {
                            $path = $anh->file_path ?? $anh->image_path;

                            if ($path && Storage::disk('local')->exists($path)) {
                                Storage::disk('local')->delete($path);
                            }

                            $anh->forceDelete();
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
                'ma_nd' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa lịch sử'
            ], 500);
        }
    }

    /**
     * Thêm sản phẩm vào giỏ hàng từ chatbot.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function addToCart(Request $request, AnToanThuocService $anToanThuoc): JsonResponse
    {
        $validated = $request->validate([
            'MaSP' => 'required|integer|exists:san_phams,ma_sp',
            'quantity' => 'nullable|integer|min:1|max:99',
            'confirm_warning' => 'nullable|boolean',
        ], [
            'MaSP.required' => 'Vui lòng chọn sản phẩm.',
            'MaSP.exists' => 'Sản phẩm không tồn tại.',
            'quantity.min' => 'Số lượng phải ít nhất là 1.',
            'quantity.max' => 'Số lượng tối đa là 99.'
        ]);

        try {
            $sanPham = \App\Models\SanPham::findOrFail($validated['MaSP']);
            $quantity = $validated['quantity'] ?? 1;

            // Kiểm tra sản phẩm
            if ($sanPham->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sản phẩm đã hết hạn.'
                ], 422);
            }

            if ($sanPham->so_luong_ton < $quantity) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.cart.stock_remaining', ['count' => $sanPham->so_luong_ton])
                ], 422);
            }

            // Thêm vào giỏ hàng trong session (cùng giỏ mà GioHangController dùng)
            $cart = session()->get('cart', []);
            $currentQty = $cart[$sanPham->ma_sp] ?? 0;
            $newQty = $currentQty + $quantity;

            if ($newQty > $sanPham->so_luong_ton) {
                return response()->json([
                    'success' => false,
                    'message' => "Số lượng vượt quá tồn kho. Đã có {$currentQty} trong giỏ, còn lại {$sanPham->so_luong_ton}."
                ], 422);
            }

            // Cùng gate an toàn thuốc với GioHangController::add. Endpoint này ghi vào
            // cùng giỏ hàng trong session, nên bỏ qua check ở đây sẽ làm phép block
            // bị vượt qua thông qua widget chat.
            $userId = (int) auth()->id();
            $confirmed = $request->boolean('confirm_warning');
            $safety = $anToanThuoc->kiemTraThemVaoGio($sanPham, CartLine::rowsFrom($cart), $userId);

            if ($safety['blocking'] && ! $confirmed) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.drug_safety.blocked_message'),
                    'requires_confirmation' => true,
                    'drug_warnings' => $safety['warnings'],
                ], 409);
            }

            $cart[$sanPham->ma_sp] = $newQty;
            session()->put('cart', $cart);

            if ($safety['blocking'] && $confirmed) {
                $anToanThuoc->ghiNhatKyBoQua($userId, $sanPham, $safety['warnings']);
            }

            // Xây dựng tóm tắt giỏ hàng cho response
            $cartSummary = $this->getCartSummary();

            return response()->json([
                'success' => true,
                'message' => "Đã thêm {$quantity} x {$sanPham->ten} vào giỏ hàng.",
                'cart' => $cartSummary,
                'requires_confirmation' => false,
                'drug_warnings' => $safety['warnings'],
            ]);

        } catch (\Exception $e) {
            Log::error('Chatbot add to cart failed', [
                'error' => $e->getMessage(),
                'ma_sp' => $validated['MaSP']
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Không thể thêm sản phẩm. Vui lòng thử lại.'
            ], 500);
        }
    }
    
    /**
     * Lấy tóm tắt giỏ hàng hiện tại.
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
     * Cập nhật số lượng sản phẩm trong giỏ hàng.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateCartItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'MaSP' => 'required|integer|exists:san_phams,ma_sp',
            'quantity' => 'required|integer|min:0|max:99'
        ]);

        try {
            $sanPham = \App\Models\SanPham::findOrFail($validated['MaSP']);
            $quantity = $validated['quantity'];
            
            $cart = session()->get('cart', []);
            
            // Xoá khi số lượng là 0
            if ($quantity === 0) {
                unset($cart[$sanPham->ma_sp]);
                session()->put('cart', $cart);
                
                return response()->json([
                    'success' => true,
                    'message' => "Đã xóa {$sanPham->ten} khỏi giỏ hàng.",
                    'cart' => $this->getCartSummary()
                ]);
            }
            
            // Kiểm tra tồn kho
            if ($sanPham->so_luong_ton < $quantity) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.cart.stock_remaining', ['count' => $sanPham->so_luong_ton])
                ], 422);
            }
            
            $cart[$sanPham->ma_sp] = $quantity;
            session()->put('cart', $cart);
            
            return response()->json([
                'success' => true,
                'message' => 'Đã cập nhật giỏ hàng.',
                'cart' => $this->getCartSummary()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Update cart item failed', [
                'error' => $e->getMessage(),
                'ma_sp' => $validated['MaSP']
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Không thể cập nhật giỏ hàng.'
            ], 500);
        }
    }

    /**
     * Đặt hàng trực tiếp từ chatbot.
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
                    $completedOrder = DonHang::where('ma_nd', $userId)->find($completedOrderId);

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

                // Dùng ThanhToanService có sẵn để tạo đơn hàng an toàn trong transaction
                $thanhToanService = app(\App\Services\ThanhToanService::class);
                $donHang = $thanhToanService->xuLyThanhToan(
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

                Cache::put($completedKey, $donHang->ma_dh, now()->addMinutes(10));
                session()->forget('cart');

                // Ghi nhật kiểm toán
                app(NhatKyKiemToanService::class)->ghi(
                    action: 'chatbot_order_placed',
                    modelType: DonHang::class,
                    modelId: $donHang->ma_dh,
                    oldValues: null,
                    newValues: [
                        'ma_nd' => $userId,
                        'source' => 'chatbot',
                        'ma_dh' => $donHang->ma_dh,
                        'total_amount' => $donHang->tong_tien
                    ]
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Đặt hàng thành công!',
                    'order' => $this->formatOrderPayload($donHang)
                ]);
            });

        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn hàng đang được xử lý. Vui lòng chờ trong giây lát.'
            ], 429);
        } catch (\Exception $e) {
            Log::error('Chatbot place order failed', [
                'ma_nd' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Lấy tóm tắt giỏ hàng kèm chi tiết sản phẩm.
     *
     * @return array
     */
    protected function getCartSummary(): array
    {
        $cart = session()->get('cart', []);

        if (empty($cart)) {
            return ['items' => [], 'total' => 0, 'count' => 0];
        }

        $sanPhams = \App\Models\SanPham::whereIn('ma_sp', array_keys($cart))
            ->notExpired()
            ->get()
            ->keyBy('ma_sp');

        $items = [];
        $total = 0;

        foreach ($cart as $productId => $quantity) {
            if (isset($sanPhams[$productId])) {
                $sanPham = $sanPhams[$productId];
                $subtotal = $sanPham->price_vnd * $quantity;
                $total += $subtotal;
                $items[] = [
                    'MaSP' => $sanPham->ma_sp,
                    'name' => $sanPham->ten,
                    'price' => $sanPham->price_vnd,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'stock_quantity' => $sanPham->so_luong_ton,
                    'image_url' => $sanPham->image_url
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

    private function formatOrderPayload(DonHang $donHang): array
    {
        return [
            'id' => $donHang->ma_dh,
            'total_amount' => $donHang->tong_tien,
            'status' => $donHang->trang_thai,
            'created_at' => $donHang->created_at->format('d/m/Y H:i')
        ];
    }

    /**
     * Lấy hoặc tạo session ID cho người dùng hiện tại.
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

    protected function formatMessageContentForAiContext(TinNhanChat $tinNhan): string
    {
        $content = trim((string) $tinNhan->content);
        $productNames = $this->extractProductNamesFromRecognitionPayload($tinNhan->products ?? []);

        if ($tinNhan->role === 'assistant' && !empty($productNames)) {
            $content .= "\n\nSản phẩm đã nhận dạng từ ảnh: " . implode(', ', $productNames) . '.';
            $content .= "\nNếu người dùng hỏi \"thuốc đó\", \"thuốc này\", \"sản phẩm đó\" hoặc \"sản phẩm này\", hãy hiểu là đang hỏi về các sản phẩm này.";
        }

        return trim($content);
    }

    protected function extractRecentImageProducts(array $cacTinNhan): array
    {
        for ($index = count($cacTinNhan) - 1; $index >= 0; $index--) {
            $tinNhan = $cacTinNhan[$index];
            if (!is_array($tinNhan) || ($tinNhan['role'] ?? null) !== 'assistant') {
                continue;
            }

            // Wrapper nhận diện, không phải các dòng đã render: luồng hỏi theo cần
            // parsed_name và các dòng không khớp, mà việc làm phẳng đã mất đi.
            $recognition = $tinNhan['metadata']['image_recognition'] ?? [];
            if (is_array($recognition) && !empty($recognition)) {
                return $recognition;
            }

            $sanPhams = $tinNhan['products'] ?? [];
            if (is_array($sanPhams) && !empty($sanPhams)) {
                return $sanPhams;
            }
        }

        return [];
    }

    /**
     * Các dòng catalog phẳng mà widget render (`product.name`, `product.price`,
     * `product.image_url`), từ bất kỳ hình dạng nào từng được lưu trên tin nhắn.
     *
     * khopSanPham() trả về một wrapper — `{parsed_name, matched, product:{...}}` — trong khi
     * luồng văn bản lưu các dòng trực tiếp. Lưu wrapper cho lượt ảnh đã làm mỗi card
     * đọc undefined và render "0 đ" kèm icon placeholder, nên hàm này chạy cả khi GHI
     * lẫn khi ĐỌC: các session trước khi sửa vẫn giữ wrapper.
     *
     * @param mixed $sanPhams
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeProductsForWidget($sanPhams): array
    {
        if (!is_array($sanPhams)) {
            return [];
        }

        $rows = [];

        foreach ($sanPhams as $item) {
            if (!is_array($item)) {
                continue;
            }

            $isWrapper = array_key_exists('product', $item) || array_key_exists('matched', $item);

            if (!$isWrapper) {
                $rows[] = $item;
                continue;
            }

            // Dòng không khớp không có dòng catalog nào để render; tên đã phân tích và
            // gợi ý của chúng đã có trong văn bản câu trả lời.
            if (($item['matched'] ?? false) !== true) {
                continue;
            }

            $sanPham = $item['product'] ?? null;
            if (is_array($sanPham) && !empty($sanPham)) {
                $rows[] = $sanPham;
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

    protected function extractProductNamesFromRecognitionPayload($sanPhams): array
    {
        if (!is_array($sanPhams)) {
            return [];
        }

        $names = [];
        foreach ($sanPhams as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sanPham = $item['product'] ?? null;
            $name = is_array($sanPham)
                ? trim((string) ($sanPham['name'] ?? ''))
                : trim((string) ($item['parsed_name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}