<?php

namespace Tahadudhiya\AltMonitor\services;

use Tahadudhiya\AltMonitor\enums\AltIssue;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\models\AltAnalysis;
use yii\base\Component;

/**
 * The rule-based alt text checks: the one place an alt text is classified.
 *
 * Rules only flag what is demonstrably unhelpful (a file name, a lone generic word, an ID…).
 * Short text is not a problem by itself, and empty text is not always one either: an image a
 * person marked decorative is meant to have none, and one whose file name suggests decoration is
 * left for review. Nothing here can tell whether a description is accurate, so a “good” result is
 * only the absence of a known problem.
 */
class AltTextAnalyzer extends Component
{
    /** Alt text longer than this is flagged for review. */
    public const LONG_TEXT = 250;

    /** Words and phrases that, alone, describe nothing. */
    public const GENERIC_TERMS = [
        'alt', 'alt text', 'alternative text', 'asset', 'background', 'banner', 'bg', 'blank', 'default',
        'featured image', 'figure', 'file', 'graphic', 'graphics', 'header', 'header image', 'hero',
        'hero image', 'icon', 'illustration', 'image', 'image here', 'images', 'img', 'insert alt text',
        'logo', 'media', 'n/a', 'na', 'none', 'null', 'photo', 'photograph', 'photos', 'pic', 'pics',
        'picture', 'pictures', 'placeholder', 'screenshot', 'screen shot', 'spacer', 'stock photo',
        'test', 'thumb', 'thumbnail', 'todo', 'undefined', 'untitled',
    ];

    private const IMAGE_EXTENSIONS = 'jpe?g|png|gif|webp|svg|avif|heic|heif|tiff?|bmp';

    /** File name words suggesting an image is decoration rather than content. */
    private const DECORATIVE_WORDS = 'spacer|divider|separator|decoration|decorative|ornament|flourish|border|shadow|background|bg|pattern|texture|gradient|transparent|pixel';

    /** @var list<string> Further generic terms from settings. */
    public array $additionalGenericTerms = [];

    public function analyze(?string $alt, string $filename, bool $decorative = false): AltAnalysis
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', (string)$alt));

        if ($text === '') {
            return match (true) {
                $decorative => new AltAnalysis(AltStatus::GOOD, [AltIssue::DECORATIVE]),
                self::looksDecorative($filename) => new AltAnalysis(AltStatus::REVIEW, [AltIssue::POSSIBLY_DECORATIVE]),
                default => new AltAnalysis(AltStatus::MISSING, [AltIssue::EMPTY]),
            };
        }

        $weak = $this->weaknesses($text, $filename);

        if ($weak !== []) {
            return new AltAnalysis(AltStatus::WEAK, $weak);
        }

        $review = [];
        $normalized = self::normalize($text);

        if (preg_match('/^(?:an? |the )?(?:image|picture|photo|photograph|graphic) (?:of|showing)\b/', $normalized)) {
            $review[] = AltIssue::REDUNDANT_PREFIX;
        }

        if (mb_strlen($text) > self::LONG_TEXT) {
            $review[] = AltIssue::TOO_LONG;
        }

        if (mb_strlen($text) < 3) {
            $review[] = AltIssue::VERY_SHORT;
        }

        return new AltAnalysis($review === [] ? AltStatus::GOOD : AltStatus::REVIEW, $review);
    }

    /**
     * Whether text would be weak alt text for the file, for checking a suggestion before it is
     * offered.
     */
    public function isWeak(string $text, string $filename): bool
    {
        return $this->weaknesses(trim($text), $filename) !== [];
    }

    /**
     * Lowercase words and digits only, single-spaced, with camelCase and separators split.
     */
    public static function normalize(string $text): string
    {
        $text = (string)preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $text);
        $text = mb_strtolower($text);
        $text = (string)preg_replace('/[^\p{L}\p{N}\/]+/u', ' ', $text);

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    public static function looksDecorative(string $filename): bool
    {
        return (bool)preg_match('/(?:^|[^a-z])(?:' . self::DECORATIVE_WORDS . ')(?:[^a-z]|$)/i', pathinfo($filename, PATHINFO_FILENAME));
    }

    /**
     * @return list<AltIssue>
     */
    private function weaknesses(string $text, string $filename): array
    {
        $issues = [];
        $normalized = self::normalize($text);
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        // The file name as it is (“IMG_1234”, “team-photo.jpg”), not words that happen to match
        // it: “Harbour at night” is a description, even of harbour-at-night.jpg.
        if (
            in_array(mb_strtolower($text), [mb_strtolower($filename), mb_strtolower($basename)], true)
            || ($normalized === self::normalize($basename) && !str_contains($text, ' '))
        ) {
            $issues[] = AltIssue::FILENAME;
        }

        if (preg_match('/\.(?:' . self::IMAGE_EXTENSIONS . ')\b/i', $text)) {
            $issues[] = AltIssue::FILE_EXTENSION;
        }

        if (self::isIdentifier($normalized)) {
            $issues[] = AltIssue::IDENTIFIER;
        } elseif ($this->isGeneric($normalized)) {
            $issues[] = AltIssue::GENERIC;
        }

        if (self::isRepetitive($normalized)) {
            $issues[] = AltIssue::REPETITIVE;
        }

        return $issues;
    }

    private function isGeneric(string $normalized): bool
    {
        $terms = array_merge(self::GENERIC_TERMS, array_map(self::normalize(...), $this->additionalGenericTerms));
        $bare = trim((string)preg_replace(['/^(?:an?|the|my|our|your) /', '/ \d+$/'], '', $normalized));

        return in_array($normalized, $terms, true) || in_array($bare, $terms, true);
    }

    private static function isIdentifier(string $normalized): bool
    {
        $compact = str_replace(' ', '', $normalized);

        return $compact !== '' && (
            // Camera and screenshot names: IMG 1234, DSC_0042, PXL 20240101, Screenshot 2024 01 01…
            (bool)preg_match('/^(?:img|image|dsc|dscn|dcim|pxl|mvimg|photo|pic|scan|screenshot|screen shot|untitled|p)?\s*[\d ]+$/', $normalized)
            // Hashes and UUIDs.
            || ((bool)preg_match('/^[0-9a-f]{8,}$/', $compact) && (bool)preg_match('/\d/', $compact))
            // Mostly digits.
            || (mb_strlen($compact) >= 4 && preg_match_all('/\d/', $compact) / mb_strlen($compact) > 0.6)
        );
    }

    private static function isRepetitive(string $normalized): bool
    {
        $words = array_values(array_filter(explode(' ', $normalized), static fn(string $word): bool => mb_strlen($word) > 2));

        if (count($words) < 3) {
            return false;
        }

        $counts = array_count_values($words);

        return max($counts) >= 3 || (count($words) >= 4 && count($counts) / count($words) < 0.5);
    }
}
