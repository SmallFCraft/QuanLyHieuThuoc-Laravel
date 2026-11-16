<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\BaoCaoSangLocController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DuocSi\HangChoSangLocController;
use App\Http\Controllers\NhacUongThuocController;
use App\Http\Controllers\SangLocController;
use App\Http\Requests\Auth\DangNhapRequest;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

class NapLopHttpTest extends TestCase
{
    public function test_cac_lop_http_tieng_viet_co_the_duoc_tu_dong_nap(): void
    {
        foreach ([
            SangLocController::class,
            NhacUongThuocController::class,
            HangChoSangLocController::class,
            BaoCaoSangLocController::class,
            DangNhapRequest::class,
        ] as $tenLop) {
            $this->assertTrue(class_exists($tenLop), "Không thể tự động nạp {$tenLop}");
        }
    }

    public function test_route_dung_controller_tieng_viet(): void
    {
        foreach ([
            'sang-loc.kiem-tra' => SangLocController::class.'@index',
            'nhac-uong.index' => NhacUongThuocController::class.'@index',
            'admin.bao-cao-sang-loc.index' => BaoCaoSangLocController::class.'@index',
            'duoc-si.sang-loc.index' => HangChoSangLocController::class.'@index',
        ] as $tenRoute => $hanhDong) {
            $route = Route::getRoutes()->getByName($tenRoute);

            $this->assertNotNull($route, "Thiếu route {$tenRoute}");
            $this->assertSame($hanhDong, $route->getActionName(), "Route {$tenRoute} dùng sai controller");
        }
    }

    public function test_dang_nhap_dung_request_tieng_viet(): void
    {
        $thamSo = (new ReflectionMethod(AuthenticatedSessionController::class, 'store'))
            ->getParameters()[0];

        $this->assertSame(DangNhapRequest::class, $thamSo->getType()?->getName());
    }
}
