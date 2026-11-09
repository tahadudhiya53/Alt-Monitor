<?php

namespace Tahadudhiya\AltMonitor\services;

use Tahadudhiya\AltMonitor\enums\SuggestionSource;
use Tahadudhiya\AltMonitor\models\ImageContext;
use Tahadudhiya\AltMonitor\models\SuggestionDraft;
use yii\base\Component;

/**
 * Rule-based suggestions: alt text built from what is known about an image, without looking at
 * it. Works with no AI at all.
 *
 * It tries, in order, a caption written for the image, a title someone gave it, a readable file
 * name, and the title of the content using it. Nothing is invented: the suggestion is only ever
 * those words, cleaned up. Since none of them is proof of what the image shows, every suggestion
 * but one taken from a caption is marked for review.
 */
class SuggestionGenerator extends Component
{
    public const BASIS_CAPTION = 'caption';
    public const BASIS_TITLE = 'title';
    public const BASIS_FILENAME = 'filename';
    public const BASIS_USAGE = 'usage';

    /** The longest rule-based suggestion. */
    private const MAX_LENGTH = 150;

    /** File name words that say nothing about the picture. */
    private const NOISE_WORDS = [
        'img', 'image', 'images', 'dsc', 'dscn', 'dcim', 'pxl', 'mvimg', 'photo', 'pic', 'picture', 'screenshot',
        'final', 'copy', 'edited', 'edit', 'version', 'web', 'hires', 'lowres', 'small', 'large', 'medium', 'thumb',
        'thumbnail', 'resized', 'cropped', 'crop', 'original', 'orig', 'new', 'old', 'draft', 'export', 'scaled',
        'compressed', 'optimized', 'optimised', 'retina', 'min', 'sm', 'md', 'lg', 'xl', 'xs', 'xxl', 'jpg', 'jpeg',
        'png', 'gif', 'webp', 'svg', 'avif', 'stock', 'shutterstock', 'getty', 'gettyimages', 'istock', 'adobestock',
        'unsplash', 'pexels', 'untitled', 'scan', 'file', 'asset', 'upload', 'uploaded', 'download',
    ];

    public function __construct(private readonly AltTextAnalyzer $analyzer, array $config = [])
    {
        parent::__construct($config);
    }

    public function suggest(ImageContext $context): ?SuggestionDraft
    {
        foreach ($context->captions as $caption) {
            $text = self::sentence($caption);

            if ($text !== null && !$this->analyzer->isWeak($text, $context->filename)) {
                return new SuggestionDraft($text, SuggestionSource::RULES, self::BASIS_CAPTION, false);
            }
        }

        $fromFilename = self::humanizeFilename($context->filename);

        // Craft titles new assets from their file name; such a title says no more than the name.
        if ($context->title !== null && $this->isOwnTitle($context->title, $context->filename, $fromFilename)) {
            $text = self::tidy($context->title);

            if ($text !== null && !$this->analyzer->isWeak($text, $context->filename)) {
                return new SuggestionDraft($text, SuggestionSource::RULES, self::BASIS_TITLE, true);
            }
        }

        if ($fromFilename !== null && !$this->analyzer->isWeak($fromFilename, $context->filename)) {
            return new SuggestionDraft($fromFilename, SuggestionSource::RULES, self::BASIS_FILENAME, true);
        }

        foreach ($context->usedOn as $title) {
            $text = self::tidy($title);

            if ($text !== null && !$this->analyzer->isWeak($text, $context->filename)) {
                return new SuggestionDraft($text, SuggestionSource::RULES, self::BASIS_USAGE, true);
            }
        }

        return null;
    }

    /**
     * Readable words from a file name, or null when it has none: the extension, camera and stock
     * prefixes, sizes, IDs and repeated words are dropped.
     */
    public static function humanizeFilename(string $filename): ?string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = (string)preg_replace(['/@\d+x\b/i', '/\b\d+x\d+\b/i'], ' ', $name);
        $name = (string)preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $name);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = [];

        foreach ($words as $word) {
            $lower = mb_strtolower($word);

            if (
                in_array($lower, self::NOISE_WORDS, true)
                || mb_strlen($word) < 2
                || preg_match('/^v?\d+$/i', $word)
                || (preg_match('/\d/', $word) && preg_match('/^[0-9a-f]{6,}$/i', $word))
                || isset($kept[$lower])
            ) {
                continue;
            }

            // Acronyms keep their capitals; everything else reads as a sentence.
            $kept[$lower] = mb_strlen($word) <= 5 && $word === mb_strtoupper($word) && preg_match('/\p{L}/u', $word) ? $word : $lower;
        }

        if (!array_filter($kept, static fn(string $word): bool => (bool)preg_match('/\p{L}{3,}/u', $word))) {
            return null;
        }

        return self::tidy(implode(' ', $kept));
    }

    private function isOwnTitle(string $title, string $filename, ?string $fromFilename): bool
    {
        $normalized = AltTextAnalyzer::normalize($title);

        return $normalized !== ''
            && $normalized !== AltTextAnalyzer::normalize(pathinfo($filename, PATHINFO_FILENAME))
            && $normalized !== AltTextAnalyzer::normalize((string)$fromFilename);
    }

    /** The first sentence of a text, tidied, or null when it is too long to be alt text. */
    private static function sentence(string $text): ?string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));
        $first = preg_split('/(?<=[.!?])\s+/u', $text, 2)[0] ?? '';

        return mb_strlen($first) > self::MAX_LENGTH ? null : self::tidy($first);
    }

    private static function tidy(string $text): ?string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)), " \t\n\r\0\x0B-_.,;:");

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return null;
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
