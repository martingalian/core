<?php

declare(strict_types=1);

namespace Martingalian\Core\Concerns\Step;

trait HasActions
{
    public function log(string $message)
    {
        $this->step_log = $message;
        $this->save();
    }
}
