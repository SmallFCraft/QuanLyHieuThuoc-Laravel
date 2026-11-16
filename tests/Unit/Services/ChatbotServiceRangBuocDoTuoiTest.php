<?php

namespace Tests\Unit\Services;

use App\Models\NguoiDung;
use App\Services\AiProviderService;
use App\Services\NhatKyKiemToanService;
use App\Services\ChatbotService;
use App\Services\ThanhToanService;
use App\Services\TonKhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class ChatbotServiceRangBuocDoTuoiTest extends TestCase
{
    use RefreshDatabase;

    protected ChatbotService $chatbotService;
    protected MockObject $aiProviderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiProviderService = $this->createMock(AiProviderService::class);
        $inventoryService = $this->createStub(TonKhoService::class);
        $thanhToanService = $this->createStub(ThanhToanService::class);
        $auditLogService = $this->createStub(NhatKyKiemToanService::class);

        $this->chatbotService = new ChatbotService(
            $this->aiProviderService,
            $inventoryService,
            $thanhToanService,
            $auditLogService
        );
    }

    public function test_chatbot_bo_cau_hoi_do_tuoi_khi_nguoi_dung_co_ngay_sinh(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $nguoiDung = NguoiDung::factory()->create(['ngay_sinh' => now()->subYears(25)->toDateString(),
        ]);

        $reply = $this->chatbotService->processMessage(
            'Tôi bị tiêu chảy và đau bụng do đâu?',
            (int) $nguoiDung->ma_nd,
            []
        );

        $this->assertTrue($reply['success']);
        // Same canned guidance path as the unknown-age cases below, so the
        // omission above proves the branch, not an unrelated reply.
        $this->assertStringContainsString('Nếu anh/chị muốn em gợi ý sản phẩm hỗ trợ', $reply['response'] ?? '');
        $this->assertStringNotContainsString('độ tuổi', $reply['response'] ?? '');
    }

    public function test_chatbot_hoi_do_tuoi_khi_nguoi_dung_khong_co_ngay_sinh(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $nguoiDung = NguoiDung::factory()->create(['ngay_sinh' => null,
        ]);

        $reply = $this->chatbotService->processMessage(
            'Tôi bị tiêu chảy và đau bụng do đâu?',
            (int) $nguoiDung->ma_nd,
            []
        );

        $this->assertTrue($reply['success']);
        $this->assertStringContainsString('độ tuổi', $reply['response'] ?? '');
    }

    public function test_chatbot_hoi_do_tuoi_cho_khach_vang_lai(): void
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

    public function test_ca_bon_loi_hoi_trieu_chung_bo_cau_hoi_do_tuoi_khi_da_biet_tuoi(): void
    {
        $this->aiProviderService->expects($this->never())->method('chat');

        $nguoiDung = NguoiDung::factory()->create(['ngay_sinh' => now()->subYears(30)->toDateString(),
        ]);
        $userId = (int) $nguoiDung->ma_nd;

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
