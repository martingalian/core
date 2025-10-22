<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;

final class AlternativeMeExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers;

    public array $ignorableHttpCodes = [];

    public array $retryableHttpCodes = [
        503,
        504,
    ];

    public array $forbiddenHttpCodes = [401, 403];

    public array $rateLimitedHttpCodes = [429];

    public array $recvWindowMismatchedHttpCodes = [];

    public function __construct()
    {
        $this->backoffSeconds = 5;
    }

    public function ping(): bool
    {
        return true;
    }
}
