<?php

namespace App\Services;

use App\Models\DanhMuc;
use App\Models\SanPham;
use App\Support\LongChauProduct;
use App\Support\VietnameseText;
use Illuminate\Support\Facades\DB;

/**
 * Writes crawled catalog data into san_phams and danh_mucs.
 *
 * Two data layers meet here. Catalog fields (name, price, ingredients, usage,
 * image, category) belong to the source and are refreshed on every crawl. Stock
 * fields (batch, expiry, quantities) belong to the pharmacy: the first import
 * seeds a placeholder so the row is complete, and later imports leave whatever
 * staff entered alone.
 */
class ProductImportService
{
    /** Placeholder stock, only ever written when a product is first created. */
    private const SEED_STOCK_MIN = 0;
    private const SEED_STOCK_MAX = 200;
    private const SEED_REORDER_MIN = 10;
    private const SEED_REORDER_MAX = 30;

    /** @var array<string, int> folded category name => ma_dm, avoiding a lookup per product */
    private array $categoryIds = [];

    /** @var array<string> display names of the categories this run had to create */
    private array $newCategories = [];

    /**
     * Create or refresh one product. Safe to call repeatedly for the same source
     * record: it resolves to the same row.
     */
    public function import(LongChauProduct $source): SanPham
    {
        return DB::transaction(function () use ($source) {
            $categoryId = $this->resolveCategoryId($source->categoryName);
            $batchNumber = $source->batchNumber();

            $sanPham = SanPham::withTrashed()->firstWhere('so_lo', $batchNumber);

            if ($sanPham === null) {
                return $this->create($source, $categoryId, $batchNumber);
            }

            return $this->refresh($sanPham, $source, $categoryId);
        });
    }

    /**
     * Reset the per-run category cache. Long crawls otherwise hold ids that an
     * admin may have deleted mid-run.
     */
    public function forgetCategories(): void
    {
        $this->categoryIds = [];
        $this->newCategories = [];
    }

    /**
     * Display names of the categories this run created, so the command can report
     * them. A crawl that silently invents categories is how the catalog drifted into
     * two "Cơ xương khớp" entries in the first place.
     *
     * @return array<string>
     */
    public function newCategories(): array
    {
        return array_values($this->newCategories);
    }

    private function create(LongChauProduct $source, int $categoryId, string $batchNumber): SanPham
    {
        // ProductObserver::created() reads so_lo and ngay_het_han to build
        // the matching lo_san_phams row, so both must be set on insert.
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
     * Catalog fields only. Stock quantities, reorder points and expiry dates are
     * left untouched because a pharmacist may have corrected them since the last
     * crawl, and overwriting real stock with a random figure would corrupt FEFO.
     */
    private function refresh(SanPham $sanPham, LongChauProduct $source, int $categoryId): SanPham
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
     * Categories come from Long Châu's own breadcrumb. Matched on the folded name,
     * not the raw one: the source publishes the same level-2 breadcrumb as both
     * "Cơ - xương - khớp" (under Thuốc) and "Cơ xương khớp" (under Thực phẩm chức
     * năng), and matching raw made the crawl create a category per spelling, which
     * split 114 products across two storefront entries a customer reads as one.
     *
     * The first spelling seen keeps the display name; DanhMuc::booted() fills in the
     * code and the folded column.
     */
    private function resolveCategoryId(string $name): int
    {
        $folded = VietnameseText::fold($name);

        if (isset($this->categoryIds[$folded])) {
            return $this->categoryIds[$folded];
        }

        $danhMuc = DanhMuc::firstOrCreate(['ten_khong_dau' => $folded], ['ten' => $name]);

        if ($danhMuc->wasRecentlyCreated) {
            $this->newCategories[$folded] = $danhMuc->ten;
        }

        return $this->categoryIds[$folded] = (int) $danhMuc->ma_dm;
    }
}
