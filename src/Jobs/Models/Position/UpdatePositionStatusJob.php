<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Position;

use Martingalian\Core\Abstracts\BaseQueueableJob;
use Martingalian\Core\Models\Position;

/**
 * Updates the status of a Position model based on a given status string.
 */
final class UpdatePositionStatusJob extends BaseQueueableJob
{
    public string $status;

    public ?string $message = null;

    public Position $position;

    /**
     * Create a new job instance.
     */
    public function __construct(int $positionId, string $status, ?string $message = null)
    {
        $this->position = Position::findOrFail($positionId);
        $this->status = $status;
        $this->message = $message;
    }

    /**
     * Returns the relatable model for this job.
     */
    public function relatable()
    {
        return $this->position;
    }

    /**
     * Executes the logic to update the position's status.
     */
    public function compute()
    {
        switch ($this->status) {
            case 'cancelling':
                $this->position->updateToCancelling();
                break;

            case 'active':
                $this->position->updateToActive();
                break;

            case 'closing':
                $this->position->updateToClosing();
                break;

            case 'closed':
                $this->position->updateToClosed();
                break;

            case 'cancelled':
                $this->position->updateToCancelled($this->message);
                break;

            case 'failed':
                $this->position->updateToFailed($this->message);
                break;

            case 'watching':
                $this->position->updateToWatching();
                break;

            case 'waping':
                $this->position->updateToWaping();
                break;
        }

        return [
            'message' => 'Position '.$this->position->parsed_trading_pair.' updated to '.$this->status.'.',
        ];
    }
}
