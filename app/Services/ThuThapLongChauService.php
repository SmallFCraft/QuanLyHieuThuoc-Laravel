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
 * Tai catalog Nha Thuoc Long Chau.
 *
 * Long Chau phat hanh cac trang da render, nen HTTP thuan la du — khong can browser va
 * khong can AI trich xuat. Sitemaps cung danh sach URL, va moi trang mang san pham cua no
 * duoi dang JSON, ma LongChauProduct anh xa.
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
     * @return array<string> cac sitemap key ma danhSachUrlSanPham() chap nhan
     */
    public function danhSachSiteMap(): array
    {
        return array_keys($this->sitemaps);
    }

    /**
     * URL san pham tu cac sitemap duoc chi ten, da loai trung va giu thu tu sitemap.
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
     * Tai tung URL va giao moi san pham dung duoc cho callback.
     *
     * Cac trang duoc tai theo cac batch dong thoi nho, co nghi giua cac batch. Trang
     * that bai duoc dem va bo qua: mot URL chet trong hang nghin URL khong duoc ket
     * thuc lan chay.
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
     * Cac trang doc lap khong co __NEXT_DATA__ la URL chet. Mot ti le lon nghia la Long
     * Chau da doi cach render, va tiep tuc se khong import gi trong khi van bao cao
     * thanh cong — nen lan chay dung lai va noi ra dieu do.
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
     * Cloudflare chan theo TLS fingerprint: Guzzle (libcurl-OpenSSL) bi 403 trong khi
     * curl.exe (schannel) qua duoc. Khi Guzzle tra 403, fetch lai bang curl.exe va
     * boc ket qua thanh Response gia lap de khong doi duong xu ly phan hoi.
     * ponytail: chi chay khi Guzzle bi chan; neu site go muc chan thi nhanh nay
     * khong bao gio vao. Doi sang cURL handler tu chon TLS backend neu can loai bo.
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
     * Trang landing section nhu /thuoc chia se sitemap voi san pham nhung khong chua
     * payload san pham, nen chung duoc loai truoc khi bat ky trang nao duoc tai.
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
