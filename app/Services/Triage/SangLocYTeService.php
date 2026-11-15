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
 * Sàng lọc triệu chứng có cấu trúc theo khung câu hỏi WWHAM / ASMETHOD
 * và mô hình chuyển tự vấn STARZ-DRP.
 *
 * Đây là công cụ sàng lọc, KHÔNG phải chẩn đoán: nó quyết định người dùng có tự chăm sóc
 * được không, cần dược sĩ, hay phải đi khám. Mọi quyết định đến từ
 * quy tắc trong database, không bao giờ từ mô hình ngôn ngữ. Tầng AI chỉ viết lại những gì
 * dịch vụ này đã quyết định.
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
     * Từ điển y khoa, đọc một lần mỗi request. @see symptomDictionary()
     */
    protected ?Collection $symptomDictionary = null;

    protected ?Collection $redFlagDictionary = null;

    /**
     * Tiếp tục phiên đang mở của người dùng hoặc bắt đầu phiên mới. Phiên theo từng người dùng,
     * nên triage làm dở vẫn còn sau khi tải lại trang hoặc đóng browser.
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
     * Khớp tin nhắn văn bản tự do với từ điển triệu chứng.
     *
     * Chạy độc lập với AI: nếu nhà cung cấp bị chết hoặc trả về gì đó không dùng được,
     * viết lại phỏng đoán triệu chứng vẫn hoạt động trên tin nhắn gốc.
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
     * Cờ đỏ được kiểm tra với cả tin nhắn VÀ hồ sơ sức khỏe đã lưu,
     * vì "đang mang thai" có thể đến từ hồ sơ chứ không phải tin nhắn.
     *
     * Giữ như một lần tìm trên toàn tin nhắn vì đây là entry point công khai mà chatbot
     * và tests dùng. Phỏng đoán theo phiên đi qua sessionRedFlags(), hàm
     * khớp mỗi câu trả lời riêng — xem comment đó.
     */
    public function phanLoaiCoDo(string $message, ?HoSoSucKhoe $hoSo = null): Collection
    {
        return $this->matchRedFlagSegments([$message, ...$this->profileTerms($hoSo)]);
    }

    /**
     * @return array<int, string> Các thuật ngữ hồ sơ đã đồng ý, mỗi thuật ngữ là một câu phát nguyện riêng.
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
     * Khớp cờ đỏ với nhiều câu phát nguyện độc lập, rồi hợp lại.
     *
     * Dùng các đoạn chứ, không phải một chuỗi nối, vì cửa sổ phủ định trong
     * mentionsAffirmatively() chạy ngược qua hai động từ nhẹ và không thể biết
     * hai từ đến từ hai câu hỏi khác nhau. Đo thực tế: wizard bước 4 trả lời
     * "Không có" rồi bước 5 "khó thở nhiều lắm" được nối thành "... không có khó thở
     * nhiều lắm", và "không có" cạnh kề đã hủy một trường hợp khẩn cấp thật. Cùng
     * tính cạnh kề còn làm vô hiệu một thuật ngữ hồ sơ nằm ngay sau câu trả lời cuối.
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
     * Câu trả lời hỏi làm được khớp với việc biết câu hỏi nào sinh ra nó.
     *
     * Vòng hỏi làm của wizard hỏi về mức độ bằng cách đưa cho bệnh nhân một thang đo
     * ("nhẹ, vừa hay dữ dội?"), nên lựa chọn đầu là từ của ta, chứ không phải của họ. Khớp cạn
     * với từ khóa `severe_pain` không giới hạn, nhấn chọn nó đã nâng cấp mọi bệnh nhân như vậy
     * lên một cuộc gọi 115. Đo thực tế: đau kinh nguyệt chấm "dau du doi" trả về là
     * "dau du doi khong ro nguyen nhan" trong khi nguyên nhân nằm ở câu trả lời đầu.
     *
     * Mọi thứ bệnh nhân tự nguyện nói vẫn được tính, do đó chỉ từ vựng mà
     * câu hỏi chính nó được giảm trọng số — xem answerRaisesFlag().
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
     * Một từ khóa chỉ được tính khi câu trả lời thực sự đưa nó vào.
     *
     * Kiểm tra thang đo là thứ giữ phần đó hẹp: một câu hỏi nhắc một dấu hiệu nguy hiểm
     * ("có đau ngực dữ dội không?") không phải menu, nên `chest_pain_severe` giữ nguyên
     * trọng số đầy đủ tại đó. Chỉ một câu hỏi đưa ra nhiều mức độ mới có thể
     * giảm trọng số mức độ mà câu trả lời mang về.
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

                // Từ vựng riêng của cờ đỏ phải xuất hiện ở đâu đó trong bảng tổng hợp, nghĩa là
                // một trong NHỮNG câu hỏi của TA đã nêu nó: mã code bịa ra không có mốc neo,
                // và chỉ nêu tên khác ("hoi tuc nguc") không phải từ khóa của cờ đỏ.
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
     * Một câu hỏi hỏi làm nhắm đến triệu chứng này và bệnh nhân trả lời "không".
     *
     * Một "Không" trần trả lời phủ định các dấu hiệu nguy hiểm của câu hỏi mà không nhắc tên, nên
     * isDeniedInText() — hàm cần thuật ngữ xuất hiện để phủ định — thấy không có gì. Nhưng
     * bộ trích xuất cũng bám câu hỏi và trả về các mã nó nhắm đến. Đo thực tế: một
     * bệnh nhân với "hoi tuc nguc khi ho" đã trả lời "Không" cho "Anh/chị có đau ngực dữ
     * dội không?" bị nâng cấp lên khẩn cấp bởi tầng code.
     *
     * Chỉ một lần phủ định rõ ràng mới được tính. Câu trả lời né tránh ("Khi nghi và vận động")
     * không được phủ định, vì đúng là trường hợp mà tầng này tồn tại để bắt.
     *
     * @param  array<int, string>  $cacThuatNgu  Tên chuẩn của triệu chứng và các tên khác của nó.
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
     * Từ điển đọc một lần mỗi request thay vì mỗi lần gọi: vì
     * wizard chạy phanLoaiCoDo ở mỗi bước và lại lần nữa trong danhGia(), và một
     * triage năm bước đã đọc lại cả hai bảng đầy đủ chín lần.
     *
     * Cố ý không cache liên request — dược sĩ sửa quy tắc phải thấy nó
     * có tác dụng ngay request tiếp theo, chứ không phải sau một TTL.
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
     * Câu hỏi tiếp theo cần hỏi, hoặc null khi không còn gì đáng hỏi.
     *
     * Trả về null ngay khi một cờ đỏ khẩn cấp tồn tại: một người
     * mô tả đau ngực phải bị ép trả lời thêm bốn câu trước khi
     * được bảo gọi cứu.
     *
     * Lưu ý hàm đọc chỉ `current_step`, không đọc câu trả lời tồn tại — điều đó
     * giúp nút "back" quay về bước đã trả lời thay vì đẩy người dùng
     * nhảy thẳng tương lai.
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
     * Những gì người dùng đã gõ cho bước này trước đó, nên quay lại thấy văn bản của họ
     * chứ không phải ô trống.
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
     * Đúng ngay khi bất cứ gì người dùng nói kích hoạt một cờ đỏ `emergency`.
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
     * Lưu một câu trả lời và tăng biến đếm bước.
     */
    public function ghiCauTraLoi(PhienSangLoc $phien, int $step, mixed $cauTraLoi, bool $skipped = false): PhienSangLoc
    {
        $key = $this->answerKeyFor($step);
        $value = $skipped ? null : $this->parseAnswer($step, $cauTraLoi);

        $data = $phien->collected_data ?? [];
        $data[$key] = $value;

        // Cắt tỉa cũng như thêm: quay lại và trả lời một bước đã bỏ qua trước đó
        // phải xóa marker của nó, nếu không buildFacts vẫn coi câu trả lời là
        // thiếu và dược sĩ thấy "đã bỏ qua" bên cạnh một giá trị thật.
        $data['skipped'] = array_values(array_diff(
            array_unique([...($data['skipped'] ?? []), ...($skipped ? [$key] : [])]),
            $skipped ? [] : [$key]
        ));

        if ($step === self::STEP_SYMPTOM && is_string($cauTraLoi)) {
            $data['raw_symptom_text'] = trim($cauTraLoi);
        }

        $phien->collected_data = $data;
        // max(): một form submit lại hoặc cũ không được lùi wizard về sau.
        $phien->current_step = max(
            (int) $phien->current_step,
            min($step + 1, PhienSangLoc::TOTAL_STEPS + 1)
        );
        $phien->save();

        return $phien;
    }

    /**
     * Biến các câu trả lời đã thu thành một kết quả.
     *
     * Thứ tự quyết định là cố định và không được xếp lại:
     *   1. cờ đỏ khẩn cấp  -> hướng dẫn viết cứng, không sản phẩm, không AI
     *   2. cờ đỏ khẩn cấp ở mức nhỏ hơn -> đi khám trong 24h, không sản phẩm
     *   3. quy tắc sàng lọc        -> self_care | pharmacist_review | refer_doctor | emergency
     *   4. không khớp gì      -> pharmacist_review (an toàn hơn là đoán)
     *
     * Trích xuất chi tiết AI, khi bật, chỉ tham gia ở bước 3 và không thể làm nhẹ
     * kết quả — xem resolveOutcomeWithAi().
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

        // Cờ cảnh báo ("đã dùng thuốc mà không đỡ") không bao giờ cho tự chăm sóc thuần.
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
     * Giải quy tắc thắng, cho phép chi tiết AI trích xuất đề nghị một
     * ứng cử viên thứ hai — và giữ phía nào nghiêm hơn.
     *
     * Đây là cơ chế an toàn cho toàn bộ tính hợp AI. Quy tắc chạy hai lần:
     * một lần trên chi tiết regex, một lần trên chi tiết hợp AI. `severerOutcome()` rồi
     * chọn phía nghiêm hơn trong hai, nên một con số bịa ra hoặc một lệnh được
     * chèn vào ("answer self_care") chỉ có thể bị bỏ qua — nó không thể làm nhẹ
     * chuyển tự vấn. Giá trị đến từ phía khác: một chi tiết mà regex bỏ sót
     * (vd "đã uống mà không đỡ") có thể nâng self_care lên pharmacist_review.
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

        // Mã đã được giao với từ điển đang hoạt động trước đó, nên bước này không thể
        // chèn vào một triệu chứng không tồn tại.
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
            // Triệu chứng khớp thêm được ghi lại theo cả hai cách: chúng chỉ là mô tả,
            // và kết quả phía trên đã được quyết định.
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
     * Quy tắc có kết quả nghiêm hơn. Quy tắc null nghĩa là "không khớp gì",
     * điều đó dẫn đến pharmacist_review và vì vậy đi trước self_care.
     */
    protected function severerRule(?QuyTacPhanTangYTe $a, ?QuyTacPhanTangYTe $b): ?QuyTacPhanTangYTe
    {
        if ($a === null || $b === null) {
            // Giữ null: pharmacistFallback nghiêm hơn mọi quy tắc self_care,
            // và khớp phía nghiêm hơn trong hai nhánh là toàn bộ ý nghĩa.
            return $this->severityRank($a?->outcome) <= $this->severityRank($b?->outcome) ? $a : $b;
        }

        return $this->severityRank($a->outcome) <= $this->severityRank($b->outcome) ? $a : $b;
    }

    /**
     * Rank nhỏ = nghiêm hơn. Tái dùng thứ tự đã khai báo trong
     * BaoCaoSangLocService::OUTCOMES, hàm sắp theo độ nặng trước.
     * Kết quả null (không quy tắc) nằm ngay trên self_care vì nó trở thành
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
     * Lưu kết quả, ghi nhật ký kiểm toán, và lên lịch theo dõi sau sàng lọc.
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
            // Hàng đợi dược sĩ là kênh phụ tùy chọn, chứ không phải đích mặc định:
            // kết quả `pharmacist_review` vẫn có nghĩa "bộ quy tắc không
            // quyết định được", nhưng khách hàng nhận câu trả lời AI trước và chỉ mời người
            // khi họ muốn. yeuCauDuyetDacSi() chuyển phiên sang hàng đợi.
            'status' => 'completed',
            'follow_up_at' => $ketQua === 'self_care'
                ? now()->addDays(self::FOLLOW_UP_DAYS)->setTime(9, 0)
                : null,
            // Nguồn gốc AI nằm trong cột riêng. `ai_meta` ghi lại model đề nghị gì
            // VÀ nhánh nào thắng, nên một lần xem lại có thể biết AI có thay đổi
            // kết quả hay không. `advice_given` phía trên không bị nó động đến.
            // clarify_questions bị bỏ: nó là trạng thái vòng cho phiên đang mở, và
            // giữ câu hỏi chưa hỏi trên phiên đã chốt là dẫn dắt sai.
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
     * Chuyển phiên đã chốt vào hàng đợi dược sĩ theo yêu cầu của khách hàng.
     *
     * Hàng đợi là kênh phụ: câu trả lời AI là kết quả chính, chỉ mời người vào
     * khi khách hàng yêu cầu. Idempotent, và không bao giờ áp dụng cho
     * khẩn cấp — kết quả đó bảo khách hàng gọi 115, chứ không phải chờ trả lời.
     */
    public function yeuCauDuyetDacSi(PhienSangLoc $phien): bool
    {
        if ($phien->status !== 'completed' || $phien->outcome === 'emergency') {
            return false;
        }

        $phien->forceFill([
            'status' => 'pending_pharmacist',
            // Ca đang chờ là chờ người, nên kiểm tra tự động sau 3 ngày
            // sẽ va vào câu trả lời riêng của dược sĩ.
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
     * Bản ghi kiểm toán lưu CODE triệu chứng đã khớp, không bao giờ là lời người dùng —
     * lời than thở sức khỏe không được rơi vào log văn bản thuần.
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
                    // Nhánh nào quyết định, nên một lần xem lại có thể tách kết quả quy tắc
                    // khỏi kết quả AI nâng lên. Chỉ mã và enum.
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
     * Khớp không cần dấu, cùng cách làm với ChatbotService::normalizeIntentText
     * nên hai tầng đồng ý về nghĩa "sốt cao".
     */
    protected function normalize(string $text): string
    {
        return VietnameseText::fold($text);
    }

    /**
     * Khớp ranh giới từ, không phải substring: "ho" không được kích hoạt khi gặp "họng".
     *
     * Có nhận phủ định nữa. Vòng hỏi làm hỏi dấu hiệu nguy hiểm bằng tên
     * ("anh/chị có kèm sốt, nôn hay cứng cổ không a?"), nên câu trả lời tự nhiên —
     * "không sốt, không nôn, không cứng cổ" — chứa mọi từ khóa. Khớp con
     * đọc câu đó là viêm não và bảo bệnh nhân đau đầu do kích gợi 115,
     * điều đó phá hại lòng tin vào công cụ. Cửa sổ phủ định được thiết kế chủ đích
     * hẹp (xem VietnameseText::mentionsAffirmatively): bỏ sót một cờ đỏ thật là
     * xấu hơn nhiều so với bắt một cờ đỏ nhầm, nên chỉ phủ định cạnh kề mới được tính.
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
     * Mỗi bước đã trả lời hoặc bỏ qua có chủ đích — dùng để quyết định phiên
     * bỏ giữa chừng có đủ để đánh giá hay không.
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
     * Bước 4 hỏi về bệnh mạn tính, dị ứng và thuốc đang dùng.
     * Hồ sơ đã đồng ý đã trả lời hết phần đó, nên hỏi lại là âm thanh gián đoạn.
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
     * Thời lượng đến dưới dạng văn bản ("2–3 ngày", "hơn 1 tuần") và phải thành con số
     * bộ quy tắc có thể so sánh. Khoảng giá trị giải về biên trên nên câu trả lời 2–3 ngày
     * được coi là 3 ngày — cố nghiêng hướng chuyển tự vấn.
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

        // "4 7 ngày" sau khi chuẩn hóa — lấy con số lớn nhất xuất hiện.
        preg_match_all('/(\d+)/', $normalized, $numbers);

        if (! empty($numbers[1])) {
            return max(array_map('intval', $numbers[1]));
        }

        return null;
    }

    /**
     * Có nên mở thêm một vòng hỏi làm AI có giới hạn trước khi chốt phiên không.
     *
     * Các điều kiện, tất cả đều phải đúng:
     *  - tính năng đang bật;
     *  - model nói nó vẫn cần thêm thông tin VÀ nhắm được cần gì;
     *  - phiên còn đủ vòng hỏi (MAX_CLARIFY_ROUNDS là nút dừng cứng, nên model
     *    không thể giữ bệnh nhân trả lời mãi mãi);
     *  - kết quả không phải khẩn cấp — người mô tả đau ngực không được
     *    bị ép trả lời thêm câu trước khi được bảo gọi cứu,
     *    cùng quy tắc với cauHoiTiep().
     *
     * @param  array<string, mixed>  $result  kết quả trả về của danhGia()
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

        // Model tự dừng khi đủ thông tin: needs_more_info false có nghĩa là
        // bước tự vấn đã xong, dù model có liệt kê câu hỏi theo thói quen.
        if (($result['ai']['needs_more_info'] ?? true) === false) {
            return false;
        }

        return ($result['ai']['clarify_questions'] ?? []) !== [];
    }

    /**
     * Lưu câu hỏi của vòng và tăng biến đếm. Phiên vẫn giữ
     * `in_progress`, nên trang wizard render form thêm thay vì một bước.
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
     * Nối câu trả lời của vòng này vào những gì đã thu.
     *
     * Chúng nằm dưới key riêng và được nối vào collectedText(), nên chúng
     * được đưa vào khớp triệu chứng và cờ đỏ đúng như một câu trả lời wizard. Marker
     * `round` giúp prompt sau cho model thấy nó đã hỏi gì.
     *
     * @param  array<int, string>  $answers  các câu trả lời
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
        // Consumed: câu hỏi đã lưu thuộc về vòng vừa trả lời, nên
        // để yên chúng sẽ render lại đúng form đó sau redirect.
        $phien->ai_meta = array_merge((array) $phien->ai_meta, ['clarify_questions' => []]);
        $phien->save();

        return $phien;
    }

    /**
     * Khách hàng từ chối trả lời, nên không vòng nào được đề nghị tiếp.
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
     * Mọi thứ người dùng đã nói, làm phẳng cho khớp triệu chứng và cho prompt.
     *
     * Một chuỗi đúng cho phỏng đoán triệu chứng, hàm không có cửa sổ phủ định, và cho
     * prompt AI. Khớp cờ đỏ dùng collectedSegments() thay thế.
     */
    protected function collectedText(PhienSangLoc $phien): string
    {
        return collect($this->collectedSegments($phien))->implode(' ');
    }

    /**
     * Cùng nội dung, mỗi phần tử là một câu phát nguyện.
     *
     * Mỗi câu trả lời wizard và mỗi câu trả lời hỏi làm là một câu trả lời cho một câu hỏi
     * riêng, nên phủ định trong một câu không được lan sang câu kế tiếp.
     *
     * Khớp cờ đỏ KHÔNG dùng hàm này: hàm cần tách câu trả lời wizard và
     * câu trả lời hỏi làm thành hai phần, vì chỉ phía sau có câu hỏi kèm theo mà
     * có thể đã cung từ ngữ. Xem sessionRedFlags().
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
     * Các chi tiết mà điều kiện quy tắc được đánh giá trên đó.
     * Tuổi là null khi birth_date chưa được điền — quy tắc coi tuổi không biết
     * là không biết, chứ không phải người lớn.
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
     * Tuổi bệnh nhân được nêu rõ (hỏi cho trẻ con) ưu tiên hơn ngày sinh
     * của chủ tài khoản.
     */
    protected function resolveAge(array $data, ?NguoiDung $nguoiDung): ?int
    {
        $text = $this->normalize($this->flatten($data['raw_symptom_text'] ?? '').' '.$this->flatten($data['history'] ?? ''));

        // "N tháng" chỉ có nghĩa là tuổi khi câu nói về một người. Trần
        // "3 tháng rồi" là thời lượng, và đọc nó thành 0 tháng biến người lớn
        // thành sơ sinh và dẫn họ sang lời khuyên nhi khoa.
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
     * Quy tắc khớp có ưu tiên cao nhất thắng. Quy tắc có điều kiện tham chiếu
     * một chi tiết người dùng đã bỏ qua được coi là không khớp, nên bỏ qua đẩy kết quả
     * về phía quy tắc chung (ưu tiên thấp hơn) hoặc pha dự phòng dược sĩ.
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
     * Điều kiện là một JSON DSL nhỏ:
     *   {"age": {"<": 2}}              -> tuổi dưới 2
     *   {"duration_days": {">=": 3}}   -> 3 ngày trở lên
     *   {"pregnant": true}             -> chi tiết boolean phải là true
     * Bộ điều kiện null khớp mọi thứ (quy tắc chốt tất cả).
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

            // Giá trị không biết không thể đáp ứng một phép so sánh số học.
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
     * Kết quả khẩn cấp và cần thiết trả về hướng dẫn dược sĩ viết nguyên văn.
     * Không AI, không sản phẩm, không đoán chẩn đoán.
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
     * Không khớp gì, hoặc khớp quá yếu. Dẫn sang người là mặc định an toàn —
     * đoán kết quả tại đây đúng là điều dịch vụ này không được làm.
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
     * Nhóm bệnh là các nhóm rộng với độ tin cậy do dược sĩ gán,
     * không bao giờ là chẩn đoán cụ thể. Kết quả chuyển tự vấn không có gì hết.
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
     * Chỉ chạm đến cho self_care. Sản phẩm đến từ danh mục với cùng
     * quy tắc khả dụng như phần còn lại của trang: còn hàng và chưa hết hạn.
     */
    /**
     * Sản phẩm bán tự do thực sự khớp với triệu chứng.
     *
     * Chấm điểm theo TỪ, không phải theo cụm. Giá trị `catalog_query` là cụm từ tự nhiên
     * ("giảm đau đầu") và không mô tả sản phẩm nào chứa đúng chính xác chúng, nên cách
     * `LIKE %phrase%` cũ không khớp gì cho cả 22 triệu chứng và mọi trường hợp tự chăm sóc
     * lặng im rơi về "bốn sản phẩm có tồn cao nhất trong danh mục". Vì vậy
     * mỗi trường hợp đau đầu lại được gợi ý Aspirin 100mg, có cách dùng lưu là
     * "Phòng ngừa đột quỵ, nhồi máu cơ tim" — và vì sao bệnh mề đay được gợi ý aspirin,
     * thứ làm mề đay tồi hại hơn.
     *
     * Một từ phải khớp CẢ dạng không dấu và bản bản có dấu: không dấu đơn lẻ
     * để "mất ngủ" trúng "mất nước" qua "mất". Khớp bằng không trả về gì hết
     * trong này; trang nói rõ thay vì bịa ra một gợi ý.
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

        // Truy vấn một khái niệm ("hạ sốt" -> sốt) chỉ chấm tối đa điểm 1, nên một trúng là
        // toàn bộ khớp. Truy vấn hai khái niệm phải trúng cả hai: "mất ngủ" và "mất nước"
        // dùng chung từ "mất", và một từ dùng chung không phải là khớp.
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
            // Liên quan trước, rồi tồn, nên sản phẩm khớp tốt sắp hết vẫn
            // thắng sản phẩm liên quan yếu nhưng tồn đầy kệ.
            ->sortByDesc(fn (array $hang) => [$hang['score'], $hang['sanPham']->so_luong_ton])
            ->take(4)
            ->map(fn (array $hang) => $this->presentProduct($hang['sanPham']))
            ->values()
            ->all();
    }

    /**
     * Các từ có nghĩa của `catalog_query`, key là dạng có dấu để khớp
     * được xác nhận trên cả hai dạng viết.
     *
     * @return array<string, string> từ có dấu => từ không dấu
     */
    protected function catalogTerms(?string $query): array
    {
        // Động từ và danh từ chung xuất hiện trong gần mọi dòng cách dùng, nên khớp chúng
        // sẽ làm cả danh mục cùng xếp hạng.
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