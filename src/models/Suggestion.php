<?php

namespace Tahadudhiya\AltMonitor\models;

use DateTime;
use Tahadudhiya\AltMonitor\enums\SuggestionSource;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\services\SuggestionGenerator;

/**
 * The current suggestion for one image in one site.
 */
final class Suggestion
{
    public function __construct(
        public readonly int $id,
        public readonly int $assetId,
        public readonly int $siteId,
        public readonly ?string $suggestedAlt,
        public readonly SuggestionSource $source,
        public readonly SuggestionStatus $status,
        public readonly bool $needsReview,
        public readonly ?string $basis,
        public readonly ?string $error,
        public readonly ?DateTime $dateGenerated,
    ) {
    }

    /** Where the suggestion came from, in words. */
    public function basisLabel(): string
    {
        if ($this->source === SuggestionSource::AI) {
            return (string)$this->basis;
        }

        return match ($this->basis) {
            SuggestionGenerator::BASIS_CAPTION => \Craft::t('alt-monitor', 'From the caption'),
            SuggestionGenerator::BASIS_TITLE => \Craft::t('alt-monitor', 'From the asset title'),
            SuggestionGenerator::BASIS_FILENAME => \Craft::t('alt-monitor', 'From the file name'),
            SuggestionGenerator::BASIS_USAGE => \Craft::t('alt-monitor', 'From the content using it'),
            default => '',
        };
    }

    public function canApprove(): bool
    {
        return $this->status === SuggestionStatus::PENDING && $this->suggestedAlt !== null;
    }
}
