<?php

namespace Tahadudhiya\AltMonitor\enums;

use Craft;

enum ScanType: string
{
    /** Every image asset, then content usage. */
    case FULL = 'full';

    /** Only image assets changed since the last completed scan. */
    case INCREMENTAL = 'incremental';

    /** Content usage only. */
    case USAGE = 'usage';

    public function label(): string
    {
        return match ($this) {
            self::FULL => Craft::t('alt-monitor', 'Full scan'),
            self::INCREMENTAL => Craft::t('alt-monitor', 'Incremental scan'),
            self::USAGE => Craft::t('alt-monitor', 'Content usage scan'),
        };
    }
}
