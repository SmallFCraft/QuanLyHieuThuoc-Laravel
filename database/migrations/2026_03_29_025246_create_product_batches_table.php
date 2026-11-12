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
        Schema::create('lo_san_phams', function (Blueprint $table) {
            $table->id('ma_lo');
            $table->foreignId('ma_sp')->constrained('san_phams', 'ma_sp')->onDelete('cascade');
            $table->string('so_lo')->unique();
            $table->date('ngay_het_han');
            $table->integer('so_luong_ton')->default(0);
            $table->integer('muc_canh_bao')->default(10);
            $table->timestamps();

            $table->index(['ma_sp', 'ngay_het_han'], 'lo_san_phams_ma_sp_ngay_het_han_index');
            $table->index(['ngay_het_han', 'so_luong_ton']);
        });

        DB::statement('
            INSERT INTO lo_san_phams (ma_sp, so_lo, ngay_het_han, so_luong_ton, muc_canh_bao, created_at, updated_at)
            SELECT ma_sp, so_lo, ngay_het_han, so_luong_ton, muc_canh_bao, created_at, updated_at
            FROM san_phams
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

        Schema::dropIfExists('lo_san_phams');
    }

    protected function repairExistingNegativeValues(): void
    {
        DB::table('san_phams')->where('gia', '<', 0)->update(['gia' => 0]);
        DB::table('san_phams')->where('so_luong_ton', '<', 0)->update(['so_luong_ton' => 0]);
        DB::table('san_phams')->where('muc_canh_bao', '<', 0)->update(['muc_canh_bao' => 0]);

        DB::table('lo_san_phams')->where('so_luong_ton', '<', 0)->update(['so_luong_ton' => 0]);
        DB::table('lo_san_phams')->where('muc_canh_bao', '<', 0)->update(['muc_canh_bao' => 0]);
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
            CREATE TRIGGER san_phams_non_negative_insert
            BEFORE INSERT ON san_phams
            FOR EACH ROW
            WHEN NEW.gia < 0 OR NEW.so_luong_ton < 0 OR NEW.muc_canh_bao < 0
            BEGIN
                SELECT RAISE(ABORT, 'san_phams_non_negative_check');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER san_phams_non_negative_update
            BEFORE UPDATE ON san_phams
            FOR EACH ROW
            WHEN NEW.gia < 0 OR NEW.so_luong_ton < 0 OR NEW.muc_canh_bao < 0
            BEGIN
                SELECT RAISE(ABORT, 'san_phams_non_negative_check');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER lo_san_phams_non_negative_insert
            BEFORE INSERT ON lo_san_phams
            FOR EACH ROW
            WHEN NEW.so_luong_ton < 0 OR NEW.muc_canh_bao < 0
            BEGIN
                SELECT RAISE(ABORT, 'lo_san_phams_non_negative_check');
            END;
        ");

        DB::unprepared("
            CREATE TRIGGER lo_san_phams_non_negative_update
            BEFORE UPDATE ON lo_san_phams
            FOR EACH ROW
            WHEN NEW.so_luong_ton < 0 OR NEW.muc_canh_bao < 0
            BEGIN
                SELECT RAISE(ABORT, 'lo_san_phams_non_negative_check');
            END;
        ");
    }

    protected function dropSqliteGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS san_phams_non_negative_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS san_phams_non_negative_update');
        DB::unprepared('DROP TRIGGER IF EXISTS lo_san_phams_non_negative_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS lo_san_phams_non_negative_update');
    }

    protected function createMysqlChecks(): void
    {
        DB::statement('ALTER TABLE san_phams ADD CONSTRAINT chk_san_phams_gia_non_negative CHECK (gia >= 0)');
        DB::statement('ALTER TABLE san_phams ADD CONSTRAINT chk_san_phams_so_luong_ton_non_negative CHECK (so_luong_ton >= 0)');
        DB::statement('ALTER TABLE san_phams ADD CONSTRAINT chk_san_phams_muc_canh_bao_non_negative CHECK (muc_canh_bao >= 0)');
        DB::statement('ALTER TABLE lo_san_phams ADD CONSTRAINT chk_lo_san_phams_so_luong_ton_non_negative CHECK (so_luong_ton >= 0)');
        DB::statement('ALTER TABLE lo_san_phams ADD CONSTRAINT chk_lo_san_phams_muc_canh_bao_non_negative CHECK (muc_canh_bao >= 0)');
    }

    protected function dropMysqlChecks(): void
    {
        DB::statement('ALTER TABLE san_phams DROP CHECK chk_san_phams_gia_non_negative');
        DB::statement('ALTER TABLE san_phams DROP CHECK chk_san_phams_so_luong_ton_non_negative');
        DB::statement('ALTER TABLE san_phams DROP CHECK chk_san_phams_muc_canh_bao_non_negative');
        DB::statement('ALTER TABLE lo_san_phams DROP CHECK chk_lo_san_phams_so_luong_ton_non_negative');
        DB::statement('ALTER TABLE lo_san_phams DROP CHECK chk_lo_san_phams_muc_canh_bao_non_negative');
    }

    protected function createPostgresChecks(): void
    {
        DB::statement('ALTER TABLE san_phams ADD CONSTRAINT chk_san_phams_gia_non_negative CHECK (gia >= 0)');
        DB::statement('ALTER TABLE san_phams ADD CONSTRAINT chk_san_phams_so_luong_ton_non_negative CHECK (so_luong_ton >= 0)');
        DB::statement('ALTER TABLE san_phams ADD CONSTRAINT chk_san_phams_muc_canh_bao_non_negative CHECK (muc_canh_bao >= 0)');
        DB::statement('ALTER TABLE lo_san_phams ADD CONSTRAINT chk_lo_san_phams_so_luong_ton_non_negative CHECK (so_luong_ton >= 0)');
        DB::statement('ALTER TABLE lo_san_phams ADD CONSTRAINT chk_lo_san_phams_muc_canh_bao_non_negative CHECK (muc_canh_bao >= 0)');
    }

    protected function dropPostgresChecks(): void
    {
        DB::statement('ALTER TABLE san_phams DROP CONSTRAINT IF EXISTS chk_san_phams_gia_non_negative');
        DB::statement('ALTER TABLE san_phams DROP CONSTRAINT IF EXISTS chk_san_phams_so_luong_ton_non_negative');
        DB::statement('ALTER TABLE san_phams DROP CONSTRAINT IF EXISTS chk_san_phams_muc_canh_bao_non_negative');
        DB::statement('ALTER TABLE lo_san_phams DROP CONSTRAINT IF EXISTS chk_lo_san_phams_so_luong_ton_non_negative');
        DB::statement('ALTER TABLE lo_san_phams DROP CONSTRAINT IF EXISTS chk_lo_san_phams_muc_canh_bao_non_negative');
    }
};
