<?php

namespace Martingalian\Core\Concerns\Position;

use Illuminate\Database\Eloquent\Builder;

trait HasScopes
{
    public function scopeActive(Builder $query)
    {
        return $query->whereIn('positions.status', $this->activeStatuses());
    }

    public function scopeOnlyShorts(Builder $query)
    {
        return $query->where('positions.direction', 'SHORT');
    }

    public function scopeOnlyLongs(Builder $query)
    {
        return $query->where('positions.direction', 'LONG');
    }

    public function scopeNonActive(Builder $query)
    {
        return $query->whereIn('positions.status', $this->nonActiveStatuses());
    }

    public function scopeOngoing(Builder $query)
    {
        return $query->whereIn('positions.status', $this->ongoingStatuses());
    }

    public function scopeOpened(Builder $query)
    {
        return $query->whereIn('positions.status', $this->openedStatuses());
    }

    public function ongoingStatuses()
    {
        return ['active', 'watching', 'opening', 'waping'];
    }

    public function openedStatuses()
    {
        return ['opening', 'waping', 'active', 'new', 'closing', 'cancelling', 'watching'];
    }

    public function activeStatuses()
    {
        return ['active', 'new', 'watching', 'waping'];
    }

    public function nonActiveStatuses()
    {
        return ['closed', 'cancelled', 'failed'];
    }
}
