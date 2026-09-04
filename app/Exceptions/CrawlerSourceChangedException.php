<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a crawled site stops matching the shape the mapper expects, so a
 * run aborts instead of quietly importing nothing.
 */
class CrawlerSourceChangedException extends RuntimeException
{
}
