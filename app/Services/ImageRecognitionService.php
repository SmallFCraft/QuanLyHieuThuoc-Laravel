<?php

namespace App\Services;

use App\Exceptions\AiProviderVisionNotSupportedException;
use App\Models\ChatbotImage;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImageRecognitionService
{
    /**
     * Minimum identifying evidence before a catalog product may be reported as
     * the product on the image.
     */
    protected const IDENTITY_MATCH_MIN_SCORE = 30;

    /**
     * Required score gap over the best differently-named rival, so a coin-flip
     * between two products is reported as "not found" instead of a guess.
     */
    protected const IDENTITY_MATCH_MIN_MARGIN = 12;

    /**
     * Tokens that appear across most catalog rows, so they never identify a product.
     */
    protected const WEAK_IDENTITY_TOKENS = [
        'vitamin', 'vitamins', 'thuoc', 'duoc', 'pham', 'san', 'hang', 'khoang', 'chat',
        'bao', 'gia', 'cong', 'dung', 'lieu', 'nang', 'cung', 'mem', 'tablet', 'tablets',
        'capsule', 'capsules', 'supplement', 'supplements', 'complex', 'extra', 'plus',
    ];

    protected AuditLogService $auditLogService;
    protected AiProviderService $aiProviderService;
    protected const VISION_MAX_IMAGE_WIDTH = 1600;
    protected const VISION_MAX_IMAGE_HEIGHT = 1600;
    protected const VISION_JPEG_QUALITY = 75;
    protected const VISION_MIME_TYPE = 'image/jpeg';

    public function __construct(
        AuditLogService $auditLogService,
        AiProviderService $aiProviderService
    ) {
        $this->auditLogService = $auditLogService;
        $this->aiProviderService = $aiProviderService;
    }

    /**
     * Process a prescription image using the configured AI provider.
     *
     * @param UploadedFile $image
     * @param int $userId
     * @return array
     */
    public function processPrescription(UploadedFile $image, int $userId): array
    {
        $startTime = microtime(true);
        
        try {
            $imagePath = $this->storeImage($image, $userId, 'prescription');
            
            $analysisResult = $this->analyzeImageWithProvider($imagePath, 'prescription');
            
            if (empty($analysisResult['products'])) {
                Log::warning('No products recognized from prescription image', [
                    'MaKH' => $userId,
                    'description' => $analysisResult['description'] ?? 'N/A'
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Dạ, em chưa đọc được thông tin trên ảnh này. Anh/chị vui lòng chụp lại rõ nét hơn hoặc gửi ảnh chụp trực tiếp nhãn trên hộp thuốc giúp em nhé.',
                    'description' => $analysisResult['description'] ?? ''
                ];
            }
            
            $matched = $this->matchProducts($analysisResult['products']);
            
            $processingTime = (microtime(true) - $startTime) * 1000;
            $this->markLatestImageAnalysisResult($userId, 'prescription', $analysisResult, $matched);
            
            // Log audit
            $this->auditLogService->log(
                action: 'chatbot_image_processed',
                modelType: ChatbotImage::class,
                modelId: null,
                oldValues: null,
                newValues: [
                    'MaKH' => $userId,
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
                'processing_time_ms' => $processingTime
            ];
        } catch (\Exception $e) {
            Log::error('Prescription processing failed', [
                'MaKH' => $userId,
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
     * Process a receipt image using the configured AI provider.
     *
     * @param UploadedFile $image
     * @param int $userId
     * @return array
     */
    public function processReceipt(UploadedFile $image, int $userId): array
    {
        $startTime = microtime(true);
        
        try {
            $imagePath = $this->storeImage($image, $userId, 'receipt');
            
            $analysisResult = $this->analyzeImageWithProvider($imagePath, 'receipt');
            
            if (empty($analysisResult['products'])) {
                Log::warning('No products recognized from receipt image', [
                    'MaKH' => $userId,
                    'description' => $analysisResult['description'] ?? 'N/A'
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Dạ, em chưa nhận diện được các mặt hàng trên hóa đơn này. Anh/chị vui lòng chụp lại ảnh rõ hơn hoặc gửi tên sản phẩm để em kiểm tra nhanh ạ.',
                    'description' => $analysisResult['description'] ?? ''
                ];
            }
            
            $matched = $this->matchProducts($analysisResult['products']);
            
            $processingTime = (microtime(true) - $startTime) * 1000;
            $this->markLatestImageAnalysisResult($userId, 'receipt', $analysisResult, $matched);
            
            // Log audit
            $this->auditLogService->log(
                action: 'chatbot_image_processed',
                modelType: ChatbotImage::class,
                modelId: null,
                oldValues: null,
                newValues: [
                    'MaKH' => $userId,
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
                'processing_time_ms' => $processingTime
            ];
        } catch (\Exception $e) {
            Log::error('Receipt processing failed', [
                'MaKH' => $userId,
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
     * Analyze image using the configured AI provider.
     *
     * @param string $imagePath Absolute path to the image file
     * @param string $type Image type (prescription or receipt)
     * @return array Analysis result with 'products' and 'description'
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
            $message = mb_strtolower($e->getMessage(), 'UTF-8');

            if (str_contains($message, 'quota') || str_contains($message, 'rate limit') || str_contains($message, 'too many requests') || str_contains($message, '429')) {
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
     * Build prompt for provider vision based on image type.
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
     * Parse provider vision response text into structured data.
     *
     * @param string $responseText
     * @return array
     */
    protected function parseVisionProviderResponse(string $responseText): array
    {
        // Try to extract JSON from the response
        $jsonText = trim($responseText);
        
        // Remove markdown code blocks if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $jsonText, $matches)) {
            $jsonText = trim($matches[1]);
        }
        
        // Try direct JSON parse
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
        
        // Try to find JSON object in the text
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
     * Resize and normalize the image before sending it to the AI provider.
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
     * Store uploaded image to local disk.
     *
     * @param UploadedFile $image
     * @param int $userId
     * @param string $type
     * @return string Absolute path to stored image
     */
    protected function storeImage(UploadedFile $image, int $userId, string $type): string
    {
        $storagePath = (string) config('chatbot.images.storage_path', 'chatbot-uploads');

        // Ensure upload directory exists (local disk root is storage/app/private/)
        $uploadDir = storage_path('app/private/' . $storagePath);
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $sessionId = session('chat_session_id');
        if (!$sessionId) {
            $sessionId = Str::uuid()->toString();
            session(['chat_session_id' => $sessionId]);
        }

        $filename = Str::uuid() . '.' . $image->extension();
        $path = $image->storeAs($storagePath, $filename, 'local');
        
        ChatbotImage::create([
            'MaKH' => $userId,
            'session_id' => $sessionId,
            'filename' => $filename,
            'original_name' => $image->getClientOriginalName(),
            'image_path' => $path,
            'file_path' => $path,
            'file_size' => $image->getSize(),
            'mime_type' => $image->getMimeType(),
            'image_type' => $type
        ]);
        
        return storage_path('app/private/' . $path);
    }

    protected function markLatestImageAnalysisDebug(int $userId, string $type, array $debug): void
    {
        $sessionId = session('chat_session_id');
        if (!$sessionId) {
            return;
        }

        $image = ChatbotImage::where('MaKH', $userId)
            ->where('session_id', $sessionId)
            ->where('image_type', $type)
            ->latest('MaAnh')
            ->first();

        if (!$image) {
            return;
        }

        $image->update([
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

        $image = ChatbotImage::where('MaKH', $userId)
            ->where('session_id', $sessionId)
            ->where('image_type', $type)
            ->latest('MaAnh')
            ->first();

        if (!$image) {
            return;
        }

        $provider = $this->aiProviderService->describeLastProvider();

        $image->update([
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
     * Match parsed products against the database.
     *
     * @param array $parsedProducts
     * @return array
     */
    public function matchProducts(array $parsedProducts): array
    {
        $matched = [];
        
        foreach ($parsedProducts as $parsedProduct) {
            $productName = $parsedProduct['name'] ?? '';
            
            if (empty($productName)) {
                continue;
            }
            
            // Search for product in database with provider-extracted context.
            $product = $this->findProductByParsedData($parsedProduct);
            
            if ($product) {
                $matched[] = [
                    'parsed_name' => $productName,
                    'matched' => true,
                    'product' => [
                        'id' => $product->MaSP,
                        'name' => $product->name,
                        'price' => $product->price_vnd,
                        'stock_quantity' => $product->stock_quantity,
                        'image_url' => $product->image_url,
                        'expiration_date' => $product->expiration_date,
                        'is_expired' => $product->isExpired(),
                    ],
                    'quantity' => $parsedProduct['quantity'] ?? 1,
                    'dosage' => $parsedProduct['dosage'] ?? null,
                    'instructions' => $parsedProduct['instructions'] ?? null,
                    'confidence' => $this->calculateMatchConfidenceFromParsedData($parsedProduct, $product)
                ];
            } else {
                // Product not found
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
     * Find product by name with fuzzy matching.
     *
     * @param string $name Product name to search
     * @return \App\Models\Product|null
     */
    protected function findProductByParsedData(array $parsedProduct): ?Product
    {
        $name = trim((string) ($parsedProduct['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        // Clean the name: remove brand names, dosage, common words
        $cleanName = $this->cleanProductName($name);
        
        // Try exact match first
        $product = Product::notExpired()
            ->where('stock_quantity', '>', 0)
            ->where('name', 'LIKE', $name)
            ->FEFO()
            ->first();
            
        if ($product) {
            return $product;
        }
        
        // Try partial match with cleaned name
        $product = Product::notExpired()
            ->where('stock_quantity', '>', 0)
            ->where(function($query) use ($name, $cleanName) {
                $query->where('name', 'LIKE', "%{$name}%")
                      ->orWhere('name', 'LIKE', "%{$cleanName}%")
                      ->orWhere('ingredients', 'LIKE', "%{$cleanName}%");
            })
            ->FEFO()
            ->first();

        // "Vitamin A" must not settle for "Vitamin C 1000mg" just because the
        // cleaned name is a substring of it.
        if ($product && !$this->conflictsWithParsedProduct($parsedProduct, $product)) {
            return $product;
        }

        $scoredProduct = $this->findProductByProviderContext($parsedProduct);
        if ($scoredProduct) {
            return $scoredProduct;
        }
        
        // Try word-by-word matching with cleaned name.
        // Require at least 2 meaningful words (>2 chars) so we don't return
        // a generic match when the differentiating tokens are too short
        // (e.g. "Vitamin A-D" -> ["vitamin", "a", "d"] should NOT match any
        // "Vitamin *" product just because of the shared "vitamin" word).
        $words = explode(' ', $cleanName);
        $meaningfulWords = array_values(array_filter(
            $words,
            fn ($w) => mb_strlen($w, 'UTF-8') > 2
        ));

        if (count($meaningfulWords) < 2) {
            return null;
        }

        $query = Product::notExpired()
            ->where('stock_quantity', '>', 0);

        foreach ($meaningfulWords as $word) {
            $query->where(function($q) use ($word) {
                $q->where('name', 'LIKE', "%{$word}%")
                  ->orWhere('ingredients', 'LIKE', "%{$word}%");
            });
        }

        $product = $query->FEFO()->first();

        return $product && !$this->conflictsWithParsedProduct($parsedProduct, $product) ? $product : null;
    }

    /**
     * True when the catalog row clearly is not what the image shows.
     */
    protected function conflictsWithParsedProduct(array $parsedProduct, Product $product): bool
    {
        return $this->hasVitaminLetterConflict(
            $this->normalizeProductText($this->buildProviderIdentityText($parsedProduct)),
            $this->normalizeProductText(implode(' ', array_filter([$product->name, $product->ingredients])))
        );
    }

    protected function findProductByProviderContext(array $parsedProduct): ?Product
    {
        $identityTokens = $this->extractMeaningfulProductTokens(
            $this->buildProviderIdentityText($parsedProduct)
        );

        if (empty($identityTokens)) {
            return null;
        }

        $candidates = Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->FEFO()
            ->get()
            ->map(function (Product $product) use ($parsedProduct, $identityTokens) {
                return [
                    'product' => $product,
                    'score' => $this->scoreProductIdentity($parsedProduct, $identityTokens, $product),
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] >= self::IDENTITY_MATCH_MIN_SCORE)
            ->sortByDesc('score')
            ->values();

        $best = $candidates->first();
        if ($best === null) {
            return null;
        }

        // A near-tie between two different products means the image data does not
        // identify either of them. Reporting one as "found in stock" would be a
        // guess, so leave it unmatched and let the caller offer suggestions.
        // Same-name rows (different batches) are not rivals - FEFO already picked one.
        $bestName = $this->normalizeProductText($best['product']->name);
        $rival = $candidates
            ->first(fn (array $candidate): bool => $this->normalizeProductText($candidate['product']->name) !== $bestName);

        if ($rival !== null && ($best['score'] - $rival['score']) < self::IDENTITY_MATCH_MIN_MARGIN) {
            Log::info('Vision product match rejected as ambiguous', [
                'parsed_name' => $parsedProduct['name'] ?? null,
                'best' => $best['product']->name,
                'best_score' => $best['score'],
                'rival' => $rival['product']->name,
                'rival_score' => $rival['score'],
            ]);

            return null;
        }

        return $best['product'];
    }

    /**
     * Fields that identify which product the image shows.
     */
    protected function buildProviderIdentityText(array $parsedProduct): string
    {
        return $this->joinParsedFields($parsedProduct, ['name', 'dosage'], ['active_ingredients', 'generic_names', 'aliases']);
    }

    /**
     * Fields that only describe the product's purpose. Useful for suggestions,
     * but far too generic to prove identity - every supplement in the catalog
     * shares wording like "bo sung", "tang cuong mien dich" or "uong 1 vien moi ngay".
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
     * Score a candidate on identifying evidence only. Returns 0 when the image
     * and the product clearly describe different things.
     */
    protected function scoreProductIdentity(array $parsedProduct, array $identityTokens, Product $product): int
    {
        $parsedName = trim((string) ($parsedProduct['name'] ?? ''));
        $normalizedParsedName = $this->normalizeProductText($parsedName);
        $normalizedCleanParsedName = $this->normalizeProductText($this->cleanProductName($parsedName));
        $normalizedProductName = $this->normalizeProductText($product->name);
        $normalizedProductText = $this->normalizeProductText(implode(' ', array_filter([
            $product->name,
            $product->ingredients,
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
     * "Vitamin A" and "Vitamin C 1000mg" are different products; a shared word
     * "vitamin" must never make them match.
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
     * Loose score used for "similar product" suggestions only. Identity evidence
     * dominates, purpose/category overlap breaks ties, and products from the same
     * family (e.g. another vitamin) outrank unrelated supplements.
     */
    protected function scoreProductCandidate(array $parsedProduct, string $queryText, array $queryTokens, Product $product): int
    {
        $identityTokens = $this->extractMeaningfulProductTokens(
            $this->buildProviderIdentityText($parsedProduct)
        );
        $identityScore = $this->scoreProductIdentity($parsedProduct, $identityTokens, $product);

        $productText = $this->normalizeProductText(implode(' ', array_filter([
            $product->name,
            $product->ingredients,
            $product->usage,
            $product->category?->name,
        ])));

        // Family affinity is judged on name/ingredients only: every supplement in
        // the catalog shares the category name "Vitamin va khoang chat", so using
        // the category here would give the bonus to all of them equally.
        $productIdentityText = $this->normalizeProductText(implode(' ', array_filter([
            $product->name,
            $product->ingredients,
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
        // Fold diacritics so "Bổ sung kẽm" and "bo sung kem" compare equal on
        // both the parsed and the catalog side.
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
     * Clean product name by removing brand names, dosage, and common words.
     *
     * @param string $name
     * @return string
     */
    protected function cleanProductName(string $name): string
    {
        // Convert to lowercase for processing
        $cleaned = mb_strtolower($name, 'UTF-8');

        // Normalize separators (hyphen, en-dash, em-dash, ampersand, plus, slash)
        // to spaces so "Vitamin A-D", "Vitamin A&D", "Vitamin A+D" tokenize
        // consistently.
        $cleaned = preg_replace('/[-–—&+\/]/u', ' ', $cleaned);

        // Remove common brand names
        $brands = ['mylan', 'teva', 'pfizer', 'novartis', 'roche', 'sanofi', 'glaxo', 'bayer'];
        foreach ($brands as $brand) {
            $cleaned = str_replace($brand, '', $cleaned);
        }
        
        // Remove dosage patterns (50mg, 500mg, 10ml, etc.)
        $cleaned = preg_replace('/\d+\s*(mg|g|ml|mcg|iu|%)/i', '', $cleaned);
        
        // Remove common words
        $commonWords = ['tablets', 'tablet', 'viên', 'vỉ', 'hộp', 'box', 'pack'];
        foreach ($commonWords as $word) {
            $cleaned = str_replace($word, '', $cleaned);
        }
        
        // Remove extra spaces
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);
        
        return $cleaned;
    }
    
    /**
     * Calculate match confidence score.
     *
     * @param string $parsedName Parsed product name
     * @param string $actualName Actual product name from database
     * @return float Confidence score (0-1)
     */
    protected function calculateMatchConfidenceFromParsedData(array $parsedProduct, Product $product): float
    {
        $parsedName = trim((string) ($parsedProduct['name'] ?? ''));
        $baseConfidence = $this->calculateMatchConfidence($parsedName, $product->name);
        $identityTokens = $this->extractMeaningfulProductTokens(
            $this->buildProviderIdentityText($parsedProduct)
        );
        $identityScore = $this->scoreProductIdentity($parsedProduct, $identityTokens, $product);

        return max($baseConfidence, min(0.95, $identityScore / 100));
    }

    protected function calculateMatchConfidence(string $parsedName, string $actualName): float
    {
        $parsedLower = mb_strtolower($parsedName, 'UTF-8');
        $actualLower = mb_strtolower($actualName, 'UTF-8');
        
        // Exact match
        if ($parsedLower === $actualLower) {
            return 1.0;
        }
        
        // Contains match
        if (strpos($actualLower, $parsedLower) !== false || strpos($parsedLower, $actualLower) !== false) {
            return 0.8;
        }
        
        // Word overlap
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
     * @param string $name Product name
     * @return array
     */
    protected function findSimilarProductsFromParsedData(array $parsedProduct): array
    {
        $queryText = $this->buildProviderProductSearchText($parsedProduct);
        $words = $this->extractMeaningfulProductTokens($queryText);

        if (empty($words)) {
            return [];
        }

        $products = Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->FEFO()
            ->get()
            ->map(function (Product $product) use ($parsedProduct, $queryText, $words) {
                return [
                    'product' => $product,
                    'score' => $this->scoreProductCandidate($parsedProduct, $queryText, $words, $product),
                ];
            })
            ->filter(fn (array $candidate): bool => $candidate['score'] >= 24)
            ->sortByDesc('score')
            ->take(3)
            ->pluck('product');

        return $products->map(function(Product $product) {
            return [
                'id' => $product->MaSP,
                'name' => $product->name,
                'price' => $product->price_vnd,
                'stock_quantity' => $product->stock_quantity
            ];
        })->toArray();
    }
}
