<?php

namespace Tests\Feature;

use App\Jobs\XuLyTinNhanChatbot;
use App\Models\AiJob;
use App\Models\TinNhanChat;
use App\Models\NguoiDung;
use App\Services\ChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The chatbot turn as background work.
 *
 * The invariant under test: no AI call happens inside the web request when a worker
 * is available, the customer still gets an answer when one is not, and a job is only
 * ever readable by the customer who created it.
 */
class HangDoiCongViecAiTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function reply(array $overrides = []): array
    {
        return array_merge([
            'success' => true,
            'response' => 'Dạ, em đã ghi nhận câu hỏi.',
            'products' => [],
            'categories' => [],
            'show_disclaimer' => false,
        ], $overrides);
    }

    protected function fakeService(array $reply): void
    {
        $this->mock(ChatbotService::class, function (MockInterface $mock) use ($reply) {
            $mock->shouldReceive('processMessage')->andReturn($reply);
        });
    }

    public function test_trinh_xu_ly_nhan_luot_va_yeu_cau_tra_ve_ngay(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $response = $this->actingAs($this->nguoiDung)->postJson('/api/chatbot/message', [
            'message' => 'Tôi cần tư vấn thuốc cảm',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonStructure(['job_id', 'poll_url', 'session_id']);

        // The whole point: the provider is not called in the web request.
        Http::assertNothingSent();
        Queue::assertPushed(XuLyTinNhanChatbot::class, 1);

        $job = AiJob::firstOrFail();
        $this->assertSame('queued', $job->status);
        $this->assertSame(AiJob::KIND_CHAT, $job->kind);
        $this->assertSame((int) $this->nguoiDung->ma_nd, (int) $job->ma_nd);
        $this->assertSame('Tôi cần tư vấn thuốc cảm', $job->payload['message']);
    }

    public function test_chua_ghi_dong_hoi_thoai_truoc_khi_cong_viec_chay(): void
    {
        Queue::fake();

        $this->actingAs($this->nguoiDung)->postJson('/api/chatbot/message', [
            'message' => 'Tôi bị đau đầu',
        ])->assertStatus(202);

        // A message the model has not answered yet must not appear in the thread,
        // otherwise a reload shows a question with no reply.
        $this->assertSame(0, TinNhanChat::count());
    }

    public function test_chay_cong_viec_luu_luot_hoi_va_ket_qua(): void
    {
        Queue::fake();
        $this->fakeService($this->reply(['response' => 'Dạ, anh/chị uống nhiều nước nhé.']));

        $this->actingAs($this->nguoiDung)->postJson('/api/chatbot/message', [
            'message' => 'Tôi bị đau đầu',
        ])->assertStatus(202);

        $job = AiJob::firstOrFail();
        (new XuLyTinNhanChatbot($job->uuid))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $job->refresh();

        $this->assertSame('done', $job->status);
        $this->assertSame('Dạ, anh/chị uống nhiều nước nhé.', $job->result['response']);
        $this->assertNotNull($job->finished_at);

        $this->assertDatabaseHas('tin_nhan_chats', [
            'ma_nd' => $this->nguoiDung->ma_nd,
            'role' => 'user',
            'content' => 'Tôi bị đau đầu',
        ]);
        $this->assertDatabaseHas('tin_nhan_chats', [
            'ma_nd' => $this->nguoiDung->ma_nd,
            'role' => 'assistant',
            'content' => 'Dạ, anh/chị uống nhiều nước nhé.',
        ]);
    }

    public function test_kiem_tra_cong_viec_hoan_thanh_tra_cau_tra_loi(): void
    {
        Queue::fake();
        $this->fakeService($this->reply(['response' => 'Dạ, em trả lời đây ạ.']));

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        (new XuLyTinNhanChatbot($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $this->actingAs($this->nguoiDung)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('success', true)
            ->assertJsonPath('response', 'Dạ, em trả lời đây ạ.');
    }

    public function test_kiem_tra_cong_viec_chua_xong_bao_tien_do_ma_khong_co_ket_qua(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $this->actingAs($this->nguoiDung)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('stranded', false)
            ->assertJsonMissingPath('response');
    }

    public function test_khach_khac_khong_kiem_tra_duoc_cong_viec_cua_nguoi_khac(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        // The uuid is a public id, not a capability token.
        $this->actingAs($other)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertNotFound();
    }

    public function test_khach_vo_danh_khong_kiem_tra_duoc_cong_viec(): void
    {
        $job = AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'queued',
        ]);

        $this->getJson("/api/ai-jobs/{$job->uuid}")
            ->assertStatus(401)
            ->assertJsonPath('require_login', true);
    }

    public function test_cong_viec_nem_loi_duoc_ghi_that_bai_va_van_truy_vet_duoc(): void
    {
        Queue::fake();

        $this->mock(ChatbotService::class, function (MockInterface $mock) {
            $mock->shouldReceive('processMessage')->andThrow(new \RuntimeException('AI provider failed'));
        });

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Chatbot đang lỗi à?'])
            ->json('job_id');

        (new XuLyTinNhanChatbot($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $job = AiJob::where('uuid', $jobId)->firstOrFail();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('AI provider failed', $job->error);

        // Support must still be able to see what was asked.
        $this->assertDatabaseHas('tin_nhan_chats', [
            'ma_nd' => $this->nguoiDung->ma_nd,
            'role' => 'user',
            'content' => 'Chatbot đang lỗi à?',
        ]);
        $this->assertDatabaseHas('tin_nhan_chats', [
            'ma_nd' => $this->nguoiDung->ma_nd,
            'role' => 'system',
            'content' => 'Không thể xử lý tin nhắn do lỗi hệ thống.',
        ]);

        $this->actingAs($this->nguoiDung)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('success', false);
    }

    public function test_cong_viec_khong_ai_nhan_duoc_bao_la_bo_lac(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $job = AiJob::where('uuid', $jobId)->firstOrFail();
        $job->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])->save();

        $this->actingAs($this->nguoiDung)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('stranded', true);
    }

    public function test_cong_viec_dang_chay_khong_bao_bo_lac_du_model_cham(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        // A slow provider must not be retried: that would send the same message twice.
        AiJob::where('uuid', $jobId)->firstOrFail()->forceFill([
            'status' => 'running',
            'created_at' => now()->subMinutes(2),
        ])->save();

        $this->actingAs($this->nguoiDung)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('stranded', false);
    }

    /**
     * A job left behind by a worker that never started. Created directly because
     * Queue::fake() would also intercept the synchronous retry under test.
     */
    protected function strandedJob(): AiJob
    {
        $job = AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'queued',
            'payload' => ['message' => 'Tôi bị ho', 'context' => [], 'session_id' => 'test-session'],
        ]);

        $job->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])->save();

        return $job->refresh();
    }

    public function test_thu_lai_dong_bo_tra_loi_trong_yeu_cau_va_chot_cong_viec_bo_lac(): void
    {
        $this->fakeService($this->reply(['response' => 'Dạ, em trả lời trực tiếp ạ.']));
        $stranded = $this->strandedJob();

        $response = $this->actingAs($this->nguoiDung)->postJson('/api/chatbot/message', [
            'message' => 'Tôi bị ho',
            'sync' => true,
            'abandon_job_id' => $stranded->uuid,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('response', 'Dạ, em trả lời trực tiếp ạ.');

        $stranded->refresh();
        $this->assertSame('failed', $stranded->status);
        $this->assertStringContainsString('no worker', $stranded->error);
    }

    public function test_thu_lai_dong_bo_khong_chot_duoc_cong_viec_cua_khach_khac(): void
    {
        $this->fakeService($this->reply());
        $victim = $this->strandedJob();

        $attacker = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        $this->actingAs($attacker)->postJson('/api/chatbot/message', [
            'message' => 'Xin chào',
            'sync' => true,
            'abandon_job_id' => $victim->uuid,
        ])->assertOk();

        $this->assertSame('queued', $victim->refresh()->status);
    }

    public function test_tin_nhan_bi_tu_choi_khong_ghi_gi_va_khong_can_trinh_xu_ly(): void
    {
        Queue::fake();
        $this->fakeService([
            'success' => false,
            'response' => 'Xin chào! Bạn cần tìm thuốc hay tư vấn triệu chứng gì ạ?',
            'validation_error' => true,
        ]);

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'aaaaaa'])
            ->json('job_id');

        (new XuLyTinNhanChatbot($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $this->assertSame('done', AiJob::where('uuid', $jobId)->firstOrFail()->status);
        $this->assertSame(0, TinNhanChat::count());
    }

    public function test_dung_luot_chot_cong_viec_va_khong_dua_cau_tra_loi_vao_hoi_thoai(): void
    {
        Queue::fake();
        $this->fakeService($this->reply(['response' => 'Câu trả lời không ai còn chờ.']));

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $this->actingAs($this->nguoiDung)
            ->deleteJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        // The worker was already mid-call when stop was pressed. Its answer must not
        // become a reply to a question the customer withdrew.
        (new XuLyTinNhanChatbot($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $this->assertSame('cancelled', AiJob::where('uuid', $jobId)->firstOrFail()->status);
        $this->assertSame(0, TinNhanChat::count());
    }

    public function test_kiem_tra_luot_da_dung_bao_da_huy_thay_vi_loi(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $this->actingAs($this->nguoiDung)->deleteJson("/api/ai-jobs/{$jobId}")->assertOk();

        // A poll still in flight must not paint a failure the customer never caused.
        $this->actingAs($this->nguoiDung)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('cancelled', true)
            ->assertJsonMissingPath('error');
    }

    public function test_luot_da_xong_khong_bi_viet_lai_boi_lenh_dung_muon(): void
    {
        Queue::fake();
        $this->fakeService($this->reply(['response' => 'Dạ, em đã trả lời rồi ạ.']));

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        (new XuLyTinNhanChatbot($jobId))->handle(app(ChatbotService::class), app(\App\Services\AiJobService::class));

        $this->actingAs($this->nguoiDung)->deleteJson("/api/ai-jobs/{$jobId}")->assertOk();

        // The answer is already on screen and in the transcript; relabelling it
        // cancelled would make the reload disagree with what the customer saw.
        $this->assertSame('done', AiJob::where('uuid', $jobId)->firstOrFail()->status);
    }

    public function test_khach_khac_khong_dung_duoc_luot_cua_nguoi_khac(): void
    {
        Queue::fake();

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        $this->actingAs($other)
            ->deleteJson("/api/ai-jobs/{$jobId}")
            ->assertNotFound();

        $this->assertSame('queued', AiJob::where('uuid', $jobId)->firstOrFail()->status);
    }

    public function test_khach_vo_danh_khong_dung_duoc_luot(): void
    {
        $job = AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'queued',
        ]);

        $this->deleteJson("/api/ai-jobs/{$job->uuid}")
            ->assertStatus(401)
            ->assertJsonPath('require_login', true);
    }

    public function test_payload_khong_chua_du_lieu_ho_so_suc_khoe(): void
    {
        Queue::fake();

        \App\Models\HoSoSucKhoe::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'allergies' => ['penicillin'],
            'chronic_conditions' => ['loét dạ dày'],
            'consented_at' => now(),
        ]);

        $this->actingAs($this->nguoiDung)->postJson('/api/chatbot/message', [
            'message' => 'Tôi bị đau đầu',
        ])->assertStatus(202);

        // ai_jobs.payload is plain json, so profile data must never reach it.
        $encoded = json_encode(AiJob::firstOrFail()->payload, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('penicillin', $encoded);
        $this->assertStringNotContainsString('loét dạ dày', $encoded);
    }

    public function test_cong_viec_chi_chay_mot_lan_du_duoc_giao_hai_lan(): void
    {
        Queue::fake();
        $this->fakeService($this->reply());

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        $jobs = app(\App\Services\AiJobService::class);
        (new XuLyTinNhanChatbot($jobId))->handle(app(ChatbotService::class), $jobs);
        (new XuLyTinNhanChatbot($jobId))->handle(app(ChatbotService::class), $jobs);

        // A duplicate delivery must not post a second assistant reply.
        $this->assertSame(1, TinNhanChat::where('role', 'assistant')->count());
    }

    public function test_don_dep_chot_cong_viec_co_trinh_xu_ly_chet_va_xoa_payload_cu(): void
    {
        $stuck = AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'running',
            'payload' => ['message' => 'tôi bị đau đầu'],
        ]);
        $stuck->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $old = AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'done',
            'payload' => ['message' => 'tôi bị sốt'],
        ]);
        $old->forceFill(['created_at' => now()->subHours(30)])->save();

        $recent = AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'done',
        ]);

        $this->artisan('ai-jobs:cleanup')->assertSuccessful();

        $this->assertSame('failed', $stuck->refresh()->status);
        // The payload holds what the customer typed, so it must not sit in the DB.
        $this->assertNull(AiJob::find($old->ma_aj));
        $this->assertNotNull(AiJob::find($recent->ma_aj));
    }

    public function test_don_dep_xoa_dong_hang_doi_cua_cong_viec_da_chot(): void
    {
        config()->set('queue.default', 'database');

        $job = AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_BRIEFING,
            'status' => 'queued',
            'payload' => ['ma_sl' => 1],
        ]);
        $job->forceFill(['created_at' => now()->subMinutes(30)])->save();

        \Illuminate\Support\Facades\DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => \App\Jobs\XuLyTomTatSangLoc::class,
                'data' => ['command' => 'uuid";s:36:"' . $job->uuid . '";'],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->artisan('ai-jobs:cleanup')->assertSuccessful();

        $this->assertSame('failed', $job->refresh()->status);
        // Left behind, a worker starting later finds the row finished, returns early,
        // and the patient's briefing disappears with nothing recorded.
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('jobs')->count());
    }

    public function test_cong_viec_xep_sau_cong_viec_dang_chay_la_dang_cho_khong_bo_lac(): void
    {
        Queue::fake();

        // The worker is busy on a slow provider call, not absent.
        AiJob::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_CHAT,
            'status' => 'running',
        ])->forceFill(['started_at' => now()->subSeconds(30)])->save();

        $jobId = $this->actingAs($this->nguoiDung)
            ->postJson('/api/chatbot/message', ['message' => 'Tôi bị ho'])
            ->json('job_id');

        AiJob::where('uuid', $jobId)->firstOrFail()
            ->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])
            ->save();

        // Reported stranded, the browser would re-run this inline and block the
        // single-process dev server for the full provider budget a second time.
        $this->actingAs($this->nguoiDung)
            ->getJson("/api/ai-jobs/{$jobId}")
            ->assertOk()
            ->assertJsonPath('stranded', false);
    }

    public function test_trang_ket_qua_hien_ket_luan_truoc_khi_ban_viet_lai_ve(): void
    {
        // Only the rewrite is faked. The decision job must really run, because the
        // patient waits on it — faking it would leave the questionnaire undecided,
        // which is exactly the state this test exists to rule out.
        Queue::fake([\App\Jobs\XuLyVietLaiLoiKhuyen::class]);
        config()->set('chatbot.ai.triage.advice_rewrite', true);

        $danhMuc = \App\Models\DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);
        $this->seed(\Database\Seeders\TriThucYTeSeeder::class);
        \App\Models\SanPham::factory()->create(['ma_dm' => $danhMuc->ma_dm,
            'ten' => 'Paracetamol 500mg',
            'thanh_phan' => 'Paracetamol 500mg',
            'cach_dung' => 'Giảm đau đầu, hạ sốt',
            'so_luong_ton' => 50,
        ]);

        $this->nguoiDung->forceFill(['ngay_sinh' => now()->subYears(34)->toDateString()])->save();

        $this->actingAs($this->nguoiDung)->get(route('sang-loc.kiem-tra'));
        $phien = \App\Models\PhienSangLoc::where('ma_nd', $this->nguoiDung->ma_nd)->inProgress()->firstOrFail();

        foreach ([1 => 'đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'không có', 5 => 'không có thêm'] as $step => $answer) {
            $this->actingAs($this->nguoiDung)->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        $phien->refresh();

        // The medical conclusion is already persisted and readable; only the wording
        // is still queued. Deferring the rewrite must never defer the outcome.
        $this->assertSame('self_care', $phien->outcome);
        $this->assertSame('completed', $phien->status);
        $this->assertNull($phien->ai_advice);
        $this->assertNotNull($phien->advice_given);

        Queue::assertPushed(\App\Jobs\XuLyVietLaiLoiKhuyen::class, 1);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.outcome_self_care'))
            // Honest about what is still coming, while the conclusion is already there.
            ->assertSee(__('messages.health.ai_pending'));

        // A reload while the rewrite is genuinely still queued keeps saying so — the
        // banner tracks the job, not a one-shot flash, so it survives the reload that
        // a 15-second wait invites.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.ai_pending'));

        // Once the rewrite lands there is nothing left to wait for.
        $phien->forceFill(['ai_status' => 'ok', 'ai_advice' => 'Anh/chị nghỉ ngơi nhé.'])->save();

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.ket-qua', $phien->ma_sl))
            ->assertOk()
            ->assertSee(__('messages.health.outcome_self_care'))
            ->assertDontSee(__('messages.health.ai_pending'));
    }

    public function test_viet_lai_loi_khuyen_sang_loc_chay_nhu_cong_viec_ma_khong_doi_ket_qua(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => "LỜI KHUYÊN:\nAnh/chị nghỉ ngơi và uống nhiều nước trong 3 ngày nhé.",
            ]]],
        ])]);

        config()->set('chatbot.ai.triage.advice_rewrite', true);

        $phien = \App\Models\PhienSangLoc::create(['ma_nd' => $this->nguoiDung->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống nhiều nước trong 3 ngày.',
            'collected_data' => ['raw_symptom_text' => 'toi bi sot nhe'],
        ]);

        $job = app(\App\Services\AiJobService::class)->dispatchAdviceRewrite($phien);

        $this->assertSame(AiJob::KIND_TRIAGE_ADVICE, $job->kind);
        // No customer free text in the payload for this kind.
        $this->assertSame(['ma_sl' => $phien->ma_sl], $job->payload);

        (new \App\Jobs\XuLyVietLaiLoiKhuyen($job->uuid))->handle(
            app(\App\Services\Triage\Ai\SangLocAiVietLaiService::class),
            app(\App\Services\AiJobService::class)
        );

        $phien->refresh();

        $this->assertSame('done', $job->refresh()->status);
        $this->assertSame('ok', $phien->ai_status);
        // The deterministic record and the medical conclusion are untouched.
        $this->assertSame('Nghỉ ngơi, uống nhiều nước trong 3 ngày.', $phien->advice_given);
        $this->assertSame('self_care', $phien->outcome);
    }
}
