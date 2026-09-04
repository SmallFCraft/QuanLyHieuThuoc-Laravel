<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Từ khoá khách đã tìm, một dòng mỗi từ khoá — không phải mỗi lượt tìm.
     *
     * Nguồn cho khối "Xu hướng tìm kiếm" ở panel tìm kiếm. Chỉ giữ dạng đã gộp nên
     * bảng đứng yên ở cỡ vài nghìn dòng dù traffic tăng, và không lưu gì gắn với
     * một người cụ thể: không MaKH, không IP, không user agent.
     */
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();

            // Khoá gộp là dạng đã fold: "Vitamin C", "vitamin c" và "vitamin  C"
            // là cùng một xu hướng, không phải ba.
            $table->string('term_folded')->unique();

            // Dạng khách gõ lần đầu, dùng để hiện pill — "omega 3" đọc dễ hơn
            // "omega 3" đã bị lột dấu ở những từ có dấu.
            $table->string('term');

            $table->unsignedInteger('hits')->default(1);
            $table->timestamp('last_searched_at');

            // Xu hướng sắp theo hits trong cửa sổ last_searched_at, nên hai cột này
            // luôn được đọc cùng nhau.
            $table->index(['last_searched_at', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
