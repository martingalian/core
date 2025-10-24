<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiExceptionHandlers;

use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Concerns\ApiExceptionHelpers;
use Psr\Http\Message\ResponseInterface;

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

    public function getApiSystem(): string
    {
        return 'alternativeme';
    }

    /**
     * No-op: AlternativeMe doesn't require response header tracking.
     */
    public function recordResponseHeaders(ResponseInterface $response): void
    {
        // No-op - AlternativeMe uses simple API calls without complex rate limiting
    }

    /**
     * No-op: AlternativeMe doesn't implement IP bans.
     */
    public function isCurrentlyBanned(): bool
    {
        return false;
    }

    /**
     * No-op: AlternativeMe doesn't require IP ban recording.
     */
    public function recordIpBan(int $retryAfterSeconds): void
    {
        // No-op - AlternativeMe doesn't implement IP bans
    }

    /**
     * No-op: AlternativeMe doesn't require pre-request safety checks.
     * Always return true to allow requests to proceed.
     */
    public function isSafeToMakeRequest(): bool
    {
        return true;
    }
}
