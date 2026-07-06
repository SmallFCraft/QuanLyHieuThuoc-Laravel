<?php

namespace Tests\Unit\Services;

use App\Exceptions\AiProviderVisionNotSupportedException;
use App\Services\AiProviderService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderServiceTest extends TestCase
{
    protected function configureProvider(string $mode): void
    {
        config()->set('chatbot.ai.mode', $mode);
        config()->set('chatbot.ai.base_url', 'http://localhost:55597/v1');
        config()->set('chatbot.ai.api_key', 'agt_codex_test');
        config()->set('chatbot.ai.model', 'test-model');
    }

    public function test_chat_completions_mode_posts_minimal_v1_payload(): void
    {
        $this->configureProvider('chat_completions');

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Da, em co the ho tro.',
                        ],
                    ],
                ],
            ]),
        ]);

        $service = new AiProviderService();
        $result = $service->chat(
            [
                ['role' => 'system', 'content' => 'System prompt'],
                ['role' => 'user', 'content' => 'Xin chao'],
            ],
            [
                'functions' => [
                    [
                        'name' => 'search_products',
                        'description' => 'Search products',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'function_call' => 'auto',
                'temperature' => 0.1,
                'max_tokens' => 100,
            ]
        );

        $this->assertSame('Da, em co the ho tro.', $result['choices'][0]['message']['content']);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'http://localhost:55597/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer agt_codex_test')
                && ($payload['model'] ?? null) === 'test-model'
                && isset($payload['messages'][0], $payload['messages'][1])
                && isset($payload['tools'][0]['function'])
                && !array_key_exists('temperature', $payload)
                && !array_key_exists('max_tokens', $payload);
        });
    }

    public function test_responses_mode_normalizes_function_call_output(): void
    {
        $this->configureProvider('responses');

        Http::fake([
            'http://localhost:55597/v1/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'function_call',
                        'name' => 'search_products',
                        'arguments' => '{"query":"vitamin c"}',
                    ],
                ],
            ]),
        ]);

        $service = new AiProviderService();
        $result = $service->chat(
            [
                ['role' => 'system', 'content' => 'System prompt'],
                ['role' => 'user', 'content' => 'Can vitamin c'],
            ],
            [
                'functions' => [
                    [
                        'name' => 'search_products',
                        'description' => 'Search products',
                        'parameters' => ['type' => 'object', 'properties' => []],
                    ],
                ],
                'function_call' => 'auto',
            ]
        );

        $functionCall = $result['choices'][0]['message']['function_call'] ?? [];
        $this->assertSame('search_products', $functionCall['name'] ?? null);
        $this->assertSame('{"query":"vitamin c"}', $functionCall['arguments'] ?? null);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'http://localhost:55597/v1/responses'
                && ($payload['model'] ?? null) === 'test-model'
                && ($payload['instructions'] ?? null) === 'System prompt'
                && isset($payload['input'][0])
                && isset($payload['tools'][0])
                && !array_key_exists('temperature', $payload)
                && !array_key_exists('max_tokens', $payload);
        });
    }

    public function test_chat_completions_mode_posts_vision_payload(): void
    {
        $this->configureProvider('chat_completions');

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"description":"Hop thuoc Vitamin C","products":[{"name":"Vitamin C","quantity":1}]}',
                        ],
                    ],
                ],
            ]),
        ]);

        $service = new AiProviderService();
        $result = $service->analyzeImage('Read this image', base64_encode('image-bytes'), 'image/jpeg');

        $this->assertStringContainsString('Vitamin C', $result);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $content = $payload['messages'][0]['content'] ?? [];

            return $request->url() === 'http://localhost:55597/v1/chat/completions'
                && ($payload['model'] ?? null) === 'test-model'
                && ($content[0]['type'] ?? null) === 'text'
                && ($content[0]['text'] ?? null) === 'Read this image'
                && ($content[1]['type'] ?? null) === 'image_url'
                && str_starts_with($content[1]['image_url']['url'] ?? '', 'data:image/jpeg;base64,');
        });
    }

    public function test_responses_mode_posts_vision_payload(): void
    {
        $this->configureProvider('responses');

        Http::fake([
            'http://localhost:55597/v1/responses' => Http::response([
                'output_text' => '{"description":"Hoa don Vitamin C","products":[{"name":"Vitamin C","quantity":1}]}',
            ]),
        ]);

        $service = new AiProviderService();
        $result = $service->analyzeImage('Read this receipt', base64_encode('image-bytes'), 'image/png');

        $this->assertStringContainsString('Vitamin C', $result);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $content = $payload['input'][0]['content'] ?? [];

            return $request->url() === 'http://localhost:55597/v1/responses'
                && ($payload['model'] ?? null) === 'test-model'
                && ($content[0]['type'] ?? null) === 'input_text'
                && ($content[0]['text'] ?? null) === 'Read this receipt'
                && ($content[1]['type'] ?? null) === 'input_image'
                && str_starts_with($content[1]['image_url'] ?? '', 'data:image/png;base64,');
        });
    }

    public function test_unsupported_vision_model_is_classified(): void
    {
        $this->configureProvider('chat_completions');

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'This model does not support image input',
                ],
            ], 400),
        ]);

        $this->expectException(AiProviderVisionNotSupportedException::class);

        (new AiProviderService())->analyzeImage('Read this image', base64_encode('image-bytes'), 'image/jpeg');
    }
}
