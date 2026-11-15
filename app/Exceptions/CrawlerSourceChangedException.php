<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dùng khi một site crawled không còn khớp với hình dạng mà mapper dùng, để một
 * lượt chạy abort thay vì mù quáng import không được gì.
 */
class CrawlerSourceChangedException extends RuntimeException
{
}
