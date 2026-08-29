<?php

namespace Tests\Unit\Services;

use App\Services\AiProviderService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Per-call timeout override, added so a triage call inside a synchronous form POST
 * fails fast instead of inheriting the chatbot's generous AI_TIMEOUT.
 *
 * The timeout is not visible on a faked request, so the assertions capture the
 * value `dispatchChat()` hands to `post()` via a subclass seam.
 */
class AiProviderTimeoutOverrideTest extends TestCase
{
    protected function configureProvider(string $mode): void
    {
        config()->set('chatbot.ai.mode', $mode);
        config()->set('chatbot.ai.base_url', 'http://localhost:55597/v1');
        config()->set('chatbot.ai.api_key', 'agt_codex_test');
        config()->set('chatbot.ai.model', 'test-model');
        config()->set('chatbot.ai.timeout', 45);
        config()->set('chatbot.ai.fallback.base_url', null);
        config()->set('chatbot.ai.fallback.api_key', null);
        config()->set('chatbot.ai.fallback.model', null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modeProvider(): array
    {
        return [
            'chat_completions' => ['chat_completions'],
            'responses' => ['responses'],
            'anthropic_messages' => ['anthropic_messages'],
        ];
    }

    #[DataProvider('modeProvider')]
    public function test_a_per_call_timeout_reaches_the_http_layer_in_every_mode(string $mode): void
    {
        $this->configureProvider($mode);

        $service = $this->recordingService();
        $service->chat([['role' => 'user', 'content' => 'ping']], ['timeout' => 8]);

        $this->assertSame([8], $service->recordedTimeouts, "Mode {$mode} must honour the override.");
    }

    public function test_without_an_override_the_configured_timeout_is_used(): void
    {
        $this->configureProvider('chat_completions');

        $service = $this->recordingService();
        $service->chat([['role' => 'user', 'content' => 'ping']]);

        $this->assertSame([45], $service->recordedTimeouts);
    }

    public function test_a_zero_or_negative_override_is_clamped_to_one_second(): void
    {
        $this->configureProvider('chat_completions');

        $service = $this->recordingService();
        $service->chat([['role' => 'user', 'content' => 'ping']], ['timeout' => 0]);
        $service->chat([['role' => 'user', 'content' => 'ping']], ['timeout' => -5]);

        $this->assertSame([1, 1], $service->recordedTimeouts);
    }

    public function test_the_override_does_not_leak_into_the_next_call(): void
    {
        $this->configureProvider('chat_completions');

        $service = $this->recordingService();
        $service->chat([['role' => 'user', 'content' => 'ping']], ['timeout' => 8]);
        $service->chat([['role' => 'user', 'content' => 'ping']]);

        // dispatchChat mutates a copy, so the stored provider config is unchanged.
        $this->assertSame([8, 45], $service->recordedTimeouts);
    }

    public function test_vision_calls_are_unaffected_by_the_chat_override(): void
    {
        $this->configureProvider('chat_completions');

        $service = $this->recordingService();
        $service->analyzeImage('what is this', base64_encode('png-bytes'), 'image/png');

        $this->assertSame([45], $service->recordedTimeouts, 'analyzeImage takes no options.');
    }

    /**
     * A subclass that records the timeout each dispatch resolves, then returns a
     * canned response instead of touching the network.
     */
    protected function recordingService(): AiProviderService
    {
        Http::preventStrayRequests();

        return new class extends AiProviderService {
            /** @var array<int, int> */
            public array $recordedTimeouts = [];

            protected function post(array $provider, string $path, array $payload): Response
            {
                $this->recordedTimeouts[] = (int) $provider['timeout'];

                Http::fake(['*' => Http::response([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]],
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'output_text' => 'ok',
                ])]);

                return Http::acceptJson()->post('http://localhost:55597/v1' . $path, $payload);
            }
        };
    }
}
