<?php

namespace Tahadudhiya\AltMonitor\enums;

use Craft;

/**
 * Whether content is known to reference an image.
 *
 * Only references Craft records (element relations and reference tags in field content) can be
 * found. “Unused” means none of those was found, not that the image appears nowhere: templates,
 * custom code and other systems can use it by URL.
 */
enum UsageStatus: string
{
    case USED = 'used';
    case UNUSED = 'unused';

    /** No content usage scan has looked at the image yet. */
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::USED => Craft::t('alt-monitor', 'Used'),
            self::UNUSED => Craft::t('alt-monitor', 'Not found in content'),
            self::UNKNOWN => Craft::t('alt-monitor', 'Not checked'),
        };
    }
}
