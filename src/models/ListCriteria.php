<?php

namespace Tahadudhiya\AltMonitor\models;

use Craft;
use InvalidArgumentException;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\SuggestionSource;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\enums\UsageStatus;

/**
 * The filters, search and page of the image and suggestion lists, read strictly from a query
 * string: a malformed or unknown value is refused, never guessed at.
 */
final class ListCriteria
{
    public const PER_PAGE = 50;

    /** Values the `suggestion` filter of the image list takes besides a suggestion status. */
    public const SUGGESTION_NONE = 'none';

    /** The query parameter of the site filter. */
    public const SITE_PARAM = 'inSite';

    private function __construct(
        public readonly ?AltStatus $status = null,
        public readonly ?UsageStatus $usage = null,
        public readonly ?string $suggestion = null,
        public readonly ?SuggestionSource $source = null,
        public readonly ?SuggestionStatus $suggestionStatus = null,
        public readonly ?int $siteId = null,
        public readonly ?int $volumeId = null,
        public readonly string $search = '',
        public readonly int $page = 1,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @throws InvalidArgumentException naming what is malformed.
     */
    public static function fromParams(array $params): self
    {
        $string = static function(string $key) use ($params): ?string {
            $value = $params[$key] ?? null;

            if ($value === null || $value === '') {
                return null;
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException("The “{$key}” parameter must be text.");
            }

            return $value;
        };
        $enum = static function(string $key, string $class) use ($string): mixed {
            $value = $string($key);

            /** @var class-string<\BackedEnum> $class */
            return $value === null ? null : ($class::tryFrom($value) ?? throw new InvalidArgumentException("“{$value}” is not a valid “{$key}”."));
        };

        // Not `site`: Craft adds `site=` to control panel URLs for its own site switcher, which
        // would silently filter every list.
        $site = $string(self::SITE_PARAM);
        $siteId = $site === null ? null : (Craft::$app->getSites()->getSiteByHandle($site)->id ?? throw new InvalidArgumentException("There is no site “{$site}”."));
        $volume = $string('volume');
        $volumeId = $volume === null ? null : (Craft::$app->getVolumes()->getVolumeByHandle($volume)->id ?? throw new InvalidArgumentException("There is no volume “{$volume}”."));
        $suggestion = $string('suggestion');

        if ($suggestion !== null && $suggestion !== self::SUGGESTION_NONE && SuggestionStatus::tryFrom($suggestion) === null) {
            throw new InvalidArgumentException("“{$suggestion}” is not a valid “suggestion”.");
        }

        $page = $string('page') ?? '1';

        if (!preg_match('/^[1-9]\d{0,5}$/', $page)) {
            throw new InvalidArgumentException('The page must be a positive whole number.');
        }

        return new self(
            status: $enum('status', AltStatus::class),
            usage: $enum('usage', UsageStatus::class),
            suggestion: $suggestion,
            source: $enum('source', SuggestionSource::class),
            suggestionStatus: $enum('suggestionStatus', SuggestionStatus::class),
            siteId: $siteId === null ? null : (int)$siteId,
            volumeId: $volumeId === null ? null : (int)$volumeId,
            search: mb_substr(trim((string)$string('search')), 0, 255),
            page: (int)$page,
        );
    }

    /**
     * The query string of these criteria, with some values changed; null drops a value.
     *
     * @param array<string, mixed> $changes
     * @return array<string, string|int>
     */
    public function params(array $changes = []): array
    {
        $params = array_merge([
            'status' => $this->status?->value,
            'usage' => $this->usage?->value,
            'suggestion' => $this->suggestion,
            'source' => $this->source?->value,
            'suggestionStatus' => $this->suggestionStatus?->value,
            self::SITE_PARAM => $this->siteId !== null ? Craft::$app->getSites()->getSiteById($this->siteId)?->handle : null,
            'volume' => $this->volumeId !== null ? Craft::$app->getVolumes()->getVolumeById($this->volumeId)?->handle : null,
            'search' => $this->search !== '' ? $this->search : null,
            'page' => $this->page > 1 ? $this->page : null,
        ], $changes);

        return array_filter($params, static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    public function isFiltered(): bool
    {
        return array_diff_key($this->params(), ['page' => true]) !== [];
    }

    public function offset(): int
    {
        return ($this->page - 1) * self::PER_PAGE;
    }
}
