<?php

namespace App\Services;

use App\Exceptions\CrawlerSourceChangedException;
use App\Support\LongChauProduct;
use Closure;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fetches the Nhà Thuốc Long Châu catalog.
 *
 * Long Châu serves rendered pages, so plain HTTP is enough — no browser and no AI
 * extraction. Sitemaps supply the URL list, and each page carries its product as
 * JSON, which LongChauProduct maps.
 */
class LongChauCrawlerService
{
    private string $baseUrl;
    private array $sitemaps;
    private string $userAgent;
    private int $timeout;
    private int $connectTimeout;
    private int $concurrency;
    private int $delayMs;

    public function __construct()
    {
        $config = config('crawler.longchau');

        $this->baseUrl = rtrim((string) $config['base_url'], '/');
        $this->sitemaps = (array) $config['sitemaps'];
        $this->userAgent = (string) $config['user_agent'];
        $this->timeout = max(1, (int) $config['timeout']);
        $this->connectTimeout = max(1, (int) $config['connect_timeout']);
        $this->concurrency = max(1, (int) $config['concurrency']);
        $this->delayMs = max(0, (int) $config['delay_ms']);
    }

    /**
     * @return array<string> the sitemap keys accepted by productUrls()
     */
    public function availableSitemaps(): array
    {
        return array_keys($this->sitemaps);
    }

    /**
     * Product URLs from the named sitemaps, de-duplicated and in sitemap order.
     *
     * @param  array<string>  $keys
     * @return array<string>
     */
    public function productUrls(array $keys): array
    {
        $unknown = array_diff($keys, $this->availableSitemaps());

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown sitemap: ' . implode(', ', $unknown)
                . '. Available: ' . implode(', ', $this->availableSitemaps())
            );
        }

        $urls = [];

        foreach ($keys as $key) {
            $response = $this->request()->get($this->baseUrl . '/' . $this->sitemaps[$key]);

            if (! $response->successful()) {
                Log::warning('Long Châu sitemap could not be read', [
                    'sitemap' => $this->sitemaps[$key],
                    'status' => $response->status(),
                ]);

                continue;
            }

            foreach ($this->parseSitemap($response->body()) as $url) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * Fetch each URL and hand every usable product to the callback.
     *
     * Pages are fetched in small concurrent batches with a pause in between. A page
     * that fails is counted and skipped: one dead URL out of thousands must not end
     * the run.
     *
     * @param  array<string>  $urls
     * @param  Closure(LongChauProduct, string): void  $onProduct
     * @return array{fetched:int, imported:int, unusable:int, failed:int, no_json:int}
     */
    public function eachProduct(array $urls, Closure $onProduct): array
    {
        $stats = ['fetched' => 0, 'imported' => 0, 'unusable' => 0, 'failed' => 0, 'no_json' => 0];
        $batches = array_chunk($urls, $this->concurrency);
        $lastIndex = count($batches) - 1;

        foreach ($batches as $index => $batch) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $url) => $this->pooled($pool, $url),
                $batch
            ));

            foreach ($batch as $position => $url) {
                $stats['fetched']++;
                $this->consume($responses[$position] ?? null, $url, $stats, $onProduct);
            }

            $this->guardAgainstStructureChange($stats);

            if ($this->delayMs > 0 && $index < $lastIndex) {
                usleep($this->delayMs * 1000);
            }
        }

        return $stats;
    }

    /**
     * @param  array{fetched:int, imported:int, unusable:int, failed:int, no_json:int}  $stats
     */
    private function consume(mixed $response, string $url, array &$stats, Closure $onProduct): void
    {
        if (! $response instanceof Response) {
            $stats['failed']++;

            Log::warning('Long Châu page request threw', [
                'url' => $url,
                'error' => $response instanceof \Throwable ? $response->getMessage() : 'unknown',
            ]);

            return;
        }

        if (! $response->successful()) {
            $stats['failed']++;

            return;
        }

        $data = LongChauProduct::extractNextData($response->body());

        if ($data === null) {
            $stats['no_json']++;

            return;
        }

        $product = LongChauProduct::fromNextData($data);

        if ($product === null) {
            $stats['unusable']++;

            return;
        }

        $stats['imported']++;
        $onProduct($product, $url);
    }

    /**
     * Isolated pages without __NEXT_DATA__ are dead URLs. A large share means Long
     * Châu changed how it renders, and continuing would import nothing while
     * reporting success — so the run stops and says so.
     *
     * @param  array{fetched:int, no_json:int}  $stats
     */
    private function guardAgainstStructureChange(array $stats): void
    {
        $floor = (int) config('crawler.longchau.missing_json_guard_floor');
        $ratio = (float) config('crawler.longchau.max_missing_json_ratio');

        if ($stats['fetched'] < $floor || $stats['fetched'] === 0) {
            return;
        }

        if ($stats['no_json'] / $stats['fetched'] <= $ratio) {
            return;
        }

        throw new CrawlerSourceChangedException(sprintf(
            '%d of %d Long Châu pages had no __NEXT_DATA__ payload. The site layout '
            . 'likely changed; review the field mapping before importing.',
            $stats['no_json'],
            $stats['fetched']
        ));
    }

    private function pooled(Pool $pool, string $url)
    {
        return $pool
            ->withHeaders($this->headers())
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(2, 500, throw: false)
            ->get($url);
    }

    private function request()
    {
        return Http::withHeaders($this->headers())
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry(2, 500, throw: false);
    }

    private function headers(): array
    {
        return [
            'User-Agent' => $this->userAgent,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'vi-VN,vi;q=0.9,en;q=0.8',
        ];
    }

    /**
     * Section landing pages such as /thuoc share the sitemap with products but hold
     * no product payload, so they are filtered out before any page is fetched.
     *
     * @return array<string>
     */
    private function parseSitemap(string $xml): array
    {
        preg_match_all('#<loc>\s*(.*?)\s*</loc>#s', $xml, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $url) => trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            ->filter(fn (string $url) => Str::startsWith($url, $this->baseUrl))
            ->filter(fn (string $url) => Str::contains(trim(Str::after($url, $this->baseUrl), '/'), '/'))
            ->unique()
            ->values()
            ->all();
    }
}
