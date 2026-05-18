<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foreign keys that point to the products primary key.
     *
     * @var array<int, array<string, string>>
     */
    private array $references = [
        [
            'table' => 'product_batches',
            'old_column' => 'product_id',
            'new_column' => 'MaSP',
            'old_constraint' => 'product_batches_product_id_foreign',
            'new_constraint' => 'product_batches_masp_foreign',
            'delete' => 'CASCADE',
        ],
        [
            'table' => 'order_items',
            'old_column' => 'product_id',
            'new_column' => 'MaSP',
            'old_constraint' => 'order_items_product_id_foreign',
            'new_constraint' => 'order_items_masp_foreign',
            'delete' => 'RESTRICT',
        ],
    ];

    /**
     * Index names changed for consistency with MaSP.
     *
     * @var array<int, array<string, string>>
     */
    private array $indexes = [
        [
            'table' => 'product_batches',
            'old' => 'product_batches_product_id_expiration_date_index',
            'new' => 'product_batches_masp_expiration_date_index',
        ],
        [
            'table' => 'order_items',
            'old' => 'order_items_product_id_index',
            'new' => 'order_items_masp_index',
        ],
        [
            'table' => 'order_items',
            'old' => 'order_items_order_product_idx',
            'new' => 'order_items_order_masp_idx',
        ],
        [
            'table' => 'order_items',
            'old' => 'order_items_batch_product_idx',
            'new' => 'order_items_batch_masp_idx',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! Schema::hasTable('products')) {
            return;
        }

        $this->dropProductForeignKeys();

        foreach ($this->references as $reference) {
            $this->renameColumnIfNeeded($reference['table'], $reference['old_column'], $reference['new_column']);
        }

        $this->renameColumnIfNeeded('products', 'id', 'MaSP', autoIncrement: true);
        $this->renameIndexes('old', 'new');
        $this->addProductForeignKeys('new_constraint', 'new_column', 'MaSP');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! Schema::hasTable('products')) {
            return;
        }

        $this->dropProductForeignKeys();

        foreach ($this->references as $reference) {
            $this->renameColumnIfNeeded($reference['table'], $reference['new_column'], $reference['old_column']);
        }

        $this->renameColumnIfNeeded('products', 'MaSP', 'id', autoIncrement: true);
        $this->renameIndexes('new', 'old');
        $this->addProductForeignKeys('old_constraint', 'old_column', 'id');
    }

    private function dropProductForeignKeys(): void
    {
        foreach ($this->references as $reference) {
            if (! Schema::hasTable($reference['table'])) {
                continue;
            }

            foreach ($this->productForeignKeyNames($reference['table']) as $constraint) {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                    $reference['table'],
                    $constraint
                ));
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function productForeignKeyNames(string $table): array
    {
        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('REFERENCED_TABLE_NAME', 'products')
            ->pluck('CONSTRAINT_NAME')
            ->unique()
            ->values()
            ->all();
    }

    private function addProductForeignKeys(string $constraintKey, string $columnKey, string $productKey): void
    {
        if (! Schema::hasColumn('products', $productKey)) {
            return;
        }

        foreach ($this->references as $reference) {
            $table = $reference['table'];
            $column = $reference[$columnKey];
            $constraint = $reference[$constraintKey];

            if (
                ! Schema::hasTable($table)
                || ! Schema::hasColumn($table, $column)
                || $this->foreignKeyExists($table, $constraint)
            ) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `products` (`%s`) ON DELETE %s',
                $table,
                $constraint,
                $column,
                $productKey,
                $reference['delete']
            ));
        }
    }

    private function renameColumnIfNeeded(
        string $table,
        string $from,
        string $to,
        bool $autoIncrement = false
    ): void {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, $from)
            || Schema::hasColumn($table, $to)
        ) {
            return;
        }

        $autoIncrementSql = $autoIncrement ? ' AUTO_INCREMENT' : '';

        DB::statement(sprintf(
            'ALTER TABLE `%s` CHANGE `%s` `%s` BIGINT UNSIGNED NOT NULL%s',
            $table,
            $from,
            $to,
            $autoIncrementSql
        ));
    }

    private function renameIndexes(string $fromKey, string $toKey): void
    {
        foreach ($this->indexes as $index) {
            if (
                ! Schema::hasTable($index['table'])
                || ! $this->indexExists($index['table'], $index[$fromKey])
                || $this->indexExists($index['table'], $index[$toKey])
            ) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` RENAME INDEX `%s` TO `%s`',
                $index['table'],
                $index[$fromKey],
                $index[$toKey]
            ));
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
