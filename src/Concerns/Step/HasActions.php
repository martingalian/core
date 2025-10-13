<?php

namespace Martingalian\Core\Concerns\Step;

trait HasActions
{
    public function log(string $message)
    {
        $this->step_log = $message;
        $this->save();
    }
}
