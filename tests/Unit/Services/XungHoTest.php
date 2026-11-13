<?php

namespace Tests\Unit\Services;

use App\Models\NguoiDung;
use App\Services\Triage\Ai\SangLocAiNguCanhService;
use App\Services\NhatKyKiemToanService;
use Tests\TestCase;

class XungHoTest extends TestCase
{
    public function test_gioi_tinh_nam_tra_ve_anh(): void
    {
        $this->assertSame('anh', SangLocAiNguCanhService::honorificFor('male'));
    }

    public function test_gioi_tinh_nu_tra_ve_chi(): void
    {
        $this->assertSame('chị', SangLocAiNguCanhService::honorificFor('female'));
    }

    public function test_gioi_tinh_khac_tra_ve_anh_chi(): void
    {
        $this->assertSame('anh/chị', SangLocAiNguCanhService::honorificFor('other'));
    }

    public function test_gioi_tinh_null_tra_ve_anh_chi(): void
    {
        $this->assertSame('anh/chị', SangLocAiNguCanhService::honorificFor(null));
    }
}
