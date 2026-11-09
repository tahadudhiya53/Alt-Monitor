<?php

namespace Tahadudhiya\AltMonitor\errors;

use RuntimeException;

/**
 * An AI provider could not produce a description. The message is safe to show and store: it
 * never contains image data or credentials.
 *
 * `retryable` tells the queue whether trying again later can help (a timeout or a busy server
 * can; a missing model or an unsupported image cannot).
 */
class AiProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
