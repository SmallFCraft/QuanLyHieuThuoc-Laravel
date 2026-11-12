<?php

namespace App\Http\Controllers\Pharmacist;

use App\Http\Controllers\Controller;
use App\Models\TinNhanChat;
use App\Models\TrieuChungYTe;
use App\Models\PhienSangLoc;
use App\Models\HoSoSucKhoe;
use App\Services\AuditLogService;
use App\Services\Triage\Ai\TriageAiDraftService;
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
        $cacPhien = PhienSangLoc::with('nguoiDung')
            ->pendingPharmacist()
            ->oldest('created_at')
            ->oldest('MaSL')
            ->paginate(15)
            ->withQueryString();

        $pendingStats = PhienSangLoc::pendingPharmacist()
            ->selectRaw('COUNT(*) as total, MIN(created_at) as oldest')
            ->first();

        $pendingCount = (int) ($pendingStats?->total ?? 0);
        $oldestPendingAt = $pendingStats?->oldest;

        return view('pharmacist.triage.index', [
            'sessions' => $cacPhien,
            'pendingCount' => $pendingCount,
            'oldestPendingAt' => $oldestPendingAt ? \Illuminate\Support\Carbon::parse($oldestPendingAt) : null,
            'handledToday' => PhienSangLoc::where('pharmacist_id', (int) auth()->id())
                ->whereDate('reviewed_at', today())
                ->count(),
        ]);
    }

    public function show(int $MaSL, TriageAiDraftService $draftService): View
    {
        // Only cases that actually entered the queue. Without this filter any
        // pharmacist could walk MaSL=1..N and read the health profile of every
        // customer, including sessions still being filled in.
        $phien = PhienSangLoc::with(['nguoiDung', 'pharmacist'])
            ->whereIn('status', self::REVIEWABLE_STATUSES)
            ->where('MaSL', $MaSL)
            ->firstOrFail();

        $hoSo = HoSoSucKhoe::consentedFor((int) $phien->MaKH);

        // Reading someone's health data is auditable every single time, not once.
        if ($hoSo) {
            app(AuditLogService::class)->log(
                action: 'health_profile_accessed',
                modelType: HoSoSucKhoe::class,
                modelId: $hoSo->MaHS,
                oldValues: null,
                newValues: ['MaSL' => $phien->MaSL, 'MaKH' => $phien->MaKH]
            );
        }

        // Same REVIEWABLE_STATUSES as the current case: a session still in progress
        // was never handed to the pharmacist team, so it is not ours to read here.
        $pastSessions = $phien->MaKH
            ? PhienSangLoc::where('MaKH', $phien->MaKH)
                ->where('MaSL', '!=', $phien->MaSL)
                ->whereIn('status', self::REVIEWABLE_STATUSES)
                ->latest('created_at')
                ->limit(5)
                ->get()
            : collect();

        return view('pharmacist.triage.show', [
            'session' => $phien,
            'pastSessions' => $pastSessions,
            'answers' => $this->labelledAnswers($phien),
            'skipped' => $this->labelledSkips($phien),
            'symptomNames' => $this->symptomNames($phien),
            'age' => $phien->nguoiDung?->ageInYears(),
            'profile' => $hoSo,
            'isPending' => $phien->status === 'pending_pharmacist',
            'aiDraftEnabled' => $draftService->isEnabled(),
        ]);
    }

    public function reply(Request $request, int $MaSL): RedirectResponse
    {
        $note = $this->validatedNote($request);
        $pharmacistId = (int) auth()->id();

        $error = DB::transaction(function () use ($MaSL, $note, $pharmacistId) {
            $phien = PhienSangLoc::lockForUpdate()->findOrFail($MaSL);

            if ($phien->status !== 'pending_pharmacist') {
                return __('messages.pharmacist.error_already_reviewed');
            }

            if ($phien->pharmacist_id && $phien->pharmacist_id !== $pharmacistId) {
                return __('messages.pharmacist.error_claimed_by_other');
            }

            $phien->fill([
                'pharmacist_id' => $pharmacistId,
                'pharmacist_note' => $note,
                'reviewed_at' => now(),
                'status' => 'reviewed',
            ])->save();

            // Mirror the answer into the chat thread so the customer sees a human replied.
            if ($phien->session_id) {
                TinNhanChat::create([
                    'MaKH' => $phien->MaKH,
                    'session_id' => $phien->session_id,
                    'role' => 'assistant',
                    'content' => $note,
                    'metadata' => [
                        'reviewed_by_pharmacist' => true,
                        'pharmacist_id' => $pharmacistId,
                        'triage_session_id' => $phien->MaSL,
                    ],
                    'show_disclaimer' => false,
                ]);
            }

            app(AuditLogService::class)->log(
                action: 'triage_pharmacist_replied',
                modelType: PhienSangLoc::class,
                modelId: $phien->MaSL,
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
            $phien = PhienSangLoc::lockForUpdate()->findOrFail($MaSL);

            if ($phien->status !== 'pending_pharmacist') {
                return __('messages.pharmacist.error_already_reviewed');
            }

            if ($phien->pharmacist_id && $phien->pharmacist_id !== $pharmacistId) {
                return __('messages.pharmacist.error_claimed_by_other');
            }

            $previousOutcome = $phien->outcome;

            $phien->fill([
                'pharmacist_id' => $pharmacistId,
                'pharmacist_note' => $note,
                'reviewed_at' => now(),
                'status' => 'reviewed',
                'outcome' => 'refer_doctor',
                // Referred cases must not carry a shopping list.
                'suggested_products' => [],
            ])->save();

            if ($phien->session_id) {
                TinNhanChat::create([
                    'MaKH' => $phien->MaKH,
                    'session_id' => $phien->session_id,
                    'role' => 'assistant',
                    'content' => $note,
                    'metadata' => [
                        'reviewed_by_pharmacist' => true,
                        'pharmacist_id' => $pharmacistId,
                        'triage_session_id' => $phien->MaSL,
                        'escalated' => true,
                    ],
                    'show_disclaimer' => false,
                ]);
            }

            app(AuditLogService::class)->log(
                action: 'triage_escalated',
                modelType: PhienSangLoc::class,
                modelId: $phien->MaSL,
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
            $phien = PhienSangLoc::lockForUpdate()->findOrFail($MaSL);

            if ($phien->status !== 'pending_pharmacist') {
                return __('messages.pharmacist.error_already_reviewed');
            }

            if ($phien->pharmacist_id && $phien->pharmacist_id !== $pharmacistId) {
                return __('messages.pharmacist.error_claimed_by_other');
            }

            $phien->pharmacist_id = $pharmacistId;
            $phien->save();

            app(AuditLogService::class)->log(
                action: 'triage_claimed',
                modelType: PhienSangLoc::class,
                modelId: $phien->MaSL,
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

        $phien = PhienSangLoc::whereIn('status', self::REVIEWABLE_STATUSES)
            ->where('MaSL', $MaSL)
            ->firstOrFail();

        // Same gates as reply(): an already-answered or someone else's case must not
        // burn a provider call.
        if ($phien->status !== 'pending_pharmacist') {
            return $this->draftError($MaSL, __('messages.pharmacist.error_already_reviewed'));
        }

        if ($phien->pharmacist_id !== $pharmacistId) {
            return $this->draftError($MaSL, __('messages.pharmacist.error_claimed_by_other'));
        }

        $draft = $draftService->draftReply($phien, $this->symptomNames($phien));

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
    protected function labelledAnswers(PhienSangLoc $phien): array
    {
        $data = $phien->collected_data ?? [];
        $cacCauTraLoi = [];

        foreach (self::ANSWER_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $this->flatten($data[$key]);

            if ($value === '') {
                continue;
            }

            $cacCauTraLoi[__('messages.pharmacist.answer_' . $key)] = $value;
        }

        return $cacCauTraLoi;
    }

    /**
     * @return array<int, string>
     */
    protected function labelledSkips(PhienSangLoc $phien): array
    {
        $skipped = (array) data_get($phien->collected_data, 'skipped', []);

        return collect($skipped)
            ->map(fn ($key) => __('messages.pharmacist.answer_' . $key))
            ->all();
    }

    /**
     * Matched symptoms are stored as codes; the pharmacist needs the Vietnamese names.
     */
    protected function symptomNames(PhienSangLoc $phien): array
    {
        $codes = array_filter((array) $phien->matched_symptoms);

        if ($codes === []) {
            return [];
        }

        $names = TrieuChungYTe::whereIn('code', $codes)->pluck('name_vi', 'code');

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
