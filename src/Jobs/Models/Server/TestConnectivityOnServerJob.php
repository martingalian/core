<?php

declare(strict_types=1);

namespace Martingalian\Core\Jobs\Models\Server;

use Exception;
use Martingalian\Core\Abstracts\BaseApiableJob;
use Martingalian\Core\Abstracts\BaseExceptionHandler;
use Martingalian\Core\Models\Account;

/**
 * TestConnectivityOnServerJob
 *
 * Tests connectivity from specific server using provided exchange credentials.
 * This job MUST run on the target server to test that server's IP address.
 *
 * Success: step.state = 'completed', step.response = {connected: true, ...}
 * Failure: step.state = 'failed', step.error_message = exception details
 *
 * Used during user registration to verify which server IPs can connect to exchange APIs.
 * No user relation since user doesn't exist yet during registration.
 */
final class TestConnectivityOnServerJob extends BaseApiableJob
{
    /**
     * @var array<string, string>
     */
    public array $credentials;

    public string $exchangeCanonical;

    public string $serverHostname;

    public string $serverIp;

    private Account $tempAccount;

    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        array $credentials,
        string $exchangeCanonical,
        string $serverHostname,
        string $serverIp
    ) {
        $this->credentials = $credentials;
        $this->exchangeCanonical = $exchangeCanonical;
        $this->serverHostname = $serverHostname;
        $this->serverIp = $serverIp;

        // Create temporary Account with user-provided credentials
        $this->tempAccount = Account::temporary($exchangeCanonical, $credentials);
    }

    public function assignExceptionHandler()
    {
        $this->exceptionHandler = BaseExceptionHandler::make($this->exchangeCanonical)
            ->withAccount($this->tempAccount);
    }

    public function relatable()
    {
        return null;
    }

    public function computeApiable()
    {
        $currentHostname = gethostname();

        // Verify we're running on the correct server
        if ($currentHostname !== $this->serverHostname) {
            throw new Exception(
                "Job dispatched to wrong server. Expected: {$this->serverHostname}, Got: {$currentHostname}"
            );
        }

        // Test connectivity via GetAccountBalance (requires signed request + whitelisted IP)
        $apiResponse = $this->tempAccount->apiQueryBalance();

        // Success! Return structured response
        return [
            'connected' => true,
            'server' => $this->serverHostname,
            'ip' => $this->serverIp,
            'exchange' => $this->exchangeCanonical,
            'tested_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Override BaseApiableJob's safety checks.
     * For connectivity tests, we WANT to test if IP is forbidden or rate-limited.
     * We skip pre-flight checks and just test the API call directly.
     */
    public function shouldExitEarly(): bool
    {
        // Skip all BaseApiableJob safety checks:
        // - ForbiddenHostname check (we're testing if IP is forbidden)
        // - Throttling check (we want immediate result)
        // - shouldStartOrStop/shouldStartOrFail/shouldStartOrSkip/shouldStartOrRetry

        // Only enforce max retries to prevent infinite loops
        $this->checkMaxRetries();

        return false;
    }
}
