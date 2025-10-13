<?php

namespace Martingalian\Core\Concerns;

use Martingalian\Core\Models\ForbiddenHostname;
use Martingalian\Core\Models\User;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * ApiExceptionHelpers (generic)
 *
 * • Exchange-agnostic utilities for classifying/handling HTTP/API exceptions.
 * • No throttling, Redis, or clock/recvWindow logic here (handled elsewhere).
 */
trait ApiExceptionHelpers
{
    /**
     * ----------------
     * Core classifiers
     * ----------------
     */
    public function isRecvWindowMismatch(\Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->recvWindowMismatchedHttpCodes);
    }

    public function isRateLimited(\Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->rateLimitedHttpCodes);
    }

    public function isForbidden(\Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->forbiddenHttpCodes);
    }

    /**
     * Call only for true credential/IP whitelist failures (not temporary rate-limit bans).
     */
    public function forbid(): void
    {
        $record = ForbiddenHostname::updateOrCreate(
            [
                'account_id' => $this->account->id,
                'ip_address' => gethostbyname(gethostname()),
            ],
            [
                'updated_at' => now(),
            ]
        );

        User::notifyAdminsViaPushover(
            "Forbidden hostname detected.\n".
            "Account ID: {$this->account->id}\n".
            "IP: {$record->ip_address}\n".
            'Time: '.now()->toDateTimeString(),
            "[A:{$this->account->id}] - Forbidden Hostname added",
            'nidavellir_warnings'
        );
    }

    public function retryException(\Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->retryableHttpCodes);
    }

    public function ignoreException(\Throwable $exception): bool
    {
        return $this->containsHttpExceptionIn($exception, $this->ignorableHttpCodes);
    }

    /**
     * ----------------
     * Low-level helpers
     * ----------------
     */

    /**
     * Match a RequestException to a map of HTTP/vendor codes.
     * $statusCodes can be:
     *  • flat list: [429, 418]
     *  • map: [400 => [-1021, -5028], 401 => [-2015]]
     */
    protected function containsHttpExceptionIn(\Throwable $exception, array $statusCodes): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        $data = $this->extractHttpErrorCodes($exception);
        $httpCode = $data['http_code'];
        $statusCode = $data['status_code'];

        if (array_key_exists($httpCode, $statusCodes)) {
            $codes = $statusCodes[$httpCode];

            return is_array($codes) && $codes !== []
                ? in_array($statusCode, $codes, true)
                : true;
        }

        return in_array($httpCode, $statusCodes, true);
    }

    /**
     * Minimal body decoder for HTTP code + vendor JSON code/message.
     * NOTE: Also supports CoinMarketCap style payloads:
     *   { "status": { "error_code": 1008, "error_message": "...", ... }, "data": ... }
     */
    protected function extractHttpErrorCodes(RequestException|ResponseInterface $input): array
    {
        $httpCode = null;
        $statusCode = null;
        $message = null;
        $body = null;

        if ($input instanceof ResponseInterface) {
            $httpCode = $input->getStatusCode();
            $body = (string) $input->getBody();
        } elseif ($input instanceof RequestException && $input->hasResponse()) {
            $httpCode = $input->getResponse()->getStatusCode();
            $body = (string) $input->getResponse()->getBody();
        }

        if ($body) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                // Common schema (e.g., Binance-like)
                $statusCode = $json['code'] ?? null;
                $message = $json['msg'] ?? null;

                // CoinMarketCap schema (nested "status")
                if (($statusCode === null || $message === null) && isset($json['status']) && is_array($json['status'])) {
                    $statusCode = $json['status']['error_code'] ?? $statusCode;
                    $message = $json['status']['error_message'] ?? $message;
                }
            }
        }

        return [
            'http_code' => $httpCode,
            'status_code' => $statusCode,
            'message' => $message,
        ];
    }

    /**
     * Normalize headers (lower-case keys, comma-joined values).
     */
    protected function normalizeHeaders(MessageInterface $msg): array
    {
        $headers = [];
        foreach ($msg->getHeaders() as $k => $vals) {
            $headers[strtolower($k)] = implode(', ', $vals);
        }

        return $headers;
    }

    /**
     * Extract normalized headers plus common fields (Retry-After, Date).
     * Venue-specific handlers can parse extra headers from $meta['headers'] if needed.
     */
    protected function extractHttpMeta(RequestException|ResponseInterface $input): array
    {
        $meta = $this->extractHttpErrorCodes($input);
        $headers = [];

        if ($input instanceof RequestException && $input->hasResponse()) {
            $headers = $this->normalizeHeaders($input->getResponse());
        } elseif ($input instanceof ResponseInterface) {
            $headers = $this->normalizeHeaders($input);
        }

        $meta['headers'] = $headers;
        $meta['retry_after'] = Arr::get($headers, 'retry-after');
        $meta['server_date'] = Arr::get($headers, 'date');

        return $meta;
    }

    /**
     * Compute the next reset boundary for a given (intervalNum, intervalLetter) from a server time.
     * Provided as a generic utility for handlers that know their windowing.
     */
    protected function nextWindowResetAt(Carbon $serverNow, int $intervalNum, string $intervalLetter): Carbon
    {
        $letter = strtolower($intervalLetter);
        $t = $serverNow->copy();

        switch ($letter) {
            case 's':
                $sec = (int) $t->second;
                $next = (int) (floor($sec / $intervalNum) * $intervalNum + $intervalNum);

                return $t->copy()->second($next)->startOfSecond();

            case 'm':
                $min = (int) $t->minute;
                $next = (int) (floor($min / $intervalNum) * $intervalNum + $intervalNum);

                return $t->copy()->minute($next)->second(0)->startOfSecond();

            case 'h':
                $hr = (int) $t->hour;
                $next = (int) (floor($hr / $intervalNum) * $intervalNum + $intervalNum);

                return $t->copy()->hour($next)->minute(0)->second(0)->startOfSecond();

            case 'd':
                return $t->copy()->startOfDay()->addDays($intervalNum);

            default:
                return $t->copy()->startOfMinute()->addMinute();
        }
    }

    /**
     * --------------
     * Backoff helpers
     * --------------
     */

    /**
     * Generic "retry-at" computation:
     * • Prefer Retry-After (seconds or RFC-date).
     * • Otherwise fall back to a fixed base backoff with a tiny jitter.
     * Handlers may override to use window boundaries (e.g., minute/day/month).
     */
    public function rateLimitUntil(RequestException $exception): Carbon
    {
        $now = Carbon::now();

        if (! $exception->hasResponse()) {
            return $now->copy()->addSeconds($this->backoffSeconds);
        }

        $meta = $this->extractHttpMeta($exception);
        $retryAfter = trim((string) ($meta['retry_after'] ?? ''));

        if ($retryAfter !== '') {
            if (is_numeric($retryAfter)) {
                return $now->copy()->addSeconds((int) $retryAfter + random_int(2, 6));
            }

            try {
                $parsed = Carbon::parse($retryAfter);

                return $parsed->isPast()
                    ? $now->copy()->addSeconds($this->backoffSeconds)
                    : $parsed;
            } catch (\Throwable) {
                // fall through to base backoff
            }
        }

        // Base fallback + light jitter
        return $now->copy()->addSeconds($this->backoffSeconds + random_int(1, 5));
    }

    /**
     * Legacy wrapper returning seconds until retry for callers that expect an int.
     * Uses rateLimitUntil() when the exception is classified as rate-limited.
     */
    public function backoffSeconds(\Throwable $e): int
    {
        if ($e instanceof RequestException && $e->hasResponse()) {
            if ($this->isRateLimited($e)) {
                $until = $this->rateLimitUntil($e);

                return max(0, now()->diffInSeconds($until, false));
            }
        }

        return $this->backoffSeconds;
    }
}
