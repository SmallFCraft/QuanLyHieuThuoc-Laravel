<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, array{table: string, old: string, new: string}>
     */
    private array $indexes = [
        ['table' => 'products', 'old' => 'products_category_id_index', 'new' => 'products_madm_index'],
        ['table' => 'product_batches', 'old' => 'product_batches_product_id_expiration_date_index', 'new' => 'product_batches_masp_expiration_date_index'],
        ['table' => 'orders', 'old' => 'orders_user_id_index', 'new' => 'orders_makh_index'],
        ['table' => 'order_items', 'old' => 'order_items_order_id_index', 'new' => 'order_items_madh_index'],
        ['table' => 'order_items', 'old' => 'order_items_batch_id_index', 'new' => 'order_items_malo_index'],
        ['table' => 'order_items', 'old' => 'order_items_order_product_idx', 'new' => 'order_items_madh_masp_idx'],
        ['table' => 'order_items', 'old' => 'order_items_order_masp_idx', 'new' => 'order_items_madh_masp_idx'],
        ['table' => 'order_items', 'old' => 'order_items_batch_product_idx', 'new' => 'order_items_malo_masp_idx'],
        ['table' => 'order_items', 'old' => 'order_items_batch_masp_idx', 'new' => 'order_items_malo_masp_idx'],
        ['table' => 'audit_logs', 'old' => 'audit_logs_user_id_index', 'new' => 'audit_logs_makh_index'],
        ['table' => 'audit_logs', 'old' => 'audit_logs_model_type_model_id_index', 'new' => 'audit_logs_model_type_madoituong_index'],
        ['table' => 'audit_logs', 'old' => 'audit_logs_model_created_idx', 'new' => 'audit_logs_target_created_idx'],
        ['table' => 'chatbot_images', 'old' => 'chatbot_images_user_id_session_id_index', 'new' => 'chatbot_images_makh_session_id_index'],
        ['table' => 'chat_messages', 'old' => 'chat_messages_user_id_session_id_created_at_index', 'new' => 'chat_messages_makh_session_id_created_at_index'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->renameIndexes('old', 'new');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->renameIndexes('new', 'old');
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
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
