<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiDataMappers\Kraken\ApiRequests;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Martingalian\Core\Support\ValueObjects\ApiProperties;

trait MapsServerTimeQuery
{
    public function prepareServerTimeProperties(): ApiProperties
    {
        return new ApiProperties;
    }

    /**
     * Resolves Kraken server time response.
     *
     * Kraken Futures response structure:
     * {
     *     "result": "success",
     *     "serverTime": "2024-01-15T10:30:00.000Z"
     * }
     */
    public function resolveServerTimeResponse(Response $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        // Kraken returns ISO 8601 formatted time
        $serverTime = $data['serverTime'] ?? null;

        if ($serverTime === null) {
            return [
                'timestamp_ms' => 0,
                'timestamp_sec' => 0,
                'datetime_utc' => null,
                'datetime_app' => null,
            ];
        }

        // Parse ISO 8601 datetime
        $carbonUtc = Carbon::parse($serverTime, 'UTC');
        $carbonApp = $carbonUtc->clone()->setTimezone(config('app.timezone'));

        return [
            'timestamp_ms' => $carbonUtc->getTimestampMs(),
            'timestamp_sec' => $carbonUtc->getTimestamp(),
            'datetime_utc' => $carbonUtc->toDateTimeString(),
            'datetime_app' => $carbonApp->toDateTimeString(),
        ];
    }
}
