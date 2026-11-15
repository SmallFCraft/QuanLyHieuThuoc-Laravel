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
 * Hàng đợi xem lại cho các phiên sàng lọc bộ quy tắc không tự chốt được.
 *
 * Mỗi lần ghi đi qua một lần đọc có khoá của phiên nên hai dược sĩ
 * không thể trả lời cùng một khách hàng hai lần.
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
     * Các trạng thái có nghĩa "ca này đã được đưa cho đội dược sĩ". Bất kỳ
     * trạng thái khác (in_progress, tự chăm sóc hoàn thành) chưa từng được chuyển tư vấn, nên hồ sơ
     * sức khoẻ không phải của ta đọc.
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
        // Chỉ những ca thực sự đã vào hàng đợi. Nếu không có bộ lọc này, bất kỳ
        // dược sĩ nào cũng duyệt được MaSL=1..N và đọc hồ sơ sức khoẻ của mọi
        // khách hàng, gồm cả phiên vẫn đang điền.
        $phien = PhienSangLoc::with(['nguoiDung', 'pharmacist'])
            ->whereIn('status', self::REVIEWABLE_STATUSES)
            ->where('ma_sl', $MaSL)
            ->firstOrFail();

        $hoSo = HoSoSucKhoe::consentedFor((int) $phien->ma_nd);

        // Đọc dữ liệu sức khoẻ của người khác phải kiểm toán mỗi lần, chứ không phải một lần.
        if ($hoSo) {
            app(NhatKyKiemToanService::class)->ghi(
                action: 'health_profile_accessed',
                modelType: HoSoSucKhoe::class,
                modelId: $hoSo->ma_hs,
                oldValues: null,
                newValues: ['ma_sl' => $phien->ma_sl, 'ma_nd' => $phien->ma_nd]
            );
        }

        // Cùng REVIEWABLE_STATUSES với ca hiện tại: phiên còn đang làm
        // chưa từng được đưa cho đội dược sĩ, nên không phải của ta đọc tại đây.
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

            // Chép câu trả lời sang phiên chat để khách hàng thấy có người trả lời.
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
                // Ca đã chuyển tư vấn không được kèm danh sách mua sắm.
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
     * Nhận phân công trước khi viết câu trả lời, nên dược sĩ thứ hai mở cùng
     * ca sẽ được báo là đã có người nhận thay vì trả lời trùng.
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
     * Điền trước ô trả lời bằng bản soạn AI.
     *
     * Chỉ POST và không bao giờ lưu: bản soạn trở về qua `withInput()`, nên
     * `old('pharmacist_note')` điền form hiện có và dược sĩ vẫn phải
     * đọc, sửa và gửi nó. `validatedNote()` vẫn là ranh giới tin duy nhất.
     * GET còn ghi row kiểm toán `health_profile_accessed` mỗi lần click.
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

        // Cùng gate với reply(): ca đã trả lời hoặc của người khác không được
        // tốn một lần gọi nhà cung cấp.
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
     * @return array<string, string> nhãn tiếng Việt => câu trả lời đọc được
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
     * Triệu chứng đã khớp được lưu dưới dạng mã; dược sĩ cần tên tiếng Việt.
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