<?php

namespace Tests\Feature;

use App\Models\DanhMuc;
use App\Models\HoSoSucKhoe;
use App\Models\NguoiDung;
use App\Models\SanPham;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class HopDongBienHttpTest extends TestCase
{
    use RefreshDatabase;

    private DanhMuc $danhMuc;
    private SanPham $sanPham;
    private NguoiDung $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->danhMuc = DanhMuc::factory()->create(['ten' => 'Vitamin']);
        $this->sanPham = SanPham::factory()->create([
            'ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Vitamin C',
            'thanh_phan' => null,
            'gia' => 15000,
            'so_luong_ton' => 10,
            'muc_canh_bao' => 2,
            'so_lo' => 'HTTP-001',
            'ngay_het_han' => now()->addYear(),
        ]);
        $this->admin = NguoiDung::factory()->create([
            'vai_tro' => 'Administrator',
            'ngay_sinh' => now()->subYears(30)->toDateString(),
        ]);
    }

    public function test_masp_cua_form_gio_hang_luu_dung_session(): void
    {
        $this->post('/cart/add', ['MaSP' => $this->sanPham->ma_sp, 'quantity' => 2])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('cart', [$this->sanPham->ma_sp => 2]);

        $this->postJson('/cart/add', ['MaSP' => $this->sanPham->ma_sp, 'quantity' => 1])
            ->assertOk()
            ->assertJsonPath('cart_count', 1)
            ->assertSessionHas('cart', [$this->sanPham->ma_sp => 3]);
    }

    public function test_chatbot_nhan_masp_khi_them_vao_gio(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/chatbot/cart/add', ['MaSP' => $this->sanPham->ma_sp, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertSessionHas('cart', [$this->sanPham->ma_sp => 2]);
    }

    public function test_cong_an_toan_thuoc_van_chan_khi_gui_masp(): void
    {
        HoSoSucKhoe::create([
            'ma_nd' => $this->admin->ma_nd,
            'allergies' => ['Paracetamol'],
            'consented_at' => now(),
        ]);

        $panadol = SanPham::factory()->create([
            'ten' => 'Panadol 500mg',
            'thanh_phan' => 'Paracetamol 500mg',
            'gia' => 5000,
            'so_luong_ton' => 10,
            'muc_canh_bao' => 2,
            'so_lo' => 'HTTP-SAFETY',
            'ngay_het_han' => now()->addYear(),
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/chatbot/cart/add', ['MaSP' => $panadol->ma_sp, 'quantity' => 1])
            ->assertStatus(409)
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('success', false);
        $this->assertEmpty(session('cart', []));

        $this->actingAs($this->admin)
            ->post('/cart/add', ['MaSP' => $panadol->ma_sp, 'quantity' => 1])
            ->assertSessionHas('drug_warnings');
        $this->assertEmpty(session('cart', []));

        $this->actingAs($this->admin)
            ->postJson('/api/chatbot/cart/add', [
                'MaSP' => $panadol->ma_sp,
                'quantity' => 1,
                'confirm_warning' => 1,
            ])
            ->assertOk()
            ->assertSessionHas('cart', [$panadol->ma_sp => 1]);
        $this->assertDatabaseHas('nhat_ky_kiem_toans', ['action' => 'drug_warning_overridden']);
    }

    public function test_tom_tat_gio_chatbot_giu_khoa_masp(): void
    {
        $response = $this->actingAs($this->admin)
            ->withSession(['cart' => [$this->sanPham->ma_sp => 2]])
            ->getJson('/api/chatbot/cart')
            ->assertOk()
            ->assertJsonPath('cart.items.0.MaSP', $this->sanPham->ma_sp)
            ->assertJsonPath('cart.items.0.name', 'Vitamin C')
            ->assertJsonPath('cart.items.0.price', 15000)
            ->assertJsonPath('cart.items.0.quantity', 2)
            ->assertJsonPath('cart.items.0.stock_quantity', 10)
            ->assertJsonPath('cart.total', 30000);

        $this->assertArrayNotHasKey('ma_sp', $response->json('cart.items.0'));
    }

    public function test_chatbot_nhan_masp_khi_cap_nhat_va_xoa(): void
    {
        $this->actingAs($this->admin)->withSession(['cart' => [$this->sanPham->ma_sp => 2]]);

        $this->postJson('/api/chatbot/cart/update', ['MaSP' => $this->sanPham->ma_sp, 'quantity' => 4])
            ->assertOk()
            ->assertJsonPath('cart.total', 60000)
            ->assertSessionHas('cart', [$this->sanPham->ma_sp => 4]);

        $this->postJson('/api/chatbot/cart/update', ['MaSP' => $this->sanPham->ma_sp, 'quantity' => 0])
            ->assertOk()
            ->assertJsonPath('cart.items', [])
            ->assertJsonPath('cart.count', 0)
            ->assertSessionHas('cart', []);
    }

    public function test_form_ton_kho_nhan_masp_adjustment_reason(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/inventory/adjust', [
            'MaSP' => $this->sanPham->ma_sp,
            'adjustment' => 2,
            'reason' => 'Nhập bổ sung',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('san_phams', ['ma_sp' => $this->sanPham->ma_sp, 'so_luong_ton' => 12]);
        $this->assertDatabaseHas('lo_san_phams', ['ma_sp' => $this->sanPham->ma_sp, 'so_luong_ton' => 12]);

        $this->postJson('/admin/inventory/adjust', [
            'MaSP' => $this->sanPham->ma_sp,
            'adjustment' => -13,
            'reason' => 'Xuất quá tồn',
        ])->assertStatus(400)->assertJsonPath('success', false);

        $this->assertSame(12, $this->sanPham->fresh()->so_luong_ton);
    }

    public function test_form_san_pham_nhan_madm_va_luu_cot_viet(): void
    {
        $this->actingAs($this->admin)->post('/admin/products', $this->productForm())
            ->assertRedirect('/admin/products')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('san_phams', [
            'ma_dm' => $this->danhMuc->ma_dm,
            'ten' => 'Sản phẩm từ form',
            'gia' => 25000,
            'so_lo' => 'HTTP-NEW',
            'so_luong_ton' => 7,
            'muc_canh_bao' => 3,
        ]);
    }

    public function test_form_cap_nhat_san_pham_giu_madm_va_lo(): void
    {
        $this->actingAs($this->admin);

        $form = $this->productForm();
        $form['batch_number'] = 'HTTP-001';
        $form['MaDM'] = DanhMuc::factory()->create(['ten' => 'Danh mục khác'])->ma_dm;

        $this->put('/admin/products/'.$this->sanPham->ma_sp, $form)
            ->assertRedirect('/admin/products')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('san_phams', [
            'ma_sp' => $this->sanPham->ma_sp,
            'ma_dm' => $form['MaDM'],
            'ten' => 'Sản phẩm từ form',
            'so_luong_ton' => 7,
        ]);
        $this->assertDatabaseHas('lo_san_phams', [
            'ma_sp' => $this->sanPham->ma_sp,
            'so_lo' => 'HTTP-001',
            'so_luong_ton' => 7,
            'muc_canh_bao' => 3,
        ]);
        $this->assertSame(1, $this->sanPham->cacLo()->count());
    }

    public function test_loc_san_pham_nhan_madm(): void
    {
        SanPham::factory()->create(['ngay_het_han' => now()->addYear()]);

        $this->actingAs($this->admin)
            ->getJson('/admin/products/data?MaDM='.$this->danhMuc->ma_dm)
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Vitamin C');
    }

    public function test_json_san_pham_giu_khoa_masp(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/admin/products/data')
            ->assertOk()
            ->assertJsonPath('data.0.MaSP', $this->sanPham->ma_sp)
            ->assertJsonPath('data.0.stock_quantity', 10);

        $this->assertArrayNotHasKey('ma_sp', $response->json('data.0'));
    }

    public function test_xuat_san_pham_nhan_madm(): void
    {
        SanPham::factory()->create(['ten' => 'Không thuộc danh mục', 'ngay_het_han' => now()->addYear()]);

        $response = $this->actingAs($this->admin)
            ->get('/admin/products/export?MaDM='.$this->danhMuc->ma_dm)
            ->assertOk()
            ->assertDownload('san-pham.xlsx');

        $workbook = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $rows = $workbook->getActiveSheet()->toArray();
        $workbook->disconnectWorksheets();

        $this->assertSame(['Tên sản phẩm', 'Vitamin C'], array_column($rows, 1));
    }

    public function test_loi_xac_thuc_giu_khoa_masp_madm(): void
    {
        $this->actingAs($this->admin);

        foreach (['/cart/add', '/api/chatbot/cart/add', '/api/chatbot/cart/update', '/admin/inventory/adjust'] as $url) {
            $this->postJson($url, ['MaSP' => 999999, 'quantity' => 1, 'adjustment' => 1, 'reason' => 'Kiểm tra'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('MaSP');
        }

        $form = $this->productForm();
        $form['MaDM'] = 999999;
        $this->postJson('/admin/products', $form)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('MaDM');

        $this->assertEmpty(session('cart', []));
        $this->assertSame(10, $this->sanPham->fresh()->so_luong_ton);
        $this->assertSame(1, SanPham::count());
    }

    private function productForm(): array
    {
        return [
            'MaDM' => $this->danhMuc->ma_dm,
            'name' => 'Sản phẩm từ form',
            'ingredients' => 'Vitamin C',
            'usage' => 'Theo hướng dẫn',
            'price' => 25000,
            'expiration_date' => now()->addYear()->format('Y-m-d'),
            'stock_quantity' => 7,
            'reorder_point' => 3,
            'batch_number' => 'HTTP-NEW',
        ];
    }
}
