<?php

namespace App\Services;

use App\Exceptions\AiProviderVisionNotSupportedException;
use App\Models\AnhChatbot;
use App\Models\SanPham;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class NhanDienHinhAnhService
{
    /**
     * Lượng bằng chứng nhận diện tối thiểu trước khi một sản phẩm trong danh mục
     * được báo là sản phẩm trên ảnh.
     */
    protected const IDENTITY_MATCH_MIN_SCORE = 30;

    /**
     * Khoảng cách điểm bắt buộc so với đối thủ tên khác tốt nhất, để một lựa chọn
     * 50/50 giữa hai sản phẩm được báo là "không tìm thấy" chứ không phải đoán.
     */
    protected const IDENTITY_MATCH_MIN_MARGIN = 12;

    /**
     * Các token xuất hiện trong phần lớn dòng danh mục, nên chúng không bao giờ
     * xác định được một sản phẩm.
     */
    protected const WEAK_IDENTITY_TOKENS = [
        'vitamin', 'vitamins', 'thuoc', 'duoc', 'pham', 'san', 'hang', 'khoang', 'chat',
        'bao', 'gia', 'cong', 'dung', 'lieu', 'nang', 'cung', 'mem', 'tablet', 'tablets',
        'capsule', 'capsules', 'supplement', 'supplements', 'complex', 'extra', 'plus',
    ];

    protected NhatKyKiemToanService $nhatKyKiemToanService;
    protected AiProviderService $aiProviderService;

    /**
     * MaAnh của dòng luuAnh() vừa ghi, để caller có thể liên kết lượt chat
     * với các byte trên đĩa. Lịch sử chat cần liên kết này để render lại ảnh tải
     * lên sau khi tải lại trang — bubble trực tiếp dùng blob: URL chết theo trang.
     */
    protected ?int $maAnhLuuCuoi = null;

    /**
     * MaAnh của dòng luuAnh() vừa ghi, để caller có thể liên kết lượt chat
     * với các byte trên đĩa. Lịch sử chat cần liên kết này để render lại ảnh tải
     * lên sau khi tải lại trang — bubble trực tiếp dùng blob: URL chết theo trang.
     *
     * Đọc nó SAU MỌI kết quả: file được lưu trước khi phân tích chạy, nên một ảnh
     * bị từ chối hoặc không đọc được vẫn có cái gì đó để hiển thị.
     */
    public function maAnhLuuCuoi(): ?int
    {
        return $this->maAnhLuuCuoi;
    }

    protected const VISION_MAX_IMAGE_WIDTH = 1600;
    protected const VISION_MAX_IMAGE_HEIGHT = 1600;
    protected const VISION_JPEG_QUALITY = 75;
    protected const VISION_MIME_TYPE = 'image/jpeg';

    public function __construct(
        NhatKyKiemToanService $nhatKyKiemToanService,
        AiProviderService $aiProviderService
    ) {
        $this->nhatKyKiemToanService = $nhatKyKiemToanService;
        $this->aiProviderService = $aiProviderService;
    }

    /**
     * Xử lý ảnh đơn thuốc bằng nhà cung cấp AI đã cấu hình.
     *
     * @param UploadedFile $anh
     * @param int $userId
     * @return array
     */
    public function xuLyDonThuoc(UploadedFile $anh, int $userId): array
    {
        $startTime = microtime(true);
        
        try {
            $imagePath = $this->luuAnh($anh, $userId, 'prescription');
            
            $analysisResult = $this->analyzeImageWithProvider($imagePath, 'prescription');
            
            if (empty($analysisResult['products'])) {
                Log::warning('No products recognized from prescription image', [
                    'ma_nd' => $userId,
                    'description' => $analysisResult['description'] ?? 'N/A'
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Dạ, em chưa đọc được thông tin trên ảnh này. Anh/chị vui lòng chụp lại rõ nét hơn hoặc gửi ảnh chụp trực tiếp nhãn trên hộp thuốc giúp em nhé.',
                    'description' => $analysisResult['description'] ?? ''
                ];
            }
            
            $matched = $this->khopSanPham($analysisResult['products']);
            
            $processingTime = (microtime(true) - $startTime) * 1000;
            $this->markLatestImageAnalysisResult($userId, 'prescription', $analysisResult, $matched);
            
            // Ghi nhật ký kiểm toán
            $this->nhatKyKiemToanService->ghi(
                action: 'chatbot_image_processed',
                modelType: AnhChatbot::class,
                modelId: null,
                oldValues: null,
                newValues: [
                    'ma_nd' => $userId,
                    'image_type' => 'prescription',
                    'products_recognized' => count($matched),
                    'processing_time_ms' => $processingTime
                ],
                userId: $userId
            );
            
            return [
                'success' => true,
                'recognized_products' => $matched,
                'parsed_products' => $analysisResult['products'],
                'ocr_text' => $analysisResult['description'] ?? '',
                'processing_time_ms' => $processingTime,
                'image_id' => $this->maAnhLuuCuoi,
            ];
        } catch (\Exception $e) {
            Log::error('Prescription processing failed', [
                'ma_nd' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($e->getPrevious() instanceof AiProviderVisionNotSupportedException) {
                $this->markLatestImageAnalysisDebug($userId, 'prescription', [
                    'ai_provider_used' => true,
                    'ai_provider_mode' => config('chatbot.ai.mode', 'chat_completions'),
                    'ai_provider_model' => config('chatbot.ai.model'),
                    'vision_supported' => false,
                    'provider_error_type' => 'vision_not_supported',
                ]);

                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'debug' => [
                        'ai_provider_used' => true,
                        'ai_provider_mode' => config('chatbot.ai.mode', 'chat_completions'),
                        'ai_provider_model' => config('chatbot.ai.model'),
                        'vision_supported' => false,
                        'provider_error_type' => 'vision_not_supported',
                    ],
                ];
            }

            return [
                'success' => false,
                'error' => 'Dạ, em gặp chút khó khăn khi đọc ảnh đơn thuốc. Anh/chị vui lòng gửi lại ảnh rõ hơn hoặc nhập tên thuốc để em tìm nhanh cho mình nhé.'
            ];
        }
    }

    /**
     * Xử lý ảnh hoá đơn bằng nhà cung cấp AI đã cấu hình.
     *
     * @param UploadedFile $anh
     * @param int $userId
     * @return array
     */
    public function xuLyHoaDon(UploadedFile $anh, int $userId): array
    {
        $startTime = microtime(true);
        
        try {
            $imagePath = $this->luuAnh($anh, $userId, 'receipt');
            
            $analysisResult = $this->analyzeImageWithProvider($imagePath, 'receipt');
            
            if (empty($analysisResult['products'])) {
                Log::warning('No products recognized from receipt image', [
                    'ma_nd' => $userId,
                    'description' => $analysisResult['description'] ?? 'N/A'
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Dạ, em chưa nhận diện được các mặt hàng trên hóa đơn này. Anh/chị vui lòng chụp lại ảnh rõ hơn hoặc gửi tên sản phẩm để em kiểm tra nhanh ạ.',
                    'description' => $analysisResult['description'] ?? ''
                ];
            }
            
            $matched = $this->khopSanPham($analysisResult['products']);
            
            $processingTime = (microtime(true) - $startTime) * 1000;
            $this->markLatestImageAnalysisResult($userId, 'receipt', $analysisResult, $matched);
            
            // Ghi nhật ký kiểm toán
            $this->nhatKyKiemToanService->ghi(
                action: 'chatbot_image_processed',
                modelType: AnhChatbot::class,
                modelId: null,
                oldValues: null,
                newValues: [
                    'ma_nd' => $userId,
                    'image_type' => 'receipt',
                    'products_recognized' => count($matched),
                    'processing_time_ms' => $processingTime
                ],
                userId: $userId
            );
            
            return [
                'success' => true,
                'recognized_products' => $matched,
                'parsed_products' => $analysisResult['products'],
                'ocr_text' => $analysisResult['description'] ?? '',
                'processing_time_ms' => $processingTime,
                'image_id' => $this->maAnhLuuCuoi,
            ];
        } catch (\Exception $e) {
            Log::error('Receipt processing failed', [
                'ma_nd' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($e->getPrevious() instanceof AiProviderVisionNotSupportedException) {
                $this->markLatestImageAnalysisDebug($userId, 'receipt', [
                    'ai_provider_used' => true,
                    'ai_provider_mode' => config('chatbot.ai.mode', 'chat_completions'),
                    'ai_provider_model' => config('chatbot.ai.model'),
                    'vision_supported' => false,
                    'provider_error_type' => 'vision_not_supported',
                ]);

                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'debug' => [
                        'ai_provider_used' => true,
                        'ai_provider_mode' => config('chatbot.ai.mode', 'chat_completions'),
                        'ai_provider_model' => config('chatbot.ai.model'),
                        'vision_supported' => false,
                        'provider_error_type' => 'vision_not_supported',
                    ],
                ];
            }

            return [
                'success' => false,
                'error' => 'Dạ, em chưa đọc được hóa đơn này. Anh/chị vui lòng gửi lại ảnh rõ nét hơn giúp em với ạ.'
            ];
        }
    }

    /**
     * Phân tích ảnh bằng nhà cung cấp AI đã cấu hình.
     *
     * @param string $imagePath Đường dẫn tuyệt đối đến file ảnh
     * @param string $type Loại ảnh (prescription hoặc receipt)
     * @return array Kết quả phân tích gồm 'products' và 'description'
     * @throws \Exception
     */
    protected function analyzeImageWithProvider(string $imagePath, string $type): array
    {
        [$base64Image, $mimeType] = $this->prepareImageForProvider($imagePath);
        $prompt = $this->buildVisionPrompt($type);

        try {
            $textContent = $this->aiProviderService->analyzeImage($prompt, $base64Image, $mimeType);

            if (strlen(trim($textContent)) < 20) {
                Log::warning('AI provider vision returned very short response', [
                    'type' => $type,
                    'response_length' => strlen($textContent),
                    'response' => $textContent,
                ]);
                throw new \Exception('Không thể nhận dạng nội dung từ hình ảnh. Hình ảnh có thể không rõ ràng hoặc không đúng loại.');
            }

            Log::info('AI provider vision analysis completed', [
                'type' => $type,
                'response_length' => strlen($textContent),
                'response_preview' => substr($textContent, 0, 200),
                'ai_provider' => $this->aiProviderService->describeLastProvider(),
            ]);

            return $this->parseVisionProviderResponse($textContent);
        } catch (AiProviderVisionNotSupportedException $e) {
            Log::warning('AI provider vision is not supported by current model', [
                'type' => $type,
                'ai_provider_mode' => config('chatbot.ai.mode', 'chat_completions'),
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Dạ, model AI hiện tại chưa hỗ trợ đọc ảnh. Anh/chị vui lòng đổi sang model có hỗ trợ hình ảnh hoặc nhập trực tiếp tên thuốc/sản phẩm để em tra cứu nhanh nhé.', previous: $e);
        } catch (\Exception $e) {
            $tinNhan = mb_strtolower($e->getMessage(), 'UTF-8');

            if (str_contains($tinNhan, 'quota') || str_contains($tinNhan, 'rate limit') || str_contains($tinNhan, 'too many requests') || str_contains($tinNhan, '429')) {
                Log::warning('AI provider vision capacity limit hit', [
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
                throw new \Exception('Dạ, nhà cung cấp AI đang quá tải hoặc bị giới hạn lượt đọc ảnh. Anh/chị vui lòng thử lại sau ít phút hoặc nhập tên thuốc trực tiếp để em hỗ trợ ngay nhé.', previous: $e);
            }

            throw $e;
        }
    }
    /**
     * Xây dựng prompt cho vision của nhà cung cấp theo loại ảnh.
     *
     * @param string $type
     * @return string
     */
    protected function buildVisionPrompt(string $type): string
    {
        if ($type === 'prescription') {
            return <<<PROMPT
You are a pharmacy vision assistant. Read the image and extract all visible medicines, supplements, prescriptions, labels, packages, blister packs, or receipts.

Return ONLY valid JSON. Do not use markdown.

Schema:
{
  "description": "Short Vietnamese description of the image and visible text",
  "products": [
    {
      "name": "Exact visible brand/product name",
      "dosage": "Visible strength, e.g. 500mg or 1000mg",
      "quantity": 1,
      "active_ingredients": ["Likely active ingredient or nutrient if visible or strongly inferable"],
      "generic_names": ["Generic/common names if known"],
      "category": "Medicine/supplement category if inferable",
      "uses": ["Common intended uses shown or strongly inferable from package text"],
      "health_goals": ["Short searchable goals such as immunity, fever, pain, digestion"],
      "visible_text": "Important raw text seen on the package/label",
      "instructions": "Directions or claims visible on the image"
    }
  ]
}

Rules:
- Prefer exact visible text for "name"; use knowledge only for active_ingredients, generic_names, category, uses, and health_goals.
- If the brand name is not in the local catalog, the extra fields will be used to match local products by ingredient/usage.
- Do not invent dosage or quantity if not visible.
- If no medicine/supplement can be recognized, return {"description":"Không thể nhận dạng thuốc từ hình ảnh","products":[]}.
- JSON only.
PROMPT;
        }

        return <<<PROMPT
You are a pharmacy receipt vision assistant. Read the receipt image and extract all listed pharmacy products.

Return ONLY valid JSON. Do not use markdown.

Schema:
{
  "description": "Short Vietnamese description of the receipt",
  "products": [
    {
      "name": "Exact visible product name",
      "quantity": 1,
      "price": 0,
      "dosage": "Visible strength if any",
      "active_ingredients": ["Likely active ingredient or nutrient if visible or strongly inferable"],
      "generic_names": ["Generic/common names if known"],
      "category": "Medicine/supplement category if inferable",
      "uses": ["Common intended uses if inferable"],
      "visible_text": "Important raw receipt line"
    }
  ]
}

Rules:
- Keep product names as printed on the receipt.
- Use extra fields only to support local catalog matching.
- If no receipt/product can be recognized, return {"description":"Không thể nhận dạng hóa đơn","products":[]}.
- JSON only.
PROMPT;
    }

    /**
     * Phân tích văn bản trả lời của provider vision thành dữ liệu có cấu trúc.
     *
     * @param string $responseText
     * @return array
     */
    protected function parseVisionProviderResponse(string $responseText): array
    {
        // Cố thử trích xuất JSON từ câu trả lời
        $jsonText = trim($responseText);

        // Gỡ bỏ các markdown code block nếu có
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $jsonText, $matches)) {
            $jsonText = trim($matches[1]);
        }

        // Cố thử parse JSON trực tiếp
        $parsed = json_decode($jsonText, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            Log::info('Successfully parsed provider vision response', [
                'products_count' => count($parsed['products'] ?? [])
            ]);
            return [
                'description' => $parsed['description'] ?? '',
                'products' => $parsed['products'] ?? []
            ];
        }
        
        // Cố thử tìm object JSON trong văn bản
        if (preg_match('/\{[\s\S]*\}/', $jsonText, $jsonMatch)) {
            $parsed = json_decode($jsonMatch[0], true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                Log::info('Successfully parsed provider vision response from extracted JSON', [
                    'products_count' => count($parsed['products'] ?? [])
                ]);
                return [
                    'description' => $parsed['description'] ?? '',
                    'products' => $parsed['products'] ?? []
                ];
            }
        }
        
        Log::warning('Could not parse provider vision response as JSON', [
            'response' => substr($responseText, 0, 500),
            'json_error' => json_last_error_msg(),
            'response_length' => strlen($responseText)
        ]);
        
        return [
            'description' => 'Không thể nhận dạng nội dung từ hình ảnh',
            'products' => []
        ];
    }

    /**
     * Resize và chuẩn hoá ảnh trước khi gửi cho nhà cung cấp AI.
     *
     * @param string $imagePath
     * @return array{0: string, 1: string}
     */
    protected function prepareImageForProvider(string $imagePath): array
    {
        if (!is_file($imagePath)) {
            throw new \Exception('Unable to read image file.');
        }

        try {
            $manager = new ImageManager(Driver::class);
            $encodedImage = $manager
                ->decodePath($imagePath)
                ->scaleDown(
                    width: self::VISION_MAX_IMAGE_WIDTH,
                    height: self::VISION_MAX_IMAGE_HEIGHT
                )
                ->encodeUsingMediaType(
                    self::VISION_MIME_TYPE,
                    quality: self::VISION_JPEG_QUALITY
                );
        } catch (\Throwable $e) {
            Log::warning('Failed to normalize image before provider vision analysis', [
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Unable to normalize image for provider vision analysis.', previous: $e);
        }

        return [
            base64_encode((string) $encodedImage),
            self::VISION_MIME_TYPE,
        ];
    }

    /**
     * Lưu ảnh tải lên vào local disk.
     *
     * @param UploadedFile $anh
     * @param int $userId
     * @param string $type
     * @return string Đường dẫn tuyệt đối đến ảnh đã lưu
     */
    protected function luuAnh(UploadedFile $anh, int $userId, string $type): string
    {
        $storagePath = (string) config('chatbot.images.storage_path', 'chatbot-uploads');

        $sessionId = session('chat_session_id');
        if (!$sessionId) {
            $sessionId = Str::uuid()->toString();
            session(['chat_session_id' => $sessionId]);
        }

        $filename = Str::uuid() . '.' . $anh->extension();
        $path = $anh->storeAs($storagePath, $filename, 'local');

        $this->maAnhLuuCuoi = null;

        $stored = AnhChatbot::create([
            'ma_nd' => $userId,
            'session_id' => $sessionId,
            'filename' => $filename,
            'original_name' => $anh->getClientOriginalName(),
            'image_path' => $path,
            'file_path' => $path,
            'file_size' => $anh->getSize(),
            'mime_type' => $anh->getMimeType(),
            'image_type' => $type
        ]);

        $this->maAnhLuuCuoi = (int) $stored->ma_anh;

        // Resolve qua disk thay vì storage_path(): root hardcode trùng lặp với
        // filesystems.local và làm Storage::fake() vô dụng, nên mỗi test upload
        // đều ghi vào thư mục storage thật.
        return Storage::disk('local')->path($path);
    }

    protected function markLatestImageAnalysisDebug(int $userId, string $type, array $debug): void
    {
        $sessionId = session('chat_session_id');
        if (!$sessionId) {
            return;
        }

        $anh = AnhChatbot::where('ma_nd', $userId)
            ->where('session_id', $sessionId)
            ->where('image_type', $type)
            ->latest('ma_anh')
            ->first();

        if (!$anh) {
            return;
        }

        $anh->update([
            'analysis_result' => json_encode(['debug' => $debug], JSON_UNESCAPED_UNICODE),
            'processed_at' => now(),
        ]);
    }

    protected function markLatestImageAnalysisResult(int $userId, string $type, array $analysisResult, array $matchedProducts): void
    {
        $sessionId = session('chat_session_id');
        if (!$sessionId) {
            return;
        }

        $anh = AnhChatbot::where('ma_nd', $userId)
            ->where('session_id', $sessionId)
            ->where('image_type', $type)
            ->latest('ma_anh')
            ->first();

        if (!$anh) {
            return;
        }

        $provider = $this->aiProviderService->describeLastProvider();

        $anh->update([
            'analysis_result' => json_encode([
                'description' => $analysisResult['description'] ?? '',
                'debug' => [
                    'ai_provider_used' => true,
                    'ai_provider_mode' => $provider['mode'] ?? config('chatbot.ai.mode', 'chat_completions'),
                    'ai_provider_model' => $provider['model'] ?? config('chatbot.ai.model'),
                    'ai_provider_index' => $provider['index'] ?? null,
                    'vision_supported' => true,
                ],
            ], JSON_UNESCAPED_UNICODE),
            'ocr_text' => $analysisResult['description'] ?? '',
            'recognized_products' => $matchedProducts,
            'processed_at' => now(),
        ]);
    }

    /**
     * Khớp các sản phẩm đã parse với database.
     *
     * @param array $parsedProducts
     * @return array
     */
    public function khopSanPham(array $parsedProducts): array
    {
        $matched = [];

        foreach ($parsedProducts as $parsedProduct) {
            $productName = $parsedProduct['name'] ?? '';

            if (empty($productName)) {
                continue;
            }

            // Tìm sản phẩm trong database kèm ngữ cảnh provider đã trích xuất.
            $sanPham = $this->findProductByParsedData($parsedProduct);
            
            if ($sanPham) {
                $matched[] = [
                    'parsed_name' => $productName,
                    'matched' => true,
                    'product' => [
                        'id' => $sanPham->ma_sp,
                        'name' => $sanPham->ten,
                        'price' => $sanPham->price_vnd,
                        'stock_quantity' => $sanPham->so_luong_ton,
                        'image_url' => $sanPham->image_url,
                        'expiration_date' => $sanPham->ngay_het_han,
                        'is_expired' => $sanPham->isExpired(),
                    ],
                    'quantity' => $parsedProduct['quantity'] ?? 1,
                    'dosage' => $parsedProduct['dosage'] ?? null,
                    'instructions' => $parsedProduct['instructions'] ?? null,
                    'confidence' => $this->calculateMatchConfidenceFromParsedData($parsedProduct, $sanPham)
                ];
            } else {
                // Không tìm thấy SanPham
                $matched[] = [
                    'parsed_name' => $productName,
                    'matched' => false,
                    'product' => null,
                    'quantity' => $parsedProduct['quantity'] ?? 1,
                    'dosage' => $parsedProduct['dosage'] ?? null,
                    'instructions' => $parsedProduct['instructions'] ?? null,
                    'suggestions' => $this->findSimilarProductsFromParsedData($parsedProduct)
                ];
            }
        }
        
        return $matched;
    }
    
    /**
     * Tìm sản phẩm theo tên với fuzzy matching.
     *
     * @param string $name Tên SanPham cần tìm
     * @return \App\Models\SanPham|null
     */
    protected function findProductByParsedData(array $parsedProduct): ?SanPham
    {
        $name = trim((string) ($parsedProduct['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        // Dọn tên: bỏ tên thương mại, liều lượng, từ thông thường
        $cleanName = $this->cleanProductName($name);

        // Cố thử khớp chính xác trước
        $sanPham = SanPham::notExpired()
            ->where('so_luong_ton', '>', 0)
            ->where('ten', 'LIKE', $name)
            ->FEFO()
            ->first();

        if ($sanPham) {
            return $sanPham;
        }

        // Cố thử khớp một phần với tên đã dọn
        $sanPham = SanPham::notExpired()
            ->where('so_luong_ton', '>', 0)
            ->where(function($query) use ($name, $cleanName) {
                $query->where('ten', 'LIKE', "%{$name}%")
                      ->orWhere('ten', 'LIKE', "%{$cleanName}%")
                      ->orWhere('thanh_phan', 'LIKE', "%{$cleanName}%");
            })
            ->FEFO()
            ->first();

        // "Vitamin A" không được ăn xong với "Vitamin C 1000mg" chỉ vì tên đã dọn
        // là một substring của nó.
        if ($sanPham && !$this->conflictsWithParsedProduct($parsedProduct, $sanPham)) {
            return $sanPham;
        }

        $scoredProduct = $this->findProductByProviderContext($parsedProduct);
        if ($scoredProduct) {
            return $scoredProduct;
        }
        
        // Cố thử khớp từng từ với tên đã dọn.
        // Yêu cầu ít nhất 2 từ có nghĩa (>2 ký tự) để không trả về một khớp
        // chung chung khi các token phân biệt quá ngắn
        // (ví dụ "Vitamin A-D" -> ["vitamin", "a", "d"] KHÔNG được khớp bất kỳ
        // sản phẩm "Vitamin *" nào chỉ vì từ "vitamin" dùng chung).
        $words = explode(' ', $cleanName);
        $meaningfulWords = array_values(array_filter(
            $words,
            fn ($w) => mb_strlen($w, 'UTF-8') > 2
        ));

        if (count($meaningfulWords) < 2) {
            return null;
        }

        $query = SanPham::notExpired()
            ->where('so_luong_ton', '>', 0);

        foreach ($meaningfulWords as $word) {
            $query->where(function($q) use ($word) {
                $q->where('ten', 'LIKE', "%{$word}%")
                  ->orWhere('thanh_phan', 'LIKE', "%{$word}%");
            });
        }

        $sanPham = $query->FEFO()->first();

        return $sanPham && !$this->conflictsWithParsedProduct($parsedProduct, $sanPham) ? $sanPham : null;
    }

    /**
     * Đúng khi dòng danh mục rõ ràng không phải sản phẩm mà ảnh thể hiện.
     */
    protected function conflictsWithParsedProduct(array $parsedProduct, SanPham $sanPham): bool
    {
        return $this->hasVitaminLetterConflict(
            $this->normalizeProductText($this->buildProviderIdentityText($parsedProduct)),
            $this->normalizeProductText(implode(' ', array_filter([$sanPham->ten, $sanPham->thanh_phan])))
        );
    }

    protected function findProductByProviderContext(array $parsedProduct): ?SanPham
    {
        $identityTokens = $this->extractMeaningfulProductTokens(
            $this->buildProviderIdentityText($parsedProduct)
        );

        if (empty($identityTokens)) {
            return null;
        }

        $candidates = SanPham::with('danhMuc')
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->FEFO()
            ->get()
            ->map(function (SanPham $sanPham) use ($parsedProduct, $identityTokens) {
                return [
                    'product' => $sanPham,
                    'score' => $this->scoreProductIdentity($parsedProduct, $identityTokens, $sanPham),
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] >= self::IDENTITY_MATCH_MIN_SCORE)
            ->sortByDesc('score')
            ->values();

        $best = $candidates->first();
        if ($best === null) {
            return null;
        }

        // Gần như hoà nhau giữa hai sản phẩm khác nhau nghĩa là dữ liệu ảnh không
        // xác định được sản phẩm nào trong hai sản phẩm. Báo một trong hai là "còn
        // trong kho" sẽ là một đoán đoán, nên để nó không khớp và để caller đưa ra gợi ý.
        // Các dòng cùng tên (lô khác nhau) không phải đối thủ - FEFO đã chọn một dòng.
        $bestName = $this->normalizeProductText($best['product']->ten);
        $rival = $candidates
            ->first(fn (array $candidate): bool => $this->normalizeProductText($candidate['product']->ten) !== $bestName);

        if ($rival !== null && ($best['score'] - $rival['score']) < self::IDENTITY_MATCH_MIN_MARGIN) {
            Log::info('Vision product match rejected as ambiguous', [
                'parsed_name' => $parsedProduct['name'] ?? null,
                'best' => $best['product']->ten,
                'best_score' => $best['score'],
                'rival' => $rival['product']->ten,
                'rival_score' => $rival['score'],
            ]);

            return null;
        }

        return $best['product'];
    }

    /**
     * Các trường xác định sản phẩm mà ảnh thể hiện.
     */
    protected function buildProviderIdentityText(array $parsedProduct): string
    {
        return $this->joinParsedFields($parsedProduct, ['name', 'dosage'], ['active_ingredients', 'generic_names', 'aliases']);
    }

    /**
     * Các trường chỉ mô tả công dụng của sản phẩm. Hữu ích cho gợi ý, nhưng quá
     * chung chung để chứng minh danh tính — mỗi vi bổ sung trong danh mục đều
     * chia sẻ cách diễn đạt như "bổ sung", "tăng cường miễn dịch" hay "uống 1 viên mỗi ngày".
     */
    protected function buildProviderContextText(array $parsedProduct): string
    {
        return $this->joinParsedFields(
            $parsedProduct,
            ['instructions', 'category', 'visible_text', 'description'],
            ['uses', 'health_goals']
        );
    }

    protected function buildProviderProductSearchText(array $parsedProduct): string
    {
        return trim(
            $this->buildProviderIdentityText($parsedProduct)
            . ' '
            . $this->buildProviderContextText($parsedProduct)
        );
    }

    protected function joinParsedFields(array $parsedProduct, array $scalarFields, array $listFields): string
    {
        $parts = [];

        foreach ($scalarFields as $field) {
            $value = trim((string) ($parsedProduct[$field] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        foreach ($listFields as $field) {
            $value = $parsedProduct[$field] ?? [];

            if (is_string($value) && trim($value) !== '') {
                $parts[] = $value;
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_scalar($item) && trim((string) $item) !== '') {
                        $parts[] = (string) $item;
                    }
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Chấm điểm ứng cử viên chỉ trên bằng chứng nhận diện. Trả về 0 khi ảnh và
     * sản phẩm rõ ràng mô tả hai thứ khác nhau.
     */
    protected function scoreProductIdentity(array $parsedProduct, array $identityTokens, SanPham $sanPham): int
    {
        $parsedName = trim((string) ($parsedProduct['name'] ?? ''));
        $normalizedParsedName = $this->normalizeProductText($parsedName);
        $normalizedCleanParsedName = $this->normalizeProductText($this->cleanProductName($parsedName));
        $normalizedProductName = $this->normalizeProductText($sanPham->ten);
        $normalizedProductText = $this->normalizeProductText(implode(' ', array_filter([
            $sanPham->ten,
            $sanPham->thanh_phan,
        ])));

        $identityText = $this->normalizeProductText($this->buildProviderIdentityText($parsedProduct));
        if ($this->hasVitaminLetterConflict($identityText, $normalizedProductText)) {
            return 0;
        }

        $score = 0;

        if ($normalizedParsedName !== '' && $normalizedProductName === $normalizedParsedName) {
            $score += 100;
        }

        if ($normalizedParsedName !== '' && str_contains($normalizedProductText, $normalizedParsedName)) {
            $score += 70;
        }

        if ($normalizedCleanParsedName !== '' && str_contains($normalizedProductText, $normalizedCleanParsedName)) {
            $score += 55;
        }

        foreach ($identityTokens as $token) {
            if (preg_match('/^\d+$/', $token) === 1) {
                $score += preg_match('/\b' . preg_quote($token, '/') . '\s*(?:mg|g|ml|mcg|iu)?\b/u', $normalizedProductText) === 1 ? 20 : 0;
                continue;
            }

            if ($this->isVitaminLetterToken($token, $identityText)) {
                $score += preg_match('/\b(?:vitamin\s*)?' . preg_quote($token, '/') . '\b/u', $normalizedProductText) === 1 ? 20 : 0;
                continue;
            }

            if (in_array($token, self::WEAK_IDENTITY_TOKENS, true)) {
                continue;
            }

            if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $normalizedProductText) === 1) {
                $score += 12;
            }
        }

        return $score;
    }

    /**
     * "Vitamin A" và "Vitamin C 1000mg" là hai sản phẩm khác nhau; từ chung
     * "vitamin" không bao giờ được làm chúng khớp nhau.
     */
    protected function hasVitaminLetterConflict(string $normalizedIdentityText, string $normalizedProductText): bool
    {
        $parsedLetters = $this->extractVitaminLetters($normalizedIdentityText);
        $productLetters = $this->extractVitaminLetters($normalizedProductText);

        if (empty($parsedLetters) || empty($productLetters)) {
            return false;
        }

        return empty(array_intersect($parsedLetters, $productLetters));
    }

    /**
     * @return array<int, string>
     */
    protected function extractVitaminLetters(string $normalizedText): array
    {
        preg_match_all('/\b(?:vitamin|bio)\s*([abcdek])\b/u', $normalizedText, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Điểm lỏng dùng cho các gợi ý "sản phẩm tương tự" thôi. Bằng chứng nhận diện
     * ưu tiên cao nhất, phần công dụng/danh mục trùng lặp dùng để phân thắng thua,
     * và các sản phẩm cùng họ (ví dụ một vitamin khác) xếp trên các vi bổ sung
     * không liên quan.
     */
    protected function scoreProductCandidate(array $parsedProduct, string $queryText, array $queryTokens, SanPham $sanPham): int
    {
        $identityTokens = $this->extractMeaningfulProductTokens(
            $this->buildProviderIdentityText($parsedProduct)
        );
        $identityScore = $this->scoreProductIdentity($parsedProduct, $identityTokens, $sanPham);

        $productText = $this->normalizeProductText(implode(' ', array_filter([
            $sanPham->ten,
            $sanPham->thanh_phan,
            $sanPham->cach_dung,
            $sanPham->danhMuc?->ten,
        ])));

        // Độ thân thiết cùng họ được chấm trên tên/hoạt chất thôi: mỗi vi bổ sung
        // trong danh mục đều chia sẻ tên danh mục "Vitamin và khoáng chất", nên dùng
        // danh mục tại đây sẽ trả thưởng cho tất cả như nhau.
        $productIdentityText = $this->normalizeProductText(implode(' ', array_filter([
            $sanPham->ten,
            $sanPham->thanh_phan,
        ])));

        $contextTokens = array_values(array_diff(
            $this->extractMeaningfulProductTokens($this->buildProviderContextText($parsedProduct)),
            $identityTokens
        ));

        $contextScore = 0;
        foreach ($contextTokens as $token) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $productText) === 1) {
                $contextScore += 3;
            }
        }

        $familyScore = 0;
        $parsedText = $this->normalizeProductText($queryText);
        foreach (['vitamin', 'kem', 'zinc', 'calci', 'calcium', 'sat', 'iron', 'omega', 'probiotic', 'collagen'] as $family) {
            if (str_contains($parsedText, $family) && str_contains($productIdentityText, $family)) {
                $familyScore = 12;
                break;
            }
        }

        return $identityScore * 2 + min($contextScore, 15) + $familyScore;
    }

    protected function normalizeProductText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['đ', 'Đ'], ['d', 'd'], $text);
        // Fold dấu để "Bổ sung kẽm" và "bo sung kem" so sánh bằng nhau trên
        // cả hai bên: phần đã phân tích và phần catalog.
        $text = Str::ascii($text);
        $text = preg_replace('/(\d+)\s*(mg|g|ml|mcg|iu)\b/u', '$1 $2', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function extractMeaningfulProductTokens(string $text): array
    {
        $normalized = $this->normalizeProductText($text);
        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($tokens, function (string $token) use ($normalized): bool {
            if (preg_match('/^\d+$/', $token) === 1) {
                return true;
            }

            if ($this->isVitaminLetterToken($token, $normalized)) {
                return true;
            }

            return mb_strlen($token, 'UTF-8') > 2
                && !in_array($token, ['tablet', 'tablets', 'vien', 'hop', 'chai', 'lo', 'box', 'pack'], true);
        })));
    }

    protected function isVitaminLetterToken(string $token, string $normalizedText): bool
    {
        return in_array($token, ['a', 'b', 'c', 'd', 'e', 'k'], true)
            && preg_match('/\b(?:vitamin|bio)\s*' . preg_quote($token, '/') . '\b/u', $normalizedText) === 1;
    }
    
    /**
     * Làm sạch tên sản phẩm bằng cách bỏ tên thương hiệu, liều lượng và các từ phổ biến.
     *
     * @param string $name
     * @return string
     */
    protected function cleanProductName(string $name): string
    {
        // Chuyển về chữ thường để xử lý
        $cleaned = mb_strtolower($name, 'UTF-8');

        // Chuẩn hoá các ký tự phân cách (gạch nối, en-dash, em-dash, dấu &, dấu cộng, gạch chéo)
        // thành khoảng trắng để "Vitamin A-D", "Vitamin A&D", "Vitamin A+D" được tách token
        // nhất quán.
        $cleaned = preg_replace('/[-–—&+\/]/u', ' ', $cleaned);

        // Bỏ các tên thương hiệu phổ biến
        $brands = ['mylan', 'teva', 'pfizer', 'novartis', 'roche', 'sanofi', 'glaxo', 'bayer'];
        foreach ($brands as $brand) {
            $cleaned = str_replace($brand, '', $cleaned);
        }

        // Bỏ các mẫu liều lượng (50mg, 500mg, 10ml, v.v.)
        $cleaned = preg_replace('/\d+\s*(mg|g|ml|mcg|iu|%)/i', '', $cleaned);

        // Bỏ các từ phổ biến
        $commonWords = ['tablets', 'tablet', 'viên', 'vỉ', 'hộp', 'box', 'pack'];
        foreach ($commonWords as $word) {
            $cleaned = str_replace($word, '', $cleaned);
        }

        // Bỏ khoảng trắng thừa
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }
    
    /**
     * Tính điểm confidence của việc khớp.
     *
     * @param string $parsedName Tên sản phẩm đã phân tích
     * @param string $actualName Tên sản phẩm thật từ database
     * @return float Điểm confidence (0-1)
     */
    protected function calculateMatchConfidenceFromParsedData(array $parsedProduct, SanPham $sanPham): float
    {
        $parsedName = trim((string) ($parsedProduct['name'] ?? ''));
        $baseConfidence = $this->calculateMatchConfidence($parsedName, $sanPham->ten);
        $identityTokens = $this->extractMeaningfulProductTokens(
            $this->buildProviderIdentityText($parsedProduct)
        );
        $identityScore = $this->scoreProductIdentity($parsedProduct, $identityTokens, $sanPham);

        return max($baseConfidence, min(0.95, $identityScore / 100));
    }

    protected function calculateMatchConfidence(string $parsedName, string $actualName): float
    {
        $parsedLower = mb_strtolower($parsedName, 'UTF-8');
        $actualLower = mb_strtolower($actualName, 'UTF-8');
        
        // Khớp chính xác
        if ($parsedLower === $actualLower) {
            return 1.0;
        }

        // Khớp chứa bên trong
        if (strpos($actualLower, $parsedLower) !== false || strpos($parsedLower, $actualLower) !== false) {
            return 0.8;
        }

        // Trùng lặp giữa các từ
        $parsedWords = explode(' ', $parsedLower);
        $actualWords = explode(' ', $actualLower);
        $commonWords = array_intersect($parsedWords, $actualWords);
        
        if (count($commonWords) > 0) {
            return 0.6 * (count($commonWords) / max(count($parsedWords), count($actualWords)));
        }
        
        return 0.3;
    }
    
    /**
     * Tìm các sản phẩm tương tự cho gợi ý.
     *
     * @param string $name Tên SanPham
     * @return array
     */
    protected function findSimilarProductsFromParsedData(array $parsedProduct): array
    {
        $queryText = $this->buildProviderProductSearchText($parsedProduct);
        $words = $this->extractMeaningfulProductTokens($queryText);

        if (empty($words)) {
            return [];
        }

        $sanPhams = SanPham::with('danhMuc')
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->FEFO()
            ->get()
            ->map(function (SanPham $sanPham) use ($parsedProduct, $queryText, $words) {
                return [
                    'product' => $sanPham,
                    'score' => $this->scoreProductCandidate($parsedProduct, $queryText, $words, $sanPham),
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 24)
            ->sortByDesc('score')
            ->take(3)
            ->pluck('product');

        return $sanPhams->map(function(SanPham $sanPham) {
            return [
                'id' => $sanPham->ma_sp,
                'name' => $sanPham->ten,
                'price' => $sanPham->price_vnd,
                'stock_quantity' => $sanPham->so_luong_ton
            ];
        })->toArray();
    }
}
