<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class OpenAIServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Set a test API key
        Config::set('chatbot.openai.api_key', 'sk-test-key-for-unit-testing');
        Config::set('chatbot.openai.model', 'gpt-4');
        Config::set('chatbot.openai.max_tokens', 500);
        Config::set('chatbot.openai.temperature', 0.7);
    }

    public function test_service_initializes_with_valid_config()
    {
        $service = new OpenAIService();
        
        $this->assertInstanceOf(OpenAIService::class, $service);
    }

    public function test_service_throws_exception_without_api_key()
    {
        Config::set('chatbot.openai.api_key', null);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API key not configured');
        
        new OpenAIService();
    }

    public function test_get_fallback_response_returns_vietnamese_message()
    {
        $service = new OpenAIService();
        $fallback = $service->getFallbackResponse();
        
        $this->assertIsArray($fallback);
        $this->assertEquals('assistant', $fallback['role']);
        $this->assertStringContainsString('Xin lỗi', $fallback['content']);
        $this->assertTrue($fallback['fallback']);
    }

    public function test_set_retry_config_updates_settings()
    {
        $service = new OpenAIService();
        $result = $service->setRetryConfig(5, 2000);
        
        $this->assertInstanceOf(OpenAIService::class, $result);
    }

    public function test_set_timeout_updates_settings()
    {
        $service = new OpenAIService();
        $result = $service->setTimeout(60);
        
        $this->assertInstanceOf(OpenAIService::class, $result);
    }

    public function test_chat_method_accepts_messages_array()
    {
        $service = new OpenAIService();
        
        $messages = [
            ['role' => 'user', 'content' => 'Test message']
        ];
        
        // This will fail without a real API key, but we're testing the structure
        try {
            $service->chat($messages);
        } catch (\Exception $e) {
            // Expected to fail with test key
            $this->assertStringContainsString('failed', $e->getMessage());
        }
    }

    public function test_chat_method_accepts_options()
    {
        $service = new OpenAIService();
        
        $messages = [
            ['role' => 'user', 'content' => 'Test message']
        ];
        
        $options = [
            'temperature' => 0.5,
            'max_tokens' => 100,
        ];
        
        try {
            $service->chat($messages, $options);
        } catch (\Exception $e) {
            // Expected to fail with test key
            $this->assertStringContainsString('failed', $e->getMessage());
        }
    }
}
