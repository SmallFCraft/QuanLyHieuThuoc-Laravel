<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Favorite;
use App\Models\MedicationReminder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserHealthProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trang tài khoản sau khi gộp style và thêm tab tổng quan.
 *
 * Trước đây mỗi partial mang một khối <style> riêng nên cùng một ô input có ba bán
 * kính bo góc tuỳ theo tab; và không có chỗ nào cho người dùng thấy tổng quan.
 */
class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Nguyễn Văn An']);
    }

    public function test_the_page_opens_on_the_overview_tab(): void
    {
        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('id="overview"', false)
            ->assertSee('show active', false)
            ->assertSee(__('messages.profile.overview_title'));
    }

    public function test_every_tab_is_rendered(): void
    {
        $response = $this->actingAs($this->user)->get(route('profile.edit'))->assertOk();

        foreach (['overview', 'profile-info', 'orders', 'favorites', 'security', 'health-profile', 'settings'] as $tab) {
            $response->assertSee('id="' . $tab . '"', false);
        }
    }

    public function test_the_sidebar_is_a_tablist_so_bootstrap_can_switch_tabs(): void
    {
        // Bootstrap Tab tìm container qua closest('.nav, .list-group, [role="tablist"]')
        // và im lặng không làm gì nếu không thấy — tab sẽ không đổi được.
        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('class="profile-nav" role="tablist"', false)
            ->assertSee('class="profile-tabbar" role="tablist"', false);
    }

    public function test_the_overview_counts_orders_by_status(): void
    {
        foreach (['Pending', 'Pending', 'Delivering', 'Completed'] as $status) {
            Order::create([
                'MaKH' => $this->user->MaKH,
                'total_amount' => 120000,
                'status' => $status,
                'shipping_address' => '1 Lê Văn Hiến, Đà Nẵng',
            ]);
        }

        $counts = $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->viewData('orderCounts');

        $this->assertSame(2, $counts['Pending']);
        $this->assertSame(1, $counts['Delivering']);
        $this->assertSame(1, $counts['Completed']);
        $this->assertSame(4, $counts->sum());
    }

    public function test_the_overview_lists_the_three_most_recent_orders(): void
    {
        foreach (range(1, 5) as $i) {
            $order = Order::create([
                'MaKH' => $this->user->MaKH,
                'total_amount' => 10000 * $i,
                'status' => 'Completed',
                'shipping_address' => "{$i} Lê Văn Hiến, Đà Nẵng",
            ]);

            // created_at không nằm trong $fillable nên Order::create() bỏ qua nó và
            // cả 5 đơn dùng cùng một mốc thời gian.
            $order->forceFill(['created_at' => now()->subDays(10 - $i)])->save();
        }

        $recent = $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->viewData('recentOrders');

        $this->assertCount(3, $recent);
        // Mới nhất trước: đơn thứ 5 tạo gần nhất.
        $this->assertSame('5 Lê Văn Hiến, Đà Nẵng', $recent->first()->shipping_address);
    }

    public function test_the_overview_lists_only_this_users_active_reminders(): void
    {
        $category = Category::create(['name' => 'Thuốc hô hấp']);
        $product = Product::create([
            'MaDM' => $category->MaDM,
            'name' => 'Thuốc ho Prospan',
            'price' => 85000,
            'expiration_date' => now()->addYears(2),
            'stock_quantity' => 10,
            'reorder_point' => 4,
            'batch_number' => 'LC-prospan',
        ]);
        $other = User::factory()->create();

        MedicationReminder::create([
            'MaKH' => $this->user->MaKH,
            'MaSP' => $product->MaSP,
            'dosage_text' => '1 viên sau ăn',
            'times_per_day' => 2,
            'duration_days' => 5,
            'start_date' => now()->toDateString(),
            'next_remind_at' => now()->addHours(3),
            'status' => 'active',
        ]);
        MedicationReminder::create([
            'MaKH' => $other->MaKH,
            'MaSP' => $product->MaSP,
            'dosage_text' => '2 viên sau ăn',
            'times_per_day' => 2,
            'duration_days' => 5,
            'start_date' => now()->toDateString(),
            'next_remind_at' => now()->addHours(1),
            'status' => 'active',
        ]);

        $reminders = $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->viewData('upcomingReminders');

        // Lịch nhắc của người khác không được lọt vào: MaKH đi trong URL email.
        $this->assertCount(1, $reminders);
        $this->assertSame((int) $this->user->MaKH, (int) $reminders->first()->MaKH);
    }

    public function test_the_health_progress_is_hidden_without_consent(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['penicillin'],
            'chronic_conditions' => ['tăng huyết áp'],
            'weight_kg' => 62,
            'consented_at' => null,
        ]);

        // Không đồng ý thì hồ sơ không được dùng cho bất kỳ mục đích nào, kể cả
        // hiển thị mức hoàn thiện — chỉ thấy lời mời khai báo.
        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('messages.profile.overview_health_empty'))
            ->assertDontSee(__('messages.profile.overview_health_complete', ['percent' => 75]));
    }

    public function test_the_health_progress_shows_once_consent_is_given(): void
    {
        UserHealthProfile::create([
            'MaKH' => $this->user->MaKH,
            'allergies' => ['penicillin'],
            'chronic_conditions' => ['tăng huyết áp'],
            'current_medications' => ['amlodipin'],
            'weight_kg' => 62,
            'consented_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('messages.profile.overview_health_complete', ['percent' => 100]))
            ->assertDontSee(__('messages.profile.overview_health_empty'));
    }

    public function test_the_notification_switches_are_marked_unavailable(): void
    {
        // Chúng chưa có chỗ lưu; để chúng bật/tắt được là nói dối người dùng.
        //
        // `disabled` phải nằm trong đúng thẻ input: chatbot widget cũng có chuỗi đó chục
        // lần, nên assertSee('disabled') trần vẫn xanh sau khi bỏ hẳn nó khỏi switch.
        $html = $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('messages.profile.notifications_soon'))
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="orderNotif"[^>]*\sdisabled/s',
            $html,
            'Switch "thông báo đơn hàng" phải là disabled.'
        );
    }

    public function test_the_overview_previews_three_favourite_products(): void
    {
        $category = Category::create(['name' => 'Thuốc tiêu hoá & gan mật']);

        foreach (range(1, 5) as $i) {
            $product = Product::create([
                'MaDM' => $category->MaDM,
                'name' => "Thuốc số {$i} điều trị viêm gan do virus, xơ gan",
                'price' => 100000 + $i,
                'expiration_date' => now()->addYears(2),
                'stock_quantity' => 10,
                'reorder_point' => 4,
                'batch_number' => "LC-fav-{$i}",
            ]);

            Favorite::create(['MaKH' => $this->user->MaKH, 'MaSP' => $product->MaSP]);
        }

        $response = $this->actingAs($this->user)->get(route('profile.edit'))->assertOk();

        // Ba dòng, kèm tên + danh mục + giá — không còn là một thẻ trống với một nút.
        $this->assertSame(
            3,
            substr_count($response->getContent(), 'profile-overview-thumb'),
            'Thẻ yêu thích ở tổng quan phải hiện đúng 3 sản phẩm.'
        );

        $response->assertSee('Thuốc tiêu hoá &amp; gan mật', false)
            ->assertSee(__('messages.profile.overview_favorites_more', ['count' => 2]))
            ->assertSee(route('products.show', 1), false);
    }

    public function test_the_overview_favourites_fall_back_to_a_call_to_action(): void
    {
        $this->actingAs($this->user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(__('messages.profile.favorites_empty_desc'))
            ->assertSee(__('messages.profile.shop_now'))
            ->assertDontSee('profile-overview-thumb', false);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('login'));
    }
}
