<?php

declare(strict_types=1);

namespace Martingalian\Core\Indicators\RefreshData;

use Martingalian\Core\Abstracts\BaseIndicator;
use Martingalian\Core\Contracts\Indicators\ValidationIndicator;

final class ADXIndicator extends BaseIndicator implements ValidationIndicator
{
    public string $endpoint = 'adx';

    public function conclusion(): bool
    {
        return $this->isValid();
    }

    public function isValid(): bool
    {
        if (! array_key_exists(key: 0, array: $this->data['value'])) {
            return false;
        }

        // Major number to keep the trend solid (e.g. >= 15).
        return $this->data['value'][0] >= 15;
    }
}
