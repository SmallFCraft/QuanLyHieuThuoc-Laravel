<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $columns = [
        ['table' => 'categories', 'from' => 'id', 'to' => 'MaDM', 'auto' => true],
        ['table' => 'products', 'from' => 'category_id', 'to' => 'MaDM'],
        ['table' => 'product_batches', 'from' => 'id', 'to' => 'MaLo', 'auto' => true],
        ['table' => 'orders', 'from' => 'id', 'to' => 'MaDH', 'auto' => true],
        ['table' => 'order_items', 'from' => 'id', 'to' => 'MaCTDH', 'auto' => true],
        ['table' => 'order_items', 'from' => 'order_id', 'to' => 'MaDH'],
        ['table' => 'order_items', 'from' => 'batch_id', 'to' => 'MaLo', 'nullable' => true],
        ['table' => 'audit_logs', 'from' => 'id', 'to' => 'MaNK', 'auto' => true],
        ['table' => 'audit_logs', 'from' => 'model_id', 'to' => 'MaDoiTuong', 'nullable' => true],
        ['table' => 'contact_inquiries', 'from' => 'id', 'to' => 'MaLH', 'auto' => true],
        ['table' => 'chatbot_images', 'from' => 'id', 'to' => 'MaAnh', 'auto' => true],
        ['table' => 'chat_messages', 'from' => 'id', 'to' => 'MaTN', 'auto' => true],
    ];

    /**
     * @var array<int, array<string, string>>
     */
    private array $foreignKeys = [
        [
            'table' => 'products',
            'column' => 'MaDM',
            'constraint' => 'products_madm_foreign',
            'references_table' => 'categories',
            'references_column' => 'MaDM',
            'delete' => 'RESTRICT',
        ],
        [
            'table' => 'order_items',
            'column' => 'MaDH',
            'constraint' => 'order_items_madh_foreign',
            'references_table' => 'orders',
            'references_column' => 'MaDH',
            'delete' => 'CASCADE',
        ],
        [
            'table' => 'order_items',
            'column' => 'MaLo',
            'constraint' => 'order_items_malo_foreign',
            'references_table' => 'product_batches',
            'references_column' => 'MaLo',
            'delete' => 'SET NULL',
        ],
    ];

    /**
     * @var array<int, array<string, string>>
     */
    private array $indexes = [
        ['table' => 'products', 'old' => 'products_category_id_index', 'new' => 'products_madm_index'],
        ['table' => 'products', 'old' => 'products_category_expiration_idx', 'new' => 'products_madm_expiration_idx'],
        ['table' => 'order_items', 'old' => 'order_items_order_id_index', 'new' => 'order_items_madh_index'],
        ['table' => 'order_items', 'old' => 'order_items_batch_id_index', 'new' => 'order_items_malo_index'],
        ['table' => 'order_items', 'old' => 'order_items_order_product_idx', 'new' => 'order_items_madh_masp_idx'],
        ['table' => 'order_items', 'old' => 'order_items_order_masp_idx', 'new' => 'order_items_madh_masp_idx'],
        ['table' => 'order_items', 'old' => 'order_items_batch_product_idx', 'new' => 'order_items_malo_masp_idx'],
        ['table' => 'order_items', 'old' => 'order_items_batch_masp_idx', 'new' => 'order_items_malo_masp_idx'],
        ['table' => 'audit_logs', 'old' => 'audit_logs_model_type_model_id_index', 'new' => 'audit_logs_model_type_madoituong_index'],
        ['table' => 'audit_logs', 'old' => 'audit_logs_model_created_idx', 'new' => 'audit_logs_target_created_idx'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->dropForeignKeys([
            ['table' => 'products', 'columns' => ['category_id', 'MaDM']],
            ['table' => 'order_items', 'columns' => ['order_id', 'MaDH', 'batch_id', 'MaLo']],
        ]);

        foreach ($this->columns as $column) {
            $this->renameColumnIfNeeded(
                $column['table'],
                $column['from'],
                $column['to'],
                (bool) ($column['auto'] ?? false),
                (bool) ($column['nullable'] ?? false)
            );
        }

        $this->renameIndexes('old', 'new');
        $this->addForeignKeys();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->dropForeignKeys([
            ['table' => 'products', 'columns' => ['category_id', 'MaDM']],
            ['table' => 'order_items', 'columns' => ['order_id', 'MaDH', 'batch_id', 'MaLo']],
        ]);

        foreach (array_reverse($this->columns) as $column) {
            $this->renameColumnIfNeeded(
                $column['table'],
                $column['to'],
                $column['from'],
                (bool) ($column['auto'] ?? false),
                (bool) ($column['nullable'] ?? false)
            );
        }

        $this->renameIndexes('new', 'old');
        $this->addLegacyForeignKeys();
    }

    /**
     * @param array<int, array{table: string, columns: array<int, string>}> $targets
     */
    private function dropForeignKeys(array $targets): void
    {
        foreach ($targets as $target) {
            if (! Schema::hasTable($target['table'])) {
                continue;
            }

            foreach ($this->foreignKeyNamesForColumns($target['table'], $target['columns']) as $constraint) {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                    $target['table'],
                    $constraint
                ));
            }
        }
    }

    /**
     * @param array<int, string> $columns
     * @return array<int, string>
     */
    private function foreignKeyNamesForColumns(string $table, array $columns): array
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->whereIn('COLUMN_NAME', $columns)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->unique()
            ->values()
            ->all();
    }

    private function renameColumnIfNeeded(
        string $table,
        string $from,
        string $to,
        bool $autoIncrement = false,
        bool $nullable = false
    ): void {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, $from)
            || Schema::hasColumn($table, $to)
        ) {
            return;
        }

        $nullSql = $nullable ? 'NULL' : 'NOT NULL';
        $autoIncrementSql = $autoIncrement ? ' AUTO_INCREMENT' : '';

        DB::statement(sprintf(
            'ALTER TABLE `%s` CHANGE `%s` `%s` BIGINT UNSIGNED %s%s',
            $table,
            $from,
            $to,
            $nullSql,
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

    private function addForeignKeys(): void
    {
        foreach ($this->foreignKeys as $foreignKey) {
            $this->addForeignKey(
                $foreignKey['table'],
                $foreignKey['column'],
                $foreignKey['constraint'],
                $foreignKey['references_table'],
                $foreignKey['references_column'],
                $foreignKey['delete']
            );
        }
    }

    private function addLegacyForeignKeys(): void
    {
        $legacyForeignKeys = [
            ['products', 'category_id', 'products_category_id_foreign', 'categories', 'id', 'RESTRICT'],
            ['order_items', 'order_id', 'order_items_order_id_foreign', 'orders', 'id', 'CASCADE'],
            ['order_items', 'batch_id', 'order_items_batch_id_foreign', 'product_batches', 'id', 'SET NULL'],
        ];

        foreach ($legacyForeignKeys as [$table, $column, $constraint, $referencesTable, $referencesColumn, $delete]) {
            $this->addForeignKey($table, $column, $constraint, $referencesTable, $referencesColumn, $delete);
        }
    }

    private function addForeignKey(
        string $table,
        string $column,
        string $constraint,
        string $referencesTable,
        string $referencesColumn,
        string $delete
    ): void {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasTable($referencesTable)
            || ! Schema::hasColumn($table, $column)
            || ! Schema::hasColumn($referencesTable, $referencesColumn)
            || $this->foreignKeyExists($table, $constraint)
        ) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s',
            $table,
            $constraint,
            $column,
            $referencesTable,
            $referencesColumn,
            $delete
        ));
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
