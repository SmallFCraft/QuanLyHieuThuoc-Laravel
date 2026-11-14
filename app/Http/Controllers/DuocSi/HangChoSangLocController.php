<?php

namespace App\Http\Controllers\DuocSi;

use App\Http\Controllers\Controller;
use App\Models\TinNhanChat;
use App\Models\TrieuChungYTe;
use App\Models\PhienSangLoc;
use App\Models\HoSoSucKhoe;
use App\Services\NhatKyKiemToanService;
use App\Services\Triage\Ai\SangLocAiSoanLoiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Hang doi xem lai cho cac phien sang loc bo quy tac khong tu chot duoc.
 *
 * Moi lan ghi di qua mot lan doc co khoa cua phien nen hai duoc si
 * khong the tra loi cung mot khach hang hai lan.
 */
class HangChoSangLocController extends Controller
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
     * Cac trang thai co nghia "ca nay da duoc dua cho doi duoc si". Bat ky
     * trang thai khac (in_progress, tu cham soc hoan thanh) chua tung duoc chuyen tu van, nen ho so
     * suc khoe khong phai cua ta doc.
     */
    private const REVIEWABLE_STATUSES = ['pending_pharmacist', 'reviewed'];

    public function index(Request $request): View
    {
        $cacPhien = PhienSangLoc::with('nguoiDung')
            ->pendingPharmacist()
            ->oldest('created_at')
            ->oldest('ma_sl')
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

    public function show(int $MaSL, SangLocAiSoanLoiService $draftService): View
    {
        // Chi nhung ca thuc su da vao hang doi. Neu khong co bo loc nay, bat ky
        // duoc si nao cung duyet duoc MaSL=1..N va doc ho so suc khoe cua moi
        // khach hang, gom ca phien van dang dien.
        $phien = PhienSangLoc::with(['nguoiDung', 'pharmacist'])
            ->whereIn('status', self::REVIEWABLE_STATUSES)
            ->where('ma_sl', $MaSL)
            ->firstOrFail();

        $hoSo = HoSoSucKhoe::consentedFor((int) $phien->ma_nd);

        // Doc du lieu suc khoe cua nguoi khac phai kiem toan moi lan, chu khong phai mot lan.
        if ($hoSo) {
            app(NhatKyKiemToanService::class)->ghi(
                action: 'health_profile_accessed',
                modelType: HoSoSucKhoe::class,
                modelId: $hoSo->ma_hs,
                oldValues: null,
                newValues: ['ma_sl' => $phien->ma_sl, 'ma_nd' => $phien->ma_nd]
            );
        }

        // Cung REVIEWABLE_STATUSES voi ca hien tai: phien con dang lam
        // chua tung duoc dua cho doi duoc si, nen khong phai cua ta doc tai day.
        $pastSessions = $phien->ma_nd
            ? PhienSangLoc::where('ma_nd', $phien->ma_nd)
                ->where('ma_sl', '!=', $phien->ma_sl)
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
            'aiDraftEnabled' => $draftService->daBat(),
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

            // Chep cau tra loi sang phien chat de khach hang thay co nguoi tra loi.
            if ($phien->session_id) {
                TinNhanChat::create([
                    'ma_nd' => $phien->ma_nd,
                    'session_id' => $phien->session_id,
                    'role' => 'assistant',
                    'content' => $note,
                    'metadata' => [
                        'reviewed_by_pharmacist' => true,
                        'pharmacist_id' => $pharmacistId,
                        'triage_session_id' => $phien->ma_sl,
                    ],
                    'show_disclaimer' => false,
                ]);
            }

            app(NhatKyKiemToanService::class)->ghi(
                action: 'triage_pharmacist_replied',
                modelType: PhienSangLoc::class,
                modelId: $phien->ma_sl,
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
                // Ca da chuyen tu van khong duoc kem danh sach mua sam.
                'suggested_products' => [],
            ])->save();

            if ($phien->session_id) {
                TinNhanChat::create([
                    'ma_nd' => $phien->ma_nd,
                    'session_id' => $phien->session_id,
                    'role' => 'assistant',
                    'content' => $note,
                    'metadata' => [
                        'reviewed_by_pharmacist' => true,
                        'pharmacist_id' => $pharmacistId,
                        'triage_session_id' => $phien->ma_sl,
                        'escalated' => true,
                    ],
                    'show_disclaimer' => false,
                ]);
            }

            app(NhatKyKiemToanService::class)->ghi(
                action: 'triage_escalated',
                modelType: PhienSangLoc::class,
                modelId: $phien->ma_sl,
                oldValues: ['outcome' => $previousOutcome],
                newValues: ['outcome' => 'refer_doctor', 'pharmacist_id' => $pharmacistId]
            );

            return null;
        });

        return $this->backToSession($MaSL, $error, __('messages.pharmacist.escalate_success'));
    }

    /**
     * Nhan phan cong truoc khi viet cau tra loi, nen duoc si thu hai mo cung
     * ca se duoc bao la da co nguoi nhan thay vi tra loi trung.
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

            app(NhatKyKiemToanService::class)->ghi(
                action: 'triage_claimed',
                modelType: PhienSangLoc::class,
                modelId: $phien->ma_sl,
                oldValues: null,
                newValues: ['pharmacist_id' => $pharmacistId]
            );

            return null;
        });

        return $this->backToSession($MaSL, $error, __('messages.pharmacist.claim_success'));
    }

    /**
     * Dien truoc o tra loi bang ban soan AI.
     *
     * Chi POST va khong bao gio luu: ban soan tro ve qua `withInput()`, nen
     * `old('pharmacist_note')` dien form hien co va duoc si van phai
     * doc, sua va gui no. `validatedNote()` van la ranh gioi tin duy nhat.
     * GET con ghi row kiem toan `health_profile_accessed` moi lan click.
     */
    public function draft(Request $request, int $MaSL, SangLocAiSoanLoiService $draftService): RedirectResponse
    {
        if (!$draftService->daBat()) {
            return $this->draftError($MaSL, __('messages.pharmacist.ai_draft_disabled'));
        }

        $pharmacistId = (int) auth()->id();

        $phien = PhienSangLoc::whereIn('status', self::REVIEWABLE_STATUSES)
            ->where('ma_sl', $MaSL)
            ->firstOrFail();

        // Cung gate voi reply(): ca da tra loi hoac cua nguoi khac khong duoc
        // ton mot lan goi nha cung cap.
        if ($phien->status !== 'pending_pharmacist') {
            return $this->draftError($MaSL, __('messages.pharmacist.error_already_reviewed'));
        }

        if ($phien->pharmacist_id !== $pharmacistId) {
            return $this->draftError($MaSL, __('messages.pharmacist.error_claimed_by_other'));
        }

        $draft = $draftService->soanLoiTraLoi($phien, $this->symptomNames($phien));

        if ($draft === null) {
            return $this->draftError($MaSL, __('messages.pharmacist.ai_draft_failed'));
        }

        return redirect()
            ->route('duoc-si.sang-loc.show', $MaSL)
            ->withInput(['pharmacist_note' => $draft])
            ->with('success', __('messages.pharmacist.ai_draft_success'));
    }

    protected function draftError(int $MaSL, string $message): RedirectResponse
    {
        return redirect()
            ->route('duoc-si.sang-loc.show', $MaSL)
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
                ->route('duoc-si.sang-loc.show', $MaSL)
                ->withErrors(['pharmacist_note' => $error]);
        }

        return redirect()->route('duoc-si.sang-loc.index')->with('success', $success);
    }

    /**
     * @return array<string, string> nhan tieng Viet => cau tra loi doc duoc
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
     * Trieu chung da khop duoc luu duoi dang ma; duoc si can ten tieng Viet.
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