<?php

namespace Tahadudhiya\AltMonitor\models;

use Tahadudhiya\AltMonitor\enums\UsageKind;

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
    ) {
    }
}
