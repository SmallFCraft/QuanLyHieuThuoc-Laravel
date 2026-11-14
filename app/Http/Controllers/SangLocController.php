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
 * Wizard sang loc y te.
 *
 * Chay duoc khi khong co JavaScript: moi buoc la mot form POST that dan sang buoc
 * ke tiep. Tang JS chi de tranh tai lai toan trang. Mot cong cu suc khoe phai
 * van chay duoc khi script gap loi.
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
     * Quyet dinh ket qua — trong worker neu co, truc tiep neu khong.
     *
     * `evaluate()` goi AI trich xuat chi tiet, va qua trinh do measured tai
     * 11-30s tren nha cung cap da cau hinh trong khi ngan sach dong bo la 8s. Vi vay
     * no gan nhu luon bi timeout, va mat ket noi khong chi ton do tre: no
     * lam mat chi tiet da trich xuat, nen moc thoi luong "may nam nay" duoc cham nhu
     * mot ngay, va vong hoi lam ro khong bao gio xuat hien vi shouldAskClarify() khong co
     * ket qua AI de doc. Do la bug "vong 1/3 roi nhay thang den ket qua".
     *
     * Tri hoan an toan o day — khac voi tri hoan sau finalize() — vi
     * benh nhan cho: khong gi duoc hien cho den khi job quyet dinh xong, nen ket luan
     * khong bao gio duoc sua sau khi ho da doc.
     *
     * Truong hop khan cap duoc loai tru va quyet dinh truc tiep: ket qua do khong can AI.
     *
     * Tren queue driver `sync`, job da chay xong khi dispatch tra ve,
     * vi vay suite test va cau hinh khong co worker van hanh nhu truoc.
     */
    protected function decideOutcome(Request $request, PhienSangLoc $phien): RedirectResponse|JsonResponse
    {
        // Khan cap khong bao gio vao hang doi. Co do duoc tim bang PHP thuan, khong AI, va
        // nguoi mo ta dau nguc phai thay so 115 trong phan hoi nay —
        // khong phai sau khi nhan job.
        if ($this->triageService->coCapCuuCuu($phien, $this->profileFor((int) $phien->ma_nd))) {
            return $this->decideOutcomeInline($request, $phien);
        }

        // Mot lan thu truoc cua phien nay da bi rui vi khong co worker.
        // Dua vao hang doi them lan nua se lap vo han: tre, rui, vao hang doi, tre — 12s moi chu ky,
        // mai mai. Mot lan that bai la du bang chung rang khong ai tieu thu job.
        if ($this->hasAbandonedEvaluation($phien)) {
            return $this->decideOutcomeInline($request, $phien);
        }

        try {
            $job = $this->aiJobs->dispatchEvaluation($phien);
        } catch (\Throwable $exception) {
            // Hang doi khong kha dung khong duoc de bo tron cau hoi da hoan thanh.
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
     * Chay quyet dinh ngay trong request nay. Dung khi khong co worker nhan viec.
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
     * Chot phien va dua hai seam AI sau quyet dinh vao hang doi.
     *
     * Ca hai bat dau *sau* finalize(), chi ghi cot `ai_*` cua chung, va khong the
     * thay doi ket qua — nen khac voi quyet dinh phia tren, chung co the den khi
     * benh nhan dang doc, va trang ket qua tu poll lay chung.
     *
     * @param array<string, mixed> $result Already-computed evaluate() output, so the
     *                                     quy tac (va buoc trich xuat AI neu co) chi chay mot lan.
     */
    protected function finalizeSession(PhienSangLoc $phien, array $result): PhienSangLoc
    {
        $phien = $this->triageService->hoanTat($phien, $result);

        $this->aiJobs->dispatchAdviceRewriteIfEligible($phien);
        $this->aiJobs->dispatchBriefingIfEligible($phien);

        return $phien->refresh();
    }

    /**
     * Du lieu view ma buoc wizard can, du duoc render thanh trang day du hay
     * hoan doi qua AJAX.
     *
     * @param array<string, mixed>|null $question
     * @return array<string, mixed>
     */
    protected function stageData(PhienSangLoc $phien, ?array $question, array $clarifyQuestions, ?AiJob $pendingJob, ?HoSoSucKhoe $hoSo): array
    {
        return [
            'session' => $phien,
            'question' => $question,
            //  Dien truoc de quay lai thay van ban cua nguoi dung, khong phai o trong.
            'previousAnswer' => $question
                ? $this->triageService->cauTraLoiBaiTruoc($phien, (int) $question['step'])
                : '',
            'hasProfile' => $hoSo !== null,
            'clarifyQuestions' => $clarifyQuestions,
            'clarifyRound' => (int) $phien->ai_clarify_rounds,
            'clarifyMaxRounds' => PhienSangLoc::MAX_CLARIFY_ROUNDS,
            // Khac null khi worker dang quyet dinh: buoc hien trang thai cho va
            // tu poll, thay vi giu request mo cho qua trinh goi AI 11-30s.
            'pendingJobId' => $pendingJob?->uuid,
            'answeredSteps' => $this->answeredSteps($phien),
        ];
    }

    /**
     * Markup cua buoc ma phien dang o buoc do.
     *
     * Tang AJAX nhan HTML chu khong phai model JSON la co chu y: dung lai cau hoi,
     * vong hoi lam roi van ban cho trong JavaScript se nhan ban
     * moi chuoi tieng Viet — gom cac nhan y — sang phia client,
     * dieu ma quy tac "khong co noi dung y khoa ben ngoai server" cam.
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
     * Buoc hien tai cua phien dang mo, hoac cau hoi dau tien cua phien moi.
     *
     * Khi khong con gi de hoi — ho so suc khoe da tra loi het, hoac mot
     * co do khan cap da dung cau hoi som — quyet dinh duoc dua ra tai day thay vi
     * hien mot trang kep cua.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $userId = (int) auth()->id();
        $phien = $this->triageService->batDauHoacTiepTuc($userId, session('chat_session_id'));
        $hoSo = $this->profileFor($userId);
        $question = $this->triageService->cauHoiTiep($phien, $hoSo);
        $clarifyQuestions = $this->pendingClarifyQuestions($phien);
        $pendingJob = $this->pendingEvaluationJob($phien);

        // Kiem tra truoc khi quyet dinh: vong dang cho nghia la cau hoi da
        // da luu, va job dang chay nghia la quyet dinh dang tien hanh — hoac
        // cach do, danh gia lai o day se ton them mot lan goi nha cung cap.
        if ($question === null
            && $clarifyQuestions === []
            && $pendingJob === null
            && !empty($phien->collected_data)
        ) {
            $decision = $this->decideOutcome($request, $phien);

            $phien = $phien->fresh();
            $pendingJob = $this->pendingEvaluationJob($phien);
            $clarifyQuestions = $this->pendingClarifyQuestions($phien);

            // Chi quyet dinh hoan thanh moi la mot lan dieu huong that. Job trong hang doi render
            // trang thai cho phia duoi va mot vong hoi moi render form cua no — ca hai
            // ma khong phai qua redirect quay lai chinh action nay.
            if ($pendingJob === null && $clarifyQuestions === []) {
                return $decision;
            }
        }

        return view('health.check', array_merge(
            $this->stageData($phien, $question, $clarifyQuestions, $pendingJob, $hoSo),
            [
                'disclaimer' => SangLocYTeService::DISCLAIMER,
                // Chi return cuoi cung. `return $decision` som o tren va stageData()
                // — chia se voi renderStage() — giu nguyen, nen khong lan hoan doi buoc AJAX nao co the
                // render lai popup giua chung wizard.
                'showDisclaimerPopup' => $this->disclaimerPopupDue($request, $userId),
            ]
        ));
    }

    /**
     * Job danh gia van dang quyet dinh ket qua phien nay, neu co.
     *
     * Gioi han trong `in_progress`: mot khi phien da chot, job la lich su, va
     * row cu khong duoc lam trang thai cho xuat hien tro lai.
     *
     * Job bi tre — van `queued` lau sau khi tao, nghia la khong worker nao tung
     * nhan no — duoc rui o day va bao cao la "khong co gi dang cho". Neu khong lam vay
     * trang se lap: tai lai thay job trong hang doi, render trang thai cho,
     * client poll, thay `stranded`, tai lai trang, roi lai thay cung job do.
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
     * Quyet dinh cho phien nay da bi bo cu vi khong co worker.
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
     * Cac cau tra loi da trinh bay, moi nhat o cuoi, cho duong dang chung minh phia tren
     * question.
     *
     * Chi doc nhung gi khach hang go — khong output quy tac, khong output AI — nen
     * wizard khong bao gio xem truoc ket luan ma no chua dat den.
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
     * Cac cau hoi AI da hoi ma khach hang chua tra loi hoac bo qua.
     *
     * @return array<int, string>
     */
    protected function pendingClarifyQuestions(PhienSangLoc $phien): array
    {
        if ($phien->status !== 'in_progress') {
            return [];
        }

        // recordClarifyAnswers() xoa danh sach khi vong duoc tra loi, nen
        // danh sach khac rong o day luon co nghia "vong nay van dang mo".
        return $phien->pendingClarifyQuestions();
    }

    /**
     * Ghi mot cau tra loi, roi hoac hoi cau tiep hoac chot phien.
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

        // Ep kieu tuong minh: quy tac `integer` xac minh kieu nhung khong tu chuyen doi,
        // nen $validated['step'] den la string va se that bai === voi
        // STEP_* constants.
        $step = (int) $validated['step'];
        $hoSo = $this->profileFor($userId);

        // Cau tra loi phai thuoc cau hoi ma phien dang o buoc do. Neu khong co
        // mot POST gia mau (step=5) se chot sang loc ma khong ghi trieu chung hay
        // thoi luong, va moi quy tac bao ve deu roi vao tu cham soc. So sanh voi
        // nextQuestion thay vi current_step vi buoc du ho so suc khoe che khuat
        // se bi bo qua, nen buoc da render co the dung truoc bien dem.
        $expected = $this->triageService->cauHoiTiep($phien, $hoSo);

        if ($expected === null || $step !== (int) $expected['step']) {
            return $this->respondStepMismatch($request);
        }

        $skipped = (bool) ($validated['skipped'] ?? false);
        // Lua chon quick-pick duoc nhan thang voi o nhap, ma nut Back dien truoc theo
        // cau tra loi da luu — nen lan nhan phai thay duoc no.
        //
        // Dieu do chi dung vi `option_answer` gio chi xuat hien KHI co mot lan nhan chu dich
        // chon: template buoc dat nut submit mac dinh khong ten len truoc, nen
        // submit ngam cua HTML (Enter trong o nhap) khong con kich hoat lua chon
        // quick-pick. Truoc do, Enter gui `option_answer=<lua chon dau>`
        // kem voi van ban go va lua chon thang — benh nhan da go mot
        // loi phan nan va wizard lai ghi them mot lan nua.
        // TriageTypedAnswerTest khang dinh bat bien do markup do; khong them mot nut
        // nut submit truoc no.
        $answer = trim((string) ($validated['option_answer'] ?? '')) !== ''
            ? trim((string) $validated['option_answer'])
            : trim((string) ($validated['answer'] ?? ''));

        // Buoc 1 la cau tra loi bat buoc duy nhat: khong co trieu chung thi khong co gi de sang loc.
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

            // Cau hoi hoan thanh. Quyet dinh — gom ca buoc AI — chay trong mot
            // worker, va benh nhan cho ket qua tu no.
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
     * Tra loi (hoac bo qua) mot vong hoi lam ro AI, roi hoac hoi vong tiep hoac
     * finalise.
     *
     * Cac action rieng chu khong phai buoc wizard them: TOTAL_STEPS giu nguyen 5,
     * thanh tien do giu dung y nghia, va moi vong deu co the bo qua.
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

        // Chi cham duoc khi mot vong thuc su dang mo.
        if ($phien->pendingClarifyQuestions() === []) {
            return $this->respondStepMismatch($request);
        }

        $skipped = $request->boolean('skipped');

        try {
            if ($skipped) {
                // Tu choi la ket thuc cuoc tro chuyen: khong vong nao duoc de nghi tiep.
                $phien = $this->triageService->boQuaCauHoiLamRo($phien);

                return $this->decideOutcome($request, $phien);
            }

            $phien = $this->triageService->ghiCacChamLamRo(
                $phien,
                (array) ($validated['clarify'] ?? [])
            );

            // Cau tra loi co the mo ra khoang truc moi — hoac dong lai chung. Model quyet dinh
            // qua needs_more_info, trong tran vong hoi, ben trong job.
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
     * Moi duoc si xem mot phien da hoan thanh.
     *
     * Kenhd phu tuy chon: cau tra loi AI/quy tac la ket qua chinh, chi dua nguoi vao khi
     * khach hang chu dong yeu cau.
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
     * Quay lai mot buoc ma khong mat cau tra loi da luu.
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
     * Phien hoan thanh co URL rieng de chia se, danh dau va in.
     */
    public function result(Request $request, int $MaSL): View
    {
        $userId = (int) auth()->id();
        $phien = PhienSangLoc::where('ma_nd', $userId)
            ->where('ma_sl', $MaSL)
            ->firstOrFail();

        abort_if($phien->status === 'in_progress', 404);

        $hoSo = $this->profileFor($userId);

        // Doc cot AI truoc: tren queue driver `sync`, job ma qua trinh goi nay
        // co the dispatch da chay xong va ghi cot, nen $phien neu khong lam lai
        // van giu row truoc khi dispatch.
        $briefingJob = $this->ensureBriefingJob($phien);
        $phien = $phien->refresh();

        return view('health.result', [
            'session' => $phien,
            'disclaimer' => SangLocYTeService::DISCLAIMER,
            // Khac null khi ban viet lai van dang duoc ghi. Tra cuu thay vi
            // flash, vi quyet dinh co the do worker thuc hien, ma worker
            // khong co session de flash vao.
            'aiJobId' => $this->pendingAdviceJob($phien)?->uuid,
            // Cung y tuong cho briefing chi tiet, qua trinh goi cham hon nhieu nen
            // duoc poll rieng: loi khuyen co the den truod minutes truoc no.
            'briefingJobId' => $briefingJob?->uuid,
            'briefing' => $this->briefingFor($phien),
            // Phien nay co du dieu kien co briefing khong, de trang phan biet
            // "khong bao gio due" (khan cap) voi "da thu nhung khong ra
            // gi" ma khong phai suy lai quy tac dieu kien trong Blade.
            'briefingExpected' => app(SangLocAiTomTatService::class)->duDieuKien($phien),
            // Cung ly do voi danh sach briefing: row da luu khong the giu URL anh
            // dung duoc, nen duoc giai o day tu san pham con song.
            'suggestedProducts' => $this->suggestedProductsFor($phien),
            // Khong bao gio truoc ket qua khan cap: nut tel:115 la hanh dong duy nhat
            // cua trang do va khong duoc chia man hinh voi popup co the bo qua,
            // thong bao. Thong bao gon van render — nhung dang inline,
            // vi khi popup da bi an thi "Xem lai" khong con gi de mo lai.
            'showDisclaimerPopup' => !$phien->isEmergency() && $this->disclaimerPopupDue($request, $userId),
            'disclaimerReviewable' => !$phien->isEmergency(),
            'hasProfile' => $hoSo !== null,
            'safety' => $this->safetyCheck($phien, $hoSo),
            'basis' => $this->decisionBasis($phien),
            'metrics' => $this->analysisMetrics($phien),
        ]);
    }

    /**
     * Khoi phan tich so tren trang ket qua: diem nguy co theo quy tac kem
     * per-factor contribution bars.
     *
     * Tinh chi tu cot da luu — bieu do phai render duoc
     * hoan toan khong AI, va moi con so briefing ghi qua `data_analysis` deu duoc kiem tra
     * voi cung bang nay.
     *
     * Diem la quy tac cham diem, khong phai xac suat: dai cua chinh ket qua lam moc
     * no (tu cham soc 0-24 … khan cap 75-100), nen con so khong bao gio tra loi nguoc voi
     * banner ket luan, va nhan van ban cung noi ro. Dong gop la trong so ma quy tac
     * yeu to thuc su mang — day la cho duy nhat tren trang ma nhung con so do
     * ton tai.
     *
     * @return array<string, mixed>|null null when the session has no outcome yet
     */
    protected function analysisMetrics(PhienSangLoc $phien): ?array
    {
        $rank = array_search((string) $phien->outcome, array_reverse(BaoCaoSangLocService::OUTCOMES), true);

        if ($rank === false) {
            return null;
        }

        // Moc dai + trong so la mot thang do chu dich, dat ngay can code ap dung no.
        // Nang mot trong so la quyet dinh lam sang, khong phai chinh sua nho.
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

        // Moi thu tren diem nen deu den tu yeu to do duoc, nen vi tri trong dai
        // ti le voi phan ma cac yeu to do thuc su mang. Nhan van ban
        // hien diem moc: truong hop tu cham soc doc thap du dieu kien con lai the nao.
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
            // Thanh cho moi thu: yeu to do ra 0 hien thanh track rong,
            // vi "co do = 0" LA mot ket qua do duoc, chu khong phai thieu du lieu.
            'contributions' => $contributions,
            'zero_contributions' => array_values(array_filter($contributions, fn (array $bar) => $bar['points'] === 0)),
        ];
    }

    /**
     * Panel briefing rieng, de trang ket qua hoan doi vao khi job hoan thanh.
     *
     * 202 trong khi worker van dang ghi, nen poller tiep tuc cho; 200 kem
     * panel sau khi ghi xong, hoac khi that bai ro rang. Phien khan cap
     * khong bao gio due briefing va nhan 404, va phien chua chot khong co trang ket qua
     * de lam pane cua no.
     *
     * Luoi cham soc di kem trong <template>: lua chon san pham cua briefing
     * chua biet khi trang moi ve lan dau, nen tab se giu hien
     * rule engine's list alone until a reload.
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

        // Job hoan thanh khong phai dang cho, du cot ghi gi. Worker chet
        // truoc khi ghi trang thai de ca hai null trong khi job la `done`; doc
        // pending chi dua vao cot se tra 202 cho moi lan poll tu do ve sau,
        // va poller lap den gioi han 90 lan thu truoc khi hien thong bao.
        $jobFinished = AiJob::where('ma_nd', $userId)
            ->where('kind', AiJob::KIND_TRIAGE_BRIEFING)
            ->where('payload->ma_sl', $MaSL)
            ->whereIn('status', ['done', 'failed', 'cancelled'])
            ->exists();

        $pending = $briefing === null && $phien->ai_briefing_status === null && ! $jobFinished;

        // San pham cham soc chi render khi briefing thuc su den: lan poll
        // dang cho bo body 202, va lan that bai roi vao thong bao khong kha dung.
        // Tinh snapshot cho moi lan poll se chay lai bo quy tac
        // picks 60+ times while waiting.
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
     * Cac san pham goi y cua ket qua, voi anh duoc giai o day.
     *
     * Chi lam moi anh: phan khach hang thay trong danh sach nay la
     * snapshot ma sang loc da chup, va doc lai ton hoac gia se thay lam
     * mot quyet dinh da luu. Anh khong the nam trong snapshot do vi bo quy tac
     * co the chay trong worker, noi URL asset se cam host cua APP_URL vao row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function suggestedProductsFor(PhienSangLoc $phien): array
    {
        // Closure chu khong phai chuoi 'is_array': Collection::filter truyen ($value, $key)
        // va is_array() nhan dung mot doi so, nen dang chuoi la 500.
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
     * Job briefing de poll, dua vao hang doi neu phien nay chua co.
     *
     * Cac phien chot truoc khi tinh nang ra doi, va phien ma lan dispatch gap
     * hang doi chet, khong job khong briefing — nen lan xem dau trang ket qua
     * trang dua no vao hang doi. Guard tren "khong ton tai row job briefing nao cho phien nay
     * ca", chu khong phai "khong ai dang chay": mot lan tre hoac that bai khong duoc
     * thu lai tai moi lan tai lai trang.
     */
    protected function ensureBriefingJob(PhienSangLoc $phien): ?AiJob
    {
        // Thanh cong khong bao gio chay lai. Lan that bai duoc thu lai mot lan neu job truoc
        // hoan thanh ma khong sinh briefing dung duoc.
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

        // Job truoc ket thuc voi that bai va chua het so lan thu lai.
        if ($job !== null && $job->isFinished() && $phien->ai_briefing_status === 'failed' && $briefingAttempts < 2) {
            $phien->forceFill(['ai_briefing_status' => null])->save();
            $job = $this->aiJobs->dispatchBriefingIfEligible($phien);
        } elseif ($job === null) {
            $job = $this->aiJobs->dispatchBriefingIfEligible($phien);
        }

        // Job trong hang doi khong ai nhan: khong worker dang chay, nen poll no chi
        // ra thong bao "hien chua kha dung". Viet briefing ngay
        // request nay thay the — trang ket qua da tai mot lan va tai lai khi
        // job den, nen chi phi nam o lan ve dau tien, chu khong phai mot lan luot them.
        if ($job !== null && $job->isStranded()) {
            // Rui chu khong sinh o day. Chay ben trong request trang ket qua tren
            // dev server mot process khong co worker, nen generate() truc tiep se
            // giu moi request khac trong ~90s ngan sach nha cung cap. Trang render
            // khong co briefing va pane hien thong bao khong kha dung; poller
            // van hoan doi vao khi worker thuc su den.
            $this->aiJobs->abandon($job, 'no worker picked the briefing up; result page loaded without it');

            // Ghi lan thu la that bai, chu khong de cot null: cua thu lai
            // phia tren chi mo khi trang thai khac null, nen lan thu bo cu voi
            // cot null khong bao gio cham den no — phien bi tre mai mai voi
            // so lan thu lai con ngan sach.
            if (app(SangLocAiTomTatService::class)->duDieuKien($phien)) {
                $phien->forceFill(['ai_briefing_status' => 'failed'])->save();
            }

            return null;
        }

        // Job hoan thanh voi trang thai null nghia la no chet truoc khi ghi; job
        // tre nghia la khong ai tieu thu. Ca hai khong dang poll.
        return $job !== null && !$job->isFinished() && !$job->isStranded() ? $job : null;
    }

    /**
     * Briefing de render, voi san pham goi y kiem tra lai voi
     * danh muc va link duoc dung o day.
     *
     * Doc lai thay vi tin cot: san pham co the het hang hoac het han
     * giu lan worker ghi briefing va lan trang duoc mo, va worker
     * khong co request den, nen khong the dung URL san pham ma khong
     * cam host APP_URL vao row da luu.
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
     * Job viet lai van dang lam viec voi phien nay, neu co.
     *
     * `ai_status` van null cho den khi ban viet lai xong, nen job con trong hang doi la
     * tin hieu duy nhat rang co gi dang den. Danh dau hoac tai lai sau khi no den
     * se khong thay job dang cho va render trang da hoan thanh.
     *
     * Job khong ai nhan duoc rui o day va viet lai ngay trong request nay, cung
     * cach briefing lam. Ca hai nua phai di cung nhau: trang ket qua
     * tai lai khi thay `stranded`, nen de job nay nam trong hang doi se tai lai
     * mai mai, va giao cho poller se giu banner cho mai cho mot worker
     * khong bao gio den.
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

        // Cung quy tac voi briefing: rui chu khong viet lai o day. Chay nha cung cap
        // ngay trong request nay giu dev server mot process cho toan bo
        // timeout, nen trang render van ban cua duoc si va ngung poll.
        $this->aiJobs->abandon($job, 'no worker picked the advice rewrite up; result page loaded without it');

        return null;
    }

    /**
     * Cac chi tiet bo quy tac thuc su dung, cho khoi "vi sao co ket luan nay".
     *
     * Chi doc nhung gi da luu san — khong noi dung y khoa moi, khong lan goi
     * danh gia. Ma trieu chung duoc giai sang ten tieng Viet vi mot
     * khach hang khong doc duoc `headache`.
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
     * Cho moi thuoc duoc goi y qua cung kiem tra an toan thuoc gio hang dang chay.
     *
     * Wizard co the goi y san pham ma ho so suc khoe cua chinh khach hang chi dinh
     * — ibuprofen khi mang thai, di ung aspirin — trong khi gio hang chan dung thu do
     * dung san pham do mot lan nhan sau. Kiem tra o day nghia la canh bao xuat hien noi
     * duoc goi y.
     *
     * Phu ca lua chon cua briefing AI cung nhu bo quy tac: model chon tu
     * danh muc va khong biet gi ve ho so cua khach hang nay, nen goi y cua no
     * can kiem tra hon, chu khong phai it hon.
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

        // Mot truy van cho ca bo, roi kiem tra trong bo nho cho moi san pham: AnToanThuocService
        // index bang chung chi dinh dinh thuoc va tuong tac mot lan moi request.
        foreach (SanPham::whereIn('ma_sp', $productIds)->get() as $sanPham) {
            $result = $this->anToanThuoc->kiemTraSanPham($sanPham, $hoSo, $phien->nguoiDung);

            if ($result['warnings'] === []) {
                continue;
            }

            $flagged[] = (int) $sanPham->ma_sp;
            $warnings = [...$warnings, ...$result['warnings']];
        }

        return [
            // Cung mot canh bao co the den tu hai san pham dung chung hoat chat.
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
     * Bo phien dang mo va bat dau lai.
     */
    public function restart(Request $request): RedirectResponse
    {
        PhienSangLoc::where('ma_nd', (int) auth()->id())->inProgress()->delete();

        return redirect()->route('sang-loc.kiem-tra');
    }

    /**
     * Khong lap lai thong bao pham vi dang popup cho phan con lai cua ngay lich.
     *
     * Cache chu khong phai cot: viec nay an phan *nhan manh*, chu khong phai thong bao — khoi
     * `role="note"` van giu toan bo van ban mien tru tren moi trang sang loc —
     * nen no khong mang trong so tuan thu va khong du ly do de them schema. Cache cung la
     * noi luu chung cua ung dung cho key tam thoi theo nguoi dung (key completed-checkout cua CheckoutController
     * bi day ra thi popup hien lai, do la huong an toan khi loi.
     *
     * Theo nguoi dung chu khong phai cookie hay localStorage: tren tablet dung chung tai cua hang,
     * viec mot khach dong thong bao khong duoc an thong bao cua nguoi dang nhap tiep theo.
     */
    public function ackDisclaimer(Request $request): RedirectResponse
    {
        // min:1 va ep kieu tai ranh gioi: `route('sang-loc.ket-qua', 0)` va `-5` tao
        // URL chi 404 khi cham den, va `integer` nhan '+1' (filter_var doc dau
        // dau) ma khong chuyen doi, dieu do se tao /health-check/result/+1. Chuoi rong
        // chuoi khong bao gio cham den day — middleware ConvertEmptyStringsToNull toan cuc bien
        // thanh null, nen mot hidden input lac dau lam roi vhanh "khong co MaSL".
        $validated = $request->validate([
            'ma_sl' => ['nullable', 'integer', 'min:1'],
        ]);

        Cache::put($this->disclaimerAckKey((int) auth()->id()), true, now()->addHours(25));

        // Route co ten cong mot so nguyen, khong bao gio URL do len: khong co cho mo cho open-redirect,
        // va result() kiem tra lai quyen so huu bang firstOrFail() nen id gia mau 404.
        return isset($validated['ma_sl'])
            ? redirect()->route('sang-loc.ket-qua', (int) $validated['ma_sl'])
            : redirect()->route('sang-loc.kiem-tra');
    }

    /**
     * Ngay lich nam trong key, chu khong nam trong TTL.
     *
     * "Mot lan mot ngay" nhu vay song sot qua TTL sai, va khong co phep tinh han su dung de
     * sai gan nua dem — Cache::put voi endOfDay da qua se tra false. Nho vay
     * TTL 25h chi la viec don rac, mot gio qua thoi gian dai nhat ma key con co y nghia.
     * today() dung Asia/Ho_Chi_Minh theo config/app.php.
     */
    protected function disclaimerAckKey(int $userId): string
    {
        return "triage:disclaimer_ack:{$userId}:" . today()->toDateString();
    }

    /**
     * Co render popup hay khong.
     *
     * Ba dau vao, theo thu tu: `?disclaimer=1` ro rang (link "Xem lai") ep mo;
     * `?disclaimer=0` ro rang an no chi cho URL nay; con lai no do moi
     * m lan theo ngay lich.
     *
     * Truong hop `0` ton tai vi Escape va bam scrim co nghia "de sau", chu khong phai
     * "da hieu", nen ho khong duoc ghi co ngay — nhung trang ket qua tai lai
     * chinh no khi mot job AI poll den, va triage.js tu dieu huong nua. Neu khong co
     * marker trong URL, nhung lan tai lai do se mo lai hop thoai va lay lai focus tu
     * nguoi vua dong no. Lan truy cap moi khong co marker nen hoi lai.
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
     * Worker dang quyet dinh. Bao client cho thay vi giu request mo cho qua trinh goi
     * nha cung cap 11-30s.
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
     * Tien do cua quyet dinh, de trang thai cho poll.
     *
     * Gioi han trong khach hang da dang nhap: uuid la dinh danh cong khai, khong phai
     * capability token.
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
                // Dung o day, chu khong phai trong worker: route() trong job khong co request den
                // va se giai host tu APP_URL.
                : route('sang-loc.ket-qua', $job->result['ma_sl']);

            return response()->json([
                'success' => true,
                'status' => 'done',
                'next' => $job->result['next'] ?? 'result',
                'redirect_url' => $target,
            ]);
        }

        if ($job->status === 'failed') {
            // Client tai lai /health-check, trang nay quyet dinh truc tiep.
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'redirect_url' => route('sang-loc.kiem-tra'),
            ]);
        }

        if ($job->isStranded()) {
            // Rui o day cung nhu trong pendingEvaluationJob(): phia nao client
            // cham truoc cung phai dut vong lap, neu lan tai lai lai thay cung job trong hang doi.
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
                // Chuan tu phia server: buoc du ho so che khuat nghia la buoc da render co the
                // chay truoc current_step, nen client khong duoc tu suy dien cai nay.
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
                // Trang ket qua la mot lan dieu huong that, chu khong phai hoan doi buoc: render no
                // phan client se dan den phan do ket qua tuong tac, kiem tra an toan thuoc va
                // the san pham vao JavaScript.
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
     * Vong hoi lam ro AI duoc de nghi ngay tren trang wizard, nen redirect
     * redirect giu nguyen luong form khong JavaScript.
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
                // Khop dung dang ma $request->validate() tra ve, nen client chi co mot
                // duong code cho ca hai loai 422.
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
                // Khong con gi de render lai tai cho: client tai lai wizard.
                'redirect_url' => route('sang-loc.kiem-tra'),
            ], 410);
        }

        return redirect()->route('sang-loc.kiem-tra')->with('error', $tinNhan);
    }

    /**
     * Buoc do len khong phai buoc dang hoi — tab cu, submit lai
     * form, hoac request gia mau. Dua nguoi dung quay ve cau hoi hien tai thuc su
     * thay vi ghi cau tra loi duoi sai key.
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