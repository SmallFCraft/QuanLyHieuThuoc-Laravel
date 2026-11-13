<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index phien am dung san tu catalog, mot dong moi cum tra cuu duoc.
     *
     * La bang chu khong phai cache vi CACHE_STORE=database: nap ca index ra roi
     * doc lai moi request nghia la moi luot voice keo ~150KB tu bang `cache`. Mot
     * whereIn tren cot co index doc vai chuc dong.
     *
     * Du lieu o day la dan xuat — xoa di roi chay `voice:build-phonetics` la dung
     * lai duoc toan bo.
     */
    public function up(): void
    {
        Schema::create('phien_am_giong_nois', function (Blueprint $table) {
            $table->id();

            // Khoa phien am giu dau cach giua cac am tiet: luat so cua
            // VoicePhonetics::within() can so tung am tiet, vi mot nguong
            // Levenshtein tren khoa noi lien gop "vitamin C" voi "vitamin D".
            // Khong index rieng: unique (phonetic_key, term_folded) ben duoi da
            // phu leading-prefix, moi lookup phonetic_key chay qua no.
            $this->caseSensitive($table->string('phonetic_key', 191));

            // Hai ky tu dau cua khoa da bo dau cach. Bo loc tho lam trong SQL cho
            // tang khop gan — within() la ham PHP, khong phai vi tu SQL, nen khong
            // co cot nay thi phai nap ca bang ra PHP moi request.
            // null voi cum dai: ten san pham day du khong bao gio la ung vien khop
            // GAN cua mot cau noi ngan.
            $this->caseSensitive($table->string('block_key', 2)->nullable())->index();

            $table->string('term', 191);
            $table->string('term_folded', 191);
            $table->enum('kind', ['product', 'category', 'token']);

            // So san pham chua cum nay. Chi de xep thu tu giua cac ung vien da hop
            // le, khong de quyet dinh nhan hay loai.
            $table->unsignedInteger('weight')->default(0);

            $table->unique(['phonetic_key', 'term_folded']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phien_am_giong_nois');
    }

    /**
     * MySQL mac dinh utf8mb4_0900_ai_ci — KHONG phan biet hoa/thuong. Khoa phien
     * am dung chu hoa de ma am gop (`S`, `C`, `D`, `N`, `T`, `U`…) ben canh chu
     * thuong cua nguyen am goc, nen hoa/thuong LA thong tin: do duoc 65 nhom khoa
     * bi nhap mot khi de mac dinh, vi du `thUT` ("thuoc") voi `thuT` ("thut").
     *
     * SQLite mac dinh da la BINARY va tu choi ten collation cua MySQL
     * ("no such collation sequence"), ma bo test chay tren sqlite :memory: —
     * nen nhanh nay bat buoc, khong phai phong xa.
     */
    private function caseSensitive(\Illuminate\Database\Schema\ColumnDefinition $column): \Illuminate\Database\Schema\ColumnDefinition
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $column->collation('utf8mb4_bin');
        }

        return $column;
    }
};
