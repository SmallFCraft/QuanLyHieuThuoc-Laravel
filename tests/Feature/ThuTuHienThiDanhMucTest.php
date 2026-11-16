<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\DonHang;
use App\Models\NguoiDung;
use App\Models\SanPham;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThuTuHienThiDanhMucTest extends TestCase
{
    use RefreshDatabase;

    // Route names come from the live route table, so this file compiles whichever
    // naming the branch currently ships.
    private const CATEGORY_INDEX = 'admin.categories.index';

    private function routeName(string $vietnamese, string $english): string
    {
        return app('router')->has($vietnamese) ? $vietnamese : $english;
    }

    private NguoiDung $admin;
    private NguoiDung $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = NguoiDung::factory()->create([
            'vai_tro' => 'Administrator',
            'trang_thai' => 'active',
        ]);
        $this->customer = NguoiDung::factory()->create([
            'vai_tro' => 'User',
            'trang_thai' => 'active',
        ]);
    }

    public function test_trang_danh_muc_admin_hien_so_luong_san_pham_khac_khong(): void
    {
        $category = DanhMuc::create(['ten' => 'Danh mục kiểm thử']);
        SanPham::create([
            'ma_dm' => $category->ma_dm,
            'ten' => 'Sản phẩm kiểm thử',
            'gia' => 25000,
            'ngay_het_han' => now()->addYear(),
            'so_luong_ton' => 5,
            'muc_canh_bao' => 1,
            'so_lo' => 'COUNT-001',
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route($this->routeName('admin.danh-muc.index', 'admin.categories.index')))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/<h5[^>]*>Danh mục kiểm thử<\/h5>.*?<div class="fw-bold text-success fs-5">1<\/div>/s', $html),
            'The category card must render its one product.'
        );
    }

    public function test_trang_don_hang_admin_hien_trang_thai_cho_xu_ly_tren_desktop_va_mobile(): void
    {
        $order = $this->pendingOrder();

        $html = $this->actingAs($this->admin)
            ->get(route($this->routeName('admin.don-hang.index', 'admin.orders.index')))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match(
                '/#' . $order->ma_dh . '.*?<span class="badge text-bg-warning">Chờ xử lý<\/span>/s',
                $html
            ),
            'The desktop order row must render Pending as Chờ xử lý.'
        );
        $this->assertTrue(
            str_contains($html, '<span class="badge text-bg-warning align-self-start">Chờ xử lý</span>'),
            'The mobile order card must render Pending as Chờ xử lý.'
        );
    }

    public function test_chi_tiet_don_hang_cho_xu_ly_hien_trang_thai_va_nut_huy(): void
    {
        $order = $this->pendingOrder();

        $html = $this->actingAs($this->admin)
            ->get(route($this->routeName('admin.don-hang.show', 'admin.orders.show'), $order->ma_dh))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            [true, true, true, true, false],
            [
                str_contains($html, __('messages.admin.orders.status_pending')),
                str_contains($html, '<option value="Delivering">Chuyển sang Đang giao</option>'),
                str_contains($html, '<option value="Cancelled">Hủy đơn hàng</option>'),
                str_contains($html, 'action="' . route($this->routeName('admin.don-hang.huy', 'admin.orders.cancel'), $order->ma_dh) . '"'),
                str_contains($html, '>Đã hủy</span>'),
            ],
            'Pending detail must show its status, both transitions and cancel form, not Cancelled.'
        );
    }

    public function test_lich_su_don_hang_ho_so_hien_trang_thai_cho_xu_ly_ca_hai_badge(): void
    {
        $order = $this->pendingOrder();

        $html = $this->actingAs($this->customer)
            ->get(route($this->routeName('ho-so.edit', 'profile.edit')))
            ->assertOk()
            ->getContent();

        $entry = substr($html, (int) strpos($html, 'id="order-' . $order->ma_dh . '"'));

        $this->assertStringContainsString('Chờ xử lý', $entry);
        $this->assertSame(
            2,
            substr_count($entry, 'badge-glass-warning rounded-pill px-2 py-1')
        );
    }

    private function pendingOrder(): DonHang
    {
        return DonHang::create([
            'ma_nd' => $this->customer->ma_nd,
            'tong_tien' => 120000,
            'trang_thai' => 'Pending',
            'dia_chi_giao_hang' => '1 Lê Văn Hiến, Đà Nẵng',
            'sdt_giao_hang' => '0901234567',
        ]);
    }
}
