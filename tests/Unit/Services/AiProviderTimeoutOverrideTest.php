<?php

namespace Tests\Unit\Services;

use App\Services\AiProviderService;
use Illuminate\Http\Client\ConnectionException;
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
     * A second provider on a different host, so `resolveFallbackProvider()` keeps it.
     */
    protected function configureFallbackProvider(): void
    {
        config()->set('chatbot.ai.fallback.mode', 'chat_completions');
        config()->set('chatbot.ai.fallback.base_url', 'http://localhost:55598/v1');
        config()->set('chatbot.ai.fallback.api_key', 'agt_codex_fallback');
        config()->set('chatbot.ai.fallback.model', 'fallback-model');
        config()->set('chatbot.ai.fallback.timeout', 60);
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
     * The reported incident: two providers, both timing out, one `chat()` call
     * spending 8s per provider instead of the 8s the caller asked for.
     *
     * The budget is scaled down to 2s so the test can burn a whole slice for real
     * instead of faking a clock; 2 is the smallest budget that can tell the two
     * behaviours apart, because the 1s Guzzle floor collapses them at 1.
     */
    public function test_a_per_call_timeout_is_a_budget_for_the_whole_failover_call(): void
    {
        $this->configureProvider('chat_completions');
        $this->configureFallbackProvider();

        $budget = 2;
        $service = $this->recordingService();
        $service->providerBehaviour = 'burn-then-time-out';

        $startedAt = microtime(true);

        try {
            $service->chat([['role' => 'user', 'content' => 'ping']], ['timeout' => $budget]);
            $this->fail('Both providers time out, so chat() must rethrow.');
        } catch (ConnectionException) {
            // The budget, not the error, is what this test is about.
        }

        $elapsed = microtime(true) - $startedAt;

        $this->assertCount(2, $service->recordedTimeouts, 'Both providers must be attempted.');
        $this->assertSame(
            $budget,
            $service->recordedTimeouts[0],
            'The first provider gets the whole budget.'
        );
        $this->assertLessThanOrEqual(
            $budget + 1,
            array_sum($service->recordedTimeouts),
            'Each later provider may only have what the earlier ones left, plus the 1s floor.'
        );
        $this->assertGreaterThanOrEqual(
            1,
            min($service->recordedTimeouts),
            'Guzzle reads 0 as "no timeout", so the floor must never be crossed.'
        );
        $this->assertLessThan(
            $budget + 1 + 1.5,
            $elapsed,
            'Wall clock for one chat() call must stay inside the budget plus the per-provider floor.'
        );
    }

    /**
     * Failover exists for the case where the primary dies fast, and that case must
     * keep working: provider 1 gets essentially the untouched budget.
     */
    public function test_a_fast_primary_failure_leaves_the_budget_to_the_fallback(): void
    {
        $this->configureProvider('chat_completions');
        $this->configureFallbackProvider();

        $service = $this->recordingService();
        $service->providerBehaviour = 'fail-fast-once';

        $service->chat([['role' => 'user', 'content' => 'ping']], ['timeout' => 8]);

        $this->assertSame([8, 8], $service->recordedTimeouts);
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

            /** One of `succeed`, `burn-then-time-out`, `fail-fast-once`. */
            public string $providerBehaviour = 'succeed';

            protected function post(array $provider, string $path, array $payload): Response
            {
                $timeout = (int) $provider['timeout'];
                $this->recordedTimeouts[] = $timeout;

                if ($this->providerBehaviour === 'burn-then-time-out') {
                    // Only a provider that consumes its whole slice can overrun the
                    // budget, so the test has to spend the time for real.
                    usleep($timeout * 1_000_000);

                    throw new ConnectionException(sprintf(
                        'cURL error 28: Operation timed out after %d milliseconds',
                        $timeout * 1000
                    ));
                }

                if ($this->providerBehaviour === 'fail-fast-once') {
                    $this->providerBehaviour = 'succeed';

                    throw new ConnectionException('cURL error 7: Failed to connect');
                }

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
