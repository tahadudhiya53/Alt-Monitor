<?php

namespace Tahadudhiya\AltMonitor\enums;

use Craft;

/**
 * One reason the analyzer gives for a classification.
 */
enum AltIssue: string
{
    case EMPTY = 'empty';
    case DECORATIVE = 'decorative';
    case POSSIBLY_DECORATIVE = 'possiblyDecorative';
    case FILENAME = 'filename';
    case FILE_EXTENSION = 'fileExtension';
    case IDENTIFIER = 'identifier';
    case GENERIC = 'generic';
    case REPETITIVE = 'repetitive';
    case REDUNDANT_PREFIX = 'redundantPrefix';
    case VERY_SHORT = 'veryShort';
    case TOO_LONG = 'tooLong';

    public function label(): string
    {
        return match ($this) {
            self::EMPTY => Craft::t('alt-monitor', 'There is no alt text.'),
            self::DECORATIVE => Craft::t('alt-monitor', 'Marked as decorative, so empty alt text is intended.'),
            self::POSSIBLY_DECORATIVE => Craft::t('alt-monitor', 'There is no alt text, but the file name suggests the image may be decorative.'),
            self::FILENAME => Craft::t('alt-monitor', 'The alt text is the file name.'),
            self::FILE_EXTENSION => Craft::t('alt-monitor', 'The alt text contains a file extension.'),
            self::IDENTIFIER => Craft::t('alt-monitor', 'The alt text is an identifier rather than a description.'),
            self::GENERIC => Craft::t('alt-monitor', 'The alt text is a generic word that describes nothing.'),
            self::REPETITIVE => Craft::t('alt-monitor', 'The alt text repeats the same words.'),
            self::REDUNDANT_PREFIX => Craft::t('alt-monitor', 'The alt text starts with “image of” or similar, which screen readers already announce.'),
            self::VERY_SHORT => Craft::t('alt-monitor', 'The alt text is very short.'),
            self::TOO_LONG => Craft::t('alt-monitor', 'The alt text is long; consider a shorter description or a caption.'),
        };
    }
}
