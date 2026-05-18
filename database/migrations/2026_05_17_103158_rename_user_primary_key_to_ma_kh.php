<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foreign keys that point to the users primary key.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $references = [
        [
            'table' => 'sessions',
            'old_column' => 'user_id',
            'new_column' => 'user_id',
            'old_constraint' => 'sessions_user_id_foreign',
            'new_constraint' => 'sessions_user_id_foreign',
            'delete' => 'CASCADE',
            'nullable' => true,
        ],
        [
            'table' => 'orders',
            'old_column' => 'user_id',
            'new_column' => 'MaKH',
            'old_constraint' => 'orders_user_id_foreign',
            'new_constraint' => 'orders_makh_foreign',
            'delete' => 'RESTRICT',
            'nullable' => false,
        ],
        [
            'table' => 'audit_logs',
            'old_column' => 'user_id',
            'new_column' => 'MaKH',
            'old_constraint' => 'audit_logs_user_id_foreign',
            'new_constraint' => 'audit_logs_makh_foreign',
            'delete' => 'SET NULL',
            'nullable' => true,
        ],
        [
            'table' => 'chatbot_images',
            'old_column' => 'user_id',
            'new_column' => 'MaKH',
            'old_constraint' => 'chatbot_images_user_id_foreign',
            'new_constraint' => 'chatbot_images_makh_foreign',
            'delete' => 'CASCADE',
            'nullable' => false,
        ],
        [
            'table' => 'chat_messages',
            'old_column' => 'user_id',
            'new_column' => 'MaKH',
            'old_constraint' => 'chat_messages_user_id_foreign',
            'new_constraint' => 'chat_messages_makh_foreign',
            'delete' => 'CASCADE',
            'nullable' => false,
        ],
    ];

    /**
     * @var array<int, array{table: string, old: string, new: string}>
     */
    private array $indexes = [
        ['table' => 'orders', 'old' => 'orders_user_id_index', 'new' => 'orders_makh_index'],
        ['table' => 'audit_logs', 'old' => 'audit_logs_user_id_index', 'new' => 'audit_logs_makh_index'],
        ['table' => 'chatbot_images', 'old' => 'chatbot_images_user_id_session_id_index', 'new' => 'chatbot_images_makh_session_id_index'],
        ['table' => 'chat_messages', 'old' => 'chat_messages_user_id_session_id_created_at_index', 'new' => 'chat_messages_makh_session_id_created_at_index'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! Schema::hasTable('users')) {
            return;
        }

        $this->dropUserForeignKeys('old_constraint');

        foreach ($this->references as $reference) {
            $this->renameColumnIfNeeded(
                $reference['table'],
                $reference['old_column'],
                $reference['new_column'],
                $reference['nullable']
            );
        }

        $this->renameColumnIfNeeded('users', 'id', 'MaKH', false, true);
        $this->renameIndexes('old', 'new');

        $this->addUserForeignKeys('new_constraint', 'new_column', 'MaKH');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! Schema::hasTable('users')) {
            return;
        }

        $this->dropUserForeignKeys('new_constraint');

        foreach ($this->references as $reference) {
            $this->renameColumnIfNeeded(
                $reference['table'],
                $reference['new_column'],
                $reference['old_column'],
                $reference['nullable']
            );
        }

        $this->renameColumnIfNeeded('users', 'MaKH', 'id', false, true);
        $this->renameIndexes('new', 'old');

        $this->addUserForeignKeys('old_constraint', 'old_column', 'id');
    }

    private function dropUserForeignKeys(string $constraintKey): void
    {
        foreach ($this->references as $reference) {
            if (
                Schema::hasTable($reference['table'])
                && $this->foreignKeyExists($reference['table'], $reference[$constraintKey])
            ) {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                    $reference['table'],
                    $reference[$constraintKey]
                ));
            }
        }
    }

    private function addUserForeignKeys(string $constraintKey, string $columnKey, string $userKey): void
    {
        if (! Schema::hasColumn('users', $userKey)) {
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
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `users` (`%s`) ON DELETE %s',
                $table,
                $constraint,
                $column,
                $userKey,
                $reference['delete']
            ));
        }
    }

    private function renameColumnIfNeeded(
        string $table,
        string $from,
        string $to,
        bool $nullable,
        bool $autoIncrement = false
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
