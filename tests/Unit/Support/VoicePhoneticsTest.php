<?php

namespace Tests\Unit\Support;

use App\Support\VoicePhonetics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Khoá phiên âm gộp các cặp âm lệch giữa ba vùng miền, nên "thuấc hạ sốc" và
 * "thuốc hạ sốt" phải cho cùng một khoá. Số trong test này là số đã đo, không
 * phải kỳ vọng — xem §4.2 và §4.3 của spec.
 */
class VoicePhoneticsTest extends TestCase
{
    /** Cách đọc lệch vùng miền phải được nhận. */
    public static function regionalPairs(): array
    {
        return [
            'coda t/c (Nam)' => ['thuoc ha sot', 'thuấc hạ sốc'],
            'onset tr/ch' => ['khau trang', 'khẩu chang'],
            'onset s/x' => ['men vi sinh', 'mem vi xinh'],
            'nhiều âm tiết' => ['nuoc muoi sinh ly', 'nước muối xinh lý'],
            'coda n/ng' => ['kem chong nang', 'kem chóng nắng'],
            'đọc rời âm tiết' => ['paracetamol', 'pa ra xe ta mon'],
            'x giữa từ' => ['canxi', 'can xi'],
            'onset r/d' => ['siro ho', 'si rô hô'],
            'onset ph/f' => ['ibuprofen', 'i bu prô phen'],
            'onset gi/d' => ['collagen', 'cô la gien'],
            'số đọc thành chữ' => ['omega 3', 'ô mê ga ba'],
        ];
    }

    /** Cách đọc lệch vùng miền phải được nhận. */
    #[DataProvider('regionalPairs')]
    public function test_regional_pronunciation_is_accepted(string $catalog, string $spoken): void
    {
        $this->assertTrue(
            VoicePhonetics::within(VoicePhonetics::key($catalog), VoicePhonetics::key($spoken)),
            "\"{$spoken}\" phải khớp \"{$catalog}\""
        );
    }

    /**
     * Hai sản phẩm khác nhau không bao giờ được gộp. Danh sách gồm những tên
     * chỉ khác nhau một chữ, vốn là chỗ mọi ngưỡng Levenshtein đơn đã sai.
     */
    public function test_distinct_products_never_merge(): void
    {
        $names = [
            'paracetamol', 'panadol', 'ibuprofen', 'amoxicillin',
            'vitamin c', 'vitamin d', 'vitamin e', 'vitamin b1', 'vitamin b6',
            'canxi', 'collagen', 'omega 3', 'men vi sinh', 'khau trang',
            'kem chong nang', 'berberin', 'smecta', 'oresol', 'efferalgan',
            'hapacol', 'decolgen', 'tiffy', 'strepsils', 'dorithricin',
            'betadine', 'povidine',
        ];

        foreach ($names as $i => $left) {
            foreach (array_slice($names, $i + 1) as $right) {
                $this->assertFalse(
                    VoicePhonetics::within(VoicePhonetics::key($left), VoicePhonetics::key($right)),
                    "\"{$left}\" và \"{$right}\" là hai sản phẩm khác nhau"
                );
            }
        }
    }

    /** Âm tiết một-hai ký tự mang toàn bộ nghĩa phân biệt, nên nó phải khít. */
    public function test_atomic_syllable_must_match_exactly(): void
    {
        $this->assertFalse(VoicePhonetics::within('DitamiN K', 'DitamiN D'));
        $this->assertTrue(VoicePhonetics::within('DitamiN K', 'DitamiN K'));
    }

    /**
     * levenshtein() của PHP đếm byte. Khoá chỉ chứa ASCII nên byte ≡ ký tự;
     * một ký tự đa byte lọt vào khoá sẽ làm mọi ngưỡng lệch âm thầm.
     */
    public function test_key_is_ascii_only(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9 ]*$/',
            VoicePhonetics::key('Thuốc hạ sốt 500mg — trẻ em!')
        );
    }

    /** Hoa/thường là thông tin: "thuốc" và "thut" là hai âm khác nhau. */
    public function test_case_carries_meaning(): void
    {
        $this->assertSame('thUT', VoicePhonetics::key('thuoc'));
        $this->assertSame('thuT', VoicePhonetics::key('thut'));
        $this->assertNotSame(VoicePhonetics::key('thuoc'), VoicePhonetics::key('thut'));
    }

    /** Nguyên âm đôi phải sống sót qua bước xử lý âm cuối. */
    public function test_rime_survives_coda_replacement(): void
    {
        $this->assertStringContainsString('U', VoicePhonetics::key('thuoc'));
    }

    public function test_block_key_groups_regional_variants(): void
    {
        foreach (self::regionalPairs() as $label => [$catalog, $spoken]) {
            $this->assertSame(
                VoicePhonetics::blockKey(VoicePhonetics::key($catalog)),
                VoicePhonetics::blockKey(VoicePhonetics::key($spoken)),
                "block_key phải trùng cho: {$label}"
            );
        }
    }

    /** Tên sản phẩm đầy đủ không phải ứng viên khớp gần của một câu nói ngắn. */
    public function test_block_key_is_null_for_long_keys(): void
    {
        $long = 'Thuốc Silymax-F Hưng Việt điều trị viêm gan do virus, xơ gan, gan nhiễm mỡ';

        $this->assertNull(VoicePhonetics::blockKey(VoicePhonetics::key($long)));
        $this->assertSame('th', VoicePhonetics::blockKey(VoicePhonetics::key('thuoc ha sot')));
    }

    public function test_empty_and_odd_input_does_not_throw(): void
    {
        $this->assertSame('', VoicePhonetics::key(''));
        $this->assertSame('', VoicePhonetics::key('!!! ??? ---'));
        $this->assertSame('500', VoicePhonetics::key('500'));
        $this->assertFalse(VoicePhonetics::within('', ''));
        $this->assertNull(VoicePhonetics::blockKey(''));
    }

    /**
     * "bơ bơ rin" cho "berberin" cố ý trượt: nó xa quá mức một bảng gộp âm
     * chịu được, và tầng AI tồn tại đúng cho những trường hợp đó.
     */
    public function test_far_pronunciation_is_left_to_the_ai_tier(): void
    {
        $this->assertFalse(
            VoicePhonetics::within(VoicePhonetics::key('berberin'), VoicePhonetics::key('bơ bơ rin'))
        );
    }
}
