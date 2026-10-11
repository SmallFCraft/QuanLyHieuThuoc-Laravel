<?php

namespace App\Http\Controllers;

use App\Models\AiJob;
use App\Models\MedicalSymptom;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\UserHealthProfile;
use App\Services\AiJobService;
use App\Services\DrugSafetyService;
use App\Services\Triage\MedicalTriageService;
use App\Services\Triage\Ai\TriageAiAdviceService;
use App\Services\Triage\Ai\TriageAiBriefingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        protected DrugSafetyService $drugSafety,
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
    protected function decideOutcome(Request $request, TriageSession $session): RedirectResponse|JsonResponse
    {
        // Emergency never queues. The red flag is found in plain PHP with no AI, and
        // somebody describing chest pain must see the 115 number in this response —
        // not after a worker picks the job up.
        if ($this->triageService->hasEmergencyRedFlag($session, $this->profileFor((int) $session->MaKH))) {
            return $this->decideOutcomeInline($request, $session);
        }

        // A previous attempt for this session was already retired for want of a worker.
        // Queueing again would loop: strand, retire, queue, strand — 12s per cycle,
        // forever. One failed attempt is enough evidence that nobody is consuming.
        if ($this->hasAbandonedEvaluation($session)) {
            return $this->decideOutcomeInline($request, $session);
        }

        try {
            $job = $this->aiJobs->dispatchEvaluation($session);
        } catch (\Throwable $exception) {
            // An unavailable queue must never strand a finished questionnaire.
            Log::warning('Triage evaluation queue unavailable, deciding inline', [
                'MaSL' => $session->MaSL,
                'error' => $exception->getMessage(),
            ]);

            return $this->decideOutcomeInline($request, $session);
        }

        if ($job->status === 'failed') {
            return $this->decideOutcomeInline($request, $session);
        }

        if ($job->status === 'done') {
            return $this->respondDecided($request, $session->fresh(), $job);
        }

        return $this->respondPending($request, $session, $job);
    }

    /**
     * Run the decision in this request. Used when there is no worker to take it.
     */
    protected function decideOutcomeInline(Request $request, TriageSession $session): RedirectResponse|JsonResponse
    {
        $result = $this->triageService->evaluate($session, $this->profileFor((int) $session->MaKH));

        if ($this->triageService->shouldAskClarify($session, $result)) {
            return $this->respondClarify(
                $request,
                $this->triageService->storeClarifyQuestions($session, $result)
            );
        }

        return $this->respondResult($request, $this->finalizeSession($session, $result));
    }

    /**
     * @param \App\Models\AiJob $job A finished evaluation job.
     */
    protected function respondDecided(Request $request, TriageSession $session, AiJob $job): RedirectResponse|JsonResponse
    {
        if (($job->result['next'] ?? null) === 'clarify') {
            return $this->respondClarify($request, $session);
        }

        return $this->respondResult($request, $session);
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
    protected function finalizeSession(TriageSession $session, array $result): TriageSession
    {
        $session = $this->triageService->finalize($session, $result);

        $this->aiJobs->dispatchAdviceRewriteIfEligible($session);
        $this->aiJobs->dispatchBriefingIfEligible($session);

        return $session->refresh();
    }

    /**
     * The view data the wizard stage needs, whether it is rendered as a full page or
     * swapped in over AJAX.
     *
     * @param array<string, mixed>|null $question
     * @return array<string, mixed>
     */
    protected function stageData(TriageSession $session, ?array $question, array $clarifyQuestions, ?AiJob $pendingJob, ?UserHealthProfile $profile): array
    {
        return [
            'session' => $session,
            'question' => $question,
            // Pre-fill so going back shows the user's own words, not a blank box.
            'previousAnswer' => $question
                ? $this->triageService->previousAnswerFor($session, (int) $question['step'])
                : '',
            'hasProfile' => $profile !== null,
            'clarifyQuestions' => $clarifyQuestions,
            'clarifyRound' => (int) $session->ai_clarify_rounds,
            'clarifyMaxRounds' => TriageSession::MAX_CLARIFY_ROUNDS,
            // Non-null while a worker is deciding: the stage shows a waiting state and
            // polls, instead of holding the request open for the 11-30s AI call.
            'pendingJobId' => $pendingJob?->uuid,
            'answeredSteps' => $this->answeredSteps($session),
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
    protected function renderStage(TriageSession $session): string
    {
        $session = $session->fresh();
        $profile = $this->profileFor((int) $session->MaKH);

        return view('health.partials.stage', $this->stageData(
            $session,
            $session->status === 'in_progress'
                ? $this->triageService->nextQuestion($session, $profile)
                : null,
            $this->pendingClarifyQuestions($session),
            $this->pendingEvaluationJob($session),
            $profile
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
        $session = $this->triageService->startOrResume($userId, session('chat_session_id'));
        $profile = $this->profileFor($userId);
        $question = $this->triageService->nextQuestion($session, $profile);
        $clarifyQuestions = $this->pendingClarifyQuestions($session);
        $pendingJob = $this->pendingEvaluationJob($session);

        // Checked before deciding: a pending round means the questions are already
        // stored, and a running job means the decision is already under way — either
        // way, re-evaluating here would spend a second provider call.
        if ($question === null
            && $clarifyQuestions === []
            && $pendingJob === null
            && !empty($session->collected_data)
        ) {
            $decision = $this->decideOutcome($request, $session);

            $session = $session->fresh();
            $pendingJob = $this->pendingEvaluationJob($session);
            $clarifyQuestions = $this->pendingClarifyQuestions($session);

            // Only a finished decision is a real navigation. A queued job renders the
            // waiting state below and a fresh follow-up round renders its form — both
            // without bouncing through a redirect back to this same action.
            if ($pendingJob === null && $clarifyQuestions === []) {
                return $decision;
            }
        }

        return view('health.check', array_merge(
            $this->stageData($session, $question, $clarifyQuestions, $pendingJob, $profile),
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
    protected function pendingEvaluationJob(TriageSession $session): ?AiJob
    {
        if ($session->status !== 'in_progress') {
            return null;
        }

        $job = AiJob::where('MaKH', $session->MaKH)
            ->where('kind', AiJob::KIND_TRIAGE_EVALUATION)
            ->whereIn('status', ['queued', 'running'])
            ->where('payload->MaSL', $session->MaSL)
            ->latest('MaAJ')
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
    protected function hasAbandonedEvaluation(TriageSession $session): bool
    {
        return AiJob::where('MaKH', $session->MaKH)
            ->where('kind', AiJob::KIND_TRIAGE_EVALUATION)
            ->where('status', 'failed')
            ->where('payload->MaSL', $session->MaSL)
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
    protected function answeredSteps(TriageSession $session): array
    {
        $data = $session->collected_data ?? [];
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
    protected function pendingClarifyQuestions(TriageSession $session): array
    {
        if ($session->status !== 'in_progress') {
            return [];
        }

        // recordClarifyAnswers() clears the list once the round is answered, so a
        // non-empty list here always means "this round is still open".
        return $session->pendingClarifyQuestions();
    }

    /**
     * Record one answer, then either ask the next question or finalise.
     */
    public function answer(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'triage_session_id' => ['required', 'integer'],
            'step' => ['required', 'integer', 'min:1', 'max:' . TriageSession::TOTAL_STEPS],
            'answer' => ['nullable', 'string', 'max:1000'],
            'option_answer' => ['nullable', 'string', 'max:1000'],
            'skipped' => ['nullable', 'boolean'],
        ], [
            'answer.max' => 'Câu trả lời không được vượt quá 1000 ký tự.',
        ]);

        $userId = (int) auth()->id();
        $session = TriageSession::where('MaKH', $userId)
            ->where('MaSL', $validated['triage_session_id'])
            ->inProgress()
            ->first();

        if (!$session) {
            return $this->respondExpired($request);
        }

        // Cast explicitly: the `integer` rule validates the type but does not coerce,
        // so $validated['step'] arrives as a string and would fail === against the
        // STEP_* constants.
        $step = (int) $validated['step'];
        $profile = $this->profileFor($userId);

        // The answer must belong to the question the session is actually on. Without
        // this a single crafted POST (step=5) finalises the triage with no symptom or
        // duration recorded, and every guard rule falls through to self-care. Compared
        // against nextQuestion rather than current_step because a profile-covered step
        // is skipped, so the rendered step can be ahead of the counter.
        $expected = $this->triageService->nextQuestion($session, $profile);

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
            $session = $this->triageService->recordAnswer($session, $step, $answer, $skipped);
            $question = $this->triageService->nextQuestion($session, $profile);

            if ($question !== null) {
                return $this->respondQuestion($request, $session, $question);
            }

            // Questionnaire complete. The decision — including the AI hop — runs in a
            // worker, and the patient waits on it.
            return $this->decideOutcome($request, $session);
        } catch (\Throwable $exception) {
            Log::error('Triage answer processing failed', [
                'MaSL' => $session->MaSL,
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
            'clarify' => ['nullable', 'array', 'max:' . TriageSession::MAX_CLARIFY_QUESTIONS_PER_ROUND],
            'clarify.*' => ['nullable', 'string', 'max:500'],
            'skipped' => ['nullable', 'boolean'],
        ]);

        $userId = (int) auth()->id();
        $session = TriageSession::where('MaKH', $userId)
            ->where('MaSL', $validated['triage_session_id'])
            ->inProgress()
            ->first();

        if (!$session) {
            return $this->respondExpired($request);
        }

        // Only reachable while a round is actually open.
        if ($session->pendingClarifyQuestions() === []) {
            return $this->respondStepMismatch($request);
        }

        $skipped = $request->boolean('skipped');

        try {
            if ($skipped) {
                // Declining ends the conversation: no further round may be offered.
                $session = $this->triageService->skipClarifyRounds($session);

                return $this->decideOutcome($request, $session);
            }

            $session = $this->triageService->recordClarifyAnswers(
                $session,
                (array) ($validated['clarify'] ?? [])
            );

            // The answers may have raised new gaps — or closed them. The model decides
            // via needs_more_info, within the round ceiling, inside the job.
            return $this->decideOutcome($request, $session);
        } catch (\Throwable $exception) {
            Log::error('Triage clarify processing failed', [
                'MaSL' => $session->MaSL,
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
        $session = TriageSession::where('MaKH', (int) auth()->id())
            ->where('MaSL', $MaSL)
            ->firstOrFail();

        $moved = $this->triageService->requestPharmacistReview($session);

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

        $session = TriageSession::where('MaKH', (int) auth()->id())
            ->where('MaSL', $validated['triage_session_id'])
            ->inProgress()
            ->first();

        if ($session && $session->current_step > 1) {
            $session->current_step = $session->current_step - 1;
            $session->save();
        }

        return redirect()->route('health.check');
    }

    /**
     * A finished session has its own URL so it can be shared, bookmarked and printed.
     */
    public function result(Request $request, int $MaSL): View
    {
        $userId = (int) auth()->id();
        $session = TriageSession::where('MaKH', $userId)
            ->where('MaSL', $MaSL)
            ->firstOrFail();

        abort_if($session->status === 'in_progress', 404);

        $profile = $this->profileFor($userId);

        // Before reading the AI columns: on the `sync` queue driver the job this call
        // may dispatch has already finished and written them, and $session would
        // otherwise still hold the pre-dispatch row.
        $briefingJob = $this->ensureBriefingJob($session);
        $session = $session->refresh();

        return view('health.result', [
            'session' => $session,
            'disclaimer' => MedicalTriageService::DISCLAIMER,
            // Non-null while the rewrite is still being written. Looked up rather than
            // flashed, because the decision may have been taken by a worker, which has
            // no session to flash into.
            'aiJobId' => $this->pendingAdviceJob($session)?->uuid,
            // Same idea for the detailed briefing, which is a much slower call and so
            // is polled separately: the advice can land minutes before it.
            'briefingJobId' => $briefingJob?->uuid,
            'briefing' => $this->briefingFor($session),
            // Whether this session should have a briefing at all, so the page can tell
            // "none was ever due" (emergency) from "it was attempted and produced
            // nothing" without re-deriving the eligibility rule in Blade.
            'briefingExpected' => app(TriageAiBriefingService::class)->isEligible($session),
            // Same reason as the briefing list: the stored row cannot hold a usable
            // image URL, so it is resolved here from the live product.
            'suggestedProducts' => $this->suggestedProductsFor($session),
            // Never in front of an emergency outcome: the tel:115 button is the only
            // action on that page and must not share the viewport with a dismissible
            // notice. The compact notice itself still renders — but in its inline form,
            // since with the popup suppressed there is nothing for "Xem lại" to reopen.
            'showDisclaimerPopup' => !$session->isEmergency() && $this->disclaimerPopupDue($request, $userId),
            'disclaimerReviewable' => !$session->isEmergency(),
            'hasProfile' => $profile !== null,
            'safety' => $this->safetyCheck($session, $profile),
            'basis' => $this->decisionBasis($session),
        ]);
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
    protected function suggestedProductsFor(TriageSession $session): array
    {
        // Closure, not the 'is_array' string: Collection::filter passes ($value, $key)
        // and is_array() takes exactly one argument, so the string form is a 500.
        $products = collect($session->suggested_products ?? [])->filter(fn ($row) => is_array($row));

        if ($products->isEmpty()) {
            return [];
        }

        $live = Product::whereIn('MaSP', $products->pluck('id')->filter()->all())
            ->get()
            ->keyBy('MaSP');

        return $products
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
    protected function ensureBriefingJob(TriageSession $session): ?AiJob
    {
        if ($session->ai_briefing_status !== null) {
            return null;
        }

        $job = AiJob::where('MaKH', $session->MaKH)
            ->where('kind', AiJob::KIND_TRIAGE_BRIEFING)
            ->where('payload->MaSL', $session->MaSL)
            ->latest('MaAJ')
            ->first();

        if ($job === null) {
            $job = $this->aiJobs->dispatchBriefingIfEligible($session);
        }

        // A queued job nobody picked up: no worker is running, so polling it would
        // only produce the "not available this time" notice. Write the briefing in
        // this request instead — the result page already loads once and reloads when
        // a job lands, so the cost is the first paint, not a second round trip.
        if ($job !== null && $job->isStranded()) {
            $briefing = app(TriageAiBriefingService::class);

            if (!$briefing->isEligible($session)) {
                $this->aiJobs->abandon($job, 'session not eligible for a briefing');

                return null;
            }

            try {
                // generate() records its own status and swallows provider failures, so
                // this reports whether the seam ran, matching ProcessTriageBriefing.
                $briefing->generate($session);
                $this->aiJobs->markDone($job, ['ai_briefing_status' => $session->refresh()->ai_briefing_status]);
            } catch (\Throwable $exception) {
                $this->aiJobs->markFailed($job, $exception->getMessage());
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
    protected function briefingFor(TriageSession $session): ?array
    {
        $briefing = $session->ai_briefing;

        if (!is_array($briefing) || $briefing === []) {
            return null;
        }

        $products = collect($briefing['products'] ?? []);

        if ($products->isEmpty()) {
            return $briefing;
        }

        $live = Product::whereIn('MaSP', $products->pluck('id')->filter()->all())
            ->notExpired()
            ->where('stock_quantity', '>', 0)
            ->get()
            ->keyBy('MaSP');

        $briefing['products'] = $products
            ->filter(fn (array $row) => $live->has((int) ($row['id'] ?? 0)))
            ->map(function (array $row) use ($live) {
                $product = $live[(int) $row['id']];

                return array_merge($row, [
                    'stock_quantity' => (int) $product->stock_quantity,
                    'price' => $product->price_vnd,
                    'url' => route('products.show', $product->MaSP),
                    'image_url' => $product->image_url,
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
    protected function pendingAdviceJob(TriageSession $session): ?AiJob
    {
        if ($session->ai_status !== null) {
            return null;
        }

        $job = AiJob::where('MaKH', $session->MaKH)
            ->where('kind', AiJob::KIND_TRIAGE_ADVICE)
            ->whereIn('status', ['queued', 'running'])
            ->where('payload->MaSL', $session->MaSL)
            ->latest('MaAJ')
            ->first();

        if ($job === null || !$job->isStranded()) {
            return $job;
        }

        $advice = app(TriageAiAdviceService::class);

        if (!$advice->isEligible($session)) {
            $this->aiJobs->abandon($job, 'session not eligible for an advice rewrite');

            return null;
        }

        try {
            // rewrite() records its own ai_status and swallows provider failures, so
            // this reports whether the seam ran, matching ProcessTriageAdviceRewrite.
            $advice->rewrite($session);
            $this->aiJobs->markDone($job, ['ai_status' => $session->refresh()->ai_status]);
        } catch (\Throwable $exception) {
            $this->aiJobs->markFailed($job, $exception->getMessage());
        }

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
    protected function decisionBasis(TriageSession $session): array
    {
        $codes = array_values(array_filter((array) $session->matched_symptoms));

        return [
            'symptoms' => $codes === []
                ? []
                : MedicalSymptom::whereIn('code', $codes)->pluck('name_vi')->all(),
            'duration_days' => $session->answerFor('duration_days'),
            'age' => data_get($session->ai_meta, 'age'),
            'clarify_rounds' => (int) $session->ai_clarify_rounds,
            'red_flags' => (array) $session->triggered_red_flags,
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
    protected function safetyCheck(TriageSession $session, ?UserHealthProfile $profile): array
    {
        $productIds = collect($session->suggested_products ?? [])
            ->concat((array) data_get($session->ai_briefing, 'products', []))
            ->pluck('id')
            ->filter()
            ->unique()
            ->all();

        if ($profile === null || $productIds === []) {
            return ['warnings' => [], 'flagged' => []];
        }

        $warnings = [];
        $flagged = [];

        // One query for the set, then an in-memory check per product: DrugSafetyService
        // indexes the contraindication and interaction tables once per request.
        foreach (Product::whereIn('MaSP', $productIds)->get() as $product) {
            $result = $this->drugSafety->checkProduct($product, $profile, $session->user);

            if ($result['warnings'] === []) {
                continue;
            }

            $flagged[] = (int) $product->MaSP;
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
        $sessions = $this->paginateOr404(
            TriageSession::where('MaKH', (int) auth()->id())
                ->where('status', '!=', 'in_progress')
                ->latest('MaSL'),
            10
        );

        return view('health.history', ['sessions' => $sessions]);
    }

    /**
     * Abandon the open session and start over.
     */
    public function restart(Request $request): RedirectResponse
    {
        TriageSession::where('MaKH', (int) auth()->id())->inProgress()->delete();

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
            'MaSL' => ['nullable', 'integer', 'min:1'],
        ]);

        Cache::put($this->disclaimerAckKey((int) auth()->id()), true, now()->addHours(25));

        // A named route plus an integer, never a submitted URL: no open-redirect surface,
        // and result() re-checks ownership with firstOrFail() so a forged id 404s.
        return isset($validated['MaSL'])
            ? redirect()->route('health.result', (int) $validated['MaSL'])
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

    protected function profileFor(int $userId): ?UserHealthProfile
    {
        return UserHealthProfile::consentedFor($userId);
    }

    /**
     * A worker is deciding. Tell the client to wait rather than holding the request
     * open for the 11-30s provider call.
     */
    protected function respondPending(Request $request, TriageSession $session, AiJob $job): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'mode' => 'deciding',
                'stage' => $this->renderStage($session),
                'triage_pending' => [
                    'triage_session_id' => $session->MaSL,
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
            ->where('MaKH', $userId)
            ->where('kind', AiJob::KIND_TRIAGE_EVALUATION)
            ->first();

        if ($job === null) {
            return response()->json(['success' => false, 'status' => 'missing'], 404);
        }

        if ($job->status === 'done') {
            $target = ($job->result['next'] ?? null) === 'clarify' || empty($job->result['MaSL'])
                ? route('health.check')
                // Built here, not in the worker: route() inside a job has no incoming
                // request and would resolve the host from APP_URL.
                : route('health.result', $job->result['MaSL']);

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

    protected function respondQuestion(Request $request, TriageSession $session, array $question): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'mode' => 'question',
                'stage' => $this->renderStage($session),
                'triage_question' => $question,
                // Server-side truth: a profile-covered step means the rendered step can
                // run ahead of current_step, so the client must not derive this itself.
                'allow_back' => (int) $session->current_step > 1,
                'previous_answer' => $this->triageService->previousAnswerFor($session, (int) $question['step']),
                'answered_steps' => $this->answeredSteps($session),
                'show_disclaimer' => true,
                'disclaimer' => MedicalTriageService::DISCLAIMER,
            ]);
        }

        return redirect()->route('health.check');
    }

    protected function respondResult(Request $request, TriageSession $session): RedirectResponse|JsonResponse
    {
        $url = route('health.result', $session->MaSL);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                // The result page is a real navigation, not a stage swap: rendering it
                // client-side would move the outcome map, the drug-safety check and the
                // product cards into JavaScript.
                'mode' => 'result',
                'triage_session_id' => $session->MaSL,
                'triage_result' => [
                    'outcome' => $session->outcome,
                    'advice_given' => $session->advice_given,
                    'safety_net' => $session->safety_net,
                    'possible_condition_groups' => $session->possible_condition_groups,
                    'triggered_red_flags' => $session->triggered_red_flags,
                    'suggested_products' => $session->suggested_products,
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
    protected function respondClarify(Request $request, TriageSession $session): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'mode' => 'clarify',
                'stage' => $this->renderStage($session),
                'triage_clarify' => [
                    'triage_session_id' => $session->MaSL,
                    'questions' => $session->pendingClarifyQuestions(),
                    'round' => (int) $session->ai_clarify_rounds,
                    'max_rounds' => TriageSession::MAX_CLARIFY_ROUNDS,
                    'answered_steps' => $this->answeredSteps($session),
                ],
                'show_disclaimer' => true,
                'disclaimer' => MedicalTriageService::DISCLAIMER,
            ]);
        }

        return redirect()->route('health.check');
    }

    protected function respondValidationError(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => $message,
                // Matches the shape $request->validate() emits, so the client has one
                // code path for both kinds of 422.
                'errors' => ['answer' => [$message]],
            ], 422);
        }

        return redirect()->route('health.check')->withErrors(['answer' => $message]);
    }

    protected function respondExpired(Request $request): RedirectResponse|JsonResponse
    {
        $message = 'Phiên sàng lọc đã kết thúc hoặc không còn hiệu lực. Anh/chị vui lòng bắt đầu lại nhé.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => $message,
                // Nothing left to re-render in place: the client reloads the wizard.
                'redirect_url' => route('health.check'),
            ], 410);
        }

        return redirect()->route('health.check')->with('error', $message);
    }

    /**
     * The submitted step is not the one being asked — a stale tab, a resubmitted
     * form, or a crafted request. Send the user back to the real current question
     * instead of recording the answer under the wrong key.
     */
    protected function respondStepMismatch(Request $request): RedirectResponse|JsonResponse
    {
        $message = 'Câu hỏi này đã chuyển sang bước khác. Anh/chị trả lời tiếp câu đang hiển thị giúp em nhé.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => $message,
                'redirect_url' => route('health.check'),
            ], 409);
        }

        return redirect()->route('health.check')->with('error', $message);
    }
}
