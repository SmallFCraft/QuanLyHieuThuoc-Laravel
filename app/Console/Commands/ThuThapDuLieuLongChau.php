<?php

namespace App\Console\Commands;

use App\Exceptions\CrawlerSourceChangedException;
use App\Models\DanhMuc;
use App\Services\NhapSanPhamService;
use App\Services\ThuThapLongChauService;
use App\Support\LongChauProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ThuThapDuLieuLongChau extends Command
{
    protected $signature = 'crawl:longchau
        {--sitemap=* : Sitemap keys to crawl; defaults to all}
        {--limit=0 : Stop after this many product pages (0 = no limit)}
        {--concurrency= : Pages fetched at once, overriding config}
        {--dry-run : Report what would be imported without writing to the database}';

    protected $description = 'Nạp danh mục sản phẩm từ Nhà Thuốc Long Châu vào bảng san_phams';

    public function handle(ThuThapLongChauService $crawler, NhapSanPhamService $importer): int
    {
        $sitemaps = $this->option('sitemap') ?: $crawler->danhSachSiteMap();
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        if ($concurrency = (int) $this->option('concurrency')) {
            config()->set('crawler.longchau.concurrency', max(1, $concurrency));
        }

        $this->info('Đang đọc sitemap: ' . implode(', ', $sitemaps));

        try {
            $urls = $crawler->danhSachUrlSanPham($sitemaps);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($urls === []) {
            $this->error('Không đọc được URL sản phẩm nào. Kiểm tra kết nối mạng hoặc cấu hình sitemap.');

            return self::FAILURE;
        }

        if ($limit > 0) {
            $urls = array_slice($urls, 0, $limit);
        }

        $this->line(sprintf('Tìm thấy %s trang sản phẩm.', number_format(count($urls))));

        if ($dryRun) {
            $this->warn('Chế độ --dry-run: không ghi vào cơ sở dữ liệu.');
        }

        $importer->quenDanhMuc();

        $bar = $this->output->createProgressBar(count($urls));
        $bar->start();

        $written = 0;
        $writeErrors = 0;

        try {
            $stats = $crawler->moiSanPham(
                $urls,
                function (LongChauProduct $product, string $url) use ($importer, $dryRun, $bar, &$written, &$writeErrors) {
                    $bar->advance();

                    if ($dryRun) {
                        $this->reportDryRun($product);

                        return;
                    }

                    try {
                        $importer->nhap($product);
                        $written++;
                    } catch (Throwable $e) {
                        // Mot dong loi duy nhat khong duoc ket thuc mot lan chay cua hang nghin dong.
                        $writeErrors++;

                        Log::warning('Long Châu product could not be imported', [
                            'url' => $url,
                            'sku' => $product->sku,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            );
        } catch (CrawlerSourceChangedException $e) {
            $bar->finish();
            $this->newLine(2);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            // Progress bar chi tien khi co san pham dung duoc, nen no luon dung truoc
            // tong so khi co trang bi bo qua.
            $bar->finish();
            $this->newLine(2);
        }

        if (! $dryRun && $written > 0) {
            DanhMuc::flushCategoryCaches();
        }

        $this->table(
            ['Trang tải', 'Nạp được', 'Không có giá', 'Lỗi tải', 'Thiếu JSON', 'Lỗi ghi'],
            [[
                number_format($stats['fetched']),
                number_format($dryRun ? $stats['imported'] : $written),
                number_format($stats['unusable']),
                number_format($stats['failed']),
                number_format($stats['no_json']),
                number_format($writeErrors),
            ]]
        );

        if ($stats['unusable'] > 0) {
            $this->line(sprintf(
                '%s sản phẩm bị bỏ qua vì Long Châu không niêm yết giá (chủ yếu là thuốc kê đơn).',
                number_format($stats['unusable'])
            ));
        }

        if ($writeErrors > 0) {
            $this->warn("{$writeErrors} sản phẩm không ghi được — xem laravel.log để biết chi tiết.");
        }

        $this->reportNewCategories($importer, $dryRun);

        // Catalog vua doi nen index phien am da lech. Khong goi o day thi voice
        // search chi thay catalog cua lan crawl truoc cho toi lan chay lich ke.
        // --dry-run khong ghi gi vao DB nen cung khong co gi de danh index lai.
        if (! $dryRun && $written > 0) {
            $this->call('voice:build-phonetics');
        }

        return self::SUCCESS;
    }

    /**
     * Danh muc ma lan chay phai tao. Long Chau viet cung mot section khac nhau
     * giua cac chi nhanh ("Co - xuong - khop" thuoc Thuoc, "Co xuong khop" thuoc Thuc
     * pham chuc nang); fold bat duoc nhung truong hop do, nhung mot section hoan toan
     * moi van can nguoi quyet dinh no co nam tren storefront nhu mot entry rieng hay khong.
     */
    private function reportNewCategories(NhapSanPhamService $importer, bool $dryRun): void
    {
        $created = $dryRun ? [] : $importer->danhMucMoi();

        if ($created === []) {
            return;
        }

        $this->warn(sprintf('%d danh mục mới được tạo trong lần chạy này:', count($created)));

        foreach ($created as $name) {
            $this->line('  • ' . $name);
        }

        $this->line('Kiểm tra xem có danh mục nào trùng nghĩa với danh mục cũ không (admin → Danh mục).');
    }

    private function reportDryRun(LongChauProduct $product): void
    {
        $this->newLine();
        $this->line(sprintf(
            '  [%s] %s — %s đ — %s',
            $product->sku,
            \Illuminate\Support\Str::limit($product->name, 60),
            number_format($product->price),
            $product->categoryName
        ));
    }
}
