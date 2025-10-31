<?php

declare(strict_types=1);

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;
use Martingalian\Core\Concerns\ApiRequestLog\SendsNotifications;

final class ApiRequestLog extends BaseModel
{
    use SendsNotifications;

    protected $table = 'api_request_logs';

    protected $casts = [
        'debug_data' => 'array',
        'payload' => 'array',
        'http_headers_sent' => 'array',
        'response' => 'array',
        'http_headers_returned' => 'array',

        'started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function relatable()
    {
        return $this->morphTo();
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
