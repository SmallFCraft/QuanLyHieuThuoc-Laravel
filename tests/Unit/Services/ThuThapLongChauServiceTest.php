<?php

namespace Tests\Unit\Services;

use App\Exceptions\CrawlerSourceChangedException;
use App\Services\ThuThapLongChauService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThuThapLongChauServiceTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(base_path("tests/fixtures/longchau/{$name}"));
    }

    private function sitemap(array $urls): string
    {
        $entries = collect($urls)
            ->map(fn (string $url) => "<url><loc>{$url}</loc><lastmod>2026-08-30</lastmod></url>")
            ->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?><urlset>' . $entries . '</urlset>';
    }

    private function crawler(): ThuThapLongChauService
    {
        config()->set('crawler.longchau.delay_ms', 0);

        return app(ThuThapLongChauService::class);
    }

    public function test_doc_url_san_pham_tu_sitemap(): void
    {
        Http::fake([
            '*/sitemap_thuoc.xml' => Http::response($this->sitemap([
                'https://nhathuoclongchau.com.vn/thuoc',
                'https://nhathuoclongchau.com.vn/thuoc/panadol-extra-1663.html',
                'https://nhathuoclongchau.com.vn/thuoc/bisoloc-25mg-4482.html',
            ])),
        ]);

        $urls = $this->crawler()->danhSachUrlSanPham(['thuoc']);

        // The section landing page sits in the same sitemap and carries no product.
        $this->assertSame([
            'https://nhathuoclongchau.com.vn/thuoc/panadol-extra-1663.html',
            'https://nhathuoclongchau.com.vn/thuoc/bisoloc-25mg-4482.html',
        ], $urls);
    }

    public function test_gop_nhieu_sitemap_va_bo_url_trung(): void
    {
        $shared = 'https://nhathuoclongchau.com.vn/thuoc/panadol-extra-1663.html';

        Http::fake([
            '*/sitemap_thuoc.xml' => Http::response($this->sitemap([$shared])),
            '*/sitemap_thuc-pham-chuc-nang.xml' => Http::response($this->sitemap([
                $shared,
                'https://nhathuoclongchau.com.vn/thuc-pham-chuc-nang/berocca-123.html',
            ])),
        ]);

        $urls = $this->crawler()->danhSachUrlSanPham(['thuoc', 'tpcn']);

        $this->assertCount(2, $urls);
    }

    public function test_sitemap_khong_xac_dinh_bi_tu_choi_truoc_khi_gui_request(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);

        $this->crawler()->danhSachUrlSanPham(['khong-ton-tai']);
    }

    public function test_quet_san_pham_tra_ve_mapping_va_thong_ke_tung_ket_qua(): void
    {
        Http::fake([
            '*/panadol*' => Http::response($this->fixture('panadol.html')),
            '*/bisoloc*' => Http::response($this->fixture('bisoloc-no-price.html')),
            '*/missing*' => Http::response('<html><body>404</body></html>', 404),
            '*/broken*' => Http::response('<html><body>no next data here</body></html>'),
        ]);

        $seen = [];

        $stats = $this->crawler()->moiSanPham([
            'https://nhathuoclongchau.com.vn/thuoc/panadol-extra-1663.html',
            'https://nhathuoclongchau.com.vn/thuoc/bisoloc-25mg-4482.html',
            'https://nhathuoclongchau.com.vn/thuoc/missing-9999.html',
            'https://nhathuoclongchau.com.vn/thuoc/broken-8888.html',
        ], function ($sanPham) use (&$seen) {
            $seen[] = $sanPham->sku;
        });

        $this->assertSame(['00005713'], $seen);
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(1, $stats['unusable'], 'The prescription drug has no published price.');
        $this->assertSame(1, $stats['failed'], 'The 404 counts as a failed fetch.');
        $this->assertSame(1, $stats['no_json'], 'A 200 without __NEXT_DATA__ is a structure miss.');
        $this->assertSame(4, $stats['fetched']);
    }

    public function test_mot_trang_loi_khong_dung_chuyen_quet(): void
    {
        Http::fake([
            '*/dead*' => Http::response('', 500),
            '*' => Http::response($this->fixture('panadol.html')),
        ]);

        $count = 0;

        $stats = $this->crawler()->moiSanPham([
            'https://nhathuoclongchau.com.vn/thuoc/dead-1.html',
            'https://nhathuoclongchau.com.vn/thuoc/panadol-extra-1663.html',
        ], function () use (&$count) {
            $count++;
        });

        $this->assertSame(1, $count);
        $this->assertSame(1, $stats['failed']);
    }

    public function test_gui_user_agent_cua_trinh_duyet(): void
    {
        Http::fake(['*' => Http::response($this->fixture('panadol.html'))]);

        $this->crawler()->moiSanPham(
            ['https://nhathuoclongchau.com.vn/thuoc/panadol-extra-1663.html'],
            fn () => null
        );

        Http::assertSent(fn (Request $request) => str_contains(
            $request->header('User-Agent')[0] ?? '',
            'Mozilla/5.0'
        ));
    }

    public function test_cau_truc_nguon_doi_la_dung_quet_thay_vi_ghi_row_sai(): void
    {
        // Long Châu dropping __NEXT_DATA__ would silently zero out the import, so
        // past a floor the crawler stops and says the mapping needs review.
        config()->set('crawler.longchau.missing_json_guard_floor', 4);

        Http::fake(['*' => Http::response('<html><body>rendered elsewhere</body></html>')]);

        $urls = collect(range(1, 8))
            ->map(fn (int $i) => "https://nhathuoclongchau.com.vn/thuoc/p{$i}.html")
            ->all();

        $this->expectException(CrawlerSourceChangedException::class);

        $this->crawler()->moiSanPham($urls, fn () => null);
    }

    public function test_trang_thieu_gia_khong_kich_hoat_gac_cau_truc(): void
    {
        config()->set('crawler.longchau.missing_json_guard_floor', 4);

        Http::fake(['*' => Http::response($this->fixture('bisoloc-no-price.html'))]);

        $urls = collect(range(1, 8))
            ->map(fn (int $i) => "https://nhathuoclongchau.com.vn/thuoc/p{$i}.html")
            ->all();

        $stats = $this->crawler()->moiSanPham($urls, fn () => null);

        $this->assertSame(8, $stats['unusable']);
        $this->assertSame(0, $stats['no_json']);
    }
}
