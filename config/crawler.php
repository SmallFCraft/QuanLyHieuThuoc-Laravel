<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Nhà Thuốc Long Châu catalog crawler
    |--------------------------------------------------------------------------
    |
    | Long Châu runs Next.js with server-side rendering, so every product page
    | ships the full product JSON inside a __NEXT_DATA__ script tag. The crawler
    | reads that instead of scraping markup.
    |
    */

    'longchau' => [
        'base_url' => env('CRAWLER_LONGCHAU_BASE_URL', 'https://nhathuoclongchau.com.vn'),

        /*
        | Sitemaps keyed by the --sitemap option. Measured 2026-08-30:
        | thuoc 5799 URLs, tpcn 529, my-pham 485, ca-nhan 316, thiet-bi 553.
        */
        'sitemaps' => [
            'thuoc' => 'sitemap_thuoc.xml',
            'tpcn' => 'sitemap_thuc-pham-chuc-nang.xml',
            'my-pham' => 'sitemap_duoc-my-pham.xml',
            'ca-nhan' => 'sitemap_cham-soc-ca-nhan.xml',
            'thiet-bi' => 'sitemap_trang-thiet-bi-y-te.xml',
        ],

        /*
        | A plain browser User-Agent. Long Châu's robots.txt does not disallow
        | product paths and sets no Crawl-delay, but the request still has to
        | identify as a normal client to get the rendered page.
        */
        'user_agent' => env(
            'CRAWLER_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
        ),

        'timeout' => (int) env('CRAWLER_TIMEOUT', 25),
        'connect_timeout' => (int) env('CRAWLER_CONNECT_TIMEOUT', 10),

        /* Pages fetched per Http::pool() batch. */
        'concurrency' => (int) env('CRAWLER_CONCURRENCY', 4),

        /* Pause between batches, in milliseconds. Politeness, not a site rule. */
        'delay_ms' => (int) env('CRAWLER_DELAY_MS', 800),

        /*
        | Share of pages that may lack __NEXT_DATA__ before the run aborts.
        | Isolated misses are dead URLs; a large share means Long Châu changed
        | its page structure and the mapping needs review before writing rows.
        */
        'max_missing_json_ratio' => 0.2,

        /* Below this many pages the ratio guard is noise, so it stays off. */
        'missing_json_guard_floor' => 20,
    ],
];
