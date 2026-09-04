<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One product as published by Nhà Thuốc Long Châu.
 *
 * Long Châu runs Next.js with server-side rendering, so each product page embeds
 * the whole product record as JSON in a __NEXT_DATA__ script tag. This class maps
 * that payload onto the columns this project stores and rejects anything it cannot
 * store truthfully. It performs no I/O, so it is unit-testable against saved HTML.
 */
final class LongChauProduct
{
    /** products.name and products.image are both VARCHAR(255). */
    private const MAX_NAME = 255;
    private const MAX_URL = 255;

    /** products.price is DECIMAL(10,2) and the admin form caps it at 99,999,999. */
    private const MAX_PRICE = 99_999_999;

    /**
     * Used to date the placeholder batch when the manufacturer publishes no shelf
     * life. It is never surfaced as `shelfLifeMonths`, so the storefront cannot
     * present a fallback as if the manufacturer had stated it.
     */
    private const DEFAULT_SHELF_LIFE_MONTHS = 24;

    private function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $price,
        public readonly string $categoryName,
        public readonly ?string $ingredients,
        public readonly ?string $usage,
        public readonly ?string $imageUrl,
        /** Months from manufacture, as published. Null when the source states none. */
        public readonly ?int $shelfLifeMonths,
        public readonly bool $prescription,
    ) {
    }

    /**
     * Pull the Next.js payload out of a fetched page.
     *
     * Returns null when the tag is absent, which separates "Long Châu changed its
     * page structure" from "this product is unusable" — the crawler counts those
     * two outcomes differently.
     */
    public static function extractNextData(string $html): ?array
    {
        if (! preg_match(
            '#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s',
            $html,
            $matches
        )) {
            return null;
        }

        $decoded = json_decode($matches[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function fromHtml(string $html): ?self
    {
        $data = self::extractNextData($html);

        return $data === null ? null : self::fromNextData($data);
    }

    /**
     * Map the payload, or return null when the product cannot be stored as-is.
     */
    public static function fromNextData(array $data): ?self
    {
        $product = data_get($data, 'props.pageProps.product');

        if (! is_array($product)) {
            return null;
        }

        $sku = trim((string) ($product['sku'] ?? ''));
        $name = self::pickName($product);
        $category = self::pickCategoryName($product);
        $price = self::pickPrice($product['prices'] ?? []);

        if ($sku === '' || $name === '' || $category === null || $price === null) {
            return null;
        }

        return new self(
            sku: $sku,
            name: Str::limit($name, self::MAX_NAME, ''),
            price: $price,
            categoryName: $category,
            ingredients: self::formatIngredients($product['ingredient'] ?? []),
            usage: self::formatUsage($product),
            imageUrl: self::pickImageUrl($product),
            shelfLifeMonths: self::parseShelfLifeMonths($product['expirationDate'] ?? null),
            prescription: (bool) ($product['prescription'] ?? false),
        );
    }

    /**
     * Derived from the SKU alone, deliberately without a date: a later crawl has to
     * resolve to the same batch, and batch_number is what the importer matches on.
     * Folding the crawl month in would make every month's run insert a duplicate
     * product. This placeholder batch exists so the row is complete; real batches
     * are entered by staff when stock arrives.
     */
    public function batchNumber(): string
    {
        return 'LC-' . $this->sku;
    }

    /**
     * Dates the placeholder batch. Long Châu publishes shelf life from manufacture
     * ("36 tháng"), never a batch expiry, so this is inferred — it exists to keep
     * the batch row complete until staff enter real stock, and falls back to a
     * default when nothing was published.
     */
    public function expirationDate(?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())
            ->copy()
            ->startOfDay()
            ->addMonths($this->shelfLifeMonths ?? self::DEFAULT_SHELF_LIFE_MONTHS);
    }

    private static function pickName(array $product): string
    {
        foreach (['webName', 'officialProductName', 'name'] as $key) {
            $name = trim((string) ($product[$key] ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * Level 2 of Long Châu's breadcrumb is the useful granularity: level 1 is a
     * storefront section ("Thuốc") and level 3 splits too finely to browse.
     */
    private static function pickCategoryName(array $product): ?string
    {
        $categories = collect($product['categories'] ?? [])
            ->filter(fn ($category) => is_array($category) && filled($category['name'] ?? null));

        $name = $categories->firstWhere('level', 2)['name']
            ?? $categories->firstWhere('level', 1)['name']
            ?? null;

        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }

    /**
     * The row describes one box (`specification` reads "Hộp 15 Vỉ x 12 Viên"), so
     * the box price is the one that matches it. Prescription drugs carry null on
     * every unit — Vietnamese law bars publishing their price online — and those
     * products are dropped rather than given an invented number.
     */
    private static function pickPrice(mixed $prices): ?int
    {
        if (! is_array($prices)) {
            return null;
        }

        $price = collect($prices)
            ->filter(fn ($row) => is_array($row) && is_numeric($row['price'] ?? null))
            ->sortBy(fn ($row) => (int) ($row['level'] ?? PHP_INT_MAX))
            ->value('price');

        if ($price === null) {
            return null;
        }

        $price = (int) round((float) $price);

        return ($price > 0 && $price <= self::MAX_PRICE) ? $price : null;
    }

    /**
     * "Caffeine 65mg, Paracetamol 500mg" — the shape DrugSafetyService expects
     * when it tokenises products.ingredients for interaction and allergy checks.
     */
    private static function formatIngredients(mixed $ingredients): ?string
    {
        if (! is_array($ingredients)) {
            return null;
        }

        $formatted = collect($ingredients)
            ->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null))
            ->map(function (array $row) {
                $name = trim((string) $row['name']);
                $dose = trim((string) ($row['shortDescription'] ?? ''));

                return $dose === '' ? $name : "{$name} {$dose}";
            })
            ->unique()
            ->implode(', ');

        return $formatted === '' ? null : $formatted;
    }

    /**
     * Indications and dosage arrive as separate HTML blocks. Both are flattened to
     * text because the storefront renders products.usage through Blade's escaping
     * `{{ }}`, which would otherwise show the raw tags to customers.
     */
    private static function formatUsage(array $product): ?string
    {
        $usage = collect([$product['usage'] ?? null, $product['dosage'] ?? null])
            ->filter(fn ($block) => is_string($block) && trim($block) !== '')
            ->map(fn (string $block) => self::htmlToText($block))
            ->filter()
            ->implode("\n\n");

        return $usage === '' ? null : $usage;
    }

    private static function htmlToText(string $html): string
    {
        // Block boundaries become newlines before the tags go, otherwise list items
        // and headings run together into one unreadable paragraph.
        $text = preg_replace('#</(p|div|li|ul|ol|h[1-6]|tr|table)>#i', "\n", $html);
        $text = preg_replace('#<br\s*/?>#i', "\n", (string) $text);
        $text = preg_replace('#<(h[1-6])[^>]*>#i', "\n", (string) $text);

        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Entity decoding can reveal tags that were escaped in the source, and
        // non-breaking spaces survive as U+00A0.
        $text = strip_tags($text);
        $text = str_replace(["\u{00A0}", "\r"], [' ', ''], $text);

        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ ?\n ?/u', "\n", (string) $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", (string) $text);

        return trim((string) $text);
    }

    /**
     * Long Châu's own image resizer. The originals under /v1/static/ are 1.5-3.5 MB
     * PNG/JPEG masters; the same file through this endpoint is webp at 40-100 KB,
     * which is what a product card and a detail page actually need.
     */
    private const CDN_HOST = 'https://cdn.nhathuoclongchau.com.vn';
    private const CDN_ORIGIN_PREFIX = self::CDN_HOST . '/v1/static/';
    private const CDN_RESIZE_PREFIX = self::CDN_HOST . '/unsafe/600x0/filters:quality(90):format(webp)/';

    /**
     * Only absolute URLs are kept: Product::getImageUrlAttribute() returns http(s)
     * values untouched but resolves anything else against the local public disk,
     * where a Long Châu path does not exist.
     */
    private static function pickImageUrl(array $product): ?string
    {
        $url = trim((string) data_get($product, 'primaryImage.url', ''));

        if ($url === '' || ! Str::startsWith($url, ['http://', 'https://'])) {
            return null;
        }

        $url = self::resizedImageUrl($url);

        return mb_strlen($url) > self::MAX_URL ? null : $url;
    }

    /**
     * Rewrite a stored master image to its resized variant. Anything that is not a
     * plain /v1/static/ original — another host, or a URL already carrying resize
     * filters — is left alone, because serving the original beats serving nothing.
     *
     * The path after /v1/static/ is kept whole: the resizer returns HTTP 500 when a
     * subfolder such as smalls/ is dropped.
     */
    private static function resizedImageUrl(string $url): string
    {
        if (! Str::startsWith($url, self::CDN_ORIGIN_PREFIX)) {
            return $url;
        }

        return self::CDN_RESIZE_PREFIX . Str::after($url, self::CDN_ORIGIN_PREFIX);
    }

    /**
     * Reads figures like "36 tháng" or "02 năm".
     *
     * Returns null rather than a default when nothing usable is published: this
     * value is shown to customers as the manufacturer's figure, so a guess must not
     * be dressed up as one.
     */
    private static function parseShelfLifeMonths(mixed $raw): ?int
    {
        if (! is_string($raw) || ! preg_match('/(\d+)/', $raw, $matches)) {
            return null;
        }

        $value = (int) $matches[1];

        if ($value <= 0) {
            return null;
        }

        $months = Str::contains(Str::lower($raw), ['năm', 'nam', 'year']) ? $value * 12 : $value;

        // Guards against a stray figure such as "2 tuần" parsed as months or a
        // typo like "360 tháng" producing an expiry date decades out.
        return ($months >= 1 && $months <= 120) ? $months : null;
    }
}
