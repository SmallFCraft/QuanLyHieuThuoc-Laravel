<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MedicalRedFlag;
use App\Models\MedicalSymptom;
use App\Services\ChatbotService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 4 of the triage plan: red flags and symptom aliases must come from the
 * database, so a pharmacist can add a warning sign or a new spelling without a
 * code change. These tests prove the data actually drives behaviour — a passing
 * ChatbotServiceTest alone cannot, because the hard-coded fallback would satisfy
 * it just as well.
 */
class MedicalKnowledgeFromDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected ChatbotService $chatbot;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(MedicalKnowledgeSeeder::class);

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

    public function test_seeded_emergency_keyword_is_detected(): void
    {
        $this->assertTrue($this->isUrgent('tôi bị đau ngực dữ dội'));
    }

    public function test_keyword_present_only_in_the_database_is_detected(): void
    {
        // "sung luoi" (anaphylaxis) never existed in the old hard-coded array.
        $this->assertTrue($this->isUrgent('môi và lưỡi tôi bị sưng lưỡi sau khi uống thuốc'));
    }

    public function test_a_pharmacist_can_add_a_red_flag_without_a_code_change(): void
    {
        $novel = 'toi bi dau hieu rat kho ta';

        $this->assertFalse($this->isUrgent($novel));

        MedicalRedFlag::create([
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
    public function test_ordinary_phrases_are_not_mistaken_for_emergencies(): void
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
    public function test_real_warning_signs_still_trigger(): void
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
    public function test_patient_context_rows_do_not_short_circuit_chat(): void
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
            MedicalRedFlag::where('code', 'pregnancy_symptom')->where('is_active', true)->exists()
        );
        $this->assertFalse(
            (bool) MedicalRedFlag::where('code', 'pregnancy_symptom')->value('chat_short_circuit')
        );
    }

    /**
     * Symptom aliases are matched on word boundaries, and short ones like "sot"
     * collide with everyday Vietnamese. Ordinary shopping phrases must stay clean.
     */
    public function test_ordinary_phrases_do_not_match_symptom_aliases(): void
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
    public function test_real_symptom_phrases_still_match(): void
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
    public function test_a_pharmacist_can_add_a_guard_word_without_a_code_change(): void
    {
        MedicalSymptom::create([
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

    public function test_deactivating_a_red_flag_stops_it_firing(): void
    {
        $this->assertTrue($this->isUrgent('tôi bị co giật'));

        MedicalRedFlag::where('code', 'seizure')->update(['is_active' => false]);

        $this->assertFalse($this->isUrgent('tôi bị co giật'));
    }

    public function test_caution_rows_do_not_count_as_urgent(): void
    {
        // "kéo dài" is a caution flag; it belongs further down the ladder, not here.
        $this->assertFalse($this->isUrgent('triệu chứng của tôi kéo dài cả tuần nay'));
    }

    public function test_urgent_detection_survives_an_empty_knowledge_base(): void
    {
        MedicalRedFlag::query()->delete();

        // Falls back to the built-in list rather than declaring nothing dangerous.
        $this->assertTrue($this->isUrgent('tôi bị khó thở'));
    }

    // --- Symptom aliases read from medical_symptoms ---

    public function test_seeded_alias_maps_to_the_catalog_query(): void
    {
        $this->assertContains('tiêu chảy', $this->symptomQueries('tôi bị tiêu chảy từ hôm qua'));
    }

    public function test_alias_present_only_in_the_database_is_matched(): void
    {
        // "nong nguoi" is a fever alias that the old hard-coded map never had.
        $this->assertNotEmpty($this->symptomQueries('nguoi toi nong nguoi tu sang'));
    }

    public function test_a_pharmacist_can_add_an_alias_without_a_code_change(): void
    {
        $slang = 'toi bi xon bung ca ngay';

        $this->assertSame([], $this->symptomQueries($slang));

        $symptom = MedicalSymptom::where('code', 'diarrhea')->firstOrFail();
        $symptom->update(['aliases' => [...$symptom->aliases, 'xon bung']]);

        $this->assertContains('tiêu chảy', $this->symptomQueries($slang));
    }

    public function test_deactivating_a_symptom_removes_its_aliases(): void
    {
        $this->assertNotEmpty($this->symptomQueries('tôi bị táo bón'));

        MedicalSymptom::where('code', 'constipation')->update(['is_active' => false]);

        $this->assertSame([], $this->symptomQueries('tôi bị táo bón'));
    }

    public function test_symptom_detection_survives_an_empty_knowledge_base(): void
    {
        MedicalSymptom::query()->delete();

        $this->assertContains('tiêu chảy', $this->symptomQueries('tôi bị tiêu chảy'));
    }

    public function test_catalog_query_wins_over_the_display_name(): void
    {
        MedicalSymptom::where('code', 'diarrhea')->update(['catalog_query' => 'thuốc cầm tiêu chảy']);

        $this->assertContains('thuốc cầm tiêu chảy', $this->symptomQueries('tôi bị tiêu chảy'));
    }
}
