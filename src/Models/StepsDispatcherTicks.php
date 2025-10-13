<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\HasDebuggable;
use Martingalian\Core\Concerns\HasLoggable;

class StepsDispatcherTicks extends BaseModel
{
    use HasDebuggable;
    use HasLoggable;

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
