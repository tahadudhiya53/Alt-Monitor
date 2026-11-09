<?php

namespace Tahadudhiya\AltMonitor\models;

/**
 * What is known about an image besides its pixels: the asset's own details and the content it is
 * used in. Suggestions are built from it; none of it is proof of what the image shows.
 */
final class ImageContext
{
    /**
     * @param list<string> $captions Text from the asset's caption-like fields.
     * @param list<string> $usedOn Titles of content that references the image.
     */
    public function __construct(
        public readonly string $filename,
        public readonly ?string $title = null,
        public readonly array $captions = [],
        public readonly array $usedOn = [],
    ) {
    }
}
