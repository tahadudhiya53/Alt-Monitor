<?php

namespace Tahadudhiya\AltMonitor\models;

use craft\base\ElementInterface;

/**
 * One row of the Pages list: a piece of content (an entry, a global set…) with how many of the
 * images it uses need work, in one site.
 */
final class PageItem
{
    public function __construct(
        public readonly int $elementId,
        /** Null when the user may not see the content: it is then listed without its title. */
        public readonly ?ElementInterface $element,
        /** Images that need work, each counted once however many checks it fails. */
        public readonly int $problems,
        public readonly int $missing,
        public readonly int $weak,
        public readonly int $review,
        /** Images embedded in its text with no alt text there. */
        public readonly int $noAltInText,
    ) {
    }
}
