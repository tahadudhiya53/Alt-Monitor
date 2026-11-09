<?php

namespace Tahadudhiya\AltMonitor\models;

use Tahadudhiya\AltMonitor\enums\AltIssue;
use Tahadudhiya\AltMonitor\enums\AltStatus;

/**
 * The analyzer's verdict on one alt text, with the reasons for it.
 */
final class AltAnalysis
{
    /**
     * @param list<AltIssue> $issues
     */
    public function __construct(
        public readonly AltStatus $status,
        public readonly array $issues = [],
    ) {
    }

    /** The issues as stored: their values, comma-separated, or null when there are none. */
    public function issuesString(): ?string
    {
        return $this->issues === [] ? null : implode(',', array_map(static fn(AltIssue $issue): string => $issue->value, $this->issues));
    }

    /**
     * @return list<AltIssue>
     */
    public static function issuesFromString(?string $value): array
    {
        return array_values(array_filter(array_map(
            static fn(string $item): ?AltIssue => AltIssue::tryFrom($item),
            $value === null || $value === '' ? [] : explode(',', $value),
        )));
    }
}
