<?php

namespace App\Http\Controllers\Pharmacist;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\MedicalSymptom;
use App\Models\TriageSession;
use App\Models\UserHealthProfile;
use App\Services\AuditLogService;
use App\Services\TriageAiDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Review queue for triage sessions the rules engine could not close on its own.
 *
 * Every write goes through a locked read of the session so two pharmacists
 * cannot answer the same customer twice.
 */
class TriageQueueController extends Controller
{
    private const ANSWER_KEYS = [
        'raw_symptom_text',
        'symptoms',
        'duration_days',
        'medications_taken',
        'history',
        'other_symptoms',
    ];

    /**
     * Statuses that mean "this case was handed to the pharmacist team". Anything
     * else (in_progress, completed self-care) was never referred, so its health
     * profile is not ours to read.
     */
    private const REVIEWABLE_STATUSES = ['pending_pharmacist', 'reviewed'];

    public function index(Request $request): View
    {
        $sessions = TriageSession::with('user')
            ->pendingPharmacist()
            ->oldest('created_at')
            ->oldest('MaSL')
            ->paginate(15)
            ->withQueryString();

        $pendingStats = TriageSession::pendingPharmacist()
            ->selectRaw('COUNT(*) as total, MIN(created_at) as oldest')
            ->first();

        $pendingCount = (int) ($pendingStats?->total ?? 0);
        $oldestPendingAt = $pendingStats?->oldest;

        return view('pharmacist.triage.index', [
            'sessions' => $sessions,
            'pendingCount' => $pendingCount,
            'oldestPendingAt' => $oldestPendingAt ? \Illuminate\Support\Carbon::parse($oldestPendingAt) : null,
            'handledToday' => TriageSession::where('pharmacist_id', (int) auth()->id())
                ->whereDate('reviewed_at', today())
                ->count(),
        ]);
    }

    public function show(int $MaSL, TriageAiDraftService $draftService): View
    {
        // Only cases that actually entered the queue. Without this filter any
        // pharmacist could walk MaSL=1..N and read the health profile of every
        // customer, including sessions still being filled in.
        $session = TriageSession::with(['user', 'pharmacist'])
            ->whereIn('status', self::REVIEWABLE_STATUSES)
            ->where('MaSL', $MaSL)
            ->firstOrFail();

        $profile = UserHealthProfile::consentedFor((int) $session->MaKH);

        // Reading someone's health data is auditable every single time, not once.
        if ($profile) {
            app(AuditLogService::class)->log(
                action: 'health_profile_accessed',
                modelType: UserHealthProfile::class,
                modelId: $profile->MaHS,
                oldValues: null,
                newValues: ['MaSL' => $session->MaSL, 'MaKH' => $session->MaKH]
            );
        }

        // Same REVIEWABLE_STATUSES as the current case: a session still in progress
        // was never handed to the pharmacist team, so it is not ours to read here.
        $pastSessions = $session->MaKH
            ? TriageSession::where('MaKH', $session->MaKH)
                ->where('MaSL', '!=', $session->MaSL)
                ->whereIn('status', self::REVIEWABLE_STATUSES)
                ->latest('created_at')
                ->limit(5)
                ->get()
            : collect();

        return view('pharmacist.triage.show', [
            'session' => $session,
            'pastSessions' => $pastSessions,
            'answers' => $this->labelledAnswers($session),
            'skipped' => $this->labelledSkips($session),
            'symptomNames' => $this->symptomNames($session),
            'age' => $session->user?->ageInYears(),
            'profile' => $profile,
            'isPending' => $session->status === 'pending_pharmacist',
            'aiDraftEnabled' => $draftService->isEnabled(),
        ]);
    }

    public function reply(Request $request, int $MaSL): RedirectResponse
    {
        $note = $this->validatedNote($request);
        $pharmacistId = (int) auth()->id();

        $error = DB::transaction(function () use ($MaSL, $note, $pharmacistId) {
            $session = TriageSession::lockForUpdate()->findOrFail($MaSL);

            if ($session->status !== 'pending_pharmacist') {
                return __('messages.pharmacist.error_already_reviewed');
            }

            if ($session->pharmacist_id && $session->pharmacist_id !== $pharmacistId) {
                return __('messages.pharmacist.error_claimed_by_other');
            }

            $session->fill([
                'pharmacist_id' => $pharmacistId,
                'pharmacist_note' => $note,
                'reviewed_at' => now(),
                'status' => 'reviewed',
            ])->save();

            // Mirror the answer into the chat thread so the customer sees a human replied.
            if ($session->session_id) {
                ChatMessage::create([
                    'MaKH' => $session->MaKH,
                    'session_id' => $session->session_id,
                    'role' => 'assistant',
                    'content' => $note,
                    'metadata' => [
                        'reviewed_by_pharmacist' => true,
                        'pharmacist_id' => $pharmacistId,
                        'triage_session_id' => $session->MaSL,
                    ],
                    'show_disclaimer' => false,
                ]);
            }

            app(AuditLogService::class)->log(
                action: 'triage_pharmacist_replied',
                modelType: TriageSession::class,
                modelId: $session->MaSL,
                oldValues: null,
                newValues: ['pharmacist_id' => $pharmacistId]
            );

            return null;
        });

        return $this->backToSession($MaSL, $error, __('messages.pharmacist.reply_success'));
    }

    public function escalate(Request $request, int $MaSL): RedirectResponse
    {
        $note = $this->validatedNote($request);
        $pharmacistId = (int) auth()->id();

        $error = DB::transaction(function () use ($MaSL, $note, $pharmacistId) {
            $session = TriageSession::lockForUpdate()->findOrFail($MaSL);

            if ($session->status !== 'pending_pharmacist') {
                return __('messages.pharmacist.error_already_reviewed');
            }

            if ($session->pharmacist_id && $session->pharmacist_id !== $pharmacistId) {
                return __('messages.pharmacist.error_claimed_by_other');
            }

            $previousOutcome = $session->outcome;

            $session->fill([
                'pharmacist_id' => $pharmacistId,
                'pharmacist_note' => $note,
                'reviewed_at' => now(),
                'status' => 'reviewed',
                'outcome' => 'refer_doctor',
                // Referred cases must not carry a shopping list.
                'suggested_products' => [],
            ])->save();

            if ($session->session_id) {
                ChatMessage::create([
                    'MaKH' => $session->MaKH,
                    'session_id' => $session->session_id,
                    'role' => 'assistant',
                    'content' => $note,
                    'metadata' => [
                        'reviewed_by_pharmacist' => true,
                        'pharmacist_id' => $pharmacistId,
                        'triage_session_id' => $session->MaSL,
                        'escalated' => true,
                    ],
                    'show_disclaimer' => false,
                ]);
            }

            app(AuditLogService::class)->log(
                action: 'triage_escalated',
                modelType: TriageSession::class,
                modelId: $session->MaSL,
                oldValues: ['outcome' => $previousOutcome],
                newValues: ['outcome' => 'refer_doctor', 'pharmacist_id' => $pharmacistId]
            );

            return null;
        });

        return $this->backToSession($MaSL, $error, __('messages.pharmacist.escalate_success'));
    }

    /**
     * Take ownership before writing an answer, so a second pharmacist opening the
     * same case is told it is taken instead of duplicating the reply.
     */
    public function claim(int $MaSL): RedirectResponse
    {
        $pharmacistId = (int) auth()->id();

        $error = DB::transaction(function () use ($MaSL, $pharmacistId) {
            $session = TriageSession::lockForUpdate()->findOrFail($MaSL);

            if ($session->status !== 'pending_pharmacist') {
                return __('messages.pharmacist.error_already_reviewed');
            }

            if ($session->pharmacist_id && $session->pharmacist_id !== $pharmacistId) {
                return __('messages.pharmacist.error_claimed_by_other');
            }

            $session->pharmacist_id = $pharmacistId;
            $session->save();

            app(AuditLogService::class)->log(
                action: 'triage_claimed',
                modelType: TriageSession::class,
                modelId: $session->MaSL,
                oldValues: null,
                newValues: ['pharmacist_id' => $pharmacistId]
            );

            return null;
        });

        return $this->backToSession($MaSL, $error, __('messages.pharmacist.claim_success'));
    }

    /**
     * Prefill the reply textarea with an AI draft.
     *
     * POST-only and never persisted: the draft comes back through `withInput()`, so
     * `old('pharmacist_note')` fills the existing form and the pharmacist still has
     * to read, edit and submit it. `validatedNote()` stays the only trust boundary.
     * GET would also write a `health_profile_accessed` audit row on every click.
     */
    public function draft(Request $request, int $MaSL, TriageAiDraftService $draftService): RedirectResponse
    {
        if (!$draftService->isEnabled()) {
            return $this->draftError($MaSL, __('messages.pharmacist.ai_draft_disabled'));
        }

        $pharmacistId = (int) auth()->id();

        $session = TriageSession::whereIn('status', self::REVIEWABLE_STATUSES)
            ->where('MaSL', $MaSL)
            ->firstOrFail();

        // Same gates as reply(): an already-answered or someone else's case must not
        // burn a provider call.
        if ($session->status !== 'pending_pharmacist') {
            return $this->draftError($MaSL, __('messages.pharmacist.error_already_reviewed'));
        }

        if ($session->pharmacist_id !== $pharmacistId) {
            return $this->draftError($MaSL, __('messages.pharmacist.error_claimed_by_other'));
        }

        $draft = $draftService->draftReply($session, $this->symptomNames($session));

        if ($draft === null) {
            return $this->draftError($MaSL, __('messages.pharmacist.ai_draft_failed'));
        }

        return redirect()
            ->route('pharmacist.triage.show', $MaSL)
            ->withInput(['pharmacist_note' => $draft])
            ->with('success', __('messages.pharmacist.ai_draft_success'));
    }

    protected function draftError(int $MaSL, string $message): RedirectResponse
    {
        return redirect()
            ->route('pharmacist.triage.show', $MaSL)
            ->withErrors(['pharmacist_note' => $message]);
    }

    protected function validatedNote(Request $request): string
    {
        $validated = $request->validate([
            'pharmacist_note' => ['required', 'string', 'max:2000'],
        ], [
            'pharmacist_note.required' => __('messages.pharmacist.note_required'),
            'pharmacist_note.max' => __('messages.pharmacist.note_max'),
        ]);

        return trim($validated['pharmacist_note']);
    }

    protected function backToSession(int $MaSL, ?string $error, string $success): RedirectResponse
    {
        if ($error !== null) {
            return redirect()
                ->route('pharmacist.triage.show', $MaSL)
                ->withErrors(['pharmacist_note' => $error]);
        }

        return redirect()->route('pharmacist.triage.index')->with('success', $success);
    }

    /**
     * @return array<string, string> Vietnamese label => readable answer
     */
    protected function labelledAnswers(TriageSession $session): array
    {
        $data = $session->collected_data ?? [];
        $answers = [];

        foreach (self::ANSWER_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $this->flatten($data[$key]);

            if ($value === '') {
                continue;
            }

            $answers[__('messages.pharmacist.answer_' . $key)] = $value;
        }

        return $answers;
    }

    /**
     * @return array<int, string>
     */
    protected function labelledSkips(TriageSession $session): array
    {
        $skipped = (array) data_get($session->collected_data, 'skipped', []);

        return collect($skipped)
            ->map(fn ($key) => __('messages.pharmacist.answer_' . $key))
            ->all();
    }

    /**
     * Matched symptoms are stored as codes; the pharmacist needs the Vietnamese names.
     */
    protected function symptomNames(TriageSession $session): array
    {
        $codes = array_filter((array) $session->matched_symptoms);

        if ($codes === []) {
            return [];
        }

        $names = MedicalSymptom::whereIn('code', $codes)->pluck('name_vi', 'code');

        return collect($codes)->map(fn ($code) => $names[$code] ?? $code)->unique()->values()->all();
    }

    protected function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map(fn ($item) => is_scalar($item) ? trim((string) $item) : '', $value)));
        }

        if (is_bool($value)) {
            return $value
                ? __('messages.pharmacist.profile_yes')
                : __('messages.pharmacist.profile_no');
        }

        return $value === null ? '' : trim((string) $value);
    }
}
