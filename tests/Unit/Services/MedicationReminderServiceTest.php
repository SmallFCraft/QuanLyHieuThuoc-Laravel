<?php

namespace Tests\Unit\Services;

use App\Models\Category;
use App\Models\MedicationReminder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\MedicationReminderService;
use Database\Seeders\MedicalKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicationReminderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected MedicationReminderService $service;
    protected Category $category;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Thuốc giảm đau - Hạ sốt',
            'description' => 'Giảm đau, hạ sốt',
        ]);

        $this->seed(MedicalKnowledgeSeeder::class);

        $this->service = app(MedicationReminderService::class);

        $this->user = User::factory()->create([
            'role' => 'User',
            'status' => 'active',
            'birth_date' => now()->subYears(34)->toDateString(),
        ]);
    }

    // --- parseTimesPerDay ---

    #[DataProvider('usageTextProvider')]
    public function test_times_per_day_is_parsed_from_real_usage_text(?string $usage, int $expected): void
    {
        $this->assertSame($expected, $this->service->parseTimesPerDay($usage));
    }

    public static function usageTextProvider(): array
    {
        return [
            'once daily' => ['Phòng ngừa đột quỵ, nhồi máu cơ tim. Uống 1 viên/ngày', 1],
            'range of times per day' => ['Giảm đau, chống viêm khớp. Uống 1 viên x 2-3 lần/ngày sau ăn', 3],
            'interval in hours' => ['Giảm đau, hạ sốt. Uống 1-2 viên mỗi 4-6 giờ, không quá 8 viên/ngày', 3],
            'fixed times per day' => ['Kháng sinh điều trị nhiễm khuẩn. Uống 1 viên x 3 lần/ngày', 3],
            'four times per day' => ['Kháng sinh nhóm cephalosporin thế hệ 1. Uống 1 viên x 4 lần/ngày', 4],
            'one to two times' => ['Giảm đau viêm khớp. Uống 1 viên x 1-2 lần/ngày', 2],
            'six to eight hours' => ['Giảm đau, chống viêm. Uống 1 viên mỗi 6-8 giờ sau ăn', 2],
            'liquid per day' => ['Điều trị táo bón. Uống 15-30ml/ngày', 1],
            'bedtime only' => ['Thuốc nhuận tràng. Uống 1-2 viên trước khi đi ngủ', 1],
            'as needed narrative' => ['Điều trị tiêu chảy cấp. Uống 2 viên lần đầu, sau đó 1 viên sau mỗi lần đi ngoài', 1],
            'null' => [null, 1],
            'empty' => ['', 1],
            'unparseable' => ['Dùng theo chỉ định của bác sĩ', 1],
        ];
    }

    // --- createFromOrder ---

    public function test_create_from_order_makes_one_reminder_per_line(): void
    {
        $order = $this->orderWithProducts(3);

        $created = $this->service->createFromOrder($order);

        $this->assertSame(3, $created);
        $this->assertSame(3, MedicationReminder::where('MaDH', $order->MaDH)->count());
    }

    public function test_create_from_order_is_idempotent(): void
    {
        $order = $this->orderWithProducts(2);

        $this->service->createFromOrder($order);
        $this->service->createFromOrder($order);

        $this->assertSame(2, MedicationReminder::where('MaDH', $order->MaDH)->count());
    }

    public function test_first_reminder_falls_inside_waking_hours(): void
    {
        // Pinned to the middle of the night: the first slot must still land inside
        // 08:00–20:00 rather than waking the customer up.
        $this->travelTo(now()->startOfDay()->setTime(3, 0));

        $order = $this->orderWithProducts(1);

        $this->service->createFromOrder($order);
        $reminder = MedicationReminder::where('MaDH', $order->MaDH)->firstOrFail();

        $this->assertNotNull($reminder->next_remind_at);
        $this->assertGreaterThanOrEqual(8, $reminder->next_remind_at->hour);
        $this->assertLessThan(20, $reminder->next_remind_at->hour);
    }

    public function test_reminder_copies_usage_text_and_frequency_from_the_product(): void
    {
        $product = $this->product('Uống 1 viên x 3 lần/ngày');
        $order = $this->order();
        OrderItem::create([
            'MaDH' => $order->MaDH,
            'MaSP' => $product->MaSP,
            'quantity' => 9,
            'unit_price' => 10000,
            'batch_number' => 'B-1',
        ]);

        $this->service->createFromOrder($order->fresh());
        $reminder = MedicationReminder::where('MaSP', $product->MaSP)->firstOrFail();

        $this->assertSame('Uống 1 viên x 3 lần/ngày', $reminder->dosage_text);
        $this->assertSame(3, $reminder->times_per_day);
        $this->assertSame(3, $reminder->duration_days);
    }

    // --- advanceSchedule ---

    public function test_schedule_completes_once_the_course_is_over(): void
    {
        $reminder = $this->reminder([
            'times_per_day' => 1,
            'duration_days' => 1,
            'start_date' => now()->subDays(5)->toDateString(),
            'next_remind_at' => now()->subDays(1)->setTime(10, 0),
        ]);

        $reminder->advanceSchedule();

        $this->assertSame('completed', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->next_remind_at);
    }

    public function test_schedule_stays_inside_waking_hours_when_it_rolls_over(): void
    {
        $reminder = $this->reminder([
            'times_per_day' => 1,
            'duration_days' => 30,
            'start_date' => now()->toDateString(),
            'next_remind_at' => now()->setTime(19, 0),
        ]);

        $reminder->advanceSchedule();
        $next = $reminder->fresh()->next_remind_at;

        $this->assertNotNull($next);
        $this->assertGreaterThanOrEqual(8, $next->hour);
        $this->assertLessThan(20, $next->hour);
    }

    // --- dispatchDue ---

    public function test_dispatch_due_only_touches_active_and_due_reminders(): void
    {
        Mail::fake();

        $due = $this->reminder(['next_remind_at' => now()->subMinutes(5)]);
        $future = $this->reminder(['next_remind_at' => now()->addHours(3)]);
        $cancelled = $this->reminder([
            'next_remind_at' => now()->subMinutes(5),
            'status' => 'cancelled',
        ]);

        $sent = $this->service->dispatchDue();

        $this->assertSame(1, $sent);
        Mail::assertQueued(\App\Mail\MedicationReminderMail::class, 1);
        $this->assertNotSame($due->next_remind_at, $due->fresh()->next_remind_at);
        $this->assertEquals(
            $future->next_remind_at->timestamp,
            $future->fresh()->next_remind_at->timestamp
        );
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_dispatch_due_sends_nothing_when_no_reminder_is_due(): void
    {
        Mail::fake();
        $this->reminder(['next_remind_at' => now()->addDay()]);

        $this->assertSame(0, $this->service->dispatchDue());
        Mail::assertNothingQueued();
    }

    // --- cancelForUser ---

    public function test_cancel_for_user_only_cancels_that_users_reminder(): void
    {
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);
        $product = $this->product('Uống 1 viên/ngày');

        $mine = $this->reminder(['MaSP' => $product->MaSP]);
        $theirs = $this->reminder(['MaSP' => $product->MaSP, 'MaKH' => $other->MaKH]);

        $this->service->cancelForUser((int) $this->user->MaKH, (int) $product->MaSP);

        $this->assertSame('cancelled', $mine->fresh()->status);
        $this->assertSame('active', $theirs->fresh()->status);
    }

    // --- Console command ---

    public function test_console_command_dispatches_due_reminders(): void
    {
        Mail::fake();
        $this->reminder(['next_remind_at' => now()->subMinutes(5)]);

        $this->artisan('reminders:dispatch')->assertSuccessful();

        Mail::assertQueued(\App\Mail\MedicationReminderMail::class, 1);
    }

    // --- Ownership through the HTTP layer ---

    public function test_a_customer_only_sees_their_own_active_reminders(): void
    {
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);
        $mineProduct = $this->product('Uống 1 viên/ngày');
        $theirsProduct = $this->product('Uống 1 viên x 2 lần/ngày');

        $this->reminder(['MaSP' => $mineProduct->MaSP]);
        $this->reminder(['MaSP' => $theirsProduct->MaSP, 'MaKH' => $other->MaKH]);

        // Neo vào aria-label của nút huỷ, không phải tên sản phẩm trần: panel tìm kiếm
        // nhúng sản phẩm xem nhiều vào mọi trang storefront, nên quét cả trang thì tên
        // nào cũng có thể xuất hiện ngoài bảng nhắc thuốc.
        $cancelLabel = fn (Product $product) => __('messages.reminder.btn_cancel_aria', ['name' => $product->name]);

        $this->actingAs($this->user)
            ->get(route('reminders.index'))
            ->assertOk()
            ->assertSee($cancelLabel($mineProduct))
            ->assertDontSee($cancelLabel($theirsProduct));
    }

    public function test_a_customer_cannot_cancel_someone_elses_reminder(): void
    {
        $other = User::factory()->create(['role' => 'User', 'status' => 'active']);
        $theirs = $this->reminder(['MaKH' => $other->MaKH]);

        $this->actingAs($this->user)
            ->post(route('reminders.cancel', $theirs->MaNN))
            ->assertRedirect(route('reminders.index'));

        $this->assertSame('active', $theirs->fresh()->status);
    }

    public function test_a_customer_can_cancel_their_own_reminder(): void
    {
        $mine = $this->reminder();

        $this->actingAs($this->user)
            ->post(route('reminders.cancel', $mine->MaNN))
            ->assertRedirect(route('reminders.index'));

        $this->assertSame('cancelled', $mine->fresh()->status);
    }

    // --- helpers ---

    private function product(string $usage): Product
    {
        return Product::factory()->create([
            'MaDM' => $this->category->MaDM,
            'usage' => $usage,
        ]);
    }

    private function order(?User $user = null): Order
    {
        return Order::create([
            'MaKH' => ($user ?? $this->user)->MaKH,
            'total_amount' => 100000,
            'status' => 'Completed',
            'shipping_address' => '12 Nguyễn Huệ, Quận 1',
            'shipping_phone' => '0901234567',
        ]);
    }

    private function orderWithProducts(int $lines): Order
    {
        $order = $this->order();
        $usages = [
            'Uống 1 viên/ngày',
            'Uống 1 viên x 2-3 lần/ngày sau ăn',
            'Uống 1-2 viên mỗi 4-6 giờ, không quá 8 viên/ngày',
        ];

        for ($i = 0; $i < $lines; $i++) {
            $product = $this->product($usages[$i % count($usages)]);
            OrderItem::create([
                'MaDH' => $order->MaDH,
                'MaSP' => $product->MaSP,
                'quantity' => 6,
                'unit_price' => 10000,
                'batch_number' => 'B-' . $i,
            ]);
        }

        return $order->fresh();
    }

    private function reminder(array $attributes = []): MedicationReminder
    {
        return MedicationReminder::create(array_merge([
            'MaKH' => $this->user->MaKH,
            'MaDH' => null,
            'MaSP' => $this->product('Uống 1 viên x 2 lần/ngày')->MaSP,
            'dosage_text' => 'Uống 1 viên x 2 lần/ngày',
            'times_per_day' => 2,
            'duration_days' => 5,
            'start_date' => now()->toDateString(),
            'next_remind_at' => now(),
            'status' => 'active',
        ], $attributes));
    }
}
