<?php

namespace Tahadudhiya\AltMonitor\models;

use Tahadudhiya\AltMonitor\enums\SuggestionSource;

/**
 * A suggestion as a generator made it, before it is stored.
 */
final class SuggestionDraft
{
    public function __construct(
        public readonly string $text,
        public readonly SuggestionSource $source,
        public readonly string $basis,
        public readonly bool $needsReview,
    ) {
    }
}
