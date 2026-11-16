<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tu khoa khach da tim, mot dong moi tu khoa — khong phai moi luot tim.
     *
     * Nguon cho khoi "Xu huong tim kiem" o panel tim kiem. Chi giu dang da gop nen
     * bang dung yen o co vai nghin dong du traffic tang, va khong luu gi gan voi
     * mot nguoi cu the: khong MaKH, khong IP, khong user agent.
     */
    public function up(): void
    {
        Schema::create('truy_van_tim_kiems', function (Blueprint $table) {
            $table->id();

            // Khoa gop la dang da fold: "Vitamin C", "vitamin c" va "vitamin  C"
            // la cung mot xu huong, khong phai ba.
            $table->string('term_folded')->unique();

            // Dang khach go lan dau, dung de hien pill — "omega 3" doc de hon
            // "omega 3" da bi lot dau o nhung tu co dau.
            $table->string('term');

            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('last_searched_at');

            // Xu huong sap theo hits trong cua so last_searched_at, nen hai cot nay
            // luon duoc doc cung nhau.
            $table->index(['last_searched_at', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truy_van_tim_kiems');
    }
};
