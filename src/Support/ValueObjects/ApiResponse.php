<?php

namespace Martingalian\Core\Support\ValueObjects;

use GuzzleHttp\Psr7\Response;

class ApiResponse
{
    public Response $response;

    public array $result;

    public function __construct(?Response $response = null, ?array $result = [])
    {
        $this->response = $response;
        $this->result = $result;
    }
}
