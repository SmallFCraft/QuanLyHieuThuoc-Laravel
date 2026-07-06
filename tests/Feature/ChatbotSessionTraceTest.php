<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChatbotImage;
use App\Models\ChatMessage;
use App\Models\Product;
use App\Models\User;
use App\Services\ChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class ChatbotSessionTraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_message_response_includes_session_id(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);

        $this->mock(ChatbotService::class, function (MockInterface $mock) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->andReturn([
                    'success' => true,
                    'response' => 'Dạ, em đã ghi nhận câu hỏi.',
                    'products' => [],
                    'categories' => [],
                    'show_disclaimer' => false,
                    'debug' => [
                        'fallback_type' => 'provider_unavailable_no_catalog_match',
                        'provider_error_type' => 'capacity_or_rate_limit',
                    ],
                ]);
        });

        $response = $this->actingAs($user)->postJson('/api/chatbot/message', [
            'message' => 'Tôi cần tư vấn thuốc cảm',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['session_id']);

        $sessionId = $response->json('session_id');

        $this->assertTrue(Str::isUuid($sessionId));
        $this->assertDatabaseHas('chat_messages', [
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Tôi cần tư vấn thuốc cảm',
        ]);

        $assistantMessage = ChatMessage::where('session_id', $sessionId)
            ->where('role', 'assistant')
            ->first();

        $this->assertSame(
            'provider_unavailable_no_catalog_match',
            $assistantMessage->metadata['debug']['fallback_type'] ?? null
        );
    }

    public function test_chatbot_message_passes_declared_name_memory_beyond_recent_context(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $sessionId = (string) Str::uuid();
        $baseTime = now()->subHour();

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'minh la Huy',
            'created_at' => $baseTime,
            'updated_at' => $baseTime,
        ]);

        for ($index = 1; $index <= 12; $index++) {
            ChatMessage::create([
                'MaKH' => $user->MaKH,
                'session_id' => $sessionId,
                'role' => 'user',
                'content' => 'tin nhan ngan ' . $index,
                'created_at' => $baseTime->copy()->addSeconds($index),
                'updated_at' => $baseTime->copy()->addSeconds($index),
            ]);
        }

        $this->mock(ChatbotService::class, function (MockInterface $mock) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->with('toi ten la gi?', \Mockery::type('int'), \Mockery::on(function (array $context): bool {
                    $recentContents = array_column($context['previous_messages'] ?? [], 'content');
                    $memoryContents = array_column($context['name_memory_messages'] ?? [], 'content');

                    return !in_array('minh la Huy', $recentContents, true)
                        && in_array('minh la Huy', $memoryContents, true);
                }))
                ->andReturn([
                    'success' => true,
                    'response' => 'Da, em nho ten trong phien chat nay la Huy.',
                    'products' => [],
                    'categories' => [],
                    'show_disclaimer' => false,
                ]);
        });

        $this
            ->actingAs($user)
            ->withSession(['chat_session_id' => $sessionId])
            ->postJson('/api/chatbot/message', [
                'message' => 'toi ten la gi?',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_chatbot_message_passes_recent_image_products_for_follow_up(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $sessionId = (string) Str::uuid();

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Đã nhận dạng được 1 sản phẩm từ hình ảnh.',
            'products' => [
                [
                    'matched' => true,
                    'product' => [
                        'id' => 123,
                        'name' => 'Vitamin E 400IU',
                    ],
                ],
            ],
        ]);

        $capturedContext = null;

        $this->mock(ChatbotService::class, function (MockInterface $mock) use (&$capturedContext) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->andReturnUsing(function (string $message, int $userId, array $context) use (&$capturedContext): array {
                    $capturedContext = $context;

                    return [
                    'success' => true,
                    'response' => 'Dạ, đây là Vitamin E 400IU.',
                    'products' => [],
                    'categories' => [],
                    'show_disclaimer' => false,
                    ];
                });
        });

        $this
            ->actingAs($user)
            ->withSession(['chat_session_id' => $sessionId])
            ->postJson('/api/chatbot/message', [
                'message' => 'thuốc đó có công dụng gì?',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNotEmpty($capturedContext['recent_image_products'] ?? [], json_encode($capturedContext, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Vitamin E 400IU', $capturedContext['recent_image_products'][0]['product']['name'] ?? null);
        $this->assertStringContainsString('Vitamin E 400IU', $capturedContext['previous_messages'][0]['content'] ?? '');
    }

    public function test_chatbot_history_returns_current_session_id(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $sessionId = (string) Str::uuid();

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Đau đầu nên dùng gì?',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['chat_session_id' => $sessionId])
            ->getJson('/api/chatbot/history');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('session_id', $sessionId)
            ->assertJsonPath('count', 1);
    }

    public function test_chatbot_image_upload_uses_ai_provider_vision_and_matches_products(): void
    {
        config()->set('chatbot.ai.mode', 'chat_completions');
        config()->set('chatbot.ai.base_url', 'http://localhost:55597/v1');
        config()->set('chatbot.ai.api_key', 'agt_codex_test');
        config()->set('chatbot.ai.model', 'vision-model');

        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $category = Category::factory()->create(['name' => 'Vitamin']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C 1000mg',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => "```json\n{\"description\":\"Hop thuoc Vitamin C 1000mg\",\"products\":[{\"name\":\"Vitamin C 1000mg\",\"quantity\":1,\"dosage\":\"1000mg\"}]}\n```",
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/chatbot/upload-image', [
                'type' => 'prescription',
                'message' => 'Thuốc trong ảnh này dùng thế nào?',
                'image' => UploadedFile::fake()->image('vitamin-c.jpg', 600, 400),
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('recognized_products.0.matched', true)
            ->assertJsonPath('recognized_products.0.product.name', 'Vitamin C 1000mg')
            ->assertJsonStructure(['session_id', 'ocr_text', 'processing_time_ms']);

        $this->assertDatabaseHas('chat_messages', [
            'MaKH' => $user->MaKH,
            'session_id' => $response->json('session_id'),
            'role' => 'user',
            'content' => 'Thuốc trong ảnh này dùng thế nào?',
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $content = $payload['messages'][0]['content'] ?? [];

            return $request->url() === 'http://localhost:55597/v1/chat/completions'
                && ($payload['model'] ?? null) === 'vision-model'
                && ($content[1]['type'] ?? null) === 'image_url'
                && str_starts_with($content[1]['image_url']['url'] ?? '', 'data:image/jpeg;base64,');
        });
    }

    public function test_chatbot_image_upload_maps_blackmores_bio_c_to_vitamin_c_product(): void
    {
        config()->set('chatbot.ai.mode', 'chat_completions');
        config()->set('chatbot.ai.base_url', 'http://localhost:55597/v1');
        config()->set('chatbot.ai.api_key', 'agt_codex_test');
        config()->set('chatbot.ai.model', 'vision-model');

        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $category = Category::factory()->create(['name' => 'Vitamin']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Vitamin C 1000mg',
            'ingredients' => 'Acid ascorbic 1000mg',
            'usage' => 'Bổ sung vitamin C, tăng sức đề kháng.',
            'stock_quantity' => 50,
            'expiration_date' => now()->addYear(),
        ]);

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"description":"Lọ viên uống Blackmores Bio C 1000mg, 31 tablets","products":[{"name":"Blackmores Bio C","quantity":31,"dosage":"1000mg","instructions":"Reduces the severity and duration of common cold symptoms; high potency one-a-day dose"}]}',
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/chatbot/upload-image', [
                'type' => 'prescription',
                'image' => UploadedFile::fake()->image('blackmores-bio-c.jpg', 600, 600),
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('recognized_products.0.matched', true)
            ->assertJsonPath('recognized_products.0.parsed_name', 'Blackmores Bio C')
            ->assertJsonPath('recognized_products.0.product.name', 'Vitamin C 1000mg');

        $message = ChatMessage::where('MaKH', $user->MaKH)
            ->where('role', 'assistant')
            ->latest('MaTN')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('Vitamin C 1000mg', $message->content);
    }

    public function test_chatbot_image_upload_returns_clear_error_when_provider_model_does_not_support_vision(): void
    {
        config()->set('chatbot.ai.mode', 'chat_completions');
        config()->set('chatbot.ai.base_url', 'http://localhost:55597/v1');
        config()->set('chatbot.ai.api_key', 'agt_codex_test');
        config()->set('chatbot.ai.model', 'text-only-model');

        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'This model does not support image input',
                ],
            ], 400),
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/chatbot/upload-image', [
                'type' => 'prescription',
                'image' => UploadedFile::fake()->image('label.jpg', 600, 400),
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('debug.vision_supported', false)
            ->assertJsonPath('debug.provider_error_type', 'vision_not_supported');

        $this->assertStringContainsString('chưa hỗ trợ đọc ảnh', $response->json('error'));

        $image = ChatbotImage::where('MaKH', $user->MaKH)->latest('MaAnh')->first();
        $this->assertNotNull($image);
        $this->assertStringContainsString('vision_not_supported', (string) $image->analysis_result);
    }

    public function test_chatbot_receipt_image_upload_uses_responses_vision_payload(): void
    {
        config()->set('chatbot.ai.mode', 'responses');
        config()->set('chatbot.ai.base_url', 'http://localhost:55597/v1');
        config()->set('chatbot.ai.api_key', 'agt_codex_test');
        config()->set('chatbot.ai.model', 'vision-model');

        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $category = Category::factory()->create(['name' => 'Giảm đau']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Paracetamol 500mg',
            'stock_quantity' => 20,
            'expiration_date' => now()->addYear(),
        ]);

        Http::fake([
            'http://localhost:55597/v1/responses' => Http::response([
                'output_text' => '{"description":"Hoa don co Paracetamol 500mg","products":[{"name":"Paracetamol 500mg","quantity":2,"price":10000}]}',
            ]),
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/chatbot/upload-image', [
                'type' => 'receipt',
                'image' => UploadedFile::fake()->image('receipt.png', 600, 400),
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('recognized_products.0.matched', true)
            ->assertJsonPath('recognized_products.0.product.name', 'Paracetamol 500mg');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $content = $payload['input'][0]['content'] ?? [];

            return $request->url() === 'http://localhost:55597/v1/responses'
                && ($content[1]['type'] ?? null) === 'input_image'
                && str_starts_with($content[1]['image_url'] ?? '', 'data:image/jpeg;base64,');
        });
    }

    public function test_chatbot_history_does_not_expose_empty_session_id(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['chat_session_id' => (string) Str::uuid()])
            ->getJson('/api/chatbot/history');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('count', 0)
            ->assertJsonPath('session_id', null);
    }

    public function test_failed_chatbot_message_is_traceable_by_session_id(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);

        $this->mock(ChatbotService::class, function (MockInterface $mock) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->andThrow(new \RuntimeException('AI provider failed'));
        });

        $response = $this->actingAs($user)->postJson('/api/chatbot/message', [
            'message' => 'Chatbot đang lỗi à?',
        ]);

        $response->assertStatus(500)
            ->assertJsonStructure(['session_id']);

        $sessionId = $response->json('session_id');

        $this->assertTrue(Str::isUuid($sessionId));
        $this->assertDatabaseHas('chat_messages', [
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Chatbot đang lỗi à?',
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'system',
            'content' => 'Không thể xử lý tin nhắn do lỗi hệ thống.',
        ]);
    }

    public function test_admin_can_find_and_read_chat_session(): void
    {
        Storage::fake('local');
        config()->set('chatbot.ai.model', 'vision-model');

        $admin = User::factory()->create([
            'role' => 'Administrator',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'name' => 'Nguyễn Văn An',
            'email' => 'nguyenvanan@example.com',
            'role' => 'User',
            'status' => 'active',
        ]);
        $sessionId = (string) Str::uuid();
        $displayId = 'CHAT-' . strtoupper(substr($sessionId, 0, 8));

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Tôi bị ho',
        ]);

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Thuốc trong ảnh này là gì?',
            'metadata' => [
                'image_upload' => [
                    'type' => 'prescription',
                    'status' => 'processed',
                ],
            ],
        ]);

        $imagePath = 'chatbot-uploads/admin-session-test.jpg';
        Storage::disk('local')->put($imagePath, 'fake image bytes');

        $image = ChatbotImage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'filename' => 'admin-session-test.jpg',
            'original_name' => 'blackmores-bio-c.jpg',
            'image_path' => $imagePath,
            'file_path' => $imagePath,
            'file_size' => 15360,
            'mime_type' => 'image/jpeg',
            'image_type' => 'prescription',
            'ocr_text' => 'Lọ viên uống Blackmores Bio C 1000mg, 31 tablets',
            'recognized_products' => [
                [
                    'matched' => true,
                    'parsed_name' => 'Blackmores Bio C',
                    'product' => [
                        'id' => 22,
                        'name' => 'Vitamin C 1000mg',
                        'stock_quantity' => 50,
                    ],
                    'dosage' => '1000mg',
                    'confidence' => 0.82,
                ],
            ],
            'processed_at' => now(),
        ]);

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Anh/chị nên mô tả thêm triệu chứng.',
            'products' => [
                [
                    'matched' => true,
                    'parsed_name' => 'Blackmores Bio C',
                    'product' => [
                        'id' => 22,
                        'name' => 'Vitamin C 1000mg',
                        'stock_quantity' => 50,
                    ],
                    'dosage' => '1000mg',
                    'confidence' => 0.82,
                ],
            ],
            'show_disclaimer' => true,
            'metadata' => [
                'disclaimer' => 'Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.',
                'response_type' => 'image_product_follow_up',
                'debug' => [
                    'ai_provider_used' => true,
                    'ai_provider_mode' => 'chat_completions',
                    'ai_provider_model' => 'vision-model',
                ],
            ],
        ]);

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Thuốc này uống sau ăn được không?',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.chatSessions.index', ['q' => $displayId]))
            ->assertOk()
            ->assertSee($displayId)
            ->assertSee('Nguyễn Văn An');

        $this->actingAs($admin)
            ->get(route('admin.chatSessions.show', $sessionId))
            ->assertOk()
            ->assertSee($displayId)
            ->assertSee('Tôi bị ho')
            ->assertSee('Anh/chị nên mô tả thêm triệu chứng.')
            ->assertSee('Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.')
            ->assertSee(route('admin.chatSessions.images.show', ['sessionId' => $sessionId, 'image' => $image->MaAnh]), false)
            ->assertSee('Thuốc trong ảnh này là gì?')
            ->assertSee('Thuốc này uống sau ăn được không?')
            ->assertSee('Vitamin C 1000mg')
            ->assertSee('Nhận dạng: Blackmores Bio C')
            ->assertSee('Model:')
            ->assertSee('vision-model')
            ->assertDontSee('Không rõ tên');

        $detailHtml = $this->actingAs($admin)
            ->get(route('admin.chatSessions.show', $sessionId))
            ->getContent();

        $this->assertGreaterThanOrEqual(
            4,
            substr_count($detailHtml, route('admin.chatSessions.images.show', ['sessionId' => $sessionId, 'image' => $image->MaAnh]))
        );

        $this->actingAs($admin)
            ->get(route('admin.chatSessions.images.show', ['sessionId' => $sessionId, 'image' => $image->MaAnh]))
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_non_admin_and_guest_cannot_access_chat_sessions_admin_pages(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('admin.chatSessions.index'))
            ->assertForbidden();

        auth()->logout();

        $this->get(route('admin.chatSessions.index'))
            ->assertRedirect(route('login'));
    }
}
