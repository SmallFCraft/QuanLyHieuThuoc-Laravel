<?php

namespace Tests\Unit\Services;

use App\Exceptions\AiProviderVisionNotSupportedException;
use App\Services\AiProviderService;
use Illuminate\Http\Client\ConnectionException;
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
        config()->set('chatbot.ai.max_tokens', 1024);
        config()->set('chatbot.ai.anthropic_version', '2023-06-01');
        config()->set('chatbot.ai.thinking', 'disabled');
        config()->set('chatbot.ai.fallback.base_url', null);
        config()->set('chatbot.ai.fallback.api_key', null);
        config()->set('chatbot.ai.fallback.model', null);
    }

    protected function configureFallbackProvider(string $mode = 'anthropic_messages'): void
    {
        config()->set('chatbot.ai.fallback.mode', $mode);
        config()->set('chatbot.ai.fallback.base_url', 'http://localhost:55598/v1');
        config()->set('chatbot.ai.fallback.api_key', 'agt_codex_fallback');
        config()->set('chatbot.ai.fallback.model', 'claude-sonnet-5');
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

    public function test_provider_timeout_config_is_loaded_and_normalized(): void
    {
        $this->configureProvider('chat_completions');
        config()->set('chatbot.ai.timeout', 12);
        config()->set('chatbot.ai.connect_timeout', 3);

        $service = new AiProviderService();
        $reflection = new \ReflectionClass($service);

        $timeout = $reflection->getProperty('timeout');
        $timeout->setAccessible(true);
        $connectTimeout = $reflection->getProperty('connectTimeout');
        $connectTimeout->setAccessible(true);

        $this->assertSame(12, $timeout->getValue($service));
        $this->assertSame(3, $connectTimeout->getValue($service));

        config()->set('chatbot.ai.timeout', 0);
        config()->set('chatbot.ai.connect_timeout', -5);

        $service = new AiProviderService();

        $this->assertSame(1, $timeout->getValue($service));
        $this->assertSame(1, $connectTimeout->getValue($service));
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

    public function test_anthropic_messages_mode_posts_messages_payload(): void
    {
        $this->configureProvider('anthropic_messages');

        Http::fake([
            'http://localhost:55597/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Da, em co the ho tro.'],
                ],
                'stop_reason' => 'end_turn',
            ]),
        ]);

        $result = (new AiProviderService())->chat(
            [
                ['role' => 'system', 'content' => 'System prompt'],
                ['role' => 'user', 'content' => 'Xin chao'],
                ['role' => 'system', 'content' => 'Extra instruction'],
            ],
            [
                'functions' => [
                    [
                        'name' => 'search_products',
                        'description' => 'Search products',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => ['query' => ['type' => 'string']],
                        ],
                    ],
                ],
                'function_call' => 'auto',
            ]
        );

        $this->assertSame('Da, em co the ho tro.', $result['choices'][0]['message']['content']);
        $this->assertSame('end_turn', $result['choices'][0]['finish_reason']);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'http://localhost:55597/v1/messages'
                && $request->hasHeader('x-api-key', 'agt_codex_test')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && !$request->hasHeader('Authorization')
                && ($payload['model'] ?? null) === 'test-model'
                && ($payload['max_tokens'] ?? null) === 1024
                && ($payload['system'] ?? null) === "System prompt\n\nExtra instruction"
                && ($payload['thinking']['type'] ?? null) === 'disabled'
                && count($payload['messages'] ?? []) === 1
                && ($payload['messages'][0]['role'] ?? null) === 'user'
                && ($payload['messages'][0]['content'] ?? null) === 'Xin chao'
                && isset($payload['tools'][0]['input_schema']['properties']['query'])
                && ($payload['tool_choice']['type'] ?? null) === 'auto';
        });
    }

    public function test_anthropic_messages_mode_normalizes_tool_use_output(): void
    {
        $this->configureProvider('anthropic_messages');

        Http::fake([
            'http://localhost:55597/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => ''],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'search_products',
                        'input' => ['query' => 'vitamin c'],
                    ],
                ],
                'stop_reason' => 'tool_use',
            ]),
        ]);

        $result = (new AiProviderService())->chat(
            [['role' => 'user', 'content' => 'Can vitamin c']],
            ['functions' => [['name' => 'search_products', 'description' => '', 'parameters' => []]]]
        );

        $functionCall = $result['choices'][0]['message']['function_call'] ?? [];

        $this->assertSame('search_products', $functionCall['name'] ?? null);
        $this->assertSame(['query' => 'vitamin c'], json_decode($functionCall['arguments'] ?? '{}', true));
    }

    public function test_anthropic_messages_mode_posts_vision_payload(): void
    {
        $this->configureProvider('anthropic_messages');

        Http::fake([
            'http://localhost:55597/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => '{"description":"Don thuoc","products":[{"name":"Vitamin C","quantity":1}]}'],
                ],
            ]),
        ]);

        $result = (new AiProviderService())->analyzeImage('Read this image', base64_encode('image-bytes'), 'image/png');

        $this->assertStringContainsString('Vitamin C', $result);

        Http::assertSent(function (Request $request): bool {
            $content = $request->data()['messages'][0]['content'] ?? [];

            return $request->url() === 'http://localhost:55597/v1/messages'
                && ($content[0]['type'] ?? null) === 'image'
                && ($content[0]['source']['type'] ?? null) === 'base64'
                && ($content[0]['source']['media_type'] ?? null) === 'image/png'
                && ($content[0]['source']['data'] ?? null) === base64_encode('image-bytes')
                && ($content[1]['type'] ?? null) === 'text'
                && ($content[1]['text'] ?? null) === 'Read this image';
        });
    }

    public function test_chat_fails_over_to_fallback_provider(): void
    {
        $this->configureProvider('chat_completions');
        $this->configureFallbackProvider();

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Upstream access forbidden, please contact administrator',
                    'type' => 'upstream_error',
                ],
            ], 502),
            'http://localhost:55598/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Fallback tra loi.']],
            ]),
        ]);

        $service = new AiProviderService();
        $result = $service->chat([['role' => 'user', 'content' => 'Xin chao']]);

        $this->assertSame('Fallback tra loi.', $result['choices'][0]['message']['content']);
        $this->assertSame(
            ['index' => 1, 'mode' => 'anthropic_messages', 'model' => 'claude-sonnet-5'],
            $service->describeLastProvider()
        );
        Http::assertSentCount(2);
    }

    public function test_chat_throws_when_every_provider_fails(): void
    {
        $this->configureProvider('chat_completions');
        $this->configureFallbackProvider();

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response(['error' => 'down'], 502),
            'http://localhost:55598/v1/messages' => Http::response(['error' => 'down'], 500),
        ]);

        $service = new AiProviderService();

        $this->expectException(\RuntimeException::class);

        try {
            $service->chat([['role' => 'user', 'content' => 'Xin chao']]);
        } finally {
            Http::assertSentCount(2);
            $this->assertNull($service->describeLastProvider());
        }
    }

    public function test_vision_fails_over_when_primary_model_has_no_vision(): void
    {
        $this->configureProvider('chat_completions');
        $this->configureFallbackProvider();

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response([
                'error' => ['message' => 'This model does not support image input'],
            ], 400),
            'http://localhost:55598/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => '{"description":"Hoa don","products":[]}']],
            ]),
        ]);

        $result = (new AiProviderService())->analyzeImage('Read this', base64_encode('image-bytes'), 'image/jpeg');

        $this->assertStringContainsString('Hoa don', $result);
        Http::assertSentCount(2);
    }

    public function test_non_json_response_error_reports_status_and_body(): void
    {
        $this->configureProvider('anthropic_messages');

        Http::fake([
            'http://localhost:55597/v1/messages' => Http::response('<html><body>Forbidden</body></html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        try {
            (new AiProviderService())->chat([['role' => 'user', 'content' => 'Xin chao']]);
            $this->fail('Expected a RuntimeException for a non-JSON provider response.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('non-JSON anthropic messages response', $exception->getMessage());
            $this->assertStringContainsString('status 200', $exception->getMessage());
            $this->assertStringContainsString('Forbidden', $exception->getMessage());
        }
    }

    public function test_chat_fails_over_when_primary_connection_fails(): void
    {
        $this->configureProvider('chat_completions');
        $this->configureFallbackProvider();

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => fn () => throw new ConnectionException('cURL error 7: Failed to connect'),
            'http://localhost:55598/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Fallback sau loi ket noi.']],
            ]),
        ]);

        $service = new AiProviderService();
        $result = $service->chat([['role' => 'user', 'content' => 'Xin chao']]);

        $this->assertSame('Fallback sau loi ket noi.', $result['choices'][0]['message']['content']);
        $this->assertSame(1, $service->describeLastProvider()['index']);
    }

    public function test_fallback_provider_can_use_its_own_timeout(): void
    {
        $this->configureProvider('chat_completions');
        $this->configureFallbackProvider();
        config()->set('chatbot.ai.timeout', 20);
        config()->set('chatbot.ai.fallback.timeout', 60);

        $service = new AiProviderService();
        $reflection = new \ReflectionClass($service);
        $providers = $reflection->getProperty('providers');
        $providers->setAccessible(true);
        $resolved = $providers->getValue($service);

        $this->assertSame(20, $resolved[0]['timeout']);
        $this->assertSame(60, $resolved[1]['timeout']);
    }

    public function test_describe_providers_hides_api_keys(): void
    {
        $this->configureProvider('anthropic_messages');
        $this->configureFallbackProvider('chat_completions');

        $providers = (new AiProviderService())->describeProviders();

        $this->assertCount(2, $providers);
        $this->assertSame('anthropic_messages', $providers[0]['mode']);
        $this->assertSame('chat_completions', $providers[1]['mode']);
        $this->assertTrue($providers[0]['has_key']);
        $this->assertSame(
            ['index', 'mode', 'base_url', 'model', 'has_key'],
            array_keys($providers[0])
        );
    }
}
