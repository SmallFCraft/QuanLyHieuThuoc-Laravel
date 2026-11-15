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
 * Tải catalog Nhà Thuốc Long Châu.
 *
 * Long Châu phát hành các trang đã render, nên HTTP thuần là đủ — không cần browser và
 * không cần AI trích xuất. Sitemaps cung danh sách URL, và mỗi trang mang sản phẩm của nó
 * dưới dạng JSON, mà LongChauProduct ánh xạ.
 */
class ThuThapLongChauService
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
     * @return array<string> các sitemap key mà danhSachUrlSanPham() chấp nhận
     */
    public function danhSachSiteMap(): array
    {
        return array_keys($this->sitemaps);
    }

    /**
     * URL sản phẩm từ các sitemap được chỉ tên, đã loại trùng và giữ thứ tự sitemap.
     *
     * @param  array<string>  $keys
     * @return array<string>
     */
    public function danhSachUrlSanPham(array $keys): array
    {
        $unknown = array_diff($keys, $this->danhSachSiteMap());

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown sitemap: ' . implode(', ', $unknown)
                . '. Available: ' . implode(', ', $this->danhSachSiteMap())
            );
        }

        $urls = [];

        foreach ($keys as $key) {
            $response = $this->yeuCau()->get($this->baseUrl . '/' . $this->sitemaps[$key]);

            if ($response->status() === 403) {
                $response = $this->phanHoiQuaCurl($this->baseUrl . '/' . $this->sitemaps[$key]);
            }

            if (! $response->successful()) {
                Log::warning('Long Châu sitemap could not be read', [
                    'sitemap' => $this->sitemaps[$key],
                    'status' => $response->status(),
                ]);

                continue;
            }

            foreach ($this->phanTichSiteMap($response->body()) as $url) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * Tải từng URL và giao mỗi sản phẩm dùng được cho callback.
     *
     * Các trang được tải theo các batch đồng thời nhỏ, có nghỉ giữa các batch. Trang
     * thất bại được đếm và bỏ qua: một URL chết trong hàng nghìn URL không được kết
     * thúc lượt chạy.
     *
     * @param  array<string>  $urls
     * @param  Closure(LongChauProduct, string): void  $onProduct
     * @return array{fetched:int, imported:int, unusable:int, failed:int, no_json:int}
     */
    public function moiSanPham(array $urls, Closure $onProduct): array
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

                if ($responses[$position] instanceof Response && $responses[$position]->status() === 403) {
                    $responses[$position] = $this->phanHoiQuaCurl($url);
                }

                $this->xuLyPhanHoi($responses[$position] ?? null, $url, $stats, $onProduct);
            }

            $this->chongThayDoiCauTruc($stats);

            if ($this->delayMs > 0 && $index < $lastIndex) {
                usleep($this->delayMs * 1000);
            }
        }

        return $stats;
    }

    /**
     * @param  array{fetched:int, imported:int, unusable:int, failed:int, no_json:int}  $stats
     */
    private function xuLyPhanHoi(mixed $response, string $url, array &$stats, Closure $onProduct): void
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
     * Các trang độc lập không có __NEXT_DATA__ là URL chết. Một tỷ lệ lớn nghĩa là Long
     * Châu đã đổi cách render, và tiếp tục sẽ không import gì trong khi vẫn báo cáo
     * thành công — nên lượt chạy dừng lại và nói ra điều đó.
     *
     * @param  array{fetched:int, no_json:int}  $stats
     */
    private function chongThayDoiCauTruc(array $stats): void
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

    private function yeuCau()
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
     * Cloudflare chặn theo TLS fingerprint: Guzzle (libcurl-OpenSSL) bị 403 trong khi
     * curl.exe (schannel) qua được. Khi Guzzle trả 403, fetch lại bằng curl.exe và
     * bọc kết quả thành Response giả lập để không đổi đường xử lý phản hồi.
     * ponytail: chỉ chạy khi Guzzle bị chặn; nếu site gỡ mức chặn thì nhánh này
     * không bao giờ vào. Đổi sang cURL handler tự chọn TLS backend nếu cần loại bỏ.
     */
    private function phanHoiQuaCurl(string $url): Response
    {
        $head = implode(' ', array_map(
            fn (string $k, string $v) => '-H '.escapeshellarg($k.': '.$v),
            array_keys($this->headers()),
            $this->headers()
        ));

        $lenh = 'curl.exe -s --max-time '.escapeshellarg((string) $this->timeout)
            .' -L '.$head.' '.escapeshellarg($url).' 2>NUL';

        $body = shell_exec($lenh) ?? '';

        return new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'text/html'], $body));
    }

    /**
     * Trang landing section như /thuoc chia sẻ sitemap với sản phẩm nhưng không chứa
     * payload sản phẩm, nên chúng được loại trước khi bất kỳ trang nào được tải.
     *
     * @return array<string>
     */
    private function phanTichSiteMap(string $xml): array
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
