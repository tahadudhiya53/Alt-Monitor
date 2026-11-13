<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\enums\UsageStatus;
use Tahadudhiya\AltMonitor\models\AltAnalysis;
use Tahadudhiya\AltMonitor\models\AuditItem;
use Tahadudhiya\AltMonitor\models\AuditPage;
use Tahadudhiya\AltMonitor\models\DashboardStats;
use Tahadudhiya\AltMonitor\models\ListCriteria;
use Tahadudhiya\AltMonitor\records\AuditRecord;
use Tahadudhiya\AltMonitor\records\SuggestionRecord;
use Tahadudhiya\AltMonitor\records\UsageRecord;
use yii\base\Component;

/**
 * The audit of every image: the only writer of `altmonitor_audits`, and the image list and
 * dashboard numbers read from it.
 *
 * Lists show only images in volumes the user may view, and read the assets themselves through
 * Craft's element queries, so what is shown is always the asset as it is now.
 */
class Audits extends Component
{
    /**
     * Records an analysis. The decorative flag and usage status, which scans don't decide, are
     * kept as they were.
     */
    public function save(Asset $asset, int $siteId, AltAnalysis $analysis, string $hash, ?int $scanId): void
    {
        Db::upsert(AuditRecord::TABLE, [
            'assetId' => $asset->id,
            'siteId' => $siteId,
            'volumeId' => $asset->volumeId,
            'scanId' => $scanId,
            'altStatus' => $analysis->status->value,
            'issues' => $analysis->issuesString(),
            'altHash' => $hash,
            'dateScanned' => Db::prepareDateForDb(new DateTime()),
        ], [
            'volumeId' => $asset->volumeId,
            'scanId' => $scanId,
            'altStatus' => $analysis->status->value,
            'issues' => $analysis->issuesString(),
            'altHash' => $hash,
            'dateScanned' => Db::prepareDateForDb(new DateTime()),
        ]);
    }

    /**
     * What is stored for images in one site, by asset ID.
     *
     * @param list<int> $assetIds
     * @return array<int, array{decorative: bool, altHash: string}>
     */
    public function stored(int $siteId, array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['assetId', 'decorative', 'altHash'])
            ->from(AuditRecord::TABLE)
            ->where(['siteId' => $siteId, 'assetId' => $assetIds])
            ->all();
        $stored = [];

        foreach ($rows as $row) {
            $stored[(int)$row['assetId']] = ['decorative' => (bool)$row['decorative'], 'altHash' => (string)$row['altHash']];
        }

        return $stored;
    }

    /**
     * Records that a scan saw images it found unchanged.
     *
     * @param list<int> $assetIds
     */
    public function touch(int $siteId, array $assetIds, ?int $scanId): void
    {
        if ($assetIds !== []) {
            Db::update(AuditRecord::TABLE, ['dateScanned' => Db::prepareDateForDb(new DateTime()), 'scanId' => $scanId], ['siteId' => $siteId, 'assetId' => $assetIds]);
        }
    }

    public function setDecorative(int $assetId, int $siteId, bool $decorative): void
    {
        Db::update(AuditRecord::TABLE, ['decorative' => $decorative], ['assetId' => $assetId, 'siteId' => $siteId]);
    }

    public function markAltSaved(int $assetId, int $siteId): void
    {
        Db::update(AuditRecord::TABLE, ['dateAltSaved' => Db::prepareDateForDb(new DateTime())], ['assetId' => $assetId, 'siteId' => $siteId]);
    }

    /**
     * Forgets an image, in every site or one.
     */
    public function forget(int $assetId, ?int $siteId = null): void
    {
        Db::delete(AuditRecord::TABLE, array_filter(['assetId' => $assetId, 'siteId' => $siteId], static fn(?int $value): bool => $value !== null));
    }

    /**
     * Removes audits a full scan did not reach: assets deleted, no longer images, or in sites and
     * volumes no longer scanned.
     */
    public function pruneNotScannedSince(string $since): int
    {
        return Db::delete(AuditRecord::TABLE, ['<', 'dateScanned', $since]);
    }

    /**
     * Removes audits of assets in the trash or no longer images. Hard deletion needs nothing:
     * foreign keys remove those rows.
     */
    public function pruneGone(): int
    {
        $gone = (new Query())
            ->select(['elements.id'])
            ->from(['elements' => Table::ELEMENTS])
            ->leftJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[elements.id]]')
            ->where(['elements.id' => (new Query())->select(['assetId'])->from(AuditRecord::TABLE)])
            ->andWhere(['or', ['not', ['elements.dateDeleted' => null]], ['assets.id' => null], ['not', ['assets.kind' => Asset::KIND_IMAGE]]])
            ->column();

        return $gone === [] ? 0 : Db::delete(AuditRecord::TABLE, ['assetId' => $gone]);
    }

    /**
     * Sets every audited image's usage status from the usage a scan found.
     */
    public function applyUsageStatuses(): void
    {
        $used = (new Query())->select(['assetId'])->distinct()->from(UsageRecord::TABLE);

        Db::update(AuditRecord::TABLE, ['usageStatus' => UsageStatus::USED->value], ['assetId' => $used], updateTimestamp: false);
        Db::update(AuditRecord::TABLE, ['usageStatus' => UsageStatus::UNUSED->value], ['not', ['assetId' => $used]], updateTimestamp: false);
    }

    /**
     * Audits that want a suggestion and have none yet (or whose last attempt failed), in ID order
     * after the given audit, optionally only in some volumes.
     *
     * @param list<int>|null $volumeIds
     * @return list<array{id: int, assetId: int, siteId: int}>
     */
    public function needingSuggestions(int $afterId, int $limit, ?array $volumeIds = null, ?User $user = null): array
    {
        $rows = $this->needingSuggestionsQuery($volumeIds, $user)
            ->select(['audits.id', 'audits.assetId', 'audits.siteId'])
            ->andWhere(['>', 'audits.id', $afterId])
            ->orderBy(['audits.id' => SORT_ASC])
            ->limit($limit)
            ->all();

        return array_map(static fn(array $row): array => ['id' => (int)$row['id'], 'assetId' => (int)$row['assetId'], 'siteId' => (int)$row['siteId']], $rows);
    }

    /**
     * How many audits {@see needingSuggestions()} walks through, so describing them all can say
     * how far along it is.
     *
     * @param list<int>|null $volumeIds
     */
    public function countNeedingSuggestions(?array $volumeIds = null, ?User $user = null): int
    {
        return (int)$this->needingSuggestionsQuery($volumeIds, $user)->count();
    }

    /**
     * @param list<int>|null $volumeIds
     */
    private function needingSuggestionsQuery(?array $volumeIds, ?User $user = null): Query
    {
        $query = (new Query())
            ->from(['audits' => AuditRecord::TABLE])
            ->leftJoin(['suggestions' => SuggestionRecord::TABLE], '[[suggestions.assetId]] = [[audits.assetId]] AND [[suggestions.siteId]] = [[audits.siteId]]')
            ->where(['audits.altStatus' => [AltStatus::MISSING->value, AltStatus::WEAK->value]])
            ->andWhere(['or',
                ['suggestions.id' => null],
                ['suggestions.status' => SuggestionStatus::FAILED->value],
                // A reservation whose job died without generating anything.
                ['and', ['suggestions.status' => SuggestionStatus::QUEUED->value], ['<', 'suggestions.dateUpdated', Db::prepareDateForDb(new DateTime('@' . (time() - Suggestions::RESERVATION_TIMEOUT)))]],
            ]);

        // An empty list means no volume at all, not every volume.
        if ($volumeIds !== null) {
            $query->andWhere(['audits.volumeId' => $volumeIds]);
        }

        // For a person (describing in the browser): only images they may see.
        $visible = $this->visibleCondition($user);

        if ($visible !== []) {
            $query->innerJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[audits.assetId]]')->andWhere($visible);
        }

        return $query;
    }

    /**
     * IDs of the volumes whose images the user may see. All of them without a user (queue, console).
     *
     * @return list<int>
     */
    public function viewableVolumeIds(?User $user): array
    {
        $ids = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($user === null || $user->can("viewAssets:$volume->uid")) {
                $ids[] = (int)$volume->id;
            }
        }

        return $ids;
    }

    /**
     * The rows a user may see, in SQL, so lists count and page only those: Craft's own rules for
     * other people's uploads (`viewPeerAssets`) and for sites (`editSite`, except in volumes whose
     * alt text is shared, which may be edited from any site). Expects `audits` and `assets`
     * aliases. Rows are still checked one by one with Craft afterwards.
     *
     * @return array<int|string, mixed>
     */
    public function visibleCondition(?User $user): array
    {
        if ($user === null || $user->admin) {
            return [];
        }

        $condition = ['and'];
        $ownOnly = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if (!$user->can("viewPeerAssets:$volume->uid")) {
                $ownOnly[] = (int)$volume->id;
            }
        }

        if ($ownOnly !== []) {
            $condition[] = ['or', ['not', ['audits.volumeId' => $ownOnly]], ['assets.uploaderId' => $user->id]];
        }

        if (Craft::$app->getIsMultiSite()) {
            $siteIds = [];

            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                if ($user->can("editSite:$site->uid")) {
                    $siteIds[] = (int)$site->id;
                }
            }

            // Shared alt text may be edited from any site, but only by someone who may edit one.
            $condition[] = $siteIds === []
                ? ['audits.siteId' => []]
                : ['or', ['audits.siteId' => $siteIds], ['audits.volumeId' => AltMonitor::getInstance()->getAssetScanner()->sharedAltVolumeIds()]];
        }

        return count($condition) > 1 ? $condition : [];
    }

    /**
     * @param list<int> $volumeIds
     */
    public function stats(array $volumeIds, ?User $user = null): DashboardStats
    {
        $counts = static fn(string $column): array => array_map('intval', (new Query())
            ->select(['COUNT(*)'])
            ->from(AuditRecord::TABLE)
            ->where(['volumeId' => $volumeIds])
            ->groupBy([$column])
            ->indexBy($column)
            ->column());
        $status = $counts('altStatus');
        $usage = $counts('usageStatus');
        $base = (new Query())->from(AuditRecord::TABLE)->where(['volumeId' => $volumeIds]);

        return new DashboardStats(
            totalImages: $volumeIds === [] ? 0 : (int)Asset::find()->kind(Asset::KIND_IMAGE)->volumeId($volumeIds)->status(null)->site('*')->unique()->count(),
            audited: array_sum($status),
            missing: $status[AltStatus::MISSING->value] ?? 0,
            weak: $status[AltStatus::WEAK->value] ?? 0,
            good: $status[AltStatus::GOOD->value] ?? 0,
            review: $status[AltStatus::REVIEW->value] ?? 0,
            decorative: (int)(clone $base)->andWhere(['decorative' => true])->count(),
            pendingSuggestions: AltMonitor::getInstance()->getSuggestions()->count(SuggestionStatus::PENDING, $volumeIds, $user),
            updated: (int)(clone $base)->andWhere(['not', ['dateAltSaved' => null]])->count(),
            used: $usage[UsageStatus::USED->value] ?? 0,
            unused: $usage[UsageStatus::UNUSED->value] ?? 0,
            usageUnknown: $usage[UsageStatus::UNKNOWN->value] ?? 0,
        );
    }

    /**
     * One page of the image list.
     *
     * @param list<int> $volumeIds Volumes the user may view.
     * @return AuditPage<AuditItem>
     */
    public function page(ListCriteria $criteria, array $volumeIds, ?User $user): AuditPage
    {
        $query = (new Query())
            ->from(['audits' => AuditRecord::TABLE])
            ->innerJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[audits.assetId]]')
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[audits.assetId]] AND [[elements_sites.siteId]] = [[audits.siteId]]')
            ->leftJoin(['suggestions' => SuggestionRecord::TABLE], '[[suggestions.assetId]] = [[audits.assetId]] AND [[suggestions.siteId]] = [[audits.siteId]]')
            ->where(['audits.volumeId' => $criteria->volumeId !== null ? array_intersect($volumeIds, [$criteria->volumeId]) : $volumeIds]);

        $query->andFilterWhere([
            'audits.altStatus' => $criteria->status?->value,
            'audits.usageStatus' => $criteria->usage?->value,
            'audits.siteId' => $criteria->siteId,
        ]);

        if ($criteria->suggestion === ListCriteria::SUGGESTION_NONE) {
            $query->andWhere(['suggestions.id' => null]);
        } elseif ($criteria->suggestion !== null) {
            $query->andWhere(['suggestions.status' => $criteria->suggestion]);
        }

        if ($criteria->search !== '') {
            $query->andWhere(['or', ['like', 'assets.filename', $criteria->search], ['like', 'elements_sites.title', $criteria->search]]);
        }

        $query->andWhere($this->visibleCondition($user));

        $total = (int)$query->count('[[audits.id]]');
        $rows = $query
            ->select(['audits.*'])
            ->orderBy(['assets.filename' => SORT_ASC, 'audits.id' => SORT_ASC])
            ->offset($criteria->offset())
            ->limit(ListCriteria::PER_PAGE)
            ->all();

        return new AuditPage($this->items($rows, $user), $total, $criteria->page, ListCriteria::PER_PAGE);
    }

    /**
     * The audit status of several assets as each is seen in its own site (an element index's
     * page of them), read in one query. Assets with no audit (not images, or not scanned yet) are
     * left out.
     *
     * @param Asset[] $assets
     * @return array<string, array{status: AltStatus, issues: list<\Tahadudhiya\AltMonitor\enums\AltIssue>, decorative: bool}>
     *         By `assetId-siteId`, the site being the asset's own.
     */
    public function statusesFor(array $assets): array
    {
        $scanner = AltMonitor::getInstance()->getAssetScanner();
        $lookups = [];

        foreach ($assets as $asset) {
            if ($asset->id && $asset->kind === Asset::KIND_IMAGE) {
                $auditSiteId = $scanner->auditSiteId((int)$asset->volumeId, (int)$asset->siteId);
                $lookups[$auditSiteId . '-' . $asset->id][] = $asset->id . '-' . $asset->siteId;
            }
        }

        if ($lookups === []) {
            return [];
        }

        $rows = (new Query())
            ->select(['assetId', 'siteId', 'altStatus', 'issues', 'decorative'])
            ->from(AuditRecord::TABLE)
            ->where(['or', ...array_map(static function(string $key): array {
                [$siteId, $assetId] = explode('-', $key);

                return ['assetId' => (int)$assetId, 'siteId' => (int)$siteId];
            }, array_keys($lookups))])
            ->all();
        $statuses = [];

        foreach ($rows as $row) {
            $status = [
                'status' => AltStatus::from($row['altStatus']),
                'issues' => AltAnalysis::issuesFromString($row['issues']),
                'decorative' => (bool)$row['decorative'],
            ];

            foreach ($lookups[$row['siteId'] . '-' . $row['assetId']] ?? [] as $key) {
                $statuses[$key] = $status;
            }
        }

        return $statuses;
    }

    /**
     * The audit of one image in one site, as the image list shows it.
     */
    public function item(int $assetId, int $siteId, ?User $user): ?AuditItem
    {
        return $this->itemsFor([[$assetId, $siteId]], $user)[0] ?? null;
    }

    /**
     * The audits of several images, in the order given, loaded together.
     *
     * @param list<array{0: int, 1: int}> $pairs Asset and site IDs.
     * @return list<AuditItem>
     */
    public function itemsFor(array $pairs, ?User $user): array
    {
        if ($pairs === []) {
            return [];
        }

        $rows = (new Query())
            ->from(AuditRecord::TABLE)
            ->where(['or', ...array_map(static fn(array $pair): array => ['assetId' => $pair[0], 'siteId' => $pair[1]], $pairs)])
            ->all();
        $order = array_flip(array_map(static fn(array $pair): string => $pair[0] . '-' . $pair[1], $pairs));
        usort($rows, static fn(array $a, array $b): int => $order[$a['assetId'] . '-' . $a['siteId']] <=> $order[$b['assetId'] . '-' . $b['siteId']]);

        return $this->items($rows, $user);
    }

    /**
     * Audit rows as list items, with their assets, suggestions and usage counts loaded once for
     * the whole page. Rows whose asset can no longer be loaded are left out.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<AuditItem>
     */
    private function items(array $rows, ?User $user): array
    {
        $plugin = AltMonitor::getInstance();
        $assets = [];

        foreach (array_unique(array_column($rows, 'siteId')) as $siteId) {
            $ids = array_column(array_filter($rows, static fn(array $row): bool => $row['siteId'] == $siteId), 'assetId');
            $assets[(int)$siteId] = Asset::find()->id($ids)->siteId((int)$siteId)->status(null)->indexBy('id')->all();
        }

        $pairs = array_map(static fn(array $row): array => [(int)$row['assetId'], (int)$row['siteId']], $rows);
        $suggestions = $plugin->getSuggestions()->forPairs($pairs);
        $usageCounts = $plugin->getContentUsage()->counts(array_map('intval', array_column($rows, 'assetId')));
        $scanner = $plugin->getAssetScanner();
        $items = [];

        foreach ($rows as $row) {
            $asset = $assets[(int)$row['siteId']][(int)$row['assetId']] ?? null;

            // Craft's own rule, which also covers other people's uploads (viewPeerAssets).
            if (!$asset instanceof Asset || ($user !== null && !$scanner->userMay($asset, $user))) {
                continue;
            }

            $items[] = new AuditItem(
                asset: $asset,
                siteId: (int)$row['siteId'],
                status: AltStatus::from($row['altStatus']),
                issues: AltAnalysis::issuesFromString($row['issues']),
                decorative: (bool)$row['decorative'],
                usage: UsageStatus::from($row['usageStatus']),
                usageCount: $usageCounts[(int)$row['assetId']] ?? 0,
                suggestion: $suggestions[$row['assetId'] . '-' . $row['siteId']] ?? null,
                dateScanned: DateTimeHelper::toDateTime($row['dateScanned']) ?: new DateTime(),
                dateAltSaved: $row['dateAltSaved'] ? (DateTimeHelper::toDateTime($row['dateAltSaved']) ?: null) : null,
                canEdit: $user !== null && $scanner->userMay($asset, $user, true),
            );
        }

        return $items;
    }
}
