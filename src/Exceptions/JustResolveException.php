<?php

namespace Martingalian\Core\Exceptions;

/**
 * Type of exception used on the BaseQueueableJob, so when it's raised
 * it will not run the ignoreException cycles, and just call the
 * resolveException cycle.
 */
class JustResolveException extends \Exception {}
