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
 * Xóa sạch catalog sản phẩm và mọi thứ trỏ đến nó.
 *
 * Không thể hoàn tác. chi_tiet_don_hangs.ma_sp là foreign key chặn (restrict), nên các bảng
 * phải đi theo thứ tự phụ thuộc nếu không lệnh xóa sẽ dừng nửa đường. Lịch sử đơn hàng
 * đi cùng catalog vì một dòng đơn hàng không thể tồn tại không có dòng sản phẩm.
 */
class XoaSachDanhMuc extends Command
{
    protected $signature = 'db:wipe-catalog
        {--force : Skip the confirmation prompt}
        {--keep-images : Leave uploaded product images on disk}';

    protected $description = 'Xoá toàn bộ sản phẩm, danh mục, đơn hàng và ảnh sản phẩm (không thể hoàn tác)';

    /**
     * Thứ tự xóa quan trọng: mỗi bảng được liệt kê tại đây được tham chiếu bởi các bảng phía trên.
     *
     * truy_van_tim_kiems không có foreign key đến san_phams, nhưng nó giữ các từ khóa mà
     * các pill trending quảng bá — để lại chúng sẽ trỏ đến một catalog không còn
     * tồn tại, và khách bấm vào sẽ rơi trên trang kết quả trống.
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
     * Snapshot JSON của sản phẩm, giữ trên các bản ghi sống lâu hơn catalog. Nếu để
     * dữ liệu thì chúng sẽ render link đến các product id không còn tồn tại.
     * Tên cột là các key JSON payload của các bảng này — không đổi bởi việc
     * đổi tên schema.
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
     * Dùng truncate thay vì delete để key auto-increment chạy lại từ đầu, giữ catalog
     * crawled được đánh số từ 1. Không thể chạy trong transaction trên MySQL, và nó
     * bỏ qua foreign keys, nên các check được nâng lên quanh nó và luôn được khôi phục.
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
     * Chỉ ảnh tải lên cục bộ nằm trên disk. Sản phẩm crawled lưu URL CDN Long Châu,
     * không có gì để xóa.
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