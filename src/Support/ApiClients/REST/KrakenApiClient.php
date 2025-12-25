<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\REST;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Martingalian\Core\Abstracts\BaseApiClient;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * KrakenApiClient
 *
 * Low-level HTTP client for Kraken Futures API.
 * Handles HMAC-SHA512 signature generation and request execution.
 *
 * Authentication Headers:
 * - APIKey: Public API key
 * - Authent: HMAC-SHA512 signature (base64 encoded)
 * - Nonce: Incrementing timestamp (optional but recommended)
 *
 * Signature Algorithm:
 * 1. Concatenate: postData + nonce + endpointPath
 * 2. SHA-256 hash the concatenation
 * 3. HMAC-SHA512 with base64-decoded private key
 * 4. Base64 encode the result
 */
final class KrakenApiClient extends BaseApiClient
{
    public function __construct(array $config)
    {
        $this->apiSystem = ApiSystem::firstWhere('canonical', 'kraken');

        $this->exceptionHandler = BaseExceptionHandler::make('kraken');

        $credentials = ApiCredentials::make([
            'api_key' => $config['api_key'],
            'private_key' => $config['private_key'],
        ]);

        parent::__construct($config['url'], $credentials);
    }

    /**
     * Execute a public (unauthenticated) request.
     */
    public function publicRequest(ApiRequest $apiRequest)
    {
        /** @var ResponseInterface $response */
        $response = $this->processRequest($apiRequest);
        $this->throwIfMaskedError($response);

        return $response;
    }

    /**
     * Execute a signed (authenticated) request.
     *
     * Kraken Futures uses HMAC-SHA512 signature with:
     * - postData (URL-encoded query string)
     * - nonce (incrementing timestamp)
     * - endpointPath (e.g., /api/v3/accounts - WITHOUT /derivatives prefix)
     *
     * @see https://docs.kraken.com/api/docs/guides/futures-rest/
     */
    public function signRequest(ApiRequest $apiRequest)
    {
        // Generate nonce in microsecond precision format (timestamp + microseconds)
        // Format: 1234567890123456 (16 digits)
        $microtime = explode(' ', microtime());
        $nonce = $microtime[1].mb_str_pad(mb_substr($microtime[0], 2, length: 6), 6, '0');

        // Build POST data string from options
        $options = $apiRequest->properties->getOr('options', []);
        $postData = empty($options) ? '' : http_build_query($options);

        // Get the endpoint path for signature - must strip /derivatives prefix
        // The request URL is /derivatives/api/v3/accounts but signature uses /api/v3/accounts
        // This matches the working mvaessen/kraken-future-api library behavior
        $endpointPath = str_replace(search: '/derivatives', replace: '', subject: $apiRequest->path);

        // Kraken signature algorithm:
        // 1. Concatenate: postData + nonce + endpointPath
        $message = $postData.$nonce.$endpointPath;

        // 2. SHA-256 hash the message
        $sha256Hash = hash('sha256', $message, true);

        // 3. HMAC-SHA512 with base64-decoded private key
        $privateKey = base64_decode($this->credentials->get('private_key'));
        $hmacSignature = hash_hmac('sha512', $sha256Hash, $privateKey, true);

        // 4. Base64 encode the signature
        $authent = base64_encode($hmacSignature);

        // Add authentication headers
        $apiRequest->properties->set('headers.APIKey', $this->credentials->get('api_key'));
        $apiRequest->properties->set('headers.Authent', $authent);
        $apiRequest->properties->set('headers.Nonce', $nonce);

        /** @var ResponseInterface $response */
        $response = $this->processRequest($apiRequest);
        $this->throwIfMaskedError($response);

        return $response;
    }

    /**
     * Get default headers for all requests.
     */
    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Kraken Futures API returns HTTP 200 for all responses, including errors.
     * Detect masked errors and throw RequestException so our handler infrastructure works.
     *
     * @see https://docs.kraken.com/api/docs/guides/futures-rest/
     */
    private function throwIfMaskedError(ResponseInterface $response): void
    {
        $body = (string) $response->getBody();

        /** @var array{result?: string, error?: string}|null $data */
        $data = json_decode($body, associative: true);

        if (! is_array($data) || ! isset($data['result']) || $data['result'] !== 'error') {
            return;
        }

        $error = (string) ($data['error'] ?? 'unknownError');

        // Map Kraken error strings to semantic HTTP codes
        $httpCode = match ($error) {
            'authenticationError', 'accountInactive' => 401,
            'apiLimitExceeded' => 429,
            'marketUnavailable', 'Unavailable' => 503,
            'Server Error' => 500,
            'notFound' => 404,
            default => 400,
        };

        // Update ApiRequestLog so observer triggers notifications
        $this->updateRequestLogData([
            'http_response_code' => $httpCode,
            'error_message' => $error,
        ]);

        // Throw RequestException with synthetic response - KrakenExceptionHandler will classify it
        throw new RequestException(
            "Kraken API error: {$error}",
            new Request('GET', ''),
            new Response($httpCode, [], $body)
        );
    }
}
