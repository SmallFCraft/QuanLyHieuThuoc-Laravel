<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index phiên âm dựng sẵn từ catalog, một dòng mỗi cụm tra cứu được.
     *
     * Là bảng chứ không phải cache vì CACHE_STORE=database: nạp cả index ra rồi
     * đọc lại mỗi request nghĩa là mỗi lượt voice kéo ~150KB từ bảng `cache`. Một
     * whereIn trên cột có index đọc vài chục dòng.
     *
     * Dữ liệu ở đây là dẫn xuất — xoá đi rồi chạy `voice:build-phonetics` là dựng
     * lại được toàn bộ.
     */
    public function up(): void
    {
        Schema::create('voice_phonetics', function (Blueprint $table) {
            $table->id();

            // Khoá phiên âm giữ dấu cách giữa các âm tiết: luật so của
            // VoicePhonetics::within() cần so từng âm tiết, vì một ngưỡng
            // Levenshtein trên khoá nối liền gộp "vitamin C" với "vitamin D".
            // Không index riêng: unique (phonetic_key, term_folded) bên dưới đã
            // phủ leading-prefix, mọi lookup phonetic_key chạy qua nó.
            $this->caseSensitive($table->string('phonetic_key', 191));

            // Hai ký tự đầu của khoá đã bỏ dấu cách. Bộ lọc thô làm trong SQL cho
            // tầng khớp gần — within() là hàm PHP, không phải vị từ SQL, nên không
            // có cột này thì phải nạp cả bảng ra PHP mỗi request.
            // null với cụm dài: tên sản phẩm đầy đủ không bao giờ là ứng viên khớp
            // GẦN của một câu nói ngắn.
            $this->caseSensitive($table->string('block_key', 2)->nullable())->index();

            $table->string('term', 191);
            $table->string('term_folded', 191);
            $table->enum('kind', ['product', 'category', 'token']);

            // Số sản phẩm chứa cụm này. Chỉ để xếp thứ tự giữa các ứng viên đã hợp
            // lệ, không để quyết định nhận hay loại.
            $table->unsignedInteger('weight')->default(0);

            $table->unique(['phonetic_key', 'term_folded']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_phonetics');
    }

    /**
     * MySQL mặc định utf8mb4_0900_ai_ci — KHÔNG phân biệt hoa/thường. Khoá phiên
     * âm dùng chữ hoa để mã âm gộp (`S`, `C`, `D`, `N`, `T`, `U`…) bên cạnh chữ
     * thường của nguyên âm gốc, nên hoa/thường LÀ thông tin: đo được 65 nhóm khoá
     * bị nhập một khi để mặc định, ví dụ `thUT` ("thuốc") với `thuT` ("thut").
     *
     * SQLite mặc định đã là BINARY và từ chối tên collation của MySQL
     * ("no such collation sequence"), mà bộ test chạy trên sqlite :memory: —
     * nên nhánh này bắt buộc, không phải phòng xa.
     */
    private function caseSensitive(\Illuminate\Database\Schema\ColumnDefinition $column): \Illuminate\Database\Schema\ColumnDefinition
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $column->collation('utf8mb4_bin');
        }

        return $column;
    }
};
