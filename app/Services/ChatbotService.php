<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ChatbotService
{
    protected $aiService;
    protected InventoryService $inventoryService;
    protected CheckoutService $checkoutService;
    protected AuditLogService $auditLogService;

    /**
     * System prompt for AI assistant in Vietnamese.
     * Defines the role, responsibilities, and behavior of the pharmacy assistant.
     */
    protected const SYSTEM_PROMPT = <<<'PROMPT'
Bạn là trợ lý AI của nhà thuốc Medicare Pharmacy. Nhiệm vụ chính:
1. Giúp tìm kiếm dược phẩm và tư vấn sản phẩm chăm sóc sức khỏe.
2. Đề xuất sản phẩm dựa trên triệu chứng (luôn dặn dò tham khảo bác sĩ).
3. KHÔNG đưa ra chẩn đoán y khoa nghiêm trọng.
4. Trả lời tiếng Việt, ngắn gọn, súc tích và thân thiện.

QUY TẮC TIẾT KIỆM TÀI NGUYÊN (VÔ CÙNG QUAN TRỌNG):
- Nếu người dùng chỉ chào hỏi hoặc nói những câu từ trống không/ngắn gọn, hãy chào lại và hỏi xem họ cần mua gì. TUYỆT ĐỐI KHÔNG gọi function `search_products`.
- Nếu người dùng đưa ra các chủ đề ngoài ngành y tế, sức khỏe, làm đẹp, thuốc men (ví dụ: giải trí, máy tính, ăn uống linh tinh, v.v.), hãy từ chối khéo léo. TUYỆT ĐỐI KHÔNG gọi function.
- CHỈ GỌI function `search_products` khi người dùng THỰC SỰ đang hỏi mua một loại thuốc/sản phẩm cụ thể hoặc đang kể lể về các triệu chứng bệnh lý mà họ đang mắc phải.

Khi đáp ứng yêu cầu tìm sản phẩm:
- Gọi function `search_products` với keyword ngắn gọn, chính xác.
- Đưa ra lời khuyên sử dụng rất ngắn gọn và thông báo các sản phẩm tìm được.
PROMPT;

    /**
     * Function schema for OpenAI function calling.
     * Defines the search_products function for structured product queries.
     *
     * @return array
     */
    protected function getFunctionSchema(): array
    {
        return [
            [
                'name' => 'search_products',
                'description' => 'Tìm kiếm sản phẩm trong cơ sở dữ liệu',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Từ khóa tìm kiếm (tên sản phẩm, thành phần, công dụng)'
                        ],
                        'category' => [
                            'type' => 'string',
                            'description' => 'Danh mục sản phẩm (optional)'
                        ],
                        'symptoms' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Danh sách triệu chứng (optional)'
                        ]
                    ],
                    'required' => ['query']
                ]
            ]
        ];
    }

    /**
     * Get the system prompt for AI assistant.
     *
     * @return string
     */
    public function getSystemPrompt(): string
    {
        return self::SYSTEM_PROMPT;
    }

    /**
     * Initialize ChatbotService with dependency injection.
     *
     * @param InventoryService $inventoryService
     * @param CheckoutService $checkoutService
     * @param AuditLogService $auditLogService
     */
    public function __construct(
        InventoryService $inventoryService,
        CheckoutService $checkoutService,
        AuditLogService $auditLogService
    ) {
        // Use Gemini AI service
        $this->aiService = app(GeminiService::class);
            
        $this->inventoryService = $inventoryService;
        $this->checkoutService = $checkoutService;
        $this->auditLogService = $auditLogService;
    }

    /**
     * Validate if message is meaningful enough to process.
     *
     * @param string $message User message
     * @return array Validation result with success flag and reason
     */
    public function validateMessage(string $message): array
    {
        $trimmed = trim($message);
        $length = mb_strlen($trimmed, 'UTF-8');
        
        if ($length < 2) {
            return [
                'valid' => false,
                'reason' => 'Xin chào! Bạn cần tìm thuốc hay tư vấn triệu chứng gì ạ?'
            ];
        }
        
        // Spam/repeated characters detection
        if (preg_match('/^([a-zA-Z0-9À-ỹ])\1{4,}$/u', $trimmed)) {
            return [
                'valid' => false,
                'reason' => 'Vui lòng nhập câu hỏi hoặc mô tả triệu chứng cụ thể hơn.'
            ];
        }
        
        // Contains only special characters
        if (preg_match('/^[^a-zA-ZÀ-ỹ0-9\s]+$/', $trimmed)) {
            return [
                'valid' => false,
                'reason' => 'Tôi chưa hiểu ý bạn. Vui lòng nhập thông tin bằng chữ.'
            ];
        }
        
        // Intercept common meaningless greetings to save API wrapper tokens
        $meaninglessGreetings = ['hi', 'hello', 'alo', 'chào', 'xin chào', 'chao', 'xin chao', 'ê', 'hey', 'có ai không', 'bot'];
        $cleanMessage = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $trimmed), 'UTF-8');
        if (in_array(trim($cleanMessage), $meaninglessGreetings)) {
            return [
                'valid' => false,
                'reason' => 'Xin chào! Tôi là trợ lý AI của Medicare Pharmacy. Bạn cần tìm sản phẩm hay tư vấn sức khỏe gì hôm nay?'
            ];
        }
        
        return ['valid' => true];
    }

    /**
     * Process user message and generate AI response.
     *
     * @param string $message User message content
     * @param int $userId User ID
     * @param array $context Conversation context
     * @return array Response with AI message and metadata
     * @throws \Exception
     */
    public function processMessage(string $message, int $userId, array $context): array
    {
        // Validate message first
        $validation = $this->validateMessage($message);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'response' => $validation['reason'],
                'products' => [],
                'explanations' => [],
                'show_disclaimer' => false,
                'validation_error' => true
            ];
        }
        
        $startTime = microtime(true);
        
        try {
            // ---> AI QUOTA SAVER: LOCAL QUICK SEARCH <---
            // Bypass Gemini API for simple queries (e.g. asking for price, product name) to save the 20 limits/day.
            if (mb_strlen($message, 'UTF-8') < 50 && !$this->isSymptomRelatedQuery($message)) {
                $cleanQuery = trim(preg_replace('/(?i)(cho tôi|tôi muốn|có bán|có|giá|bao nhiêu|tìm|thuốc)/u', '', $message));
                
                if (mb_strlen($cleanQuery, 'UTF-8') >= 3) {
                    $directResults = $this->searchProducts($cleanQuery);
                    
                    if ($directResults->count() > 0) {
                        $products = $directResults->map(function($product) {
                            return [
                                'id' => $product->id,
                                'name' => $product->name,
                                'price' => $product->price,
                                'stock_quantity' => $product->stock_quantity,
                                'image_url' => $product->image ? asset('storage/' . $product->image) : null,
                                'ingredients' => $product->ingredients,
                                'usage' => $product->usage,
                                'expiration_date' => $product->expiration_date,
                                'category' => $product->category ? $product->category->name : null
                            ];
                        })->toArray();
                        
                        return [
                            'success' => true,
                            'response' => "Tôi đã tìm thấy " . count($products) . " sản phẩm liên quan đến '*{$cleanQuery}*' trong hệ thống nhà thuốc:",
                            'products' => $products,
                            'explanations' => [], // Bypass AI explanation
                            'show_disclaimer' => false,
                            'disclaimer' => null
                        ];
                    }
                }
            }
            // ---> END AI QUOTA SAVER <---

            // Build conversation history from context
            $messages = $this->buildConversationMessages($message, $context);
            
            // Call AI service with function calling capability
            $response = $this->aiService->chat($messages, [
                'functions' => $this->getFunctionSchema(),
                'function_call' => 'auto'
            ]);
            
            $products = [];
            $explanations = [];
            $showDisclaimer = false;
            $responseText = '';
            
            // Check if AI wants to call a function
            if (isset($response['choices'][0]['message']['function_call'])) {
                $functionCall = $response['choices'][0]['message']['function_call'];
                
                if ($functionCall['name'] === 'search_products') {
                    $arguments = json_decode($functionCall['arguments'], true);
                    $query = $arguments['query'] ?? '';
                    $symptoms = $arguments['symptoms'] ?? null;
                    
                    Log::info('Chatbot function call: search_products', [
                        'query' => $query,
                        'symptoms' => $symptoms,
                    ]);
                    
                    // Determine if this is a symptom-based query
                    $isSymptomQuery = !empty($symptoms) || $this->isSymptomRelatedQuery($message);
                    
                    // Search products
                    $searchResults = $this->searchProducts($query);
                    
                    Log::info('Product search results', [
                        'query' => $query,
                        'count' => $searchResults->count(),
                    ]);
                    
                    // Build follow-up message with search results for AI to explain
                    $searchResultsJson = json_encode($searchResults->map(function($product) {
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'price' => $product->price,
                            'stock_quantity' => $product->stock_quantity,
                            'ingredients' => $product->ingredients,
                            'usage' => $product->usage,
                            'expiration_date' => $product->expiration_date
                        ];
                    })->toArray(), JSON_UNESCAPED_UNICODE);
                    
                    $followUpMessages = [
                        ['role' => 'system', 'content' => $this->getSystemPrompt()],
                        ['role' => 'user', 'content' => $message],
                        ['role' => 'assistant', 'content' => "Tôi sẽ tìm kiếm sản phẩm phù hợp cho bạn với từ khóa: {$query}"],
                        ['role' => 'user', 'content' => "Kết quả tìm kiếm sản phẩm: {$searchResultsJson}\n\nDựa trên kết quả trên, hãy giải thích tại sao các sản phẩm này phù hợp với nhu cầu của người dùng và đưa ra lời khuyên sử dụng. Nếu không có sản phẩm nào, hãy thông báo."]
                    ];
                    
                    // Get AI's final response with explanations
                    $finalResponse = $this->aiService->chat($followUpMessages);
                    $responseText = $finalResponse['choices'][0]['message']['content'] ?? '';
                    
                    // Format products for response
                    $products = $searchResults->map(function($product) {
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'price' => $product->price,
                            'stock_quantity' => $product->stock_quantity,
                            'image_url' => $product->image ? asset('storage/' . $product->image) : null,
                            'ingredients' => $product->ingredients,
                            'usage' => $product->usage,
                            'expiration_date' => $product->expiration_date,
                            'category' => $product->category ? $product->category->name : null
                        ];
                    })->toArray();
                    
                    // Generate explanations for each product
                    foreach ($products as $product) {
                        $explanations[$product['id']] = $this->extractProductExplanation(
                            $product['name'],
                            $responseText
                        );
                    }
                    
                    // Show disclaimer for symptom-based queries
                    $showDisclaimer = $isSymptomQuery;
                }
            } else {
                // No function call - direct response from AI
                $responseText = $response['choices'][0]['message']['content'] ?? '';
                
                // Check if response mentions symptoms or medical advice
                $showDisclaimer = $this->isSymptomRelatedQuery($message) || 
                                 $this->containsMedicalAdvice($responseText);
            }
            
            // Calculate processing time
            $processingTime = (microtime(true) - $startTime) * 1000;
            
            // Log the query
            $this->auditLogService->log(
                action: 'chatbot_query',
                modelType: 'Chatbot',
                modelId: null,
                oldValues: null,
                newValues: [
                    'user_id' => $userId,
                    'query' => $message,
                    'response_type' => !empty($products) ? 'product_search' : 'general',
                    'products_returned' => count($products),
                    'processing_time_ms' => $processingTime,
                    'show_disclaimer' => $showDisclaimer
                ]
            );
            
            return [
                'success' => true,
                'response' => $responseText,
                'products' => $products,
                'explanations' => $explanations,
                'show_disclaimer' => $showDisclaimer,
                'disclaimer' => $showDisclaimer ? 
                    'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.' : null
            ];
            
        } catch (\Exception $e) {
            Log::error('Message processing failed', [
                'user_id' => $userId,
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            
            // --- AI QUOTA / EXHAUSTION FALLBACK ---
            // If the 20 API hits limit is reached, it throws an exception. We intercept it here and try local DB.
            $fallbackQuery = trim(preg_replace('/(?i)(cho tôi|tôi muốn|có bán|có|giá|bao nhiêu|tìm|thuốc)/u', '', $message));
            if (mb_strlen($fallbackQuery, 'UTF-8') >= 3) {
                try {
                    $directResults = $this->searchProducts($fallbackQuery);
                    if ($directResults->count() > 0) {
                        $products = $directResults->map(function($product) {
                            return [
                                'id' => $product->id,
                                'name' => $product->name,
                                'price' => $product->price,
                                'stock_quantity' => $product->stock_quantity,
                                'image_url' => $product->image ? asset('storage/' . $product->image) : null,
                            ];
                        })->toArray();
                        
                        return [
                            'success' => true,
                            'response' => "Hệ thống AI hiện đang bận. Tôi tìm thấy " . count($products) . " sản phẩm liên quan đến yêu cầu của bạn qua hệ thống tìm kiếm tự động:",
                            'products' => $products,
                            'explanations' => [],
                            'show_disclaimer' => false,
                            'error' => null
                        ];
                    }
                } catch (\Exception $fallbackErr) {
                    Log::error('Fallback search failed', ['error' => $fallbackErr->getMessage()]);
                }
            }
            // --- END FALLBACK ---
            
            // Generic Error Fallback
            return [
                'success' => false,
                'response' => 'Hệ thống AI hiện đang bận. Vui lòng thử lại sau hoặc sử dụng thanh tìm kiếm thủ công.',
                'products' => [],
                'explanations' => [],
                'show_disclaimer' => false,
                'error' => 'API Quota Exceeded'
            ];
        }
    }
    
    /**
     * Build conversation messages array for OpenAI.
     *
     * @param string $currentMessage Current user message
     * @param array $context Conversation context with previous messages
     * @return array Array of message objects
     */
    protected function buildConversationMessages(string $currentMessage, array $context): array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt()]
        ];
        
        // Add previous messages from context (limit to last 10 for token efficiency)
        if (isset($context['previous_messages']) && is_array($context['previous_messages'])) {
            $previousMessages = array_slice($context['previous_messages'], -10);
            
            foreach ($previousMessages as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $messages[] = [
                        'role' => $msg['role'],
                        'content' => $msg['content']
                    ];
                }
            }
        }
        
        // Add current message
        $messages[] = [
            'role' => 'user',
            'content' => $currentMessage
        ];
        
        return $messages;
    }
    
    /**
     * Check if query is symptom-related.
     *
     * @param string $query User query
     * @return bool True if query appears to be about symptoms
     */
    protected function isSymptomRelatedQuery(string $query): bool
    {
        $symptomKeywords = [
            'đau', 'sốt', 'ho', 'cảm', 'viêm', 'nhiễm', 'dị ứng', 'ngứa',
            'chóng mặt', 'buồn nôn', 'tiêu chảy', 'táo bón', 'mệt mỏi',
            'khó thở', 'đau đầu', 'đau bụng', 'đau họng', 'sổ mũi',
            'triệu chứng', 'bệnh', 'ốm', 'khó chịu', 'không khỏe'
        ];
        
        $queryLower = mb_strtolower($query, 'UTF-8');
        
        foreach ($symptomKeywords as $keyword) {
            if (mb_strpos($queryLower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if response contains medical advice.
     *
     * @param string $response AI response text
     * @return bool True if response contains medical advice
     */
    protected function containsMedicalAdvice(string $response): bool
    {
        $medicalKeywords = [
            'triệu chứng', 'bệnh', 'điều trị', 'chữa', 'uống thuốc',
            'liều lượng', 'dược sĩ', 'bác sĩ', 'khám', 'tái khám'
        ];
        
        $responseLower = mb_strtolower($response, 'UTF-8');
        
        foreach ($medicalKeywords as $keyword) {
            if (mb_strpos($responseLower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract product-specific explanation from AI response.
     *
     * @param string $productName Product name
     * @param string $responseText Full AI response
     * @return string Product-specific explanation
     */
    protected function extractProductExplanation(string $productName, string $responseText): string
    {
        // Split response into sentences
        $sentences = preg_split('/[.!?]+/u', $responseText);
        
        // Find sentences mentioning the product
        $relevantSentences = array_filter($sentences, function($sentence) use ($productName) {
            return mb_stripos($sentence, $productName, 0, 'UTF-8') !== false;
        });
        
        if (!empty($relevantSentences)) {
            return trim(implode('. ', $relevantSentences)) . '.';
        }
        
        // Fallback: return generic explanation
        return 'Sản phẩm này phù hợp với nhu cầu của bạn.';
    }

    /**
     * Search products based on query string.
     *
     * @param string $query Search query
     * @return Collection Collection of matching products
     */
    public function searchProducts(string $query): Collection
    {
        $query = trim($query);
        $products = Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->where(function($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('ingredients', 'LIKE', "%{$query}%")
                  ->orWhere('usage', 'LIKE', "%{$query}%");
            })
            ->FEFO()
            ->limit(5)
            ->get();
            
        // Fallback: Tìm mờ (Fuzzy Search) nếu không có kết quả chính xác
        if ($products->isEmpty() && mb_strlen($query, 'UTF-8') >= 4) {
            $qBuilder = Product::with('category')
                ->notExpired()
                ->where('stock_quantity', '>', 0);
                
            $qBuilder->where(function($q) use ($query) {
                $words = explode(' ', mb_strtolower($query, 'UTF-8'));
                foreach ($words as $word) {
                    if (mb_strlen($word, 'UTF-8') >= 4) {
                        // Cắt chuỗi thành các cụm 4 ký tự (ngrams) để tìm kiếm
                        for ($i = 0; $i <= mb_strlen($word, 'UTF-8') - 4; $i += 2) { 
                            $ngram = mb_substr($word, $i, 4, 'UTF-8');
                            $q->orWhere('name', 'LIKE', "%{$ngram}%");
                        }
                    } else if (mb_strlen($word, 'UTF-8') > 0) {
                        $q->orWhere('name', 'LIKE', "%{$word}%");
                    }
                }
            });
            
            $candidates = $qBuilder->limit(40)->get();
            
            if ($candidates->isNotEmpty()) {
                $queryLower = mb_strtolower($query, 'UTF-8');
                
                // Đánh giá độ tương đồng (similarity) cho từng kết quả
                $candidates = $candidates->map(function($p) use ($queryLower) {
                    $nameLower = mb_strtolower($p->name, 'UTF-8');
                    
                    // Tính phần trăm giống nhau
                    similar_text($queryLower, $nameLower, $percent);
                    
                    // Thưởng điểm nếu bắt đầu bằng 3 ký tự giống nhau
                    if (mb_strlen($queryLower, 'UTF-8') >= 3 && 
                        mb_substr($queryLower, 0, 3, 'UTF-8') === mb_substr($nameLower, 0, 3, 'UTF-8')) {
                        $percent += 20;
                    }
                    
                    $p->similarity = $percent;
                    return $p;
                })->sortByDesc('similarity')->values();
                
                // Lọc những sản phẩm có độ giống > 30% và lấy top 5
                $products = $candidates->filter(function($p) {
                    return $p->similarity > 30; 
                })->take(5);
            }
        }
        
        return $products;
    }

    /**
     * Recommend products based on symptoms.
     *
     * @param string $symptoms User-described symptoms
     * @return array Array with recommended products and disclaimer
     * @throws \Exception
     */
    public function recommendBySymptoms(string $symptoms): array
    {
        try {
            // Build messages for AI
            $messages = [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'user', 'content' => $symptoms]
            ];
            
            // Call AI service with function calling
            $response = $this->aiService->chat($messages, [
                'functions' => $this->getFunctionSchema(),
                'function_call' => 'auto'
            ]);
            
            $products = [];
            $explanations = [];
            
            // Process function calls if present
            if (isset($response['choices'][0]['message']['function_call'])) {
                $functionCall = $response['choices'][0]['message']['function_call'];
                
                if ($functionCall['name'] === 'search_products') {
                    $arguments = json_decode($functionCall['arguments'], true);
                    $query = $arguments['query'] ?? '';
                    
                    // Search products using the extracted query
                    $searchResults = $this->searchProducts($query);
                    
                    // Build follow-up message with search results
                    $searchResultsJson = json_encode($searchResults->map(function($product) {
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'price' => $product->price,
                            'stock_quantity' => $product->stock_quantity,
                            'ingredients' => $product->ingredients,
                            'usage' => $product->usage
                        ];
                    })->toArray(), JSON_UNESCAPED_UNICODE);
                    
                    $followUpMessages = [
                        ['role' => 'system', 'content' => $this->getSystemPrompt()],
                        ['role' => 'user', 'content' => $symptoms],
                        ['role' => 'assistant', 'content' => "Tôi sẽ tìm kiếm sản phẩm phù hợp cho bạn với từ khóa: {$query}"],
                        ['role' => 'user', 'content' => "Kết quả tìm kiếm sản phẩm: {$searchResultsJson}\n\nDựa trên kết quả trên, hãy giải thích tại sao các sản phẩm này phù hợp với triệu chứng của người dùng."]
                    ];
                    
                    // Get AI explanation for recommendations
                    $explanationResponse = $this->aiService->chat($followUpMessages);
                    
                    $products = $searchResults->toArray();
                    
                    // Extract explanation from AI response
                    $aiExplanation = $explanationResponse['choices'][0]['message']['content'] ?? '';
                    
                    // Generate individual explanations for each product
                    foreach ($products as $product) {
                        $explanations[$product['id']] = $this->generateProductExplanation(
                            $product,
                            $symptoms,
                            $aiExplanation
                        );
                    }
                }
            }
            
            return [
                'products' => $products,
                'explanations' => $explanations,
                'show_disclaimer' => true,
                'disclaimer' => 'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.'
            ];
            
        } catch (\Exception $e) {
            Log::error('Symptom recommendation failed', [
                'symptoms' => $symptoms,
                'error' => $e->getMessage()
            ]);
            
            return [
                'products' => [],
                'explanations' => [],
                'error' => 'Không thể xử lý yêu cầu. Vui lòng thử lại.',
                'show_disclaimer' => true,
                'disclaimer' => 'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.'
            ];
        }
    }
    
    /**
     * Generate explanation for why a product is recommended.
     *
     * @param array $product Product data
     * @param string $symptoms User symptoms
     * @param string $aiExplanation AI-generated explanation
     * @return string Product-specific explanation
     */
    protected function generateProductExplanation(array $product, string $symptoms, string $aiExplanation): string
    {
        // Extract product-specific explanation from AI response
        // If AI explanation mentions the product, use that part
        $productName = $product['name'];
        
        // Simple extraction: look for sentences mentioning the product
        $sentences = preg_split('/[.!?]+/', $aiExplanation);
        $relevantSentences = array_filter($sentences, function($sentence) use ($productName) {
            return stripos($sentence, $productName) !== false;
        });
        
        if (!empty($relevantSentences)) {
            return trim(implode('. ', $relevantSentences)) . '.';
        }
        
        // Fallback: generate basic explanation based on product usage
        return "Sản phẩm này được gợi ý vì: " . ($product['usage'] ?? 'phù hợp với triệu chứng của bạn');
    }

    /**
     * Create draft order from recognized products.
     *
     * @param array $products Array of products with quantities
     * @param int $userId User ID
     * @return array Draft order data with validation results
     * @throws \Exception
     */
    public function createDraftOrder(array $products, int $userId): array
    {
        try {
            $validProducts = [];
            $invalidProducts = [];
            $totalAmount = 0;
            
            foreach ($products as $productData) {
                if (!isset($productData['product']) || !$productData['matched']) {
                    $invalidProducts[] = [
                        'name' => $productData['parsed_name'] ?? 'Unknown',
                        'reason' => 'Không tìm thấy sản phẩm trong hệ thống',
                        'suggestions' => $productData['suggestions'] ?? []
                    ];
                    continue;
                }
                
                $product = $productData['product'];
                $quantity = $productData['quantity'] ?? 1;
                
                // Validate stock availability
                if ($product['stock_quantity'] < $quantity) {
                    $invalidProducts[] = [
                        'name' => $product['name'],
                        'reason' => "Không đủ hàng trong kho. Chỉ còn {$product['stock_quantity']} sản phẩm.",
                        'available_quantity' => $product['stock_quantity']
                    ];
                    continue;
                }
                
                // Check expiration
                if ($product['is_expired']) {
                    $invalidProducts[] = [
                        'name' => $product['name'],
                        'reason' => 'Sản phẩm đã hết hạn',
                        'expiration_date' => $product['expiration_date']
                    ];
                    continue;
                }
                
                // Add to valid products
                $itemTotal = $product['price'] * $quantity;
                $totalAmount += $itemTotal;
                
                $validProducts[] = [
                    'product_id' => $product['id'],
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'quantity' => $quantity,
                    'subtotal' => $itemTotal,
                    'image_url' => $product['image_url'],
                    'stock_quantity' => $product['stock_quantity'],
                    'dosage' => $productData['dosage'] ?? null,
                    'instructions' => $productData['instructions'] ?? null
                ];
            }
            
            // Log draft order creation
            $this->auditLogService->log(
                action: 'chatbot_order_created',
                modelType: 'Chatbot',
                modelId: null,
                oldValues: null,
                newValues: [
                    'user_id' => $userId,
                    'source' => 'AI Chatbot',
                    'order_type' => 'draft',
                    'total_amount' => $totalAmount,
                    'valid_products_count' => count($validProducts),
                    'invalid_products_count' => count($invalidProducts)
                ]
            );
            
            return [
                'success' => true,
                'draft_order' => [
                    'products' => $validProducts,
                    'total_amount' => $totalAmount,
                    'currency' => 'VND'
                ],
                'invalid_products' => $invalidProducts,
                'message' => count($validProducts) > 0 
                    ? 'Đơn hàng nháp đã được tạo thành công' 
                    : 'Không có sản phẩm hợp lệ để tạo đơn hàng'
            ];
            
        } catch (\Exception $e) {
            Log::error('Draft order creation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Không thể tạo đơn hàng nháp. Vui lòng thử lại.');
        }
    }

    /**
     * Validate draft order data.
     *
     * @param array $orderData Draft order data to validate
     * @return bool True if valid, throws exception otherwise
     * @throws \Exception
     */
    public function validateDraftOrder(array $orderData): bool
    {
        if (!isset($orderData['products']) || empty($orderData['products'])) {
            throw new \Exception('Đơn hàng phải có ít nhất một sản phẩm');
        }
        
        foreach ($orderData['products'] as $item) {
            if (!isset($item['product_id']) || !isset($item['quantity'])) {
                throw new \Exception('Thông tin sản phẩm không hợp lệ');
            }
            
            if ($item['quantity'] <= 0) {
                throw new \Exception('Số lượng sản phẩm phải lớn hơn 0');
            }
            
            // Validate stock availability
            $product = \App\Models\Product::find($item['product_id']);
            
            if (!$product) {
                throw new \Exception("Sản phẩm ID {$item['product_id']} không tồn tại");
            }
            
            if ($product->stock_quantity < $item['quantity']) {
                throw new \Exception("Sản phẩm {$product->name} không đủ hàng trong kho");
            }
            
            if ($product->isExpired()) {
                throw new \Exception("Sản phẩm {$product->name} đã hết hạn");
            }
        }
        
        return true;
    }
}
