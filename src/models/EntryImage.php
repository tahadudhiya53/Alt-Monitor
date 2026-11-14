<?php

namespace Tahadudhiya\AltMonitor\models;

use craft\elements\Asset;
use Tahadudhiya\AltMonitor\enums\AltIssue;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\services\Suggestions;

/**
 * One image used in an entry, as the entry editor's box shows it: its alt text checked now (not
 * as last scanned), the suggestion waiting for it, and what the viewer may do with it.
 */
final class EntryImage
{
    /**
     * @param list<AltIssue> $issues
     */
    public function __construct(
        public readonly Asset $asset,
        public readonly int $auditSiteId,
        public readonly AltStatus $status,
        public readonly array $issues,
        public readonly bool $decorative,
        public readonly ?Suggestion $suggestion,
        public readonly string $fieldHandle,
        public readonly ?string $fieldName,
        public readonly bool $canSave,
    ) {
    }

    /** Missing or weak: what the box counts as a problem. “Needs review” is shown, not counted. */
    public function hasProblem(): bool
    {
        return !$this->decorative && in_array($this->status, [AltStatus::MISSING, AltStatus::WEAK], true);
    }

    /** Whether it needs a look at all: a problem, or something to review. */
    public function needsAttention(): bool
    {
        return $this->hasProblem() || (!$this->decorative && $this->status === AltStatus::REVIEW);
    }

    /** A suggestion waiting that would change the alt text, as {@see AuditItem::hasNewSuggestion()}. */
    public function hasNewSuggestion(): bool
    {
        return $this->suggestion !== null
            && $this->suggestion->status === SuggestionStatus::PENDING
            && $this->suggestion->suggestedAlt !== null
            && !Suggestions::sameText($this->suggestion->suggestedAlt, (string)$this->asset->alt);
    }

    /** `assetId-siteId`, the key the describer and translator use. */
    public function key(): string
    {
        return $this->asset->id . '-' . $this->auditSiteId;
    }
}
