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
     * Luong bang chung nhan dien toi thieu truoc khi mot san pham trong danh muc
     * duoc bao la san pham tren anh.
     */
    protected const IDENTITY_MATCH_MIN_SCORE = 30;

    /**
     * Khoang cach diem bat buoc so voi doi thu ten khac tot nhat, de mot lua chon
     * 50/50 giua hai san pham duoc bao la "khong tim thay" chu khong phai doan.
     */
    protected const IDENTITY_MATCH_MIN_MARGIN = 12;

    /**
     * Cac token xuat hien trong phan lon dong danh muc, nen chung khong bao gio
     * xac dinh duoc mot san pham.
     */
    protected const WEAK_IDENTITY_TOKENS = [
        'vitamin', 'vitamins', 'thuoc', 'duoc', 'pham', 'san', 'hang', 'khoang', 'chat',
        'bao', 'gia', 'cong', 'dung', 'lieu', 'nang', 'cung', 'mem', 'tablet', 'tablets',
        'capsule', 'capsules', 'supplement', 'supplements', 'complex', 'extra', 'plus',
    ];

    protected NhatKyKiemToanService $nhatKyKiemToanService;
    protected AiProviderService $aiProviderService;

    /**
     * MaAnh cua dong luuAnh() vua ghi, de caller co the lien ket luot chat
     * voi cac byte tren dia. Lich su chat can lien ket nay de render lai anh tai
     * len sau khi tai lai trang — bubble truc tiep dung blob: URL chet theo trang.
     */
    protected ?int $maAnhLuuCuoi = null;

    /**
     * MaAnh cua dong luuAnh() vua ghi, de caller co the lien ket luot chat
     * voi cac byte tren dia. Lich su chat can lien ket nay de render lai anh tai
     * len sau khi tai lai trang — bubble truc tiep dung blob: URL chet theo trang.
     *
     * Doc no SAU MOI ket qua: file duoc luu truoc khi phan tich chay, nen mot anh
     * bi tu choi hoac khong doc duoc van co cai gi do de hien thi.
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
     * Xu ly anh don thuoc bang nha cung cap AI da cau hinh.
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
            
            // Ghi nhat ky kiem toan
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
     * Xu ly anh hoa don bang nha cung cap AI da cau hinh.
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
            
            // Ghi nhat ky kiem toan
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
     * Phan tich anh bang nha cung cap AI da cau hinh.
     *
     * @param string $imagePath Duong dan tuyet doi den file anh
     * @param string $type Loai anh (prescription hoac receipt)
     * @return array Ket qua phan tich gom 'products' va 'description'
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
     * Xay dung prompt cho vision cua nha cung cap theo loai anh.
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
     * Phan tich van ban tra loi cua provider vision thanh du lieu co cau truc.
     *
     * @param string $responseText
     * @return array
     */
    protected function parseVisionProviderResponse(string $responseText): array
    {
        // Co thu trich xuat JSON tu cau tra loi
        $jsonText = trim($responseText);

        // Go bo cac markdown code block neu co
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $jsonText, $matches)) {
            $jsonText = trim($matches[1]);
        }

        // Co thu parse JSON truc tiep
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
        
        // Co thu tim object JSON trong van ban
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
     * Resize va chuan hoa anh truoc khi gui cho nha cung cap AI.
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
     * Luu anh tai len vao local disk.
     *
     * @param UploadedFile $anh
     * @param int $userId
     * @param string $type
     * @return string Duong dan tuyet doi den anh da luu
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

        // Resolve qua disk thay vi storage_path(): root hardcode trung lap voi
        // filesystems.local va lam Storage::fake() vo dung, nen moi test upload
        // deu ghi vao thu muc storage that.
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
     * Khop cac san pham da parse voi database.
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

            // Tim san pham trong database kem ngu canh provider da trich xuat.
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
                // Khong tim thay SanPham
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
     * Tim san pham theo ten voi fuzzy matching.
     *
     * @param string $name Ten SanPham can tim
     * @return \App\Models\SanPham|null
     */
    protected function findProductByParsedData(array $parsedProduct): ?SanPham
    {
        $name = trim((string) ($parsedProduct['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        // Don ten: bo ten thuong mai, lieu luong, tu thong thuong
        $cleanName = $this->cleanProductName($name);

        // Co thu khop chinh xac truoc
        $sanPham = SanPham::notExpired()
            ->where('so_luong_ton', '>', 0)
            ->where('ten', 'LIKE', $name)
            ->FEFO()
            ->first();

        if ($sanPham) {
            return $sanPham;
        }

        // Co thu khop mot phan voi ten da don
        $sanPham = SanPham::notExpired()
            ->where('so_luong_ton', '>', 0)
            ->where(function($query) use ($name, $cleanName) {
                $query->where('ten', 'LIKE', "%{$name}%")
                      ->orWhere('ten', 'LIKE', "%{$cleanName}%")
                      ->orWhere('thanh_phan', 'LIKE', "%{$cleanName}%");
            })
            ->FEFO()
            ->first();

        // "Vitamin A" khong duoc an xong voi "Vitamin C 1000mg" chi vi ten da don
        // la mot substring cua no.
        if ($sanPham && !$this->conflictsWithParsedProduct($parsedProduct, $sanPham)) {
            return $sanPham;
        }

        $scoredProduct = $this->findProductByProviderContext($parsedProduct);
        if ($scoredProduct) {
            return $scoredProduct;
        }
        
        // Co thu khop tung tu voi ten da don.
        // Yeu cau it nhat 2 tu co nghia (>2 ky tu) de khong tra ve mot khop
        // chung chung khi cac token phan biet qua ngan
        // (vi du "Vitamin A-D" -> ["vitamin", "a", "d"] KHONG duoc khop bat ky
        // san pham "Vitamin *" nao chi vi tu "vitamin" dung chung).
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
     * Dung khi dong danh muc ro rang khong phai san pham ma anh the hien.
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

        // Gan nhu hoa nhau giua hai san pham khac nhau nghia la du lieu anh khong
        // xac dinh duoc san pham nao trong hai san pham. Bao mot trong hai la "con
        // trong kho" se la mot doan doan, nen de no khop va de caller dua ra goi y.
        // Cac dong cung ten (lo khac nhau) khong phai doi thu - FEFO da chon mot dong.
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
     * Cac truong xac dinh san pham ma anh the hien.
     */
    protected function buildProviderIdentityText(array $parsedProduct): string
    {
        return $this->joinParsedFields($parsedProduct, ['name', 'dosage'], ['active_ingredients', 'generic_names', 'aliases']);
    }

    /**
     * Cac truong chi mo ta cong dung cua san pham. Huu ich cho goi y, nhung qua
     * chung chung de chung minh danh tinh — moi vi bo sung trong danh muc deu
     * chia se cach dien dat nhu "bo sung", "tang cuong mien dich" hay "uong 1 vien moi ngay".
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
     * Cham diem ung cu vien chi tren bang chung nhan dien. Tra ve 0 khi anh va
     * san pham ro rang mo ta hai thu khac nhau.
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
     * "Vitamin A" va "Vitamin C 1000mg" la hai san pham khac nhau; tu chung
     * "vitamin" khong bao gio duoc lam chung khop nhau.
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
     * Diem long dung cho cac goi y "san pham tuong tu" thoi. Bang chung nhan dien
     * uu tien cao nhat, phan cong dung/danh muc trung lap dung de phan thang thua,
     * va cac san pham cung ho (vi du mot vitamin khac) xep tren cac vi bo sung
     * khong lien quan.
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

        // Do than thiety cung ho duoc cham tren ten/hoat chat thoi: moi vi bo sung
        // trong danh muc deu chia se ten danh muc "Vitamin va khoang chat", nen dung
        // danh muc tai day se tra thuong cho tat ca nhu nhau.
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
        // Fold dau de "Bo sung kem" va "bo sung kem" so sanh bang nhau tren
        // ca hai ben: phan da phan tich va phan catalog.
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
     * Lam sach ten san pham bang cach bo ten thuong hieu, lieu luong va cac tu pho bien.
     *
     * @param string $name
     * @return string
     */
    protected function cleanProductName(string $name): string
    {
        // Chuyen ve chu thuong de xu ly
        $cleaned = mb_strtolower($name, 'UTF-8');

        // Chuan hoa cac ky tu phan cach (gach noi, en-dash, em-dash, dau &, dau cong, gach cheo)
        // thanh khoang trang de "Vitamin A-D", "Vitamin A&D", "Vitamin A+D" duoc tach token
        // nhat quan.
        $cleaned = preg_replace('/[-–—&+\/]/u', ' ', $cleaned);

        // Bo cac ten thuong hieu pho bien
        $brands = ['mylan', 'teva', 'pfizer', 'novartis', 'roche', 'sanofi', 'glaxo', 'bayer'];
        foreach ($brands as $brand) {
            $cleaned = str_replace($brand, '', $cleaned);
        }
        
        // Bo cac mau lieu luong (50mg, 500mg, 10ml, v.v.)
        $cleaned = preg_replace('/\d+\s*(mg|g|ml|mcg|iu|%)/i', '', $cleaned);

        // Bo cac tu pho bien
        $commonWords = ['tablets', 'tablet', 'viên', 'vỉ', 'hộp', 'box', 'pack'];
        foreach ($commonWords as $word) {
            $cleaned = str_replace($word, '', $cleaned);
        }
        
        // Bo khoang trang thua
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }
    
    /**
     * Tinh diem confidence cua viec khop.
     *
     * @param string $parsedName Ten san pham da phan tich
     * @param string $actualName Ten san pham that tu database
     * @return float Diem confidence (0-1)
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
        
        // Khop chinh xac
        if ($parsedLower === $actualLower) {
            return 1.0;
        }

        // Khop chua ben trong
        if (strpos($actualLower, $parsedLower) !== false || strpos($parsedLower, $actualLower) !== false) {
            return 0.8;
        }

        // Trung lap giua cac tu
        $parsedWords = explode(' ', $parsedLower);
        $actualWords = explode(' ', $actualLower);
        $commonWords = array_intersect($parsedWords, $actualWords);
        
        if (count($commonWords) > 0) {
            return 0.6 * (count($commonWords) / max(count($parsedWords), count($actualWords)));
        }
        
        return 0.3;
    }
    
    /**
     * Find similar products for suggestions.
     *
     * @param string $name SanPham name
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
