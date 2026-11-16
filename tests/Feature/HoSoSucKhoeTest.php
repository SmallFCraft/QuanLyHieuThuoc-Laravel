<?php

namespace Tests\Feature;

use App\Models\NhatKyKiemToan;
use App\Models\PhienSangLoc;
use App\Models\NguoiDung;
use App\Models\HoSoSucKhoe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HoSoSucKhoeTest extends TestCase
{
    use RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'allergies' => ['Paracetamol', 'Penicillin'],
            'chronic_conditions' => ['loét dạ dày'],
            'current_medications' => ['Amoxicillin 500mg'],
            'is_pregnant' => '0',
            'is_breastfeeding' => '0',
            'weight_kg' => '62.5',
            'notes' => 'Từng bị nổi mề đay khi dùng kháng sinh.',
            'consent' => '1',
        ], $overrides);
    }

    public function test_khach_vo_danh_khong_cap_nhat_duoc_ho_so_suc_khoe(): void
    {
        $response = $this->put(route('ho-so.suc-khoe.update'), $this->payload());

        $response->assertRedirect(route('login'));
        $this->assertDatabaseCount('ho_so_suc_khoes', 0);
    }

    public function test_nguoi_dung_tao_duoc_ho_so_suc_khoe(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload());

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('ho-so.edit') . '#health-profile');

        $hoSo = HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->firstOrFail();

        $this->assertSame(['Paracetamol', 'Penicillin'], $hoSo->allergies);
        $this->assertSame(['loét dạ dày'], $hoSo->chronic_conditions);
        $this->assertSame(['Amoxicillin 500mg'], $hoSo->current_medications);
        $this->assertFalse($hoSo->is_pregnant);
        $this->assertFalse($hoSo->is_breastfeeding);
        $this->assertSame(62.5, $hoSo->weight_kg);
        $this->assertSame('Từng bị nổi mề đay khi dùng kháng sinh.', $hoSo->notes);
    }

    public function test_ho_so_hien_co_duoc_cap_nhat_khong_bi_nhan_doi(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload());
        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload([
            'allergies' => ['Aspirin'],
        ]));

        $this->assertDatabaseCount('ho_so_suc_khoes', 1);
        $this->assertSame(['Aspirin'], HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->first()->allergies);
    }

    public function test_o_dong_y_kiem_soat_thoi_diem_dong_y(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload());
        $this->assertNotNull(HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->first()->consented_at);

        $withoutConsent = $this->payload();
        unset($withoutConsent['consent']);

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $withoutConsent);
        $this->assertNull(HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->first()->consented_at);
    }

    public function test_ho_so_chua_dong_y_bi_loai_khoi_pham_vi_da_dong_y(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $withoutConsent = $this->payload();
        unset($withoutConsent['consent']);

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $withoutConsent);

        $this->assertNull(HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->consented()->first());
    }

    public function test_du_lieu_suc_khoe_duoc_ma_hoa_khi_luu(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload());

        $row = DB::table('ho_so_suc_khoes')->where('ma_nd', $nguoiDung->ma_nd)->first();

        $this->assertStringNotContainsString('Paracetamol', $row->allergies);
        $this->assertStringNotContainsString('Penicillin', $row->allergies);
        $this->assertStringNotContainsString('dạ dày', $row->chronic_conditions);
        $this->assertStringNotContainsString('Amoxicillin', $row->current_medications);
        $this->assertStringNotContainsString('mề đay', $row->notes);
    }

    public function test_nguoi_dung_khong_sua_xoa_duoc_ho_so_cua_nguoi_khac(): void
    {
        $userA = NguoiDung::factory()->create();
        $userB = NguoiDung::factory()->create();

        $this->actingAs($userB)->put(route('ho-so.suc-khoe.update'), $this->payload([
            'allergies' => ['Ibuprofen'],
        ]));

        $this->actingAs($userA)->put(route('ho-so.suc-khoe.update'), $this->payload([
            'allergies' => ['Aspirin'],
        ]));

        $this->assertSame(['Ibuprofen'], HoSoSucKhoe::where('ma_nd', $userB->ma_nd)->first()->allergies);
        $this->assertSame(['Aspirin'], HoSoSucKhoe::where('ma_nd', $userA->ma_nd)->first()->allergies);

        $this->actingAs($userA)->delete(route('ho-so.suc-khoe.destroy'));

        $this->assertDatabaseMissing('ho_so_suc_khoes', ['ma_nd' => $userA->ma_nd]);
        $this->assertDatabaseHas('ho_so_suc_khoes', ['ma_nd' => $userB->ma_nd]);
    }

    public function test_thuat_ngu_da_biet_gop_moi_danh_sach_thanh_chu_thuong(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload([
            'allergies' => ['Paracetamol', 'Paracetamol'],
            'chronic_conditions' => ['Loét Dạ Dày'],
            'current_medications' => ['Amoxicillin'],
        ]));

        $terms = HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->first()->knownTerms();

        $this->assertSame(['paracetamol', 'loét dạ dày', 'amoxicillin'], $terms);
    }

    public function test_ho_so_xoa_duoc_va_duoc_ghi_nhat_ky(): void
    {
        $nguoiDung = NguoiDung::factory()->create();
        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload());

        $response = $this->actingAs($nguoiDung)->delete(route('ho-so.suc-khoe.destroy'));

        $response->assertRedirect(route('ho-so.edit') . '#health-profile');
        $this->assertDatabaseCount('ho_so_suc_khoes', 0);
        $this->assertDatabaseHas('nhat_ky_kiem_toans', [
            'ma_nd' => $nguoiDung->ma_nd,
            'action' => 'health_profile_deleted',
        ]);
    }

    public function test_cap_nhat_duoc_ghi_nhat_ky_khong_kem_noi_dung_suc_khoe_tho(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload());

        $log = NhatKyKiemToan::where('action', 'health_profile_updated')->firstOrFail();
        $serialized = json_encode($log->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Paracetamol', $serialized);
        $this->assertStringNotContainsString('Penicillin', $serialized);
        $this->assertStringNotContainsString('dạ dày', $serialized);
        $this->assertStringNotContainsString('Amoxicillin', $serialized);
        $this->assertStringNotContainsString('mề đay', $serialized);
        $this->assertSame(2, $log->new_values['item_counts']['allergies']);
        $this->assertContains('allergies', $log->new_values['changed_fields']);
    }

    public function test_hon_ba_muoi_muc_bi_tu_choi(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload([
            'allergies' => array_map(fn (int $i) => "Chat so {$i}", range(1, 31)),
        ]));

        $response->assertSessionHasErrors('allergies');
        $this->assertDatabaseCount('ho_so_suc_khoes', 0);
    }

    public function test_muc_dai_hon_120_ky_tu_bi_tu_choi(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $response = $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload([
            'allergies' => [str_repeat('a', 121)],
        ]));

        $response->assertSessionHasErrors('allergies.0');
        $this->assertDatabaseCount('ho_so_suc_khoes', 0);
    }

    public function test_muc_rong_bi_bo_va_gia_tri_duoc_cat_khoang_trang(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload([
            'allergies' => ['  Aspirin  ', '', '   '],
        ]));

        $this->assertSame(['Aspirin'], HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->first()->allergies);
    }

    public function test_can_nang_ngoai_pham_vi_bi_tu_choi(): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)
            ->put(route('ho-so.suc-khoe.update'), $this->payload(['weight_kg' => '501']))
            ->assertSessionHasErrors('weight_kg');

        $this->actingAs($nguoiDung)
            ->put(route('ho-so.suc-khoe.update'), $this->payload(['weight_kg' => '0.5']))
            ->assertSessionHasErrors('weight_kg');
    }

    /**
     * A literal "Không" in the allergy field made knownTerms() non-empty, which made the
     * triage skip wizard step 4 entirely — so it never asked about chronic disease,
     * allergy, pregnancy or breastfeeding, and then a follow-up round asked it instead.
     *
     * @return array<string, array{0: string}>
     */
    public static function negationTermProvider(): array
    {
        return [
            'khong' => ['Không'],
            'khong co' => ['không có'],
            'khong co gi' => ['Không có gì'],
            'ko' => ['ko'],
            'chua co' => ['chưa có'],
            'english none' => ['None'],
            'n slash a' => ['N/A'],
            'dash' => ['-'],
            'zero' => ['0'],
        ];
    }

    #[DataProvider('negationTermProvider')]
    public function test_di_ung_chi_co_phu_dinh_khong_duoc_luu(string $term): void
    {
        $nguoiDung = NguoiDung::factory()->create();

        $this->actingAs($nguoiDung)->put(route('ho-so.suc-khoe.update'), $this->payload([
            'allergies' => [$term],
        ]));

        $this->assertSame([], HoSoSucKhoe::where('ma_nd', $nguoiDung->ma_nd)->first()->allergies, $term);
    }

    public function test_thuat_ngu_da_biet_bo_gia_tri_phu_dinh_giu_cho(): void
    {
        // The reader is cleaned too, not just the writer: rows written before the request
        // filter existed still carry the junk.
        $nguoiDung = NguoiDung::factory()->create();

        $hoSo = HoSoSucKhoe::create(['ma_nd' => $nguoiDung->ma_nd,
            'allergies' => ['Không', 'Paracetamol'],
            'consented_at' => now(),
        ]);

        $this->assertSame(['paracetamol'], $hoSo->knownTerms());
    }

    public function test_ho_so_chi_co_phu_dinh_khong_bo_qua_buoc_tien_su(): void
    {
        $nguoiDung = NguoiDung::factory()->create(['vai_tro' => 'User', 'trang_thai' => 'active']);

        HoSoSucKhoe::create(['ma_nd' => $nguoiDung->ma_nd,
            'allergies' => ['Không'],
            'consented_at' => now(),
        ]);

        $this->actingAs($nguoiDung)->get(route('sang-loc.kiem-tra'));
        $phien = PhienSangLoc::where('ma_nd', $nguoiDung->ma_nd)->inProgress()->latest('ma_sl')->firstOrFail();

        foreach ([1 => 'đau đầu', 2 => 'hôm nay', 3 => 'chưa dùng gì'] as $step => $answer) {
            $this->actingAs($nguoiDung)->post(route('sang-loc.tra-loi'), [
                'triage_session_id' => $phien->ma_sl,
                'step' => $step,
                'answer' => $answer,
            ]);
        }

        // Step 4 must be the next question, not skipped as "already known".
        $this->actingAs($nguoiDung)
            ->get(route('sang-loc.kiem-tra'))
            ->assertOk()
            ->assertSee('Anh/chị có bệnh nền, dị ứng thuốc, hoặc đang mang thai / cho con bú không ạ?', false);
    }
}
