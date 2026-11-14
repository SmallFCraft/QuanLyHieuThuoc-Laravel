<?php

namespace App\Console\Commands;

use App\Models\DanhMuc;
use App\Models\TruyVanTimKiem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Xoa sach catalog san pham va moi thu tro den no.
 *
 * Khong the hoan tac. chi_tiet_don_hangs.ma_sp la foreign key chan (restrict), nen cac bang
 * phai di theo thu tu phu thuoc neu khong lenh xoa se dung luoi chang. Lich su don hang
 * di cung catalog vi mot dong don hang khong the ton tai khong co dong san pham.
 */
class XoaSachDanhMuc extends Command
{
    protected $signature = 'db:wipe-catalog
        {--force : Skip the confirmation prompt}
        {--keep-images : Leave uploaded product images on disk}';

    protected $description = 'Xoá toàn bộ sản phẩm, danh mục, đơn hàng và ảnh sản phẩm (không thể hoàn tác)';

    /**
     * Thu tu xoa quan trong: moi bang duoc liet ke tai day duoc tham chieu boi cac bang phia tren.
     *
     * truy_van_tim_kiems khong co foreign key den san_phams, nhung no giu cac tu khoa ma
     * cac pill trending quang ba — de lai chung se tro den mot catalog khong con
     * ton tai, va khach bam vao se roi tren trang ket qua trong.
     */
    private const TABLES = [
        'chi_tiet_don_hangs',
        'don_hangs',
        'yeu_thiches',
        'nhac_uong_thuocs',
        'lo_san_phams',
        'san_phams',
        'danh_mucs',
        'truy_van_tim_kiems',
    ];

    /**
     * Snapshot JSON cua san pham, giu tren cac ban ghi song lau hon catalog. Neu de
     * du lieu thi chung se render link den cac product id khong con ton tai.
     * Ten cot la cac key JSON payload cua cac bang nay — khong doi boi viec
     * doi ten schema.
     *
     * @var array<string, array{0: string, 1: string}> table => [column, empty value]
     */
    private const PRODUCT_SNAPSHOTS = [
        'tin_nhan_chats' => ['products', '[]'],
        'anh_chatbots' => ['recognized_products', '[]'],
        'phien_sang_locs' => ['suggested_products', '[]'],
    ];

    public function handle(): int
    {
        $counts = $this->currentCounts();

        $this->warn('Lệnh này xoá vĩnh viễn:');
        $this->table(['Bảng', 'Số dòng sẽ xoá'], collect($counts)
            ->map(fn (int $count, string $table) => [$table, number_format($count)])
            ->values()
            ->all());

        $imageCount = $this->productImages()->count();

        if (! $this->option('keep-images')) {
            $this->warn("Và {$imageCount} ảnh sản phẩm trong storage/app/public/products.");
        }

        $this->newLine();
        $this->error('Toàn bộ lịch sử đơn hàng sẽ mất. Không thể hoàn tác.');

        if (! $this->option('force') && ! $this->confirm('Tiếp tục?', false)) {
            $this->info('Đã huỷ. Không có gì bị xoá.');

            return self::SUCCESS;
        }

        $this->truncateTables();
        $this->clearProductSnapshots();

        if (! $this->option('keep-images')) {
            $this->deleteProductImages();
            $this->line("Đã xoá {$imageCount} ảnh sản phẩm.");
        }

        DanhMuc::flushCategoryCaches();
        Cache::forget(TruyVanTimKiem::CACHE_KEY);

        $this->newLine();
        $this->info('Đã dọn xong. Chạy `php artisan crawl:longchau` để nạp danh mục mới.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function currentCounts(): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * Dung truncate thay vi delete de key auto-increment chay lai tu dau, giu catalog
     * crawled duoc danh so tu 1. Khong the chay trong transaction tren MySQL, va no
     * bo qua foreign keys, nen cac check duoc nang len quanh no va luon duoc khoi phuc.
     */
    private function truncateTables(): void
    {
        $driver = DB::getDriverName();

        $this->toggleForeignKeys($driver, false);

        try {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                DB::table($table)->truncate();
                $this->line("  Đã xoá {$table}");
            }
        } finally {
            $this->toggleForeignKeys($driver, true);
        }
    }

    private function toggleForeignKeys(string $driver, bool $enabled): void
    {
        match ($driver) {
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=' . ($enabled ? '1' : '0')),
            'sqlite' => DB::statement('PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF')),
            default => null,
        };
    }

    private function clearProductSnapshots(): void
    {
        foreach (self::PRODUCT_SNAPSHOTS as $table => [$column, $empty]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $cleared = DB::table($table)
                ->whereNotNull($column)
                ->update([$column => $empty]);

            if ($cleared > 0) {
                $this->line("  Đã xoá tham chiếu sản phẩm trong {$table}.{$column} ({$cleared} dòng)");
            }
        }
    }

    /**
     * Chi anh tai len cuc bo nam tren disk. San pham crawled luu URL CDN Long Chau,
     * khong co gi de xoa.
     */
    private function productImages(): \Illuminate\Support\Collection
    {
        return collect(Storage::disk('public')->files('products'));
    }

    private function deleteProductImages(): void
    {
        $files = $this->productImages();

        if ($files->isNotEmpty()) {
            Storage::disk('public')->delete($files->all());
        }
    }
}