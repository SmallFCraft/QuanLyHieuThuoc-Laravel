<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\DrugContraindication;
use App\Models\DrugInteraction;
use App\Models\MedicalRedFlag;
use App\Models\MedicalSymptom;
use App\Models\MedicalTriageRule;
use Illuminate\Database\Seeder;

/**
 * Medical knowledge base for the triage feature.
 *
 * IMPORTANT: this data was compiled for an academic project and has NOT been
 * reviewed by a licensed pharmacist. Every triage rule leaves `reviewed_by` null
 * on purpose — production use requires professional review first.
 * Drug pairs below are limited to active ingredients commonly stocked by
 * Vietnamese pharmacies. Products are crawled from Nhà Thuốc Long Châu, so a
 * crawled product only triggers a check when its ingredient appears here.
 */
class MedicalKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $symptoms = $this->seedSymptoms();
        $this->seedRedFlags($symptoms);
        $this->seedTriageRules($symptoms);
        $this->seedDrugInteractions();
        $this->seedContraindications();
    }

    /**
     * @return array<string, int> symptom code => MaTC
     */
    protected function seedSymptoms(): array
    {
        $ids = [];

        foreach ($this->symptomDefinitions() as $definition) {
            $symptom = MedicalSymptom::updateOrCreate(
                ['code' => $definition['code']],
                $definition
            );

            $ids[$definition['code']] = $symptom->MaTC;
        }

        return $ids;
    }

    /**
     * Aliases are stored accent-free because MedicalTriageService normalises the
     * user's message with Str::ascii before matching.
     */
    protected function symptomDefinitions(): array
    {
        return [
            ['code' => 'fever', 'name_vi' => 'sốt', 'body_system' => 'general', 'catalog_query' => 'hạ sốt',
                // "nước sốt", "bánh mì sốt vang", "sốt cao cấp" are food, not fever.
                'guard_words' => ['nuoc', 'banh', 'mi', 'hu', 'chai', 'lo', 'goi', 'tuong', 'thit', 'nong', 'ruot'],
                'aliases' => ['sot', 'sot cao', 'nong nguoi', 'phat sot', 'sot nhe', 'nong sot', 'dang sot']],
            ['code' => 'headache', 'name_vi' => 'đau đầu', 'body_system' => 'neuro', 'catalog_query' => 'giảm đau đầu',
                'aliases' => ['dau dau', 'nhuc dau', 'dau nua dau', 'dau dinh dau', 'cang dau']],
            ['code' => 'cough', 'name_vi' => 'ho', 'body_system' => 'respiratory', 'catalog_query' => 'trị ho',
                'aliases' => ['ho khan', 'ho co dam', 'ho nhieu', 'ho keo dai', 'ho ra dam']],
            ['code' => 'sore_throat', 'name_vi' => 'đau họng', 'body_system' => 'respiratory', 'catalog_query' => 'đau họng',
                'aliases' => ['dau hong', 'viem hong', 'rat hong', 'kho hong', 'sung hong']],
            ['code' => 'runny_nose', 'name_vi' => 'chảy nước mũi', 'body_system' => 'respiratory', 'catalog_query' => 'nghẹt mũi',
                'aliases' => ['so mui', 'chay nuoc mui', 'nghet mui', 'tac mui', 'ngat mui']],
            ['code' => 'common_cold', 'name_vi' => 'cảm lạnh', 'body_system' => 'respiratory', 'catalog_query' => 'cảm cúm',
                'aliases' => ['cam lanh', 'cam cum', 'bi cam', 'cam nang', 'nhiem lanh']],
            ['code' => 'shortness_breath', 'name_vi' => 'khó thở', 'body_system' => 'respiratory', 'catalog_query' => null,
                'aliases' => ['kho tho', 'tho gap', 'hut hoi', 'tuc tho', 'ngat tho']],
            ['code' => 'chest_pain', 'name_vi' => 'đau ngực', 'body_system' => 'general', 'catalog_query' => null,
                'aliases' => ['dau nguc', 'tuc nguc', 'nhoi nguc', 'that nguc', 'de nen nguc']],
            ['code' => 'stomach_pain', 'name_vi' => 'đau dạ dày', 'body_system' => 'digestive', 'catalog_query' => 'đau dạ dày',
                'aliases' => ['dau da day', 'dau bao tu', 'dau thuong vi', 'xot da day', 'nong ra da day']],
            ['code' => 'abdominal_pain', 'name_vi' => 'đau bụng', 'body_system' => 'digestive', 'catalog_query' => 'đau bụng',
                'aliases' => ['dau bung', 'quan bung', 'dau bung duoi', 'dau quan bung', 'co that bung']],
            ['code' => 'diarrhea', 'name_vi' => 'tiêu chảy', 'body_system' => 'digestive', 'catalog_query' => 'tiêu chảy',
                'aliases' => ['tieu chay', 'ia chay', 'di ngoai long', 'di ngoai nhieu lan', 'phan long']],
            ['code' => 'constipation', 'name_vi' => 'táo bón', 'body_system' => 'digestive', 'catalog_query' => 'táo bón',
                'aliases' => ['tao bon', 'kho di ngoai', 'ia khong ra', 'ia ko ra', 'bi bon']],
            ['code' => 'bloating', 'name_vi' => 'đầy hơi', 'body_system' => 'digestive', 'catalog_query' => 'đầy hơi',
                'aliases' => ['day hoi', 'chuong bung', 'day bung', 'sinh hoi', 'trung bung']],
            ['code' => 'indigestion', 'name_vi' => 'khó tiêu', 'body_system' => 'digestive', 'catalog_query' => 'khó tiêu',
                'aliases' => ['kho tieu', 'an khong tieu', 'roi loan tieu hoa', 'tieu hoa kem', 'nang bung']],
            ['code' => 'nausea', 'name_vi' => 'buồn nôn', 'body_system' => 'digestive', 'catalog_query' => 'buồn nôn',
                'aliases' => ['buon non', 'non oi', 'lom giong', 'muon non', 'non nhieu']],
            ['code' => 'heartburn', 'name_vi' => 'trào ngược dạ dày', 'body_system' => 'digestive', 'catalog_query' => 'trào ngược',
                'aliases' => ['trao nguoc', 'nong rat co', 'chua mieng', 'o chua', 'trao nguoc da day']],
            ['code' => 'dizziness', 'name_vi' => 'chóng mặt', 'body_system' => 'neuro', 'catalog_query' => 'chóng mặt',
                'aliases' => ['chong mat', 'xay xam', 'hoa mat', 'quay cuong', 'mat thang bang']],
            ['code' => 'fatigue', 'name_vi' => 'mệt mỏi', 'body_system' => 'general', 'catalog_query' => 'vitamin bồi bổ',
                'aliases' => ['met moi', 'kiet suc', 'khong con suc', 'ua oai', 'suy nhuoc']],
            ['code' => 'insomnia', 'name_vi' => 'mất ngủ', 'body_system' => 'neuro', 'catalog_query' => 'mất ngủ',
                'aliases' => ['mat ngu', 'kho ngu', 'ngu khong sau', 'trang dem', 'thuc dem']],
            ['code' => 'joint_pain', 'name_vi' => 'đau khớp', 'body_system' => 'musculoskeletal', 'catalog_query' => 'đau khớp',
                'aliases' => ['dau khop', 'viem khop', 'nhuc khop', 'kho khop', 'sung khop']],
            ['code' => 'muscle_pain', 'name_vi' => 'đau cơ', 'body_system' => 'musculoskeletal', 'catalog_query' => 'giảm đau cơ',
                'aliases' => ['dau co', 'nhuc moi', 'cang co', 'dau lung', 'moi vai']],
            ['code' => 'toothache', 'name_vi' => 'đau răng', 'body_system' => 'general', 'catalog_query' => 'giảm đau',
                'aliases' => ['dau rang', 'nhuc rang', 'viem nuou', 'sung nuou', 'e rang']],
            ['code' => 'rash', 'name_vi' => 'phát ban', 'body_system' => 'derma', 'catalog_query' => 'dị ứng da',
                'aliases' => ['phat ban', 'noi man', 'noi me day', 'noi san', 'do da']],
            ['code' => 'itching', 'name_vi' => 'ngứa', 'body_system' => 'derma', 'catalog_query' => 'chống ngứa',
                // "con ngựa", "cưỡi ngựa" are horses, not itching.
                'guard_words' => ['con', 'cuoi', 'dan', 'chuong'],
                'aliases' => ['ngua', 'ngua ngay', 'ngua da', 'chay ngua', 'ngua khap nguoi', 'ngua qua']],
            ['code' => 'allergy', 'name_vi' => 'dị ứng', 'body_system' => 'derma', 'catalog_query' => 'dị ứng',
                'aliases' => ['di ung', 'man ngua', 'di ung thoi tiet', 'di ung thuc an', 'kich ung']],
            ['code' => 'dengue', 'name_vi' => 'sốt xuất huyết', 'body_system' => 'general', 'catalog_query' => null,
                'aliases' => ['sot xuat huyet', 'dengue', 'xuat huyet', 'sot xh']],
            ['code' => 'dehydration', 'name_vi' => 'mất nước', 'body_system' => 'general', 'catalog_query' => 'oresol bù nước',
                'aliases' => ['mat nuoc', 'kho khat', 'thieu nuoc', 'khat nhieu', 'tieu it']],
            ['code' => 'high_blood_pressure', 'name_vi' => 'huyết áp cao', 'body_system' => 'general', 'catalog_query' => 'huyết áp',
                'aliases' => ['huyet ap cao', 'cao huyet ap', 'tang huyet ap', 'ap cao']],
            ['code' => 'blood_in_stool', 'name_vi' => 'đi ngoài ra máu', 'body_system' => 'digestive', 'catalog_query' => null,
                'aliases' => ['di ngoai ra mau', 'phan co mau', 'phan den', 'ia ra mau']],
            ['code' => 'vomiting_blood', 'name_vi' => 'nôn ra máu', 'body_system' => 'digestive', 'catalog_query' => null,
                'aliases' => ['non ra mau', 'non mau', 'oi ra mau']],
            ['code' => 'menstrual_cramps', 'name_vi' => 'đau bụng kinh', 'body_system' => 'general', 'catalog_query' => 'giảm đau bụng kinh',
                // Every alias is multi-word on purpose: bare "kinh" fires inside "kinh
                // nghiệm", "kinh doanh", "kinh khủng". "đến tháng" is excluded for the
                // same reason — it matches inside "đến tháng sau vẫn không đỡ".
                'aliases' => ['dau bung kinh', 'thong kinh', 'kinh nguyet', 'ky kinh nguyet', 'hanh kinh',
                    'dau bung khi co kinh', 'den ky kinh', 'dau bung ngay kinh']],
        ];
    }

    /**
     * Red flags short-circuit the whole flow: an `emergency` match returns
     * guidance_vi verbatim and never reaches the AI or the product catalog.
     * The emergency wording below is carried over from
     * ChatbotService::buildUrgentMedicalGuidanceResponse so behaviour is unchanged.
     *
     * @param array<string, int> $symptoms
     */
    protected function seedRedFlags(array $symptoms): void
    {
        foreach ($this->redFlagDefinitions() as $definition) {
            $symptomCode = $definition['symptom'] ?? null;
            unset($definition['symptom']);

            $definition['applies_to_symptom'] = $symptomCode ? ($symptoms[$symptomCode] ?? null) : null;
            // Explicit on every row: re-seeding must reset a flag that was flipped,
            // not inherit whatever the previous run left behind.
            $definition['chat_short_circuit'] = $definition['chat_short_circuit'] ?? true;

            MedicalRedFlag::updateOrCreate(['code' => $definition['code']], $definition);
        }
    }

    protected function redFlagDefinitions(): array
    {
        $callEmergency = 'Anh/chị hãy gọi ngay 115 hoặc đến cơ sở y tế gần nhất. Không nên tự dùng thuốc trong tình huống này.';

        return [
            [
                'code' => 'chest_pain_severe', 'name_vi' => 'Đau ngực dữ dội', 'severity' => 'emergency',
                'symptom' => 'chest_pain',
                'keywords' => ['dau nguc du doi', 'dau nguc', 'that nguc', 'de nen nguc', 'dau nguc lan canh tay'],
                'guidance_vi' => 'Đau ngực dữ dội có thể là dấu hiệu của nhồi máu cơ tim hoặc bệnh tim mạch cấp, cần được xử trí trong những phút đầu. ' . $callEmergency,
            ],
            [
                'code' => 'breathing_difficulty', 'name_vi' => 'Khó thở', 'severity' => 'emergency',
                'symptom' => 'shortness_breath',
                'keywords' => ['kho tho', 'tho gap', 'ngat tho', 'khong tho duoc', 'tho khong ra hoi'],
                'guidance_vi' => 'Khó thở là dấu hiệu cần được đánh giá ngay tại cơ sở y tế vì có thể liên quan đến tim, phổi hoặc phản ứng dị ứng nặng. ' . $callEmergency,
            ],
            [
                'code' => 'fainting', 'name_vi' => 'Ngất, lơ mơ', 'severity' => 'emergency', 'symptom' => null,
                'keywords' => ['ngat xiu', 'bat tinh', 'choang ngat', 'mat y thuc', 'khong goi duoc', 'li bi'],
                'guidance_vi' => 'Ngất hoặc rối loạn ý thức là dấu hiệu nguy hiểm, cần được bác sĩ đánh giá ngay. ' . $callEmergency,
            ],
            [
                'code' => 'seizure', 'name_vi' => 'Co giật', 'severity' => 'emergency', 'symptom' => null,
                'keywords' => ['co giat', 'dong kinh', 'run giat toan than', 'giat toan than'],
                'guidance_vi' => 'Co giật cần được xử trí y tế ngay. Trong lúc chờ, hãy đặt người bệnh nằm nghiêng an toàn, không đưa vật gì vào miệng. ' . $callEmergency,
            ],
            [
                'code' => 'vomiting_blood', 'name_vi' => 'Nôn ra máu', 'severity' => 'emergency',
                'symptom' => 'vomiting_blood',
                'keywords' => ['non ra mau', 'non mau', 'oi ra mau'],
                'guidance_vi' => 'Nôn ra máu là dấu hiệu xuất huyết tiêu hóa, cần cấp cứu ngay. Không tự dùng thuốc dạ dày hay thuốc giảm đau. ' . $callEmergency,
            ],
            [
                'code' => 'blood_in_stool', 'name_vi' => 'Đi ngoài ra máu, phân đen', 'severity' => 'emergency',
                'symptom' => 'blood_in_stool',
                'keywords' => ['di ngoai ra mau', 'phan co mau', 'phan den', 'ia ra mau'],
                'guidance_vi' => 'Phân có máu hoặc phân đen có thể là dấu hiệu xuất huyết tiêu hóa. Anh/chị cần đến cơ sở y tế ngay và tránh tự dùng thuốc chống viêm giảm đau. ' . $callEmergency,
            ],
            [
                'code' => 'dengue_suspected', 'name_vi' => 'Nghi sốt xuất huyết', 'severity' => 'emergency',
                'symptom' => 'dengue',
                'keywords' => ['sot xuat huyet', 'dengue', 'xuat huyet'],
                'guidance_vi' => 'Với sốt xuất huyết, anh/chị nên đi khám hoặc liên hệ cơ sở y tế để được đánh giá tiểu cầu, mức độ mất nước và dấu hiệu cảnh báo; không nên tự chọn thuốc.'
                    . "\n" . 'Trong lúc chờ khám, nên nghỉ ngơi, uống đủ nước hoặc oresol đúng hướng dẫn nếu không bị hạn chế dịch, theo dõi nhiệt độ và lượng nước tiểu. Tránh tự dùng aspirin, ibuprofen hoặc thuốc chống viêm giảm đau khi chưa có chỉ định vì có thể tăng nguy cơ chảy máu.'
                    . "\n" . 'Cần đi cấp cứu ngay nếu đau bụng nhiều, nôn liên tục, chảy máu cam hoặc chân răng, đi ngoài phân đen, lừ đừ, tay chân lạnh, khó thở, tiểu ít hoặc sốt không hạ.',
            ],
            [
                'code' => 'cancer_treatment', 'name_vi' => 'Đang điều trị ung thư', 'severity' => 'emergency',
                'symptom' => null,
                'keywords' => ['ung thu', 'giai doan cuoi', 'dang hoa tri', 'dang xa tri', 'dieu tri ung buou'],
                'guidance_vi' => 'Với bệnh ung thư hoặc đang điều trị ung thư, mọi thuốc dùng thêm cần được bác sĩ điều trị, ung bướu hoặc chăm sóc giảm nhẹ xác nhận vì có thể tương tác thuốc, ảnh hưởng gan thận hoặc làm nặng tình trạng hiện tại. Anh/chị vui lòng liên hệ bác sĩ đang theo dõi cho mình.',
            ],
            [
                'code' => 'severe_pain', 'name_vi' => 'Đau dữ dội không rõ nguyên nhân', 'severity' => 'emergency',
                'symptom' => null,
                'keywords' => ['dau du doi', 'dau khong chiu duoc', 'dau qua suc', 'dau quan quai'],
                'guidance_vi' => 'Cơn đau dữ dội chưa rõ nguyên nhân cần được bác sĩ khám trực tiếp để loại trừ các cấp cứu ổ bụng hoặc tim mạch. ' . $callEmergency,
            ],
            [
                'code' => 'anaphylaxis', 'name_vi' => 'Dấu hiệu sốc phản vệ', 'severity' => 'emergency',
                'symptom' => 'allergy',
                'keywords' => ['sung moi', 'sung mat', 'sung luoi', 'phu mat', 'soc phan ve', 'kho tho sau khi uong thuoc'],
                'guidance_vi' => 'Sưng môi, mặt, lưỡi hoặc khó thở sau khi dùng thuốc hay ăn uống là dấu hiệu phản vệ, có thể diễn tiến rất nhanh. ' . $callEmergency,
            ],
            [
                'code' => 'stiff_neck_fever', 'name_vi' => 'Sốt kèm cứng cổ', 'severity' => 'emergency',
                'symptom' => 'fever',
                'keywords' => ['cung co', 'gu co', 'sot kem cung co', 'dau dau du doi kem sot'],
                'guidance_vi' => 'Sốt kèm cứng cổ và đau đầu dữ dội có thể là dấu hiệu viêm màng não. ' . $callEmergency,
            ],
            [
                'code' => 'self_declared_urgency', 'name_vi' => 'Người dùng tự nêu tình trạng cấp cứu', 'severity' => 'emergency',
                'symptom' => null,
                'keywords' => ['cap cuu', 'nguy kich', 'rat nguy hiem', 'khan cap', 'gap lam'],
                'guidance_vi' => 'Anh/chị đang mô tả một tình trạng cấp. Trong trường hợp này, việc được nhân viên y tế đánh giá trực tiếp quan trọng hơn bất kỳ thuốc nào. ' . $callEmergency,
            ],
            [
                'code' => 'severe_dehydration', 'name_vi' => 'Mất nước nặng', 'severity' => 'urgent',
                'symptom' => 'dehydration',
                'keywords' => ['mat nuoc', 'khong tieu duoc', 'kho moi kho mieng'],
                'guidance_vi' => 'Dấu hiệu mất nước rõ cần được bù dịch có theo dõi. Anh/chị nên đến cơ sở y tế trong hôm nay, đồng thời uống oresol đúng hướng dẫn nếu không bị bác sĩ hạn chế dịch.',
            ],
            [
                // Only unambiguous clinical terms: these cannot be a description of
                // ordinary period pain, so they are safe in the global keyword tier.
                'code' => 'heavy_menstrual_bleeding', 'name_vi' => 'Rong kinh, băng huyết', 'severity' => 'urgent',
                'symptom' => 'menstrual_cramps',
                'keywords' => ['rong kinh', 'bang huyet', 'chay mau am dao nhieu', 'uot bang moi gio'],
                'guidance_vi' => 'Chảy máu kinh quá nhiều hoặc kéo dài có thể gây thiếu máu và cần bác sĩ sản phụ khoa tìm nguyên nhân. Anh/chị nên đi khám sớm, không tự dùng thuốc cầm máu hay thuốc nội tiết.'
                    . "\n" . 'Cần đi cấp cứu ngay nếu ướt băng mỗi giờ, choáng, ngất, da tái lạnh hoặc tim đập nhanh.',
            ],
            [
                // Split from severe_dehydration: these phrases are dehydration signs the
                // wizard must weigh, but on their own they are everyday complaints, so
                // they must not turn a chat question into emergency guidance.
                'code' => 'dehydration_soft_signs', 'name_vi' => 'Dấu hiệu mất nước kèm theo', 'severity' => 'urgent',
                'symptom' => 'dehydration', 'chat_short_circuit' => false,
                'keywords' => ['tieu it', 'met la', 'moi la nguoi'],
                'guidance_vi' => 'Tiểu ít, mệt lả hoặc khô môi miệng có thể là dấu hiệu mất nước. Anh/chị nên bù nước theo hướng dẫn và đi khám nếu tình trạng không cải thiện.',
            ],
            [
                'code' => 'high_fever_persistent', 'name_vi' => 'Sốt cao kéo dài', 'severity' => 'urgent',
                'symptom' => 'fever',
                'keywords' => ['sot cao', 'sot 39', 'sot 40', 'sot khong ha', 'sot li bi'],
                'guidance_vi' => 'Sốt cao có thể do nhiễm virus, nhiễm khuẩn hoặc bệnh cần theo dõi sát. Anh/chị nên đo nhiệt độ, bù nước và đi khám sớm nếu sốt cao kéo dài, rét run, lừ đừ, khó thở, phát ban, đau đầu dữ dội hoặc không đáp ứng với hạ sốt thông thường.',
            ],
            [
                'code' => 'severe_diarrhea', 'name_vi' => 'Tiêu chảy nặng', 'severity' => 'urgent',
                'symptom' => 'diarrhea',
                'keywords' => ['tieu chay nang', 'di ngoai lien tuc', 'tieu chay nhieu lan', 'tieu chay cap do', 'ia chay cap do'],
                'guidance_vi' => 'Tiêu chảy nặng có thể gây mất nước và rối loạn điện giải nhanh. Nếu đi ngoài liên tục, khát nhiều, mệt lả, tiểu ít, sốt cao, đau bụng tăng hoặc phân có máu, anh/chị nên đến cơ sở y tế ngay.'
                    . "\n" . 'Trong lúc chờ đi khám, có thể ưu tiên bù nước theo hướng dẫn trên gói ORESOL nếu không bị bác sĩ hạn chế dịch, nhưng không tự ý dùng thuốc cầm tiêu chảy mạnh khi chưa rõ nguyên nhân.',
            ],
            [
                'code' => 'infant_symptom', 'name_vi' => 'Trẻ dưới 2 tuổi có triệu chứng', 'severity' => 'urgent',
                'symptom' => null, 'chat_short_circuit' => false,
                'keywords' => ['be so sinh', 'tre so sinh', 'be 1 tuoi', 'be may thang', 'tre duoi 2 tuoi'],
                'guidance_vi' => 'Trẻ nhỏ dưới 2 tuổi cần được bác sĩ nhi khám trực tiếp, không nên tự dùng thuốc vì liều và chống chỉ định khác người lớn. Anh/chị vui lòng đưa bé đến cơ sở y tế.',
            ],
            [
                'code' => 'pregnancy_symptom', 'name_vi' => 'Đang mang thai hoặc cho con bú', 'severity' => 'urgent',
                'symptom' => null, 'chat_short_circuit' => false,
                'keywords' => ['mang thai', 'co thai', 'thai ky', 'dang bau', 'dang mang bau', 'dang cho con bu'],
                'guidance_vi' => 'Khi đang mang thai hoặc cho con bú, phần lớn thuốc cần được bác sĩ sản khoa hoặc dược sĩ xác nhận trước khi dùng. Anh/chị vui lòng hỏi ý kiến bác sĩ đang theo dõi thai.',
            ],
            [
                'code' => 'prolonged_symptom', 'name_vi' => 'Triệu chứng kéo dài trên 7 ngày', 'severity' => 'caution',
                'symptom' => null,
                'keywords' => ['keo dai', 'ca tuan', 'mot tuan', '1 tuan', 'nhieu ngay', 'lau roi', 'khong khoi'],
                'guidance_vi' => 'Triệu chứng kéo dài quá một tuần mà không đỡ nên được dược sĩ hoặc bác sĩ đánh giá thay vì tiếp tục tự dùng thuốc.',
            ],
            [
                'code' => 'no_improvement', 'name_vi' => 'Đã dùng thuốc nhưng không đỡ', 'severity' => 'caution',
                'symptom' => null,
                'keywords' => ['khong do', 'khong giam', 'van con', 'uong thuoc khong khoi', 'da uong ma van'],
                'guidance_vi' => 'Đã dùng thuốc mà không cải thiện là dấu hiệu cần đánh giá lại nguyên nhân. Anh/chị nên gặp dược sĩ hoặc bác sĩ thay vì tăng liều hay đổi thuốc tự phát.',
            ],
            [
                'code' => 'chronic_disease_present', 'name_vi' => 'Có bệnh nền', 'severity' => 'caution',
                'symptom' => null,
                'keywords' => ['benh nen', 'tieu duong', 'suy than', 'suy gan', 'benh tim', 'hen suyen', 'huyet ap cao'],
                'guidance_vi' => 'Khi có bệnh nền, thuốc không kê đơn vẫn có thể tương tác hoặc ảnh hưởng gan thận. Anh/chị nên để dược sĩ kiểm tra trước khi dùng.',
            ],
        ];
    }

    /**
     * Conditions are evaluated by MedicalTriageService::matchesConditions.
     * Higher priority wins, so age/pregnancy guards sit above the generic rule
     * for the same symptom.
     *
     * @param array<string, int> $symptoms
     */
    protected function seedTriageRules(array $symptoms): void
    {
        $categoryIds = Category::pluck('MaDM', 'name');

        foreach ($this->triageRuleDefinitions() as $definition) {
            $symptomCode = $definition['symptom'];
            unset($definition['symptom']);

            if (!isset($symptoms[$symptomCode])) {
                continue;
            }

            $definition['MaTC'] = $symptoms[$symptomCode];
            $definition['suggest_categories'] = collect($definition['categories'] ?? [])
                ->map(fn (string $name) => $categoryIds[$name] ?? null)
                ->filter()
                ->values()
                ->all();
            unset($definition['categories']);

            // reviewed_by stays null on purpose: no licensed pharmacist has signed
            // off on this seed data. See the class docblock.
            MedicalTriageRule::updateOrCreate(
                ['MaTC' => $definition['MaTC'], 'priority' => $definition['priority']],
                $definition
            );
        }
    }

    protected function triageRuleDefinitions(): array
    {
        $painCategory = 'Thuốc giảm đau - Hạ sốt';
        $digestiveCategory = 'Thuốc tiêu hóa';
        $vitaminCategory = 'Vitamin và khoáng chất';
        $cardioCategory = 'Thuốc tim mạch';

        return [
            // --- Guards that apply to every symptom they attach to (high priority) ---
            $this->rule('fever', 'refer_doctor', 100, ['age' => ['<' => 2]],
                advice: 'Trẻ dưới 2 tuổi bị sốt cần được bác sĩ nhi khám trực tiếp. Anh/chị vui lòng đưa bé đến cơ sở y tế, không tự dùng thuốc hạ sốt liều người lớn.'),
            $this->rule('fever', 'refer_doctor', 90, ['duration_days' => ['>=' => 3]],
                advice: 'Sốt kéo dài từ 3 ngày trở lên cần được bác sĩ xác định nguyên nhân, không nên tiếp tục chỉ hạ sốt.'),
            $this->rule('fever', 'pharmacist_review', 80, ['pregnant' => true],
                advice: 'Đang mang thai thì việc chọn thuốc hạ sốt cần được dược sĩ kiểm tra trước.'),
            $this->rule('fever', 'self_care', 10, null,
                group: 'nhiễm virus đường hô hấp thông thường', confidence: 0.55,
                advice: "Nghỉ ngơi, uống nhiều nước, mặc thoáng.\nLau người bằng nước ấm nếu sốt cao.\nĐo lại nhiệt độ mỗi 4–6 giờ.",
                safetyNet: 'Đi khám ngay nếu sốt trên 39°C không hạ, sốt quá 3 ngày, lừ đừ, khó thở, phát ban, đau đầu dữ dội hoặc cứng cổ.',
                categories: [$painCategory]),

            // --- Headache ---
            $this->rule('headache', 'refer_doctor', 100, ['age' => ['<' => 6]],
                advice: 'Trẻ nhỏ bị đau đầu cần được bác sĩ khám để loại trừ nguyên nhân cần theo dõi.'),
            $this->rule('headache', 'refer_doctor', 90, ['duration_days' => ['>=' => 7]],
                advice: 'Đau đầu kéo dài trên một tuần nên được bác sĩ đánh giá thay vì tiếp tục dùng thuốc giảm đau.'),
            $this->rule('headache', 'pharmacist_review', 80, ['tried_medication' => true],
                advice: 'Đã dùng thuốc giảm đau mà chưa đỡ, dược sĩ cần xem lại trước khi đổi thuốc.'),
            $this->rule('headache', 'self_care', 10, null,
                group: 'đau đầu căng cơ hoặc do căng thẳng', confidence: 0.55,
                advice: "Nghỉ ngơi ở nơi yên tĩnh, giảm ánh sáng mạnh.\nUống đủ nước, giảm thời gian nhìn màn hình.\nXoa bóp nhẹ vùng vai cổ.",
                safetyNet: 'Đi khám ngay nếu đau đầu dữ dội đột ngột, kèm nôn, sốt, cứng cổ, mờ mắt, yếu tay chân hoặc nói khó.',
                categories: [$painCategory]),

            // --- Cough / cold ---
            $this->rule('cough', 'refer_doctor', 100, ['age' => ['<' => 2]],
                advice: 'Trẻ dưới 2 tuổi bị ho cần bác sĩ nhi khám, nhiều thuốc ho chống chỉ định ở tuổi này.'),
            $this->rule('cough', 'refer_doctor', 90, ['duration_days' => ['>=' => 14]],
                advice: 'Ho kéo dài từ 2 tuần trở lên cần được bác sĩ khám để loại trừ nguyên nhân cần điều trị riêng.'),
            $this->rule('cough', 'self_care', 10, null,
                group: 'viêm đường hô hấp trên', confidence: 0.5,
                advice: "Uống nhiều nước ấm, giữ ấm vùng cổ.\nTránh khói thuốc và không khí lạnh khô.\nCó thể dùng mật ong pha nước ấm nếu trên 1 tuổi.",
                safetyNet: 'Đi khám ngay nếu ho ra máu, khó thở, đau ngực, sốt cao kéo dài hoặc sụt cân không rõ lý do.',
                categories: [$painCategory]),
            $this->rule('common_cold', 'self_care', 10, null,
                group: 'cảm lạnh do virus', confidence: 0.6,
                advice: "Nghỉ ngơi, uống đủ nước, ăn thức ăn dễ tiêu.\nRửa mũi bằng nước muối sinh lý.\nThường tự khỏi trong 5–7 ngày.",
                safetyNet: 'Đi khám nếu sau 7 ngày không đỡ, sốt cao trở lại, đau tai, khó thở hoặc đau ngực.',
                categories: [$painCategory, $vitaminCategory]),
            $this->rule('sore_throat', 'pharmacist_review', 80, ['duration_days' => ['>=' => 5]],
                advice: 'Đau họng quá 5 ngày cần dược sĩ xem có phải nhiễm khuẩn cần kháng sinh hay không.'),
            $this->rule('sore_throat', 'self_care', 10, null,
                group: 'viêm họng do virus', confidence: 0.5,
                advice: "Súc miệng nước muối ấm 2–3 lần mỗi ngày.\nUống nước ấm, tránh đồ lạnh và cay.\nHạn chế nói nhiều để họng nghỉ.",
                safetyNet: 'Đi khám ngay nếu khó thở, khó nuốt nước bọt, sưng cổ một bên, sốt cao hoặc có mủ trong họng.',
                categories: [$painCategory]),
            $this->rule('runny_nose', 'self_care', 10, null,
                group: 'viêm mũi do virus hoặc dị ứng', confidence: 0.5,
                advice: "Rửa mũi bằng nước muối sinh lý.\nGiữ ấm, tránh bụi và phấn hoa nếu nghi dị ứng.\nUống đủ nước để dịch mũi loãng hơn.",
                safetyNet: 'Đi khám nếu dịch mũi có mủ vàng xanh kéo dài, đau vùng mặt, sốt cao hoặc nghẹt mũi quá 10 ngày.',
                categories: [$painCategory]),

            // --- Digestive ---
            $this->rule('diarrhea', 'refer_doctor', 100, ['age' => ['<' => 2]],
                advice: 'Trẻ dưới 2 tuổi bị tiêu chảy rất dễ mất nước nhanh, cần bác sĩ nhi đánh giá ngay.'),
            $this->rule('diarrhea', 'refer_doctor', 95, ['duration_days' => ['>=' => 3]],
                advice: 'Tiêu chảy quá 3 ngày cần bác sĩ xác định nguyên nhân và đánh giá mức mất nước.'),
            $this->rule('diarrhea', 'self_care', 10, null,
                group: 'rối loạn tiêu hóa hoặc nhiễm khuẩn đường ruột nhẹ', confidence: 0.5,
                advice: "Bù nước bằng oresol pha đúng hướng dẫn sau mỗi lần đi ngoài.\nĂn thức ăn dễ tiêu: cháo, cơm nhão, chuối.\nTránh sữa, đồ dầu mỡ và nước ngọt có gas.",
                safetyNet: 'Đi khám ngay nếu phân có máu, sốt cao, nôn liên tục, tiểu ít, mệt lả hoặc đau bụng tăng dần.',
                categories: [$digestiveCategory]),
            $this->rule('constipation', 'pharmacist_review', 80, ['duration_days' => ['>=' => 7]],
                advice: 'Táo bón kéo dài trên một tuần cần dược sĩ xem lại, tránh lạm dụng thuốc nhuận tràng.'),
            $this->rule('constipation', 'self_care', 10, null,
                group: 'táo bón chức năng', confidence: 0.6,
                advice: "Uống 1,5–2 lít nước mỗi ngày.\nTăng rau xanh, trái cây, ngũ cốc nguyên hạt.\nVận động nhẹ 20–30 phút mỗi ngày, tập đi vệ sinh cùng giờ.",
                safetyNet: 'Đi khám nếu táo bón kèm đau bụng nhiều, sụt cân, phân có máu hoặc thay đổi thói quen đi ngoài đột ngột kéo dài.',
                categories: [$digestiveCategory]),
            $this->rule('stomach_pain', 'refer_doctor', 95, ['duration_days' => ['>=' => 14]],
                advice: 'Đau dạ dày kéo dài trên 2 tuần cần được bác sĩ nội soi hoặc đánh giá chuyên khoa.'),
            $this->rule('stomach_pain', 'self_care', 10, null,
                group: 'viêm dạ dày hoặc trào ngược mức nhẹ', confidence: 0.45,
                advice: "Ăn đúng giờ, chia nhỏ bữa, không để bụng quá đói.\nTránh rượu, cà phê, đồ chua cay và thuốc lá.\nKhông nằm ngay sau khi ăn, kê cao đầu khi ngủ.",
                safetyNet: 'Đi khám ngay nếu nôn ra máu, phân đen, sụt cân nhanh, khó nuốt hoặc đau lan ra lưng.',
                categories: [$digestiveCategory]),
            $this->rule('abdominal_pain', 'pharmacist_review', 70, null,
                advice: 'Đau bụng có rất nhiều nguyên nhân. Dược sĩ cần hỏi thêm trước khi gợi ý thuốc.'),
            $this->rule('bloating', 'self_care', 10, null,
                group: 'đầy hơi chức năng', confidence: 0.6,
                advice: "Ăn chậm, nhai kỹ, tránh nói khi ăn.\nGiảm đồ uống có gas, đậu, bắp cải.\nĐi bộ nhẹ 10–15 phút sau bữa ăn.",
                safetyNet: 'Đi khám nếu đầy hơi kèm nôn, không đi ngoài được, bụng chướng căng hoặc đau tăng dần.',
                categories: [$digestiveCategory]),
            $this->rule('indigestion', 'self_care', 10, null,
                group: 'khó tiêu chức năng', confidence: 0.55,
                advice: "Chia nhỏ bữa ăn, ưu tiên thức ăn dễ tiêu.\nHạn chế dầu mỡ, rượu bia.\nKhông ăn quá muộn trước khi ngủ.",
                safetyNet: 'Đi khám nếu khó tiêu kèm sụt cân, nôn nhiều, đau tăng hoặc phân đen.',
                categories: [$digestiveCategory]),
            $this->rule('nausea', 'pharmacist_review', 80, ['pregnant' => true],
                advice: 'Buồn nôn khi mang thai cần dược sĩ hoặc bác sĩ sản khoa hướng dẫn, không tự dùng thuốc chống nôn.'),
            $this->rule('nausea', 'self_care', 10, null,
                group: 'buồn nôn do rối loạn tiêu hóa', confidence: 0.45,
                advice: "Uống từng ngụm nhỏ nước hoặc oresol.\nĂn nhạt, khô như bánh mì, cháo trắng.\nTránh mùi thức ăn nồng và đồ dầu mỡ.",
                safetyNet: 'Đi khám ngay nếu nôn ra máu, nôn liên tục không uống được nước, đau đầu dữ dội hoặc đau bụng khu trú.',
                categories: [$digestiveCategory]),
            $this->rule('heartburn', 'self_care', 10, null,
                group: 'trào ngược dạ dày thực quản mức nhẹ', confidence: 0.5,
                advice: "Không nằm trong 2–3 giờ sau khi ăn, kê cao đầu giường.\nTránh cà phê, rượu, đồ chua cay, bạc hà.\nGiảm cân nếu thừa cân, bỏ thuốc lá.",
                safetyNet: 'Đi khám nếu khó nuốt, nuốt đau, sụt cân, nôn ra máu hoặc đau ngực khi gắng sức.',
                categories: [$digestiveCategory]),

            // --- Menstrual ---
            // Priority must clear abdominal_pain's 70: "đau bụng kinh" matches the generic
            // "dau bung" alias too, and resolveRule() takes the highest priority across
            // every matched symptom, not the most specific symptom.
            $this->rule('menstrual_cramps', 'pharmacist_review', 85, ['duration_days' => ['>=' => 7]],
                advice: 'Đau bụng kinh kéo dài quá một tuần đã vượt ngoài một kỳ kinh thông thường. Dược sĩ cần hỏi thêm trước khi gợi ý thuốc.'),
            $this->rule('menstrual_cramps', 'self_care', 75, null,
                group: 'đau bụng kinh nguyên phát', confidence: 0.5,
                advice: "Chườm ấm vùng bụng dưới 15–20 phút mỗi lần, lặp lại khi cần.\nNghỉ ngơi, giữ ấm, uống đủ nước ấm; vận động nhẹ như đi bộ hoặc giãn cơ giúp giảm co thắt.\nThuốc giảm đau kháng viêm NSAID hiệu quả nhất khi uống ngay lúc mới bắt đầu đau thay vì chờ đau nhiều. Uống sau ăn, và hỏi dược sĩ trước nếu anh/chị có bệnh dạ dày, hen, bệnh thận hoặc đang dùng thuốc khác.",
                // The engine matches one phrase at a time and cannot express "trễ kinh AND
                // đau một bên", so the conjunction that rules out an ectopic pregnancy is
                // written for the patient to read rather than faked as a rule.
                safetyNet: 'Đi khám ngay nếu: trễ kinh hoặc nghi có thai mà đau một bên bụng dưới; đau đột ngột dữ dội khác hẳn mọi kỳ trước; kèm sốt hoặc dịch âm đạo bất thường; choáng, ngất, nôn liên tục; hoặc ra máu nhiều phải thay băng mỗi giờ.',
                categories: [$painCategory]),

            // --- Musculoskeletal / general ---
            $this->rule('joint_pain', 'refer_doctor', 90, ['duration_days' => ['>=' => 14]],
                advice: 'Đau khớp kéo dài trên 2 tuần cần bác sĩ cơ xương khớp đánh giá.'),
            $this->rule('joint_pain', 'self_care', 10, null,
                group: 'đau khớp cơ học hoặc thoái hóa mức nhẹ', confidence: 0.45,
                advice: "Nghỉ ngơi vùng khớp đau, tránh mang nặng.\nChườm ấm 15–20 phút mỗi lần.\nVận động nhẹ nhàng trong ngưỡng không đau.",
                safetyNet: 'Đi khám ngay nếu khớp sưng nóng đỏ, sốt kèm đau khớp, khớp biến dạng hoặc không đi lại được.',
                categories: [$painCategory, $vitaminCategory]),
            $this->rule('muscle_pain', 'self_care', 10, null,
                group: 'căng cơ do vận động hoặc tư thế', confidence: 0.6,
                advice: "Nghỉ ngơi vùng cơ đau 1–2 ngày.\nChườm lạnh 48 giờ đầu nếu mới chấn thương, sau đó chườm ấm.\nGiãn cơ nhẹ, chỉnh lại tư thế ngồi làm việc.",
                safetyNet: 'Đi khám nếu đau sau chấn thương mạnh, sưng nề nhiều, yếu chi, tê bì hoặc đau lan xuống chân.',
                categories: [$painCategory]),
            $this->rule('toothache', 'refer_doctor', 90, null,
                advice: 'Đau răng cần nha sĩ xử lý nguyên nhân. Thuốc giảm đau chỉ giúp tạm thời và không giải quyết được ổ viêm.'),
            $this->rule('dizziness', 'pharmacist_review', 80, null,
                advice: 'Chóng mặt có thể do huyết áp, tai trong, thiếu máu hoặc thuốc đang dùng. Dược sĩ cần hỏi thêm.'),
            $this->rule('fatigue', 'self_care', 10, null,
                group: 'mệt mỏi do thiếu ngủ hoặc căng thẳng', confidence: 0.4,
                advice: "Ngủ đủ 7–8 tiếng, cố định giờ ngủ.\nĂn đủ bữa, bổ sung rau xanh và đạm.\nVận động nhẹ mỗi ngày, giảm caffeine buổi chiều.",
                safetyNet: 'Đi khám nếu mệt kéo dài trên 2 tuần, sụt cân, sốt về chiều, khó thở hoặc da xanh tái.',
                categories: [$vitaminCategory]),
            $this->rule('insomnia', 'pharmacist_review', 70, ['duration_days' => ['>=' => 14]],
                advice: 'Mất ngủ kéo dài trên 2 tuần cần dược sĩ hoặc bác sĩ đánh giá, không nên tự dùng thuốc ngủ.'),
            $this->rule('insomnia', 'self_care', 10, null,
                group: 'mất ngủ do thói quen sinh hoạt', confidence: 0.5,
                advice: "Đi ngủ và thức dậy cùng giờ mỗi ngày.\nKhông dùng điện thoại 1 giờ trước khi ngủ.\nTránh caffeine sau 14 giờ và rượu buổi tối.",
                safetyNet: 'Gặp bác sĩ nếu mất ngủ kèm buồn bã kéo dài, lo lắng nhiều, hoặc buồn ngủ quá mức ban ngày gây nguy hiểm khi lái xe.',
                categories: [$vitaminCategory]),

            // --- Skin / allergy ---
            $this->rule('rash', 'pharmacist_review', 80, null,
                advice: 'Phát ban cần được xem trực tiếp để phân biệt dị ứng, nhiễm trùng hay phản ứng thuốc.'),
            $this->rule('itching', 'self_care', 10, null,
                group: 'ngứa da do khô da hoặc dị ứng nhẹ', confidence: 0.45,
                advice: "Tắm nước ấm vừa, không chà xát mạnh.\nDưỡng ẩm ngay sau khi tắm.\nTránh xà phòng có mùi mạnh và quần áo len thô.",
                safetyNet: 'Đi khám ngay nếu ngứa kèm sưng môi mặt, khó thở, sốt, hoặc ban lan nhanh toàn thân.',
                categories: [$painCategory]),
            $this->rule('allergy', 'refer_doctor', 95, ['tried_medication' => true],
                advice: 'Dị ứng không đỡ sau khi đã dùng thuốc cần bác sĩ đánh giá, đặc biệt nếu nghi do thuốc.'),
            $this->rule('allergy', 'pharmacist_review', 70, null,
                advice: 'Dược sĩ cần biết tác nhân nghi ngờ và tiền sử dị ứng trước khi gợi ý thuốc.'),

            // --- Cardio ---
            $this->rule('high_blood_pressure', 'refer_doctor', 95, null,
                advice: 'Huyết áp cao cần bác sĩ theo dõi và kê đơn. Thuốc huyết áp không phải thuốc không kê đơn.'),

            // --- Dehydration self-care fallback ---
            $this->rule('dehydration', 'self_care', 10, null,
                group: 'mất nước mức nhẹ', confidence: 0.5,
                advice: "Uống oresol pha đúng tỉ lệ ghi trên gói, uống từng ngụm nhỏ.\nNghỉ ngơi nơi thoáng mát.\nTheo dõi lượng nước tiểu và màu nước tiểu.",
                safetyNet: 'Đi khám ngay nếu tiểu rất ít hoặc không tiểu, lừ đừ, mắt trũng, tay chân lạnh hoặc không uống được nước.',
                categories: [$digestiveCategory, $vitaminCategory]),
        ];
    }

    /**
     * Keeps the rule table above readable — long arrays of the same shape are
     * harder to audit than named arguments.
     */
    protected function rule(
        string $symptom,
        string $outcome,
        int $priority,
        ?array $conditions,
        ?string $advice = null,
        ?string $safetyNet = null,
        ?string $group = null,
        ?float $confidence = null,
        array $categories = [],
    ): array {
        return [
            'symptom' => $symptom,
            'outcome' => $outcome,
            'priority' => $priority,
            'conditions' => $conditions,
            'advice_vi' => $advice,
            'safety_net_vi' => $safetyNet,
            'condition_group_vi' => $group,
            'confidence' => $confidence,
            'categories' => $categories,
            'is_active' => true,
        ];
    }

    /**
     * Ingredient names are stored lowercase without dosage, matching how
     * DrugSafetyService normalises products.ingredients before lookup. The crawler
     * writes ingredients in the same "Name 500mg" form the parser expects.
     */
    protected function seedDrugInteractions(): void
    {
        $nsaidPairNote = 'Dùng đồng thời hai thuốc chống viêm không steroid làm tăng rõ nguy cơ loét và xuất huyết tiêu hóa mà không tăng hiệu quả giảm đau.';
        $nsaidPairAction = 'Chỉ dùng một thuốc trong nhóm. Anh/chị nên hỏi dược sĩ để chọn loại phù hợp.';

        $nsaids = ['ibuprofen', 'diclofenac', 'meloxicam', 'naproxen', 'ketoprofen', 'celecoxib', 'acid acetylsalicylic'];
        $pairs = [];

        // Every NSAID pair is a major interaction; generate them instead of listing 21 rows.
        for ($i = 0; $i < count($nsaids); $i++) {
            for ($j = $i + 1; $j < count($nsaids); $j++) {
                $pairs[] = [
                    'ingredient_a' => $nsaids[$i],
                    'ingredient_b' => $nsaids[$j],
                    'severity' => 'major',
                    'description_vi' => $nsaidPairNote,
                    'recommendation_vi' => $nsaidPairAction,
                ];
            }
        }

        $pairs = array_merge($pairs, [
            [
                'ingredient_a' => 'clopidogrel', 'ingredient_b' => 'ibuprofen', 'severity' => 'major',
                'description_vi' => 'Thuốc chống kết tập tiểu cầu dùng cùng thuốc chống viêm không steroid làm tăng nguy cơ chảy máu tiêu hóa.',
                'recommendation_vi' => 'Không tự dùng chung. Anh/chị cần hỏi bác sĩ đang kê clopidogrel.',
            ],
            [
                'ingredient_a' => 'clopidogrel', 'ingredient_b' => 'acid acetylsalicylic', 'severity' => 'major',
                'description_vi' => 'Hai thuốc đều làm giảm khả năng đông máu, dùng cùng làm tăng nguy cơ xuất huyết.',
                'recommendation_vi' => 'Chỉ dùng cùng khi có chỉ định và theo dõi của bác sĩ tim mạch.',
            ],
            [
                'ingredient_a' => 'clopidogrel', 'ingredient_b' => 'omeprazole', 'severity' => 'moderate',
                'description_vi' => 'Omeprazole có thể làm giảm hiệu quả chống kết tập tiểu cầu của clopidogrel.',
                'recommendation_vi' => 'Anh/chị nên hỏi dược sĩ để đổi sang thuốc giảm acid khác phù hợp hơn.',
            ],
            [
                'ingredient_a' => 'clopidogrel', 'ingredient_b' => 'esomeprazole', 'severity' => 'moderate',
                'description_vi' => 'Esomeprazole có thể làm giảm hiệu quả của clopidogrel qua cùng cơ chế chuyển hóa gan.',
                'recommendation_vi' => 'Cần dược sĩ hoặc bác sĩ xem lại phối hợp này.',
            ],
            [
                'ingredient_a' => 'clarithromycin', 'ingredient_b' => 'simvastatin', 'severity' => 'major',
                'description_vi' => 'Clarithromycin làm tăng mạnh nồng độ simvastatin trong máu, tăng nguy cơ tiêu cơ vân.',
                'recommendation_vi' => 'Không dùng cùng. Bác sĩ thường tạm ngưng statin trong thời gian dùng kháng sinh này.',
            ],
            [
                'ingredient_a' => 'clarithromycin', 'ingredient_b' => 'atorvastatin', 'severity' => 'moderate',
                'description_vi' => 'Clarithromycin làm tăng nồng độ atorvastatin, tăng nguy cơ đau cơ và tổn thương cơ.',
                'recommendation_vi' => 'Cần bác sĩ điều chỉnh liều hoặc tạm ngưng statin.',
            ],
            [
                'ingredient_a' => 'ciprofloxacin', 'ingredient_b' => 'calcium carbonate', 'severity' => 'moderate',
                'description_vi' => 'Canxi làm giảm hấp thu kháng sinh nhóm quinolone, khiến kháng sinh kém hiệu quả.',
                'recommendation_vi' => 'Uống cách nhau ít nhất 2 giờ.',
            ],
            [
                'ingredient_a' => 'levofloxacin', 'ingredient_b' => 'calcium carbonate', 'severity' => 'moderate',
                'description_vi' => 'Canxi làm giảm hấp thu levofloxacin qua đường tiêu hóa.',
                'recommendation_vi' => 'Uống cách nhau ít nhất 2 giờ.',
            ],
            [
                'ingredient_a' => 'ciprofloxacin', 'ingredient_b' => 'magnesium oxide', 'severity' => 'moderate',
                'description_vi' => 'Magnesi tạo phức với quinolone làm giảm hấp thu kháng sinh.',
                'recommendation_vi' => 'Uống cách nhau ít nhất 2 giờ.',
            ],
            [
                'ingredient_a' => 'doxycycline', 'ingredient_b' => 'calcium carbonate', 'severity' => 'moderate',
                'description_vi' => 'Canxi làm giảm hấp thu doxycycline đáng kể.',
                'recommendation_vi' => 'Uống doxycycline cách xa canxi và sữa ít nhất 2 giờ.',
            ],
            [
                'ingredient_a' => 'doxycycline', 'ingredient_b' => 'ferrous sulfate', 'severity' => 'moderate',
                'description_vi' => 'Sắt tạo phức với doxycycline, làm giảm hiệu quả kháng sinh.',
                'recommendation_vi' => 'Uống cách nhau ít nhất 2–3 giờ.',
            ],
            [
                'ingredient_a' => 'ferrous sulfate', 'ingredient_b' => 'omeprazole', 'severity' => 'minor',
                'description_vi' => 'Thuốc giảm acid dạ dày làm giảm hấp thu sắt.',
                'recommendation_vi' => 'Uống sắt cùng nước cam hoặc vitamin C, cách xa thuốc giảm acid.',
            ],
            [
                'ingredient_a' => 'ferrous sulfate', 'ingredient_b' => 'calcium carbonate', 'severity' => 'moderate',
                'description_vi' => 'Canxi và sắt cạnh tranh hấp thu tại ruột.',
                'recommendation_vi' => 'Uống ở hai thời điểm khác nhau trong ngày.',
            ],
            [
                'ingredient_a' => 'losartan', 'ingredient_b' => 'ibuprofen', 'severity' => 'moderate',
                'description_vi' => 'Thuốc chống viêm không steroid làm giảm hiệu quả hạ huyết áp và có thể ảnh hưởng chức năng thận.',
                'recommendation_vi' => 'Người đang dùng thuốc huyết áp nên hỏi dược sĩ trước khi dùng thuốc giảm đau nhóm này.',
            ],
            [
                'ingredient_a' => 'enalapril', 'ingredient_b' => 'ibuprofen', 'severity' => 'moderate',
                'description_vi' => 'Phối hợp này làm giảm tác dụng hạ áp và tăng nguy cơ suy giảm chức năng thận.',
                'recommendation_vi' => 'Cần dược sĩ tư vấn thuốc giảm đau thay thế.',
            ],
            [
                'ingredient_a' => 'valsartan', 'ingredient_b' => 'diclofenac', 'severity' => 'moderate',
                'description_vi' => 'Thuốc chống viêm làm giảm hiệu quả của thuốc hạ huyết áp nhóm chẹn thụ thể.',
                'recommendation_vi' => 'Nên chọn thuốc giảm đau khác sau khi hỏi dược sĩ.',
            ],
            [
                'ingredient_a' => 'amlodipine', 'ingredient_b' => 'simvastatin', 'severity' => 'moderate',
                'description_vi' => 'Amlodipine làm tăng nồng độ simvastatin, tăng nguy cơ đau cơ.',
                'recommendation_vi' => 'Bác sĩ thường giới hạn liều simvastatin khi dùng cùng amlodipine.',
            ],
            [
                'ingredient_a' => 'metoprolol', 'ingredient_b' => 'diclofenac', 'severity' => 'minor',
                'description_vi' => 'Thuốc chống viêm có thể làm giảm nhẹ hiệu quả hạ huyết áp của thuốc chẹn beta.',
                'recommendation_vi' => 'Theo dõi huyết áp nếu phải dùng cùng.',
            ],
            [
                'ingredient_a' => 'bisoprolol', 'ingredient_b' => 'ibuprofen', 'severity' => 'minor',
                'description_vi' => 'Có thể làm giảm nhẹ hiệu quả kiểm soát huyết áp.',
                'recommendation_vi' => 'Theo dõi huyết áp, ưu tiên paracetamol nếu chỉ cần giảm đau.',
            ],
            [
                'ingredient_a' => 'metronidazole', 'ingredient_b' => 'omeprazole', 'severity' => 'minor',
                'description_vi' => 'Phối hợp thường dùng trong điều trị H. pylori nhưng có thể tăng tác dụng phụ tiêu hóa.',
                'recommendation_vi' => 'Chỉ dùng theo đúng phác đồ bác sĩ kê.',
            ],
            [
                'ingredient_a' => 'loperamide', 'ingredient_b' => 'domperidone', 'severity' => 'moderate',
                'description_vi' => 'Hai thuốc tác động trái ngược lên nhu động ruột, làm khó đánh giá diễn tiến bệnh.',
                'recommendation_vi' => 'Không nên dùng cùng lúc, hãy hỏi dược sĩ về thứ tự dùng.',
            ],
            [
                'ingredient_a' => 'loperamide', 'ingredient_b' => 'bisacodyl', 'severity' => 'major',
                'description_vi' => 'Thuốc cầm tiêu chảy dùng cùng thuốc nhuận tràng là hai chỉ định ngược nhau, có thể gây rối loạn nhu động nặng.',
                'recommendation_vi' => 'Không dùng cùng. Anh/chị cần xác định lại triệu chứng với dược sĩ.',
            ],
            [
                'ingredient_a' => 'bisacodyl', 'ingredient_b' => 'lactulose', 'severity' => 'moderate',
                'description_vi' => 'Dùng hai thuốc nhuận tràng cùng lúc dễ gây tiêu chảy và mất điện giải.',
                'recommendation_vi' => 'Chỉ dùng một loại, bắt đầu từ liều thấp.',
            ],
            [
                'ingredient_a' => 'diosmectite', 'ingredient_b' => 'amoxicillin', 'severity' => 'moderate',
                'description_vi' => 'Diosmectite hấp phụ thuốc khác trong lòng ruột, làm giảm hấp thu kháng sinh.',
                'recommendation_vi' => 'Uống kháng sinh cách diosmectite ít nhất 2 giờ.',
            ],
            [
                'ingredient_a' => 'diosmectite', 'ingredient_b' => 'paracetamol', 'severity' => 'minor',
                'description_vi' => 'Diosmectite có thể làm giảm hấp thu paracetamol nếu uống cùng lúc.',
                'recommendation_vi' => 'Uống cách nhau khoảng 2 giờ.',
            ],
            [
                'ingredient_a' => 'omeprazole', 'ingredient_b' => 'esomeprazole', 'severity' => 'moderate',
                'description_vi' => 'Hai thuốc cùng nhóm ức chế bơm proton, dùng chung không tăng hiệu quả mà tăng tác dụng phụ.',
                'recommendation_vi' => 'Chỉ dùng một loại.',
            ],
            [
                'ingredient_a' => 'omeprazole', 'ingredient_b' => 'ranitidine', 'severity' => 'minor',
                'description_vi' => 'Hai thuốc giảm acid theo hai cơ chế, phối hợp thường không cần thiết.',
                'recommendation_vi' => 'Hỏi dược sĩ để chọn một loại phù hợp.',
            ],
            [
                'ingredient_a' => 'paracetamol', 'ingredient_b' => 'ibuprofen', 'severity' => 'minor',
                'description_vi' => 'Có thể phối hợp trong một số trường hợp nhưng cần đúng khoảng cách liều và không vượt liều tối đa mỗi loại.',
                'recommendation_vi' => 'Nếu cần dùng cả hai, anh/chị nên để dược sĩ hướng dẫn liều và khoảng cách.',
            ],
            [
                'ingredient_a' => 'azithromycin', 'ingredient_b' => 'magnesium oxide', 'severity' => 'minor',
                'description_vi' => 'Thuốc kháng acid chứa magnesi làm giảm nồng độ đỉnh của azithromycin.',
                'recommendation_vi' => 'Uống cách nhau ít nhất 2 giờ.',
            ],
            [
                'ingredient_a' => 'simvastatin', 'ingredient_b' => 'atorvastatin', 'severity' => 'major',
                'description_vi' => 'Hai statin dùng cùng lúc làm tăng mạnh nguy cơ tổn thương cơ và gan mà không tăng lợi ích.',
                'recommendation_vi' => 'Chỉ dùng một statin theo chỉ định bác sĩ.',
            ],
        ]);

        foreach ($pairs as $pair) {
            DrugInteraction::updateOrCreate(
                ['ingredient_a' => $pair['ingredient_a'], 'ingredient_b' => $pair['ingredient_b']],
                $pair + ['is_active' => true]
            );
        }
    }

    /**
     * `absolute` blocks the suggestion outright; `relative` warns and asks the
     * user to confirm. Age values are years, matched against users.birth_date.
     */
    protected function seedContraindications(): void
    {
        $rows = [
            // Pregnancy — NSAIDs are the main risk group in this catalog.
            ['ingredient' => 'ibuprofen', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Ibuprofen không dùng khi mang thai, đặc biệt từ tuần 20 trở đi, vì ảnh hưởng tuần hoàn và thận của thai. Anh/chị vui lòng hỏi bác sĩ sản khoa.'],
            ['ingredient' => 'diclofenac', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Diclofenac chống chỉ định trong thai kỳ do nguy cơ với thai nhi.'],
            ['ingredient' => 'naproxen', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Naproxen không dùng khi mang thai.'],
            ['ingredient' => 'meloxicam', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Meloxicam chống chỉ định trong thai kỳ.'],
            ['ingredient' => 'celecoxib', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Celecoxib chống chỉ định trong thai kỳ.'],
            ['ingredient' => 'ketoprofen', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Ketoprofen chống chỉ định trong thai kỳ.'],
            ['ingredient' => 'acid acetylsalicylic', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Aspirin liều giảm đau không dùng khi mang thai. Liều thấp phòng ngừa chỉ dùng khi bác sĩ chỉ định.'],
            ['ingredient' => 'doxycycline', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Doxycycline chống chỉ định khi mang thai vì ảnh hưởng đến răng và xương của thai.'],
            ['ingredient' => 'ciprofloxacin', 'condition_type' => 'pregnancy', 'severity' => 'relative',
                'warning_vi' => 'Kháng sinh nhóm quinolone thường được tránh trong thai kỳ. Cần bác sĩ cân nhắc lợi ích và nguy cơ.'],
            ['ingredient' => 'levofloxacin', 'condition_type' => 'pregnancy', 'severity' => 'relative',
                'warning_vi' => 'Levofloxacin thường được tránh trong thai kỳ, cần chỉ định của bác sĩ.'],
            ['ingredient' => 'simvastatin', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Statin chống chỉ định trong thai kỳ.'],
            ['ingredient' => 'atorvastatin', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Statin chống chỉ định trong thai kỳ.'],
            ['ingredient' => 'enalapril', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Thuốc ức chế men chuyển chống chỉ định trong thai kỳ do gây hại cho thai.'],
            ['ingredient' => 'losartan', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Thuốc chẹn thụ thể angiotensin chống chỉ định trong thai kỳ.'],
            ['ingredient' => 'valsartan', 'condition_type' => 'pregnancy', 'severity' => 'absolute',
                'warning_vi' => 'Valsartan chống chỉ định trong thai kỳ.'],

            // Breastfeeding
            ['ingredient' => 'doxycycline', 'condition_type' => 'breastfeeding', 'severity' => 'relative',
                'warning_vi' => 'Doxycycline khi cho con bú cần bác sĩ cân nhắc, thường có lựa chọn an toàn hơn.'],
            ['ingredient' => 'ciprofloxacin', 'condition_type' => 'breastfeeding', 'severity' => 'relative',
                'warning_vi' => 'Cần hỏi bác sĩ trước khi dùng quinolone trong thời gian cho con bú.'],
            ['ingredient' => 'metronidazole', 'condition_type' => 'breastfeeding', 'severity' => 'relative',
                'warning_vi' => 'Metronidazole qua sữa mẹ, cần bác sĩ hướng dẫn thời điểm dùng.'],
            ['ingredient' => 'acid acetylsalicylic', 'condition_type' => 'breastfeeding', 'severity' => 'relative',
                'warning_vi' => 'Aspirin liều cao không khuyến cáo khi cho con bú.'],

            // Age
            ['ingredient' => 'acid acetylsalicylic', 'condition_type' => 'age_under', 'condition_value' => '16',
                'severity' => 'absolute',
                'warning_vi' => 'Không dùng aspirin cho người dưới 16 tuổi vì nguy cơ hội chứng Reye, đặc biệt khi đang sốt do virus.'],
            ['ingredient' => 'ibuprofen', 'condition_type' => 'age_under', 'condition_value' => '2',
                'severity' => 'absolute',
                'warning_vi' => 'Trẻ dưới 2 tuổi cần bác sĩ nhi chỉ định liều, không tự dùng ibuprofen.'],
            ['ingredient' => 'doxycycline', 'condition_type' => 'age_under', 'condition_value' => '8',
                'severity' => 'absolute',
                'warning_vi' => 'Doxycycline không dùng cho trẻ dưới 8 tuổi vì ảnh hưởng răng và xương.'],
            ['ingredient' => 'ciprofloxacin', 'condition_type' => 'age_under', 'condition_value' => '18',
                'severity' => 'relative',
                'warning_vi' => 'Quinolone hạn chế dùng ở trẻ và người đang phát triển, cần chỉ định bác sĩ.'],
            ['ingredient' => 'levofloxacin', 'condition_type' => 'age_under', 'condition_value' => '18',
                'severity' => 'relative',
                'warning_vi' => 'Levofloxacin hạn chế dùng ở người dưới 18 tuổi.'],
            ['ingredient' => 'loperamide', 'condition_type' => 'age_under', 'condition_value' => '12',
                'severity' => 'absolute',
                'warning_vi' => 'Không dùng loperamide cho trẻ dưới 12 tuổi khi chưa có chỉ định bác sĩ.'],
            ['ingredient' => 'bisacodyl', 'condition_type' => 'age_under', 'condition_value' => '6',
                'severity' => 'relative',
                'warning_vi' => 'Thuốc nhuận tràng kích thích cần bác sĩ chỉ định ở trẻ nhỏ.'],
            ['ingredient' => 'domperidone', 'condition_type' => 'age_under', 'condition_value' => '12',
                'severity' => 'relative',
                'warning_vi' => 'Domperidone ở trẻ nhỏ cần bác sĩ chỉ định liều theo cân nặng.'],
            ['ingredient' => 'diclofenac', 'condition_type' => 'age_over', 'condition_value' => '65',
                'severity' => 'relative',
                'warning_vi' => 'Người trên 65 tuổi dùng thuốc chống viêm có nguy cơ loét dạ dày và ảnh hưởng thận cao hơn. Nên hỏi dược sĩ.'],
            ['ingredient' => 'meloxicam', 'condition_type' => 'age_over', 'condition_value' => '65',
                'severity' => 'relative',
                'warning_vi' => 'Người cao tuổi cần liều thấp nhất có hiệu quả và theo dõi tiêu hóa, thận.'],

            // Chronic disease — values match the tag suggestions in the health profile form.
            ['ingredient' => 'ibuprofen', 'condition_type' => 'chronic_disease', 'condition_value' => 'loét dạ dày',
                'severity' => 'absolute',
                'warning_vi' => 'Người có loét dạ dày không nên dùng thuốc chống viêm không steroid vì dễ gây chảy máu.'],
            ['ingredient' => 'diclofenac', 'condition_type' => 'chronic_disease', 'condition_value' => 'loét dạ dày',
                'severity' => 'absolute',
                'warning_vi' => 'Diclofenac làm nặng thêm loét dạ dày, cần tránh.'],
            ['ingredient' => 'acid acetylsalicylic', 'condition_type' => 'chronic_disease', 'condition_value' => 'loét dạ dày',
                'severity' => 'absolute',
                'warning_vi' => 'Aspirin làm tăng nguy cơ chảy máu ở người có loét dạ dày.'],
            ['ingredient' => 'ibuprofen', 'condition_type' => 'chronic_disease', 'condition_value' => 'suy thận',
                'severity' => 'absolute',
                'warning_vi' => 'Thuốc chống viêm không steroid có thể làm nặng thêm suy thận.'],
            ['ingredient' => 'diclofenac', 'condition_type' => 'chronic_disease', 'condition_value' => 'suy thận',
                'severity' => 'absolute',
                'warning_vi' => 'Diclofenac cần tránh ở người suy thận.'],
            ['ingredient' => 'ibuprofen', 'condition_type' => 'chronic_disease', 'condition_value' => 'hen suyễn',
                'severity' => 'relative',
                'warning_vi' => 'Một số người hen suyễn nhạy cảm với thuốc chống viêm không steroid, có thể lên cơn hen.'],
            ['ingredient' => 'acid acetylsalicylic', 'condition_type' => 'chronic_disease', 'condition_value' => 'hen suyễn',
                'severity' => 'relative',
                'warning_vi' => 'Aspirin có thể gây co thắt phế quản ở người hen nhạy cảm.'],
            ['ingredient' => 'paracetamol', 'condition_type' => 'chronic_disease', 'condition_value' => 'suy gan',
                'severity' => 'relative',
                'warning_vi' => 'Người suy gan cần giảm liều paracetamol và không vượt quá liều bác sĩ hướng dẫn.'],
            ['ingredient' => 'metoprolol', 'condition_type' => 'chronic_disease', 'condition_value' => 'hen suyễn',
                'severity' => 'relative',
                'warning_vi' => 'Thuốc chẹn beta có thể làm nặng thêm hen suyễn, cần bác sĩ theo dõi.'],
            ['ingredient' => 'bisoprolol', 'condition_type' => 'chronic_disease', 'condition_value' => 'hen suyễn',
                'severity' => 'relative',
                'warning_vi' => 'Cần bác sĩ đánh giá trước khi dùng thuốc chẹn beta ở người hen.'],
        ];

        foreach ($rows as $row) {
            DrugContraindication::updateOrCreate(
                [
                    'ingredient' => $row['ingredient'],
                    'condition_type' => $row['condition_type'],
                    'condition_value' => $row['condition_value'] ?? null,
                ],
                $row + ['is_active' => true]
            );
        }
    }
}
