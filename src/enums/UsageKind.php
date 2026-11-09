<?php

namespace Tahadudhiya\AltMonitor\enums;

use Craft;

/**
 * How a piece of content references an image.
 */
enum UsageKind: string
{
    /** A relational field (an Assets field, at any nesting depth) relates to it. */
    case RELATION = 'relation';

    /** A reference tag such as `{asset:123:url}` in field content, as rich text editors store images. */
    case REFERENCE_TAG = 'referenceTag';

    public function label(): string
    {
        return match ($this) {
            self::RELATION => Craft::t('alt-monitor', 'Related through a field'),
            self::REFERENCE_TAG => Craft::t('alt-monitor', 'Embedded in field content'),
        };
    }
}
