<?php

namespace Tahadudhiya\AltMonitor\enums;

use Craft;

/**
 * Checks the image list can narrow to that look beyond one image's own alt text.
 */
enum ListCheck: string
{
    /** Alt text another image in the same site also has (decorative and empty alt text aside). */
    case SAME_ALT = 'sameAlt';

    /** Embedded in text with an `<img>` tag that has no alt text (decorative images aside). */
    case NO_ALT_IN_TEXT = 'noAltInText';

    public function label(): string
    {
        return match ($this) {
            self::SAME_ALT => Craft::t('alt-monitor', 'Same alt text as other images'),
            self::NO_ALT_IN_TEXT => Craft::t('alt-monitor', 'No alt text where embedded in text'),
        };
    }
}
