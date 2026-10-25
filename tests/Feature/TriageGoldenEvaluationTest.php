<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\TriageSession;
use App\Models\User;
use App\Services\Triage\MedicalTriageService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Benchmark / Golden dataset evaluation harness for the medical triage system.
 *
 * Each test case pairs a realistic clinical presentation (Input) with the
 * verified, authoritative ground-truth outcome and safety constraints (Output).
 * This establishes an automated evaluation baseline that measures triage accuracy,
 * prevents safety regression, and verifies that critical cases never degrade to self-care.
 */
class TriageGoldenEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected MedicalTriageService $service;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(MedicalKnowledgeSeeder::class);
        $this->service = app(MedicalTriageService::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(30)->toDateString(),
        ]);
    }

    protected function runGoldenCase(array $answers): array
    {
        $session = $this->service->startOrResume($this->user->MaKH);

        foreach ($answers as $step => $answer) {
            $session = $this->service->recordAnswer($session->fresh(), $step, $answer);
        }

        return $this->service->evaluate($session->fresh());
    }

    /**
     * Golden clinical test cases (Ground Truth: Input -> Output).
     *
     * @return array<string, array{
     *     answers: array<int, string>,
     *     expected_outcome: string,
     *     expected_red_flag_substring: ?string,
     *     description: string
     * }>
     */
    public static function goldenClinicalCases(): array
    {
        return [
            'CS-01: Cấp cứu đau ngực khó thở' => [
                'answers' => [
                    1 => 'tôi bị đau thắt ngực dữ dội và khó thở',
                    2 => 'mới 30 phút',
                    3 => 'chưa dùng gì',
                    4 => 'không có',
                    5 => 'vã mồ hôi lạnh',
                ],
                'expected_outcome' => 'emergency',
                'expected_red_flag_substring' => '115',
                'description' => 'Đau ngực dữ dội kèm khó thở là cờ đỏ tối khẩn cấp, bắt buộc ra kết luận emergency.',
            ],

            'CS-02: Cấp cứu sốt cao co giật' => [
                'answers' => [
                    1 => 'sốt cao co giật và lơ mơ',
                    2 => 'hôm nay',
                    3 => 'chưa dùng gì',
                    4 => 'không có',
                    5 => 'gọi hỏi không tỉnh',
                ],
                'expected_outcome' => 'emergency',
                'expected_red_flag_substring' => '115',
                'description' => 'Sốt co giật, rối loạn ý thức là cấp cứu khẩn cấp.',
            ],

            'CS-03: Chuyển bác sĩ vì sốt kéo dài trên 7 ngày' => [
                'answers' => [
                    1 => 'sốt nhẹ',
                    2 => '8 ngày rồi',
                    3 => 'có uống hạ sốt',
                    4 => 'không',
                    5 => 'mệt mỏi',
                ],
                'expected_outcome' => 'refer_doctor',
                'expected_red_flag_substring' => null,
                'description' => 'Sốt kéo dài trên 7 ngày vượt quá phạm vi tự điều trị tại nhà.',
            ],

            'CS-04: Đau đầu nhẹ mới xuất hiện -> Tự chăm sóc' => [
                'answers' => [
                    1 => 'đau đầu nhẹ do thiếu ngủ',
                    2 => 'hôm nay',
                    3 => 'chưa dùng gì',
                    4 => 'không có',
                    5 => 'không có thêm',
                ],
                'expected_outcome' => 'self_care',
                'expected_red_flag_substring' => null,
                'description' => 'Đau đầu căng thẳng nhẹ mới xuất hiện ở người trẻ là ca tự chăm sóc an toàn.',
            ],

            'CS-05: Tiêu chảy cấp ngày đầu không dấu hiệu mất nước -> Tự chăm sóc' => [
                'answers' => [
                    1 => 'tiêu chảy',
                    2 => 'hôm nay đi 2 lần',
                    3 => 'chưa dùng',
                    4 => 'không có',
                    5 => 'không sốt, không nôn',
                ],
                'expected_outcome' => 'self_care',
                'expected_red_flag_substring' => null,
                'description' => 'Tiêu chảy nhẹ không sốt không mất nước phù hợp tự bù nước tại nhà.',
            ],

            'CS-06: Ho có đờm mới bị -> Dược sĩ tư vấn' => [
                'answers' => [
                    1 => 'ho có đờm',
                    2 => '2 ngày',
                    3 => 'chưa uống thuốc',
                    4 => 'không có bệnh nền',
                    5 => 'không sốt',
                ],
                'expected_outcome' => 'pharmacist_review',
                'expected_red_flag_substring' => null,
                'description' => 'Ho có đờm ở người lớn cần dược sĩ chọn thuốc long đờm phù hợp, không tự mua.',
            ],

            'CS-07: Sốt đã uống thuốc không đỡ -> Dược sĩ tư vấn' => [
                'answers' => [
                    1 => 'sốt',
                    2 => 'hôm nay',
                    3 => 'đã dùng thuốc nhưng không đỡ',
                    4 => 'không có',
                    5 => 'không có thêm',
                ],
                'expected_outcome' => 'pharmacist_review',
                'expected_red_flag_substring' => null,
                'description' => 'Dùng thuốc không đỡ cần dược sĩ đánh giá lại liều và nguyên nhân.',
            ],

            'CS-08: Táo bón mới bị -> Tự chăm sóc' => [
                'answers' => [
                    1 => 'táo bón',
                    2 => '2 ngày',
                    3 => 'chưa dùng',
                    4 => 'không có',
                    5 => 'không đau bụng',
                ],
                'expected_outcome' => 'self_care',
                'expected_red_flag_substring' => null,
                'description' => 'Táo bón nhẹ mới bị phù hợp điều chỉnh dinh dưỡng và tự chăm sóc.',
            ],
        ];
    }

    #[DataProvider('goldenClinicalCases')]
    public function test_golden_clinical_benchmark_case(
        array $answers,
        string $expected_outcome,
        ?string $expected_red_flag_substring,
        string $description
    ): void {
        $result = $this->runGoldenCase($answers);

        $this->assertSame(
            $expected_outcome,
            $result['outcome'],
            "Thất bại ca chuẩn [{$description}]: kết quả nhận được là [{$result['outcome']}], mong đợi [{$expected_outcome}]."
        );

        $this->assertNotEmpty(
            $result['advice_given'],
            "Ca [{$description}] phải có lời khuyên an toàn đi kèm."
        );

        // Emergency cases must NEVER recommend self-care medications
        if ($expected_outcome === 'emergency') {
            $this->assertEmpty(
                $result['suggested_products'],
                "Ca cấp cứu [{$description}] tuyệt đối không được gợi ý mua thuốc tự điều trị."
            );
        }

        // Self-care cases must ALWAYS provide a safety net (threshold to see doctor)
        if ($expected_outcome === 'self_care') {
            $this->assertNotEmpty(
                $result['safety_net'],
                "Ca tự chăm sóc [{$description}] bắt buộc phải có ngưỡng cảnh báo khi nào cần đi khám."
            );
        }

        if ($expected_red_flag_substring !== null) {
            $this->assertStringContainsString(
                $expected_red_flag_substring,
                $result['advice_given'] ?? ''
            );
        }
    }
}
