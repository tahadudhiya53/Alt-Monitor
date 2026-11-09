<?php

namespace Tahadudhiya\AltMonitor\models;

/**
 * What a provider check found: whether the service answered, and whether the model is there.
 */
final class AiStatus
{
    /**
     * @param list<string> $models Models the service reported as installed.
     */
    public function __construct(
        public readonly bool $reachable,
        public readonly bool $modelAvailable,
        public readonly string $message,
        public readonly array $models = [],
    ) {
    }

    public function isReady(): bool
    {
        return $this->reachable && $this->modelAvailable;
    }
}
