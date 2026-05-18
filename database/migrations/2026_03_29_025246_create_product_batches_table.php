<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id('MaLo');
            $table->foreignId('MaSP')->constrained('products', 'MaSP')->onDelete('cascade');
            $table->string('batch_number')->unique();
            $table->date('expiration_date');
            $table->integer('stock_quantity')->default(0);
            $table->integer('reorder_point')->default(10);
            $table->timestamps();

            $table->index(['MaSP', 'expiration_date'], 'product_batches_masp_expiration_date_index');
            $table->index(['expiration_date', 'stock_quantity']);
        });

        DB::statement('
            INSERT INTO product_batches (MaSP, batch_number, expiration_date, stock_quantity, reorder_point, created_at, updated_at)
            SELECT MaSP, batch_number, expiration_date, stock_quantity, reorder_point, created_at, updated_at
            FROM products
            WHERE deleted_at IS NULL
        ');

        $this->repairExistingNegativeValues();
        $this->createNonNegativeGuards();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropNonNegativeGuards();

        Schema::dropIfExists('product_batches');
    }

    protected function repairExistingNegativeValues(): void
    {
        DB::table('products')->where('price', '<', 0)->update(['price' => 0]);
        DB::table('products')->where('stock_quantity', '<', 0)->update(['stock_quantity' => 0]);
        DB::table('products')->where('reorder_point', '<', 0)->update(['reorder_point' => 0]);

        DB::table('product_batches')->where('stock_quantity', '<', 0)->update(['stock_quantity' => 0]);
        DB::table('product_batches')->where('reorder_point', '<', 0)->update(['reorder_point' => 0]);
    }

    protected function createNonNegativeGuards(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => $this->createSqliteGuards(),
            'mysql' => $this->createMysqlChecks(),
            'pgsql' => $this->createPostgresChecks(),
            default => null,
        };
    }

    protected function dropNonNegativeGuards(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => $this->dropSqliteGuards(),
            'mysql' => $this->dropMysqlChecks(),
            'pgsql' => $this->dropPostgresChecks(),
            default => null,
        };
    }

    protected function createSqliteGuards(): void
    {
        DB::unprepared("
            CREATE TRIGGER products_non_negative_insert
            BEFORE INSERT ON products
            FOR EACH ROW
            WHEN NEW.price < 0 OR NEW.stock_quantity < 0 OR NEW.reorder_point < 0
            BEGIN
                SELECT RAISE(ABORT, 'products_non_negative_check');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER products_non_negative_update
            BEFORE UPDATE ON products
            FOR EACH ROW
            WHEN NEW.price < 0 OR NEW.stock_quantity < 0 OR NEW.reorder_point < 0
            BEGIN
                SELECT RAISE(ABORT, 'products_non_negative_check');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER product_batches_non_negative_insert
            BEFORE INSERT ON product_batches
            FOR EACH ROW
            WHEN NEW.stock_quantity < 0 OR NEW.reorder_point < 0
            BEGIN
                SELECT RAISE(ABORT, 'product_batches_non_negative_check');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER product_batches_non_negative_update
            BEFORE UPDATE ON product_batches
            FOR EACH ROW
            WHEN NEW.stock_quantity < 0 OR NEW.reorder_point < 0
            BEGIN
                SELECT RAISE(ABORT, 'product_batches_non_negative_check');
            END;
        ");
    }

    protected function dropSqliteGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS products_non_negative_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS products_non_negative_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_batches_non_negative_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS product_batches_non_negative_update');
    }

    protected function createMysqlChecks(): void
    {
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_price_non_negative CHECK (price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_stock_quantity_non_negative CHECK (stock_quantity >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_reorder_point_non_negative CHECK (reorder_point >= 0)');
        DB::statement('ALTER TABLE product_batches ADD CONSTRAINT chk_product_batches_stock_quantity_non_negative CHECK (stock_quantity >= 0)');
        DB::statement('ALTER TABLE product_batches ADD CONSTRAINT chk_product_batches_reorder_point_non_negative CHECK (reorder_point >= 0)');
    }

    protected function dropMysqlChecks(): void
    {
        DB::statement('ALTER TABLE products DROP CHECK chk_products_price_non_negative');
        DB::statement('ALTER TABLE products DROP CHECK chk_products_stock_quantity_non_negative');
        DB::statement('ALTER TABLE products DROP CHECK chk_products_reorder_point_non_negative');
        DB::statement('ALTER TABLE product_batches DROP CHECK chk_product_batches_stock_quantity_non_negative');
        DB::statement('ALTER TABLE product_batches DROP CHECK chk_product_batches_reorder_point_non_negative');
    }

    protected function createPostgresChecks(): void
    {
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_price_non_negative CHECK (price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_stock_quantity_non_negative CHECK (stock_quantity >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_reorder_point_non_negative CHECK (reorder_point >= 0)');
        DB::statement('ALTER TABLE product_batches ADD CONSTRAINT chk_product_batches_stock_quantity_non_negative CHECK (stock_quantity >= 0)');
        DB::statement('ALTER TABLE product_batches ADD CONSTRAINT chk_product_batches_reorder_point_non_negative CHECK (reorder_point >= 0)');
    }

    protected function dropPostgresChecks(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_price_non_negative');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_stock_quantity_non_negative');
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS chk_products_reorder_point_non_negative');
        DB::statement('ALTER TABLE product_batches DROP CONSTRAINT IF EXISTS chk_product_batches_stock_quantity_non_negative');
        DB::statement('ALTER TABLE product_batches DROP CONSTRAINT IF EXISTS chk_product_batches_reorder_point_non_negative');
    }
};
