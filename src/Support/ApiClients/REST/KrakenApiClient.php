<?php

declare(strict_types=1);

namespace Martingalian\Core\Support\ApiClients\REST;

use Martingalian\Core\Abstracts\BaseApiClient;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\ApiSystem;
use Martingalian\Core\Support\ValueObjects\ApiCredentials;
use Martingalian\Core\Support\ValueObjects\ApiRequest;

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
        return $this->processRequest($apiRequest);
    }

    /**
     * Execute a signed (authenticated) request.
     *
     * Kraken Futures uses HMAC-SHA512 signature with:
     * - postData (URL-encoded query string)
     * - nonce (incrementing timestamp)
     * - endpointPath (e.g., /derivatives/api/v3/accounts)
     */
    public function signRequest(ApiRequest $apiRequest)
    {
        $nonce = (string) round(microtime(true) * 1000);

        // Build POST data string from options
        $options = $apiRequest->properties->getOr('options', []);
        $postData = empty($options) ? '' : http_build_query($options);

        // Get the endpoint path
        $endpointPath = $apiRequest->path;

        // Kraken signature algorithm:
        // 1. Concatenate: postData + nonce + endpointPath
        $message = $postData . $nonce . $endpointPath;

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

        return $this->processRequest($apiRequest);
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
}
