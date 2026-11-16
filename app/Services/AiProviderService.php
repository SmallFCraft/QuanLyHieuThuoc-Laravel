<?php

namespace App\Services;

use App\Exceptions\AiProviderVisionNotSupportedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AiProviderService
{
    /**
     * Danh sách nhà cung cấp theo thứ tự. Index 0 là nhà cung cấp chính, index 1 là
     * nhà cung cấp dự phòng tuỳ chọn dùng khi cái chính thất bại.
     *
     * @var array<int, array{mode: string, base_url: string, api_key: string, model: string}>
     */
    protected array $providers = [];

    protected ?int $lastProviderIndex = null;
    protected int $timeout;
    protected int $connectTimeout;

    public function __construct()
    {
        $this->timeout = max(1, (int) config('chatbot.ai.timeout', 20));
        $this->connectTimeout = max(1, (int) config('chatbot.ai.connect_timeout', 5));

        $baseUrl = rtrim((string) config('chatbot.ai.base_url', ''), '/');
        $apiKey = (string) config('chatbot.ai.api_key', '');
        $model = (string) config('chatbot.ai.model', '');

        if ($baseUrl === '') {
            throw new RuntimeException('AI_BASE_URL is not configured');
        }

        if ($apiKey === '') {
            throw new RuntimeException('AI_API_KEY is not configured');
        }

        if ($model === '') {
            throw new RuntimeException('AI_MODEL is not configured');
        }

        $this->providers[] = [
            'mode' => $this->normalizeMode((string) config('chatbot.ai.mode', 'chat_completions')),
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'model' => $model,
            'timeout' => $this->timeout,
        ];

        $fallback = $this->resolveFallbackProvider();
        if ($fallback !== null) {
            $this->providers[] = $fallback;
        }
    }

    public function chat(array $messages, array $options = []): array
    {
        return $this->runWithFailover(
            fn (array $provider): array => $this->dispatchChat($provider, $messages, $options),
            'chat',
            // Caller trong một request đồng bộ (triage, tìm kiếm giọng nói) xin một
            // budget cho cả lời gọi, chứ không phải một budget cho mỗi nhà cung cấp.
            isset($options['timeout']) ? max(1, (int) $options['timeout']) : null,
            (bool) ($options['dedicated_budgets'] ?? false)
        );
    }

    public function analyzeImage(string $prompt, string $base64Image, string $mimeType): string
    {
        return $this->runWithFailover(
            function (array $provider) use ($prompt, $base64Image, $mimeType): string {
                try {
                    return $this->dispatchVision($provider, $prompt, $base64Image, $mimeType);
                } catch (RuntimeException $e) {
                    if ($this->isVisionUnsupportedError($e)) {
                        throw new AiProviderVisionNotSupportedException(
                            'AI provider model does not support image input',
                            previous: $e
                        );
                    }

                    throw $e;
                }
            },
            'vision'
        );
    }

    /**
     * Metadata nhà cung cấp đã phục vụ lời gọi thành công gần nhất.
     *
     * @return array{index: int, mode: string, model: string}|null
     */
    public function describeLastProvider(): ?array
    {
        if ($this->lastProviderIndex === null) {
            return null;
        }

        $provider = $this->providers[$this->lastProviderIndex];

        return [
            'index' => $this->lastProviderIndex,
            'mode' => $provider['mode'],
            'model' => $provider['model'],
        ];
    }

    /**
     * Các nhà cung cấp đã cấu hình, không kèm secret, dùng cho chẩn đoán.
     *
     * @return array<int, array{index: int, mode: string, base_url: string, model: string, has_key: bool}>
     */
    public function describeProviders(): array
    {
        $described = [];

        foreach ($this->providers as $index => $provider) {
            $described[] = [
                'index' => $index,
                'mode' => $provider['mode'],
                'base_url' => $provider['base_url'],
                'model' => $provider['model'],
                'has_key' => $provider['api_key'] !== '',
            ];
        }

        return $described;
    }

    /**
     * Gửi một request tối thiểu đến một nhà cung cấp, không failover.
     *
     * @return array{ok: bool, duration_ms: float, error: string|null}
     */
    public function probeProvider(int $index, bool $withVision = false): array
    {
        $provider = $this->providers[$index] ?? null;
        if ($provider === null) {
            return ['ok' => false, 'duration_ms' => 0.0, 'error' => 'Provider index not configured'];
        }

        $startedAt = microtime(true);

        try {
            if ($withVision) {
                [$probeImage, $probeMimeType] = $this->buildVisionProbeImage();
                $this->dispatchVision($provider, 'Describe this image in one word.', $probeImage, $probeMimeType);
            } else {
                $this->dispatchChat($provider, [
                    ['role' => 'user', 'content' => 'ping'],
                ], []);
            }

            return [
                'ok' => true,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'error' => Str::limit($exception->getMessage(), 200),
            ];
        }
    }

    /**
     * Một ảnh nhỏ nhưng thật sự dùng cho vision probe; các nhà cung cấp upstream
     * từ chối pixel placeholder 1x1 với lỗi định dạng ảnh không hỗ trợ.
     *
     * @return array{0: string, 1: string} base64 payload và mime type
     */
    protected function buildVisionProbeImage(): array
    {
        if (function_exists('imagecreatetruecolor')) {
            $image = imagecreatetruecolor(64, 64);
            imagefilledrectangle($image, 0, 0, 63, 63, imagecolorallocate($image, 255, 255, 255));
            imagefilledrectangle($image, 16, 16, 47, 47, imagecolorallocate($image, 16, 185, 129));

            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();
            imagedestroy($image);

            return [base64_encode($bytes), 'image/png'];
        }

        return [
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'image/png',
        ];
    }

    protected function dispatchChat(array $provider, array $messages, array $options): array
    {
        // Không xử lý timeout ở đây: `chat()` biến override của caller thành một
        // budget cho cả lời gọi và `runWithFailover()` ghi phần chia cho từng nhà cung cấp.
        return match ($provider['mode']) {
            'anthropic_messages' => $this->sendAnthropicMessagesRequest($provider, $messages, $options),
            'responses' => $this->sendResponsesRequest($provider, $messages, $options),
            default => $this->sendChatCompletionsRequest($provider, $messages, $options),
        };
    }

    protected function dispatchVision(array $provider, string $prompt, string $base64Image, string $mimeType): string
    {
        // Data URL được dùng trong từng nhánh: một ảnh tải lên có thể là vài MB base64,
        // và mode anthropic gửi raw payload thay vì data URL.
        return match ($provider['mode']) {
            'anthropic_messages' => $this->sendAnthropicVisionRequest($provider, $prompt, $base64Image, $mimeType),
            'responses' => $this->sendResponsesVisionRequest($provider, $prompt, "data:{$mimeType};base64,{$base64Image}"),
            default => $this->sendChatCompletionsVisionRequest($provider, $prompt, "data:{$mimeType};base64,{$base64Image}"),
        };
    }

    /**
     * Chạy một lời gọi nhà cung cấp, chuyển sang nhà cung cấp kế tiếp khi thất bại.
     * Lỗi kết nối là Exceptions chứ không phải RuntimeExceptions, nên bắt mọi lỗi nào
     * phía nhà cung cấp cũng được bắt tại đây.
     *
     * `$budget` là số giây cho CẢ LỜI GỌI, chứ không phải cho từng nhà cung cấp: không có
     * nó hai nhà cung cấp chậm mỗi một lần đốt timeout của caller và tổng thời gian gấp đôi,
     * dài hơn cả thời gian browser client chờ và dài hơn cả lifetime của lock cache
     * tìm kiếm giọng nói. Mỗi nhà cung cấp nhận phần còn lại của các nhà cung cấp trước,
     * sẵn 1 giây vì Guzzle đọc `timeout(0)` như "chờ mãi mãi".
     *
     * `$dedicatedBudgets` thoát ngân sách chung: mỗi nhà cung cấp dùng timeout cấu hình
     * riêng của nó. Dành cho caller chạy trong worker — không browser nào đang chờ
     * request, nên cắt timeout của fallback xuống phần dư (thực tế là 1s khi primary
     * đốt hết ngân sách) chỉ biến mọi lần chuyển dự phòng thành thất bại chắc chắn.
     */
    protected function runWithFailover(callable $callback, string $operation, ?int $budget = null, bool $dedicatedBudgets = false): mixed
    {
        $lastException = null;
        $startedAt = microtime(true);

        foreach ($this->providers as $index => $provider) {
            if ($dedicatedBudgets) {
                // Per-provider budgets: index 0 honours the caller's override, later
                // providers keep their configured timeout. No leftover arithmetic.
                if ($index === 0 && $budget !== null) {
                    $provider['timeout'] = $budget;
                }
            } elseif ($budget !== null) {
                $provider['timeout'] = max(1, (int) ceil($budget - (microtime(true) - $startedAt)));
            }

            try {
                $result = $callback($provider);
                $this->lastProviderIndex = $index;

                return $result;
            } catch (\Exception $exception) {
                $lastException = $exception;

                $hasNextProvider = isset($this->providers[$index + 1]);
                Log::warning('AI provider call failed', [
                    'operation' => $operation,
                    'provider_index' => $index,
                    'mode' => $provider['mode'],
                    'model' => $provider['model'],
                    'failing_over' => $hasNextProvider,
                    // post() đã log status và response body cho các lỗi HTTP, nên dòng này
                    // được giữ ngắn có chủ ý.
                    'error' => Str::limit($exception->getMessage(), 120),
                ]);
            }
        }

        throw $lastException ?? new RuntimeException('No AI provider is configured');
    }

    /**
     * @return array{mode: string, base_url: string, api_key: string, model: string}|null
     */
    protected function resolveFallbackProvider(): ?array
    {
        $baseUrl = rtrim((string) config('chatbot.ai.fallback.base_url', ''), '/');
        $apiKey = (string) config('chatbot.ai.fallback.api_key', '');
        $model = (string) config('chatbot.ai.fallback.model', '');

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            return null;
        }

        // Cùng host và cùng model không là dự phòng: bất điều gì đã đốt cái chính
        // (timeout, rate limit, upstream chết) cũng đốt cái này, và khách trả giá cho
        // nó hai lần — một lần AI_TIMEOUT nữa trước khi lỗi cuối cùng hiện ra. Riêng
        // việc khác `mode` không biến nó thành nhà cung cấp thứ hai.
        $primary = $this->providers[0];
        if ($baseUrl === $primary['base_url'] && $model === $primary['model']) {
            Log::warning('AI fallback provider ignored: same base_url and model as the primary', [
                'base_url' => $baseUrl,
                'model' => $model,
            ]);

            return null;
        }

        return [
            'mode' => $this->normalizeMode((string) config('chatbot.ai.fallback.mode', 'anthropic_messages')),
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'model' => $model,
            // Nhà cung cấp dự phòng thường là model chậm hơn hoặc đắt hơn, nên nó có budget riêng.
            'timeout' => max(1, (int) config('chatbot.ai.fallback.timeout', $this->timeout)),
        ];
    }

    protected function normalizeMode(string $mode): string
    {
        $mode = Str::of($mode)->lower()->replace('-', '_')->trim()->toString();

        return match ($mode) {
            'anthropic', 'anthropic_messages', 'messages' => 'anthropic_messages',
            'responses' => 'responses',
            default => 'chat_completions',
        };
    }

    protected function sendChatCompletionsRequest(array $provider, array $messages, array $options): array
    {
        $payload = [
            'model' => $provider['model'],
            'messages' => $this->normalizeMessages($messages),
        ];

        if (!empty($options['functions'])) {
            $payload['tools'] = $this->convertFunctionsToChatCompletionTools($options['functions']);
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->post($provider, '/chat/completions', $payload);

        return $this->normalizeChatCompletionsResponse($this->decodeJsonPayload($response, 'chat completion'));
    }

    protected function sendChatCompletionsVisionRequest(array $provider, string $prompt, string $dataUrl): string
    {
        $payload = [
            'model' => $provider['model'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->post($provider, '/chat/completions', $payload);
        $data = $this->decodeJsonPayload($response, 'vision chat completion');

        return $this->normalizeChatCompletionsResponse($data)['choices'][0]['message']['content'] ?? '';
    }

    protected function sendResponsesRequest(array $provider, array $messages, array $options): array
    {
        [$instructions, $input] = $this->splitResponsesInput($messages);

        $payload = [
            'model' => $provider['model'],
            'input' => $input,
        ];

        if ($instructions !== '') {
            $payload['instructions'] = $instructions;
        }

        if (!empty($options['functions'])) {
            $payload['tools'] = $this->convertFunctionsToResponsesTools($options['functions']);
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->post($provider, '/responses', $payload);

        return $this->normalizeResponsesResponse($this->decodeJsonPayload($response, 'responses'));
    }

    protected function sendResponsesVisionRequest(array $provider, string $prompt, string $dataUrl): string
    {
        $payload = [
            'model' => $provider['model'],
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'input_image',
                            'image_url' => $dataUrl,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->post($provider, '/responses', $payload);
        $data = $this->decodeJsonPayload($response, 'vision responses');

        return $this->normalizeResponsesResponse($data)['choices'][0]['message']['content'] ?? '';
    }

    protected function sendAnthropicMessagesRequest(array $provider, array $messages, array $options): array
    {
        $payload = $this->buildAnthropicPayload(
            $provider,
            $this->buildAnthropicConversation($messages),
            $this->collectSystemInstructions($messages)
        );

        if (!empty($options['functions'])) {
            $payload['tools'] = $this->convertFunctionsToAnthropicTools($options['functions']);
            $payload['tool_choice'] = ['type' => 'auto'];
        }

        $response = $this->post($provider, '/messages', $payload);

        return $this->normalizeAnthropicResponse($this->decodeJsonPayload($response, 'anthropic messages'));
    }

    protected function sendAnthropicVisionRequest(
        array $provider,
        string $prompt,
        string $base64Image,
        string $mimeType
    ): string {
        $payload = $this->buildAnthropicPayload($provider, [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mimeType,
                            'data' => $base64Image,
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ],
        ], '');

        $response = $this->post($provider, '/messages', $payload);
        $data = $this->decodeJsonPayload($response, 'anthropic vision');

        return $this->normalizeAnthropicResponse($data)['choices'][0]['message']['content'] ?? '';
    }

    protected function buildAnthropicPayload(array $provider, array $messages, string $system): array
    {
        $payload = [
            'model' => $provider['model'],
            'max_tokens' => max(256, (int) config('chatbot.ai.max_tokens', 2048)),
            'messages' => $messages,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        // Adaptive thinking chia sẻ budget max_tokens với câu trả lời, nên chatbot
        // giữ thinking tắt trừ khi được bật rõ ràng.
        if ($this->normalizeThinkingMode() === 'disabled') {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        return $payload;
    }

    protected function normalizeThinkingMode(): string
    {
        $thinking = Str::of((string) config('chatbot.ai.thinking', 'disabled'))->lower()->trim()->toString();

        return $thinking === 'adaptive' ? 'adaptive' : 'disabled';
    }

    protected function collectSystemInstructions(array $messages): string
    {
        $instructions = [];

        foreach ($this->normalizeMessages($messages) as $message) {
            if ($message['role'] === 'system' && trim((string) $message['content']) !== '') {
                $instructions[] = (string) $message['content'];
            }
        }

        return trim(implode("\n\n", $instructions));
    }

    /**
     * Anthropic giữ system prompt ở cấp độ trên cùng và yêu cầu lượt đầu tiên
     * phải đến từ người dùng.
     */
    protected function buildAnthropicConversation(array $messages): array
    {
        $conversation = [];

        foreach ($this->normalizeMessages($messages) as $message) {
            if (!in_array($message['role'], ['user', 'assistant'], true)) {
                continue;
            }

            $content = trim((string) $message['content']);
            if ($content === '') {
                continue;
            }

            if (empty($conversation) && $message['role'] !== 'user') {
                continue;
            }

            $conversation[] = [
                'role' => $message['role'],
                'content' => $content,
            ];
        }

        if (empty($conversation)) {
            throw new RuntimeException('Anthropic messages request requires at least one user message');
        }

        return $conversation;
    }

    protected function convertFunctionsToAnthropicTools(array $functions): array
    {
        return array_values(array_map(function (array $function): array {
            return [
                'name' => $function['name'] ?? '',
                'description' => $function['description'] ?? '',
                'input_schema' => $function['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }, $functions));
    }

    protected function normalizeAnthropicResponse(array $data): array
    {
        $textSegments = [];
        $functionCall = null;

        foreach (($data['content'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            if ($type === 'text' && isset($block['text'])) {
                $textSegments[] = (string) $block['text'];
                continue;
            }

            if ($type === 'tool_use' && $functionCall === null) {
                $functionCall = [
                    'name' => (string) ($block['name'] ?? ''),
                    'arguments' => $this->scrubToolArgumentMarkup(json_encode(
                        is_array($block['input'] ?? null) ? $block['input'] : [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )),
                ];
            }
        }

        $message = [
            'role' => 'assistant',
            'content' => trim(implode("\n", array_filter($textSegments))),
        ];

        if ($functionCall !== null) {
            $message['function_call'] = $functionCall;
        }

        // Cùng guard: văn bản rỗng + không có tool call = upstream chết, throw để kích hoạt failover.
        if ($message['content'] === '' && !isset($message['function_call'])) {
            throw new RuntimeException('AI provider returned an empty response: no content and no tool call');
        }

        return [
            'choices' => [
                [
                    'message' => $message,
                    'finish_reason' => $data['stop_reason'] ?? null,
                ],
            ],
            'raw' => $data,
        ];
    }

    /**
     * Decode response của nhà cung cấp, hiện đủ chi tiết để phân biệt base URL sai
     * (trang lỗi HTML) với thất bại thật sự của nhà cung cấp.
     */
    protected function decodeJsonPayload(Response $response, string $context): array
    {
        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException(sprintf(
                'AI provider returned a non-JSON %s response (status %d from %s): %s',
                $context,
                $response->status(),
                (string) $response->effectiveUri(),
                Str::limit(preg_replace('/\s+/', ' ', $response->body()) ?? '', 200)
            ));
        }

        // Một số gateway trả lời HTTP 200 với error envelope thay vì nội dung.
        if (isset($data['error']) && !isset($data['content']) && !isset($data['choices']) && !isset($data['output'])) {
            $error = $data['error'];
            $errorMessage = is_array($error)
                ? (string) ($error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE))
                : (string) $error;

            throw new RuntimeException(sprintf(
                'AI provider returned an error in the %s response: %s',
                $context,
                Str::limit($errorMessage, 300)
            ));
        }

        return $data;
    }

    protected function post(array $provider, string $path, array $payload): Response
    {
        $url = $provider['base_url'] . $path;
        $startedAt = microtime(true);

        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($provider['timeout'] ?? $this->timeout);

        $request = $provider['mode'] === 'anthropic_messages'
            ? $request->withHeaders([
                'x-api-key' => $provider['api_key'],
                'anthropic-version' => (string) config('chatbot.ai.anthropic_version', '2023-06-01'),
            ])
            : $request->withToken($provider['api_key']);

        $response = $request->post($url, $payload);

        $duration = (microtime(true) - $startedAt) * 1000;

        if ($response->failed()) {
            Log::warning('AI provider request failed', [
                'mode' => $provider['mode'],
                'url' => $url,
                'status' => $response->status(),
                'duration_ms' => $duration,
                'body' => Str::limit($response->body(), 500),
            ]);

            throw new RuntimeException(
                'AI provider request failed with status ' . $response->status() . ': ' . Str::limit($response->body(), 300)
            );
        }

        Log::info('AI provider request successful', [
            'mode' => $provider['mode'],
            'url' => $url,
            'duration_ms' => $duration,
        ]);

        return $response;
    }

    protected function normalizeMessages(array $messages): array
    {
        return array_values(array_map(function (array $message): array {
            return [
                'role' => $message['role'] ?? 'user',
                'content' => $message['content'] ?? '',
            ];
        }, $messages));
    }

    protected function splitResponsesInput(array $messages): array
    {
        $instructions = [];
        $input = [];

        foreach ($this->normalizeMessages($messages) as $message) {
            if ($message['role'] === 'system') {
                $instructions[] = $message['content'];
                continue;
            }

            $input[] = $message;
        }

        return [trim(implode("\n\n", $instructions)), $input];
    }

    protected function convertFunctionsToChatCompletionTools(array $functions): array
    {
        return array_values(array_map(function (array $function): array {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $function['name'] ?? '',
                    'description' => $function['description'] ?? '',
                    'parameters' => $function['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }, $functions));
    }

    protected function convertFunctionsToResponsesTools(array $functions): array
    {
        return array_values(array_map(function (array $function): array {
            return [
                'type' => 'function',
                'name' => $function['name'] ?? '',
                'description' => $function['description'] ?? '',
                'parameters' => $function['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }, $functions));
    }

    /**
     * Các tag trong arguments của tool call là nhiễu transport, không phải nội dung.
     * opencode (session 64) đã tiền tố một giá trị bằng tag mà nó được yêu cầu điền —
     * `<parameter>why>Sốt cao` hoặc `<parameter name="why">Sốt cao` — và mọi consumer
     * decode các arguments này (truy vấn chatbot, briefing triage, facts triage), nên
     * việc scrub thuộc về đây chứ không phải từng service mù quáng hiện giá trị đó.
     *
     * Scrub các giá trị đã decode, không bao giờ scrub blob đã encode: regex trên văn bản
     * JSON có thể chạy qua đầu một tag chưa đóng và nuốt các field ở giữa (một ghi chú liều
     * lượng như "Dùng <param 500mg" đã nuốt tất cả đến dấu `>` kế tiếp), và kết quả vẫn là
     * JSON hợp lệ nên mất dữ liệu đi trong im lặng.
     *
     * Luôn trả về string, vì đó là hình dạng mọi consumer dùng: SangLocAiService::decodeToolCall
     * từ chối giá trị không phải string, và ChatbotService đưa giá trị thẳng vào json_decode.
     * Payload không có gì để scrub được trả lại byte nguyên vẹn.
     */
    protected function scrubToolArgumentMarkup(mixed $arguments): string
    {
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);

            if (! is_array($decoded)) {
                // Không phải JSON object: scrub văn bản nguyên vẹn thay vì bỏ đi.
                return $this->scrubTagMarkup($arguments);
            }

            [$scrubbed, $changed] = $this->scrubArgumentValues($decoded);

            return $changed
                ? json_encode($scrubbed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $arguments;
        }

        // Một số nhà cung cấp gửi arguments là object/array thay vì JSON string.
        // Các consumer phía sau dùng string (SangLocAiService từ chối non-string,
        // ChatbotService đưa thẳng vào json_decode). Chuẩn hoá thành JSON string.
        if (is_array($arguments)) {
            [$scrubbed] = $this->scrubArgumentValues($arguments);

            return (string) json_encode($scrubbed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return is_scalar($arguments) ? (string) $arguments : '{}';
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array{0: array<array-key, mixed>, 1: bool} các giá trị đã scrub và có gì thay đổi hay không
     */
    private function scrubArgumentValues(array $values): array
    {
        $changed = false;

        array_walk_recursive($values, function (&$value) use (&$changed): void {
            if (! is_string($value)) {
                return;
            }

            $scrubbed = $this->scrubTagMarkup($value);

            if ($scrubbed !== $value) {
                $value = $scrubbed;
                $changed = true;
            }
        });

        return [$values, $changed];
    }

    /**
     * Bản thân tag, rồi phần `why>` mà model để lại sau khi tag mở biến mất. Chạy trên
     * giá trị đơn giản, nói mà `<param` không có dấu `>` đóng chỉ là văn bản và không thể khớp.
     */
    protected function scrubTagMarkup(string $text): string
    {
        return preg_replace('/<\/?(?:parameter|param|arg|tool_call|function_call)\b[^>]*>\s*(?:[a-z_]+>)?/iu', '', $text) ?? $text;
    }

    protected function normalizeChatCompletionsResponse(array $data): array
    {
        $message = $data['choices'][0]['message'] ?? [];

        if (!empty($message['tool_calls'][0]['function'])) {
            $function = $message['tool_calls'][0]['function'];
            $message['function_call'] = [
                'name' => $function['name'] ?? '',
                'arguments' => $this->scrubToolArgumentMarkup($function['arguments'] ?? '{}'),
            ];
        }

        $message['content'] = $this->extractContentText($message['content'] ?? '');

        // HTTP 200 mà không có văn bản cũng không có tool call là upstream chết, không
        // phải câu trả lời: một số route combo trả `finish_reason: stop` với nội dung rỗng
        // khi upstream của chúng từ chối tool schema. Throw tại đây là thư gửi lời gọi
        // đi theo nhánh failover thay vì lưu một kết quả trống trên trang.
        if ($message['content'] === '' && !isset($message['function_call'])) {
            throw new RuntimeException('AI provider returned an empty response: no content and no tool call');
        }

        return [
            'choices' => [
                [
                    'message' => $message,
                    'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
                ],
            ],
            'raw' => $data,
        ];
    }

    protected function normalizeResponsesResponse(array $data): array
    {
        if (isset($data['choices'])) {
            return $this->normalizeChatCompletionsResponse($data);
        }

        $functionCall = $this->extractResponsesFunctionCall($data);
        if ($functionCall !== null) {
            return [
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'function_call' => $functionCall,
                        ],
                        'finish_reason' => 'tool_calls',
                    ],
                ],
                'raw' => $data,
            ];
        }

        return [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $this->extractResponsesText($data),
                    ],
                    'finish_reason' => $data['status'] ?? null,
                ],
            ],
            'raw' => $data,
        ];
    }

    protected function extractResponsesFunctionCall(array $data): ?array
    {
        foreach (($data['output'] ?? []) as $item) {
            if (($item['type'] ?? null) !== 'function_call') {
                continue;
            }

            return [
                'name' => $item['name'] ?? '',
                'arguments' => $this->scrubToolArgumentMarkup($item['arguments'] ?? '{}'),
            ];
        }

        return null;
    }

    protected function extractResponsesText(array $data): string
    {
        if (isset($data['output_text']) && is_string($data['output_text'])) {
            return trim($data['output_text']);
        }

        $segments = [];
        foreach (($data['output'] ?? []) as $item) {
            if (($item['type'] ?? null) === 'message') {
                $segments[] = $this->extractContentText($item['content'] ?? '');
            }
        }

        return trim(implode("\n", array_filter($segments)));
    }

    protected function extractContentText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $segments = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $segments[] = $part;
                continue;
            }

            if (is_array($part)) {
                $segments[] = $part['text'] ?? $part['content'] ?? '';
            }
        }

        return trim(implode("\n", array_filter($segments)));
    }

    protected function isVisionUnsupportedError(RuntimeException $exception): bool
    {
        $message = Str::of($exception->getMessage())->lower()->toString();

        return Str::contains($message, [
            'image input is not supported',
            'image inputs are not supported',
            'does not support image',
            'does not support images',
            'model does not support vision',
            'vision is not supported',
            'unsupported image',
            'unsupported content type',
            'invalid content type',
            'input_image is not supported',
            'image_url is not supported',
            'image blocks are not supported',
            'unsupported media type',
            // Chú ý giới hạn theo cụm từ: "media_type" đơn lẻ cũng xuất hiện trong
            // "image does not match the provided media_type", đó là ảnh tải lên lỗi,
            // chứ không phải model không có vision.
            'unsupported media_type',
            'media_type is not supported',
        ]);
    }
}
