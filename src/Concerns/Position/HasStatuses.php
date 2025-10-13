<?php

namespace Martingalian\Core\Concerns\Position;

trait HasStatuses
{
    public function isActive()
    {
        return ! in_array($this->status, ['closed', 'cancelled']);
    }

    public function isClosing()
    {
        return $this->status == 'closing';
    }

    public function isCancelled()
    {
        return $this->status == 'cancelled';
    }

    public function updateToWatching()
    {
        $this->updateSaving(['status' => 'watching', 'watched_since' => now()]);
    }

    public function updateToWaping()
    {
        $this->updateSaving(['status' => 'waping']);
    }

    public function updateToOpening()
    {
        $this->updateSaving(['status' => 'opening', 'watched_since' => null]);
    }

    public function updateToCancelling()
    {
        $this->updateSaving(['status' => 'cancelling', 'watched_since' => null]);
    }

    public function updateToActive()
    {
        $this->updateSaving(['status' => 'active', 'watched_since' => null]);
    }

    public function updateToClosing()
    {
        $this->updateSaving(['status' => 'closing', 'watched_since' => null]);
    }

    public function updateToClosed()
    {
        $this->updateSaving([
            'closed_at' => now(),
            'status' => 'closed',
            'watched_since' => null,
        ]);
    }

    public function updateToCancelled(?string $message = null): void
    {
        $data = ['status' => 'cancelled', 'watched_since' => null];

        if ($this->opened_at !== null) {
            $data['closed_at'] = now();
        }

        if ($message !== null) {
            $data['error_message'] = $message;
        }

        $this->updateSaving($data);
    }

    public function updateToFailed(?string $message = null): void
    {
        $data = ['status' => 'failed', 'watched_since' => null];

        if ($this->opened_at !== null) {
            $data['closed_at'] = now();
        }

        if ($message !== null) {
            $data['error_message'] = $message;
        }

        $this->updateSaving($data);
    }
}
