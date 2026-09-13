<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\AiProviderService;
use App\Services\AuditLogService;
use App\Services\ChatbotService;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ChatbotServiceAgePromptTest extends TestCase
{
    use RefreshDatabase;

    protected ChatbotService $chatbotService;
    protected MockObject $aiProviderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiProviderService = $this->createMock(AiProviderService::class);
        $inventoryService = $this->createStub(InventoryService::class);
        $checkoutService = $this->createStub(CheckoutService::class);
        $auditLogService = $this->createStub(AuditLogService::class);

        $this->chatbotService = new ChatbotService(
            $this->aiProviderService,
            $inventoryService,
            $checkoutService,
            $auditLogService
        );
    }

    public function test_chatbot_omits_age_inquiry_when_user_has_birth_date(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $user = User::factory()->create([
            'birth_date' => now()->subYears(25)->toDateString(),
        ]);

        $reply = $this->chatbotService->processMessage(
            'Tôi bị tiêu chảy và đau bụng do đâu?',
            (int) $user->MaKH,
            []
        );

        $this->assertTrue($reply['success']);
        // Same canned guidance path as the unknown-age cases below, so the
        // omission above proves the branch, not an unrelated reply.
        $this->assertStringContainsString('Nếu anh/chị muốn em gợi ý sản phẩm hỗ trợ', $reply['response'] ?? '');
        $this->assertStringNotContainsString('độ tuổi', $reply['response'] ?? '');
    }

    public function test_chatbot_asks_age_when_user_has_no_birth_date(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $user = User::factory()->create([
            'birth_date' => null,
        ]);

        $reply = $this->chatbotService->processMessage(
            'Tôi bị tiêu chảy và đau bụng do đâu?',
            (int) $user->MaKH,
            []
        );

        $this->assertTrue($reply['success']);
        $this->assertStringContainsString('độ tuổi', $reply['response'] ?? '');
    }

    public function test_chatbot_asks_age_for_guest_without_birth_date(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $reply = $this->chatbotService->processMessage(
            'Tôi bị tiêu chảy và đau bụng do đâu?',
            0,
            []
        );

        $this->assertTrue($reply['success']);
        $this->assertStringContainsString('độ tuổi', $reply['response'] ?? '');
    }

    public function test_all_four_symptom_guidance_variations_omit_age_when_age_is_known(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $user = User::factory()->create([
            'birth_date' => now()->subYears(30)->toDateString(),
        ]);
        $userId = (int) $user->MaKH;

        // 1. Cause question with diarrhea / abdominal pain
        $res1 = $this->chatbotService->processMessage('Tôi bị tiêu chảy và đau bụng do đâu?', $userId, []);
        $this->assertStringContainsString('hãy cho em biết đã bị bao lâu', $res1['response'] ?? '');
        $this->assertStringNotContainsString('độ tuổi', $res1['response'] ?? '');

        // 2. Diarrhea bowel movement question
        $res2 = $this->chatbotService->processMessage('tiêu chảy có nên đi ngoài không?', $userId, []);
        $this->assertStringContainsString('hãy cho em biết thêm triệu chứng đi kèm', $res2['response'] ?? '');
        $this->assertStringNotContainsString('độ tuổi', $res2['response'] ?? '');

        // 3. Prolonged abdominal pain
        $res3 = $this->chatbotService->processMessage('tôi bị đau bụng kéo dài cả tuần nay rồi', $userId, []);
        $this->assertStringContainsString('hãy nói thêm vị trí đau bụng', $res3['response'] ?? '');
        $this->assertStringNotContainsString('độ tuổi', $res3['response'] ?? '');

        // 4. Diarrhea symptom
        $res4 = $this->chatbotService->processMessage('Tôi bị tiêu chảy', $userId, []);
        $this->assertStringContainsString('hãy cho em biết thêm triệu chứng đi kèm', $res4['response'] ?? '');
        $this->assertStringNotContainsString('độ tuổi', $res4['response'] ?? '');
    }
}
