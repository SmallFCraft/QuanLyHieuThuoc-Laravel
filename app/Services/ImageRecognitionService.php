<?php

namespace App\Services;

use App\Models\ChatbotImage;
use Gemini\Data\Blob;
use Gemini\Enums\MimeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImageRecognitionService
{
    protected AuditLogService $auditLogService;
    protected const GEMINI_MAX_IMAGE_WIDTH = 1600;
    protected const GEMINI_MAX_IMAGE_HEIGHT = 1600;
    protected const GEMINI_JPEG_QUALITY = 75;

    public function __construct(
        AuditLogService $auditLogService
    ) {
        $this->auditLogService = $auditLogService;
    }

    /**
     * Process a prescription image using Gemini Vision AI.
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
            
            // Use Gemini Vision to analyze the image directly
            $analysisResult = $this->analyzeImageWithGemini($imagePath, 'prescription');
            
            if (empty($analysisResult['products'])) {
                Log::warning('No products recognized from prescription image', [
                    'user_id' => $userId,
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
            
            // Log audit
            $this->auditLogService->log(
                action: 'chatbot_image_processed',
                modelType: ChatbotImage::class,
                modelId: null,
                oldValues: null,
                newValues: [
                    'user_id' => $userId,
                    'image_type' => 'prescription',
                    'products_recognized' => count($matched),
                    'processing_time_ms' => $processingTime
                ]
            );
            
            return [
                'success' => true,
                'recognized_products' => $matched,
                'ocr_text' => $analysisResult['description'] ?? '',
                'processing_time_ms' => $processingTime
            ];
        } catch (\Exception $e) {
            Log::error('Prescription processing failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'error' => 'Dạ, em gặp chút khó khăn khi đọc ảnh đơn thuốc. Anh/chị vui lòng gửi lại ảnh rõ hơn hoặc nhập tên thuốc để em tìm nhanh cho mình nhé.'
            ];
        }
    }

    /**
     * Process a receipt image using Gemini Vision AI.
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
            
            // Use Gemini Vision to analyze the image directly
            $analysisResult = $this->analyzeImageWithGemini($imagePath, 'receipt');
            
            if (empty($analysisResult['products'])) {
                Log::warning('No products recognized from receipt image', [
                    'user_id' => $userId,
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
            
            // Log audit
            $this->auditLogService->log(
                action: 'chatbot_image_processed',
                modelType: ChatbotImage::class,
                modelId: null,
                oldValues: null,
                newValues: [
                    'user_id' => $userId,
                    'image_type' => 'receipt',
                    'products_recognized' => count($matched),
                    'processing_time_ms' => $processingTime
                ]
            );
            
            return [
                'success' => true,
                'recognized_products' => $matched,
                'ocr_text' => $analysisResult['description'] ?? '',
                'processing_time_ms' => $processingTime
            ];
        } catch (\Exception $e) {
            Log::error('Receipt processing failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'error' => 'Dạ, em chưa đọc được hóa đơn này. Anh/chị vui lòng gửi lại ảnh rõ nét hơn giúp em với ạ.'
            ];
        }
    }

    /**
     * Analyze image using Gemini Vision AI directly (no OCR needed).
     *
     * @param string $imagePath Absolute path to the image file
     * @param string $type Image type (prescription or receipt)
     * @return array Analysis result with 'products' and 'description'
     * @throws \Exception
     */
    protected function analyzeImageWithGemini(string $imagePath, string $type): array
    {
        [$base64Image, $mimeType] = $this->prepareImageForGemini($imagePath);

        // Build the prompt based on type
        $prompt = $this->buildVisionPrompt($type);

        // Create Blob for Gemini Vision
        $blob = new Blob(
            mimeType: $mimeType,
            data: $base64Image
        );

        try {
            // Call Gemini Vision API
            $apiKey = config('chatbot.gemini.api_key');
            $client = \Gemini::client($apiKey);
            
            $model = $client->generativeModel(
                model: config('chatbot.gemini.model', 'gemini-2.5-flash')
            );

            // Use array format for multimodal input (text + image)
            $response = $model->generateContent([$prompt, $blob]);

            $textContent = '';
            try {
                $textContent = $response->text();
            } catch (\Exception $e) {
                try {
                    $parts = $response->parts();
                    foreach ($parts as $part) {
                        if (isset($part->text)) {
                            $textContent .= $part->text;
                        }
                    }
                } catch (\Exception $e2) {
                    Log::error('Failed to extract Gemini Vision response', [
                        'error' => $e2->getMessage(),
                        'trace' => $e2->getTraceAsString()
                    ]);
                    throw new \Exception('Không thể phân tích hình ảnh. Vui lòng thử lại.');
                }
            }

            // Check if response is too short (likely an error or "I can't help with that")
            if (strlen($textContent) < 20) {
                Log::warning('Gemini Vision returned very short response', [
                    'type' => $type,
                    'response_length' => strlen($textContent),
                    'response' => $textContent
                ]);
                throw new \Exception('Không thể nhận dạng nội dung từ hình ảnh. Hình ảnh có thể không rõ ràng hoặc không phải là ' . ($type === 'prescription' ? 'đơn thuốc' : 'hóa đơn') . '.');
            }

            Log::info('Gemini Vision analysis completed', [
                'type' => $type,
                'response_length' => strlen($textContent),
                'response_preview' => substr($textContent, 0, 200)
            ]);

            // Parse JSON response from Gemini
            return $this->parseGeminiVisionResponse($textContent);
            
        } catch (\Exception $e) {
            // Check for quota exceeded error
            if (strpos($e->getMessage(), 'quota') !== false || strpos($e->getMessage(), 'Quota exceeded') !== false) {
                Log::warning('Gemini API quota exceeded', [
                    'type' => $type,
                    'error' => $e->getMessage()
                ]);
                throw new \Exception('Dạ, hiện tại cửa hàng đang tiếp nhận nhiều yêu cầu nên em chưa xử lý ảnh ngay được. Anh/chị vui lòng đợi một chút hoặc nhập tên thuốc trực tiếp để em hỗ trợ ngay nhé.');
            }
            
            // Check for rate limit error
            if (strpos($e->getMessage(), 'rate limit') !== false || strpos($e->getMessage(), 'Too Many Requests') !== false) {
                Log::warning('Gemini API rate limit hit', [
                    'type' => $type,
                    'error' => $e->getMessage()
                ]);
                throw new \Exception('Dạ, em đang xử lý hơi nhiều dữ liệu. Anh/chị vui lòng đợi khoảng 30 giây rồi gửi lại ảnh giúp em nhé.');
            }
            
            // Re-throw other exceptions
            throw $e;
        }
    }

    /**
     * Build prompt for Gemini Vision based on image type.
     *
     * @param string $type
     * @return string
     */
    protected function buildVisionPrompt(string $type): string
    {
        if ($type === 'prescription') {
            return <<<PROMPT
Bạn là trợ lý AI chuyên phân tích hình ảnh y tế. Nhiệm vụ của bạn là đọc và trích xuất thông tin từ hình ảnh.

Hãy phân tích hình ảnh này và trích xuất TẤT CẢ các loại thuốc/sản phẩm y tế. Hình ảnh có thể là:
- Đơn thuốc (prescription)
- Hộp thuốc (medicine box/package)
- Vỉ thuốc (blister pack)
- Nhãn thuốc (medicine label)

Trả về kết quả theo ĐÚNG định dạng JSON sau (CHỈ JSON, KHÔNG thêm markdown, KHÔNG thêm giải thích):

{
  "description": "Mô tả ngắn về hình ảnh (ví dụ: Hộp thuốc Diclofenac 50mg)",
  "products": [
    {
      "name": "Tên thuốc chính xác (ví dụ: Diclofenac)",
      "dosage": "Liều lượng nếu có (ví dụ: 50mg)",
      "quantity": 1,
      "instructions": "Thông tin sử dụng nếu có"
    }
  ]
}

Lưu ý quan trọng:
- Đọc TẤT CẢ text trên hình ảnh, kể cả tên thương hiệu, tên hoạt chất
- Nếu thấy tên thuốc trên hộp/vỉ/nhãn → trích xuất ra
- Nếu KHÔNG đọc được text hoặc không phải thuốc, trả về: {"description": "Không thể nhận dạng thuốc từ hình ảnh", "products": []}
- Tên thuốc có thể là tên thương hiệu (Mylan Diclofenac) hoặc tên hoạt chất (Diclofenac)
- Đảm bảo JSON hợp lệ
- CHỈ trả về JSON, không thêm text khác
PROMPT;
        } else {
            return <<<PROMPT
Bạn là trợ lý AI chuyên phân tích hóa đơn mua thuốc. Nhiệm vụ của bạn là đọc và trích xuất thông tin từ hình ảnh hóa đơn.

Hãy phân tích hình ảnh này và trích xuất TẤT CẢ các sản phẩm được liệt kê trên hóa đơn.

Trả về kết quả theo ĐÚNG định dạng JSON sau (CHỈ JSON, KHÔNG thêm markdown, KHÔNG thêm giải thích):

{
  "description": "Hóa đơn có X sản phẩm",
  "products": [
    {
      "name": "Tên sản phẩm chính xác",
      "quantity": 1,
      "price": 0
    }
  ]
}

Lưu ý quan trọng:
- Nếu hình ảnh KHÔNG phải hóa đơn hoặc không đọc được, trả về: {"description": "Không thể nhận dạng hóa đơn", "products": []}
- Tên sản phẩm phải chính xác như trên hóa đơn
- Giá và số lượng phải là số
- Đảm bảo JSON hợp lệ
- CHỈ trả về JSON, không thêm text khác
PROMPT;
        }
    }

    /**
     * Parse Gemini Vision response text into structured data.
     *
     * @param string $responseText
     * @return array
     */
    protected function parseGeminiVisionResponse(string $responseText): array
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
            Log::info('Successfully parsed Gemini Vision response', [
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
                Log::info('Successfully parsed Gemini Vision response from extracted JSON', [
                    'products_count' => count($parsed['products'] ?? [])
                ]);
                return [
                    'description' => $parsed['description'] ?? '',
                    'products' => $parsed['products'] ?? []
                ];
            }
        }
        
        Log::warning('Could not parse Gemini Vision response as JSON', [
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
     * Resize and normalize the image before sending it to Gemini.
     *
     * @param string $imagePath
     * @return array{0: string, 1: MimeType}
     */
    protected function prepareImageForGemini(string $imagePath): array
    {
        if (!is_file($imagePath)) {
            throw new \Exception('Unable to read image file.');
        }

        try {
            $manager = new ImageManager(Driver::class);
            $encodedImage = $manager
                ->decodePath($imagePath)
                ->scaleDown(
                    width: self::GEMINI_MAX_IMAGE_WIDTH,
                    height: self::GEMINI_MAX_IMAGE_HEIGHT
                )
                ->encodeUsingMediaType(
                    MimeType::IMAGE_JPEG->value,
                    quality: self::GEMINI_JPEG_QUALITY
                );
        } catch (\Throwable $e) {
            Log::warning('Failed to normalize image before Gemini analysis', [
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Unable to normalize image for Gemini analysis.', previous: $e);
        }

        return [
            base64_encode((string) $encodedImage),
            MimeType::IMAGE_JPEG,
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
        // Ensure upload directory exists (local disk root is storage/app/private/)
        $uploadDir = storage_path('app/private/chatbot-uploads');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $sessionId = session('chat_session_id');
        if (!$sessionId) {
            $sessionId = Str::uuid()->toString();
            session(['chat_session_id' => $sessionId]);
        }

        $filename = Str::uuid() . '.' . $image->extension();
        $path = $image->storeAs('chatbot-uploads', $filename, 'local');
        
        ChatbotImage::create([
            'user_id' => $userId,
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
            
            // Search for product in database with fuzzy matching
            $product = $this->findProductByName($productName);
            
            if ($product) {
                $matched[] = [
                    'parsed_name' => $productName,
                    'matched' => true,
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => $product->price,
                        'stock_quantity' => $product->stock_quantity,
                        'image_url' => $product->image ? asset('storage/' . $product->image) : null,
                        'expiration_date' => $product->expiration_date,
                        'is_expired' => $product->isExpired(),
                    ],
                    'quantity' => $parsedProduct['quantity'] ?? 1,
                    'dosage' => $parsedProduct['dosage'] ?? null,
                    'instructions' => $parsedProduct['instructions'] ?? null,
                    'confidence' => $this->calculateMatchConfidence($productName, $product->name)
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
                    'suggestions' => $this->findSimilarProducts($productName)
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
    protected function findProductByName(string $name): ?\App\Models\Product
    {
        // Clean the name: remove brand names, dosage, common words
        $cleanName = $this->cleanProductName($name);
        
        // Try exact match first
        $product = \App\Models\Product::notExpired()
            ->where('stock_quantity', '>', 0)
            ->where('name', 'LIKE', $name)
            ->FEFO()
            ->first();
            
        if ($product) {
            return $product;
        }
        
        // Try partial match with cleaned name
        $product = \App\Models\Product::notExpired()
            ->where('stock_quantity', '>', 0)
            ->where(function($query) use ($name, $cleanName) {
                $query->where('name', 'LIKE', "%{$name}%")
                      ->orWhere('name', 'LIKE', "%{$cleanName}%")
                      ->orWhere('ingredients', 'LIKE', "%{$cleanName}%");
            })
            ->FEFO()
            ->first();
            
        if ($product) {
            return $product;
        }
        
        // Try word-by-word matching with cleaned name
        $words = explode(' ', $cleanName);
        $query = \App\Models\Product::notExpired()
            ->where('stock_quantity', '>', 0);
            
        foreach ($words as $word) {
            if (strlen($word) > 2) {
                $query->where(function($q) use ($word) {
                    $q->where('name', 'LIKE', "%{$word}%")
                      ->orWhere('ingredients', 'LIKE', "%{$word}%");
                });
            }
        }
        
        return $query->FEFO()->first();
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
    protected function findSimilarProducts(string $name): array
    {
        $words = explode(' ', $name);
        $products = \App\Models\Product::notExpired()
            ->where('stock_quantity', '>', 0)
            ->where(function($query) use ($words) {
                foreach ($words as $word) {
                    if (strlen($word) > 2) {
                        $query->orWhere('name', 'LIKE', "%{$word}%");
                    }
                }
            })
            ->FEFO()
            ->limit(3)
            ->get();
            
        return $products->map(function($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $product->price,
                'stock_quantity' => $product->stock_quantity
            ];
        })->toArray();
    }

    /**
     * Cleanup temporary image file.
     *
     * @param string $imagePath
     * @return void
     */
    public function cleanupImage(string $imagePath): void
    {
        if (file_exists($imagePath)) {
            @unlink($imagePath);
        }
    }
}

