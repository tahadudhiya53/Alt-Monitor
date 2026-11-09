<?php

namespace Tahadudhiya\AltMonitor\models;

/**
 * The numbers on the dashboard, all counted from the database when it is shown.
 */
final class DashboardStats
{
    public function __construct(
        public readonly int $totalImages,
        public readonly int $audited,
        public readonly int $missing,
        public readonly int $weak,
        public readonly int $good,
        public readonly int $review,
        public readonly int $decorative,
        public readonly int $pendingSuggestions,
        public readonly int $updated,
        public readonly int $used,
        public readonly int $unused,
        public readonly int $usageUnknown,
    ) {
    }

    /** The share of audited images with no known alt text problem, in percent. */
    public function coverage(): ?float
    {
        return $this->audited > 0 ? round($this->good / $this->audited * 100, 1) : null;
    }
}
