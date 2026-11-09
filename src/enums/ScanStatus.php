<?php

namespace Tahadudhiya\AltMonitor\enums;

use Craft;

enum ScanStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => Craft::t('alt-monitor', 'Queued'),
            self::RUNNING => Craft::t('alt-monitor', 'Running'),
            self::COMPLETED => Craft::t('alt-monitor', 'Completed'),
            self::FAILED => Craft::t('alt-monitor', 'Failed'),
        };
    }

    public function isActive(): bool
    {
        return $this === self::QUEUED || $this === self::RUNNING;
    }
}
