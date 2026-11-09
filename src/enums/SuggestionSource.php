<?php

namespace Tahadudhiya\AltMonitor\enums;

use Craft;

enum SuggestionSource: string
{
    /** A vision model described the image. */
    case AI = 'ai';

    /** Built from the file name, title and content around the image; nothing looked at the image. */
    case RULES = 'rules';

    public function label(): string
    {
        return match ($this) {
            self::AI => Craft::t('alt-monitor', 'AI (local model)'),
            self::RULES => Craft::t('alt-monitor', 'Rule-based'),
        };
    }
}
