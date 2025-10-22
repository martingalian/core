<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;

final class TaapiExceptionHandler extends BaseExceptionHandler
{
    use ApiExceptionHelpers;

    public $retryableHttpCodes = [
        504,
        503,
    ];

    public array $ignorableHttpCodes = [400];

    public array $forbiddenHttpCodes = [401, 402, 403];

    public array $rateLimitedHttpCodes = [429, 502];

    public array $recvWindowMismatchedHttpCodes = [];

    public function __construct()
    {
        $this->backoffSeconds = 3;
    }

    public function ping(): bool
    {
        return true;
    }
}
