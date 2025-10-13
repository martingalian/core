<?php

namespace Martingalian\Core\Concerns\BaseQueueableJob;

use Psr\Http\Message\ResponseInterface;

/*
 * FormatsStepResult
 *
 * • Provides helper methods to normalize and store API response data.
 * • Offers a default implementation for formatting job results before
 *   saving them in the step record.
 * • Safely extracts and decodes JSON from PSR-7 ResponseInterface bodies.
 */
trait FormatsStepResult
{
    protected function formatResultForStorage($result): mixed
    {
        /*
         * Override this method in your job if result formatting is required
         * before saving into the step model.
         */
        return $result;
    }

    protected function extractBodyFromResponse(ResponseInterface $response)
    {
        /*
         * Extracts and decodes JSON content from the response body.
         * Falls back to raw string if JSON decoding fails.
         */
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return json_decode($body, true) ?? (string) $body;
    }
}
