<?php

namespace Tests\Feature;

use App\Jobs\XuLyDanhGiaSangLoc;
use App\Models\AiJob;
use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Services\Triage\Ai\SangLocAiSuKienService;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The outcome decision as background work.
 *
 * This exists because of a live bug: `danhGia()` makes the AI fact-extraction call,
 * which was measured at 11-30s against a provider whose synchronous budget was 8s. On
 * timeout the AI facts are dropped silently, so `canHoiLamRo()` had nothing to
 * read and the wizard jumped from "vòng 1/3" straight to the result — and a stated
 * duration of "mấy năm nay" was scored as one day.
 *
 * The invariant: the patient WAITS for the decision. Nothing is shown until the job
 * has decided, so the conclusion is never revised after they have read it.
 */
class HangDoiQuyetDinhSangLocTest extends TestCase
{
    use RefreshDatabase;

    protected NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt', 'mo_ta' => 'Giảm đau, hạ sốt']);
        $this->seed(TriThucYTeSeeder::class);

        $this->nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User',
            'trang_thai' => 'active',
            'ngay_sinh' => now()->subYears(34)->toDateString(),
        ]);

        SanPham::create(['ma_dm' => DanhMuc::first()->ma_dm,
            'ten' => 'Paracetamol 500mg',
            'thanh_phan' => 'Paracetamol 500mg',
            'cach_dung' => 'Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ',
            'gia' => 5000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 100,
            'muc_canh_bao' => 5,
            'so_lo' => 'B-' . uniqid(),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    protected function toolCall(array $arguments): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'function_call' => [
                        'name' => 'extract_triage_facts',
                        'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE),
                    ],
                ],
            ]],
        ];
    }

    protected function startSession(): PhienSangLoc
    {
        $this->actingAs($this->nguoiDung)->get(route('sang-loc.kiem-tra'));

        return PhienSangLoc::where('ma_nd', $this->nguoiDung->ma_nd)->inProgress()->latest('ma_sl')->firstOrFail();
    }

    /**
     * Answer the five wizard steps. The last POST is the one that triggers a decision.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function answerAllSteps(PhienSangLoc $phien)
    {
        $answers = [1 => 'tôi bị đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'không có', 5 => 'không có thêm'];
        $response = null;

        foreach ($answers as $step => $answer) {
            $current = PhienSangLoc::find($phien->ma_sl);

            if ($current === null || $current->status !== 'in_progress') {
                break;
            }

            $response = $this->actingAs($this->nguoiDung)->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        return $response;
    }

    public function test_cau_tra_loi_cuoi_xep_hang_quyet_dinh_thay_vi_goi_nha_cung_cap(): void
    {
        Queue::fake();

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        // The whole point: the 11-30s provider call does not happen in the request.
        Http::assertNothingSent();
        Queue::assertPushed(XuLyDanhGiaSangLoc::class, 1);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        $this->assertSame('queued', $job->status);
        $this->assertSame($phien->ma_sl, $job->payload['ma_sl']);

        // Undecided, not half-decided: no outcome is shown before the job runs.
        $phien->refresh();
        $this->assertSame('in_progress', $phien->status);
        $this->assertNull($phien->outcome);
    }

    public function test_quy_trinh_hien_trang_thai_cho_khi_bo_xu_ly_quyet_dinh(): void
    {
        Queue::fake();

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee(__('messages.health.deciding_title'))
            ->assertSee(__('messages.health.deciding_body'))
            // The answers are shown back, so the wait is not a blank screen.
            ->assertSee('tôi bị đau đầu', false);
    }

    public function test_tai_lai_khi_quyet_dinh_da_xep_hang_khong_xep_them(): void
    {
        Queue::fake();

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        $this->actingAs($this->nguoiDung)->get(route('sang-loc.kiem-tra'))->assertOk();
        $this->actingAs($this->nguoiDung)->get(route('sang-loc.kiem-tra'))->assertOk();

        // A second job would mean a second paid provider call for one questionnaire.
        $this->assertSame(1, AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->count());
    }

    public function test_chay_cong_viec_hoan_tat_va_diem_tham_do_tro_den_ket_qua(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->toolCall([
            'symptom_codes' => ['headache'],
            'needs_more_info' => false,
            'missing_questions' => [],
        ]))]);
        config()->set('chatbot.ai.triage.fact_extraction', true);

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        (new XuLyDanhGiaSangLoc($job->uuid))->handle(
            app(\App\Services\Triage\SangLocYTeService::class),
            app(\App\Services\AiJobService::class)
        );

        $phien->refresh();
        $this->assertSame('completed', $phien->status);
        $this->assertSame('self_care', $phien->outcome);

        $this->actingAs($this->nguoiDung)
            ->getJson(route('sang-loc.quyet-dinh', $job->uuid))
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('next', 'result')
            // Built by the controller, not the worker: route() inside a job resolves the
            // host from APP_URL, which sent the browser to a 404 on a non-default port.
            ->assertJsonPath('redirect_url', route('sang-loc.ket-qua', $phien->ma_sl));

        $this->assertArrayNotHasKey('result_url', (array) $job->refresh()->result);
    }

    /**
     * The hosting incident: the evaluation job ran the fact-extraction call with the
     * shared failover budget, so a 30s primary timeout left the fallback max(1, ceil(30-30))
     * = 1s and it died at exactly 1001ms (log, 2026-11-17 06:09). The worker has no browser
     * waiting, so its fact extraction must opt into per-provider budgets; the inline
     * controller path must keep the shared budget.
     */
    public function test_cong_viec_danh_gia_dung_ngan_sach_rieng_cho_trich_xuat(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->toolCall([
            'symptom_codes' => ['headache'],
            'needs_more_info' => false,
            'missing_questions' => [],
        ]))]);
        config()->set('chatbot.ai.triage.fact_extraction', true);

        $captured = [];
        $this->app->bind(SangLocAiSuKienService::class, function () use (&$captured) {
            return new class ($this->app->make(\App\Services\NhatKyKiemToanService::class), $captured) extends SangLocAiSuKienService {
                /** @var array<int, array<string, mixed>> */
                public array $capturedOptions = [];

                public function __construct(\App\Services\NhatKyKiemToanService $audit, array &$captured)
                {
                    parent::__construct($audit);
                    $this->capturedOptions = &$captured;
                }

                public function callProvider(\App\Services\AiProviderService $provider, array $messages, array $options = []): array
                {
                    $this->capturedOptions[] = $options;

                    return parent::callProvider($provider, $messages, $options);
                }
            };
        });

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        (new XuLyDanhGiaSangLoc($job->uuid))->handle(
            app(\App\Services\Triage\SangLocYTeService::class),
            app(\App\Services\AiJobService::class)
        );

        $this->assertNotSame([], $captured, 'The worker evaluation must reach the provider.');
        $this->assertSame(
            true,
            $captured[0]['dedicated_budgets'] ?? null,
            'Fact extraction inside the worker must use per-provider budgets.'
        );
    }

    /** The inline (synchronous request) path keeps the shared budget: a browser is waiting. */
    public function test_quyet_dinh_truc_tiep_giu_ngan_sach_chung(): void
    {
        Http::fake(['*' => Http::response($this->toolCall([
            'symptom_codes' => ['headache'],
            'needs_more_info' => false,
            'missing_questions' => [],
        ]))]);
        config()->set('chatbot.ai.triage.fact_extraction', true);

        // Force the inline path: an abandoned evaluation for THIS session makes
        // decideOutcome() skip the queue and decide inline.
        $phien0 = $this->startSession();
        AiJob::create([
            'uuid' => 'forced-inline-uuid',
            'ma_nd' => $this->nguoiDung->ma_nd,
            'kind' => AiJob::KIND_TRIAGE_EVALUATION,
            'status' => 'failed',
            'payload' => ['ma_sl' => $phien0->ma_sl],
        ]);

        $captured = [];
        $this->app->bind(SangLocAiSuKienService::class, function () use (&$captured) {
            return new class ($this->app->make(\App\Services\NhatKyKiemToanService::class), $captured) extends SangLocAiSuKienService {
                /** @var array<int, array<string, mixed>> */
                public array $capturedOptions = [];

                public function __construct(\App\Services\NhatKyKiemToanService $audit, array &$captured)
                {
                    parent::__construct($audit);
                    $this->capturedOptions = &$captured;
                }

                public function callProvider(\App\Services\AiProviderService $provider, array $messages, array $options = []): array
                {
                    $this->capturedOptions[] = $options;

                    return parent::callProvider($provider, $messages, $options);
                }
            };
        });

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        $this->assertNotSame([], $captured, 'The inline decision must reach the provider.');
        $this->assertArrayNotHasKey(
            'dedicated_budgets',
            $captured[0],
            'Inline fact extraction keeps the shared budget for the waiting browser.'
        );
    }

    public function test_vong_theo_sau_song_sot_qua_lan_chuyen_hang_doi(): void
    {
        Queue::fake();
        config()->set('chatbot.ai.triage.fact_extraction', true);
        config()->set('chatbot.ai.triage.clarify_round', true);

        Http::fake(['*' => Http::response($this->toolCall([
            'symptom_codes' => ['headache'],
            'needs_more_info' => true,
            'missing_questions' => ['Anh/chị đau ở vùng nào của đầu?'],
        ]))]);

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        (new XuLyDanhGiaSangLoc($job->uuid))->handle(
            app(\App\Services\Triage\SangLocYTeService::class),
            app(\App\Services\AiJobService::class)
        );

        $phien->refresh();

        // Still open, with a round stored — this is the state the timeout used to skip.
        $this->assertSame('in_progress', $phien->status);
        $this->assertSame(1, $phien->ai_clarify_rounds);
        $this->assertSame(['Anh/chị đau ở vùng nào của đầu?'], $phien->pendingClarifyQuestions());

        $this->actingAs($this->nguoiDung)
            ->getJson(route('sang-loc.quyet-dinh', $job->uuid))
            ->assertOk()
            ->assertJsonPath('next', 'clarify');

        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee(__('messages.health.clarify_round', ['round' => 1, 'total' => 3]))
            ->assertSee('Anh/chị đau ở vùng nào của đầu?', false);
    }

    public function test_tra_loi_mot_vong_xep_hang_quyet_dinh_tiep_theo_thay_vi_hoan_tat(): void
    {
        Queue::fake();
        config()->set('chatbot.ai.triage.fact_extraction', true);
        config()->set('chatbot.ai.triage.clarify_round', true);

        $phien = $this->startSession();
        $phien->forceFill([
            'collected_data' => ['raw_symptom_text' => 'đau đầu', 'duration_days' => 1],
            'ai_clarify_rounds' => 1,
            'ai_meta' => ['clarify_questions' => ['Anh/chị đau ở vùng nào của đầu?']],
        ])->save();

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.lam-ro'), [
            'triage_session_id' => $phien->ma_sl,
            'clarify' => ['đau nửa đầu bên phải'],
        ]);

        // The old code called danhGia() here; when that timed out the round silently
        // became the last one.
        Http::assertNothingSent();
        Queue::assertPushed(XuLyDanhGiaSangLoc::class, 1);

        $phien->refresh();
        $this->assertSame('in_progress', $phien->status);
        $this->assertSame('đau nửa đầu bên phải', $phien->collected_data['ai_clarify_answers'][0]['answer']);
    }

    public function test_cong_viec_mac_ket_duoc_ket_thuc_de_lan_tai_lai_quyet_dinh_truc_tiep(): void
    {
        Queue::fake();

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        $job->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])->save();

        $this->actingAs($this->nguoiDung)
            ->getJson(route('sang-loc.quyet-dinh', $job->uuid))
            ->assertOk()
            ->assertJsonPath('stranded', true)
            ->assertJsonPath('redirect_url', route('sang-loc.kiem-tra'));

        // Retired, not left queued: otherwise the reload finds the same job, renders the
        // waiting state again, polls, sees `stranded` again — a loop with no exit.
        $this->assertSame('failed', $job->refresh()->status);
    }

    public function test_tai_lai_sau_cong_viec_mac_ket_den_duoc_ket_qua(): void
    {
        Queue::fake();

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)
            ->firstOrFail()
            ->forceFill(['created_at' => now()->subSeconds(AiJob::SYNC_FALLBACK_SECONDS + 1)])
            ->save();

        // With no worker at all, reloading the wizard has to finish the job itself.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertRedirect(route('sang-loc.ket-qua', $phien->ma_sl));

        $this->assertSame('completed', $phien->fresh()->status);
    }

    public function test_cong_viec_that_bai_tra_may_khach_ve_quyet_dinh_truc_tiep(): void
    {
        Queue::fake();

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        $job->forceFill(['status' => 'failed', 'error' => 'worker died'])->save();

        $this->actingAs($this->nguoiDung)
            ->getJson(route('sang-loc.quyet-dinh', $job->uuid))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('redirect_url', route('sang-loc.kiem-tra'));

        // The reload finishes the questionnaire in-request rather than queueing again:
        // one dead attempt is enough evidence that nothing is consuming the queue.
        $this->actingAs($this->nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertRedirect(route('sang-loc.ket-qua', $phien->ma_sl));

        $this->assertSame('completed', $phien->fresh()->status);
        $this->assertSame(
            1,
            AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->count(),
            'A dead job must not spawn a replacement that will die the same way.'
        );
    }

    public function test_khach_hang_khac_khong_the_tham_do_quyet_dinh_cua_nguoi_khac(): void
    {
        Queue::fake();

        $phien = $this->startSession();
        $this->answerAllSteps($phien);

        $job = AiJob::where('kind', AiJob::KIND_TRIAGE_EVALUATION)->firstOrFail();
        $other = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        $this->actingAs($other)
            ->getJson(route('sang-loc.quyet-dinh', $job->uuid))
            ->assertNotFound();
    }

    public function test_khach_chua_dang_nhap_khong_the_tham_do_quyet_dinh(): void
    {
        $this->getJson(route('sang-loc.quyet-dinh', 'any-uuid'))->assertUnauthorized();
    }

    public function test_cap_cuu_khong_bao_gio_cho_bo_xu_ly(): void
    {
        Queue::fake();

        $phien = $this->startSession();

        $this->actingAs($this->nguoiDung)->post(route('sang-loc.tra-loi'), [
            'triage_session_id' => $phien->ma_sl,
            'step' => 1,
            'answer' => 'đau ngực dữ dội, khó thở',
        ]);

        $phien->refresh();

        // The red flag is detected in plain PHP, so somebody describing chest pain is
        // told to call 115 in the same request — never after a queue hop.
        $this->assertSame('emergency', $phien->outcome);
        $this->assertSame('completed', $phien->status);
        Queue::assertNotPushed(XuLyDanhGiaSangLoc::class);
    }

    public function test_quyet_dinh_chay_truc_tiep_khi_khong_co_bo_xu_ly(): void
    {
        // No Queue::fake(): the `sync` driver runs the job during dispatch, which is
        // what a worker-less install does.
        $phien = $this->startSession();
        $response = $this->answerAllSteps($phien);

        $phien->refresh();

        $this->assertSame('completed', $phien->status);
        $this->assertSame('self_care', $phien->outcome);
        $response->assertRedirect(route('sang-loc.ket-qua', $phien->ma_sl));
    }

    public function test_cau_tra_loi_ajax_tra_ve_giai_doan_tiep_theo_ma_khong_tai_lai(): void
    {
        $phien = $this->startSession();

        $response = $this->actingAs($this->nguoiDung)->postJson(route('sang-loc.tra-loi'), [
            'triage_session_id' => $phien->ma_sl,
            'step' => 1,
            'answer' => 'tôi bị đau đầu',
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'question')
            ->assertJsonPath('allow_back', true)
            ->assertJsonStructure(['stage', 'answered_steps']);

        // The stage is server-rendered HTML: no Vietnamese medical string is rebuilt in
        // the client.
        $this->assertStringContainsString('triage-question', $response->json('stage'));
        $this->assertStringContainsString(__('messages.health.progress_label', ['current' => 2, 'total' => 5]), $response->json('stage'));
        $this->assertStringContainsString('tôi bị đau đầu', $response->json('stage'));
    }

    public function test_phan_hoi_quyet_dinh_ajax_la_giai_doan_cho_khong_phai_ket_qua(): void
    {
        Queue::fake();

        $phien = $this->startSession();

        foreach ([1 => 'tôi bị đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì', 4 => 'không có'] as $step => $answer) {
            $this->actingAs($this->nguoiDung)->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        $response = $this->actingAs($this->nguoiDung)->postJson(route('sang-loc.tra-loi'), [
            'triage_session_id' => $phien->ma_sl,
            'step' => 5,
            'answer' => 'không có thêm',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('mode', 'deciding')
            ->assertJsonStructure(['stage', 'triage_pending' => ['job_id', 'poll_url']]);

        $this->assertStringContainsString(__('messages.health.deciding_title'), $response->json('stage'));
    }

    public function test_loi_xac_thuc_ajax_mang_theo_truong(): void
    {
        $phien = $this->startSession();

        $this->actingAs($this->nguoiDung)
            ->postJson(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => 1,
                'answer' => '',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.answer.0', 'Anh/chị vui lòng mô tả triệu chứng để em sàng lọc giúp nhé.');
    }

    public function test_buoc_cu_ajax_de_xuat_url_phuc_hoi(): void
    {
        $phien = $this->startSession();

        $this->actingAs($this->nguoiDung)
            ->postJson(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => 4,
                'answer' => 'gì đó',
            ])
            ->assertStatus(409)
            ->assertJsonPath('redirect_url', route('sang-loc.kiem-tra'));
    }
}
