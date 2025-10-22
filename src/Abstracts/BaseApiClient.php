<?php

declare(strict_types=1);

namespace Martingalian\Core\Abstracts;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Sleep;
use Martingalian\Core\Models\ApiRequestLog;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiRequest;
use Throwable;

/*
 * BaseApiClient
 *
 * • Abstract base class for API clients using Guzzle HTTP.
 * • Handles authenticated and unauthenticated requests with logging.
 * • Logs API requests and responses in the `api_request_logs` table.
 * • Measures and stores duration, headers, and status codes.
 * • Supports optional relational linking via relatable models.
 * • Handles JSON or query-based payloads depending on request type.
 * • Captures and rethrows exceptions for higher-level handling (e.g. jobs).
 * • Subclasses must implement `getHeaders()` to inject required auth headers.
 */
abstract class BaseApiClient
{
    protected string $baseURL;

    protected ?ApiCredentials $credentials = null;

    protected ?Client $httpRequest = null;

    protected ?ApiRequestLog $apiRequestLog = null;

    protected ?ApiSystem $apiSystem = null;

    protected ?BaseExceptionHandler $exceptionHandler = null;

    public function __construct(string $baseURL, ?ApiCredentials $credentials = null)
    {
        $this->baseURL = $baseURL;
        $this->credentials = $credentials;
        $this->buildClient();
    }

    abstract protected function getHeaders(): array;

    protected function processRequest(ApiRequest $apiRequest, bool $sendAsJson = false)
    {
        $logData = [
            'path' => $apiRequest->path,
            'payload' => $apiRequest->properties->toArray(),
            'http_method' => $apiRequest->method,
            'http_headers_sent' => $this->getHeaders(),
            'hostname' => gethostname(),
        ];

        try {
            $options = [
                'headers' => $this->getHeaders(),
            ];

            if ($sendAsJson && mb_strtoupper($apiRequest->method) !== 'GET') {
                $options['json'] = $apiRequest->properties->toArray();
            } else {
                $options['query'] = $apiRequest->properties->getOr('options', []);
            }

            $logData['debug_data'] = $apiRequest->properties->getOr('debug', []);
            $logData['api_system_id'] = $this->apiSystem->id;

            $relatable = $apiRequest->properties->getOr('relatable', null);
            if ($relatable) {
                $logData['relatable_id'] = $relatable->getKey();
                $logData['relatable_type'] = get_class($relatable);
            }

            $this->apiRequestLog = ApiRequestLog::create($logData);

            $startTime = microtime(true);
            $logData['started_at'] = now();

            $response = $this->httpRequest->request(
                $apiRequest->method,
                $apiRequest->path,
                $options
            );

            $endTime = microtime(true);
            $logData['completed_at'] = now();
            $logData['duration'] = abs((int) (($endTime - $startTime) * 1000));
            $logData['http_response_code'] = $response->getStatusCode();
            $logData['response'] = json_decode($response->getBody(), true);
            $logData['http_headers_returned'] = $response->getHeaders();

            $this->updateRequestLogData($logData);

            return $response;
        } catch (RequestException $e) {
            $logData['http_response_code'] = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
            $logData['response'] = $e->getResponse() ? (string) $e->getResponse()->getBody() : null;
            $logData['http_headers_returned'] = $e->getResponse() ? $e->getResponse()->getHeaders() : null;

            // Retry once if configured and allowed
            if ($this->exceptionHandler && $this->exceptionHandler->retryException($e)) {
                $delay = property_exists($this->exceptionHandler, 'backoffSeconds') && is_int($this->exceptionHandler->backoffSeconds)
                    ? $this->exceptionHandler->backoffSeconds
                    : 5;

                Sleep::for($delay)->seconds();

                try {
                    $response = $this->httpRequest->request(
                        $apiRequest->method,
                        $apiRequest->path,
                        $sendAsJson && mb_strtoupper($apiRequest->method) !== 'GET'
                            ? ['headers' => $this->getHeaders(), 'json' => $apiRequest->properties->toArray()]
                            : ['headers' => $this->getHeaders(), 'query' => $apiRequest->properties->getOr('options', [])]
                    );

                    $endTime = microtime(true);
                    $logData['completed_at'] = now();
                    $logData['duration'] = abs((int) (($endTime - $startTime) * 1000));
                    $logData['http_response_code'] = $response->getStatusCode();
                    $logData['response'] = json_decode($response->getBody(), true);
                    $logData['http_headers_returned'] = $response->getHeaders();

                    $this->updateRequestLogData($logData);

                    return $response;
                } catch (Throwable $retryException) {
                    $this->updateRequestLogData($logData);
                    throw $retryException;
                }
            }

            $this->updateRequestLogData($logData);
            throw $e;
        } catch (Throwable $e) {
            $this->updateRequestLogData([
                'error_message' => $e->getMessage().' (line '.$e->getLine().')',
            ]);

            throw $e;
        }
    }

    protected function buildClient()
    {
        $this->httpRequest = new Client([
            'base_uri' => $this->baseURL,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept-Encoding' => 'application/json',
                'User-Agent' => 'api-client-php',
            ],
        ]);
    }

    protected function updateRequestLogData(array $logData)
    {
        $this->apiRequestLog->update($logData);
    }

    protected function buildQuery(string $path, array $properties = []): string
    {
        return count($properties) === 0 ? $path : $path.'?'.http_build_query($properties);
    }
}
