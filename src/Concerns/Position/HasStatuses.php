<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Position;

trait HasStatuses
{
    public function isActive()
    {
        return ! in_array($this->status, ['closed', 'cancelled']);
    }

    public function isClosing()
    {
        return $this->status === 'closing';
    }

    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    public function updateToWatching()
    {
        $this->updateSaving(['status' => 'watching', 'watched_at' => now()]);
    }

    public function updateToWaping()
    {
        $this->updateSaving(['status' => 'waping']);
    }

    public function updateToOpening()
    {
        $this->updateSaving(['status' => 'opening', 'watched_at' => null]);
    }

    public function updateToCancelling()
    {
        $this->updateSaving(['status' => 'cancelling', 'watched_at' => null]);
    }

    public function updateToActive()
    {
        $this->updateSaving(['status' => 'active', 'watched_at' => null]);
    }

    public function updateToReplacing()
    {
        $this->updateSaving(['status' => 'replacing', 'watched_at' => null]);
    }

    public function updateToClosing()
    {
        $this->updateSaving(['status' => 'closing', 'watched_at' => null]);
    }

    public function updateToClosed()
    {
        $this->updateSaving([
            'closed_at' => now(),
            'status' => 'closed',
            'watched_at' => null,
        ]);
    }

    public function updateToCancelled(?string $message = null): void
    {
        $data = ['status' => 'cancelled', 'watched_at' => null];

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
        $data = ['status' => 'failed', 'watched_at' => null];

        if ($this->opened_at !== null) {
            $data['closed_at'] = now();
        }

        if ($message !== null) {
            $data['error_message'] = $message;
        }

        $this->updateSaving($data);
    }
}
