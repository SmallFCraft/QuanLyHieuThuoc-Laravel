<?php

namespace Tests\Feature;

use App\Models\User;
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
class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::factory()->create(['name' => 'Nguyễn Văn An']);
    }

    public function test_a_user_can_upload_an_avatar(): void
    {
        $this->actingAs($this->user)
            ->post(route('profile.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('me.jpg', 1200, 1200),
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'avatar-updated');

        $this->user->refresh();

        $this->assertNotNull($this->user->avatar);
        Storage::disk('public')->assertExists($this->user->avatar);

        // Chuẩn hoá về JPEG dù người dùng gửi PNG: đường dẫn luôn kết thúc bằng .jpg.
        $this->assertStringEndsWith('.jpg', $this->user->avatar);
        $this->assertStringStartsWith('avatars/', $this->user->avatar);
    }

    public function test_the_stored_avatar_is_scaled_down(): void
    {
        $this->actingAs($this->user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('huge.jpg', 3000, 2000),
        ])->assertRedirect();

        $this->user->refresh();

        $bytes = Storage::disk('public')->get($this->user->avatar);
        [$width, $height] = getimagesizefromstring($bytes);

        $max = (int) config('uploads.avatars.max_dimension');

        $this->assertLessThanOrEqual($max, $width);
        $this->assertLessThanOrEqual($max, $height);
        // Tỉ lệ giữ nguyên: 3000x2000 thu về 512x341, không bị bóp thành hình vuông.
        $this->assertSame($max, $width);
    }

    public function test_uploading_again_deletes_the_previous_file(): void
    {
        $this->actingAs($this->user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ]);

        $this->user->refresh();
        $first = $this->user->avatar;

        $this->actingAs($this->user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ]);

        $this->user->refresh();

        $this->assertNotSame($first, $this->user->avatar);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($this->user->avatar);
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        $maxKb = (int) config('uploads.images.max_size');

        $this->actingAs($this->user)
            ->post(route('profile.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('big.jpg')->size($maxKb + 64),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($this->user->fresh()->avatar);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post(route('profile.avatar.update'), [
                'avatar' => UploadedFile::fake()->create('prescription.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($this->user->fresh()->avatar);
    }

    public function test_the_chatbot_and_the_avatar_share_one_size_limit(): void
    {
        // Hai ngưỡng lệch nhau nghĩa là client chặn ở một mức, server ở mức khác.
        $this->assertSame(
            (int) config('uploads.images.max_size'),
            (int) config('chatbot.images.max_size')
        );
    }

    public function test_a_user_can_remove_their_avatar(): void
    {
        $this->actingAs($this->user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ]);

        $this->user->refresh();
        $path = $this->user->avatar;

        $this->actingAs($this->user)
            ->delete(route('profile.avatar.destroy'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'avatar-removed');

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($this->user->fresh()->avatar);
    }

    public function test_a_guest_cannot_touch_avatars(): void
    {
        $this->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertRedirect(route('login'));

        $this->delete(route('profile.avatar.destroy'))->assertRedirect(route('login'));
    }

    public function test_the_page_falls_back_to_the_name_initial(): void
    {
        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('profile-avatar--initial', false)
            ->assertSee('>N<', false);
    }

    public function test_a_missing_file_falls_back_instead_of_a_broken_image(): void
    {
        // Bản ghi còn trỏ tới ảnh đã bị xoá khỏi disk: một <img> hỏng xấu hơn chữ cái đầu.
        $this->user->update(['avatar' => 'avatars/deleted.jpg']);

        $this->assertNull($this->user->fresh()->avatarUrl());

        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('profile-avatar--initial', false);
    }

    public function test_the_header_serves_the_avatar_from_this_app(): void
    {
        $this->actingAs($this->user)->post(route('profile.avatar.update'), [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ]);

        $this->actingAs($this->user->fresh())
            ->get('/')
            ->assertOk()
            ->assertDontSee('ui-avatars.com')
            ->assertSee('/storage/avatars/', false);
    }
}
