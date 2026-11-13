<?php

namespace Tests\Feature;

use App\Mail\TheoDoiSauSangLocMail;
use App\Models\NguoiDung;
use App\Models\PhienSangLoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteMailTheoDoiDongKyTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_mail_dien_day_tham_so_route_cu_va_qua_duoc_xac_thuc_chu_ky(): void
    {
        $nguoiDung = NguoiDung::factory()->create([
            'vai_tro' => 'User',
            'trang_thai' => 'active',
        ]);
        $phien = PhienSangLoc::create([
            'ma_nd' => $nguoiDung->ma_nd,
            'entry_point' => 'page',
            'current_step' => 6,
            'status' => 'completed',
            'outcome' => 'self_care',
            'advice_given' => 'Nghỉ ngơi, uống đủ nước.',
            'collected_data' => ['raw_symptom_text' => 'đau đầu nhẹ'],
        ]);

        try {
            $links = (new TheoDoiSauSangLocMail($phien))->content()->with;
        } catch (\Illuminate\Routing\Exceptions\UrlGenerationException $exception) {
            $this->fail($exception->getMessage());
        }

        foreach (['improvedUrl' => '1', 'notImprovedUrl' => '0'] as $key => $improved) {
            $url = $links[$key];

            $this->assertSame("/health-check/{$phien->ma_sl}/follow-up", parse_url($url, PHP_URL_PATH));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($improved, $query['improved']);
            $this->assertArrayHasKey('signature', $query);

            $this->actingAs($nguoiDung)->get($url)->assertOk();
        }
    }
}
