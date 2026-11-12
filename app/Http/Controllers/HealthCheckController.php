<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use App\Models\TrieuChungYTe;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\HoSoSucKhoe;
use App\Services\AiJobService;
use App\Services\DrugSafetyService;
use App\Services\Triage\MedicalTriageService;
use App\Services\Triage\TriageReportService;
use App\Services\Triage\Ai\TriageAiAdviceService;
use App\Services\Triage\Ai\TriageAiBriefingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Symptom triage wizard.
 *
 * Works without JavaScript: each step is a real form POST that redirects to the
 * next step. The JS layer only avoids the full page reload. A health tool must
 * keep working when scripts fail.
 */
class HealthCheckController extends Controller
{
    public function __construct(
        protected MedicalTriageService $triageService,
        protected TriageAiAdviceService $adviceService,
        protected AiJobService $aiJobs,
        protected DrugSafetyService $anToanThuoc,
    ) {
    }

    /**
     * Decide the outcome — in a worker when one is available, inline when not.
     *
     * `evaluate()` makes the AI fact-extraction call, and that call was measured at
     * 11-30s against the configured provider while the synchronous budget was 8s. It
     * therefore almost always timed out, and a timeout does not just cost latency: it
     * drops the extracted facts, so a stated duration of "mấy năm nay" scored as one
     * day, and the follow-up round never appeared because shouldAskClarify() had no AI
     * result to read. That is the "vòng 1/3 then straight to the result" bug.
     *
     * Deferring is safe here — unlike deferring it after finalize() would be — because
     * the patient waits: nothing is shown until the job has decided, so the conclusion
     * is never revised after they have read it.
     *
     * Emergencies are excluded and decided inline: that outcome needs no AI at all.
     *
     * On the `sync` queue driver the job has already finished when dispatch returns,
     * which is why the test suite and a worker-less install behave as before.
     */
    protected function decideOutcome(Request $request, PhienSangLoc $phien): RedirectResponse|JsonResponse
    {
        // Emergency never queues. The red flag is found in plain PHP with no AI, and
        // somebody describing chest pain must see the 115 number in this response —
        // not after a worker picks the job up.
        if ($this->triageService->hasEmergencyRedFlag($phien, $this->profileFor((int) $phien->ma_nd))) {
            return $this->decideOutcomeInline($request, $phien);
        }

        // A previous attempt for this session was already retired for want of a worker.
        // Queueing again would loop: strand, retire, queue, strand — 12s per cycle,
        // forever. One failed attempt is enough evidence that nobody is consuming.
        if ($this->hasAbandonedEvaluation($phien)) {
            return $this->decideOutcomeInline($request, $phien);
        }

        try {
            $job = $this->aiJobs->dispatchEvaluation($phien);
        } catch (\Throwable $exception) {
            // An unavailable queue must never strand a finished questionnaire.
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
     * Run the decision in this request. Used when there is no worker to take it.
     */
    protected function decideOutcomeInline(Request $request, PhienSangLoc $phien): RedirectResponse|JsonResponse
    {
        $result = $this->triageService->evaluate($phien, $this->profileFor((int) $phien->ma_nd));

        if ($this->triageService->shouldAskClarify($phien, $result)) {
            return $this->respondClarify(
                $request,
                $this->triageService->storeClarifyQuestions($phien, $result)
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
     * Finalise a session and queue the two post-decision AI seams.
     *
     * Both start *after* finalize(), write only their own `ai_*` columns, and cannot
     * change the outcome — so unlike the decision above they may land while the
     * patient is already reading, and the result page polls for them.
     *
     * @param array<string, mixed> $result Already-computed evaluate() output, so the
     *                                     rules (and any AI extraction) run once.
     */
    protected function finalizeSession(PhienSangLoc $phien, array $result): PhienSangLoc
    {
        $phien = $this->triageService->finalize($phien, $result);

        $this->aiJobs->dispatchAdviceRewriteIfEligible($phien);
        $this->aiJobs->dispatchBriefingIfEligible($phien);

        return $phien->refresh();
    }

    /**
     * The view data the wizard stage needs, whether it is rendered as a full page or
     * swapped in over AJAX.
     *
     * @param array<string, mixed>|null $question
     * @return array<string, mixed>
     */
    protected function stageData(PhienSangLoc $phien, ?array $question, array $clarifyQuestions, ?AiJob $pendingJob, ?HoSoSucKhoe $hoSo): array
    {
        return [
            'session' => $phien,
            'question' => $question,
            // Pre-fill so going back shows the user's own words, not a blank box.
            'previousAnswer' => $question
                ? $this->triageService->previousAnswerFor($phien, (int) $question['step'])
                : '',
            'hasProfile' => $hoSo !== null,
            'clarifyQuestions' => $clarifyQuestions,
            'clarifyRound' => (int) $phien->ai_clarify_rounds,
            'clarifyMaxRounds' => PhienSangLoc::MAX_CLARIFY_ROUNDS,
            // Non-null while a worker is deciding: the stage shows a waiting state and
            // polls, instead of holding the request open for the 11-30s AI call.
            'pendingJobId' => $pendingJob?->uuid,
            'answeredSteps' => $this->answeredSteps($phien),
        ];
    }

    /**
     * The stage markup for whatever the session is currently on.
     *
     * The AJAX layer receives HTML rather than a JSON model on purpose: rebuilding the
     * question, the follow-up round and the waiting copy in JavaScript would duplicate
     * every Vietnamese string — and the medical labels among them — into the client,
     * which is exactly what the "no medical content outside the server" rule forbids.
     */
    protected function renderStage(PhienSangLoc $phien): string
    {
        $phien = $phien->fresh();
        $hoSo = $this->profileFor((int) $phien->ma_nd);

        return view('health.partials.stage', $this->stageData(
            $phien,
            $phien->status === 'in_progress'
                ? $this->triageService->nextQuestion($phien, $hoSo)
                : null,
            $this->pendingClarifyQuestions($phien),
            $this->pendingEvaluationJob($phien),
            $hoSo
        ))->render();
    }

    /**
     * Current step of the open session, or the first question of a new one.
     *
     * When there is nothing left to ask — the profile answered everything, or an
     * emergency red flag ended questioning early — the decision is taken here rather
     * than showing a dead-end page.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $userId = (int) auth()->id();
        $phien = $this->triageService->startOrResume($userId, session('chat_session_id'));
        $hoSo = $this->profileFor($userId);
        $question = $this->triageService->nextQuestion($phien, $hoSo);
        $clarifyQuestions = $this->pendingClarifyQuestions($phien);
        $pendingJob = $this->pendingEvaluationJob($phien);

        // Checked before deciding: a pending round means the questions are already
        // stored, and a running job means the decision is already under way — either
        // way, re-evaluating here would spend a second provider call.
        if ($question === null
            && $clarifyQuestions === []
            && $pendingJob === null
            && !empty($phien->collected_data)
        ) {
            $decision = $this->decideOutcome($request, $phien);

            $phien = $phien->fresh();
            $pendingJob = $this->pendingEvaluationJob($phien);
            $clarifyQuestions = $this->pendingClarifyQuestions($phien);

            // Only a finished decision is a real navigation. A queued job renders the
            // waiting state below and a fresh follow-up round renders its form — both
            // without bouncing through a redirect back to this same action.
            if ($pendingJob === null && $clarifyQuestions === []) {
                return $decision;
            }
        }

        return view('health.check', array_merge(
            $this->stageData($phien, $question, $clarifyQuestions, $pendingJob, $hoSo),
            [
                'disclaimer' => MedicalTriageService::DISCLAIMER,
                // Terminal return only. The early `return $decision` above and stageData()
                // — shared with renderStage() — stay untouched, so no AJAX stage swap can
                // re-render the popup mid-wizard.
                'showDisclaimerPopup' => $this->disclaimerPopupDue($request, $userId),
            ]
        ));
    }

    /**
     * The evaluation job still deciding this session's outcome, if any.
     *
     * Scoped to `in_progress`: once the session is finalised the job is history, and a
     * stale row must not make the waiting state reappear.
     *
     * A stranded job — still `queued` long after it was created, so no worker ever
     * picked it up — is retired here and reported as "nothing pending". Without that
     * the page loops: reload finds the queued job, renders the waiting state, the
     * client polls, sees `stranded`, reloads, and finds the same job again.
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
     * A decision for this session was already given up on for want of a worker.
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
     * The answers given so far, newest last, for the evidence trail above the current
     * question.
     *
     * Reads only what the customer typed — no rule output, no AI output — so the
     * wizard never previews a conclusion it has not reached.
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
     * Questions the AI asked that the customer has not answered or skipped yet.
     *
     * @return array<int, string>
     */
    protected function pendingClarifyQuestions(PhienSangLoc $phien): array
    {
        if ($phien->status !== 'in_progress') {
            return [];
        }

        // recordClarifyAnswers() clears the list once the round is answered, so a
        // non-empty list here always means "this round is still open".
        return $phien->pendingClarifyQuestions();
    }

    /**
     * Record one answer, then either ask the next question or finalise.
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

        // Cast explicitly: the `integer` rule validates the type but does not coerce,
        // so $validated['step'] arrives as a string and would fail === against the
        // STEP_* constants.
        $step = (int) $validated['step'];
        $hoSo = $this->profileFor($userId);

        // The answer must belong to the question the session is actually on. Without
        // this a single crafted POST (step=5) finalises the triage with no symptom or
        // duration recorded, and every guard rule falls through to self-care. Compared
        // against nextQuestion rather than current_step because a profile-covered step
        // is skipped, so the rendered step can be ahead of the counter.
        $expected = $this->triageService->nextQuestion($phien, $hoSo);

        if ($expected === null || $step !== (int) $expected['step']) {
            return $this->respondStepMismatch($request);
        }

        $skipped = (bool) ($validated['skipped'] ?? false);
        // A clicked quick-pick option wins over the box, which Back pre-fills with the
        // stored answer — so the click must be able to replace it.
        //
        // That only holds because `option_answer` is now present ONLY on a deliberate
        // click: the stage template puts an unnamed default submit button first, so
        // HTML implicit submission (Enter in the text box) no longer activates a
        // quick-pick option. Before that, Enter posted `option_answer=<first option>`
        // together with the typed text and the option won — the patient typed one
        // complaint and the wizard recorded another.
        // TriageTypedAnswerTest asserts that markup invariant; do not add a named
        // submit button ahead of it.
        $answer = trim((string) ($validated['option_answer'] ?? '')) !== ''
            ? trim((string) $validated['option_answer'])
            : trim((string) ($validated['answer'] ?? ''));

        // Step 1 is the only mandatory answer: without a symptom there is nothing to triage.
        if (!$skipped && $answer === '') {
            if ($step === MedicalTriageService::STEP_SYMPTOM) {
                return $this->respondValidationError(
                    $request,
                    'Anh/chị vui lòng mô tả triệu chứng để em sàng lọc giúp nhé.'
                );
            }

            $skipped = true;
        }

        try {
            $phien = $this->triageService->recordAnswer($phien, $step, $answer, $skipped);
            $question = $this->triageService->nextQuestion($phien, $hoSo);

            if ($question !== null) {
                return $this->respondQuestion($request, $phien, $question);
            }

            // Questionnaire complete. The decision — including the AI hop — runs in a
            // worker, and the patient waits on it.
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
     * Answer (or skip) one AI follow-up round, then either ask the next round or
     * finalise.
     *
     * Separate actions rather than extra wizard steps: TOTAL_STEPS stays 5, the
     * progress bar keeps its meaning, and every round is skippable.
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

        // Only reachable while a round is actually open.
        if ($phien->pendingClarifyQuestions() === []) {
            return $this->respondStepMismatch($request);
        }

        $skipped = $request->boolean('skipped');

        try {
            if ($skipped) {
                // Declining ends the conversation: no further round may be offered.
                $phien = $this->triageService->skipClarifyRounds($phien);

                return $this->decideOutcome($request, $phien);
            }

            $phien = $this->triageService->recordClarifyAnswers(
                $phien,
                (array) ($validated['clarify'] ?? [])
            );

            // The answers may have raised new gaps — or closed them. The model decides
            // via needs_more_info, within the round ceiling, inside the job.
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
     * Ask a pharmacist to look at a finished session.
     *
     * Opt-in side channel: the AI/rule answer is the primary result, and a human is
     * brought in only when the customer asks for one.
     */
    public function requestPharmacist(Request $request, int $MaSL): RedirectResponse
    {
        $phien = PhienSangLoc::where('ma_nd', (int) auth()->id())
            ->where('ma_sl', $MaSL)
            ->firstOrFail();

        $moved = $this->triageService->requestPharmacistReview($phien);

        return redirect()
            ->route('health.result', $MaSL)
            ->with('success', __(
                $moved
                    ? 'messages.health.pharmacist_requested'
                    : 'messages.health.pharmacist_request_unavailable'
            ));
    }

    /**
     * Go back one step without losing the stored answers.
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

        return redirect()->route('health.check');
    }

    /**
     * A finished session has its own URL so it can be shared, bookmarked and printed.
     */
    public function result(Request $request, int $MaSL): View
    {
        $userId = (int) auth()->id();
        $phien = PhienSangLoc::where('ma_nd', $userId)
            ->where('ma_sl', $MaSL)
            ->firstOrFail();

        abort_if($phien->status === 'in_progress', 404);

        $hoSo = $this->profileFor($userId);

        // Before reading the AI columns: on the `sync` queue driver the job this call
        // may dispatch has already finished and written them, and $phien would
        // otherwise still hold the pre-dispatch row.
        $briefingJob = $this->ensureBriefingJob($phien);
        $phien = $phien->refresh();

        return view('health.result', [
            'session' => $phien,
            'disclaimer' => MedicalTriageService::DISCLAIMER,
            // Non-null while the rewrite is still being written. Looked up rather than
            // flashed, because the decision may have been taken by a worker, which has
            // no session to flash into.
            'aiJobId' => $this->pendingAdviceJob($phien)?->uuid,
            // Same idea for the detailed briefing, which is a much slower call and so
            // is polled separately: the advice can land minutes before it.
            'briefingJobId' => $briefingJob?->uuid,
            'briefing' => $this->briefingFor($phien),
            // Whether this session should have a briefing at all, so the page can tell
            // "none was ever due" (emergency) from "it was attempted and produced
            // nothing" without re-deriving the eligibility rule in Blade.
            'briefingExpected' => app(TriageAiBriefingService::class)->isEligible($phien),
            // Same reason as the briefing list: the stored row cannot hold a usable
            // image URL, so it is resolved here from the live product.
            'suggestedProducts' => $this->suggestedProductsFor($phien),
            // Never in front of an emergency outcome: the tel:115 button is the only
            // action on that page and must not share the viewport with a dismissible
            // notice. The compact notice itself still renders — but in its inline form,
            // since with the popup suppressed there is nothing for "Xem lại" to reopen.
            'showDisclaimerPopup' => !$phien->isEmergency() && $this->disclaimerPopupDue($request, $userId),
            'disclaimerReviewable' => !$phien->isEmergency(),
            'hasProfile' => $hoSo !== null,
            'safety' => $this->safetyCheck($phien, $hoSo),
            'basis' => $this->decisionBasis($phien),
            'metrics' => $this->analysisMetrics($phien),
        ]);
    }

    /**
     * The result page's numeric analysis block: a rule-based risk score with its
     * per-factor contribution bars.
     *
     * Computed from persisted columns only — the chart must be able to render with
     * no AI at all, and every number the briefing's `data_analysis` writes is checked
     * against this same table.
     *
     * The score is a scoring rule, not a probability: the outcome's own band anchors
     * it (self-care 0-24 … emergency 75-100), so the number can never contradict the
     * conclusion banner, and the label says so. Contributions are the weights the
     * factors actually carried — this is the only place on the page those numbers
     * exist.
     *
     * @return array<string, mixed>|null null when the session has no outcome yet
     */
    protected function analysisMetrics(PhienSangLoc $phien): ?array
    {
        $rank = array_search((string) $phien->outcome, array_reverse(TriageReportService::OUTCOMES), true);

        if ($rank === false) {
            return null;
        }

        // Band anchor + weights are one deliberate scale, kept next to the code that
        // applies it. Raising a weight is a clinical decision, not a tweak.
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

        // Everything above the base came from measurable factors, so the position in
        // the band is proportional to what those factors actually carried. The label
        // shows the anchor reading: a self-care case reads low whatever the spread.
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
            // Bars for everything: a factor that read zero shows as an empty track,
            // because "cờ đỏ = 0" IS a measured result, not an absence of data.
            'contributions' => $contributions,
            'zero_contributions' => array_values(array_filter($contributions, fn (array $bar) => $bar['points'] === 0)),
        ];
    }

    /**
     * The briefing panel on its own, for the result page to swap in when the job lands.
     *
     * 202 while the worker is still writing, so the poller keeps waiting; 200 with the
     * panel once it is written, or once it has definitively failed. Emergency sessions
     * are never due a briefing and get 404, and an unfinished session has no result
     * page to be a pane of.
     *
     * The care grid rides along in a <template>: the briefing's own product picks are
     * unknown when the page first paints, so the tab would otherwise keep showing the
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

        // A finished job is not pending, whatever the columns say. A worker that died
        // before recording its status leaves both null while the job is `done`; reading
        // pending off the columns alone answered 202 to every poll tick from then on,
        // and the poller looped to its 90-attempt cap before showing the notice.
        $jobFinished = AiJob::where('ma_nd', $userId)
            ->where('kind', AiJob::KIND_TRIAGE_BRIEFING)
            ->where('payload->ma_sl', $MaSL)
            ->whereIn('status', ['done', 'failed', 'cancelled'])
            ->exists();

        $pending = $briefing === null && $phien->ai_briefing_status === null && ! $jobFinished;

        // Care products only render when the briefing actually arrived: a pending
        // poll discards the 202 body, and a failed attempt drops to the unavailable
        // notice. Calculating the snapshot for every poll tick would re-run the rule
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
     * The suggested products of the outcome, with their image resolved here.
     *
     * Only the image is refreshed: what the customer sees in this list is the
     * snapshot the triage took, and re-reading stock or price would silently change
     * a stored decision. The image cannot be part of that snapshot because the rule
     * engine may run in a worker, where an asset URL would bake in APP_URL's host.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function suggestedProductsFor(PhienSangLoc $phien): array
    {
        // Closure, not the 'is_array' string: Collection::filter passes ($value, $key)
        // and is_array() takes exactly one argument, so the string form is a 500.
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
     * The briefing job to poll, queueing one if this session never got one.
     *
     * Sessions finalised before the feature existed, and sessions whose dispatch hit
     * a dead queue, have no job and no briefing — so the first view of the result
     * page queues it. Guarded on "no briefing job row exists for this session at
     * all", not on "none is running": a stranded or failed attempt must not be
     * retried on every reload.
     */
    protected function ensureBriefingJob(PhienSangLoc $phien): ?AiJob
    {
        // Success never reruns. A failed attempt is retried once if the previous job
        // completed without producing a usable briefing.
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

        // Previous job finished with failure and we have not exhausted retries.
        if ($job !== null && $job->isFinished() && $phien->ai_briefing_status === 'failed' && $briefingAttempts < 2) {
            $phien->forceFill(['ai_briefing_status' => null])->save();
            $job = $this->aiJobs->dispatchBriefingIfEligible($phien);
        } elseif ($job === null) {
            $job = $this->aiJobs->dispatchBriefingIfEligible($phien);
        }

        // A queued job nobody picked up: no worker is running, so polling it would
        // only produce the "not available this time" notice. Write the briefing in
        // this request instead — the result page already loads once and reloads when
        // a job lands, so the cost is the first paint, not a second round trip.
        if ($job !== null && $job->isStranded()) {
            // Retire, never generate here. This runs inside the result-page request on a
            // single-process dev server with no queue worker, so an inline generate()
            // holds every other request for the ~90s provider budget. The page renders
            // without a briefing and the pane shows the unavailable notice; the poller
            // still swaps one in when a worker does land.
            $this->aiJobs->abandon($job, 'no worker picked the briefing up; result page loaded without it');

            // Record the attempt as failed, not leave the column null: the retry gate
            // above only opens on a non-null status, so an abandoned attempt with a
            // null column never reached it — the session was stranded forever with a
            // retry still in budget.
            if (app(TriageAiBriefingService::class)->isEligible($phien)) {
                $phien->forceFill(['ai_briefing_status' => 'failed'])->save();
            }

            return null;
        }

        // A finished job with a null status means it died before recording one; a
        // stranded one means nobody is consuming. Neither is worth polling.
        return $job !== null && !$job->isFinished() && !$job->isStranded() ? $job : null;
    }

    /**
     * The briefing to render, with its suggested products re-checked against the
     * catalogue and their links built here.
     *
     * Re-read rather than trusted from the column: a product can sell out or expire
     * between the worker writing the briefing and the page being opened, and the
     * worker has no incoming request, so it cannot build a product URL without
     * baking APP_URL's host into the stored row.
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
                    'url' => route('products.show', $sanPham->ma_sp),
                    'image_url' => $sanPham->image_url,
                ]);
            })
            ->values()
            ->all();

        return $briefing;
    }

    /**
     * The rewrite job still working on this session, if any.
     *
     * `ai_status` stays null until the rewrite finishes, so a still-queued job is the
     * only signal that something is coming. Bookmarking or reloading after it lands
     * finds no pending job and renders the finished page.
     *
     * A job nobody picked up is retired here and rewritten in this request, the same
     * way the briefing does it. Both halves are needed together: the result page
     * reloads when it sees `stranded`, so leaving this job queued would reload
     * forever, and leaving it to the poller would keep showing the waiting banner for
     * a worker that is never coming.
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

        // Same rule as the briefing: retire, never rewrite here. Running the provider
        // call inside this request holds the single-process dev server for its whole
        // timeout, so the page renders the pharmacist's text and stops polling.
        $this->aiJobs->abandon($job, 'no worker picked the advice rewrite up; result page loaded without it');

        return null;
    }

    /**
     * The facts the rule engine actually used, for a "why this conclusion" block.
     *
     * Reads only what is already persisted — no new medical content, no second
     * evaluation. Symptom codes are resolved to their Vietnamese names because a
     * customer cannot read `headache`.
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
     * Run every suggested medicine through the same drug-safety check the cart runs.
     *
     * The wizard could recommend a product the customer's own profile contraindicates
     * — ibuprofen during pregnancy, an aspirin allergy — while the cart blocked that
     * exact product one click later. Checking here means the warning appears where the
     * suggestion is made.
     *
     * Covers the AI briefing's picks as well as the rule engine's: the model chooses
     * from the catalogue and knows nothing about this customer's profile, so its
     * suggestions need the check more, not less.
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

        // One query for the set, then an in-memory check per product: DrugSafetyService
        // indexes the contraindication and interaction tables once per request.
        foreach (SanPham::whereIn('ma_sp', $productIds)->get() as $sanPham) {
            $result = $this->anToanThuoc->checkProduct($sanPham, $hoSo, $phien->nguoiDung);

            if ($result['warnings'] === []) {
                continue;
            }

            $flagged[] = (int) $sanPham->ma_sp;
            $warnings = [...$warnings, ...$result['warnings']];
        }

        return [
            // Same warning may come from two products sharing an ingredient.
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
     * Abandon the open session and start over.
     */
    public function restart(Request $request): RedirectResponse
    {
        PhienSangLoc::where('ma_nd', (int) auth()->id())->inProgress()->delete();

        return redirect()->route('health.check');
    }

    /**
     * Stop repeating the scope notice as a popup for the rest of the calendar day.
     *
     * Cache, not a column: this hides the *emphasis*, not the notice — the compact
     * `role="note"` block still carries the full disclaimer text on every triage page —
     * so it has no compliance weight and does not justify schema. Cache is also the
     * house store for per-user transient keys (CheckoutController's completed-checkout
     * key). An evicted key re-shows the popup, which is the safe direction to fail.
     *
     * Per-user rather than a cookie or localStorage: on a shared in-store tablet one
     * customer's dismissal must not suppress the notice for the next person's login.
     */
    public function ackDisclaimer(Request $request): RedirectResponse
    {
        // min:1 and a cast at the boundary: `route('health.result', 0)` and `-5` generate
        // URLs that only 404 on arrival, and `integer` accepts '+1' (filter_var parses the
        // sign) without coercing it, which would build /health-check/result/+1. An empty
        // string never reaches here — the global ConvertEmptyStringsToNull middleware makes
        // it null, so a stray hidden input takes the "no MaSL" branch.
        $validated = $request->validate([
            'ma_sl' => ['nullable', 'integer', 'min:1'],
        ]);

        Cache::put($this->disclaimerAckKey((int) auth()->id()), true, now()->addHours(25));

        // A named route plus an integer, never a submitted URL: no open-redirect surface,
        // and result() re-checks ownership with firstOrFail() so a forged id 404s.
        return isset($validated['ma_sl'])
            ? redirect()->route('health.result', (int) $validated['ma_sl'])
            : redirect()->route('health.check');
    }

    /**
     * The calendar day lives in the key, not in the TTL.
     *
     * "Once a day" then survives a wrong TTL, and there is no expiry arithmetic to get
     * wrong near midnight — Cache::put with an already-past endOfDay returns false. The
     * 25h TTL is garbage collection only, one hour past the longest a key can matter.
     * today() is Asia/Ho_Chi_Minh per config/app.php.
     */
    protected function disclaimerAckKey(int $userId): string
    {
        return "triage:disclaimer_ack:{$userId}:" . today()->toDateString();
    }

    /**
     * Whether to render the popup.
     *
     * Three inputs, in order: an explicit `?disclaimer=1` (the "Xem lại" link) forces it
     * open; an explicit `?disclaimer=0` suppresses it for this URL only; otherwise it is
     * due once per calendar day.
     *
     * The `0` case exists because Escape and a scrim click mean "not now", not
     * "understood", so they must not write the daily flag — but the result page reloads
     * itself when a polled AI job lands, and triage.js navigates on its own too. Without
     * a marker in the URL those reloads would re-open the dialog and re-steal focus from
     * somebody who had just closed it. A fresh visit carries no marker and asks again.
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
     * A worker is deciding. Tell the client to wait rather than holding the request
     * open for the 11-30s provider call.
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
                    'poll_url' => route('health.decision', $job->uuid),
                ],
                'show_disclaimer' => true,
                'disclaimer' => MedicalTriageService::DISCLAIMER,
            ], 202);
        }

        return redirect()->route('health.check');
    }

    /**
     * Progress of the decision, for the waiting state to poll.
     *
     * Scoped to the signed-in customer: the uuid is a public identifier, not a
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
                ? route('health.check')
                // Built here, not in the worker: route() inside a job has no incoming
                // request and would resolve the host from APP_URL.
                : route('health.result', $job->result['ma_sl']);

            return response()->json([
                'success' => true,
                'status' => 'done',
                'next' => $job->result['next'] ?? 'result',
                'redirect_url' => $target,
            ]);
        }

        if ($job->status === 'failed') {
            // The client reloads /health-check, which decides inline.
            return response()->json([
                'success' => false,
                'status' => 'failed',
                'redirect_url' => route('health.check'),
            ]);
        }

        if ($job->isStranded()) {
            // Retired here as well as in pendingEvaluationJob(): whichever the client
            // hits first must break the loop, or the reload finds the same queued job.
            $this->aiJobs->abandon($job, 'no worker picked the decision up, decided in the request');

            return response()->json([
                'success' => true,
                'status' => 'stranded',
                'stranded' => true,
                'redirect_url' => route('health.check'),
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
                // Server-side truth: a profile-covered step means the rendered step can
                // run ahead of current_step, so the client must not derive this itself.
                'allow_back' => (int) $phien->current_step > 1,
                'previous_answer' => $this->triageService->previousAnswerFor($phien, (int) $question['step']),
                'answered_steps' => $this->answeredSteps($phien),
                'show_disclaimer' => true,
                'disclaimer' => MedicalTriageService::DISCLAIMER,
            ]);
        }

        return redirect()->route('health.check');
    }

    protected function respondResult(Request $request, PhienSangLoc $phien): RedirectResponse|JsonResponse
    {
        $url = route('health.result', $phien->ma_sl);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                // The result page is a real navigation, not a stage swap: rendering it
                // client-side would move the outcome map, the drug-safety check and the
                // product cards into JavaScript.
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
                'disclaimer' => MedicalTriageService::DISCLAIMER,
            ]);
        }

        return redirect()->to($url);
    }

    /**
     * The AI follow-up round is offered on the wizard page itself, so the plain
     * redirect keeps the no-JS form flow intact.
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
                'disclaimer' => MedicalTriageService::DISCLAIMER,
            ]);
        }

        return redirect()->route('health.check');
    }

    protected function respondValidationError(Request $request, string $tinNhan): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => $tinNhan,
                // Matches the shape $request->validate() emits, so the client has one
                // code path for both kinds of 422.
                'errors' => ['answer' => [$tinNhan]],
            ], 422);
        }

        return redirect()->route('health.check')->withErrors(['answer' => $tinNhan]);
    }

    protected function respondExpired(Request $request): RedirectResponse|JsonResponse
    {
        $tinNhan = 'Phiên sàng lọc đã kết thúc hoặc không còn hiệu lực. Anh/chị vui lòng bắt đầu lại nhé.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => $tinNhan,
                // Nothing left to re-render in place: the client reloads the wizard.
                'redirect_url' => route('health.check'),
            ], 410);
        }

        return redirect()->route('health.check')->with('error', $tinNhan);
    }

    /**
     * The submitted step is not the one being asked — a stale tab, a resubmitted
     * form, or a crafted request. Send the user back to the real current question
     * instead of recording the answer under the wrong key.
     */
    protected function respondStepMismatch(Request $request): RedirectResponse|JsonResponse
    {
        $tinNhan = 'Câu hỏi này đã chuyển sang bước khác. Anh/chị trả lời tiếp câu đang hiển thị giúp em nhé.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => $tinNhan,
                'redirect_url' => route('health.check'),
            ], 409);
        }

        return redirect()->route('health.check')->with('error', $tinNhan);
    }
}
