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
    protected string $mode;
    protected string $baseUrl;
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->mode = $this->normalizeMode((string) config('chatbot.ai.mode', 'chat_completions'));
        $this->baseUrl = rtrim((string) config('chatbot.ai.base_url', ''), '/');
        $this->apiKey = (string) config('chatbot.ai.api_key', '');
        $this->model = (string) config('chatbot.ai.model', '');

        if ($this->baseUrl === '') {
            throw new RuntimeException('AI_BASE_URL is not configured');
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('AI_API_KEY is not configured');
        }

        if ($this->model === '') {
            throw new RuntimeException('AI_MODEL is not configured');
        }
    }

    public function chat(array $messages, array $options = []): array
    {
        return match ($this->mode) {
            'responses' => $this->sendResponsesRequest($messages, $options),
            default => $this->sendChatCompletionsRequest($messages, $options),
        };
    }

    public function analyzeImage(string $prompt, string $base64Image, string $mimeType): string
    {
        $dataUrl = "data:{$mimeType};base64,{$base64Image}";

        try {
            return match ($this->mode) {
                'responses' => $this->sendResponsesVisionRequest($prompt, $dataUrl),
                default => $this->sendChatCompletionsVisionRequest($prompt, $dataUrl),
            };
        } catch (RuntimeException $e) {
            if ($this->isVisionUnsupportedError($e)) {
                throw new AiProviderVisionNotSupportedException(
                    'AI provider model does not support image input',
                    previous: $e
                );
            }

            throw $e;
        }
    }

    protected function normalizeMode(string $mode): string
    {
        $mode = Str::of($mode)->lower()->replace('-', '_')->trim()->toString();

        return $mode === 'responses' ? 'responses' : 'chat_completions';
    }

    protected function sendChatCompletionsRequest(array $messages, array $options): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $this->normalizeMessages($messages),
        ];

        if (!empty($options['functions'])) {
            $payload['tools'] = $this->convertFunctionsToChatCompletionTools($options['functions']);
            $payload['tool_choice'] = ($options['function_call'] ?? null) === 'auto' ? 'auto' : 'auto';
        }

        $response = $this->post('/chat/completions', $payload);
        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('AI provider returned a non-JSON chat completion response');
        }

        return $this->normalizeChatCompletionsResponse($data);
    }

    protected function sendChatCompletionsVisionRequest(string $prompt, string $dataUrl): string
    {
        $payload = [
            'model' => $this->model,
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

        $response = $this->post('/chat/completions', $payload);
        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('AI provider returned a non-JSON vision chat completion response');
        }

        return $this->normalizeChatCompletionsResponse($data)['choices'][0]['message']['content'] ?? '';
    }

    protected function sendResponsesRequest(array $messages, array $options): array
    {
        [$instructions, $input] = $this->splitResponsesInput($messages);

        $payload = [
            'model' => $this->model,
            'input' => $input,
        ];

        if ($instructions !== '') {
            $payload['instructions'] = $instructions;
        }

        if (!empty($options['functions'])) {
            $payload['tools'] = $this->convertFunctionsToResponsesTools($options['functions']);
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->post('/responses', $payload);
        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('AI provider returned a non-JSON responses response');
        }

        return $this->normalizeResponsesResponse($data);
    }

    protected function sendResponsesVisionRequest(string $prompt, string $dataUrl): string
    {
        $payload = [
            'model' => $this->model,
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

        $response = $this->post('/responses', $payload);
        $data = $response->json();

        if (!is_array($data)) {
            throw new RuntimeException('AI provider returned a non-JSON vision responses response');
        }

        return $this->normalizeResponsesResponse($data)['choices'][0]['message']['content'] ?? '';
    }

    protected function post(string $path, array $payload): Response
    {
        $url = $this->baseUrl . $path;
        $startedAt = microtime(true);

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->post($url, $payload);

        $duration = (microtime(true) - $startedAt) * 1000;

        if ($response->failed()) {
            Log::warning('AI provider request failed', [
                'mode' => $this->mode,
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
            'mode' => $this->mode,
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

    protected function normalizeChatCompletionsResponse(array $data): array
    {
        $message = $data['choices'][0]['message'] ?? [];

        if (!empty($message['tool_calls'][0]['function'])) {
            $function = $message['tool_calls'][0]['function'];
            $message['function_call'] = [
                'name' => $function['name'] ?? '',
                'arguments' => $function['arguments'] ?? '{}',
            ];
        }

        $message['content'] = $this->extractContentText($message['content'] ?? '');

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
                'arguments' => $item['arguments'] ?? '{}',
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
        ]);
    }
}
