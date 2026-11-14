<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dung khi mot site crawled khong con khop voi hinh dang ma mapper dung, de mot
 * lan chay abort thay vi am tham import khong duoc gi.
 */
class CrawlerSourceChangedException extends RuntimeException
{
}
