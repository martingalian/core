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
        $headers = array_merge(
            $this->getHeaders(),
            (array) ($apiRequest->properties->getOr('headers', []))
        );

        $logData = $this->prepareLogData($apiRequest, $headers);
        $options = $this->prepareRequestOptions($apiRequest, $sendAsJson, $headers);

        $this->apiRequestLog = ApiRequestLog::create($logData);

        $startTime = microtime(true);
        $logData['started_at'] = now();

        try {
            $response = $this->executeHttpRequest(
                $apiRequest->method,
                $apiRequest->path,
                $options
            );

            $this->recordSuccessfulResponse($response, $logData, $startTime);

            return $response;
        } catch (RequestException $e) {
            return $this->handleRequestException($e, $apiRequest, $options, $logData, $startTime);
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

    protected function prepareLogData(ApiRequest $apiRequest, array $headers): array
    {
        $properties = $apiRequest->properties->toArray();

        $logData = [
            'path' => $apiRequest->path,
            'payload' => $properties,
            'http_method' => $apiRequest->method,
            'http_headers_sent' => $headers,
            'hostname' => gethostname(),
            'debug_data' => $apiRequest->properties->getOr('debug', []),
            'api_system_id' => $this->apiSystem->id,
        ];

        $relatable = $apiRequest->properties->getOr('relatable', null);
        if ($relatable) {
            $logData['relatable_id'] = $relatable->getKey();
            $logData['relatable_type'] = get_class($relatable);
        }

        $account = $apiRequest->properties->getOr('account', null);
        if ($account && $account->id) {
            $logData['account_id'] = $account->id;
        }

        return $logData;
    }

    protected function prepareRequestOptions(ApiRequest $apiRequest, bool $sendAsJson, array $headers): array
    {
        $properties = $apiRequest->properties->toArray();

        $options = [
            'headers' => $headers,
        ];

        if ($sendAsJson && mb_strtoupper($apiRequest->method) !== 'GET') {
            $bodyPayload = $properties;
            unset($bodyPayload['headers']);

            $options['json'] = $bodyPayload;
        } else {
            $options['query'] = $apiRequest->properties->getOr('options', []);
        }

        return $options;
    }

    protected function recordSuccessfulResponse($response, array &$logData, float $startTime): void
    {
        $endTime = microtime(true);
        $logData['completed_at'] = now();
        $logData['duration'] = (int) (($endTime - $startTime) * 1000);
        $logData['http_response_code'] = $response->getStatusCode();
        $logData['response'] = json_decode((string) $response->getBody(), true);
        $logData['http_headers_returned'] = $response->getHeaders();

        $this->updateRequestLogData($logData);

        if ($this->exceptionHandler) {
            $this->exceptionHandler->recordResponseHeaders($response);
        }
    }

    protected function executeHttpRequest(string $method, string $path, array $options)
    {
        return $this->httpRequest->request($method, $path, $options);
    }

    protected function handleRequestException(RequestException $e, ApiRequest $apiRequest, array $options, array &$logData, float $startTime)
    {
        $logData['http_response_code'] = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;
        $logData['response'] = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : null;
        $logData['http_headers_returned'] = $e->getResponse() ? $e->getResponse()->getHeaders() : null;

        if ($this->shouldRetryRequest($e)) {
            return $this->retryRequest($apiRequest, $options, $logData, $startTime);
        }

        $logData['completed_at'] = now();
        $this->updateRequestLogData($logData);
        throw $e;
    }

    protected function shouldRetryRequest(RequestException $e): bool
    {
        return $this->exceptionHandler && $this->exceptionHandler->retryException($e);
    }

    protected function retryRequest(ApiRequest $apiRequest, array $options, array &$logData, float $startTime)
    {
        $delay = $this->getRetryDelay();
        Sleep::for($delay)->seconds();

        try {
            $response = $this->executeHttpRequest(
                $apiRequest->method,
                $apiRequest->path,
                $options
            );

            $this->recordSuccessfulResponse($response, $logData, $startTime);

            return $response;
        } catch (Throwable $retryException) {
            $logData['completed_at'] = now();
            $this->updateRequestLogData($logData);
            throw $retryException;
        }
    }

    protected function getRetryDelay(): int
    {
        if (property_exists($this->exceptionHandler, 'backoffSeconds') && is_int($this->exceptionHandler->backoffSeconds)) {
            return $this->exceptionHandler->backoffSeconds;
        }

        return 5;
    }
}
