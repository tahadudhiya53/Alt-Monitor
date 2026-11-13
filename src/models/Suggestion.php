<?php

namespace Tahadudhiya\AltMonitor\models;

use DateTime;
use Tahadudhiya\AltMonitor\ai\Translations;
use Tahadudhiya\AltMonitor\enums\SuggestionSource;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\services\AiProviders;
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

    /**
     * Whether this is an English description for a site in another language: AI models describe
     * in English, and only the browser model's descriptions are translated, where a model exists
     * for the site's language. Rule-based suggestions reuse the site's own text, so they never are.
     */
    public function needsTranslation(): bool
    {
        // Only while it waits for review: once approved or rejected, there is nothing to translate.
        if ($this->status !== SuggestionStatus::PENDING || $this->source !== SuggestionSource::AI || $this->suggestedAlt === null || AiProviders::isTranslated($this->basis)) {
            return false;
        }

        $site = \Craft::$app->getSites()->getSiteById($this->siteId, true);

        return $site !== null && !Translations::isEnglish(Translations::languageFor($site));
    }

    /**
     * The model that can translate this English suggestion into its site's language, if it needs
     * translating and one exists.
     *
     * @return array{model: string, language: string, megabytes: int, name: string}|null
     */
    public function translation(): ?array
    {
        if (!$this->needsTranslation()) {
            return null;
        }

        $site = \Craft::$app->getSites()->getSiteById($this->siteId, true);

        $translation = $site !== null ? Translations::forLanguage(Translations::languageFor($site)) : null;

        return $translation !== null ? $translation + ['name' => Translations::languageName($translation['language'])] : null;
    }

    public function canApprove(): bool
    {
        return $this->status === SuggestionStatus::PENDING && $this->suggestedAlt !== null;
    }
}
