<?php

namespace Tahadudhiya\AltMonitor\enums;

use Craft;

/**
 * How an image's alt text was classified by the rule-based checks.
 *
 * These are heuristics. A “good” status means no rule found a problem, not that the text is
 * accurate or that the page meets any accessibility standard.
 */
enum AltStatus: string
{
    /** No alt text, and nothing marks the image as decorative. */
    case MISSING = 'missing';

    /** Alt text that a rule shows to be unhelpful (a filename, a generic word, an ID…). */
    case WEAK = 'weak';

    /** No rule found a problem, or the image is marked decorative. */
    case GOOD = 'good';

    /** Something a person should look at: it could be fine, or it could be a problem. */
    case REVIEW = 'review';

    public function label(): string
    {
        return match ($this) {
            self::MISSING => Craft::t('alt-monitor', 'Missing'),
            self::WEAK => Craft::t('alt-monitor', 'Weak'),
            self::GOOD => Craft::t('alt-monitor', 'Good'),
            self::REVIEW => Craft::t('alt-monitor', 'Needs review'),
        };
    }

    /** The Craft status colour the control panel shows it in. */
    public function color(): string
    {
        return match ($this) {
            self::MISSING => 'red',
            self::WEAK => 'orange',
            self::GOOD => 'teal',
            self::REVIEW => 'blue',
        };
    }
}
