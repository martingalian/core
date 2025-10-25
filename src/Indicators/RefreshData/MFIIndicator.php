<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;
use Martingalian\Core\Contracts\Indicators\ValidationIndicator;

final class MFIIndicator extends BaseIndicator implements ValidationIndicator
{
    public string $endpoint = 'mfi';

    public function conclusion(): bool
    {
        return $this->isValid();
    }

    public function isValid(): bool
    {
        if (! array_key_exists(0, $this->data['value'])) {
            return false;
        }

        // Major number to keep the trend solid (e.g. >= 20).
        return $this->data['value'][0] >= 15;
    }
}
