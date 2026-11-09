<?php

namespace Tahadudhiya\AltMonitor\models;

use craft\elements\Asset;
use DateTime;
use Tahadudhiya\AltMonitor\enums\AltIssue;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\enums\UsageStatus;
use Tahadudhiya\AltMonitor\services\Suggestions;

/**
 * One row of the image list: an audited image in one site, with its current suggestion.
 */
final class AuditItem
{
    /**
     * @param list<AltIssue> $issues
     */
    public function __construct(
        public readonly Asset $asset,
        public readonly int $siteId,
        public readonly AltStatus $status,
        public readonly array $issues,
        public readonly bool $decorative,
        public readonly UsageStatus $usage,
        public readonly int $usageCount,
        public readonly ?Suggestion $suggestion,
        public readonly DateTime $dateScanned,
        public readonly ?DateTime $dateAltSaved,
        public readonly bool $canEdit,
    ) {
    }

    /**
     * Whether the image has a suggestion waiting that would change its alt text (one that only
     * repeats the current alt text is not worth showing).
     */
    public function hasNewSuggestion(): bool
    {
        return $this->suggestion !== null
            && $this->suggestion->status === SuggestionStatus::PENDING
            && $this->suggestion->suggestedAlt !== null
            && !Suggestions::sameText($this->suggestion->suggestedAlt, (string)$this->asset->alt);
    }

    /** The key the image list's form uses for this row. */
    public function key(): string
    {
        return $this->asset->id . '-' . $this->siteId;
    }
}
