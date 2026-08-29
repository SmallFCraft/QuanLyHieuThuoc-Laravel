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
                    'response_type' => 'health_consultation',
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
        $this->assertSame(
            'health_consultation',
            $assistantMessage->metadata['response_type'] ?? null
        );
    }

    public function test_chatbot_message_passes_current_session_id_to_service_context(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $capturedSessionId = null;

        $this->mock(ChatbotService::class, function (MockInterface $mock) use ($user, &$capturedSessionId) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->with(
                    'đây là phiên chat mã id là gì?',
                    \Mockery::on(static fn ($userId): bool => (int) $userId === (int) $user->getAuthIdentifier()),
                    \Mockery::on(static function (array $context) use (&$capturedSessionId): bool {
                        $capturedSessionId = (string) ($context['session_id'] ?? '');

                        return $capturedSessionId !== ''
                            && Str::isUuid($capturedSessionId)
                            && ($context['current_session_id'] ?? null) === $capturedSessionId
                            && array_key_exists('previous_messages', $context)
                            && array_key_exists('name_memory_messages', $context)
                            && array_key_exists('recent_image_products', $context);
                    })
                )
                ->andReturn([
                    'success' => true,
                    'response' => 'Dạ, mã phiên chat hiện tại là CHAT-TEST. ID đầy đủ: test.',
                    'products' => [],
                    'categories' => [],
                    'explanations' => [],
                    'show_disclaimer' => false,
                    'disclaimer' => null,
                    'response_type' => 'general',
                ]);
        });

        $response = $this->actingAs($user)->postJson('/api/chatbot/message', [
            'message' => 'đây là phiên chat mã id là gì?',
            'context' => [
                'session_id' => 'client-spoofed-session-id',
                'current_session_id' => 'client-spoofed-current-session-id',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('session_id', $capturedSessionId);

        $this->assertNotSame('client-spoofed-session-id', $capturedSessionId);
        $this->assertTrue(Str::isUuid((string) $capturedSessionId));
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

    public function test_chatbot_message_passes_history_in_chronological_order(): void
    {
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $sessionId = (string) Str::uuid();
        $baseTime = now()->subMinutes(10);

        $turns = [
            ['role' => 'user', 'content' => 'có nên xài crack hay không'],
            ['role' => 'assistant', 'content' => 'Dạ, em không thể tư vấn về việc sử dụng ma túy.'],
            ['role' => 'user', 'content' => 'có thuốc gì hỗ trơ tiêu hóa nhanh không?'],
            ['role' => 'assistant', 'content' => 'Dạ, danh mục Thuốc tiêu hóa có các sản phẩm sau.'],
        ];

        foreach ($turns as $index => $turn) {
            ChatMessage::create([
                'MaKH' => $user->MaKH,
                'session_id' => $sessionId,
                'role' => $turn['role'],
                'content' => $turn['content'],
                'created_at' => $baseTime->copy()->addSeconds($index),
                'updated_at' => $baseTime->copy()->addSeconds($index),
            ]);
        }

        $capturedContext = null;

        $this->mock(ChatbotService::class, function (MockInterface $mock) use (&$capturedContext) {
            $mock->shouldReceive('processMessage')
                ->once()
                ->andReturnUsing(function (string $message, int $userId, array $context) use (&$capturedContext): array {
                    $capturedContext = $context;

                    return [
                        'success' => true,
                        'response' => 'Dạ, em đã ghi nhận.',
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
                'message' => 'có ổn không?',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        // Sequential keys matter beyond ordering: a key-preserving reverse() encodes
        // as a JSON object, and the MySQL json column returns object keys sorted, so
        // the whole conversation reached the model newest-first.
        $this->assertTrue(array_is_list($capturedContext['previous_messages'] ?? []));
        $this->assertTrue(array_is_list($capturedContext['name_memory_messages'] ?? []));

        $this->assertSame(
            array_column($turns, 'content'),
            array_column($capturedContext['previous_messages'] ?? [], 'content')
        );

        $this->assertSame(
            ['có nên xài crack hay không', 'có thuốc gì hỗ trơ tiêu hóa nhanh không?'],
            array_column($capturedContext['name_memory_messages'] ?? [], 'content')
        );
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

    public function test_chatbot_image_upload_reports_unmatched_product_instead_of_guessing(): void
    {
        config()->set('chatbot.ai.mode', 'chat_completions');
        config()->set('chatbot.ai.base_url', 'http://localhost:55597/v1');
        config()->set('chatbot.ai.api_key', 'agt_codex_test');
        config()->set('chatbot.ai.model', 'vision-model');

        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $category = Category::factory()->create(['name' => 'Vitamin và khoáng chất']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Zinc 50mg',
            'ingredients' => 'Zinc sulfate 50mg',
            'usage' => 'Bổ sung kẽm, tăng cường miễn dịch. Uống 1 viên/ngày',
            'stock_quantity' => 320,
            'expiration_date' => now()->addYear(),
        ]);

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"description":"Hộp thuốc Vitamin A 5000 IU của Mekophar, chứa 10 viên nang cứng","products":[{"name":"Vitamin A 5000 IU","dosage":"5000 IU","quantity":1,"active_ingredients":["Retinol"],"category":"Vitamin và khoáng chất","uses":["Bổ sung vitamin A"],"health_goals":["immunity"],"visible_text":"Vitamin A 5000 IU Mekophar hộp 10 viên nang cứng","instructions":"Uống 1 viên mỗi ngày"}]}',
                        ],
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/chatbot/upload-image', [
                'type' => 'prescription',
                'image' => UploadedFile::fake()->image('vitamin-a.jpg', 600, 600),
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('recognized_products.0.matched', false)
            ->assertJsonPath('recognized_products.0.parsed_name', 'Vitamin A 5000 IU')
            ->assertJsonPath('recognized_products.0.product', null);

        $message = ChatMessage::where('MaKH', $user->MaKH)
            ->where('role', 'assistant')
            ->latest('MaTN')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('Vitamin A 5000 IU', $message->content);
        $this->assertStringNotContainsString('Zinc 50mg', $message->content);
        $this->assertStringContainsString('chưa thấy sản phẩm nào khớp trong kho', $message->content);
    }

    public function test_chatbot_image_upload_answers_the_question_typed_with_the_image(): void
    {
        config()->set('chatbot.ai.mode', 'chat_completions');
        config()->set('chatbot.ai.base_url', 'http://localhost:55597/v1');
        config()->set('chatbot.ai.api_key', 'agt_codex_test');
        config()->set('chatbot.ai.model', 'vision-model');
        config()->set('chatbot.ai.image_answer', true);

        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $category = Category::factory()->create(['name' => 'Vitamin và khoáng chất']);
        Product::factory()->create([
            'MaDM' => $category->MaDM,
            'name' => 'Zinc 50mg',
            'ingredients' => 'Zinc sulfate 50mg',
            'usage' => 'Bổ sung kẽm',
            'stock_quantity' => 320,
            'expiration_date' => now()->addYear(),
        ]);

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"description":"Hộp Vitamin A 5000 IU của Mekophar, 10 viên nang cứng","products":[{"name":"Vitamin A 5000 IU","dosage":"5000 IU","quantity":1,"active_ingredients":["Retinol"],"uses":["Bổ sung vitamin A"],"visible_text":"Vitamin A 5000 IU Mekophar"}]}',
                        ],
                    ]],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Dạ, ảnh anh/chị gửi là Vitamin A 5000 IU của Mekophar, thường dùng để bổ sung vitamin A. Hiện sản phẩm này chưa có trong kho của nhà thuốc ạ.',
                        ],
                    ]],
                ]),
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/chatbot/upload-image', [
                'type' => 'prescription',
                'message' => 'đây là thuốc gì?',
                'image' => UploadedFile::fake()->image('vitamin-a.jpg', 600, 600),
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('show_disclaimer', true);

        $this->assertStringContainsString('Vitamin A 5000 IU của Mekophar', $response->json('response'));

        $message = ChatMessage::where('MaKH', $user->MaKH)
            ->where('role', 'assistant')
            ->latest('MaTN')
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('bổ sung vitamin A', $message->content);
        $this->assertTrue((bool) $message->show_disclaimer);
        $this->assertTrue((bool) ($message->metadata['debug']['image_answer_used_ai'] ?? false));
    }

    public function test_chatbot_image_upload_falls_back_to_summary_when_answer_step_fails(): void
    {
        config()->set('chatbot.ai.mode', 'chat_completions');
        config()->set('chatbot.ai.base_url', 'http://localhost:55597/v1');
        config()->set('chatbot.ai.api_key', 'agt_codex_test');
        config()->set('chatbot.ai.model', 'vision-model');
        config()->set('chatbot.ai.image_answer', true);

        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);

        Http::fake([
            'http://localhost:55597/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => '{"description":"Hộp Vitamin A 5000 IU","products":[{"name":"Vitamin A 5000 IU","dosage":"5000 IU","quantity":1}]}',
                        ],
                    ]],
                ])
                ->push(['error' => ['message' => 'upstream error']], 502),
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/chatbot/upload-image', [
                'type' => 'prescription',
                'message' => 'đây là thuốc gì?',
                'image' => UploadedFile::fake()->image('vitamin-a.jpg', 600, 600),
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('recognized_products.0.matched', false);

        $this->assertStringContainsString('Vitamin A 5000 IU', $response->json('response'));
        $this->assertStringContainsString('chưa thấy sản phẩm nào khớp trong kho', $response->json('response'));
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

    public function test_admin_chat_session_uses_shared_markdown_renderer_for_assistant_transcript(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $sessionId = (string) Str::uuid();

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'user',
            'content' => 'format test',
        ]);

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => "### Kết quả\n\n1. **Vitamin C**\n2. [Mở sản phẩm](/products/1)\n\nCông thức: \\(1 + \\frac{1}{999} \\approx 1.001\\ldots\\)",
            'metadata' => [
                'response_type' => 'general',
            ],
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.chatSessions.show', $sessionId))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('window.ChatbotMarkdownRenderer', $html);
        $this->assertStringContainsString('js-admin-chat-markdown', $html);
        $this->assertStringContainsString('js-admin-chat-markdown-source', $html);
        $this->assertStringContainsString('<strong>Vitamin C</strong>', $html);
        $this->assertStringContainsString('1 + 1/999 ~ 1.001...', $html);
    }

    public function test_admin_chat_session_shows_disclaimer_once_like_chatbot_widget(): void
    {
        $admin = User::factory()->create([
            'role' => 'Administrator',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
        ]);
        $sessionId = (string) Str::uuid();
        $disclaimer = 'Nội dung do AI tạo ra có thể không chính xác. Vui lòng tham khảo ý kiến dược sĩ hoặc bác sĩ.';

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Tin tư vấn cũ.',
            'metadata' => ['disclaimer' => $disclaimer],
            'show_disclaimer' => true,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Tin tư vấn mới nhất.',
            'metadata' => ['disclaimer' => $disclaimer],
            'show_disclaimer' => true,
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        ChatMessage::create([
            'MaKH' => $user->MaKH,
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Tin không cần disclaimer.',
            'show_disclaimer' => false,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $html = $this->actingAs($admin)
            ->get(route('admin.chatSessions.show', $sessionId))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, $disclaimer));
        $this->assertSame(1, substr_count($html, 'admin-chat-inline-disclaimer'));
        $this->assertStringNotContainsString('alert alert-warning', $html);

        $latestContentPosition = strpos($html, 'Tin không cần disclaimer.');
        $disclaimerPosition = strpos($html, $disclaimer);

        $this->assertNotFalse($latestContentPosition);
        $this->assertNotFalse($disclaimerPosition);
        $this->assertGreaterThan($latestContentPosition, $disclaimerPosition);
        $this->assertStringContainsString('Tin tư vấn cũ.', $html);
        $this->assertStringContainsString('Tin tư vấn mới nhất.', $html);
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
