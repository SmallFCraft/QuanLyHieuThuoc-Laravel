<?php

namespace Tests\Feature;

use App\Console\Commands\DanhGiaTimKiemGiongNoi;
use App\Console\Commands\DonDepAnhChatbot;
use App\Console\Commands\DonDepAnhMoCoi;
use App\Console\Commands\DonDepCongViecAi;
use App\Console\Commands\DonDepNhatKyKiemToan;
use App\Console\Commands\DonDepTinNhanChat;
use App\Console\Commands\GuiNhacUongThuoc;
use App\Console\Commands\GuiTheoDoiSangLoc;
use App\Console\Commands\KiemTraNhaCungCapAi;
use App\Console\Commands\ThuThapDuLieuLongChau;
use App\Console\Commands\XayDungPhienAmGiongNoi;
use App\Console\Commands\XoaSachDanhMuc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LenhArtisanTest extends TestCase
{
    use RefreshDatabase;

    public function test_cac_lop_lenh_tieng_viet_giu_nguyen_signature_tieng_anh(): void
    {
        $expected = [
            XayDungPhienAmGiongNoi::class => ['voice:build-phonetics', []],
            KiemTraNhaCungCapAi::class => ['chatbot:ai-check', ['vision' => false]],
            DonDepCongViecAi::class => ['ai-jobs:cleanup', []],
            DonDepNhatKyKiemToan::class => ['audit:cleanup', ['days' => '365']],
            DonDepTinNhanChat::class => ['chat:cleanup', []],
            DonDepAnhChatbot::class => ['chatbot:cleanup-images', ['hours' => null]],
            DonDepAnhMoCoi::class => ['images:cleanup', ['days' => '30']],
            ThuThapDuLieuLongChau::class => ['crawl:longchau', [
                'sitemap' => [],
                'limit' => '0',
                'concurrency' => null,
                'dry-run' => false,
            ]],
            GuiNhacUongThuoc::class => ['reminders:dispatch', []],
            GuiTheoDoiSangLoc::class => ['triage:follow-up', []],
            DanhGiaTimKiemGiongNoi::class => ['voice:eval', [
                'floor' => '56',
                'lies' => '14',
                'json' => false,
            ]],
            XoaSachDanhMuc::class => ['db:wipe-catalog', [
                'force' => false,
                'keep-images' => false,
            ]],
        ];
        $registered = Artisan::all();

        foreach ($expected as $class => [$signature, $options]) {
            $this->assertTrue(class_exists($class));
            $this->assertArrayHasKey($signature, $registered);
            $this->assertInstanceOf($class, $registered[$signature]);
            $this->assertSame($options, $registered[$signature]->getDefinition()->getOptionDefaults());
        }
    }

    public function test_cac_lenh_dinh_ky_an_toan_chay_voi_co_so_du_lieu_rong(): void
    {
        foreach ([
            'reminders:dispatch',
            'triage:follow-up',
            'ai-jobs:cleanup',
            'audit:cleanup',
            'chat:cleanup',
        ] as $signature) {
            $this->artisan($signature)->assertSuccessful();
        }
    }
}
