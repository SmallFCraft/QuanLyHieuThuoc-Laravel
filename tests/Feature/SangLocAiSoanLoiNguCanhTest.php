<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SangLocAiSoanLoiNguCanhTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $pharmacist;

    protected NguoiDung $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('chatbot.ai.triage.pharmacist_draft', true);

        DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->pharmacist = NguoiDung::factory()->create(['vai_tro' => 'Pharmacist',
            'trang_thai' => 'active',
        ]);

        $this->customer = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(41)->toDateString(),
        ]);
    }

    protected function fakeProviderReply(string $content = 'Dạ, em xin chào anh/chị.'): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ])]);
    }

    public function test_loi_nhac_chua_tuoi_va_phien_truoc_khi_da_dong_y_ai(): void
    {
        $this->fakeProviderReply();

        HoSoSucKhoe::create(['ma_nd' => $this->customer->ma_nd,
            'allergies' => ['aspirin'],
            'chronic_conditions' => ['cao huyết áp'],
            'current_medications' => ['amlodipine'],
            'consented_at' => now(),
            'ai_consent_at' => now(),
        ]);

        PhienSangLoc::create(['ma_nd' => $this->customer->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'reviewed',
            'outcome' => 'self_care',
            'collected_data' => ['raw_symptom_text' => 'dau hong va ho nhe tuan truoc'],
            'created_at' => now()->subDays(3),
        ]);

        $currentSession = PhienSangLoc::create(['ma_nd' => $this->customer->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'pending_pharmacist',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Cần nghỉ ngơi.',
            'safety_net' => 'Đi khám nếu sốt cao.',
            'matched_symptoms' => ['fever'],
            'triggered_red_flags' => [],
            'pharmacist_id' => $this->pharmacist->ma_nd,
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $currentSession->ma_sl));

        Http::assertSent(function ($request) {
            $userContent = collect($request->data()['messages'] ?? [])
                ->firstWhere('role', 'user')['content'] ?? '';

            $this->assertStringContainsString('41 tuổi', $userContent);
            $this->assertStringContainsString('aspirin', $userContent);
            $this->assertStringContainsString('dau hong va ho nhe tuan truoc', $userContent);
            $this->assertStringContainsString('Kết luận của hệ thống: pharmacist_review', $userContent);

            return true;
        });
    }

    /**
     * AI consent gates the health profile only. Age and the customer's own past
     * triage descriptions are session-derived, not profile data, and the context
     * service already sends them without consent (see SangLocAiNguCanhServiceTest).
     * What must never appear is the encrypted, purpose-limited profile.
     */
    public function test_loi_nhac_loai_ho_so_khi_chua_dong_y_ai(): void
    {
        $this->fakeProviderReply();

        HoSoSucKhoe::create(['ma_nd' => $this->customer->ma_nd,
            'allergies' => ['amoxicillin'],
            'chronic_conditions' => ['tiểu đường'],
            'current_medications' => ['metformin'],
            'consented_at' => now(),
            'ai_consent_at' => null,
        ]);

        PhienSangLoc::create(['ma_nd' => $this->customer->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'reviewed',
            'outcome' => 'self_care',
            'collected_data' => ['raw_symptom_text' => 'ngua co tay sau khi an hai san'],
            'created_at' => now()->subDays(2),
        ]);

        $currentSession = PhienSangLoc::create(['ma_nd' => $this->customer->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'pending_pharmacist',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Uống nhiều nước.',
            'safety_net' => 'Khám nếu đau ngực.',
            'matched_symptoms' => ['cough'],
            'triggered_red_flags' => [],
            'pharmacist_id' => $this->pharmacist->ma_nd,
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $currentSession->ma_sl));

        Http::assertSent(function ($request) {
            $userContent = collect($request->data()['messages'] ?? [])
                ->firstWhere('role', 'user')['content'] ?? '';

            $this->assertStringNotContainsString('amoxicillin', $userContent);
            $this->assertStringNotContainsString('tiểu đường', $userContent);
            $this->assertStringNotContainsString('metformin', $userContent);
            $this->assertStringNotContainsString('Tiền sử y tế đã xác nhận', $userContent);
            // Session-derived context still flows: proves the block is wired at all,
            // so the assertions above are not passing just because nothing was added.
            $this->assertStringContainsString('41 tuổi', $userContent);
            $this->assertStringContainsString('ngua co tay sau khi an hai san', $userContent);
            $this->assertStringContainsString('Kết luận của hệ thống: pharmacist_review', $userContent);

            return true;
        });
    }

    public function test_ket_qua_he_thong_luon_co_mat_de_chan_hoi_quy(): void
    {
        $this->fakeProviderReply();

        $phien = PhienSangLoc::create(['ma_nd' => $this->customer->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'pending_pharmacist',
            'outcome' => 'pharmacist_review',
            'advice_given' => 'Theo dõi nhiệt độ.',
            'safety_net' => 'Đi cấp cứu nếu co giật.',
            'matched_symptoms' => ['fever'],
            'triggered_red_flags' => [],
            'pharmacist_id' => $this->pharmacist->ma_nd,
        ]);

        $this->actingAs($this->pharmacist)
            ->post(route('duoc-si.sang-loc.draft', $phien->ma_sl));

        Http::assertSent(function ($request) {
            $userContent = collect($request->data()['messages'] ?? [])
                ->firstWhere('role', 'user')['content'] ?? '';

            $this->assertStringContainsString('Kết luận của hệ thống: pharmacist_review', $userContent);
            $this->assertStringContainsString('Đi cấp cứu nếu co giật.', $userContent);

            return true;
        });
    }
}
