<?php

namespace Tahadudhiya\AltMonitor\enums;

use Craft;

enum SuggestionStatus: string
{
    /** Waiting in the queue to be generated. */
    case QUEUED = 'queued';

    /** Generated, waiting for someone to approve or reject it. */
    case PENDING = 'pending';

    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    /** Generating it failed; the error says why. */
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => Craft::t('alt-monitor', 'Generating'),
            self::PENDING => Craft::t('alt-monitor', 'Pending review'),
            self::APPROVED => Craft::t('alt-monitor', 'Approved'),
            self::REJECTED => Craft::t('alt-monitor', 'Rejected'),
            self::FAILED => Craft::t('alt-monitor', 'Failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::QUEUED => 'gray',
            self::PENDING => 'blue',
            self::APPROVED => 'teal',
            self::REJECTED => 'orange',
            self::FAILED => 'red',
        };
    }
}
