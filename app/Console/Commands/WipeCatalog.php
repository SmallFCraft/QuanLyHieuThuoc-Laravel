<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\SearchQuery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Clears the product catalog and everything that points at it.
 *
 * Irreversible. order_items.MaSP is a restricting foreign key, so the tables have
 * to go in dependency order or the delete stops half-done. Order history goes with
 * the catalog because an order line cannot exist without its product row.
 */
class WipeCatalog extends Command
{
    protected $signature = 'db:wipe-catalog
        {--force : Skip the confirmation prompt}
        {--keep-images : Leave uploaded product images on disk}';

    protected $description = 'Xoá toàn bộ sản phẩm, danh mục, đơn hàng và ảnh sản phẩm (không thể hoàn tác)';

    /**
     * Delete order matters: each table listed here is referenced by the ones above it.
     *
     * search_queries has no foreign key to products, but it holds the terms the trending
     * pills promote — left behind they point at a catalog that no longer exists, and a
     * customer clicking one lands on an empty results page.
     */
    private const TABLES = [
        'order_items',
        'orders',
        'favorites',
        'medication_reminders',
        'product_batches',
        'products',
        'categories',
        'search_queries',
    ];

    /**
     * JSON snapshots of products, kept on records that outlive the catalog. Left
     * populated they would render links to product ids that no longer exist.
     *
     * @var array<string, array{0: string, 1: string}> table => [column, empty value]
     */
    private const PRODUCT_SNAPSHOTS = [
        'chat_messages' => ['products', '[]'],
        'chatbot_images' => ['recognized_products', '[]'],
        'triage_sessions' => ['suggested_products', '[]'],
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

        Category::flushCategoryCaches();
        Cache::forget(SearchQuery::CACHE_KEY);

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
     * Truncate is used rather than delete so the auto-increment keys restart, which
     * keeps the crawled catalog numbered from 1. It cannot run inside a transaction
     * on MySQL, and it ignores foreign keys, so the checks are lifted around it and
     * always restored.
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
     * Only locally uploaded images are on disk. Crawled products store a Long Châu
     * CDN URL, which has nothing to delete.
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
