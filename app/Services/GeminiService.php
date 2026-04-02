<?php

namespace App\Services;

use Gemini;
use Gemini\Client;
use Gemini\Data\Content;
use Gemini\Data\GenerationConfig;
use Gemini\Data\FunctionCall;
use Gemini\Data\FunctionDeclaration;
use Gemini\Data\FunctionResponse;
use Gemini\Data\Part;
use Gemini\Data\Schema;
use Gemini\Data\Tool;
use Gemini\Enums\DataType;
use Gemini\Enums\Role;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GeminiService
{
    protected Client $client;
    protected int $maxRetries;
    protected int $retryDelay;
    protected int $timeout;

    /**
     * Initialize Gemini service with configuration.
     */
    public function __construct()
    {
        $apiKey = config('chatbot.gemini.api_key');
        
        if (empty($apiKey)) {
            throw new \RuntimeException('Gemini API key not configured');
        }

        $this->client = Gemini::client($apiKey);
        $this->maxRetries = 3;
        $this->retryDelay = 1000; // milliseconds
        $this->timeout = 30; // seconds
    }

    /**
     * Send a chat completion request with retry logic.
     * Uses proper Gemini SDK patterns: withSystemInstruction + startChat + sendMessage.
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $options Additional options (functions, temperature, etc.)
     * @return array Response in OpenAI-compatible format
     * @throws \Exception
     */
    public function chat(array $messages, array $options = []): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                // Extract system message and conversation parts
                $systemPrompt = null;
                $conversationMessages = [];

                foreach ($messages as $message) {
                    if ($message['role'] === 'system') {
                        $systemPrompt = $message['content'];
                    } elseif ($message['role'] === 'function') {
                        // Skip function messages - handled separately in ChatbotService
                        continue;
                    } else {
                        $conversationMessages[] = $message;
                    }
                }

                // Build generation config
                $generationConfig = new GenerationConfig(
                    maxOutputTokens: $options['max_tokens'] ?? config('chatbot.gemini.max_tokens', 500),
                    temperature: $options['temperature'] ?? config('chatbot.gemini.temperature', 0.7),
                );

                $startTime = microtime(true);

                // Prepare model with system instruction
                $model = $this->client->generativeModel(
                    model: config('chatbot.gemini.model', 'gemini-2.5-flash')
                );

                // Set system instruction (critical for function calling behavior)
                if ($systemPrompt) {
                    $model = $model->withSystemInstruction(
                        Content::parse($systemPrompt)
                    );
                }

                // Set generation config
                $model = $model->withGenerationConfig($generationConfig);

                // Add tools if provided
                if (isset($options['functions'])) {
                    $tools = $this->convertFunctionsToGeminiTools($options['functions']);
                    $model = $model->withTool($tools);
                }

                // Build conversation history for multi-turn
                $history = [];
                $lastUserMessage = null;

                if (count($conversationMessages) > 1) {
                    // All messages except the last become history
                    $historyMessages = array_slice($conversationMessages, 0, -1);
                    foreach ($historyMessages as $msg) {
                        $role = $msg['role'] === 'assistant' ? Role::MODEL : Role::USER;
                        $history[] = Content::parse(
                            part: $msg['content'],
                            role: $role
                        );
                    }
                }
                
                // Last message is always from user
                $lastMessage = end($conversationMessages);
                $lastUserMessage = $lastMessage['content'] ?? '';

                // Use startChat for proper multi-turn with function calling
                $chat = $model->startChat(history: $history);
                $response = $chat->sendMessage($lastUserMessage);

                $processingTime = (microtime(true) - $startTime) * 1000;

                Log::info('Gemini chat completion successful', [
                    'attempt' => $attempt + 1,
                    'processing_time_ms' => $processingTime,
                ]);

                return $this->convertGeminiResponseToStandardFormat($response);

            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;

                Log::warning('Gemini API request failed', [
                    'attempt' => $attempt,
                    'max_retries' => $this->maxRetries,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $this->maxRetries) {
                    // Exponential backoff
                    $delay = $this->retryDelay * pow(2, $attempt - 1);
                    usleep($delay * 1000);
                } else {
                    Log::error('Gemini API request failed after all retries', [
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        throw new \Exception(
            'Gemini API request failed after ' . $this->maxRetries . ' attempts: ' . 
            ($lastException ? $lastException->getMessage() : 'Unknown error')
        );
    }

    /**
     * Convert function declarations to Gemini Tool format.
     *
     * @param array $functions
     * @return Tool
     */
    protected function convertFunctionsToGeminiTools(array $functions): Tool
    {
        $functionDeclarations = [];
        
        foreach ($functions as $function) {
            $functionDeclarations[] = new FunctionDeclaration(
                name: $function['name'],
                description: $function['description'],
                parameters: new Schema(
                    type: DataType::OBJECT,
                    properties: $this->convertParametersToGeminiSchema($function['parameters']['properties'] ?? []),
                    required: $function['parameters']['required'] ?? []
                )
            );
        }

        return new Tool(functionDeclarations: $functionDeclarations);
    }

    /**
     * Convert parameter properties to Gemini Schema format.
     *
     * @param array $properties
     * @return array
     */
    protected function convertParametersToGeminiSchema(array $properties): array
    {
        $schemas = [];
        
        foreach ($properties as $name => $property) {
            $type = $this->mapTypeToGeminiDataType($property['type'] ?? 'string');
            
            $schema = new Schema(
                type: $type,
                description: $property['description'] ?? ''
            );
            
            // Handle array items
            if ($type === DataType::ARRAY && isset($property['items'])) {
                $itemType = $this->mapTypeToGeminiDataType($property['items']['type'] ?? 'string');
                $schema = new Schema(
                    type: $type,
                    description: $property['description'] ?? '',
                    items: new Schema(type: $itemType)
                );
            }
            
            $schemas[$name] = $schema;
        }
        
        return $schemas;
    }

    /**
     * Map OpenAI type to Gemini DataType.
     *
     * @param string $type
     * @return DataType
     */
    protected function mapTypeToGeminiDataType(string $type): DataType
    {
        return match($type) {
            'string' => DataType::STRING,
            'number', 'integer' => DataType::NUMBER,
            'boolean' => DataType::BOOLEAN,
            'array' => DataType::ARRAY,
            'object' => DataType::OBJECT,
            default => DataType::STRING,
        };
    }

    /**
     * Convert Gemini response to standard format compatible with OpenAI structure.
     *
     * @param mixed $response
     * @return array
     */
    protected function convertGeminiResponseToStandardFormat($response): array
    {
        $result = [
            'choices' => []
        ];

        try {
            // Check for function calls via parts
            $hasFunctionCall = false;
            
            try {
                $parts = $response->parts();
                if (!empty($parts)) {
                    foreach ($parts as $part) {
                        if (isset($part->functionCall) && $part->functionCall !== null) {
                            $functionCall = $part->functionCall;
                            
                            Log::info('Gemini function call detected', [
                                'function_name' => $functionCall->name,
                                'arguments' => $functionCall->args,
                            ]);
                            
                            $result['choices'][] = [
                                'message' => [
                                    'role' => 'assistant',
                                    'content' => null,
                                    'function_call' => [
                                        'name' => $functionCall->name,
                                        'arguments' => json_encode($functionCall->args)
                                    ]
                                ]
                            ];
                            $hasFunctionCall = true;
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::debug('Parts check failed, trying text()', ['error' => $e->getMessage()]);
            }

            if (!$hasFunctionCall) {
                // Regular text response - extract from parts or text()
                $textContent = '';
                
                try {
                    $parts = $response->parts();
                    foreach ($parts as $part) {
                        if (isset($part->text) && $part->text !== null) {
                            $textContent .= $part->text;
                        }
                    }
                } catch (\Exception $e) {
                    // Fallback to text() method
                    try {
                        $textContent = $response->text();
                    } catch (\ValueError $ve) {
                        // If text() fails, try to extract from candidates
                        if (isset($response->candidates[0]->content->parts)) {
                            foreach ($response->candidates[0]->content->parts as $part) {
                                if (isset($part->text)) {
                                    $textContent .= $part->text;
                                }
                            }
                        }
                    }
                }

                $result['choices'][] = [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $textContent ?: 'Xin lỗi, tôi không thể tạo phản hồi.'
                    ]
                ];
            }
        } catch (\Exception $e) {
            Log::error('Failed to convert Gemini response', [
                'error' => $e->getMessage()
            ]);
            
            $result['choices'][] = [
                'message' => [
                    'role' => 'assistant',
                    'content' => 'Xin lỗi, đã xảy ra lỗi khi xử lý phản hồi.'
                ]
            ];
        }

        return $result;
    }

    /**
     * Get a fallback response when AI service is unavailable.
     *
     * @return array Fallback response structure
     */
    public function getFallbackResponse(): array
    {
        return [
            'role' => 'assistant',
            'content' => 'Xin lỗi, hệ thống AI tạm thời không khả dụng. Vui lòng thử lại sau hoặc sử dụng chức năng tìm kiếm thủ công.',
            'fallback' => true,
        ];
    }

    /**
     * Check if the Gemini service is available.
     *
     * @return bool True if service is available
     */
    public function isAvailable(): bool
    {
        $cacheKey = 'gemini_service_available';
        
        // Check cache first to avoid repeated API calls
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            // Simple test request
            $this->client->generativeModel(model: 'gemini-2.5-flash')
                ->generateContent('Hello');
            
            Cache::put($cacheKey, true, now()->addMinutes(5));
            return true;
            
        } catch (\Exception $e) {
            Log::warning('Gemini service availability check failed', [
                'error' => $e->getMessage(),
            ]);
            
            Cache::put($cacheKey, false, now()->addMinutes(1));
            return false;
        }
    }

    /**
     * Set custom retry configuration.
     *
     * @param int $maxRetries Maximum number of retry attempts
     * @param int $retryDelay Initial delay between retries in milliseconds
     * @return self
     */
    public function setRetryConfig(int $maxRetries, int $retryDelay): self
    {
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $retryDelay;
        
        return $this;
    }

    /**
     * Set request timeout.
     *
     * @param int $timeout Timeout in seconds
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        
        return $this;
    }
}
