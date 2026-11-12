<?php

namespace App\Services\Triage;

use App\Models\CoDoYTe;
use App\Models\TrieuChungYTe;
use App\Models\QuyTacPhanTangYTe;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use App\Services\AuditLogService;
use App\Services\Triage\Ai\TriageAiFactService;
use App\Support\VietnameseText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Structured symptom triage following the WWHAM / ASMETHOD questioning frameworks
 * and the STARZ-DRP referral model.
 *
 * This is a triage tool, NOT a diagnostic one: it decides whether the user can
 * self-care, needs a pharmacist, or must see a doctor. Every decision comes from
 * database rules, never from a language model. The AI layer only rephrases what
 * this service already decided.
 */
class MedicalTriageService
{
    public const STEP_SYMPTOM = 1;

    public const STEP_DURATION = 2;

    public const STEP_MEDICATION_TAKEN = 3;

    public const STEP_HISTORY = 4;

    public const STEP_OTHER_SYMPTOMS = 5;

    public const DISCLAIMER = 'Đây là công cụ sàng lọc, không phải chẩn đoán y khoa. Nội dung do hệ thống tạo ra có thể không chính xác, không thay thế việc gặp bác sĩ hoặc dược sĩ.';

    protected const FOLLOW_UP_DAYS = 3;

    public function __construct(
        protected AuditLogService $auditLogService,
    ) {}

    /**
     * Medical dictionaries, read once per request. @see symptomDictionary()
     */
    protected ?Collection $symptomDictionary = null;

    protected ?Collection $redFlagDictionary = null;

    /**
     * Resume the user's open session or start a fresh one. Sessions are per-user,
     * so a half-finished triage survives a page reload or a closed browser.
     */
    public function startOrResume(int $userId, ?string $chatSessionId = null, string $entryPoint = 'page'): PhienSangLoc
    {
        $phien = PhienSangLoc::where('ma_nd', $userId)->inProgress()->latest('ma_sl')->first();

        if ($phien) {
            return $phien;
        }

        return PhienSangLoc::create([
            'ma_nd' => $userId,
            'session_id' => $chatSessionId,
            'collected_data' => [],
            'current_step' => self::STEP_SYMPTOM,
            'entry_point' => $entryPoint,
            'status' => 'in_progress',
        ]);
    }

    /**
     * Match the free-text message against the symptom dictionary.
     *
     * Runs independently of the AI: if the provider is down or returns nothing
     * usable, symptom detection still works off the raw message.
     */
    public function detectSymptoms(string $message): Collection
    {
        $normalized = $this->normalize($message);

        if ($normalized === '') {
            return collect();
        }

        return $this->symptomDictionary()
            ->filter(fn (TrieuChungYTe $trieuChung) => $this->matchesAnyAlias($normalized, $trieuChung->aliases ?? []))
            ->values();
    }

    /**
     * Red flags are checked against the message AND the stored health profile,
     * because "đang mang thai" may come from the profile rather than the message.
     *
     * Kept as a single-haystack call because it is the public entry point the chatbot
     * and the tests use. Session-based detection goes through sessionRedFlags(), which
     * matches each answer separately — see the comment there.
     */
    public function detectRedFlags(string $message, ?HoSoSucKhoe $hoSo = null): Collection
    {
        return $this->matchRedFlagSegments([$message, ...$this->profileTerms($hoSo)]);
    }

    /**
     * @return array<int, string> Consented profile terms, each as its own utterance.
     */
    protected function profileTerms(?HoSoSucKhoe $hoSo): array
    {
        if (! $hoSo?->hasConsented()) {
            return [];
        }

        return array_values(array_filter([
            ...($hoSo->chronic_conditions ?? []),
            $hoSo->is_pregnant ? 'mang thai' : '',
            $hoSo->is_breastfeeding ? 'dang cho con bu' : '',
        ]));
    }

    /**
     * Match red flags against several utterances independently, then union them.
     *
     * Segments, not one joined string, because the negation window in
     * mentionsAffirmatively() reaches backwards across two light verbs and cannot know
     * that two words came from different questions. Measured: wizard step 4 answered
     * "Không có" followed by step 5 "khó thở nhiều lắm" joined to "... khong co kho tho
     * nhieu lam", and the adjacent "khong co" cancelled a real emergency. The same
     * adjacency also silenced a profile term sitting right after the last answer.
     *
     * @param  array<int, string>  $segments
     * @return Collection<int, CoDoYTe>
     */
    protected function matchRedFlagSegments(array $segments): Collection
    {
        $cacChuoiSoSanh = collect($segments)
            ->map(fn ($segment) => $this->normalize((string) $segment))
            ->filter()
            ->all();

        if ($cacChuoiSoSanh === []) {
            return collect();
        }

        return $this->redFlagDictionary()
            ->filter(function (CoDoYTe $coDo) use ($cacChuoiSoSanh): bool {
                foreach ($cacChuoiSoSanh as $chuoiSoSanh) {
                    if ($this->matchesAnyAlias($chuoiSoSanh, $coDo->keywords ?? [])) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    protected function sessionRedFlags(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo = null): Collection
    {
        return $this->mergeRedFlags(
            $this->matchRedFlagSegments([
                ...$this->wizardSegments($phien),
                ...$this->profileTerms($hoSo),
            ]),
            $this->clarifyAnswerFlags($phien),
            $this->affirmedClarifyFlags($phien)
        );
    }

    /**
     * Follow-up answers are matched knowing which question produced them.
     *
     * The wizard's follow-up round asks about intensity by handing the patient a scale
     * ("nhẹ, vừa hay dữ dội?"), so the top option is our word, not theirs. Matched flat
     * against the unscoped `severe_pain` keyword, picking it escalated every such
     * patient to a 115 call. Measured: period cramps graded "đau dữ dội" came back as
     * "đau dữ dội không rõ nguyên nhân" while the cause sat in the first answer.
     *
     * Anything the patient volunteers still counts, which is why only vocabulary the
     * question itself offered is discounted — see answerRaisesFlag().
     */
    protected function clarifyAnswerFlags(PhienSangLoc $phien): Collection
    {
        $daKhop = collect();

        foreach ($phien->clarifyHistory() as $capDoi) {
            $cauTraLoi = $this->normalize((string) ($capDoi['answer'] ?? ''));

            if ($cauTraLoi === '') {
                continue;
            }

            $cauHoi = (string) ($capDoi['question'] ?? '');

            $daKhop = $daKhop->merge(
                $this->redFlagDictionary()->filter(
                    fn (CoDoYTe $coDo): bool => $this->answerRaisesFlag($cauTraLoi, $cauHoi, $coDo)
                )
            );
        }

        return $this->mergeRedFlags($daKhop);
    }

    /**
     * A keyword only counts when the answer contributes it.
     *
     * The scale check is what keeps this narrow: a question naming one danger sign
     * ("có đau ngực dữ dội không?") is not a menu, so `chest_pain_severe` keeps its
     * full weight there. Only a question offering several intensity grades can
     * discount the grade that comes back.
     */
    protected function answerRaisesFlag(string $foldedAnswer, string $cauHoi, CoDoYTe $coDo): bool
    {
        $offersScale = VietnameseText::offersSeverityScale($cauHoi);

        foreach ($coDo->keywords ?? [] as $tuKhoa) {
            if (! VietnameseText::mentionsAffirmatively($foldedAnswer, (string) $tuKhoa)) {
                continue;
            }

            if ($offersScale && VietnameseText::echoesEveryWord($cauHoi, (string) $tuKhoa)) {
                continue;
            }

            return true;
        }

        return false;
    }

    protected function mergeRedFlags(Collection ...$sets): Collection
    {
        $ids = collect($sets)->flatMap(fn (Collection $set) => $set->pluck('ma_cb'))->unique();

        return $ids->isEmpty()
            ? collect()
            : $this->redFlagDictionary()->whereIn('ma_cb', $ids)->values();
    }

    protected function derivableRedFlags(): Collection
    {
        $cacTen = $this->symptomDictionary()->pluck('name_vi', 'ma_tc');

        return $this->redFlagDictionary()->filter(function (CoDoYTe $coDo) use ($cacTen): bool {
            $ten = $cacTen->get($coDo->applies_to_symptom);

            return $ten !== null
                && $this->matchesAnyAlias($this->normalize((string) $ten), $coDo->keywords ?? []);
        })->values();
    }

    protected function affirmedClarifyFlags(PhienSangLoc $phien): Collection
    {
        $cacCapDoi = collect($phien->clarifyHistory())
            ->filter(fn (array $capDoi) => VietnameseText::isAffirmation((string) ($capDoi['answer'] ?? '')));

        if ($cacCapDoi->isEmpty()) {
            return collect();
        }

        $cacTrieuChung = $this->symptomDictionary()->keyBy('ma_tc');

        return $this->derivableRedFlags()
            ->filter(function (CoDoYTe $coDo) use ($cacCapDoi, $cacTrieuChung): bool {
                $trieuChung = $cacTrieuChung->get($coDo->applies_to_symptom);

                return $trieuChung !== null && $cacCapDoi->contains(
                    fn (array $capDoi) => $this->pairAffirmsSymptom($capDoi, $trieuChung, $coDo)
                );
            })
            ->values();
    }

    protected function pairAffirmsSymptom(array $capDoi, TrieuChungYTe $trieuChung, CoDoYTe $coDo): bool
    {
        $cacThuatNgu = [$trieuChung->name_vi, ...($trieuChung->aliases ?? [])];

        if (! $this->namesAnyTerm($this->normalize((string) ($capDoi['question'] ?? '')), $cacThuatNgu)) {
            return false;
        }

        $cauTraLoi = (string) ($capDoi['answer'] ?? '');
        $folded = $this->normalize($cauTraLoi);

        if ($this->isDeniedInText($folded, [...$cacThuatNgu, ...($coDo->keywords ?? [])])) {
            return false;
        }

        return $this->matchesAnyAlias($folded, $cacThuatNgu) || ! VietnameseText::isRestrictive($cauTraLoi);
    }

    protected function namesAnyTerm(string $foldedText, array $cacThuatNgu): bool
    {
        foreach ($cacThuatNgu as $thuatNgu) {
            if (VietnameseText::containsWord($foldedText, (string) $thuatNgu)) {
                return true;
            }
        }

        return false;
    }

    protected function isDeniedInText(string $foldedText, array $cacThuatNgu): bool
    {
        foreach ($cacThuatNgu as $thuatNgu) {
            if (VietnameseText::containsWord($foldedText, (string) $thuatNgu)
                && ! VietnameseText::mentionsAffirmatively($foldedText, (string) $thuatNgu)) {
                return true;
            }
        }

        return false;
    }

    protected function codeDerivedRedFlags(PhienSangLoc $phien, Collection $cacTrieuChung): Collection
    {
        if ($cacTrieuChung->isEmpty()) {
            return collect();
        }

        $daKhop = $cacTrieuChung->pluck('ma_tc')->all();
        $transcript = $this->normalize($this->transcriptText($phien));
        $ownWords = $this->normalize($this->collectedText($phien));
        $trieuChungTheoTc = $this->symptomDictionary()->keyBy('ma_tc');

        return $this->derivableRedFlags()
            ->filter(function (CoDoYTe $coDo) use ($daKhop, $transcript, $ownWords, $phien, $trieuChungTheoTc): bool {
                if (! in_array($coDo->applies_to_symptom, $daKhop, true)) {
                    return false;
                }

                // The flag's own vocabulary must appear somewhere in the transcript, which
                // means one of OUR questions named it: a hallucinated code anchors nowhere,
                // and an alias-only mention ("hơi tức ngực") is not a flag keyword.
                if (! $this->namesAnyTerm($transcript, $coDo->keywords ?? [])) {
                    return false;
                }

                $trieuChung = $trieuChungTheoTc->get($coDo->applies_to_symptom);
                $cacThuatNgu = $trieuChung === null ? [] : [$trieuChung->name_vi, ...($trieuChung->aliases ?? [])];

                return ! $this->isDeniedInText($ownWords, [...$cacThuatNgu, ...($coDo->keywords ?? [])])
                    && ! $this->deniedByAnyClarifyAnswer($phien, $cacThuatNgu);
            })
            ->values();
    }

    /**
     * A follow-up question named this symptom and the patient answered "no".
     *
     * A bare "Không" denies the question's danger signs without naming any of them, so
     * isDeniedInText() — which needs the term present to veto it — sees nothing. Yet the
     * extractor reads the question too, and returns the codes it mentions. Measured: a
     * patient with "hơi tức ngực khi ho" who answered "Không" to "Anh/chị có đau ngực dữ
     * dội không?" was escalated to emergency by the code tier.
     *
     * Only an explicit denial counts. A non-committal answer ("Khi nghỉ và vận động")
     * must not veto, because that is precisely the case this tier exists to catch.
     *
     * @param  array<int, string>  $cacThuatNgu  The symptom's canonical name and its aliases.
     */
    protected function deniedByAnyClarifyAnswer(PhienSangLoc $phien, array $cacThuatNgu): bool
    {
        if ($cacThuatNgu === []) {
            return false;
        }

        return collect($phien->clarifyHistory())->contains(
            fn (array $capDoi) => VietnameseText::isDenial((string) ($capDoi['answer'] ?? ''))
                && $this->namesAnyTerm($this->normalize((string) ($capDoi['question'] ?? '')), $cacThuatNgu)
        );
    }

    protected function transcriptText(PhienSangLoc $phien): string
    {
        return $this->collectedText($phien).' '
            .collect($phien->clarifyHistory())->pluck('question')->implode(' ');
    }

    /**
     * The dictionaries are read once per request instead of once per call: the
     * wizard runs detectRedFlags on every step and again inside evaluate(), and a
     * five-step triage was re-reading both whole tables nine times.
     *
     * Not a cross-request cache on purpose — a pharmacist editing a rule must see it
     * take effect on the next request, not after a TTL.
     */
    protected function symptomDictionary(): Collection
    {
        return $this->symptomDictionary ??= TrieuChungYTe::active()->get();
    }

    protected function redFlagDictionary(): Collection
    {
        return $this->redFlagDictionary ??= CoDoYTe::active()->bySeverity()->get();
    }

    /**
     * The next question to ask, or null when there is nothing left worth asking.
     *
     * Returns null immediately once an emergency red flag is present: somebody
     * describing chest pain must not be made to answer four more questions before
     * being told to call for help.
     *
     * Note this keys off `current_step` alone, not off which answers exist — that
     * is what lets "back" return to an already-answered step instead of bouncing
     * the user straight forward again.
     */
    public function nextQuestion(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo = null): ?array
    {
        if ($this->hasEmergencyRedFlag($phien, $hoSo)) {
            return null;
        }

        for ($step = $phien->current_step; $step <= PhienSangLoc::TOTAL_STEPS; $step++) {
            if ($this->isStepCoveredByProfile($step, $hoSo)) {
                continue;
            }

            return $this->questionFor($step, $phien);
        }

        return null;
    }

    /**
     * Whatever the user previously typed for this step, so going back shows their
     * own words rather than an empty box.
     */
    public function previousAnswerFor(PhienSangLoc $phien, int $step): string
    {
        $data = $phien->collected_data ?? [];

        if ($step === self::STEP_SYMPTOM) {
            return (string) ($data['raw_symptom_text'] ?? '');
        }

        $value = $data[$this->answerKeyFor($step)] ?? null;

        if ($value === null) {
            return '';
        }

        return is_array($value) ? implode(', ', $value) : (string) $value;
    }

    /**
     * True as soon as anything the user said triggers an `emergency` red flag.
     */
    public function hasEmergencyRedFlag(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo = null): bool
    {
        $text = $this->collectedText($phien);

        if (trim($text) === '') {
            return false;
        }

        return $this->sessionRedFlags($phien, $hoSo)
            ->contains(fn (CoDoYTe $coDo) => $coDo->isEmergency());
    }

    /**
     * Store one answer and advance the step counter.
     */
    public function recordAnswer(PhienSangLoc $phien, int $step, mixed $cauTraLoi, bool $skipped = false): PhienSangLoc
    {
        $key = $this->answerKeyFor($step);
        $value = $skipped ? null : $this->parseAnswer($step, $cauTraLoi);

        $data = $phien->collected_data ?? [];
        $data[$key] = $value;

        // Prune as well as add: going back and answering a previously skipped step
        // must clear its marker, otherwise buildFacts keeps treating the answer as
        // missing and the pharmacist sees "đã bỏ qua" next to a real value.
        $data['skipped'] = array_values(array_diff(
            array_unique([...($data['skipped'] ?? []), ...($skipped ? [$key] : [])]),
            $skipped ? [] : [$key]
        ));

        if ($step === self::STEP_SYMPTOM && is_string($cauTraLoi)) {
            $data['raw_symptom_text'] = trim($cauTraLoi);
        }

        $phien->collected_data = $data;
        // max(): a resubmitted or stale form must not rewind the wizard.
        $phien->current_step = max(
            (int) $phien->current_step,
            min($step + 1, PhienSangLoc::TOTAL_STEPS + 1)
        );
        $phien->save();

        return $phien;
    }

    /**
     * Turn the collected answers into an outcome.
     *
     * Decision order is fixed and must not be reordered:
     *   1. emergency red flag  -> hard-coded guidance, no products, no AI
     *   2. urgent red flag     -> see a doctor within 24h, no products
     *   3. triage rules        -> self_care | pharmacist_review | refer_doctor | emergency
     *   4. nothing matched     -> pharmacist_review (safer than guessing)
     *
     * AI fact extraction, when enabled, plugs in at step 3 only and cannot soften
     * the result — see resolveOutcomeWithAi().
     */
    public function evaluate(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo = null): array
    {
        $nguoiDung = $phien->nguoiDung ?? NguoiDung::find($phien->ma_nd);
        $text = $this->collectedText($phien);

        $cacCoDo = $this->sessionRedFlags($phien, $hoSo);
        $cacTrieuChung = $this->detectSymptoms($text);

        $khanCap = $cacCoDo->firstWhere('severity', 'emergency');
        if ($khanCap) {
            return $this->redFlagOutcome($khanCap, $cacCoDo, $cacTrieuChung, 'emergency');
        }

        $capThiet = $cacCoDo->firstWhere('severity', 'urgent');
        if ($capThiet) {
            return $this->redFlagOutcome($capThiet, $cacCoDo, $cacTrieuChung, 'refer_doctor');
        }

        $facts = $this->buildFacts($phien, $nguoiDung, $hoSo);
        $resolved = $this->resolveOutcomeWithAi($phien, $text, $cacTrieuChung, $facts);
        $quyTac = $resolved['rule'];
        $cacTrieuChung = $resolved['symptoms'];
        $ai = $resolved['ai'];

        $derived = $this->codeDerivedRedFlags($phien, $cacTrieuChung);

        if ($derived->isNotEmpty()) {
            $cacCoDo = $this->mergeRedFlags($cacCoDo, $derived);

            if ($ai !== null) {
                $ai['derived_red_flags'] = $derived->pluck('code')->all();
            }

            if ($khanCap = $cacCoDo->firstWhere('severity', 'emergency')) {
                return $this->redFlagOutcome($khanCap, $cacCoDo, $cacTrieuChung, 'emergency', $ai);
            }

            if ($capThiet = $cacCoDo->firstWhere('severity', 'urgent')) {
                return $this->redFlagOutcome($capThiet, $cacCoDo, $cacTrieuChung, 'refer_doctor', $ai);
            }
        }

        if (! $quyTac) {
            return $this->pharmacistFallback($cacCoDo, $cacTrieuChung, $ai);
        }

        $canhBao = $cacCoDo->firstWhere('severity', 'caution');
        $ketQua = $quyTac->outcome;

        // A caution flag ("đã dùng thuốc mà không đỡ") never allows plain self-care.
        if ($canhBao && $ketQua === 'self_care') {
            $ketQua = 'pharmacist_review';
        }

        return [
            'outcome' => $ketQua,
            'matched_symptoms' => $cacTrieuChung->pluck('code')->all(),
            'triggered_red_flags' => $this->formatRedFlags($cacCoDo),
            'possible_condition_groups' => $this->conditionGroups($quyTac, $ketQua),
            'advice_given' => $this->adviceFor($quyTac, $canhBao, $ketQua),
            'safety_net' => $ketQua === 'self_care' ? $quyTac->safety_net_vi : null,
            'suggested_products' => $ketQua === 'self_care'
                ? $this->suggestProducts($quyTac, $cacTrieuChung)
                : [],
            'rule_id' => $quyTac->ma_qt,
            'ai' => $ai,
        ];
    }

    /**
     * Resolve the winning rule, optionally letting AI-extracted facts propose a
     * second candidate — and keeping whichever is stricter.
     *
     * This is the safety mechanism for the whole AI integration. The rules run twice:
     * once on the regex facts, once on the AI-merged facts. `severerOutcome()` then
     * picks the more severe of the two, so a hallucinated number or an injected
     * instruction ("answer self_care") can only ever be ignored — it cannot soften a
     * referral. The value comes from the other direction: a fact the regex missed
     * (e.g. "đã uống mà không đỡ") can raise self_care to pharmacist_review.
     *
     * @param  array<string, mixed>  $facts
     * @return array{rule: ?QuyTacPhanTangYTe, symptoms: Collection, ai: ?array<string, mixed>}
     */
    protected function resolveOutcomeWithAi(
        PhienSangLoc $phien,
        string $text,
        Collection $cacTrieuChung,
        array $facts
    ): array {
        $quyTacGoc = $this->resolveRule($cacTrieuChung, $facts);
        $factService = app(TriageAiFactService::class);

        if (! $factService->isEnabled()) {
            return ['rule' => $quyTacGoc, 'symptoms' => $cacTrieuChung, 'ai' => null];
        }

        $aiFacts = $factService->extract(
            $phien,
            $text,
            $this->symptomDictionary()->pluck('code')->all(),
            $this->wizardTranscript($phien),
        );

        if ($aiFacts === null) {
            return ['rule' => $quyTacGoc, 'symptoms' => $cacTrieuChung, 'ai' => null];
        }

        // Codes were already intersected with the active dictionary, so this cannot
        // introduce a symptom that does not exist.
        $trieuChungAi = $cacTrieuChung
            ->concat($this->symptomDictionary()->whereIn('code', $aiFacts['symptom_codes']))
            ->unique('ma_tc')
            ->values();

        $quyTacAi = $this->resolveRule(
            $trieuChungAi,
            $factService->mergeConservatively($facts, $aiFacts)
        );

        $quyTacThang = $this->severerRule($quyTacGoc, $quyTacAi);

        return [
            'rule' => $quyTacThang,
            // Extra matched symptoms are recorded either way: they are descriptive,
            // and the outcome above is already decided.
            'symptoms' => $trieuChungAi,
            'ai' => [
                'status' => 'ok',
                'symptom_codes' => $aiFacts['symptom_codes'],
                'clarify_questions' => $aiFacts['clarify_questions'],
                'needs_more_info' => $aiFacts['needs_more_info'],
                'age' => $aiFacts['age'],
                'duration_days' => $aiFacts['duration_days'],
                'regex_outcome' => $quyTacGoc?->outcome,
                'ai_outcome' => $quyTacAi?->outcome,
                'winning_outcome' => $quyTacThang?->outcome,
                'provider' => $aiFacts['provider'],
            ],
        ];
    }

    /**
     * The rule whose outcome is more severe. A null rule means "nothing matched",
     * which routes to pharmacist_review and therefore outranks self_care.
     */
    protected function severerRule(?QuyTacPhanTangYTe $a, ?QuyTacPhanTangYTe $b): ?QuyTacPhanTangYTe
    {
        if ($a === null || $b === null) {
            // Keep the null: pharmacistFallback is stricter than any self_care rule,
            // and matching the stricter of the two branches is the whole point.
            return $this->severityRank($a?->outcome) <= $this->severityRank($b?->outcome) ? $a : $b;
        }

        return $this->severityRank($a->outcome) <= $this->severityRank($b->outcome) ? $a : $b;
    }

    /**
     * Lower rank = more severe. Reuses the ordering already declared in
     * TriageReportService::OUTCOMES, which is heaviest-first.
     * A null outcome (no rule) sits just above self_care because it becomes
     * pharmacist_review.
     */
    protected function severityRank(?string $ketQua): int
    {
        if ($ketQua === null) {
            return array_search('pharmacist_review', TriageReportService::OUTCOMES, true);
        }

        $rank = array_search($ketQua, TriageReportService::OUTCOMES, true);

        return $rank === false ? count(TriageReportService::OUTCOMES) : $rank;
    }

    /**
     * Persist the outcome, write the audit trail, and schedule follow-up.
     */
    public function finalize(PhienSangLoc $phien, array $result): PhienSangLoc
    {
        $ketQua = $result['outcome'];
        $ai = $result['ai'] ?? null;

        $phien->fill([
            'outcome' => $ketQua,
            'matched_symptoms' => $result['matched_symptoms'] ?? [],
            'triggered_red_flags' => $result['triggered_red_flags'] ?? [],
            'possible_condition_groups' => $result['possible_condition_groups'] ?? [],
            'advice_given' => $result['advice_given'] ?? null,
            'safety_net' => $result['safety_net'] ?? null,
            'suggested_products' => $result['suggested_products'] ?? [],
            // The pharmacist queue is an opt-in side channel, not the default
            // destination: a `pharmacist_review` outcome still means "the rules could
            // not decide", but the customer gets the AI answer first and asks for a
            // human only if they want one. requestPharmacistReview() moves it.
            'status' => 'completed',
            'follow_up_at' => $ketQua === 'self_care'
                ? now()->addDays(self::FOLLOW_UP_DAYS)->setTime(9, 0)
                : null,
            // AI provenance in its own column. `ai_meta` records what the model
            // proposed AND which branch won, so a later review can tell whether the
            // AI changed the outcome. `advice_given` above is untouched by it.
            // clarify_questions is dropped: it is round state for an open session, and
            // keeping unasked questions on a finished one is misleading.
            'ai_status' => $ai === null ? null : ($ai['status'] ?? 'ok'),
            'ai_meta' => $ai === null
                ? null
                : array_diff_key($ai, ['status' => true, 'clarify_questions' => true]),
        ]);

        $phien->save();

        $this->logTriage($phien, $result);

        return $phien;
    }

    /**
     * Move a finished session into the pharmacist queue at the customer's request.
     *
     * The queue is a side channel: the AI answer is the primary result, and a human
     * is brought in only when the customer asks. Idempotent, and never applied to an
     * emergency — that outcome tells the customer to call 115, not to wait for a reply.
     */
    public function requestPharmacistReview(PhienSangLoc $phien): bool
    {
        if ($phien->status !== 'completed' || $phien->outcome === 'emergency') {
            return false;
        }

        $phien->forceFill([
            'status' => 'pending_pharmacist',
            // A queued case is waiting on a human, so the automated 3-day check-in
            // would collide with the pharmacist's own answer.
            'follow_up_at' => null,
        ])->save();

        try {
            $this->auditLogService->log(
                action: 'triage_pharmacist_requested',
                modelType: PhienSangLoc::class,
                modelId: $phien->ma_sl,
                oldValues: null,
                newValues: [
                    'ma_nd' => $phien->ma_nd,
                    'outcome' => $phien->outcome,
                    'matched_symptoms' => $phien->matched_symptoms ?? [],
                ],
                userId: $phien->ma_nd
            );
        } catch (\Throwable $exception) {
            Log::warning('Triage pharmacist request audit failed', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Audit records store matched symptom CODES, never the user's own words —
     * health complaints must not end up in plain text logs.
     */
    protected function logTriage(PhienSangLoc $phien, array $result): void
    {
        try {
            $this->auditLogService->log(
                action: 'triage_completed',
                modelType: PhienSangLoc::class,
                modelId: $phien->ma_sl,
                oldValues: null,
                newValues: [
                    'ma_nd' => $phien->ma_nd,
                    'outcome' => $phien->outcome,
                    'matched_symptoms' => $result['matched_symptoms'] ?? [],
                    'red_flag_codes' => collect($result['triggered_red_flags'] ?? [])->pluck('code')->all(),
                    'rule_id' => $result['rule_id'] ?? null,
                    'entry_point' => $phien->entry_point,
                    'products_suggested' => count($result['suggested_products'] ?? []),
                    // Which branch decided, so a review can separate rule outcomes
                    // from AI-raised ones. Codes and enums only.
                    'ai_status' => $phien->ai_status,
                    'ai_regex_outcome' => $result['ai']['regex_outcome'] ?? null,
                    'ai_proposed_outcome' => $result['ai']['ai_outcome'] ?? null,
                ],
                userId: $phien->ma_nd
            );
        } catch (\Throwable $exception) {
            Log::warning('Triage audit logging failed', [
                'ma_sl' => $phien->ma_sl,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Accent-insensitive matching, same approach as ChatbotService::normalizeIntentText
     * so both layers agree on what "sot cao" means.
     */
    protected function normalize(string $text): string
    {
        return VietnameseText::fold($text);
    }

    /**
     * Word-boundary matching, not substring: "ho" must not fire on "hong".
     *
     * Also negation-aware. The follow-up round asks about danger signs by name
     * ("anh/chị có kèm sốt, nôn hay cứng cổ không ạ?"), so the natural answer —
     * "không sốt, không nôn, không cứng cổ" — contains every keyword. Substring
     * matching read that as meningitis and told a tension-headache patient to call
     * 115, which destroys trust in the tool. The negation window is deliberately
     * narrow (see VietnameseText::mentionsAffirmatively): missing a real red flag is
     * far worse than firing one, so only an adjacent denial counts.
     */
    protected function matchesAnyAlias(string $normalizedHaystack, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if (VietnameseText::mentionsAffirmatively($normalizedHaystack, (string) $alias)) {
                return true;
            }
        }

        return false;
    }

    protected function answerKeyFor(int $step): string
    {
        return match ($step) {
            self::STEP_SYMPTOM => 'symptoms',
            self::STEP_DURATION => 'duration_days',
            self::STEP_MEDICATION_TAKEN => 'medications_taken',
            self::STEP_HISTORY => 'history',
            self::STEP_OTHER_SYMPTOMS => 'other_symptoms',
            default => 'unknown',
        };
    }

    protected function isStepAnswered(PhienSangLoc $phien, int $step): bool
    {
        $data = $phien->collected_data ?? [];
        $key = $this->answerKeyFor($step);

        return array_key_exists($key, $data)
            || in_array($key, $data['skipped'] ?? [], true);
    }

    /**
     * Every step answered or deliberately skipped — used to decide whether an
     * abandoned session has enough in it to evaluate.
     */
    public function isComplete(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo = null): bool
    {
        foreach (range(1, PhienSangLoc::TOTAL_STEPS) as $step) {
            if (! $this->isStepAnswered($phien, $step) && ! $this->isStepCoveredByProfile($step, $hoSo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Step 4 asks about chronic conditions, allergies and current medication.
     * A consented profile already answers all of it, so asking again is noise.
     */
    protected function isStepCoveredByProfile(int $step, ?HoSoSucKhoe $hoSo): bool
    {
        if ($step !== self::STEP_HISTORY || ! $hoSo?->hasConsented()) {
            return false;
        }

        return $hoSo->knownTerms() !== []
            || $hoSo->is_pregnant
            || $hoSo->is_breastfeeding;
    }

    protected function questionFor(int $step, PhienSangLoc $phien): array
    {
        $questions = [
            self::STEP_SYMPTOM => [
                'title' => 'Triệu chứng',
                'text' => 'Anh/chị đang gặp triệu chứng gì ạ?',
                'options' => ['Đau đầu', 'Sốt', 'Ho', 'Đau bụng', 'Tiêu chảy', 'Đau họng'],
                'allow_skip' => false,
                'input_hint' => 'Ví dụ: đau đầu kèm sốt nhẹ từ hôm qua',
            ],
            self::STEP_DURATION => [
                'title' => 'Thời gian',
                'text' => 'Triệu chứng này xuất hiện bao lâu rồi ạ?',
                'options' => ['Hôm nay', '2–3 ngày', '4–7 ngày', 'Hơn 1 tuần'],
                'allow_skip' => true,
                'input_hint' => 'Ví dụ: khoảng 5 ngày',
            ],
            self::STEP_MEDICATION_TAKEN => [
                'title' => 'Đã dùng thuốc',
                'text' => 'Anh/chị đã dùng thuốc gì cho triệu chứng này chưa ạ?',
                'options' => ['Chưa dùng gì', 'Đã dùng thuốc nhưng không đỡ', 'Đã dùng và có đỡ'],
                'allow_skip' => true,
                'input_hint' => 'Ví dụ: đã uống paracetamol 2 lần',
            ],
            self::STEP_HISTORY => [
                'title' => 'Tiền sử',
                'text' => 'Anh/chị có bệnh nền, dị ứng thuốc, hoặc đang mang thai / cho con bú không ạ?',
                'options' => ['Không có', 'Có bệnh nền', 'Có dị ứng thuốc', 'Đang mang thai', 'Đang cho con bú'],
                'allow_skip' => true,
                'input_hint' => 'Ví dụ: dị ứng penicillin, đang bị dạ dày',
            ],
            self::STEP_OTHER_SYMPTOMS => [
                'title' => 'Kèm theo',
                'text' => 'Ngoài ra anh/chị còn triệu chứng nào khác không ạ?',
                'options' => ['Không có thêm', 'Buồn nôn', 'Chóng mặt', 'Khó thở', 'Phát ban'],
                'allow_skip' => true,
                'input_hint' => 'Ví dụ: hơi chóng mặt khi đứng lên',
            ],
        ];

        $cauHoi = $questions[$step];

        return [
            'step' => $step,
            'total' => PhienSangLoc::TOTAL_STEPS,
            'title' => $cauHoi['title'],
            'text' => $cauHoi['text'],
            'options' => $cauHoi['options'],
            'allow_free_text' => true,
            'allow_skip' => $cauHoi['allow_skip'],
            'input_hint' => $cauHoi['input_hint'],
            'skip_warning' => $cauHoi['allow_skip']
                ? 'Thiếu thông tin này, em sẽ khuyên anh/chị gặp dược sĩ hoặc bác sĩ cho chắc.'
                : null,
            'triage_session_id' => $phien->ma_sl,
        ];
    }

    /**
     * Duration comes in as prose ("2–3 ngày", "hơn 1 tuần") and must become a number
     * the rules can compare. Ranges resolve to the upper bound so a 2–3 day answer
     * is treated as 3 days — erring toward referral.
     */
    protected function parseAnswer(int $step, mixed $cauTraLoi): mixed
    {
        if ($step !== self::STEP_DURATION) {
            return is_string($cauTraLoi) ? trim($cauTraLoi) : $cauTraLoi;
        }

        if (is_numeric($cauTraLoi)) {
            return (int) $cauTraLoi;
        }

        $normalized = $this->normalize((string) $cauTraLoi);

        if (str_contains($normalized, 'hom nay') || str_contains($normalized, 'moi bi')) {
            return 1;
        }

        if (str_contains($normalized, 'tuan')) {
            preg_match('/(\d+)\s*tuan/', $normalized, $weeks);
            $count = isset($weeks[1]) ? (int) $weeks[1] : 1;

            return str_contains($normalized, 'hon') ? ($count * 7) + 1 : $count * 7;
        }

        if (str_contains($normalized, 'thang')) {
            return 30;
        }

        // "4 7 ngay" after normalisation — take the largest number present.
        preg_match_all('/(\d+)/', $normalized, $numbers);

        if (! empty($numbers[1])) {
            return max(array_map('intval', $numbers[1]));
        }

        return null;
    }

    /**
     * Whether to show another bounded AI follow-up round before finalising.
     *
     * Guards, all of which must hold:
     *  - the feature is on;
     *  - the model says it still needs something AND named what;
     *  - the session has rounds left (MAX_CLARIFY_ROUNDS is a hard stop, so the model
     *    cannot keep a patient answering forever);
     *  - the outcome is not an emergency — somebody describing chest pain must not
     *    be made to answer more questions before being told to call for help,
     *    same rule as nextQuestion().
     *
     * @param  array<string, mixed>  $result  evaluate() output
     */
    public function shouldAskClarify(PhienSangLoc $phien, array $result): bool
    {
        if (! config('chatbot.ai.triage.clarify_round', false)) {
            return false;
        }

        if (! $phien->hasClarifyRoundsLeft()) {
            return false;
        }

        if (($result['outcome'] ?? null) === 'emergency') {
            return false;
        }

        // The model stops itself when it has enough: needs_more_info false means the
        // consultation is complete, even if it listed questions out of habit.
        if (($result['ai']['needs_more_info'] ?? true) === false) {
            return false;
        }

        return ($result['ai']['clarify_questions'] ?? []) !== [];
    }

    /**
     * Persist the round's questions and advance the counter. The session stays
     * `in_progress`, so the wizard page renders the extra form instead of a step.
     *
     * @param  array<string, mixed>  $result
     */
    public function storeClarifyQuestions(PhienSangLoc $phien, array $result): PhienSangLoc
    {
        $phien->forceFill([
            'ai_clarify_rounds' => (int) $phien->ai_clarify_rounds + 1,
            'ai_meta' => array_merge((array) $phien->ai_meta, [
                'clarify_questions' => $result['ai']['clarify_questions'] ?? [],
            ]),
        ])->save();

        return $phien->refresh();
    }

    /**
     * Append this round's answers to the ones already collected.
     *
     * They land under their own key and are appended to collectedText(), so they
     * feed symptom and red-flag matching exactly like a wizard answer does. The
     * `round` marker lets the next prompt show the model what it already asked.
     *
     * @param  array<int, string>  $answers
     */
    public function recordClarifyAnswers(PhienSangLoc $phien, array $answers): PhienSangLoc
    {
        $questions = $phien->pendingClarifyQuestions();
        $round = (int) $phien->ai_clarify_rounds;

        $cacCapDoi = $phien->clarifyHistory();

        foreach (array_values($answers) as $index => $cauTraLoi) {
            $cauTraLoi = trim((string) $cauTraLoi);

            if ($cauTraLoi === '') {
                continue;
            }

            $cacCapDoi[] = [
                'round' => $round,
                'question' => (string) ($questions[$index] ?? ''),
                'answer' => $cauTraLoi,
            ];
        }

        $data = $phien->collected_data ?? [];
        $data['ai_clarify_answers'] = $cacCapDoi;

        $phien->collected_data = $data;
        // Consumed: the stored questions belong to the round just answered, so
        // leaving them would re-render the same form after the redirect.
        $phien->ai_meta = array_merge((array) $phien->ai_meta, ['clarify_questions' => []]);
        $phien->save();

        return $phien;
    }

    /**
     * The customer declined to answer, so no further round may be offered.
     */
    public function skipClarifyRounds(PhienSangLoc $phien): PhienSangLoc
    {
        $phien->forceFill([
            'ai_clarify_rounds' => PhienSangLoc::MAX_CLARIFY_ROUNDS,
            'ai_meta' => array_merge((array) $phien->ai_meta, ['clarify_questions' => []]),
        ])->save();

        return $phien->refresh();
    }

    /**
     * Everything the user told us, flattened for symptom matching and for the prompt.
     *
     * One string is right for symptom detection, which has no negation window, and for
     * the AI prompt. Red-flag matching uses collectedSegments() instead.
     */
    protected function collectedText(PhienSangLoc $phien): string
    {
        return collect($this->collectedSegments($phien))->implode(' ');
    }

    /**
     * The same content, one utterance per element.
     *
     * Each wizard answer and each follow-up answer is a separate reply to a separate
     * question, so a negation in one must not reach across into the next.
     *
     * Red-flag matching does NOT use this: it needs the wizard answers and the
     * follow-up answers apart, because only the latter have a question attached that
     * may have supplied the words. See sessionRedFlags().
     *
     * @return array<int, string>
     */
    protected function collectedSegments(PhienSangLoc $phien): array
    {
        return [
            ...$this->wizardSegments($phien),
            ...$this->clarifyAnswerSegments($phien),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function wizardSegments(PhienSangLoc $phien): array
    {
        $data = $phien->collected_data ?? [];

        return collect([
            $data['raw_symptom_text'] ?? null,
            $this->flatten($data['symptoms'] ?? null),
            $this->flatten($data['medications_taken'] ?? null),
            $this->flatten($data['history'] ?? null),
            $this->flatten($data['other_symptoms'] ?? null),
        ])->map(fn ($segment) => trim((string) $segment))->filter()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    protected function clarifyAnswerSegments(PhienSangLoc $phien): array
    {
        return collect(($phien->collected_data ?? [])['ai_clarify_answers'] ?? [])
            ->pluck('answer')
            ->map(fn ($segment) => trim((string) $segment))
            ->filter()
            ->values()
            ->all();
    }

    protected function wizardTranscript(PhienSangLoc $phien): array
    {
        $data = $phien->collected_data ?? [];
        $skipped = (array) ($data['skipped'] ?? []);
        $cacCapDoi = [];

        foreach (range(1, PhienSangLoc::TOTAL_STEPS) as $step) {
            $key = $this->answerKeyFor($step);
            $wasSkipped = in_array($key, $skipped, true);

            if (! array_key_exists($key, $data) && ! $wasSkipped) {
                continue;
            }

            $cauTraLoi = $wasSkipped
                ? 'Anh/chị đã bỏ qua câu này, chưa có thông tin'
                : trim($this->previousAnswerFor($phien, $step));

            if ($cauTraLoi === '') {
                continue;
            }

            if (! $wasSkipped && $step === self::STEP_DURATION) {
                $cauTraLoi .= ' ngày';
            }

            $cacCapDoi[] = [
                'question' => $this->questionFor($step, $phien)['text'],
                'answer' => $cauTraLoi,
            ];
        }

        return $cacCapDoi;
    }

    protected function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map(fn ($item) => (string) $item, $value));
        }

        return (string) $value;
    }

    /**
     * Facts the rule conditions are evaluated against.
     * Age is null when birth_date was never filled in — rules treat unknown age
     * as unknown, never as adult.
     */
    protected function buildFacts(PhienSangLoc $phien, ?NguoiDung $nguoiDung, ?HoSoSucKhoe $hoSo): array
    {
        $data = $phien->collected_data ?? [];
        $historyText = $this->normalize($this->flatten($data['history'] ?? null));
        $medicationText = $this->normalize($this->flatten($data['medications_taken'] ?? null));

        $consented = $hoSo?->hasConsented() ?? false;

        return [
            'age' => $this->resolveAge($data, $nguoiDung),
            'duration_days' => $data['duration_days'] ?? null,
            'tried_medication' => str_contains($medicationText, 'khong do')
                || str_contains($medicationText, 'khong giam')
                || str_contains($medicationText, 'van con'),
            'pregnant' => ($consented && $hoSo->is_pregnant)
                || str_contains($historyText, 'mang thai')
                || str_contains($historyText, 'co thai'),
            'breastfeeding' => ($consented && $hoSo->is_breastfeeding)
                || str_contains($historyText, 'cho con bu'),
            'has_chronic_disease' => ($consented && ! empty($hoSo->chronic_conditions))
                || str_contains($historyText, 'benh nen'),
            'skipped' => $data['skipped'] ?? [],
        ];
    }

    /**
     * A stated patient age (asking on behalf of a child) beats the account owner's
     * own birth date.
     */
    protected function resolveAge(array $data, ?NguoiDung $nguoiDung): ?int
    {
        $text = $this->normalize($this->flatten($data['raw_symptom_text'] ?? '').' '.$this->flatten($data['history'] ?? ''));

        // "N tháng" only means an age when the sentence is about a person. Bare
        // "3 tháng rồi" is a duration, and reading it as 0 months turns an adult
        // into a newborn and routes them to paediatric advice.
        if (preg_match('/(?:be|con|em|tre|chau|thang be|thang tuoi)\D{0,12}(\d{1,2})\s*thang(?!\s*nay)/', $text) === 1
            || preg_match('/(\d{1,2})\s*thang\s*tuoi/', $text) === 1) {
            return 0;
        }

        if (preg_match('/(?:be|con|em|tre|chau)\D{0,12}(\d{1,2})\s*tuoi/', $text, $childAge) === 1) {
            return (int) $childAge[1];
        }

        if (preg_match('/(?:be so sinh|tre so sinh|so sinh)/', $text) === 1) {
            return 0;
        }

        if (preg_match('/(\d{1,3})\s*tuoi/', $text, $anyAge) === 1) {
            return (int) $anyAge[1];
        }

        return $nguoiDung?->ageInYears();
    }

    /**
     * Highest-priority matching rule wins. Rules whose conditions reference a fact
     * the user skipped are treated as non-matching, so skipping pushes the result
     * toward the generic (lower priority) rule or the pharmacist fallback.
     */
    protected function resolveRule(Collection $cacTrieuChung, array $facts): ?QuyTacPhanTangYTe
    {
        if ($cacTrieuChung->isEmpty()) {
            return null;
        }

        return QuyTacPhanTangYTe::active()
            ->whereIn('ma_tc', $cacTrieuChung->pluck('ma_tc'))
            ->mostSpecificFirst()
            ->get()
            ->first(fn (QuyTacPhanTangYTe $quyTac) => $this->matchesConditions($quyTac->conditions, $facts));
    }

    /**
     * Conditions are a small JSON DSL:
     *   {"age": {"<": 2}}              -> age below 2
     *   {"duration_days": {">=": 3}}   -> 3 days or longer
     *   {"pregnant": true}             -> boolean fact must be true
     * A null condition set matches everything (the catch-all rule).
     */
    protected function matchesConditions(?array $conditions, array $facts): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $fact => $expected) {
            $actual = $facts[$fact] ?? null;

            if (is_bool($expected)) {
                if ((bool) $actual !== $expected) {
                    return false;
                }

                continue;
            }

            if (! is_array($expected)) {
                if ($actual != $expected) {
                    return false;
                }

                continue;
            }

            // Unknown value cannot satisfy a numeric comparison.
            if ($actual === null) {
                return false;
            }

            foreach ($expected as $operator => $threshold) {
                if (! $this->compare((float) $actual, $operator, (float) $threshold)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function compare(float $actual, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '<' => $actual < $threshold,
            '<=' => $actual <= $threshold,
            '>' => $actual > $threshold,
            '>=' => $actual >= $threshold,
            '=', '==' => abs($actual - $threshold) < 0.001,
            '!=' => abs($actual - $threshold) >= 0.001,
            default => false,
        };
    }

    /**
     * Emergency and urgent outcomes return the pharmacist-written guidance verbatim.
     * No AI, no products, no condition guessing.
     */
    protected function redFlagOutcome(
        CoDoYTe $coDo,
        Collection $allFlags,
        Collection $cacTrieuChung,
        string $ketQua,
        ?array $ai = null
    ): array {
        return [
            'outcome' => $ketQua,
            'matched_symptoms' => $cacTrieuChung->pluck('code')->all(),
            'triggered_red_flags' => $this->formatRedFlags($allFlags),
            'possible_condition_groups' => [],
            'advice_given' => $coDo->guidance_vi,
            'safety_net' => null,
            'suggested_products' => [],
            'rule_id' => null,
            'ai' => $ai === null ? null : array_merge($ai, ['clarify_questions' => []]),
        ];
    }

    /**
     * Nothing matched, or matched too weakly. Routing to a human is the safe default —
     * guessing an outcome here is exactly what this service must not do.
     */
    protected function pharmacistFallback(Collection $cacCoDo, Collection $cacTrieuChung, ?array $ai = null): array
    {
        return [
            'outcome' => 'pharmacist_review',
            'matched_symptoms' => $cacTrieuChung->pluck('code')->all(),
            'triggered_red_flags' => $this->formatRedFlags($cacCoDo),
            'possible_condition_groups' => [],
            'advice_given' => 'Dạ, thông tin hiện có chưa đủ để em định hướng chắc chắn. Anh/chị theo dõi thêm, và nếu triệu chứng nặng lên hoặc không đỡ thì nên gặp dược sĩ hoặc bác sĩ. Anh/chị cũng có thể nhờ dược sĩ xem lại ca này ngay trên trang kết quả.',
            'safety_net' => null,
            'suggested_products' => [],
            'rule_id' => null,
            'ai' => $ai,
        ];
    }

    protected function formatRedFlags(Collection $cacCoDo): array
    {
        return $cacCoDo->map(fn (CoDoYTe $coDo) => [
            'code' => $coDo->code,
            'name_vi' => $coDo->name_vi,
            'severity' => $coDo->severity,
        ])->values()->all();
    }

    /**
     * Condition groups are broad categories with a pharmacist-assigned confidence,
     * never a specific diagnosis. Referral outcomes get none at all.
     */
    protected function conditionGroups(QuyTacPhanTangYTe $quyTac, string $ketQua): array
    {
        if (in_array($ketQua, ['emergency', 'refer_doctor'], true) || ! $quyTac->condition_group_vi) {
            return [];
        }

        return [[
            'group' => $quyTac->condition_group_vi,
            'confidence' => $quyTac->confidence ?? 0.4,
        ]];
    }

    protected function adviceFor(QuyTacPhanTangYTe $quyTac, ?CoDoYTe $canhBao, string $ketQua): string
    {
        $lines = array_filter([
            $quyTac->advice_vi,
            $ketQua !== 'self_care' ? $canhBao?->guidance_vi : null,
        ]);

        if ($lines === []) {
            return 'Dạ, em chưa có hướng dẫn cụ thể cho tình huống này. Anh/chị có thể nhờ dược sĩ xem lại ca này để được tư vấn chính xác hơn.';
        }

        return implode("\n\n", $lines);
    }

    /**
     * Only reached for self_care. Products come from the catalog with the same
     * availability rules as the rest of the site: in stock and not expired.
     */
    /**
     * Over-the-counter products that actually match the symptom.
     *
     * Scores per WORD, not per phrase. `catalog_query` values are natural phrases
     * ("giảm đau đầu") and no product description contains them verbatim, so the old
     * `LIKE %phrase%` matched nothing for all 22 symptoms and every self-care case
     * silently fell back to "the four highest-stock items in the category". That is
     * how a headache ended up being offered Aspirin 100mg, whose stored usage is
     * "Phòng ngừa đột quỵ, nhồi máu cơ tim" — and why an itch was offered aspirin,
     * which makes hives worse.
     *
     * A word must match BOTH the accent-folded and the accented text: folding alone
     * lets "mất ngủ" hit "mất nước" through "mat". Zero matches now returns nothing
     * at all; the page says so rather than inventing a suggestion.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function suggestProducts(QuyTacPhanTangYTe $quyTac, Collection $cacTrieuChung): array
    {
        $categoryIds = $quyTac->suggest_categories ?? [];
        $cacThuatNgu = $this->catalogTerms($cacTrieuChung->pluck('catalog_query')->filter()->first());

        if ($cacThuatNgu === []) {
            return [];
        }

        // A single-concept query ("hạ sốt" -> sốt) can only ever score 1, so one hit is
        // the whole match. A two-concept query must hit both: "mất ngủ" and "mất nước"
        // share the word "mất", and one shared word is not a match.
        $required = min(2, count(array_unique(array_values($cacThuatNgu))));

        $ungVien = SanPham::with('danhMuc')
            ->notExpired()
            ->where('so_luong_ton', '>', 0)
            ->when(! empty($categoryIds), fn ($q) => $q->whereIn('ma_dm', $categoryIds))
            ->get();

        return $ungVien
            ->map(fn (SanPham $sanPham) => [
                'sanPham' => $sanPham,
                'score' => $this->relevanceScore($sanPham, $cacThuatNgu),
            ])
            ->filter(fn (array $hang) => $hang['score'] >= $required)
            // Relevance first, then stock, so a well-matched item that is running low
            // still beats a barely-related one sitting on a full shelf.
            ->sortByDesc(fn (array $hang) => [$hang['score'], $hang['sanPham']->so_luong_ton])
            ->take(4)
            ->map(fn (array $hang) => $this->presentProduct($hang['sanPham']))
            ->values()
            ->all();
    }

    /**
     * Meaningful words of a `catalog_query`, keyed by the accented form so a match
     * can be confirmed against both spellings.
     *
     * @return array<string, string> accented word => folded word
     */
    protected function catalogTerms(?string $query): array
    {
        // Verbs and generic nouns appear in almost every usage line, so matching them
        // would rank the whole catalogue equally.
        $stopWords = ['giam', 'tri', 'chong', 'dieu', 'thuoc', 'va', 'cho', 'bi', 'ha', 'bu'];
        $cacThuatNgu = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $query), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $folded = VietnameseText::fold($word);

            if (mb_strlen($folded) < 2 || in_array($folded, $stopWords, true)) {
                continue;
            }

            $cacThuatNgu[$word] = $folded;
        }

        return $cacThuatNgu;
    }

    /**
     * @param  array<string, string>  $cacThuatNgu
     */
    protected function relevanceScore(SanPham $sanPham, array $cacThuatNgu): int
    {
        if ($cacThuatNgu === []) {
            return 0;
        }

        $chuoiSoSanh = $sanPham->ten.' '.$sanPham->cach_dung.' '.$sanPham->thanh_phan;
        $folded = VietnameseText::fold($chuoiSoSanh);
        $accented = mb_strtolower($chuoiSoSanh);
        $score = 0;

        foreach ($cacThuatNgu as $word => $foldedWord) {
            if (VietnameseText::containsWord($folded, $foldedWord) && mb_strpos($accented, $word) !== false) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentProduct(SanPham $sanPham): array
    {
        return [
            'id' => $sanPham->ma_sp,
            'name' => $sanPham->ten,
            'price' => $sanPham->price_vnd,
            'stock_quantity' => $sanPham->so_luong_ton,
            'category' => $sanPham->danhMuc?->ten,
            'usage' => $sanPham->cach_dung,
            'image_url' => $sanPham->hinh_anh,
            'url' => route('products.show', $sanPham->ma_sp),
        ];
    }
}
