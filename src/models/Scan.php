<?php

namespace Tahadudhiya\AltMonitor\models;

use DateTime;
use Tahadudhiya\AltMonitor\enums\ScanStatus;
use Tahadudhiya\AltMonitor\enums\ScanType;

/**
 * One run of a scan, as the dashboard shows it.
 */
final class Scan
{
    public function __construct(
        public readonly int $id,
        public readonly ScanType $type,
        public readonly ScanStatus $status,
        public readonly int $totalAssets,
        public readonly int $scannedAssets,
        public readonly int $missingAlt,
        public readonly int $weakAlt,
        public readonly int $goodAlt,
        public readonly int $reviewCount,
        public readonly ?string $error,
        public readonly ?DateTime $startedAt,
        public readonly ?DateTime $completedAt,
        public readonly DateTime $dateCreated,
        public readonly DateTime $dateUpdated,
    ) {
    }

    /** How far through it is, from 0 to 1. */
    public function progress(): float
    {
        if ($this->status === ScanStatus::COMPLETED) {
            return 1.0;
        }

        return $this->totalAssets > 0 ? min(1.0, $this->scannedAssets / $this->totalAssets) : 0.0;
    }

    /**
     * @return array<string, mixed> What the dashboard polls for.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'typeLabel' => $this->type->label(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'active' => $this->status->isActive(),
            'total' => $this->totalAssets,
            'scanned' => $this->scannedAssets,
            'progress' => round($this->progress() * 100),
            'error' => $this->error,
        ];
    }
}
