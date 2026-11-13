<?php

namespace Tests\Feature;

use App\Models\NguoiDung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ảnh đại diện người dùng.
 *
 * Trước đây header gọi ui-avatars.com trên mỗi lần tải trang — một phụ thuộc bên
 * ngoài chỉ để vẽ một chữ cái, và tên người dùng bị gửi ra ngoài theo query string.
 */
class AnhDaiDienHoSoTest extends TestCase
{
    use RefreshDatabase;

    private NguoiDung $nguoiDung;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->nguoiDung = NguoiDung::factory()->create(['ho_ten' => 'Nguyễn Văn An']);
    }

    public function test_nguoi_dung_tai_anh_dai_dien_duoc(): void
    {
        $this->actingAs($this->nguoiDung)
            ->post(route('ho-so.anh-dai-dien.update'), [
                'avatar' => UploadedFile::fake()->image('me.jpg', 1200, 1200),
            ])
            ->assertRedirect(route('ho-so.edit'))
            ->assertSessionHas('status', 'avatar-updated');

        $this->nguoiDung->refresh();

        $this->assertNotNull($this->nguoiDung->anh_dai_dien);
        Storage::disk('public')->assertExists($this->nguoiDung->anh_dai_dien);

        // Chuẩn hoá về JPEG dù người dùng gửi PNG: đường dẫn luôn kết thúc bằng .jpg.
        $this->assertStringEndsWith('.jpg', $this->nguoiDung->anh_dai_dien);
        $this->assertStringStartsWith('avatars/', $this->nguoiDung->anh_dai_dien);
    }

    public function test_anh_dai_dien_da_luu_duoc_thu_nho(): void
    {
        $this->actingAs($this->nguoiDung)->post(route('ho-so.anh-dai-dien.update'), [
            'avatar' => UploadedFile::fake()->image('huge.jpg', 3000, 2000),
        ])->assertRedirect();

        $this->nguoiDung->refresh();

        $bytes = Storage::disk('public')->get($this->nguoiDung->anh_dai_dien);
        [$width, $height] = getimagesizefromstring($bytes);

        $max = (int) config('uploads.avatars.max_dimension');

        $this->assertLessThanOrEqual($max, $width);
        $this->assertLessThanOrEqual($max, $height);
        // Tỉ lệ giữ nguyên: 3000x2000 thu về 512x341, không bị bóp thành hình vuông.
        $this->assertSame($max, $width);
    }

    public function test_tai_lai_anh_xoa_tep_truoc(): void
    {
        $this->actingAs($this->nguoiDung)->post(route('ho-so.anh-dai-dien.update'), [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ]);

        $this->nguoiDung->refresh();
        $first = $this->nguoiDung->anh_dai_dien;

        $this->actingAs($this->nguoiDung)->post(route('ho-so.anh-dai-dien.update'), [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ]);

        $this->nguoiDung->refresh();

        $this->assertNotSame($first, $this->nguoiDung->anh_dai_dien);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($this->nguoiDung->anh_dai_dien);
    }

    public function test_anh_qua_lon_bi_tu_choi(): void
    {
        $maxKb = (int) config('uploads.images.max_size');

        $this->actingAs($this->nguoiDung)
            ->post(route('ho-so.anh-dai-dien.update'), [
                'avatar' => UploadedFile::fake()->image('big.jpg')->size($maxKb + 64),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($this->nguoiDung->fresh()->anh_dai_dien);
    }

    public function test_tep_khong_phai_anh_bi_tu_choi(): void
    {
        $this->actingAs($this->nguoiDung)
            ->post(route('ho-so.anh-dai-dien.update'), [
                'avatar' => UploadedFile::fake()->create('prescription.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($this->nguoiDung->fresh()->anh_dai_dien);
    }

    public function test_chatbot_va_anh_dai_dien_dung_chung_gioi_han_kich_thuoc(): void
    {
        // Hai ngưỡng lệch nhau nghĩa là client chặn ở một mức, server ở mức khác.
        $this->assertSame(
            (int) config('uploads.images.max_size'),
            (int) config('chatbot.images.max_size')
        );
    }

    public function test_nguoi_dung_xoa_anh_dai_dien_duoc(): void
    {
        $this->actingAs($this->nguoiDung)->post(route('ho-so.anh-dai-dien.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ]);

        $this->nguoiDung->refresh();
        $path = $this->nguoiDung->anh_dai_dien;

        $this->actingAs($this->nguoiDung)
            ->delete(route('ho-so.anh-dai-dien.destroy'))
            ->assertRedirect(route('ho-so.edit'))
            ->assertSessionHas('status', 'avatar-removed');

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($this->nguoiDung->fresh()->anh_dai_dien);
    }

    public function test_khach_vo_danh_khong_thao_tac_duoc_anh_dai_dien(): void
    {
        $this->post(route('ho-so.anh-dai-dien.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertRedirect(route('login'));

        $this->delete(route('ho-so.anh-dai-dien.destroy'))->assertRedirect(route('login'));
    }

    public function test_trang_dung_chu_cai_dau_ten_khi_khong_co_anh(): void
    {
        $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->assertSee('profile-avatar--initial', false)
            ->assertSee('>N<', false);
    }

    public function test_tep_bi_mat_dung_noi_dung_du_phong_thay_vi_anh_hong(): void
    {
        // Bản ghi còn trỏ tới ảnh đã bị xoá khỏi disk: một <img> hỏng xấu hơn chữ cái đầu.
        $this->nguoiDung->update(['anh_dai_dien' => 'avatars/deleted.jpg']);

        $this->assertNull($this->nguoiDung->fresh()->avatarUrl());

        $this->actingAs($this->nguoiDung)
            ->get(route('ho-so.edit'))
            ->assertOk()
            ->assertSee('profile-avatar--initial', false);
    }

    public function test_dau_trang_phuc_vu_anh_dai_dien_tu_ung_dung_nay(): void
    {
        $this->actingAs($this->nguoiDung)->post(route('ho-so.anh-dai-dien.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ]);

        $this->actingAs($this->nguoiDung->fresh())
            ->get('/')
            ->assertOk()
            ->assertDontSee('ui-avatars.com')
            ->assertSee('/storage/avatars/', false);
    }
}
