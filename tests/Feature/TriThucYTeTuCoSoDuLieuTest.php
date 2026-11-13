<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\CoDoYTe;
use App\Models\TrieuChungYTe;
use App\Services\ChatbotService;
use Database\Seeders\TriThucYTeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 4 of the triage plan: red flags and symptom aliases must come from the
 * database, so a pharmacist can add a warning sign or a new spelling without a
 * code change. These tests prove the data actually drives behaviour — a passing
 * ChatbotServiceTest alone cannot, because the hard-coded fallback would satisfy
 * it just as well.
 */
class TriThucYTeTuCoSoDuLieuTest extends TestCase
{
    use RefreshDatabase;

    protected ChatbotService $chatbot;

    protected function setUp(): void
    {
        parent::setUp();

        DanhMuc::create(['ten' => 'Thuốc giảm đau - Hạ sốt',
            'mo_ta' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(TriThucYTeSeeder::class);

        $this->chatbot = app(ChatbotService::class);
    }

    protected function isUrgent(string $message): bool
    {
        return $this->invoke('isUrgentMedicalMessage', $message);
    }

    protected function symptomQueries(string $message): array
    {
        return $this->invoke('extractSymptomQueriesFromMessage', $message);
    }

    protected function invoke(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod(ChatbotService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(app(ChatbotService::class), ...$args);
    }

    // --- Red flags read from medical_red_flags ---

    public function test_tu_khoa_cap_cuu_duoc_seed_duoc_phat_hien(): void
    {
        $this->assertTrue($this->isUrgent('tôi bị đau ngực dữ dội'));
    }

    public function test_tu_khoa_chi_co_trong_co_so_du_lieu_duoc_phat_hien(): void
    {
        // "sung luoi" (anaphylaxis) never existed in the old hard-coded array.
        $this->assertTrue($this->isUrgent('môi và lưỡi tôi bị sưng lưỡi sau khi uống thuốc'));
    }

    public function test_duoc_si_them_duoc_co_do_ma_khong_can_sua_code(): void
    {
        $novel = 'toi bi dau hieu rat kho ta';

        $this->assertFalse($this->isUrgent($novel));

        CoDoYTe::create([
            'code' => 'brand_new_sign',
            'name_vi' => 'Dấu hiệu mới do dược sĩ thêm',
            'keywords' => ['dau hieu rat kho ta'],
            'severity' => 'emergency',
            'guidance_vi' => 'Hãy gọi 115.',
            'is_active' => true,
        ]);

        // A fresh instance: the per-request cache would otherwise hide the new row.
        $this->assertTrue(
            $this->isUrgent($novel),
            'A red flag added to the database must take effect without editing code.'
        );
    }

    /**
     * Keywords are matched accent-free, so a short one like "ngất" or "bầu" would
     * also fire on "ngắt lời" or "bầu trời". These phrases have no medical intent
     * and must never be treated as an emergency.
     */
    public function test_cum_tu_thong_thuong_khong_bi_nham_la_cap_cuu(): void
    {
        $innocent = [
            'toi bi ngat quang duong',
            'ngat loi ban',
            'toi thay bau troi dep',
            'bau bi nau canh',
            'giat minh',
            'giat gau ao',
            'anh chup lo mo',
            'ung buou la gi',
            'viec nay nguy hiem khong',
            'co gi moi la khong',
        ];

        foreach ($innocent as $phrase) {
            $this->assertFalse(
                $this->isUrgent($phrase),
                "\"{$phrase}\" has no medical intent and must not trigger urgent handling."
            );
        }
    }

    /**
     * The complement of the test above: tightening the keywords must not have
     * broken real warning signs.
     */
    public function test_dau_hieu_canh_bao_that_van_kich_hoat(): void
    {
        $urgent = [
            'toi bi dau nguc du doi',
            'toi kho tho qua',
            'be bi co giat',
            'toi bi ngat xiu hom qua',
            'toi non ra mau',
            'di ngoai ra mau',
            'nghi sot xuat huyet',
            'toi bi sot cao 39 do',
            'toi bi mat nuoc nhieu',
            'toi bi ung thu giai doan cuoi',
            'tinh trang rat nguy hiem',
            'can cap cuu',
            'sung luoi sau khi uong thuoc',
            'tieu chay nang',
            'dau khong chiu duoc',
        ];

        foreach ($urgent as $phrase) {
            $this->assertTrue(
                $this->isUrgent($phrase),
                "\"{$phrase}\" is a warning sign and must trigger urgent handling."
            );
        }
    }

    /**
     * Patient context is not an emergency. Pregnancy, breastfeeding and infant rows
     * exist so the triage wizard can weigh them, but in chat they must not replace a
     * product question with "go to hospital now" — that is the single most common
     * pharmacy question and the customer would get no answer at all.
     */
    public function test_dong_ngu_canh_benh_nhan_khong_ngat_luong_chat(): void
    {
        $context = [
            'toi dang mang thai co uong duoc khong',
            'em dang cho con bu thi uong vitamin nao duoc',
            'shop co sua cho be 1 tuoi khong',
            'toi met la sau khi tap gym',
            'toi bi tieu it co sao khong',
        ];

        foreach ($context as $phrase) {
            $this->assertFalse(
                $this->isUrgent($phrase),
                "\"{$phrase}\" is patient context, not an emergency, and must not short-circuit the chat."
            );
        }

        // The rows still exist and are still readable by the triage wizard.
        $this->assertTrue(
            CoDoYTe::where('code', 'pregnancy_symptom')->where('is_active', true)->exists()
        );
        $this->assertFalse(
            (bool) CoDoYTe::where('code', 'pregnancy_symptom')->value('chat_short_circuit')
        );
    }

    /**
     * The row fires on "đang cho con bú" as well as on pregnancy — its own keyword list
     * says so, and session 53 reached refer_doctor on exactly that answer. A label
     * naming only pregnancy leaves the customer reading a flag that does not describe
     * them.
     */
    public function test_co_mang_thai_neu_ten_ca_hai_trang_thai_kich_hoat(): void
    {
        $name = (string) CoDoYTe::where('code', 'pregnancy_symptom')->value('name_vi');

        $this->assertStringContainsString('mang thai', $name);
        $this->assertStringContainsString('cho con bú', $name);
    }

    /**
     * Symptom aliases are matched on word boundaries, and short ones like "sot"
     * collide with everyday Vietnamese. Ordinary shopping phrases must stay clean.
     */
    public function test_cum_tu_thong_thuong_khong_khop_biet_danh_trieu_chung(): void
    {
        $innocent = [
            'toi muon mua nuoc sot ca chua',
            'banh mi sot vang',
            'con ngua nay dep',
            'toi thich cuoi ngua',
            'con ngua qua dep',
            'toi thich cuoi ngua qua',
            'nuoc sot cao cap',
            'thit nong sot vua ra lo',
            'toi dang sot ruot cho don',
            'xin chao ban',
            'ban co ho tro giao hang',
            'hoa don cua toi',
        ];

        foreach ($innocent as $phrase) {
            $this->assertSame(
                [],
                $this->symptomQueries($phrase),
                "\"{$phrase}\" must not be read as a symptom."
            );
        }
    }

    /**
     * The complement: guarding the ambiguous aliases must not break the real ones.
     */
    public function test_cum_tu_trieu_chung_that_van_khop(): void
    {
        $cases = [
            'toi bi sot' => 'hạ sốt',
            'toi bi sot cao 39 do' => 'hạ sốt',
            'toi bi ngua' => 'chống ngứa',
            'toi bi ngua khap nguoi' => 'chống ngứa',
        ];

        foreach ($cases as $phrase => $expected) {
            $this->assertContains(
                $expected,
                $this->symptomQueries($phrase),
                "\"{$phrase}\" must still be read as a symptom."
            );
        }
    }

    /**
     * Guard words live in the database next to the aliases they disambiguate, so a
     * pharmacist can guard a new ambiguous alias without a code change.
     */
    public function test_duoc_si_them_duoc_tu_chan_ma_khong_can_sua_code(): void
    {
        TrieuChungYTe::create([
            'code' => 'probe_symptom',
            'name_vi' => 'cầm máu',
            'catalog_query' => 'cầm máu',
            'aliases' => ['cam'],
            'guard_words' => ['qua', 'trai', 'nuoc'],
            'body_system' => 'general',
            'is_active' => true,
        ]);

        $this->assertSame([], $this->symptomQueries('toi muon mua qua cam'));
        $this->assertContains('cầm máu', $this->symptomQueries('toi bi cam nhieu'));
    }

    public function test_tat_co_do_lam_no_ngung_kich_hoat(): void
    {
        $this->assertTrue($this->isUrgent('tôi bị co giật'));

        CoDoYTe::where('code', 'seizure')->update(['is_active' => false]);

        $this->assertFalse($this->isUrgent('tôi bị co giật'));
    }

    public function test_dong_than_trong_khong_tinh_la_khan_cap(): void
    {
        // "kéo dài" is a caution flag; it belongs further down the ladder, not here.
        $this->assertFalse($this->isUrgent('triệu chứng của tôi kéo dài cả tuần nay'));
    }

    public function test_phat_hien_khan_cap_van_hoat_dong_khi_kho_tri_thuc_rong(): void
    {
        CoDoYTe::query()->delete();

        // Falls back to the built-in list rather than declaring nothing dangerous.
        $this->assertTrue($this->isUrgent('tôi bị khó thở'));
    }

    // --- Symptom aliases read from medical_symptoms ---

    public function test_biet_danh_duoc_seed_anh_xa_sang_truy_van_danh_muc(): void
    {
        $this->assertContains('tiêu chảy', $this->symptomQueries('tôi bị tiêu chảy từ hôm qua'));
    }

    public function test_biet_danh_chi_co_trong_co_so_du_lieu_van_khop(): void
    {
        // "nong nguoi" is a fever alias that the old hard-coded map never had.
        $this->assertNotEmpty($this->symptomQueries('nguoi toi nong nguoi tu sang'));
    }

    public function test_duoc_si_them_duoc_biet_danh_ma_khong_can_sua_code(): void
    {
        $slang = 'toi bi xon bung ca ngay';

        $this->assertSame([], $this->symptomQueries($slang));

        $trieuChung = TrieuChungYTe::where('code', 'diarrhea')->firstOrFail();
        $trieuChung->update(['aliases' => [...$trieuChung->aliases, 'xon bung']]);

        $this->assertContains('tiêu chảy', $this->symptomQueries($slang));
    }

    public function test_tat_trieu_chung_loai_bo_cac_biet_danh_cua_no(): void
    {
        $this->assertNotEmpty($this->symptomQueries('tôi bị táo bón'));

        TrieuChungYTe::where('code', 'constipation')->update(['is_active' => false]);

        $this->assertSame([], $this->symptomQueries('tôi bị táo bón'));
    }

    public function test_phat_hien_trieu_chung_van_hoat_dong_khi_kho_tri_thuc_rong(): void
    {
        TrieuChungYTe::query()->delete();

        $this->assertContains('tiêu chảy', $this->symptomQueries('tôi bị tiêu chảy'));
    }

    public function test_truy_van_danh_muc_uu_tien_hon_ten_hien_thi(): void
    {
        TrieuChungYTe::where('code', 'diarrhea')->update(['catalog_query' => 'thuốc cầm tiêu chảy']);

        $this->assertContains('thuốc cầm tiêu chảy', $this->symptomQueries('tôi bị tiêu chảy'));
    }
}
