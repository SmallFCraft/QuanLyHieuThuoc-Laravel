<?php

namespace App\Services\Triage;

use App\Models\CoDoYTe;
use App\Models\TrieuChungYTe;
use App\Models\QuyTacPhanTangYTe;
use App\Models\SanPham;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use App\Services\NhatKyKiemToanService;
use App\Services\Triage\Ai\SangLocAiSuKienService;
use App\Support\VietnameseText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Sang loc trieu chung co cau truc theo khung cau hoi WWHAM / ASMETHOD
 * va mo hinh chuyen tu van STARZ-DRP.
 *
 * Day la cong cu sang loc, KHONG phai chan doan: no quyet dinh nguoi dung co tu cham soc
 * duoc khong, can duoc si, hay phai di khoa. Moi quyet dinh den tu
 * quy tac trong database, khong bao gio tu model ngon ngu. Tang AI chi viet lai nhung gi
 * dich vu nay da quyet dinh.
 */
class SangLocYTeService
{
    public const STEP_SYMPTOM = 1;

    public const STEP_DURATION = 2;

    public const STEP_MEDICATION_TAKEN = 3;

    public const STEP_HISTORY = 4;

    public const STEP_OTHER_SYMPTOMS = 5;

    public const DISCLAIMER = 'Đây là công cụ sàng lọc, không phải chẩn đoán y khoa. Nội dung do hệ thống tạo ra có thể không chính xác, không thay thế việc gặp bác sĩ hoặc dược sĩ.';

    protected const FOLLOW_UP_DAYS = 3;

    public function __construct(
        protected NhatKyKiemToanService $nhatKyKiemToanService,
    ) {}

    /**
     * Tu dien y khoa, doc mot lan moi request. @see symptomDictionary()
     */
    protected ?Collection $symptomDictionary = null;

    protected ?Collection $redFlagDictionary = null;

    /**
     * Tiep tuc phien dang mo cua nguoi dung hoac bat dau phien moi. Phien theo tung nguoi dung,
     * nen triage lam muon van con sau khi tai lai trang hoac dong browser.
     */
    public function batDauHoacTiepTuc(int $userId, ?string $chatSessionId = null, string $entryPoint = 'page'): PhienSangLoc
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
     * Khop tin nhan van ban tu do voi tu dien trieu chung.
     *
     * Chay doc lap voi AI: neu nha cung cap bi chet hoac tra ve gi do khong dung duoc,
     * viet lai phong do trieu chung van hoat dong tren tin nhan goc.
     */
    public function phanLoaiTrieuChung(string $message): Collection
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
     * Co do duoc kiem tra voi ca tin nhan VA ho so suc khoe da luu,
     * vi "dang mang thai" co the den tu ho so chu khong phai tin nhan.
     *
     * Giu nhu mot lan tim tren toan tin nhan vi day la entry point cong khai ma chatbot
     * va tests dung. Phong do theo phien di qua sessionRedFlags(), ham
     * khop moi cau tra loi rieng — xem comment do.
     */
    public function phanLoaiCoDo(string $message, ?HoSoSucKhoe $hoSo = null): Collection
    {
        return $this->matchRedFlagSegments([$message, ...$this->profileTerms($hoSo)]);
    }

    /**
     * @return array<int, string> Cac thuat ngu ho so da dong y, moi thuat ngu la mot cau phat nguyen rieng.
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
     * Khop co do voi nhieu cau phat nguyen doc lap, roi hop lai.
     *
     * Dung cac doan chu, khong phai mot chuoi noi, vi cua so phu dinh trong
     * mentionsAffirmatively() chay nguoc qua hai dong tu nhe va khong the biet
     * hai tu den tu hai cau hoi khac nhau. Do thu te: wizard buoc 4 tra loi
     * "Khong co" roi buoc 5 "kho tho nhieu lam" duoc noi thanh "... khong co kho tho
     * nhieu lam", va "khong co" canh ke da huy mot truong hop khan cap that. Cung
     * tinh canh ke con lam vo hieu mot thuat ngu ho so nam ngay sau cau tra loi cuoi.
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
     * Cau tra loi hoi lam duoc khop voi viec biet cau hoi nao sinh ra no.
     *
     * Vong hoi lam cua wizard hoi ve muc do bang cach dua cho benh nhan mot thang do
     * ("nhe, vua hay du doi?"), nen lua chon dau la tu cua ta, chu khong phai cua ho. Khop can
     * voi tu khoa `severe_pain` khong gioi han, nhan chon no da nang cap moi benh nhan nhu vay
     * patient to a 115 call. Measured: dau kinh nguyet do "dau du doi" tro ve la
     * "dau du doi khong ro nguyen nhan" trong khi nguyen nhan nam o cau tra loi dau.
     *
     * Moi thu benh nhan tu nguyen noi van duoc tinh, do do chi tu vung ma
     * cau hoi chinh no duoc giam trong so — xem answerRaisesFlag().
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
     * Mot tu khoa chi duoc tinh khi cau tra loi thuc su dua no vao.
     *
     * Kiem tra thang do la thu giu phan do hep: mot cau hoi nhon mot dau hieu nguy hiem
     * ("co dau nguc du doi khong?") khong phai menu, nen `chest_pain_severe` giu nguyen
     * trong so day du tai do. Chi mot cau hoi dua ra nhieu muc do moi co the
     * giam trong so muc do ma cau tra loi mang ve.
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

                // Tu vung rieng cua co do phai xuat hien o dau do trong bang tong hop, nen
                // means one of OUR questions named it: ma code bi a tu khong co moc neo,
                // va chi neu ten khac ("hoi tuc nguc") khong phai tu khoa cua co do.
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
     * Mot cau hoi hoi lam nhon den trieu chung nay va benh nhan tra loi "khong".
     *
     * Mot "Khong" not tra loi phu dinh cac dau hieu nguy hiem cua cau hoi ma khong nhon ten, nen
     * isDeniedInText() — ham can thuat ngu xuat hien de phu dinh — thay khong co gi. Nhung
     * bo trich xuat cung bo cau hoi va tra ve cac ma no nhon den. Do thu te: mot
     * benh nhan voi "hoi tuc nguc khi ho" da tra loi "Khong" cho "Anh/chi co dau nguc du
     * doi khong?" bi nang cap len khan cap boi tang code.
     *
     * Chi mot lan phu dinh ro rang moi duoc tinh. Cau tra loi neo roi ("Khi nghi va van dong")
     * khong duoc phu dinh, vi dung la truong hop ma tang nay ton tai de bat.
     *
     * @param  array<int, string>  $cacThuatNgu  Ten chuan cua trieu chung va cac ten khac cua no.
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
     * Tu dien doc mot lan moi request thay vi moi lan goi: vi
     * wizard chay phanLoaiCoDo o moi buoc va lai lan trong danhGia(), va mot
     * triage nam buoc da doc lai ca hai bang day du chin lan.
     *
     * Co chu khong phai cache lien request — duoc si sua quy tac phai thay no
     * co tac dung ngay request tiep theo, chu khong phai sau mot TTL.
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
     * Cau hoi tiep theo can hoi, hoac null khi khong con gi dang hoi.
     *
     * Tra ve null ngay khi mot co do khan cap ton tai: mot nguoi
     * mo ta dau nguc phai bi ep tra loi them bon cau truoc khi
     * duoc bao goi cuu.
     *
     * Luu y ham doc chi `current_step`, khong doc cau tra loi ton tai — dieu do
     * giup nut "back" quay ve buoc da tra loi thay vi day nguoi dung
     * nhay thang tuong lai.
     */
    public function cauHoiTiep(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo = null): ?array
    {
        if ($this->coCapCuuCuu($phien, $hoSo)) {
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
     * Nhung gi nguoi dung da go cho buoc nay truoc do, nen quay lai thay van ban cua ho
     * chu khong phai o trong.
     */
    public function cauTraLoiBaiTruoc(PhienSangLoc $phien, int $step): string
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
     * Cong ngay khi bat cu gi nguoi dung noi kich hoat mot co do `emergency`.
     */
    public function coCapCuuCuu(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo = null): bool
    {
        $text = $this->collectedText($phien);

        if (trim($text) === '') {
            return false;
        }

        return $this->sessionRedFlags($phien, $hoSo)
            ->contains(fn (CoDoYTe $coDo) => $coDo->isEmergency());
    }

    /**
     * Luu mot cau tra loi va tang bien dem buoc.
     */
    public function ghiCauTraLoi(PhienSangLoc $phien, int $step, mixed $cauTraLoi, bool $skipped = false): PhienSangLoc
    {
        $key = $this->answerKeyFor($step);
        $value = $skipped ? null : $this->parseAnswer($step, $cauTraLoi);

        $data = $phien->collected_data ?? [];
        $data[$key] = $value;

        // Prune as well as add: quay lai va tra loi mot buoc da bo qua truoc do
        // phai xoa marker cua no, neu khong buildFacts van coi cau tra loi la
        // thieu va duoc si thay "da bo qua" ben can mot gia tri that.
        $data['skipped'] = array_values(array_diff(
            array_unique([...($data['skipped'] ?? []), ...($skipped ? [$key] : [])]),
            $skipped ? [] : [$key]
        ));

        if ($step === self::STEP_SYMPTOM && is_string($cauTraLoi)) {
            $data['raw_symptom_text'] = trim($cauTraLoi);
        }

        $phien->collected_data = $data;
        // max(): mot form submit lai hoac cu khong duoc lui wizard ve sau.
        $phien->current_step = max(
            (int) $phien->current_step,
            min($step + 1, PhienSangLoc::TOTAL_STEPS + 1)
        );
        $phien->save();

        return $phien;
    }

    /**
     * Bien cac cau tra loi da thu thanh mot ket qua.
     *
     * Thu tu quyet dinh la co dinh va khong duoc xep lai:
     *   1. co do khan cap  -> huong dan viet cung, khong san pham, khong AI
     *   2. co do khan cap o muc nho hon -> di khoa trong 24h, khong san pham
     *   3. quy tac sang loc        -> self_care | pharmacist_review | refer_doctor | emergency
     *   4. khong khop gi      -> pharmacist_review (an toan hon la doan)
     *
     * Trich xuat chi tiet AI, khi bat, chi tham gia o buoc 3 va khong the lam nhe
     * ket qua — xem resolveOutcomeWithAi().
     */
    public function danhGia(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo = null): array
    {
        $nguoiDung = $phien->nguoiDung ?? NguoiDung::find($phien->ma_nd);
        $text = $this->collectedText($phien);

        $cacCoDo = $this->sessionRedFlags($phien, $hoSo);
        $cacTrieuChung = $this->phanLoaiTrieuChung($text);

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

        // Co canh bao ("da dung thuoc ma khong do") khong bao gio cho tu cham soc thuan.
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
     * Giai quy tac thang, cho phep chi tiet AI trich xuat de nghi mot
     * ung cu vien thu hai — va giu phia nao nghiem hon.
     *
     * Day la co che an toan cho toan bo tinh hop AI. Quy tac chay hai lan:
     * mot lan tren chi tiet regex, mot lan tren chi tiet hop AI. `severerOutcome()` roi
     * chon phia nghiem hon trong hai, nen mot con so bi a tu hoac mot lenh duoc
     * chen vao ("answer self_care") chi co the bi bo qua — no khong the lam nhe
     * chuyen tu van. Gia tri den tu phia khac: mot chi tiet ma regex bo sot
     * (vd "da uong ma khong do") co the nang self_care len pharmacist_review.
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
        $factService = app(SangLocAiSuKienService::class);

        if (! $factService->daBat()) {
            return ['rule' => $quyTacGoc, 'symptoms' => $cacTrieuChung, 'ai' => null];
        }

        $aiFacts = $factService->trichXuat(
            $phien,
            $text,
            $this->symptomDictionary()->pluck('code')->all(),
            $this->wizardTranscript($phien),
        );

        if ($aiFacts === null) {
            return ['rule' => $quyTacGoc, 'symptoms' => $cacTrieuChung, 'ai' => null];
        }

        // Ma da duoc giao voi tu dien dang hoat dong truoc do, nen buoc nay khong the
        // chen vao mot trieu chung khong ton tai.
        $trieuChungAi = $cacTrieuChung
            ->concat($this->symptomDictionary()->whereIn('code', $aiFacts['symptom_codes']))
            ->unique('ma_tc')
            ->values();

        $quyTacAi = $this->resolveRule(
            $trieuChungAi,
            $factService->gopThanTrong($facts, $aiFacts)
        );

        $quyTacThang = $this->severerRule($quyTacGoc, $quyTacAi);

        return [
            'rule' => $quyTacThang,
            // Extra matched symptoms are recorded either way: chung chi la mo ta,
            // va ket qua phia tren da duoc quyet dinh.
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
     * Quy tac co ket qua nghiem hon. Quy tac null nghia la "khong khop gi",
     * dieu do dan den pharmacist_review va vi vay di truoc self_care.
     */
    protected function severerRule(?QuyTacPhanTangYTe $a, ?QuyTacPhanTangYTe $b): ?QuyTacPhanTangYTe
    {
        if ($a === null || $b === null) {
            // Keep the null: pharmacistFallback nghiem hon moi quy tac self_care,
            // va khop phia nghiem hon trong hai nhanh la toan bo y nghia.
            return $this->severityRank($a?->outcome) <= $this->severityRank($b?->outcome) ? $a : $b;
        }

        return $this->severityRank($a->outcome) <= $this->severityRank($b->outcome) ? $a : $b;
    }

    /**
     * Rank nho = nghiem hon. Tai dung thu tu da khai bao trong
     * BaoCaoSangLocService::OUTCOMES, ham sap theo do nang truoc.
     * Ket qua null (khong quy tac) nam ngay tren self_care vi no tro thanh
     * pharmacist_review.
     */
    protected function severityRank(?string $ketQua): int
    {
        if ($ketQua === null) {
            return array_search('pharmacist_review', BaoCaoSangLocService::OUTCOMES, true);
        }

        $rank = array_search($ketQua, BaoCaoSangLocService::OUTCOMES, true);

        return $rank === false ? count(BaoCaoSangLocService::OUTCOMES) : $rank;
    }

    /**
     * Luu ket qua, ghi nhat ky kiem toan, va len lich theo doi sau sang loc.
     */
    public function hoanTat(PhienSangLoc $phien, array $result): PhienSangLoc
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
            // Hang doi duoc si la kenh phu tuy chon, chu khong phai mac dinh
            // destination: ket qua `pharmacist_review` van co nghia "bo quy tac khong
            // quyet dinh duoc", nhung khach hang nhan cau tra loi AI truoc va chi moi nguoi
            // khi ho muon. yeuCauDuyetDacSi() chuyen phien sang hang doi.
            'status' => 'completed',
            'follow_up_at' => $ketQua === 'self_care'
                ? now()->addDays(self::FOLLOW_UP_DAYS)->setTime(9, 0)
                : null,
            // Nguon goc AI nam trong cot rieng. `ai_meta` ghi lai model de nghi gi
            // VA nhanh nao thang, nen mot lan xem lai co the biet AI co thay doi
            // ket qua hay khong. `advice_given` phia tren khong bi no dong den.
            // clarify_questions is dropped: no la trang thai vong cho phien dang mo, va
            // giu cau hoi chua hoi tren phien da chot la loi dan dau.
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
     * Chuyen phien da chot vao hang doi duoc si theo yeu cau cua khach hang.
     *
     * The queue is a side channel: cau tra loi AI la ket qua chinh, chi moi nguoi vao
     * khi khach hang yeu cau. Idempotent, va khong bao gio ap dung cho
     * khan cap — ket qua do bao khach hang goi 115, chu khong phai cho tra loi.
     */
    public function yeuCauDuyetDacSi(PhienSangLoc $phien): bool
    {
        if ($phien->status !== 'completed' || $phien->outcome === 'emergency') {
            return false;
        }

        $phien->forceFill([
            'status' => 'pending_pharmacist',
            // Ca dang cho la cho nguoi, nen kiem tra tu dong sau 3 ngay
            // se va vao cau tra loi rieng cua duoc si.
            'follow_up_at' => null,
        ])->save();

        try {
            $this->nhatKyKiemToanService->ghi(
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
     * Ban ghi kiem toan luu CODE trieu chung da khop, khong bao gio la loi nguoi dung —
     * loi ban than suc khoe khong duoc roi vao log van ban thuan.
     */
    protected function logTriage(PhienSangLoc $phien, array $result): void
    {
        try {
            $this->nhatKyKiemToanService->ghi(
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
                    // Nhanh nao quyet dinh, nen mot lan xem lai co the tach ket qua quy tac
                    // khoi ket qua AI nang len. Chi ma va enum.
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
     * Khop khong can dau, cung cach lam voi ChatbotService::normalizeIntentText
     * nen hai tang dong y ve nghia "sot cao".
     */
    protected function normalize(string $text): string
    {
        return VietnameseText::fold($text);
    }

    /**
     * Word-boundary matching, not substring: "ho" khong duoc kich hoat khi gap "hong".
     *
     * Co nhan phu dinh nua. Vong hoi lam hoi dau hieu nguy hiem bang ten
     * ("anh/chi co kem sot, non hay cung co khong a?"), nen cau tra loi tu nhien —
     * "khong sot, khong non, khong cung co" — chua moi tu khoa. Khop con
     * doc cau do la viem nao va bao benh nhan dau dau do kich goi 115,
     * dieu do pha hai long tin vao cong cu. Cua so phu dinh duoc thiet ke chu dich
     * narrow (see VietnameseText::mentionsAffirmatively): bo sot mot co do that la
     * xau hon nhieu so voi bat mot co do nham, nen chi phu dinh canh ke moi duoc tinh.
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
     * Moi buoc da tra loi hoac bo qua co chu dich — dung de quyet dinh phien
     * bo giua chung co du de danh gia hay khong.
     */
    public function daHoanThanh(PhienSangLoc $phien, ?HoSoSucKhoe $hoSo = null): bool
    {
        foreach (range(1, PhienSangLoc::TOTAL_STEPS) as $step) {
            if (! $this->isStepAnswered($phien, $step) && ! $this->isStepCoveredByProfile($step, $hoSo)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Buoc 4 hoi ve benh man tinh, di ung va thuoc dang dung.
     * Ho so da dong y da tra loi het phan do, nen hoi lai la am thanh gian doan.
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
     * Thoi luong den duoi dang van ban ("2–3 ngay", "hon 1 tuan") va phai thanh con so
     * bo quy tac co the so sanh. Khoang gia tri giai ve bien tren nen cau tra loi 2–3 ngay
     * duoc coi la 3 ngay — co nang huong chuyen tu van.
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

        // "4 7 ngay" sau khi chuan hoa — lay con so lon nhat xuat hien.
        preg_match_all('/(\d+)/', $normalized, $numbers);

        if (! empty($numbers[1])) {
            return max(array_map('intval', $numbers[1]));
        }

        return null;
    }

    /**
     * Co nen mo them mot vong hoi lam AI co gioi han truoc khi chot phien khong.
     *
     * Cac dieu kien, tat ca deu phai dung:
     *  - tinh nang dang bat;
     *  - model noi no van can them thong tin VA nhon duoc can gi;
     *  - phien con du vong hoi (MAX_CLARIFY_ROUNDS la nut dung cung, nen model
     *    khong the giu benh nhan tra loi mai mai);
     *  - ket qua khong phai khan cap — nguoi mo ta dau nguc khong duoc
     *    bi ep tra loi them cau truoc khi duoc bao goi cuu,
     *    cung quy tac voi cauHoiTiep().
     *
     * @param  array<string, mixed>  $result  ket qua tra ve cua danhGia()
     */
    public function canHoiLamRo(PhienSangLoc $phien, array $result): bool
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

        // Model tu dung khi du thong tin: needs_more_info false co nghia la
        // buoc tu van da xong, du model co liet ke cau hoi theo thong quen.
        if (($result['ai']['needs_more_info'] ?? true) === false) {
            return false;
        }

        return ($result['ai']['clarify_questions'] ?? []) !== [];
    }

    /**
     * Luu cau hoi cua vong va tang bien dem. Phien van giu
     * `in_progress`, nen trang wizard render form them thay vi mot buoc.
     *
     * @param  array<string, mixed>  $result
     */
    public function luuCChamLamRo(PhienSangLoc $phien, array $result): PhienSangLoc
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
     * Noi cau tra loi cua vong nay vao nhung gi da thu.
     *
     * Chung nam duoi key rieng va duoc noi vao collectedText(), nen chung
     * duoc dua vao khop trieu chung va co do dung nhu mot cau tra loi wizard. Marker
     * `round` giup prompt sau cho model thay no da hoi gi.
     *
     * @param  array<int, string>  $cac cau tra loi
     */
    public function ghiCacChamLamRo(PhienSangLoc $phien, array $answers): PhienSangLoc
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
        // Consumed: cau hoi da luu thuoc ve vong vua tra loi, nen
        // de yen chung se render lai dung form do sau redirect.
        $phien->ai_meta = array_merge((array) $phien->ai_meta, ['clarify_questions' => []]);
        $phien->save();

        return $phien;
    }

    /**
     * Khach hang tu choi tra loi, nen khong vong nao duoc de nghi tiep.
     */
    public function boQuaCauHoiLamRo(PhienSangLoc $phien): PhienSangLoc
    {
        $phien->forceFill([
            'ai_clarify_rounds' => PhienSangLoc::MAX_CLARIFY_ROUNDS,
            'ai_meta' => array_merge((array) $phien->ai_meta, ['clarify_questions' => []]),
        ])->save();

        return $phien->refresh();
    }

    /**
     * Moi thu nguoi dung da noi, lam phang cho khop trieu chung va cho prompt.
     *
     * Mot chuoi dung cho phong do trieu chung, ham khong co cua so phu dinh, va cho
     * prompt AI. Khop co do dung collectedSegments() thay the.
     */
    protected function collectedText(PhienSangLoc $phien): string
    {
        return collect($this->collectedSegments($phien))->implode(' ');
    }

    /**
     * Cung noi dung, moi phan tu la mot cau phat nguyen.
     *
     * Moi cau tra loi wizard va moi cau tra loi hoi lam la mot cau tra loi cho mot cau hoi
     * rieng, nen phu dinh trong mot cau khong duoc lan sang cau ke tiep.
     *
     * Khop co do KHONG dung ham nay: ham can tach cau tra loi wizard va
     * cau tra loi hoi lam thanh hai phan, vi chi phia sau co cau hoi kem theo ma
     * co the da cung tu ngu. Xem sessionRedFlags().
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
                : trim($this->cauTraLoiBaiTruoc($phien, $step));

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
     * Cac chi tiet ma dieu kien quy tac duoc danh gia tren do.
     * Tuoi la null khi birth_date chua duoc dien — quy tac coi tuoi khong biet
     * la khong biet, chu khong phai nguoi lon.
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
     * Tuoi benh nhan duoc noi ro (hoi cho tre con) uu tien hon ngay sinh
     * cua chu tai khoan.
     */
    protected function resolveAge(array $data, ?NguoiDung $nguoiDung): ?int
    {
        $text = $this->normalize($this->flatten($data['raw_symptom_text'] ?? '').' '.$this->flatten($data['history'] ?? ''));

        // "N thang" chi co nghia la tuoi khi cau noi ve mot nguoi. Tho
        // "3 thang roi" la thoi luong, va doc no thanh 0 thang bien nguoi lon
        // thanh so sinh va dan ho sang loi khuyen nhi khoa.
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
     * Quy tac khop co uu tien cao nhat thang. Quy tac co dieu kien tham chieu
     * mot chi tiet nguoi dung da bo qua duoc coi la khong khop, nen bo qua day ket qua
     * ve phia quy tac chung (uu tien thap hon) hoac pha du phong duoc si.
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
     * Dieu kien la mot JSON DSL nho:
     *   {"age": {"<": 2}}              -> tuoi duoi 2
     *   {"duration_days": {">=": 3}}   -> 3 ngay tro len
     *   {"pregnant": true}             -> chi tiet boolean phai la true
     * Bo dieu kien null khop moi thu (quy tac chot tat ca).
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

            // Gia tri khong biet khong the dap ung mot phep so sanh so hoc.
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
     * Ket qua khan cap va can thiet tra ve huong dan duoc si viet nguyen van.
     * Khong AI, khong san pham, khong doan chan doan.
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
     * Khong khop gi, hoac khop qua yeu. Dan sang nguoi la mac dinh an toan —
     * doan ket qua tai day dung la dieu dich vu nay khong duoc lam.
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
     * Nhom benh la cac nhom rong voi do tin cai do duoc si gan,
     * khong bao gio la chan doan cu the. Ket qua chuyen tu van khong co gi het.
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
     * Chi cham den cho self_care. San pham den tu danh muc voi cung
     * availability rules as the rest of the site: con hang va chua het han.
     */
    /**
     * San pham ban tu do thuc su khop voi trieu chung.
     *
     * Cham diem theo TU, khong phai theo cum. Gia tri `catalog_query` la cum tu nhien
     * ("giam dau dau") va khong mo ta san pham nao chua dung chinh xac chung, nen cach
     * `LIKE %phrase%` cu khop gi cho ca 22 trieu chung va moi truong hop tu cham soc
     * lan im lang ve "bon san pham co ton cao nhat trong danh muc". Vi vay
     * mo truong hop dau dau lai duoc goi y Aspirin 100mg, co cach dung luu la
     * "Phong ngua dot quy, nhoi mau co tim" — va vi sao benh ngo da duoc goi y aspirin,
     * thu lam ngo ton hai hon.
     *
     * Mot tu phai khop CA dua khong dau va ban ban co dau: khong dau don le
     * de "mat ngu" trung "mat nuoc" qua "mat". Khop bang khong tra ve gi het
     * trong nay; trang noi ro thay vi bia ra mot goi y.
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

        // Truy van mot khai niem ("ha sot" -> sot) chi cham toi da diem 1, nen mot trung la
        // toan bo khop. Truy van hai khai niem phai trung ca hai: "mat ngu" va "mat nuoc"
        // dung chung tu "mat", va mot tu dung chung khong phai la khop.
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
            // Lien quan truoc, roi ton, nen san pham khop tot sap het van
            // thang san pham lien quan yeu nhung ton day ke.
            ->sortByDesc(fn (array $hang) => [$hang['score'], $hang['sanPham']->so_luong_ton])
            ->take(4)
            ->map(fn (array $hang) => $this->presentProduct($hang['sanPham']))
            ->values()
            ->all();
    }

    /**
     * Cac tu co nghia cua `catalog_query`, key la dang co dau de khop
     * duoc xac nhan tren ca hai dang viet.
     *
     * @return array<string, string> tu co dau => tu khong dau
     */
    protected function catalogTerms(?string $query): array
    {
        // Dong tu va danh tu chung xuat hien trong gan moi dong cach dung, nen khop chung
        // se lam ca danh muc cung xep hang.
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
            'url' => route('san-pham.show', $sanPham->ma_sp),
        ];
    }
}