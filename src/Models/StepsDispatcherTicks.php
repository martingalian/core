<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

final class StepsDispatcherTicks extends BaseModel
{

    protected $table = 'steps_dispatcher_ticks';

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration' => 'integer',
    ];

    public function steps()
    {
        return $this->hasMany(Step::class);
    }
}
