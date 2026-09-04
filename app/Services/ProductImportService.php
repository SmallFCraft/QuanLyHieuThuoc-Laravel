<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\LongChauProduct;
use App\Support\VietnameseText;
use Illuminate\Support\Facades\DB;

/**
 * Writes crawled catalog data into products and categories.
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

    /** @var array<string, int> folded category name => MaDM, avoiding a lookup per product */
    private array $categoryIds = [];

    /** @var array<string> display names of the categories this run had to create */
    private array $newCategories = [];

    /**
     * Create or refresh one product. Safe to call repeatedly for the same source
     * record: it resolves to the same row.
     */
    public function import(LongChauProduct $source): Product
    {
        return DB::transaction(function () use ($source) {
            $categoryId = $this->resolveCategoryId($source->categoryName);
            $batchNumber = $source->batchNumber();

            $product = Product::withTrashed()->firstWhere('batch_number', $batchNumber);

            if ($product === null) {
                return $this->create($source, $categoryId, $batchNumber);
            }

            return $this->refresh($product, $source, $categoryId);
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

    private function create(LongChauProduct $source, int $categoryId, string $batchNumber): Product
    {
        // ProductObserver::created() reads batch_number and expiration_date to build
        // the matching product_batches row, so both must be set on insert.
        return Product::create([
            'MaDM' => $categoryId,
            'name' => $source->name,
            'ingredients' => $source->ingredients,
            'usage' => $source->usage,
            'shelf_life_months' => $source->shelfLifeMonths,
            'price' => $source->price,
            'image' => $source->imageUrl,
            'batch_number' => $batchNumber,
            'expiration_date' => $source->expirationDate(),
            'stock_quantity' => random_int(self::SEED_STOCK_MIN, self::SEED_STOCK_MAX),
            'reorder_point' => random_int(self::SEED_REORDER_MIN, self::SEED_REORDER_MAX),
        ]);
    }

    /**
     * Catalog fields only. Stock quantities, reorder points and expiry dates are
     * left untouched because a pharmacist may have corrected them since the last
     * crawl, and overwriting real stock with a random figure would corrupt FEFO.
     */
    private function refresh(Product $product, LongChauProduct $source, int $categoryId): Product
    {
        if ($product->trashed()) {
            $product->restore();
        }

        $product->fill([
            'MaDM' => $categoryId,
            'name' => $source->name,
            'ingredients' => $source->ingredients,
            'usage' => $source->usage,
            'shelf_life_months' => $source->shelfLifeMonths,
            'price' => $source->price,
            'image' => $source->imageUrl,
        ])->save();

        return $product;
    }

    /**
     * Categories come from Long Châu's own breadcrumb. Matched on the folded name,
     * not the raw one: the source publishes the same level-2 breadcrumb as both
     * "Cơ - xương - khớp" (under Thuốc) and "Cơ xương khớp" (under Thực phẩm chức
     * năng), and matching raw made the crawl create a category per spelling, which
     * split 114 products across two storefront entries a customer reads as one.
     *
     * The first spelling seen keeps the display name; Category::booted() fills in the
     * code and the folded column.
     */
    private function resolveCategoryId(string $name): int
    {
        $folded = VietnameseText::fold($name);

        if (isset($this->categoryIds[$folded])) {
            return $this->categoryIds[$folded];
        }

        $category = Category::firstOrCreate(['name_folded' => $folded], ['name' => $name]);

        if ($category->wasRecentlyCreated) {
            $this->newCategories[$folded] = $category->name;
        }

        return $this->categoryIds[$folded] = (int) $category->MaDM;
    }
}
