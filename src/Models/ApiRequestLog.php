<?php

namespace Martingalian\Core\Models;

use Martingalian\Core\Abstracts\BaseModel;

class ApiRequestLog extends BaseModel
{
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
}
