<?php

namespace App\Http\Controllers;


use App\Models\AiJob;
use App\Models\TrieuChungYTe;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\HoSoSucKhoe;
use App\Services\AiJobService;
use App\Services\AnToanThuocService;
use App\Services\Triage\SangLocYTeService;
use App\Services\Triage\BaoCaoSangLocService;
use App\Services\Triage\Ai\SangLocAiVietLaiService;
use App\Services\Triage\Ai\SangLocAiTomTatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Wizard sàng lọc y tế.
 *
 * Chạy được khi không có JavaScript: mỗi bước là một form POST thật dẫn sang bước
 * kế tiếp. Tầng JS chỉ để tránh tải lại toàn trang. Một công cụ sức khỏe phải
 * vẫn chạy được khi script gặp lỗi.
 */
class SangLocController extends Controller
{
    public function __construct(
        protected SangLocYTeService $triageService,
        protected SangLocAiVietLaiService $adviceService,
        protected AiJobService $aiJobs,
        protected AnToanThuocService $anToanThuoc,
    ) {
    }

    /**
     * Quyết định kết quả — trong worker nếu có, trực tiếp nếu không.
     *
     * `evaluate()` gọi AI trích xuất chi tiết, và quá trình đó được đo tại
     * 11-30s trên nhà cung cấp đã cấu hình trong khi ngân sách đồng bộ là 8s. Vì vậy
     * nó gần như luôn bị timeout, và mất kết nối không chỉ tốn độ trễ: nó
     * làm mất chi tiết đã trích xuất, nên mốc thời lượng "may nam nay" được chấm như
     * một ngày, và vòng hỏi làm rõ không bao giờ xuất hiện vì shouldAskClarify() không có
     * kết quả AI để đọc. Đó là bug "vòng 1/3 rồi nhảy thẳng đến kết quả".
     *
     * Trì hoãn an toàn ở đây — khác với trì hoãn sau finalize() — vì
     * bệnh nhân chờ: không gì được hiện cho đến khi job quyết định xong, nên kết luận
     * không bao giờ được sửa sau khi họ đã đọc.
     *
     * Trường hợp khẩn cấp được loại trừ và quyết định trực tiếp: kết quả đó không cần AI.
     *
     * Trên queue driver `sync`, job đã chạy xong khi dispatch trả về,
     * vì vậy suite test và cấu hình không có worker vẫn hành xử như trước.
     */
    protected function decideOutcome(Request $request, PhienSangLoc $phien): RedirectResponse|JsonResponse
    {
        // Khẩn cấp không bao giờ vào hàng đợi. Cờ đỏ được tìm bằng PHP thuần, không AI, và
        // người mô tả đau ngực phải thấy số 115 trong phản hồi này —
        // không phải sau khi một worker nhận job.
        if ($this->triageService->coCapCuuCuu($phien, $this->profileFor((int) $phien->ma_nd))) {
            return $this->decideOutcomeInline($request, $phien);
        }

        // Một lần thử trước của phiên này đã bị rủi vì không có worker.
        // Đưa vào hàng đợi thêm lần nữa sẽ lặp vô hạn: treo, rủi, vào hàng đợi, treo — 12s mỗi chu kỳ,
        // mãi mãi. Một lần thất bại là đủ bằng chứng rằng không ai tiêu thụ job.
        if ($this->hasAbandonedEvaluation($phien)) {
            return $this->decideOutcomeInline($request, $phien);
        }

        try {
            $job = $this->aiJobs->dispatchEvaluation($phien);
        } catch (\Throwable $exception) {
            // Hàng đợi không khả dụng không được để bỏ trọn câu hỏi đã hoàn thành.
            Log::warning('Triage evaluation queue unavailable, deciding inline', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);

            return $this->decideOutcomeInline($request, $phien);
        }

        if ($job->status === 'failed') {
            return $this->decideOutcomeInline($request, $phien);
        }

        if ($job->status === 'done') {
            return $this->respondDecided($request, $phien->fresh(), $job);
        }

        return $this->respondPending($request, $phien, $job);
    }

    /**
     * Chạy quyết định ngay trong request này. Dùng khi không có worker nhận việc.
     */
    protected function decideOutcomeInline(Request $request, PhienSangLoc $phien): RedirectResponse|JsonResponse
    {
        $result = $this->triageService->danhGia($phien, $this->profileFor((int) $phien->ma_nd));

        if ($this->triageService->canHoiLamRo($phien, $result)) {
            return $this->respondClarify(
                $request,
                $this->triageService->luuCChamLamRo($phien, $result)
            );
        }

        return $this->respondResult($request, $this->finalizeSession($phien, $result));
    }

    /**
     * @param \App\Models\AiJob $job A finished evaluation job.
     */
    protected function respondDecided(Request $request, PhienSangLoc $phien, AiJob $job): RedirectResponse|JsonResponse
    {
        if (($job->result['next'] ?? null) === 'clarify') {
            return $this->respondClarify($request, $phien);
        }

        return $this->respondResult($request, $phien);
    }

    /**
     * Chốt phiên và đưa hai seam AI sau quyết định vào hàng đợi.
     *
     * Cả hai bắt đầu *sau* finalize(), chỉ ghi cột `ai_*` của chúng, và không thể
     * thay đổi kết quả — nên khác với quyết định phía trên, chúng có thể đến khi
     * bệnh nhân đang đọc, và trang kết quả tự poll lấy chúng.
     *
     * @param array<string, mixed> $result Already-computed evaluate() output, so the
     *                                     quy tắc (và bước trích xuất AI nếu có) chỉ chạy một lần.
     */
    protected function finalizeSession(PhienSangLoc $phien, array $result): PhienSangLoc
    {
        $phien = $this->triageService->hoanTat($phien, $result);

        $this->aiJobs->dispatchAdviceRewriteIfEligible($phien);
        $this->aiJobs->dispatchBriefingIfEligible($phien);

        return $phien->refresh();
    }

    /**
     * Dữ liệu view mà bước wizard cần, dù được render thành trang đầy đủ hay
     * hoán đổi qua AJAX.
     *
     * @param array<string, mixed>|null $question
     * @return array<string, mixed>
     */
    protected function stageData(PhienSangLoc $phien, ?array $question, array $clarifyQuestions, ?AiJob $pendingJob, ?HoSoSucKhoe $hoSo): array
    {
        return [
            'session' => $phien,
            'question' => $question,
            // Điền trước để quay lại thấy văn bản của người dùng, không phải ô trống.
            'previousAnswer' => $question
                ? $this->triageService->cauTraLoiBaiTruoc($phien, (int) $question['step'])
                : '',
            'hasProfile' => $hoSo !== null,
            'clarifyQuestions' => $clarifyQuestions,
            'clarifyRound' => (int) $phien->ai_clarify_rounds,
            'clarifyMaxRounds' => PhienSangLoc::MAX_CLARIFY_ROUNDS,
            // Khác null khi worker đang quyết định: bước hiện trạng thái chờ và
            // tự poll, thay vì giữ request mở cho quá trình gọi AI 11-30s.
            'pendingJobId' => $pendingJob?->uuid,
            'answeredSteps' => $this->answeredSteps($phien),
        ];
    }

    /**
     * Markup của bước mà phiên đang ở bước đó.
     *
     * Tầng AJAX nhận HTML chứ không phải model JSON là có chủ ý: dựng lại câu hỏi,
     * vòng hỏi làm rõ và văn bản chờ trong JavaScript sẽ nhân bản
     * mọi chuỗi tiếng Việt — gồm cả nhãn y — sang phía client,
     * điều mà quy tắc "không có nội dung y khoa bên ngoài server" cấm.
     */
    protected function renderStage(PhienSangLoc $phien): string
    {
        $phien = $phien->fresh();
        $hoSo = $this->profileFor((int) $phien->ma_nd);

        return view('health.partials.stage', $this->stageData(
            $phien,
            $phien->status === 'in_progress'
                ? $this->triageService->cauHoiTiep($phien, $hoSo)
                : null,
            $this->pendingClarifyQuestions($phien),
            $this->pendingEvaluationJob($phien),
            $hoSo
        ))->render();
    }

    /**
     * Bước hiện tại của phiên đang mở, hoặc câu hỏi đầu tiên của phiên mới.
     *
     * Khi không còn gì để hỏi — hồ sơ sức khỏe đã trả lời hết, hoặc một
     * cờ đỏ khẩn cấp đã dừng câu hỏi sớm — quyết định được đưa ra tại đây thay vì
     * hiện một trang cụt cánh.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $userId = (int) auth()->id();
        $phien = $this->triageService->batDauHoacTiepTuc($userId, session('chat_session_id'));
        $hoSo = $this->profileFor($userId);
        $question = $this->triageService->cauHoiTiep($phien, $hoSo);
        $clarifyQuestions = $this->pendingClarifyQuestions($phien);
        $pendingJob = $this->pendingEvaluationJob($phien);

        // Kiểm tra trước khi quyết định: vòng đang chờ nghĩa là câu hỏi đã
        // được lưu, và job đang chạy nghĩa là quyết định đang tiến hành — hoặc
        // cách khác, đánh giá lại ở đây sẽ tốn thêm một lần gọi nhà cung cấp.
        if ($question === null
            && $clarifyQuestions === []
            && $pendingJob === null
            && !empty($phien->collected_data)
        ) {
            $decision = $this->decideOutcome($request, $phien);

            $phien = $phien->fresh();
            $pendingJob = $this->pendingEvaluationJob($phien);
            $clarifyQuestions = $this->pendingClarifyQuestions($phien);

            // Chỉ quyết định hoàn thành mới là một lần điều hướng thật. Job trong hàng đợi render
            // trạng thái chờ phía dưới và một vòng hỏi mới render form của nó — cả hai
            // mà không phải qua redirect quay lại chính action này.
            if ($pendingJob === null && $clarifyQuestions === []) {
                return $decision;
            }
        }

        return view('health.check', array_merge(
            $this->stageData($phien, $question, $clarifyQuestions, $pendingJob, $hoSo),
            [
                'disclaimer' => SangLocYTeService::DISCLAIMER,
                // Chỉ return cuối cùng. `return $decision` sớm ở trên và stageData()
                // — chia sẻ với renderStage() — giữ nguyên, nên không lượt hoán đổi bước AJAX nào có thể
                // render lại popup giữa chừng wizard.
                'showDisclaimerPopup' => $this->disclaimerPopupDue($request, $userId),
            ]
        ));
    }

    /**
     * Job đánh giá vẫn đang quyết định kết quả phiên này, nếu có.
     *
     * Giới hạn trong `in_progress`: một khi phiên đã chốt, job là lịch sử, và
     * row cũ không được làm trạng thái chờ xuất hiện trở lại.
     *
     * Job bị treo — vẫn `queued` lâu sau khi tạo, nghĩa là không worker nào từng
     * nhận nó — được rủi ở đây và báo cáo là "không có gì đang chờ". Nếu không làm vậy
     * trang sẽ lặp: tải lại thấy job trong hàng đợi, render trạng thái chờ,
     * client poll, thấy `stranded`, tải lại trang, rồi lại thấy cùng job đó.
     */
    protected function pendingEvaluationJob(PhienSangLoc $phien): ?AiJob
    {
        if ($phien->status !== 'in_progress') {
            return null;
        }

        $job = AiJob::where('ma_nd', $phien->ma_nd)
            ->where('kind', AiJob::KIND_TRIAGE_EVALUATION)
            ->whereIn('status', ['queued', 'running'])
            ->where('payload->ma_sl', $phien->ma_sl)
            ->latest('ma_aj')
            ->first();

        if ($job !== null && $job->isStranded()) {
            $this->aiJobs->abandon($job, 'no worker picked the decision up, decided in the request');

            return null;
        }

        return $job;
    }

    /**
     * Quyết định cho phiên này đã bị bỏ cuộc vì không có worker.
     */
    protected function hasAbandonedEvaluation(PhienSangLoc $phien): bool
    {
        return AiJob::where('ma_nd', $phien->ma_nd)
            ->where('kind', AiJob::KIND_TRIAGE_EVALUATION)
            ->where('status', 'failed')
            ->where('payload->ma_sl', $phien->ma_sl)
            ->exists();
    }

    /**
     * Các câu trả lời đã trình bày, mới nhất ở cuối, cho đường dẫn chứng minh phía trên
     * câu hỏi hiện tại.
     *
     * Chỉ đọc những gì khách hàng gõ — không output quy tắc, không output AI — nên
     * wizard không bao giờ xem trước kết luận mà nó chưa đạt đến.
     *
     * @return array<int, array{step: int, label: string, answer: string, skipped: bool}>
     */
    protected function answeredSteps(PhienSangLoc $phien): array
    {
        $data = $phien->collected_data ?? [];
        $skipped = (array) ($data['skipped'] ?? []);
        $keys = [
            1 => 'raw_symptom_text',
            2 => 'duration_days',
            3 => 'medications_taken',
            4 => 'history',
            5 => 'other_symptoms',
        ];

        $trail = [];

        foreach ($keys as $step => $key) {
            $wasSkipped = in_array($key, $skipped, true);
            $value = $data[$key] ?? null;

            if ($value === null && !$wasSkipped) {
                continue;
            }

            if ($step === 2 && $value !== null) {
                $value = __('messages.health.basis_duration_days', ['days' => (int) $value]);
            }

            $trail[] = [
                'step' => $step,
                'label' => __('messages.health.step_' . $step),
                'answer' => is_array($value) ? implode(', ', $value) : trim((string) $value),
                'skipped' => $wasSkipped,
            ];
        }

        return $trail;
    }

    /**
     * Các câu hỏi AI đã hỏi mà khách hàng chưa trả lời hoặc bỏ qua.
     *
     * @return array<int, string>
     */
    protected function pendingClarifyQuestions(PhienSangLoc $phien): array
    {
        if ($phien->status !== 'in_progress') {
            return [];
        }

        // recordClarifyAnswers() xóa danh sách khi vòng được trả lời, nên
        // danh sách khác rỗng ở đây luôn có nghĩa "vòng này vẫn đang mở".
        return $phien->pendingClarifyQuestions();
    }

    /**
     * Ghi một câu trả lời, rồi hoặc hỏi câu tiếp hoặc chốt phiên.
     */
    public function answer(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'triage_session_id' => ['required', 'integer'],
            'step' => ['required', 'integer', 'min:1', 'max:' . PhienSangLoc::TOTAL_STEPS],
            'answer' => ['nullable', 'string', 'max:1000'],
            'option_answer' => ['nullable', 'string', 'max:1000'],
            'skipped' => ['nullable', 'boolean'],
        ], [
            'answer.max' => 'Câu trả lời không được vượt quá 1000 ký tự.',
        ]);

        $userId = (int) auth()->id();
        $phien = PhienSangLoc::where('ma_nd', $userId)
            ->where('ma_sl', $validated['triage_session_id'])
            ->inProgress()
            ->first();

        if (!$phien) {
            return $this->respondExpired($request);
        }

        // Ép kiểu tường minh: quy tắc `integer` xác minh kiểu nhưng không tự chuyển đổi,
        // nên $validated['step'] đến là string và sẽ thất bại === với
        // các hằng STEP_*.
        $step = (int) $validated['step'];
        $hoSo = $this->profileFor($userId);

        // Câu trả lời phải thuộc câu hỏi mà phiên đang ở bước đó. Nếu không có
        // kiểm tra này, một POST giả mạo (step=5) sẽ chốt sàng lọc mà không ghi triệu chứng hay
        // thời lượng, và mọi quy tắc bảo vệ đều rơi vào tự chăm sóc. So sánh với
        // nextQuestion thay vì current_step vì bước được hồ sơ sức khỏe che khuất
        // sẽ bị bỏ qua, nên bước đã render có thể đứng trước biến đếm.
        $expected = $this->triageService->cauHoiTiep($phien, $hoSo);

        if ($expected === null || $step !== (int) $expected['step']) {
            return $this->respondStepMismatch($request);
        }

        $skipped = (bool) ($validated['skipped'] ?? false);
        // Lựa chọn quick-pick được nhấn thắng với ô nhập, mà nút Back điền trước theo
        // câu trả lời đã lưu — nên lần nhấn phải thay được nó.
        //
        // Điều đó chỉ đúng vì `option_answer` giờ chỉ xuất hiện KHI có một lần nhấn chủ đích
        // chọn: template bước đặt nút submit mặc định không tên lên trước, nên
        // submit ngầm của HTML (Enter trong ô nhập) không còn kích hoạt lựa chọn
        // quick-pick. Trước đó, Enter gửi `option_answer=<lựa chọn đầu>`
        // kèm với văn bản gõ và lựa chọn thắng — bệnh nhân đã gõ một
        // lời phàn nàn và wizard lại ghi thêm một lần nữa.
        // TriageTypedAnswerTest khẳng định bất biến markup đó; không thêm một nút
        // submit có tên trước nó.
        $answer = trim((string) ($validated['option_answer'] ?? '')) !== ''
            ? trim((string) $validated['option_answer'])
            : trim((string) ($validated['answer'] ?? ''));

        // Bước 1 là câu trả lời bắt buộc duy nhất: không có triệu chứng thì không có gì để sàng lọc.
        if (!$skipped && $answer === '') {
            if ($step === SangLocYTeService::STEP_SYMPTOM) {
                return $this->respondValidationError(
                    $request,
                    'Anh/chị vui lòng mô tả triệu chứng để em sàng lọc giúp nhé.'
                );
            }

            $skipped = true;
        }

        try {
            $phien = $this->triageService->ghiCauTraLoi($phien, $step, $answer, $skipped);
            $question = $this->triageService->cauHoiTiep($phien, $hoSo);

            if ($question !== null) {
                return $this->respondQuestion($request, $phien, $question);
            }

            // Câu hỏi hoàn thành. Quyết định — gồm cả bước AI — chạy trong một
            // worker, và bệnh nhân chờ kết quả từ nó.
            return $this->decideOutcome($request, $phien);
        } catch (\Throwable $exception) {
            Log::error('Triage answer processing failed', [
                'ma_sl' => $phien->ma_sl,
                'step' => $validated['step'],
                'error' => $exception->getMessage(),
            ]);

            return $this->respondValidationError(
                $request,
                'Dạ, em gặp chút sự cố khi xử lý. Anh/chị vui lòng thử lại giúp em nhé.'
            );
        }
    }

    /**
     * Trả lời (hoặc bỏ qua) một vòng hỏi làm rõ AI, rồi hoặc hỏi vòng tiếp hoặc
     * hoàn tất phiên.
     *
     * Các action riêng chứ không phải bước wizard thêm: TOTAL_STEPS giữ nguyên 5,
     * thanh tiến độ giữ đúng ý nghĩa, và mọi vòng đều có thể bỏ qua.
     */
    public function clarify(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'triage_session_id' => ['required', 'integer'],
            'clarify' => ['nullable', 'array', 'max:' . PhienSangLoc::MAX_CLARIFY_QUESTIONS_PER_ROUND],
            'clarify.*' => ['nullable', 'string', 'max:500'],
            'skipped' => ['nullable', 'boolean'],
        ]);

        $userId = (int) auth()->id();
        $phien = PhienSangLoc::where('ma_nd', $userId)
            ->where('ma_sl', $validated['triage_session_id'])
            ->inProgress()
            ->first();

        if (!$phien) {
            return $this->respondExpired($request);
        }

        // Chỉ chạm được khi một vòng thực sự đang mở.
        if ($phien->pendingClarifyQuestions() === []) {
            return $this->respondStepMismatch($request);
        }

        $skipped = $request->boolean('skipped');

        try {
            if ($skipped) {
                // Từ chối là kết thúc cuộc trò chuyện: không vòng nào được đề nghị tiếp.
                $phien = $this->triageService->boQuaCauHoiLamRo($phien);

                return $this->decideOutcome($request, $phien);
            }

            $phien = $this->triageService->ghiCacChamLamRo(
                $phien,
                (array) ($validated['clarify'] ?? [])
            );

            // Câu trả lời có thể mở ra khoảng trống mới — hoặc đóng lại chúng. Model quyết định
            // qua needs_more_info, trong trần vòng hỏi, bên trong job.
            return $this->decideOutcome($request, $phien);
        } catch (\Throwable $exception) {
            Log::error('Triage clarify processing failed', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);

            return $this->respondValidationError(
                $request,
                'Dạ, em gặp chút sự cố khi xử lý. Anh/chị vui lòng thử lại giúp em nhé.'
            );
        }
    }

    /**
     * Mời dược sĩ xem một phiên đã hoàn thành.
     *
     * Kênh phụ tùy chọn: câu trả lời AI/quy tắc là kết quả chính, chỉ đưa người vào khi
     * khách hàng chủ động yêu cầu.
     */
    public function requestPharmacist(Request $request, int $MaSL): RedirectResponse
    {
        $phien = PhienSangLoc::where('ma_nd', (int) auth()->id())
            ->where('ma_sl', $MaSL)
            ->firstOrFail();

        $moved = $this->triageService->yeuCauDuyetDacSi($phien);

        return redirect()
            ->route('sang-loc.ket-qua', $MaSL)
            ->with('success', __(
                $moved
                    ? 'messages.health.pharmacist_requested'
                    : 'messages.health.pharmacist_request_unavailable'
            ));
    }

    /**
     * Quay lại một bước mà không mất câu trả lời đã lưu.
     */
    public function back(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'triage_session_id' => ['required', 'integer'],
        ]);

        $phien = PhienSangLoc::where('ma_nd', (int) auth()->id())
            ->where('ma_sl', $validated['triage_session_id'])
            ->inProgress()
            ->first();

        if ($phien && $phien->current_step > 1) {
            $phien->current_step = $phien->current_step - 1;
            $phien->save();
        }

        return redirect()->route('sang-loc.kiem-tra');
    }

    /**
     * Phiên hoàn thành có URL riêng để chia sẻ, đánh dấu và in.
     */
    public function result(Request $request, int $MaSL): View
    {
        $userId = (int) auth()->id();
        $phien = PhienSangLoc::where('ma_nd', $userId)
            ->where('ma_sl', $MaSL)
            ->firstOrFail();

        abort_if($phien->status === 'in_progress', 404);

        $hoSo = $this->profileFor($userId);

        // Đọc cột AI trước: trên queue driver `sync`, job mà quá trình gọi này
        // có thể dispatch đã chạy xong và ghi cột, nên $phien nếu không làm lại
        // vẫn giữ row trước khi dispatch.
        $briefingJob = $this->ensureBriefingJob($phien);
        $phien = $phien->refresh();

        return view('health.result', [
            'session' => $phien,
            'disclaimer' => SangLocYTeService::DISCLAIMER,
            // Khác null khi bản viết lại vẫn đang được ghi. Tra cứu thay vì
            // flash, vì quyết định có thể do worker thực hiện, mà worker
            // không có session để flash vào.
            'aiJobId' => $this->pendingAdviceJob($phien)?->uuid,
            // Cùng ý tưởng cho briefing chi tiết, quá trình gọi chậm hơn nhiều nên
            // được poll riêng: lời khuyên có thể đến nhiều phút trước nó.
            'briefingJobId' => $briefingJob?->uuid,
            'briefing' => $this->briefingFor($phien),
            // Phiên này có đủ điều kiện có briefing không, để trang phân biệt
            // "không bao giờ được hẹn" (khẩn cấp) với "đã thử nhưng không ra
            // gì" mà không phải suy lại quy tắc điều kiện trong Blade.
            'briefingExpected' => app(SangLocAiTomTatService::class)->duDieuKien($phien),
            // Cùng lý do với danh sách briefing: row đã lưu không thể giữ URL ảnh
            // dùng được, nên được giải ở đây từ sản phẩm còn sống.
            'suggestedProducts' => $this->suggestedProductsFor($phien),
            // Không bao giờ trước kết quả khẩn cấp: nút tel:115 là hành động duy nhất
            // của trang đó và không được chia màn hình với popup có thể bỏ qua,
            // thông báo. Thông báo gọn vẫn render — nhưng dạng inline,
            // vì khi popup đã bị ẩn thì "Xem lại" không còn gì để mở lại.
            'showDisclaimerPopup' => !$phien->isEmergency() && $this->disclaimerPopupDue($request, $userId),
            'disclaimerReviewable' => !$phien->isEmergency(),
            'hasProfile' => $hoSo !== null,
            'safety' => $this->safetyCheck($phien, $hoSo),
            'basis' => $this->decisionBasis($phien),
            'metrics' => $this->analysisMetrics($phien),
        ]);
    }

    /**
     * Khối phân tích số trên trang kết quả: điểm nguy cơ theo quy tắc kèm
     * thanh đóng góp theo từng yếu tố.
     *
     * Tính chỉ từ cột đã lưu — biểu đồ phải render được
     * hoàn toàn không AI, và mọi con số briefing ghi qua `data_analysis` đều được kiểm tra
     * với cùng bảng này.
     *
     * Điểm là quy tắc chấm điểm, không phải xác suất: dải của chính kết quả làm mốc
     * nó (tự chăm sóc 0-24 … khẩn cấp 75-100), nên con số không bao giờ trả lời ngược với
     * banner kết luận, và nhãn văn bản cũng nói rõ. Đóng góp là trọng số mà quy tắc
     * yếu tố thực sự mang — đây là chỗ duy nhất trên trang mà những con số đó
     * tồn tại.
     *
     * @return array<string, mixed>|null null when the session has no outcome yet
     */
    protected function analysisMetrics(PhienSangLoc $phien): ?array
    {
        $rank = array_search((string) $phien->outcome, array_reverse(BaoCaoSangLocService::OUTCOMES), true);

        if ($rank === false) {
            return null;
        }

        // Mốc dải + trọng số là một thang đo chủ đích, đặt ngay cạnh code áp dụng nó.
        // Nâng một trọng số là quyết định lâm sàng, không phải chỉnh sửa nhỏ.
        $bandBase = [0, 25, 50, 75][$rank];
        $bandTop = $bandBase + 24;
        $bandWidth = $bandTop - $bandBase + 1;

        $cacCoDo = collect($phien->triggered_red_flags ?? []);
        $bySeverity = fn (string $severity) => $cacCoDo
            ->filter(fn ($flag) => is_array($flag) && ($flag['severity'] ?? '') === $severity)
            ->count();

        $duration = (int) ($phien->answerFor('duration_days') ?? 0);
        $symptomCount = count(array_filter((array) $phien->matched_symptoms));
        $age = data_get($phien->ai_meta, 'age');

        $contributions = [
            ['key' => 'redflags', 'label' => __('messages.health.analysis_factor_redflags'), 'points' => min(30, $bySeverity('emergency') * 15 + $bySeverity('urgent') * 8 + $bySeverity('caution') * 3)],
            ['key' => 'duration', 'label' => __('messages.health.analysis_factor_duration'), 'points' => min(20, max(0, $duration - 1) * 4)],
            ['key' => 'symptoms', 'label' => __('messages.health.analysis_factor_symptoms'), 'points' => min(15, max(0, $symptomCount - 1) * 5)],
            ['key' => 'age', 'label' => __('messages.health.analysis_factor_age'), 'points' => $age !== null && ((int) $age >= 65 || (int) $age <= 2) ? 10 : 0],
        ];
        $factorTotal = array_sum(array_column($contributions, 'points'));

        // Mọi thứ trên điểm nền đều đến từ yếu tố đo được, nên vị trí trong dải
        // tỉ lệ với phần mà các yếu tố đó thực sự mang. Nhãn văn bản
        // hiện điểm mốc: trường hợp tự chăm sóc đọc thấp dù điều kiện còn lại thế nào.
        $score = $bandBase + (int) round($factorTotal / 75 * ($bandWidth - 1));
        $score = min($bandTop, $score);

        $barMax = max(10, $factorTotal);
        foreach ($contributions as &$bar) {
            $bar['width'] = $bar['points'] === 0 ? 0 : max(8, (int) round($bar['points'] / $barMax * 100));
        }
        unset($bar);

        return [
            'score' => $score,
            'band_base' => $bandBase,
            'band_top' => $bandTop,
            'level_label' => __('messages.health.outcome_' . $phien->outcome),
            // Thanh cho mọi thứ: yếu tố đo ra 0 hiện thanh track rỗng,
            // vì "cờ đỏ = 0" LÀ một kết quả đo được, chứ không phải thiếu dữ liệu.
            'contributions' => $contributions,
            'zero_contributions' => array_values(array_filter($contributions, fn (array $bar) => $bar['points'] === 0)),
        ];
    }

    /**
     * Panel briefing riêng, để trang kết quả hoán đổi vào khi job hoàn thành.
     *
     * 202 trong khi worker vẫn đang ghi, nên poller tiếp tục chờ; 200 kèm
     * panel sau khi ghi xong, hoặc khi thất bại rõ ràng. Phiên khẩn cấp
     * không bao giờ được hẹn briefing và nhận 404, và phiên chưa chốt không có trang kết quả
     * để làm pane của nó.
     *
     * Lưới chăm sóc đi kèm trong <template>: lựa chọn sản phẩm của briefing
     * chưa biết khi trang mới vẽ lần đầu, nên tab sẽ giữ hiện
     * chỉ danh sách của rule engine cho đến khi tải lại.
     */
    public function briefingPane(int $MaSL): Response
    {
        $userId = (int) auth()->id();
        $phien = PhienSangLoc::where('ma_nd', $userId)
            ->where('ma_sl', $MaSL)
            ->firstOrFail();

        abort_if($phien->isEmergency(), 404);
        abort_if($phien->status === 'in_progress', 404);

        $briefing = $this->briefingFor($phien);

        // Job hoàn thành không phải đang chờ, dù cột ghi gì. Worker chết
        // trước khi ghi trạng thái để cả hai null trong khi job là `done`; đọc
        // pending chỉ dựa vào cột sẽ trả 202 cho mỗi lần poll từ đó về sau,
        // và poller lặp đến giới hạn 90 lần thử trước khi hiện thông báo.
        $jobFinished = AiJob::where('ma_nd', $userId)
            ->where('kind', AiJob::KIND_TRIAGE_BRIEFING)
            ->where('payload->ma_sl', $MaSL)
            ->whereIn('status', ['done', 'failed', 'cancelled'])
            ->exists();

        $pending = $briefing === null && $phien->ai_briefing_status === null && ! $jobFinished;

        // Sản phẩm chăm sóc chỉ render khi briefing thực sự đến: lượt poll
        // đang chờ bỏ body 202, và lần thất bại rơi vào thông báo không khả dụng.
        // Tính snapshot cho mỗi lượt poll sẽ chạy lại bộ quy tắc
        // chọn sản phẩm 60+ lần trong lúc chờ.
        $hoSo = $briefing !== null ? $this->profileFor($userId) : null;

        return response()->view('health.partials.briefing-pane', [
            'briefing' => $briefing,
            'pending' => $pending,
            'unavailable' => $briefing === null && ! $pending,
            'session' => $phien,
            'safety' => $briefing !== null ? $this->safetyCheck($phien, $hoSo) : [],
            'hasProfile' => $hoSo !== null,
            'suggestedProducts' => $briefing !== null ? $this->suggestedProductsFor($phien) : [],
            'metrics' => $this->analysisMetrics($phien),
        ], $pending ? 202 : 200);
    }

    /**
     * Các sản phẩm gợi ý của kết quả, với ảnh được giải ở đây.
     *
     * Chỉ làm mới ảnh: phần khách hàng thấy trong danh sách này là
     * snapshot mà sàng lọc đã chụp, và đọc lại tồn hoặc giá sẽ thay làm
     * một quyết định đã lưu. Ảnh không thể nằm trong snapshot đó vì bộ quy tắc
     * có thể chạy trong worker, nơi URL asset sẽ cắm host của APP_URL vào row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function suggestedProductsFor(PhienSangLoc $phien): array
    {
        // Closure chứ không phải chuỗi 'is_array': Collection::filter truyền ($value, $key)
        // và is_array() nhận đúng một đối số, nên dạng chuỗi là lỗi 500.
        $sanPhams = collect($phien->suggested_products ?? [])->filter(fn ($row) => is_array($row));

        if ($sanPhams->isEmpty()) {
            return [];
        }

        $live = SanPham::whereIn('ma_sp', $sanPhams->pluck('id')->filter()->all())
            ->get()
            ->keyBy('ma_sp');

        return $sanPhams
            ->map(fn (array $row) => array_merge($row, [
                'image_url' => $live->get((int) ($row['id'] ?? 0))?->image_url,
            ]))
            ->values()
            ->all();
    }

    /**
     * Job briefing để poll, đưa vào hàng đợi nếu phiên này chưa có.
     *
     * Các phiên chốt trước khi tính năng ra đời, và phiên mà lần dispatch gặp
     * hàng đợi chết, không có job cũng không briefing — nên lần xem đầu trang kết quả
     * đưa nó vào hàng đợi. Guard trên "không tồn tại row job briefing nào cho phiên này
     * cả", chứ không phải "không ai đang chạy": một lần treo hoặc thất bại không được
     * thử lại tại mỗi lần tải lại trang.
     */
    protected function ensureBriefingJob(PhienSangLoc $phien): ?AiJob
    {
        // Thành công không bao giờ chạy lại. Lần thất bại được thử lại một lần nếu job trước
        // hoàn thành mà không sinh briefing dùng được.
        if ($phien->ai_briefing_status === 'ok') {
            return null;
        }

        $briefingAttempts = AiJob::where('ma_nd', $phien->ma_nd)
            ->where('kind', AiJob::KIND_TRIAGE_BRIEFING)
            ->where('payload->ma_sl', $phien->ma_sl)
            ->count();

        if ($phien->ai_briefing_status !== null && $briefingAttempts >= 2) {
            return null;
        }

        $job = AiJob::where('ma_nd', $phien->ma_nd)
            ->where('kind', AiJob::KIND_TRIAGE_BRIEFING)
            ->where('payload->ma_sl', $phien->ma_sl)
            ->latest('ma_aj')
            ->first();

        // Job trước kết thúc với thất bại và chưa hết số lần thử lại.
        if ($job !== null && $job->isFinished() && $phien->ai_briefing_status === 'failed' && $briefingAttempts < 2) {
            $phien->forceFill(['ai_briefing_status' => null])->save();
            $job = $this->aiJobs->dispatchBriefingIfEligible($phien);
        } elseif ($job === null) {
            $job = $this->aiJobs->dispatchBriefingIfEligible($phien);
        }

        // Job trong hàng đợi không ai nhận: không worker đang chạy, nên poll nó chỉ
        // ra thông báo "hiện chưa khả dụng". Viết briefing ngay trong
        // request này thay thế — trang kết quả đã tải một lần và tải lại khi
        // job đến, nên chi phí nằm ở lần vẽ đầu tiên, chứ không phải một lượt round-trip thêm.
        if ($job !== null && $job->isStranded()) {
            // Rủi chứ không sinh ở đây. Chạy bên trong request trang kết quả trên
            // dev server một process không có worker, nên generate() trực tiếp sẽ
            // giữ mọi request khác trong ~90s ngân sách nhà cung cấp. Trang render
            // không có briefing và pane hiện thông báo không khả dụng; poller
            // vẫn hoán đổi vào khi worker thực sự đến.
            $this->aiJobs->abandon($job, 'no worker picked the briefing up; result page loaded without it');

            // Ghi lần thử là thất bại, chứ không để cột null: cổng thử lại
            // phía trên chỉ mở khi trạng thái khác null, nên lần thử bỏ cuộc với
            // cột null không bao giờ chạm đến nó — phiên bị treo mãi mãi với
            // số lần thử lại còn ngân sách.
            if (app(SangLocAiTomTatService::class)->duDieuKien($phien)) {
                $phien->forceFill(['ai_briefing_status' => 'failed'])->save();
            }

            return null;
        }

        // Job hoàn thành với trạng thái null nghĩa là nó chết trước khi ghi; job
        // treo nghĩa là không ai tiêu thụ. Cả hai không đáng poll.
        return $job !== null && !$job->isFinished() && !$job->isStranded() ? $job : null;
    }

    /**
     * Briefing để render, với sản phẩm gợi ý kiểm tra lại với
     * danh mục và link được dựng ở đây.
     *
     * Đọc lại thay vì tin cột: sản phẩm có thể hết hàng hoặc hết hạn
     * giữa lần worker ghi briefing và lần trang được mở, và worker
     * không có request đến, nên không thể dựng URL sản phẩm mà không
     * cắm host APP_URL vào row đã lưu.
     *
     * @return array<string, mixed>|null
     */
    protected function briefingFor(PhienSangLoc $phien): ?array
    {
        $briefing = $phien->ai_briefing;

        if (!is_array($briefing) || $briefing === []) {
            return null;
        }

        $sanPhams = collect($briefing['products'] ?? []);

        if ($sanPhams->isEmpty()) {
            return $briefing;
        }

        $live = SanPham::whereIn('ma_sp', $sanPhams->pluck('id')->filter()->all())
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->get()
            ->keyBy('ma_sp');

        $briefing['products'] = $sanPhams
            ->filter(fn (array $row) => $live->has((int) ($row['id'] ?? 0)))
            ->map(function (array $row) use ($live) {
                $sanPham = $live[(int) $row['id']];

                return array_merge($row, [
                    'stock_quantity' => (int) $sanPham->so_luong_ton,
                    'price' => $sanPham->price_vnd,
                    'url' => route('san-pham.show', $sanPham->ma_sp),
                    'image_url' => $sanPham->image_url,
                ]);
            })
            ->values()
            ->all();

        return $briefing;
    }

    /**
     * Job viết lại vẫn đang làm việc với phiên này, nếu có.
     *
     * `ai_status` vẫn null cho đến khi bản viết lại xong, nên job còn trong hàng đợi là
     * tín hiệu duy nhất rằng có gì đang đến. Đánh dấu hoặc tải lại sau khi nó đến
     * sẽ không thấy job đang chờ và render trang đã hoàn thành.
     *
     * Job không ai nhận được rủi ở đây và viết lại ngay trong request này, cùng
     * cách briefing làm. Cả hai nửa phải đi cùng nhau: trang kết quả
     * tải lại khi thấy `stranded`, nên để job này nằm trong hàng đợi sẽ tải lại
     * mãi mãi, và giao cho poller sẽ giữ banner chờ mãi cho một worker
     * không bao giờ đến.
     */
    protected function pendingAdviceJob(PhienSangLoc $phien): ?AiJob
    {
        if ($phien->ai_status === 'ok') {
            return null;
        }

        $adviceAttempts = AiJob::where('ma_nd', $phien->ma_nd)
            ->where('kind', AiJob::KIND_TRIAGE_ADVICE)
            ->where('payload->ma_sl', $phien->ma_sl)
            ->count();

        if ($phien->ai_status !== null && $adviceAttempts >= 2) {
            return null;
        }

        $job = AiJob::where('ma_nd', $phien->ma_nd)
            ->where('kind', AiJob::KIND_TRIAGE_ADVICE)
            ->where('payload->ma_sl', $phien->ma_sl)
            ->latest('ma_aj')
            ->first();

        if ($job !== null && $job->isFinished() && $phien->ai_status === 'failed' && $adviceAttempts < 2) {
            $phien->forceFill(['ai_status' => null])->save();
            $job = $this->aiJobs->dispatchAdviceRewriteIfEligible($phien);
        }

        if ($job === null || !$job->isStranded()) {
            return $job !== null && in_array($job->status, ['queued', 'running'], true) ? $job : null;
        }

        // Cùng quy tắc với briefing: rủi chứ không viết lại ở đây. Chạy nhà cung cấp
        // ngay trong request này giữ dev server một process cho toàn bộ
        // timeout, nên trang render văn bản của dược sĩ và ngừng poll.
        $this->aiJobs->abandon($job, 'no worker picked the advice rewrite up; result page loaded without it');

        return null;
    }

    /**
     * Các chi tiết bộ quy tắc thực sự dùng, cho khối "vì sao có kết luận này".
     *
     * Chỉ đọc những gì đã lưu sẵn — không nội dung y khoa mới, không lần gọi
     * đánh giá. Mã triệu chứng được giải sang tên tiếng Việt vì một
     * khách hàng không đọc được `headache`.
     *
     * @return array<string, mixed>
     */
    protected function decisionBasis(PhienSangLoc $phien): array
    {
        $codes = array_values(array_filter((array) $phien->matched_symptoms));

        return [
            'symptoms' => $codes === []
                ? []
                : TrieuChungYTe::whereIn('code', $codes)->pluck('name_vi')->all(),
            'duration_days' => $phien->answerFor('duration_days'),
            'age' => data_get($phien->ai_meta, 'age'),
            'clarify_rounds' => (int) $phien->ai_clarify_rounds,
            'red_flags' => (array) $phien->triggered_red_flags,
        ];
    }

    /**
     * Cho mọi thuốc được gợi ý qua cùng kiểm tra an toàn thuốc giỏ hàng đang chạy.
     *
     * Wizard có thể gợi ý sản phẩm mà hồ sơ sức khỏe của chính khách hàng chỉ định
     * — ibuprofen khi mang thai, dị ứng aspirin — trong khi giỏ hàng chặn đúng thuốc đó
     * dùng sản phẩm đó một lần nhấn sau. Kiểm tra ở đây nghĩa là cảnh báo xuất hiện nơi
     * được gợi ý.
     *
     * Phủ cả lựa chọn của briefing AI cũng như bộ quy tắc: model chọn từ
     * danh mục và không biết gì về hồ sơ của khách hàng này, nên gợi ý của nó
     * cần kiểm tra hơn, chứ không phải ít hơn.
     *
     * @return array{warnings: array<int, array<string, mixed>>, flagged: array<int, int>}
     */
    protected function safetyCheck(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo): array
    {
        $productIds = collect($phien->suggested_products ?? [])
            ->concat((array) data_get($phien->ai_briefing, 'products', []))
            ->pluck('id')
            ->filter()
            ->unique()
            ->all();

        if ($hoSo === null || $productIds === []) {
            return ['warnings' => [], 'flagged' => []];
        }

        $warnings = [];
        $flagged = [];

        // Một truy vấn cho cả bộ, rồi kiểm tra trong bộ nhớ cho mỗi sản phẩm: AnToanThuocService
        // index bảng chống chỉ định thuốc và tương tác một lần mỗi request.
        foreach (SanPham::whereIn('ma_sp', $productIds)->get() as $sanPham) {
            $result = $this->anToanThuoc->kiemTraSanPham($sanPham, $hoSo, $phien->nguoiDung);

            if ($result['warnings'] === []) {
                continue;
            }

            $flagged[] = (int) $sanPham->ma_sp;
            $warnings = [...$warnings, ...$result['warnings']];
        }

        return [
            // Cùng một cảnh báo có thể đến từ hai sản phẩm dùng chung hoạt chất.
            'warnings' => collect($warnings)->unique(fn (array $w) => ($w['title'] ?? '') . '|' . ($w['message'] ?? ''))->values()->all(),
            'flagged' => $flagged,
        ];
    }

    public function history(Request $request): View
    {
        $cacPhien = $this->paginateOr404(
            PhienSangLoc::where('ma_nd', (int) auth()->id())
                ->where('status', '!=', 'in_progress')
                ->latest('ma_sl'),
            10
        );

        return view('health.history', ['sessions' => $cacPhien]);
    }

    /**
     * Bỏ phiên đang mở và bắt đầu lại.
     */
    public function restart(Request $request): RedirectResponse
    {
        PhienSangLoc::where('ma_nd', (int) auth()->id())->inProgress()->delete();

        return redirect()->route('sang-loc.kiem-tra');
    }

    /**
     * Không lặp lại thông báo phạm vi dạng popup cho phần còn lại của ngày lịch.
     *
     * Cache chứ không phải cột: việc này ẩn phần *nhấn mạnh*, chứ không phải thông báo — khối
     * `role="note"` vẫn giữ toàn bộ văn bản miễn trừ trên mỗi trang sàng lọc —
     * nên nó không mang trọng số tuân thủ và không đủ lý do để thêm schema. Cache cũng là
     * nơi lưu chung của ứng dụng cho key tạm thời theo người dùng (key completed-checkout của CheckoutController).
     * Một key bị đẩy ra thì popup hiện lại, đó là hướng an toàn khi lỗi.
     *
     * Theo người dùng chứ không phải cookie hay localStorage: trên tablet dùng chung tại cửa hàng,
     * việc một khách đóng thông báo không được ẩn thông báo của người đăng nhập tiếp theo.
     */
    public function ackDisclaimer(Request $request): RedirectResponse
    {
        // min:1 và ép kiểu tại ranh giới: `route('sang-loc.ket-qua', 0)` và `-5` tạo
        // URL chỉ 404 khi chạm đến, và `integer` nhận '+1' (filter_var đọc dấu
        // đầu) mà không chuyển đổi, điều đó sẽ tạo /health-check/result/+1. Chuỗi rỗng
        // không bao giờ chạm đến đây — middleware ConvertEmptyStringsToNull toàn cục biến
        // thành null, nên một hidden input lạc đầu rơi vào nhánh "không có MaSL".
        $validated = $request->validate([
            'ma_sl' => ['nullable', 'integer', 'min:1'],
        ]);

        Cache::put($this->disclaimerAckKey((int) auth()->id()), true, now()->addHours(25));

        // Route có tên cộng một số nguyên, không bao giờ là URL đưa lên: không có chỗ mở cho open-redirect,
        // và result() kiểm tra lại quyền sở hữu bằng firstOrFail() nên id giả mạo 404.
        return isset($validated['ma_sl'])
            ? redirect()->route('sang-loc.ket-qua', (int) $validated['ma_sl'])
            : redirect()->route('sang-loc.kiem-tra');
    }

    /**
     * Ngày lịch nằm trong key, chứ không nằm trong TTL.
     *
     * "Mỗi lần mỗi ngày" như vậy sống sót qua TTL sai, và không có phép tính hạn sử dụng để
     * sai gần nửa đêm — Cache::put với endOfDay đã qua sẽ trả false. Nhờ vậy
     * TTL 25h chỉ là việc dọn rác, một giờ quá thời gian dài nhất mà key còn có ý nghĩa.
     * today() dùng Asia/Ho_Chi_Minh theo config/app.php.
     */
    protected function disclaimerAckKey(int $userId): string
    {
        return "triage:disclaimer_ack:{$userId}:" . today()->toDateString();
    }

    /**
     * Có render popup hay không.
     *
     * Ba đầu vào, theo thứ tự: `?disclaimer=1` rõ ràng (link "Xem lại") ép mở;
     * `?disclaimer=0` rõ ràng ẩn nó chỉ cho URL này; còn lại nó đến mỗi
     * một lần theo ngày lịch.
     *
     * Trường hợp `0` tồn tại vì Escape và bấm scrim có nghĩa "để sau", chứ không phải
     * "đã hiểu", nên họ không được ghi cờ ngày — nhưng trang kết quả tải lại
     * chính nó khi một job AI poll đến, và triage.js tự điều hướng nữa. Nếu không có
     * marker trong URL, những lần tải lại đó sẽ mở lại hộp thoại và lấy lại focus từ
     * người vừa đóng nó. Lần truy cập mới không có marker nên hỏi lại.
     */
    protected function disclaimerPopupDue(Request $request, int $userId): bool
    {
        if ($request->has('disclaimer')) {
            return $request->boolean('disclaimer');
        }

        return !Cache::has($this->disclaimerAckKey($userId));
    }

    protected function profileFor(int $userId): ?HoSoSucKhoe
    {
        return HoSoSucKhoe::consentedFor($userId);
    }

    /**
     * Worker đang quyết định. Báo client chờ thay vì giữ request mở cho quá trình gọi
     * nhà cung cấp 11-30s.
     */
    protected function respondPending(Request $request, PhienSangLoc $phien, AiJob $job): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'mode' => 'deciding',
                'stage' => $this->renderStage($phien),
                'triage_pending' => [
                    'triage_session_id' => $phien->ma_sl,
                    'job_id' => $job->uuid,
                    'poll_url' => route('sang-loc.quyet-dinh', $job->uuid),
                ],
                'show_disclaimer' => true,
                'disclaimer' => SangLocYTeService::DISCLAIMER,
            ], 202);
        }

        return redirect()->route('sang-loc.kiem-tra');
    }

    /**
     * Tiến độ của quyết định, để trạng thái chờ poll.
     *
     * Giới hạn trong khách hàng đã đăng nhập: uuid là định danh công khai, không phải
     * token cấp quyền.
     */
    public function decision(Request $request, string $uuid): JsonResponse
    {
        $userId = (int) auth()->id();
        $job = AiJob::where('uuid', $uuid)
            ->where('ma_nd', $userId)
            ->where('kind', AiJob::KIND_TRIAGE_EVALUATION)
            ->first();

        if ($job === null) {
            return response()->json(['success' => false, 'status' => 'missing'], 404);
        }

        if ($job->status === 'done') {
            $target = ($job->result['next'] ?? null) === 'clarify' || empty($job->result['ma_sl'])
                ? route('sang-loc.kiem-tra')
                // Dựng ở đây, chứ không phải trong worker: route() trong job không có request đến
                // và sẽ giải host từ APP_URL.
                : route('sang-loc.ket-qua', $job->result['ma_sl']);

            return response()->json([
                'success' => true,
                'status' => 'done',
                'next' => $job->result['next'] ?? 'result',
                'redirect_url' => $target,
            ]);
        }

        if ($job->status === 'failed') {
            // Client tải lại /health-check, trang này quyết định trực tiếp.
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'redirect_url' => route('sang-loc.kiem-tra'),
            ]);
        }

        if ($job->isStranded()) {
            // Rủi ở đây cũng như trong pendingEvaluationJob(): phía nào client
            // chạm trước cũng phải dứt vòng lặp, nếu lần tải lại lại thấy cùng job trong hàng đợi.
            $this->aiJobs->abandon($job, 'no worker picked the decision up, decided in the request');

            return response()->json([
                'success' => true,
                'status' => 'stranded',
                'stranded' => true,
                'redirect_url' => route('sang-loc.kiem-tra'),
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $job->status,
            'stranded' => false,
        ]);
    }

    protected function respondQuestion(Request $request, PhienSangLoc $phien, array $question): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'mode' => 'question',
                'stage' => $this->renderStage($phien),
                'triage_question' => $question,
                // Chuẩn từ phía server: bước được hồ sơ che khuất nghĩa là bước đã render có thể
                // chạy trước current_step, nên client không được tự suy diễn cái này.
                'allow_back' => (int) $phien->current_step > 1,
                'previous_answer' => $this->triageService->cauTraLoiBaiTruoc($phien, (int) $question['step']),
                'answered_steps' => $this->answeredSteps($phien),
                'show_disclaimer' => true,
                'disclaimer' => SangLocYTeService::DISCLAIMER,
            ]);
        }

        return redirect()->route('sang-loc.kiem-tra');
    }

    protected function respondResult(Request $request, PhienSangLoc $phien): RedirectResponse|JsonResponse
    {
        $url = route('sang-loc.ket-qua', $phien->ma_sl);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                // Trang kết quả là một lần điều hướng thật, chứ không phải hoán đổi bước: render nó
                // phía client sẽ dần dần kéo đồ tương tác kết quả, kiểm tra an toàn thuốc và
                // thẻ sản phẩm vào JavaScript.
                'mode' => 'result',
                'triage_session_id' => $phien->ma_sl,
                'triage_result' => [
                    'outcome' => $phien->outcome,
                    'advice_given' => $phien->advice_given,
                    'safety_net' => $phien->safety_net,
                    'possible_condition_groups' => $phien->possible_condition_groups,
                    'triggered_red_flags' => $phien->triggered_red_flags,
                    'suggested_products' => $phien->suggested_products,
                ],
                'redirect_url' => $url,
                'show_disclaimer' => true,
                'disclaimer' => SangLocYTeService::DISCLAIMER,
            ]);
        }

        return redirect()->to($url);
    }

    /**
     * Vòng hỏi làm rõ AI được đề nghị ngay trên trang wizard, nên redirect
     * giữ nguyên dòng chảy form không JavaScript.
     */
    protected function respondClarify(Request $request, PhienSangLoc $phien): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'mode' => 'clarify',
                'stage' => $this->renderStage($phien),
                'triage_clarify' => [
                    'triage_session_id' => $phien->ma_sl,
                    'questions' => $phien->pendingClarifyQuestions(),
                    'round' => (int) $phien->ai_clarify_rounds,
                    'max_rounds' => PhienSangLoc::MAX_CLARIFY_ROUNDS,
                    'answered_steps' => $this->answeredSteps($phien),
                ],
                'show_disclaimer' => true,
                'disclaimer' => SangLocYTeService::DISCLAIMER,
            ]);
        }

        return redirect()->route('sang-loc.kiem-tra');
    }

    protected function respondValidationError(Request $request, string $tinNhan): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => $tinNhan,
                // Khớp đúng dạng mà $request->validate() trả về, nên client chỉ có một
                // đường code cho cả hai loại 422.
                'errors' => ['answer' => [$tinNhan]],
            ], 422);
        }

        return redirect()->route('sang-loc.kiem-tra')->withErrors(['answer' => $tinNhan]);
    }

    protected function respondExpired(Request $request): RedirectResponse|JsonResponse
    {
        $tinNhan = 'Phiên sàng lọc đã kết thúc hoặc không còn hiệu lực. Anh/chị vui lòng bắt đầu lại nhé.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => $tinNhan,
                // Không còn gì để render lại tại chỗ: client tải lại wizard.
                'redirect_url' => route('sang-loc.kiem-tra'),
            ], 410);
        }

        return redirect()->route('sang-loc.kiem-tra')->with('error', $tinNhan);
    }

    /**
     * Bước được nộp không phải bước đang hỏi — tab cũ, submit lại
     * form, hoặc request giả mạo. Đưa người dùng quay về câu hỏi hiện tại thật sự
     * thay vì ghi câu trả lời dưới sai key.
     */
    protected function respondStepMismatch(Request $request): RedirectResponse|JsonResponse
    {
        $tinNhan = 'Câu hỏi này đã chuyển sang bước khác. Anh/chị trả lời tiếp câu đang hiển thị giúp em nhé.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => $tinNhan,
                'redirect_url' => route('sang-loc.kiem-tra'),
            ], 409);
        }

        return redirect()->route('sang-loc.kiem-tra')->with('error', $tinNhan);
    }
}