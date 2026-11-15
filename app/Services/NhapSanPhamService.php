<?php

namespace App\Services;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Support\LongChauProduct;
use App\Support\VietnameseText;
use Illuminate\Support\Facades\DB;

/**
 * Ghi dữ liệu catalog crawled vào san_phams và danh_mucs.
 *
 * Hai lớp dữ liệu gặp nhau tại đây. Các field catalog (tên, giá, thành phần, cách dùng,
 * ảnh, danh mục) thuộc về nguồn và được làm mới mỗi lần crawl. Các field tồn kho
 * (số lô, hạn dùng, số lượng) thuộc về nhà thuốc: lần nhập đầu tiên seed một giá trị
 * cho để dòng hoàn chỉnh, và các lần nhập sau để nguyên những gì nhân viên đã nhập.
 */
class NhapSanPhamService
{
    /** Tồn kho cho trước, chỉ được ghi khi sản phẩm được tạo lần đầu. */
    private const SEED_STOCK_MIN = 0;
    private const SEED_STOCK_MAX = 200;
    private const SEED_REORDER_MIN = 10;
    private const SEED_REORDER_MAX = 30;

    /** @var array<string, int> tên danh mục đã fold => ma_dm, tránh một lookup cho mỗi sản phẩm */
    private array $maDanhMuc = [];

    /** @var array<string> tên hiển thị của các danh mục lượt chạy này phải tạo */
    private array $danhMucMoi = [];

    /**
     * Tạo hoặc làm mới một sản phẩm. An toàn để gọi nhiều lần cho cùng bản ghi nguồn:
     * nó phân giải về cùng một dòng.
     */
    public function nhap(LongChauProduct $source): SanPham
    {
        return DB::transaction(function () use ($source) {
            $categoryId = $this->phanGiaiMaDanhMuc($source->categoryName);
            $batchNumber = $source->batchNumber();

            $sanPham = SanPham::withTrashed()->firstWhere('so_lo', $batchNumber);

            if ($sanPham === null) {
                return $this->tao($source, $categoryId, $batchNumber);
            }

            return $this->lamMoi($sanPham, $source, $categoryId);
        });
    }

    /**
     * Reset cache danh mục theo từng lượt chạy. Các lần crawl dài nếu không sẽ giữ
     * những id mà quản trị viên có thể đã xóa giữa lượt chạy.
     */
    public function quenDanhMuc(): void
    {
        $this->maDanhMuc = [];
        $this->danhMucMoi = [];
    }

    /**
     * Tên hiển thị của các danh mục lượt chạy này đã tạo, để lệnh có thể báo cáo
     * chúng. Một lần crawl mù quáng tạo danh mục là nguyên nhân đầu tiên làm catalog
     * drift thành hai dòng "Cơ xương khớp".
     *
     * @return array<string>
     */
    public function danhMucMoi(): array
    {
        return array_values($this->danhMucMoi);
    }

    private function tao(LongChauProduct $source, int $categoryId, string $batchNumber): SanPham
    {
        // ProductObserver::created() đọc so_lo và ngay_het_han để dựng dòng lo_san_phams
        // tương ứng, nên cả hai phải được gán khi insert.
        return SanPham::create([
            'ma_dm' => $categoryId,
            'ten' => $source->name,
            'thanh_phan' => $source->ingredients,
            'cach_dung' => $source->usage,
            'han_dung_thang' => $source->shelfLifeMonths,
            'gia' => $source->price,
            'hinh_anh' => $source->imageUrl,
            'so_lo' => $batchNumber,
            'ngay_het_han' => $source->expirationDate(),
            'so_luong_ton' => random_int(self::SEED_STOCK_MIN, self::SEED_STOCK_MAX),
            'muc_canh_bao' => random_int(self::SEED_REORDER_MIN, self::SEED_REORDER_MAX),
        ]);
    }

    /**
     * Chỉ các field catalog. Số lượng tồn kho, điểm đặt lại hàng và ngày hết hạn được
     * để nguyên vì nhà thuốc có thể đã sửa chúng từ lần crawl trước, và ghi đè tồn kho
     * thật bằng một con số ngẫu nhiên sẽ làm hỏng FEFO.
     */
    private function lamMoi(SanPham $sanPham, LongChauProduct $source, int $categoryId): SanPham
    {
        if ($sanPham->trashed()) {
            $sanPham->restore();
        }

        $sanPham->fill([
            'ma_dm' => $categoryId,
            'ten' => $source->name,
            'thanh_phan' => $source->ingredients,
            'cach_dung' => $source->usage,
            'han_dung_thang' => $source->shelfLifeMonths,
            'gia' => $source->price,
            'hinh_anh' => $source->imageUrl,
        ])->save();

        return $sanPham;
    }

    /**
     * Danh mục đến từ breadcrumb của chính Long Châu. Khớp trên tên đã fold, chứ không
     * phải tên thô: nguồn phát hành cùng breadcrumb cấp 2 như cả hai "Cơ - xương - khớp"
     * (thuốc Thuốc) và "Cơ xương khớp" (thuốc Thực phẩm chức
     * năng), và khớp theo tên thô đã làm crawl tạo một danh mục cho mỗi cách viết, điều
     * đó chia 114 sản phẩm thành hai dòng trên storefront mà khách đọc như một.
     *
     * Cách viết đầu tiên được thấy giữ tên hiển thị; DanhMuc::booted() điền mã
     * và cột folded.
     */
    private function phanGiaiMaDanhMuc(string $name): int
    {
        $folded = VietnameseText::fold($name);

        if (isset($this->maDanhMuc[$folded])) {
            return $this->maDanhMuc[$folded];
        }

        $danhMuc = DanhMuc::firstOrCreate(['ten_khong_dau' => $folded], ['ten' => $name]);

        if ($danhMuc->wasRecentlyCreated) {
            $this->danhMucMoi[$folded] = $danhMuc->ten;
        }

        return $this->maDanhMuc[$folded] = (int) $danhMuc->ma_dm;
    }
}
