<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Queue;
use DateTime;
use Tahadudhiya\AltMonitor\ai\Translations;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\SuggestionSource;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\errors\AiProviderException;
use Tahadudhiya\AltMonitor\errors\AltTextException;
use Tahadudhiya\AltMonitor\jobs\GenerateAltTextJob;
use Tahadudhiya\AltMonitor\models\AuditPage;
use Tahadudhiya\AltMonitor\models\ImageContext;
use Tahadudhiya\AltMonitor\models\ListCriteria;
use Tahadudhiya\AltMonitor\models\Suggestion;
use Tahadudhiya\AltMonitor\models\SuggestionDraft;
use Tahadudhiya\AltMonitor\records\AuditRecord;
use Tahadudhiya\AltMonitor\records\SuggestionRecord;
use yii\base\Component;

/**
 * Suggestions: storing them (described from the image in the browser, or generated on the server
 * by Ollama where it is configured), reviewing them, and approving them onto the asset. The only
 * writer of `altmonitor_suggestions`.
 *
 * There is one current suggestion per image and site; regenerating replaces it. Nothing reaches
 * an asset until a person approves it.
 */
class Suggestions extends Component
{
    /** Images queued per job when generating for a selection. */
    public const JOB_SIZE = 5;

    /** Seconds after which a suggestion still marked as generating may be queued again. */
    public const RESERVATION_TIMEOUT = 3600;

    /**
     * Whether suggestions are generated on the server (Ollama in the queue) rather than in the
     * browser.
     */
    public function generatesOnServer(): bool
    {
        return AltMonitor::getInstance()->getAiProviders()->getProvider() !== null;
    }

    /**
     * Stores a description the browser's model wrote for an image the user may view. One that only
     * repeats the image's current alt text is not stored (null is returned).
     *
     * `$translated` is the browser saying it translated the description into the site's language;
     * the basis names the model only when the site's language has one.
     *
     * @throws AltTextException when there is no such image, or the description isn't usable.
     */
    public function storeDescription(int $assetId, int $siteId, string $description, User $user, bool $translated = false): ?Suggestion
    {
        $plugin = AltMonitor::getInstance();
        $asset = $this->viewableImage($assetId, $siteId, $user);

        try {
            $text = $plugin->getAiProviders()->getPromptBuilder()->parse(mb_substr($description, 0, 2000));
        } catch (AiProviderException $exception) {
            throw new AltTextException($exception->getMessage(), 0, $exception);
        }

        $text = AltTextEditor::sanitize($text);

        if ($plugin->getAnalyzer()->isWeak($text, (string)$asset->getFilename())) {
            throw new AltTextException(Craft::t('alt-monitor', 'The description couldn’t be used as alt text.'));
        }

        // Repeating the alt text the image already has suggests nothing; any suggestion already
        // waiting is left as it is.
        if (self::sameText($text, (string)$asset->alt)) {
            return null;
        }

        $translation = $translated ? Translations::forLanguage(Translations::languageFor($asset->getSite())) : null;
        $basis = AiProviders::browserModelLabel($plugin->getSettings()->browserModel, $translation['model'] ?? null);
        $this->store($assetId, $siteId, new SuggestionDraft($text, SuggestionSource::AI, $basis, true), null);

        return $this->get($assetId, $siteId) ?? throw new \LogicException('A suggestion just stored could not be read.');
    }

    /**
     * Replaces an English suggestion waiting for review with its translation into the site's
     * language, made in the browser. It stays a suggestion waiting for review; its basis adds the
     * translation model.
     *
     * @throws AltTextException when there is no such suggestion, it can't be translated, or the
     * translation isn't usable.
     */
    public function storeTranslation(int $id, string $translated, User $user): Suggestion
    {
        $suggestion = $this->getById($id) ?? throw new AltTextException(Craft::t('alt-monitor', 'There is no such suggestion.'));
        $asset = $this->viewableImage($suggestion->assetId, $suggestion->siteId, $user);
        $translation = $suggestion->translation()
            ?? throw new AltTextException(Craft::t('alt-monitor', 'This suggestion can’t be translated.'));
        $text = AltTextEditor::sanitize($translated);

        if ($text === '' || AltMonitor::getInstance()->getAnalyzer()->isWeak($text, (string)$asset->getFilename())) {
            throw new AltTextException(Craft::t('alt-monitor', 'The translation couldn’t be used as alt text.'));
        }

        Db::update(SuggestionRecord::TABLE, [
            'suggestedAlt' => $text,
            'basis' => mb_substr(AiProviders::translatedBasis($suggestion->basis, $translation['model']), 0, 255),
        ], ['id' => $id]);

        return $this->get($suggestion->assetId, $suggestion->siteId) ?? throw new \LogicException('A suggestion just translated could not be read.');
    }

    /** Whether two alt texts say the same, ignoring case, spacing and a final full stop. */
    public static function sameText(string $a, string $b): bool
    {
        $normalize = static fn(string $text): string => rtrim(mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $text))), '.');

        return $normalize($a) !== '' && $normalize($a) === $normalize($b);
    }

    /**
     * An image the user may view, in the given site.
     *
     * @throws AltTextException when there is none.
     */
    public function viewableImage(int $assetId, int $siteId, User $user): Asset
    {
        $asset = $assetId > 0 ? Asset::find()->id($assetId)->siteId($siteId)->status(null)->one() : null;

        if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE || !AltMonitor::getInstance()->getAssetScanner()->userMay($asset, $user)) {
            throw new AltTextException(Craft::t('alt-monitor', 'There is no such image.'));
        }

        return $asset;
    }

    /**
     * Marks images as being generated, leaving out those already waiting in the queue.
     *
     * @param list<array{0: int, 1: int}> $pairs Asset and site IDs.
     * @return list<array{0: int, 1: int, 2?: string}> The pairs reserved, with the status of the
     * suggestion they had (kept if generating a new one fails), where it had one.
     */
    public function reserve(array $pairs): array
    {
        $current = $this->forPairs($pairs);
        $cutoff = Db::prepareDateForDb(new DateTime('@' . (time() - self::RESERVATION_TIMEOUT)));
        $queuedRecently = $pairs === [] ? [] : (new Query())
            ->select(['assetId', 'siteId'])
            ->from(SuggestionRecord::TABLE)
            ->where(['status' => SuggestionStatus::QUEUED->value])
            ->andWhere(['>=', 'dateUpdated', $cutoff])
            ->andWhere(['or', ...array_map(static fn(array $pair): array => ['assetId' => $pair[0], 'siteId' => $pair[1]], $pairs)])
            ->all();
        $busy = array_flip(array_map(static fn(array $row): string => $row['assetId'] . '-' . $row['siteId'], $queuedRecently));
        $reserved = [];

        foreach ($pairs as [$assetId, $siteId]) {
            $key = "$assetId-$siteId";

            if (isset($busy[$key]) || isset($reserved[$key])) {
                continue;
            }

            $existing = $current[$key] ?? null;
            Db::upsert(SuggestionRecord::TABLE, [
                'assetId' => $assetId,
                'siteId' => $siteId,
                'source' => $existing?->source->value ?? SuggestionSource::RULES->value,
                'status' => SuggestionStatus::QUEUED->value,
            ], ['status' => SuggestionStatus::QUEUED->value, 'error' => null]);
            // Text from a run that failed or died keeps its failed status, but is never lost.
            $reserved[$key] = $existing?->suggestedAlt !== null
                ? [$assetId, $siteId, (in_array($existing->status, [SuggestionStatus::QUEUED, SuggestionStatus::FAILED], true) ? SuggestionStatus::FAILED : $existing->status)->value]
                : [$assetId, $siteId];
        }

        return array_values($reserved);
    }

    /**
     * Reserves images and queues jobs generating their suggestions.
     *
     * @param list<array{0: int, 1: int}> $pairs
     * @return int How many images were queued.
     */
    public function queue(array $pairs): int
    {
        $reserved = $this->reserve($pairs);

        foreach (array_chunk($reserved, self::JOB_SIZE) as $chunk) {
            Queue::push(new GenerateAltTextJob(['targets' => $chunk]));
        }

        return count($reserved);
    }

    /**
     * Queues a walk generating suggestions for every image with missing or weak alt text that has
     * none, optionally only in some volumes.
     *
     * @param list<int>|null $volumeIds
     */
    public function queueForAllWanting(?array $volumeIds = null): void
    {
        Queue::push(new GenerateAltTextJob(['walk' => true, 'volumeIds' => $volumeIds]));
    }

    /**
     * Puts back the suggestion an image had before it was reserved, when no new one could be
     * made, with the reason.
     *
     * @return bool Whether there was one to put back.
     */
    public function keepPrevious(int $assetId, int $siteId, SuggestionStatus $previous, ?string $reason): bool
    {
        if ($this->get($assetId, $siteId)?->suggestedAlt === null) {
            return false;
        }

        $message = $reason !== null && $reason !== ''
            ? Craft::t('alt-monitor', 'No new suggestion could be made ({reason}), so the last one was kept.', ['reason' => $reason])
            : Craft::t('alt-monitor', 'No new suggestion could be made, so the last one was kept.');
        Db::update(SuggestionRecord::TABLE, ['status' => $previous->value, 'error' => mb_substr($message, 0, 1000)], ['assetId' => $assetId, 'siteId' => $siteId], updateTimestamp: false);

        return true;
    }

    /** Records that generating an image's suggestion failed, so it is no longer shown as generating. */
    public function markFailed(int $assetId, int $siteId, string $error): void
    {
        Db::update(SuggestionRecord::TABLE, ['status' => SuggestionStatus::FAILED->value, 'error' => mb_substr($error, 0, 1000)], ['assetId' => $assetId, 'siteId' => $siteId]);
    }

    /**
     * Generates and stores the suggestion for one image now, on the server: by Ollama when it is
     * configured, and from the caption, title or file name only where the settings allow it.
     *
     * @param SuggestionStatus|null $previous The status of the suggestion the image had before it
     * was reserved. When no new one can be made, that suggestion is kept, with the reason.
     */
    public function generate(int $assetId, int $siteId, ?SuggestionStatus $previous = null): ?Suggestion
    {
        $mutex = Craft::$app->getMutex();
        $lock = "altmonitor:suggest:$assetId-$siteId";

        if (!$mutex->acquire($lock)) {
            return $this->get($assetId, $siteId);
        }

        try {
            $asset = Asset::find()->id($assetId)->siteId($siteId)->status(null)->one();

            if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
                $this->forget($assetId, $siteId);

                return null;
            }

            [$draft, $error] = $this->draft($asset);

            if ($draft === null && $previous !== null && $this->keepPrevious($assetId, $siteId, $previous, $error)) {
                return $this->get($assetId, $siteId);
            }

            $this->store($assetId, $siteId, $draft, $error);

            return $this->get($assetId, $siteId);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * What the image and its context are known to be, for generators.
     */
    public function contextFor(Asset $asset): ImageContext
    {
        $plugin = AltMonitor::getInstance();
        $captions = [];
        $layout = $asset->getFieldLayout();

        foreach ($plugin->getSettings()->contextFieldHandles as $handle) {
            if ($layout?->getFieldByHandle($handle) === null) {
                continue;
            }

            $value = $asset->getFieldValue($handle);

            if (is_string($value) || $value instanceof \Stringable) {
                $text = trim(strip_tags((string)$value));

                if ($text !== '') {
                    $captions[] = $text;
                }
            }
        }

        return new ImageContext(
            filename: (string)$asset->getFilename(),
            title: $asset->title,
            captions: $captions,
            usedOn: $plugin->getContentUsage()->titles((int)$asset->id),
        );
    }

    /**
     * Approves a suggestion: its text, or the edited text given, is saved as the image's alt text.
     *
     * @throws AltTextException when it can't be.
     */
    public function approve(int $id, ?string $editedText, User $user): Suggestion
    {
        $suggestion = $this->getById($id) ?? throw new AltTextException(Craft::t('alt-monitor', 'There is no such suggestion.'));

        if (!$suggestion->canApprove()) {
            throw new AltTextException(Craft::t('alt-monitor', 'Only suggestions waiting for review can be approved.'));
        }

        $text = AltTextEditor::sanitize($editedText ?? (string)$suggestion->suggestedAlt);

        if ($text === '') {
            throw new AltTextException(Craft::t('alt-monitor', 'Alt text can’t be empty. Mark the image as decorative instead.'));
        }

        AltMonitor::getInstance()->getAltTextEditor()->save($suggestion->assetId, $suggestion->siteId, $text, $user);
        Db::update(SuggestionRecord::TABLE, ['status' => SuggestionStatus::APPROVED->value, 'suggestedAlt' => $text], ['id' => $id]);

        return $this->get($suggestion->assetId, $suggestion->siteId) ?? $suggestion;
    }

    /**
     * Approves several suggestions, each on its own: one failing doesn't stop the others.
     *
     * @param list<int> $ids
     * @param array<int, string> $editedTexts Edited text by suggestion ID, where it was edited.
     * @return array<int, string|null> For each ID, null when approved, or why it wasn't.
     */
    public function approveMany(array $ids, array $editedTexts, User $user): array
    {
        $results = [];

        foreach (array_unique($ids) as $id) {
            try {
                $this->approve($id, $editedTexts[$id] ?? null, $user);
                $results[$id] = null;
            } catch (AltTextException $exception) {
                $results[$id] = $exception->getMessage();
            }
        }

        return $results;
    }

    /**
     * Marks the waiting suggestion for an image approved when the image was saved with its text,
     * as happens after “Use” in Craft's asset editor fills the Alt Text field with it.
     *
     * @return bool Whether a suggestion was marked approved.
     */
    public function markUsedIfSaved(int $assetId, int $siteId, ?string $alt): bool
    {
        $suggestion = $this->get($assetId, $siteId);

        if (
            $alt === null || $alt === ''
            || $suggestion?->status !== SuggestionStatus::PENDING
            || $suggestion->suggestedAlt === null
            || !self::sameText($suggestion->suggestedAlt, $alt)
        ) {
            return false;
        }

        Db::update(SuggestionRecord::TABLE, ['status' => SuggestionStatus::APPROVED->value, 'suggestedAlt' => $alt], ['id' => $suggestion->id]);

        return true;
    }

    /**
     * @throws AltTextException when there is no such suggestion, or it isn't waiting for review.
     */
    public function reject(int $id, User $user): void
    {
        $suggestion = $this->getById($id) ?? throw new AltTextException(Craft::t('alt-monitor', 'There is no such suggestion.'));
        $this->viewableImage($suggestion->assetId, $suggestion->siteId, $user);

        if ($suggestion->status !== SuggestionStatus::PENDING && $suggestion->status !== SuggestionStatus::FAILED) {
            throw new AltTextException(Craft::t('alt-monitor', 'Only suggestions waiting for review can be rejected.'));
        }

        Db::update(SuggestionRecord::TABLE, ['status' => SuggestionStatus::REJECTED->value], ['id' => $id]);
    }

    public function forget(int $assetId, ?int $siteId = null): void
    {
        Db::delete(SuggestionRecord::TABLE, array_filter(['assetId' => $assetId, 'siteId' => $siteId], static fn(?int $value): bool => $value !== null));
    }

    /** Removes suggestions for images that are no longer audited. */
    public function pruneUnaudited(): int
    {
        $ids = (new Query())
            ->select(['suggestions.id'])
            ->from(['suggestions' => SuggestionRecord::TABLE])
            ->leftJoin(['audits' => AuditRecord::TABLE], '[[audits.assetId]] = [[suggestions.assetId]] AND [[audits.siteId]] = [[suggestions.siteId]]')
            ->where(['audits.id' => null])
            ->column();

        return $ids === [] ? 0 : Db::delete(SuggestionRecord::TABLE, ['id' => $ids]);
    }

    public function get(int $assetId, int $siteId): ?Suggestion
    {
        $row = (new Query())->from(SuggestionRecord::TABLE)->where(['assetId' => $assetId, 'siteId' => $siteId])->one();

        return $row ? self::model($row) : null;
    }

    public function getById(int $id): ?Suggestion
    {
        $row = (new Query())->from(SuggestionRecord::TABLE)->where(['id' => $id])->one();

        return $row ? self::model($row) : null;
    }

    /**
     * Suggestions for several images at once, keyed `assetId-siteId`.
     *
     * @param list<array{0: int, 1: int}> $pairs
     * @return array<string, Suggestion>
     */
    public function forPairs(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $rows = (new Query())
            ->from(SuggestionRecord::TABLE)
            ->where(['or', ...array_map(static fn(array $pair): array => ['assetId' => $pair[0], 'siteId' => $pair[1]], $pairs)])
            ->all();
        $suggestions = [];

        foreach ($rows as $row) {
            $suggestions[$row['assetId'] . '-' . $row['siteId']] = self::model($row);
        }

        return $suggestions;
    }

    /**
     * @param list<int> $volumeIds
     */
    public function count(SuggestionStatus $status, array $volumeIds, ?User $user = null): int
    {
        $query = $this->query($volumeIds)->andWhere(['suggestions.status' => $status->value]);
        $visible = AltMonitor::getInstance()->getAudits()->visibleCondition($user);

        // For a person: only those they may see, as the list shows.
        if ($visible !== []) {
            $query->innerJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[suggestions.assetId]]')->andWhere($visible);
        }

        return (int)$query->count();
    }

    /**
     * One page of the suggestions list, with each suggestion's audit item.
     *
     * @param list<int> $volumeIds Volumes the user may view.
     * @return AuditPage<\Tahadudhiya\AltMonitor\models\AuditItem>
     */
    public function page(ListCriteria $criteria, array $volumeIds, ?User $user): AuditPage
    {
        $query = $this->query($criteria->volumeId !== null ? array_values(array_intersect($volumeIds, [$criteria->volumeId])) : $volumeIds)
            ->andFilterWhere([
                'suggestions.status' => $criteria->suggestionStatus->value ?? ($criteria->isFiltered() ? null : SuggestionStatus::PENDING->value),
                'suggestions.source' => $criteria->source?->value,
                'suggestions.siteId' => $criteria->siteId,
            ]);

        if ($criteria->search !== '') {
            $query->andWhere(['like', 'suggestions.suggestedAlt', $criteria->search]);
        }

        $query
            ->innerJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[suggestions.assetId]]')
            ->andWhere(AltMonitor::getInstance()->getAudits()->visibleCondition($user));

        $total = (int)$query->count();
        $rows = $query
            ->select(['suggestions.assetId', 'suggestions.siteId'])
            ->orderBy(['suggestions.dateUpdated' => SORT_DESC, 'suggestions.id' => SORT_DESC])
            ->offset($criteria->offset())
            ->limit(ListCriteria::PER_PAGE)
            ->all();
        $pairs = array_map(static fn(array $row): array => [(int)$row['assetId'], (int)$row['siteId']], $rows);

        return new AuditPage(AltMonitor::getInstance()->getAudits()->itemsFor($pairs, $user), $total, $criteria->page, ListCriteria::PER_PAGE);
    }

    /**
     * @param list<int> $volumeIds
     */
    private function query(array $volumeIds): Query
    {
        return (new Query())
            ->from(['suggestions' => SuggestionRecord::TABLE])
            ->innerJoin(['audits' => AuditRecord::TABLE], '[[audits.assetId]] = [[suggestions.assetId]] AND [[audits.siteId]] = [[suggestions.siteId]]')
            ->where(['audits.volumeId' => $volumeIds]);
    }

    /**
     * @return array{0: SuggestionDraft|null, 1: string|null} The suggestion, and why AI didn't make it.
     */
    private function draft(Asset $asset): array
    {
        $plugin = AltMonitor::getInstance();
        $ai = $plugin->getAiProviders();
        $provider = $ai->getProvider();
        $context = $this->contextFor($asset);
        $error = null;

        if ($provider !== null) {
            try {
                $image = $ai->getImageProcessor()->prepare($asset);
                $text = $ai->getPromptBuilder()->parse($provider->describe($image, $ai->getPromptBuilder()->build($context)));

                if ($plugin->getAnalyzer()->isWeak($text, $context->filename)) {
                    throw new AiProviderException(Craft::t('alt-monitor', 'The description couldn’t be used as alt text.'));
                }

                return [new SuggestionDraft($text, SuggestionSource::AI, $provider->modelName(), true), null];
            } catch (AiProviderException $exception) {
                $error = $exception->getMessage();
            }
        }

        if (!$plugin->getSettings()->fallbackToRules) {
            return [null, $error ?? Craft::t('alt-monitor', 'Suggestions are made in your browser. Use “Describe” on this image.')];
        }

        return [$plugin->getSuggestionGenerator()->suggest($context), $error];
    }

    private function store(int $assetId, int $siteId, ?SuggestionDraft $draft, ?string $aiError): void
    {
        $error = $draft === null
            ? ($aiError ?? Craft::t('alt-monitor', 'Nothing about the image (caption, title, file name or where it’s used) said enough to suggest alt text.'))
            : ($aiError !== null ? Craft::t('alt-monitor', 'The AI couldn’t describe the image ({reason}), so this suggestion comes from the image’s details.', ['reason' => $aiError]) : null);
        $columns = [
            'suggestedAlt' => $draft?->text,
            'source' => ($draft->source ?? (AltMonitor::getInstance()->getAiProviders()->getProvider() !== null ? SuggestionSource::AI : SuggestionSource::RULES))->value,
            'status' => ($draft !== null ? SuggestionStatus::PENDING : SuggestionStatus::FAILED)->value,
            'needsReview' => $draft->needsReview ?? true,
            'basis' => $draft !== null ? mb_substr($draft->basis, 0, 255) : null,
            'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
            'dateGenerated' => Db::prepareDateForDb(new DateTime()),
        ];

        Db::upsert(SuggestionRecord::TABLE, ['assetId' => $assetId, 'siteId' => $siteId] + $columns, $columns);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function model(array $row): Suggestion
    {
        return new Suggestion(
            id: (int)$row['id'],
            assetId: (int)$row['assetId'],
            siteId: (int)$row['siteId'],
            suggestedAlt: $row['suggestedAlt'],
            source: SuggestionSource::from($row['source']),
            status: SuggestionStatus::from($row['status']),
            needsReview: (bool)$row['needsReview'],
            basis: $row['basis'],
            error: $row['error'],
            dateGenerated: $row['dateGenerated'] ? (DateTimeHelper::toDateTime($row['dateGenerated']) ?: null) : null,
        );
    }
}
