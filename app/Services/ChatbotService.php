<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatbotService
{
    protected GeminiService $aiService;
    protected InventoryService $inventoryService;
    protected CheckoutService $checkoutService;
    protected AuditLogService $auditLogService;

    /**
     * System prompt for AI assistant in Vietnamese.
     * Defines the role, responsibilities, and behavior of the pharmacy assistant.
     */
    protected const SYSTEM_PROMPT = <<<'PROMPT'
Bạn là trợ lý AI của nhà thuốc Medicare Pharmacy.

Nhiệm vụ chính:
1. Tư vấn sức khỏe cơ bản và triệu chứng nhẹ theo cách an toàn.
2. Đề xuất sản phẩm dựa trên triệu chứng khi thực sự cần, nhưng luôn nhắc người dùng tham khảo ý kiến bác sĩ hoặc dược sĩ.
3. Khi hệ thống đã cung cấp dữ liệu sản phẩm hoặc danh mục, hãy trả lời chi tiết bằng chính dữ liệu đó.
4. Trả lời tiếng Việt, rõ ràng, chi tiết vừa đủ, lịch sự và chuyên nghiệp.

Quy tắc an toàn:
- Không đưa ra chẩn đoán y khoa nghiêm trọng hoặc khẳng định điều trị thay cho bác sĩ.
- Nếu triệu chứng có dấu hiệu nghiêm trọng, hãy khuyên người dùng đi khám.
- Không bịa thêm giá, tồn kho, công dụng, thành phần hay danh mục nếu dữ liệu không được cung cấp.

Cách trả lời:
- Luôn trả lời trực tiếp câu hỏi trước, sau đó mới gợi ý thêm nếu phù hợp.
- Khi có products, hãy giải thích lý do sản phẩm phù hợp với nhu cầu của người dùng, nêu ngắn gọn công dụng, giá, tồn kho và lưu ý sử dụng nếu có.
- Khi có categories, hãy tổng hợp danh mục phù hợp, giải thích nên xem danh mục nào trước, và có thể gợi ý một vài sản phẩm tiêu biểu nếu dữ liệu đã có.
- Nếu không tìm thấy dữ liệu phù hợp, hãy nói rõ là chưa thấy kết quả và gợi ý người dùng đổi cách diễn đạt.

Khi cần tìm sản phẩm để hỗ trợ tư vấn triệu chứng, hãy gọi function search_products với keyword ngắn gọn, chính xác.
PROMPT;


    protected const SEARCH_STOP_WORDS = [
        'cho tôi hỏi', 'cho mình hỏi', 'tôi muốn tìm', 'tôi muốn mua', 'tôi cần tìm', 'tôi cần mua',
        'cần tìm', 'cần mua', 'cho tôi', 'tôi muốn', 'tôi cần', 'có bán', 'cho hỏi',
        'tôi', 'mình', 'anh', 'chị', 'em', 'cần', 'mua', 'tìm', 'có', 'giá', 'bao nhiêu',
        'cái', 'hỏi', 'xem', 'muốn', 'về', 'loại', 'tao', 'mày'
    ];

    protected const CATEGORY_KEYWORDS = [
        'danh mục', 'nhóm hàng', 'nhóm sản phẩm', 'phân loại', 'category', 'các nhóm', 'các danh mục'
    ];

    protected const CONSULTATION_KEYWORDS = [
        'có nên', 'nên dùng', 'nên mua', 'loại nào tốt', 'loại nào phù hợp', 'uống thế nào',
        'dùng thế nào', 'sử dụng thế nào', 'bao lâu', 'tác dụng phụ', 'chống chỉ định',
        'so sánh', 'khác nhau', 'mang thai', 'cho con bú', 'trẻ em', 'người già', 'an toàn không'
    ];

    protected const CAPABILITY_KEYWORDS = [
        'bạn là ai', 'em là ai', 'giúp được gì', 'làm được gì', 'hỗ trợ gì', 'có thể làm gì'
    ];

    protected const SUPPORT_KEYWORDS = [
        'giao hàng', 'ship', 'thanh toán', 'đặt hàng', 'giỏ hàng', 'đơn hàng', 'liên hệ', 'hỗ trợ đặt'
    ];

    protected const OUT_OF_DOMAIN_KEYWORDS = [
        'thời tiết', 'bóng đá', 'chứng khoán', 'bitcoin', 'coin', 'máy tính', 'lập trình',
        'code', 'phim', 'âm nhạc', 'game', 'du lịch'
    ];

    protected const WEBSITE_INFO_KEYWORDS = [
        'website này là gì', 'trang web này là gì', 'web này là gì', 'đây là website gì', 'đây là trang gì',
        'website này bán gì', 'trang web này bán gì', 'website này có gì', 'shop này bán gì', 'cửa hàng này bán gì',
        'giới thiệu website', 'giới thiệu trang web', 'website này dùng để làm gì'
    ];

    /**
     * Function schema for Gemini function calling.
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
        GeminiService $aiService,
        InventoryService $inventoryService,
        CheckoutService $checkoutService,
        AuditLogService $auditLogService
    ) {
        $this->aiService = $aiService;
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
                'reason' => 'Dạ, anh/chị vui lòng nhập câu hỏi hoặc mô tả triệu chứng cụ thể hơn để em hỗ trợ nhé.'
            ];
        }
        
        // Contains only special characters
        if (preg_match('/^[^a-zA-ZÀ-ỹ0-9\s]+$/', $trimmed)) {
            return [
                'valid' => false,
                'reason' => 'Dạ, em chưa hiểu ý anh/chị lắm. Anh/chị vui lòng nhập thông tin bằng chữ để em tư vấn kỹ hơn nhé.'
            ];
        }
        
        // Bad words detection from config
        $badWords = config('blacklists.chatbot', []);
        if (!empty($badWords)) {
            // Escape words for regex and join them
            $escapedWords = array_map(function($word) {
                return preg_quote($word, '/');
            }, $badWords);
            
            $badWordsPattern = implode('|', $escapedWords);
            $badWordsRegex = '/\b(' . $badWordsPattern . ')\b/ui';
            
            if (preg_match($badWordsRegex, $trimmed)) {
                return [
                    'valid' => false,
                    'reason' => 'Dạ, anh/chị vui lòng sử dụng ngôn từ lịch sự ạ. Em xin phép từ chối phản hồi các nội dung không phù hợp nhé.'
                ];
            }
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
        $validation = $this->validateMessage($message);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'response' => $validation['reason'],
                'products' => [],
                'categories' => [],
                'explanations' => [],
                'show_disclaimer' => false,
                'validation_error' => true
            ];
        }

        $startTime = microtime(true);
        $messageRouting = $this->separateWebsiteIntent($message);
        $workingMessage = $messageRouting['message'];
        $prependWebsiteIntro = $messageRouting['prepend_intro'];

        try {
            $localResponse = $this->tryLocalResponse($workingMessage);
            if ($localResponse !== null) {
                $responseType = $localResponse['response_type'] ?? 'general';
                unset($localResponse['response_type']);

                if ($prependWebsiteIntro) {
                    $localResponse['response'] = $this->prependWebsiteIntroduction($localResponse['response']);
                }

                $this->logChatbotInteraction(
                    $userId,
                    $message,
                    (microtime(true) - $startTime) * 1000,
                    $responseType,
                    $localResponse['products'] ?? [],
                    $localResponse['categories'] ?? [],
                    $localResponse['show_disclaimer'] ?? false
                );

                return $localResponse;
            }

            $messages = $this->buildConversationMessages($workingMessage, $context);
            $response = $this->aiService->chat($messages, [
                'functions' => $this->getFunctionSchema(),
                'function_call' => 'auto'
            ]);

            $products = [];
            $categories = [];
            $explanations = [];
            $showDisclaimer = false;
            $responseText = '';
            $responseType = 'general';

            if (isset($response['choices'][0]['message']['function_call'])) {
                $functionCall = $response['choices'][0]['message']['function_call'];

                if (($functionCall['name'] ?? null) === 'search_products') {
                    $arguments = json_decode($functionCall['arguments'] ?? '{}', true) ?: [];
                    $query = trim((string) ($arguments['query'] ?? ''));
                    $symptoms = is_array($arguments['symptoms'] ?? null) ? $arguments['symptoms'] : [];
                    $requiresDisclaimer = !empty($symptoms)
                        || $this->isSymptomRelatedQuery($workingMessage)
                        || $this->requiresAiConsultation($this->normalizeMessage($workingMessage));

                    Log::info('Chatbot function call: search_products', [
                        'query' => $query,
                        'symptoms' => $symptoms,
                    ]);

                    $catalogResponse = $this->buildCatalogLookupResponse(
                        $query !== '' ? $query : $this->resolveCatalogQuery($workingMessage),
                        $workingMessage,
                        $requiresDisclaimer
                    );

                    $products = $catalogResponse['products'];
                    $categories = $catalogResponse['categories'];
                    $explanations = $catalogResponse['explanations'];
                    $showDisclaimer = $catalogResponse['show_disclaimer'];
                    $responseText = $catalogResponse['response'];
                    $responseType = $catalogResponse['response_type'] ?? 'general';
                }
            } else {
                $responseText = $response['choices'][0]['message']['content'] ?? '';
                $showDisclaimer = $this->isSymptomRelatedQuery($workingMessage)
                    || $this->containsMedicalAdvice($responseText);
            }

            if ($prependWebsiteIntro) {
                $responseText = $this->prependWebsiteIntroduction($responseText);
            }

            $result = [
                'success' => true,
                'response' => $responseText,
                'products' => $products,
                'categories' => $categories,
                'explanations' => $explanations,
                'show_disclaimer' => $showDisclaimer,
                'disclaimer' => $showDisclaimer
                    ? 'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.'
                    : null
            ];

            $this->logChatbotInteraction(
                $userId,
                $message,
                (microtime(true) - $startTime) * 1000,
                $responseType,
                $products,
                $categories,
                $showDisclaimer
            );

            return $result;
        } catch (\Exception $e) {
            Log::error('Message processing failed', [
                'user_id' => $userId,
                'message' => $message,
                'error' => $e->getMessage()
            ]);

            $fallbackQuery = $this->resolveCatalogQuery($workingMessage);
            $normalizedMessage = $this->normalizeMessage($workingMessage);
            $fallbackDisclaimer = $this->requiresAiConsultation($normalizedMessage);

            if ($this->isCategoryIntent($normalizedMessage)) {
                try {
                    $categoryFallback = $this->formatCategoriesPayload($this->searchCategories('', 6));
                    if (!empty($categoryFallback)) {
                        return [
                            'success' => true,
                            'response' => 'Dạ, em chưa xử lý xong yêu cầu vừa rồi nhưng anh/chị có thể xem nhanh các danh mục nổi bật bên dưới để tiếp tục lọc sản phẩm.',
                            'products' => [],
                            'categories' => $categoryFallback,
                            'explanations' => [],
                            'show_disclaimer' => false,
                            'disclaimer' => null,
                        ];
                    }
                } catch (\Exception $categoryFallbackError) {
                    Log::error('Category fallback failed', ['error' => $categoryFallbackError->getMessage()]);
                }
            }

            if ($fallbackQuery !== '') {
                try {
                    $fallbackResponse = $this->buildCatalogLookupResponse($fallbackQuery, $workingMessage, $fallbackDisclaimer);
                    if (!empty($fallbackResponse['products']) || !empty($fallbackResponse['categories'])) {
                        if ($prependWebsiteIntro) {
                            $fallbackResponse['response'] = $this->prependWebsiteIntroduction($fallbackResponse['response']);
                        }

                        unset($fallbackResponse['response_type']);

                        return $fallbackResponse;
                    }
                } catch (\Exception $fallbackError) {
                    Log::error('Fallback search failed', ['error' => $fallbackError->getMessage()]);
                }
            }

            $suggestedProducts = [];
            try {
                $suggestions = Product::with('category')
                    ->notExpired()
                    ->where('stock_quantity', '>', 0)
                    ->inRandomOrder()
                    ->limit(5)
                    ->get();

                $suggestedProducts = $suggestions->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => (float) $product->price,
                        'stock_quantity' => (int) $product->stock_quantity,
                        'image_url' => $product->image_url,
                        'ingredients' => $product->ingredients,
                        'usage' => $product->usage,
                        'expiration_date' => optional($product->expiration_date)->toDateString(),
                        'category' => $product->category ? $product->category->name : null,
                        'url' => route('products.show', $product->id)
                    ];
                })->toArray();
            } catch (\Exception $suggestionError) {
                Log::error('Fallback suggestion failed', ['error' => $suggestionError->getMessage()]);
            }

            return [
                'success' => true,
                'response' => 'Dạ, hiện tại cửa hàng đang tiếp nhận nhiều yêu cầu cùng lúc. Trong lúc chờ đợi, anh/chị có thể tham khảo một số gợi ý dưới đây, hoặc nhắn tên sản phẩm/danh mục cụ thể để em tìm nhanh hơn nhé.',
                'products' => $suggestedProducts,
                'categories' => [],
                'explanations' => $this->buildProductExplanations($suggestedProducts, $message),
                'show_disclaimer' => $fallbackDisclaimer,
                'disclaimer' => $fallbackDisclaimer
                    ? 'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.'
                    : null,
                'error' => null
            ];
        }
    }
    
    protected function tryLocalResponse(string $message): ?array
    {
        $normalizedMessage = $this->normalizeMessage($message);

        if ($this->isWebsiteInfoIntent($normalizedMessage)) {
            return $this->buildWebsiteIntroductionResponse();
        }

        if ($this->isCapabilityIntent($normalizedMessage)) {
            return [
                'success' => true,
                'response' => 'Dạ, em có thể hỗ trợ anh/chị tìm sản phẩm, gợi ý danh mục phù hợp, tư vấn triệu chứng nhẹ, thêm sản phẩm vào giỏ và đặt hàng ngay trên website. Anh/chị chỉ cần gửi tên thuốc, hoạt chất, công dụng hoặc danh mục muốn xem.',
                'products' => [],
                'categories' => [],
                'explanations' => [],
                'show_disclaimer' => false,
                'disclaimer' => null,
                'response_type' => 'general'
            ];
        }

        if ($this->isSupportIntent($normalizedMessage)) {
            return [
                'success' => true,
                'response' => 'Dạ, em có thể hỗ trợ anh/chị tìm sản phẩm, xem danh mục, thêm vào giỏ hàng và tạo đơn ngay trên website. Nếu anh/chị muốn mua nhanh, hãy gửi tên sản phẩm hoặc danh mục cần xem để em lọc đúng dữ liệu trong kho.',
                'products' => [],
                'categories' => [],
                'explanations' => [],
                'show_disclaimer' => false,
                'disclaimer' => null,
                'response_type' => 'general'
            ];
        }

        if ($this->isOutOfDomainQuery($normalizedMessage)) {
            return [
                'success' => true,
                'response' => 'Dạ, em đang tập trung hỗ trợ các nội dung liên quan đến nhà thuốc như sản phẩm, danh mục, sức khỏe và đặt hàng. Nếu anh/chị cần tìm thuốc hoặc muốn em gợi ý danh mục phù hợp, cứ nhắn trực tiếp để em hỗ trợ ngay.',
                'products' => [],
                'categories' => [],
                'explanations' => [],
                'show_disclaimer' => false,
                'disclaimer' => null,
                'response_type' => 'general'
            ];
        }

        if ($this->isCategoryIntent($normalizedMessage)) {
            return $this->buildCategoryLookupResponse($message);
        }

        if (!$this->isSymptomRelatedQuery($message) && !$this->requiresAiConsultation($normalizedMessage)) {
            $query = $this->resolveCatalogQuery($message);

            if ($query !== '') {
                return $this->buildCatalogLookupResponse($query, $message, false);
            }
        }

        return null;
    }

    protected function buildCatalogLookupResponse(string $query, string $originalMessage, bool $showDisclaimer): array
    {
        $query = trim($query);
        $searchResults = $query !== '' ? $this->searchProducts($query) : collect();
        $products = $this->formatProductsPayload($searchResults);
        $explanations = $this->buildProductExplanations($products, $originalMessage);
        $categories = [];

        if (!empty($products)) {
            $response = $this->buildDetailedProductResponseText($query, $products, $explanations, $showDisclaimer);
        } else {
            $categories = $this->formatCategoriesPayload($this->searchCategories($query, 3));
            $response = $this->buildNoProductMatchResponse($query, $categories, $originalMessage);
        }

        return [
            'success' => true,
            'response' => $response,
            'products' => $products,
            'categories' => $categories,
            'explanations' => $explanations,
            'show_disclaimer' => $showDisclaimer,
            'disclaimer' => $showDisclaimer
                ? 'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.'
                : null,
            'response_type' => !empty($products) ? 'product_search' : (!empty($categories) ? 'category_search' : 'general')
        ];
    }

    protected function buildCategoryLookupResponse(string $message): array
    {
        $query = $this->extractSearchQuery($message, self::CATEGORY_KEYWORDS);
        $categories = $this->searchCategories($query, $query === '' ? 6 : 5);

        if ($categories->isEmpty()) {
            $categories = $this->searchCategories('', 6);
        }

        if ($categories->isEmpty() && $query !== '') {
            return $this->buildCatalogLookupResponse($query, $message, false);
        }

        $featuredProducts = collect();
        if ($categories->count() === 1) {
            $featuredProducts = $this->getFeaturedProductsForCategory((int) $categories->first()->id);
        }

        return [
            'success' => true,
            'response' => $this->buildCategoryResponseText(
                $this->formatCategoriesPayload($categories),
                $this->formatProductsPayload($featuredProducts),
                $query
            ),
            'products' => $this->formatProductsPayload($featuredProducts),
            'categories' => $this->formatCategoriesPayload($categories),
            'explanations' => [],
            'show_disclaimer' => false,
            'disclaimer' => null,
            'response_type' => 'category_search'
        ];
    }

    protected function buildWebsiteIntroductionResponse(): array
    {
        $categories = $this->formatCategoriesPayload($this->searchCategories('', 4));

        $response = 'Dạ, đây là website của Medicare Pharmacy. Hệ thống này hỗ trợ tra cứu sản phẩm nhà thuốc, xem danh mục, tham khảo công dụng cơ bản, kiểm tra tình trạng còn hàng và hỗ trợ đặt hàng trực tuyến.';

        if (!empty($categories)) {
            $response .= "\n\nAnh/chị có thể bắt đầu từ một số danh mục nổi bật bên dưới để lọc nhanh sản phẩm phù hợp.";
        } else {
            $response .= "\n\nAnh/chị có thể nhắn tên thuốc, hoạt chất, công dụng hoặc danh mục cần tìm để em hỗ trợ ngay.";
        }

        return [
            'success' => true,
            'response' => $response,
            'products' => [],
            'categories' => $categories,
            'explanations' => [],
            'show_disclaimer' => false,
            'disclaimer' => null,
            'response_type' => 'general'
        ];
    }

    protected function separateWebsiteIntent(string $message): array
    {
        $originalMessage = trim($message);
        $clauses = preg_split('/[?.!;\n]+/u', $originalMessage) ?: [];
        $websiteDetected = false;
        $remainingClauses = [];

        foreach ($clauses as $clause) {
            $trimmedClause = trim($clause);
            if ($trimmedClause === '') {
                continue;
            }

            $strippedClause = $this->stripLeadingWebsiteIntroduction($trimmedClause);
            if ($strippedClause !== $trimmedClause) {
                $websiteDetected = true;

                if ($strippedClause !== '') {
                    $remainingClauses[] = $strippedClause;
                }

                continue;
            }

            if ($this->isWebsiteInfoIntent($this->normalizeMessage($trimmedClause))) {
                $websiteDetected = true;
                continue;
            }

            $remainingClauses[] = $trimmedClause;
        }

        if (!$websiteDetected) {
            return [
                'message' => $originalMessage,
                'prepend_intro' => false,
            ];
        }

        $remainingMessage = trim(implode('. ', $remainingClauses));

        return [
            'message' => $remainingMessage !== '' ? $remainingMessage : $originalMessage,
            'prepend_intro' => $remainingMessage !== '',
        ];
    }

    protected function stripLeadingWebsiteIntroduction(string $message): string
    {
        $patterns = [
            '/^\s*website\s+này\s+là\s+gì[\s,:-]*/iu',
            '/^\s*trang\s+web\s+này\s+là\s+gì[\s,:-]*/iu',
            '/^\s*web\s+này\s+là\s+gì[\s,:-]*/iu',
            '/^\s*đây\s+là\s+website\s+gì[\s,:-]*/iu',
            '/^\s*đây\s+là\s+trang\s+gì[\s,:-]*/iu',
            '/^\s*website\s+này\s+(?:bán|có)\s+gì[\s,:-]*/iu',
            '/^\s*trang\s+web\s+này\s+(?:bán|có)\s+gì[\s,:-]*/iu',
            '/^\s*giới\s+thiệu\s+website[\s,:-]*/iu',
            '/^\s*giới\s+thiệu\s+trang\s+web[\s,:-]*/iu',
            '/^\s*website\s+này\s+dùng\s+để\s+làm\s+gì[\s,:-]*/iu',
        ];

        foreach ($patterns as $pattern) {
            $updatedMessage = preg_replace($pattern, '', $message, 1, $count);

            if ($count > 0) {
                return trim((string) $updatedMessage);
            }
        }

        return trim($message);
    }

    protected function prependWebsiteIntroduction(string $response): string
    {
        $response = trim($response);
        $intro = 'Dạ, đây là website của Medicare Pharmacy.';

        if ($response === '') {
            return $intro;
        }

        return $intro . "\n\n" . $response;
    }

    protected function logChatbotInteraction(
        int $userId,
        string $message,
        float $processingTimeMs,
        string $responseType,
        array $products,
        array $categories,
        bool $showDisclaimer
    ): void {
        try {
            $this->auditLogService->log(
                action: 'chatbot_query',
                modelType: 'Chatbot',
                modelId: null,
                oldValues: null,
                newValues: [
                    'user_id' => $userId,
                    'query' => $message,
                    'response_type' => $responseType,
                    'products_returned' => count($products),
                    'categories_returned' => count($categories),
                    'processing_time_ms' => $processingTimeMs,
                    'show_disclaimer' => $showDisclaimer
                ]
            );
        } catch (\Throwable $exception) {
            Log::warning('Chatbot audit logging failed', [
                'user_id' => $userId,
                'response_type' => $responseType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    protected function searchCategories(string $query = '', int $limit = 5): Collection
    {
        return Category::query()
            ->select('id', 'name', 'description', 'icon')
            ->whereHas('products', function ($builder) {
                $builder->notExpired()->where('stock_quantity', '>', 0);
            })
            ->withCount([
                'products' => function ($builder) {
                    $builder->notExpired()->where('stock_quantity', '>', 0);
                }
            ])
            ->when($query !== '', function ($builder) use ($query) {
                $builder->where(function ($categoryQuery) use ($query) {
                    $categoryQuery->where('name', 'like', '%' . $query . '%')
                        ->orWhere('description', 'like', '%' . $query . '%');
                });
            })
            ->orderByDesc('products_count')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    protected function getFeaturedProductsForCategory(int $categoryId): Collection
    {
        return Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->where('category_id', $categoryId)
            ->FEFO()
            ->limit(3)
            ->get();
    }

    protected function formatProductsPayload(Collection $products): array
    {
        return $products->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'price' => (float) $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'image_url' => $product->image_url,
                'ingredients' => $product->ingredients,
                'usage' => $product->usage,
                'expiration_date' => optional($product->expiration_date)->toDateString(),
                'category' => $product->category ? $product->category->name : null,
                'url' => route('products.show', $product->id)
            ];
        })->values()->all();
    }

    protected function formatCategoriesPayload(Collection $categories): array
    {
        return $categories->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'icon' => $category->icon,
                'product_count' => (int) ($category->products_count ?? 0),
                'url' => route('products.index', ['category' => $category->id])
            ];
        })->values()->all();
    }

    protected function buildDetailedProductResponseText(
        string $query,
        array $products,
        array $explanations,
        bool $showDisclaimer
    ): string {
        $intro = $showDisclaimer
            ? 'Dạ, em đã tổng hợp một số sản phẩm có thể phù hợp để anh/chị tham khảo thêm:'
            : 'Dạ, em tìm thấy một số sản phẩm phù hợp với nhu cầu của anh/chị:';

        if ($query !== '') {
            $intro = $showDisclaimer
                ? "Dạ, với nhu cầu liên quan đến `{$query}`, em đã tổng hợp một số sản phẩm anh/chị có thể tham khảo:"
                : "Dạ, với từ khóa `{$query}`, em tìm thấy các sản phẩm sau:";
        }

        $lines = [$intro, ''];

        foreach ($products as $index => $product) {
            $lines[] = ($index + 1) . '. **' . $product['name'] . '**';
            $lines[] = '- Danh mục: ' . ($product['category'] ?? 'Chưa phân loại');
            $lines[] = '- Giá tham khảo: ' . $this->formatCurrencyValue($product['price']);
            $lines[] = '- Tồn kho hiện tại: ' . $product['stock_quantity'] . ' sản phẩm';

            if (!empty($product['usage'])) {
                $lines[] = '- Công dụng: ' . $this->summarizeText($product['usage'], 180);
            }

            if (!empty($explanations[$product['id']])) {
                $lines[] = '- Phù hợp vì: ' . $explanations[$product['id']];
            }

            $lines[] = '- Xem chi tiết: [Mở sản phẩm](' . $product['url'] . ')';
            $lines[] = '';
        }

        if ($showDisclaimer) {
            $lines[] = 'Anh/chị vui lòng đọc kỹ hướng dẫn sử dụng và tham khảo thêm ý kiến dược sĩ hoặc bác sĩ nếu triệu chứng kéo dài hoặc nặng lên.';
        }

        return trim(implode("\n", $lines));
    }

    protected function buildNoProductMatchResponse(string $query, array $categories, string $originalMessage): string
    {
        $lines = [];

        if ($query !== '') {
            $lines[] = "Dạ, hiện tại em chưa thấy sản phẩm khớp hoàn toàn với từ khóa `{$query}`.";
        } else {
            $lines[] = 'Dạ, hiện tại em chưa tìm thấy sản phẩm phù hợp với mô tả vừa rồi.';
        }

        if (!empty($categories)) {
            $lines[] = '';
            $lines[] = 'Anh/chị có thể xem các danh mục gần nhất để lọc nhanh hơn:';

            foreach ($categories as $category) {
                $line = '- **' . $category['name'] . '**';
                if (!empty($category['product_count'])) {
                    $line .= ' (' . $category['product_count'] . ' sản phẩm)';
                }
                if (!empty($category['description'])) {
                    $line .= ': ' . $this->summarizeText($category['description'], 120);
                }
                $line .= ' - [Mở danh mục](' . $category['url'] . ')';
                $lines[] = $line;
            }
        }

        $lines[] = '';
        $lines[] = 'Anh/chị có thể thử nhắn lại theo tên thuốc, hoạt chất, công dụng hoặc danh mục cụ thể hơn để em tìm chính xác hơn.';

        if ($this->isSymptomRelatedQuery($originalMessage)) {
            $lines[] = 'Nếu đây là câu hỏi về triệu chứng, anh/chị có thể mô tả rõ hơn tình trạng hiện tại để em hỗ trợ tư vấn an toàn hơn.';
        }

        return trim(implode("\n", $lines));
    }

    protected function buildCategoryResponseText(array $categories, array $products, string $query): string
    {
        $lines = [
            $query !== ''
                ? "Dạ, với nhu cầu liên quan đến `{$query}`, anh/chị có thể xem các danh mục sau:"
                : 'Dạ, dưới đây là một số danh mục nổi bật anh/chị có thể tham khảo:',
            ''
        ];

        foreach ($categories as $category) {
            $line = '- **' . $category['name'] . '**';
            if (!empty($category['product_count'])) {
                $line .= ' (' . $category['product_count'] . ' sản phẩm)';
            }
            if (!empty($category['description'])) {
                $line .= ': ' . $this->summarizeText($category['description'], 120);
            }
            $line .= ' - [Xem danh mục](' . $category['url'] . ')';
            $lines[] = $line;
        }

        if (!empty($products)) {
            $lines[] = '';
            $lines[] = 'Một vài sản phẩm tiêu biểu trong danh mục phù hợp:';

            foreach ($products as $product) {
                $lines[] = '- **' . $product['name'] . '** - ' . $this->formatCurrencyValue($product['price']) . ' - [Xem chi tiết](' . $product['url'] . ')';
            }
        }

        return trim(implode("\n", $lines));
    }

    protected function buildProductExplanations(array $products, string $originalMessage): array
    {
        $explanations = [];

        foreach ($products as $product) {
            $explanations[$product['id']] = $this->buildProductExplanationFromData($product, $originalMessage);
        }

        return $explanations;
    }

    protected function buildProductExplanationFromData(array $product, string $originalMessage): string
    {
        if (!empty($product['usage'])) {
            return 'Công dụng đang lưu trên hệ thống là ' . $this->summarizeText($product['usage'], 160) . '.';
        }

        if (!empty($product['ingredients'])) {
            return 'Sản phẩm có thành phần nổi bật: ' . $this->summarizeText($product['ingredients'], 120) . '.';
        }

        if (!empty($product['category'])) {
            return 'Sản phẩm thuộc danh mục ' . $product['category'] . ', phù hợp để anh/chị tham khảo thêm các lựa chọn cùng nhóm.';
        }

        return $this->isSymptomRelatedQuery($originalMessage)
            ? 'Sản phẩm đang có sẵn trong kho và có thể là một lựa chọn tham khảo thêm cho nhu cầu hiện tại.'
            : 'Sản phẩm đang có sẵn trong kho để anh/chị tham khảo thêm.';
    }

    protected function normalizeMessage(string $message): string
    {
        $normalized = mb_strtolower(trim($message), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);

        return trim($normalized);
    }

    protected function normalizeIntentText(string $text): string
    {
        $normalized = Str::lower(Str::ascii(trim($text)));
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }

    protected function containsAnyKeyword(string $haystack, array $keywords): bool
    {
        $normalizedHaystack = $this->normalizeIntentText($haystack);

        foreach ($keywords as $keyword) {
            if (mb_strpos($normalizedHaystack, $this->normalizeIntentText($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function extractSearchQuery(string $message, array $extraStopWords = []): string
    {
        $query = $this->normalizeMessage($message);
        $stopWords = array_unique(array_merge(self::SEARCH_STOP_WORDS, $extraStopWords));

        usort($stopWords, static fn ($left, $right) => mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8'));

        foreach ($stopWords as $stopWord) {
            $query = preg_replace('/(^|\\s)' . preg_quote($stopWord, '/') . '(?=\\s|$)/u', ' ', $query);
        }

        $query = preg_replace('/\\s+/u', ' ', $query);

        return trim($query);
    }

    protected function resolveCatalogQuery(string $message): string
    {
        $explicitProductQuery = $this->extractExplicitProductQuery($message);
        if ($explicitProductQuery !== '') {
            return $explicitProductQuery;
        }

        return $this->extractSearchQuery($message);
    }

    protected function extractExplicitProductQuery(string $message): string
    {
        $clauses = preg_split('/[?.!;\n]+/u', $message) ?: [];

        foreach ($clauses as $clause) {
            $trimmedClause = trim($clause);
            if ($trimmedClause === '') {
                continue;
            }

            if (preg_match('/\b(?:thuốc|thuoc|sản phẩm|san pham)\s+(.+)$/ui', $trimmedClause, $matches) !== 1) {
                continue;
            }

            $candidate = trim($matches[1]);
            $candidate = preg_replace('/\b(?:có|co|ko|không|khong|còn|con|hay|và|va|loại|nao|nào|tác dụng|công dụng)\b.*$/ui', '', $candidate);
            $candidate = preg_replace('/\s+/u', ' ', (string) $candidate);
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    protected function isCategoryIntent(string $normalizedMessage): bool
    {
        return $this->containsAnyKeyword($normalizedMessage, self::CATEGORY_KEYWORDS);
    }

    protected function requiresAiConsultation(string $normalizedMessage): bool
    {
        return $this->containsAnyKeyword($normalizedMessage, self::CONSULTATION_KEYWORDS);
    }

    protected function isCapabilityIntent(string $normalizedMessage): bool
    {
        return $this->containsAnyKeyword($normalizedMessage, self::CAPABILITY_KEYWORDS);
    }

    protected function isWebsiteInfoIntent(string $normalizedMessage): bool
    {
        $normalized = $this->normalizeIntentText($normalizedMessage);
        $siteReferences = ['website', 'trang web', 'web', 'shop', 'cua hang'];

        if (!$this->containsAnyKeyword($normalized, $siteReferences)) {
            return false;
        }

        $introTokens = [
            'website', 'trang', 'web', 'shop', 'cua', 'hang', 'nay', 'la', 'gi', 'ban', 'co',
            'gioi', 'thieu', 'dung', 'de', 'lam', 'day'
        ];

        $remainingTokens = array_values(array_filter(
            explode(' ', $normalized),
            static fn (string $token): bool => $token !== '' && !in_array($token, $introTokens, true) && strlen($token) > 1
        ));

        return empty($remainingTokens);
    }

    protected function isSupportIntent(string $normalizedMessage): bool
    {
        return $this->containsAnyKeyword($normalizedMessage, self::SUPPORT_KEYWORDS);
    }

    protected function isOutOfDomainQuery(string $normalizedMessage): bool
    {
        return $this->containsAnyKeyword($normalizedMessage, self::OUT_OF_DOMAIN_KEYWORDS);
    }

    protected function summarizeText(?string $text, int $limit = 150): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '...';
    }

    protected function formatCurrencyValue($amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' ₫';
    }

    /**
     * Build conversation messages array for Gemini.
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

        $messages[] = [
            'role' => 'user',
            'content' => $currentMessage
        ];

        return $messages;
    }

    /**
     * Check if query is symptom-related.
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
     */
    protected function extractProductExplanation(string $productName, string $responseText): string
    {
        $sentences = preg_split('/[.!?]+/u', $responseText);
        $relevantSentences = array_filter($sentences, function ($sentence) use ($productName) {
            return mb_stripos($sentence, $productName, 0, 'UTF-8') !== false;
        });

        if (!empty($relevantSentences)) {
            return trim(implode('. ', $relevantSentences)) . '.';
        }

        return 'Sản phẩm này phù hợp với nhu cầu của bạn.';
    }

    /**
     * Search products based on query string.
     */
    public function searchProducts(string $query): Collection
    {
        $query = trim($query);
        $queryLower = mb_strtolower($query, 'UTF-8');

        $products = Product::with('category')
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', $query)
                    ->orWhere('name', 'LIKE', "{$query} %")
                    ->orWhere('name', 'LIKE', "% {$query}")
                    ->orWhere('name', 'LIKE', "% {$query} %");
            })
            ->FEFO()
            ->limit(5)
            ->get();

        if ($products->count() < 5) {
            $partialMatches = Product::with('category')
                ->notExpired()
                ->where('stock_quantity', '>', 0)
                ->where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                        ->orWhere('ingredients', 'LIKE', "%{$query}%")
                        ->orWhere('usage', 'LIKE', "%{$query}%");
                })
                ->when($products->isNotEmpty(), function ($q) use ($products) {
                    $q->whereNotIn('id', $products->pluck('id'));
                })
                ->FEFO()
                ->limit(5 - $products->count())
                ->get();

            if ($partialMatches->isNotEmpty()) {
                $products = $products->concat($partialMatches)->values();
            }
        }

        if ($products->isEmpty() && mb_strlen($query, 'UTF-8') >= 3) {
            $words = array_filter(explode(' ', $queryLower), function ($word) {
                return mb_strlen($word, 'UTF-8') >= 2;
            });

            if (!empty($words)) {
                $queryBuilder = Product::with('category')
                    ->notExpired()
                    ->where('stock_quantity', '>', 0);

                $queryBuilder->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->where(function ($subQ) use ($word) {
                            $subQ->where('name', 'LIKE', "{$word}%")
                                ->orWhere('name', 'LIKE', "% {$word}%");
                        });
                    }
                });

                $candidates = $queryBuilder->limit(20)->get();

                if ($candidates->isNotEmpty()) {
                    $candidates = $candidates->map(function ($product) use ($queryLower, $words) {
                        $nameLower = mb_strtolower($product->name, 'UTF-8');
                        $score = 0;

                        if ($nameLower === $queryLower) {
                            $score += 100;
                        }

                        if (mb_strpos($nameLower, $queryLower) === 0) {
                            $score += 50;
                        }

                        if (mb_strpos($nameLower, $queryLower) !== false) {
                            $score += 30;
                        }

                        foreach ($words as $word) {
                            if (preg_match('/\b' . preg_quote($word, '/') . '\b/ui', $nameLower)) {
                                $score += 20;
                            } elseif (mb_strpos($nameLower, $word) !== false) {
                                $score += 5;
                            }
                        }

                        $lengthDiff = abs(mb_strlen($nameLower, 'UTF-8') - mb_strlen($queryLower, 'UTF-8'));
                        if ($lengthDiff > 20) {
                            $score -= 10;
                        }

                        $product->similarity = $score;
                        return $product;
                    })->sortByDesc('similarity')->values();

                    $products = $candidates->filter(function ($product) {
                        return $product->similarity >= 15;
                    })->take(5);
                }
            }
        }

        return $products;
    }

    /**
     * Recommend products based on symptoms.
     */
    public function recommendBySymptoms(string $symptoms): array
    {
        try {
            $messages = [
                ['role' => 'system', 'content' => $this->getSystemPrompt()],
                ['role' => 'user', 'content' => $symptoms]
            ];

            $response = $this->aiService->chat($messages, [
                'functions' => $this->getFunctionSchema(),
                'function_call' => 'auto'
            ]);

            if (isset($response['choices'][0]['message']['function_call'])) {
                $functionCall = $response['choices'][0]['message']['function_call'];

                if (($functionCall['name'] ?? null) === 'search_products') {
                    $arguments = json_decode($functionCall['arguments'] ?? '{}', true) ?: [];
                    $query = trim((string) ($arguments['query'] ?? ''));
                    $catalogResponse = $this->buildCatalogLookupResponse(
                        $query !== '' ? $query : $symptoms,
                        $symptoms,
                        true
                    );

                    return [
                        'products' => $catalogResponse['products'],
                        'categories' => $catalogResponse['categories'],
                        'explanations' => $catalogResponse['explanations'],
                        'response' => $catalogResponse['response'],
                        'show_disclaimer' => true,
                        'disclaimer' => 'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.'
                    ];
                }
            }

            return [
                'products' => [],
                'categories' => [],
                'explanations' => [],
                'response' => $response['choices'][0]['message']['content'] ?? '',
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
                'categories' => [],
                'explanations' => [],
                'error' => 'Không thể xử lý yêu cầu. Vui lòng thử lại.',
                'show_disclaimer' => true,
                'disclaimer' => 'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.'
            ];
        }
    }

    /**
     * Generate explanation for why a product is recommended.
     */
    protected function generateProductExplanation(array $product, string $symptoms, string $aiExplanation): string
    {
        $productName = $product['name'];
        $sentences = preg_split('/[.!?]+/', $aiExplanation);
        $relevantSentences = array_filter($sentences, function ($sentence) use ($productName) {
            return stripos($sentence, $productName) !== false;
        });

        if (!empty($relevantSentences)) {
            return trim(implode('. ', $relevantSentences)) . '.';
        }

        return 'Sản phẩm này được gợi ý vì: ' . ($product['usage'] ?? 'phù hợp với triệu chứng của bạn');
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

                if ($product['stock_quantity'] < $quantity) {
                    $invalidProducts[] = [
                        'name' => $product['name'],
                        'reason' => "Không đủ hàng trong kho. Chỉ còn {$product['stock_quantity']} sản phẩm.",
                        'available_quantity' => $product['stock_quantity']
                    ];
                    continue;
                }

                if ($product['is_expired']) {
                    $invalidProducts[] = [
                        'name' => $product['name'],
                        'reason' => 'Sản phẩm đã hết hạn',
                        'expiration_date' => $product['expiration_date']
                    ];
                    continue;
                }

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

            $product = Product::find($item['product_id']);

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
