<?php

declare(strict_types=1);

namespace Martingalian\Core\Support;

use Carbon\Carbon;
use DateTimeZone;
use InvalidArgumentException;

final class TimeTick
{
    public function __construct(
        public readonly int $tick,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public static function with(Carbon $carbon): TimeTickBuilder
    {
        return new TimeTickBuilder($carbon);
    }

    public static function current(int $minutes, ?string $tz = null): self
    {
        return self::with(now())
            ->withDuration($minutes)
            ->when($tz, fn (TimeTickBuilder $b) => $b->withTimezone($tz))
            ->tick();
    }
}

final class TimeTickBuilder
{
    private Carbon $carbon;

    private int $minutes = 0;

    private int $origin = 0;

    private int $rewind = 0;

    private ?DateTimeZone $timezone = null;

    public function __construct(Carbon $carbon)
    {
        $this->carbon = $carbon;
    }

    public function withDuration(int $minutes): self
    {
        if ($minutes <= 0) {
            throw new InvalidArgumentException('Minutes must be > 0.');
        }
        $this->minutes = $minutes;

        return $this;
    }

    public function withOrigin(int $origin): self
    {
        $this->origin = $origin;

        return $this;
    }

    public function rewind(int $ticks): self
    {
        $this->rewind = $ticks;

        return $this;
    }

    public function withTimezone(string $tz): self
    {
        $this->timezone = new DateTimeZone($tz);

        return $this;
    }

    public function get(): TimeTick
    {
        $period = $this->minutes * 60;
        $ts = $this->carbon->timestamp;
        $tick = intdiv($ts - $this->origin, $period) - $this->rewind;

        $startTs = $this->origin + ($tick * $period);
        $endTs = $startTs + $period;

        $tz = $this->timezone ?? new DateTimeZone(config('app.timezone'));

        return new TimeTick(
            tick: $tick,
            start: Carbon::createFromTimestamp($startTs, $tz),
            end: Carbon::createFromTimestamp($endTs, $tz),
        );
    }

    public function tick(): TimeTick
    {
        return $this->get();
    }
}
