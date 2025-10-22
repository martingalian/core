<?php

declare(strict_types=1);

namespace Martingalian\Core\Exceptions;

use Exception;

/**
 * Type of exception used on the BaseQueueableJob, so when it's raised
 * it will not run the ignoreException cycles, and just call the
 * resolveException cycle.
 */
final class JustResolveException extends Exception {}
