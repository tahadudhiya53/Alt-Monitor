<?php

namespace Tahadudhiya\AltMonitor\models;

use Tahadudhiya\AltMonitor\enums\UsageKind;
use Tahadudhiya\AltMonitor\services\Suggestions;

/**
 * One place content references an image, ready to show.
 */
final class UsageReference
{
    public function __construct(
        public readonly UsageKind $kind,
        public readonly string $label,
        public readonly string $elementType,
        public readonly ?string $cpEditUrl,
        public readonly ?string $fieldName,
        /** The sites it is referenced in, or null for all of them. */
        public readonly ?string $siteName,
        public readonly ?string $nestedIn,
        /**
         * For an image embedded in text with `<img>` tags: the alt text each gives it there, ''
         * for none. Null for Assets fields and links.
         *
         * @var list<string>|null
         */
        public readonly ?array $contentAlts = null,
    ) {
    }

    /** Whether the content shows the image somewhere without alt text. */
    public function hasNoAltInContent(): bool
    {
        return $this->contentAlts !== null && in_array('', $this->contentAlts, true);
    }

    /**
     * Alt text the content gives the image that isn't the image's own.
     *
     * @return list<string>
     */
    public function otherAltsInContent(?string $assetAlt): array
    {
        return array_values(array_filter($this->contentAlts ?? [], static fn(string $alt): bool => $alt !== '' && !Suggestions::sameText($alt, (string)$assetAlt)));
    }
}
