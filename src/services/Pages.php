<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\UsageStatus;
use Tahadudhiya\AltMonitor\models\AuditPage;
use Tahadudhiya\AltMonitor\models\ListCriteria;
use Tahadudhiya\AltMonitor\models\PageItem;
use Tahadudhiya\AltMonitor\records\AuditRecord;
use Tahadudhiya\AltMonitor\records\UsageRecord;
use yii\base\Component;

/**
 * Content ranked by how many of its images need work (“fix by page”), from the usage the last
 * usage scan found and the images' current checks. Images in nested content (Matrix and other
 * nested entries) count toward the content that owns it. Never writes.
 *
 * Like usage, it only knows the images Craft fields and rich text hold: an image a template adds
 * is not counted.
 */
class Pages extends Component
{
    /** How deep nested content is followed, as a guard against a cycle in Craft's owners table. */
    private const MAX_DEPTH = 10;

    private const PROBLEMS = [AltStatus::MISSING, AltStatus::WEAK, AltStatus::REVIEW];

    /** IDs per `IN (…)` list and rows per fetch, so a large site never builds one huge query or array. */
    private const CHUNK = 1000;

    /** What an image used by a piece of content fails, as bits. */
    private const FAILS = ['missing' => 1, 'weak' => 2, 'review' => 4, 'noAltInText' => 8];

    /**
     * One page of the list, in one site, for a user (who sees only images they may view, and
     * content they may not view without its title).
     *
     * @return AuditPage<PageItem>
     */
    public function page(int $siteId, int $page, ?User $user): AuditPage
    {
        $ranked = $this->ranked($siteId, $user);
        $slice = array_slice($ranked, ($page - 1) * ListCriteria::PER_PAGE, ListCriteria::PER_PAGE, true);
        $elements = $this->load(array_keys($slice), $siteId);
        $items = [];

        foreach ($slice as $elementId => $counts) {
            $element = $elements[$elementId] ?? null;

            // Gone since the usage scan (or not in this site any more): nothing to fix there.
            if ($element === null) {
                continue;
            }

            $mayView = $user === null || Craft::$app->getElements()->canView($element, $user);
            $items[] = new PageItem($elementId, $mayView ? $element : null, ...$counts);
        }

        return new AuditPage($items, count($ranked), $page, ListCriteria::PER_PAGE);
    }

    /**
     * Every piece of content using an image that needs work, most problems first (then most
     * missing, then weak), with its counts.
     *
     * @return array<int, array{problems: int, missing: int, weak: int, review: int, noAltInText: int}> By element ID.
     */
    public function ranked(int $siteId, ?User $user): array
    {
        $plugin = AltMonitor::getInstance();
        $audits = $plugin->getAudits();
        $volumeIds = $audits->viewableVolumeIds($user);

        if ($volumeIds === []) {
            return [];
        }

        $query = (new Query())
            ->select(['usages.sourceElementId', 'audits.assetId', 'audits.altStatus', 'audits.decorative', 'usages.altInContent'])
            ->from(['usages' => UsageRecord::TABLE])
            ->innerJoin(['audits' => AuditRecord::TABLE], '[[audits.assetId]] = [[usages.assetId]]')
            ->innerJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[audits.assetId]]')
            // The content is in this site (a relation without a site applies in all of its sites).
            ->innerJoin(['sources' => Table::ELEMENTS_SITES], '[[sources.elementId]] = [[usages.sourceElementId]] AND [[sources.siteId]] = :siteId', [':siteId' => $siteId])
            ->where(['audits.volumeId' => $volumeIds])
            ->andWhere(['or', ['usages.sourceSiteId' => null], ['usages.sourceSiteId' => $siteId]])
            ->andWhere($this->auditInSite($siteId))
            ->andWhere(['or',
                ['audits.altStatus' => array_map(static fn(AltStatus $status): string => $status->value, self::PROBLEMS)],
                ['and', ['usages.altInContent' => false], ['audits.decorative' => false]],
            ])
            ->andWhere($audits->visibleCondition($user))
            ->orderBy(['usages.id' => SORT_ASC]);

        // What each image fails, by source element and image, as bits: compact, and read a
        // batch of rows at a time instead of the whole site at once.
        $bySource = [];

        foreach ($query->batch(self::CHUNK) as $rows) {
            foreach ($rows as $row) {
                $fails = self::FAILS[$row['altStatus']] ?? 0;

                if ($row['altInContent'] !== null && !$row['altInContent'] && !$row['decorative']) {
                    $fails |= self::FAILS['noAltInText'];
                }

                $bySource[(int)$row['sourceElementId']][(int)$row['assetId']] = ($bySource[(int)$row['sourceElementId']][(int)$row['assetId']] ?? 0) | $fails;
            }
        }

        $roots = $this->roots(array_keys($bySource));
        $live = $this->liveInSite(array_values(array_unique($roots)), $siteId);
        $byRoot = [];

        foreach ($bySource as $sourceId => $images) {
            $root = $roots[$sourceId] ?? null;

            // Content trashed since the usage scan, or not in this site, has nothing to fix here.
            if ($root === null || !isset($live[$root])) {
                continue;
            }

            foreach ($images as $assetId => $fails) {
                $byRoot[$root][$assetId] = ($byRoot[$root][$assetId] ?? 0) | $fails;
            }
        }

        $ranked = [];

        foreach ($byRoot as $root => $images) {
            $counts = ['problems' => count($images)];

            foreach (self::FAILS as $name => $bit) {
                $counts[$name] = count(array_filter($images, static fn(int $fails): bool => ($fails & $bit) !== 0));
            }

            $ranked[$root] = $counts;
        }

        uksort($ranked, static fn(int $a, int $b): int => [$ranked[$b]['problems'], $ranked[$b]['missing'], $ranked[$b]['weak'], $a] <=> [$ranked[$a]['problems'], $ranked[$a]['missing'], $ranked[$a]['weak'], $b]);

        return $ranked;
    }

    /** Whether a usage scan has looked for where images are used: without one, nothing is known. */
    public function usageChecked(): bool
    {
        return (new Query())->from(AuditRecord::TABLE)->where(['not', ['usageStatus' => UsageStatus::UNKNOWN->value]])->exists();
    }

    /**
     * A piece of content and every live element nested in it, at any depth: where its images
     * can be referenced from.
     *
     * @return list<int>
     */
    public function contentIds(int $elementId): array
    {
        $ids = [$elementId => true];
        $level = [$elementId];

        for ($depth = 0; $level !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $level = array_values(array_diff(array_map('intval', (new Query())
                ->select(['owners.elementId'])
                ->distinct()
                ->from(['owners' => Table::ELEMENTS_OWNERS])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[owners.elementId]]')
                ->where(['owners.ownerId' => $level, 'elements.draftId' => null, 'elements.revisionId' => null, 'elements.dateDeleted' => null])
                ->column()), array_keys($ids)));

            foreach ($level as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The audit an image has as seen in a site: that site's, or the primary site's for a volume
     * whose alt text all sites share. Expects the `audits` alias.
     *
     * @return array<int|string, mixed>
     */
    public function auditInSite(int $siteId): array
    {
        $primarySiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        return $siteId === $primarySiteId
            ? ['audits.siteId' => $siteId]
            : ['or', ['audits.siteId' => $siteId], ['and', ['audits.siteId' => $primarySiteId], ['audits.volumeId' => AltMonitor::getInstance()->getAssetScanner()->sharedAltVolumeIds()]]];
    }

    /**
     * The content each element belongs to: itself, or for nested content its top-level owner,
     * following live owners only. Elements whose owners are all drafts, revisions or trashed are
     * left out.
     *
     * @param list<int> $ids
     * @return array<int, int>
     */
    private function roots(array $ids): array
    {
        $roots = [];
        // Original ID => the element reached so far.
        $current = array_combine($ids, $ids);

        for ($depth = 0; $current !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $owners = [];

            foreach (array_chunk(array_values(array_unique($current)), self::CHUNK) as $chunk) {
                array_push($owners, ...(new Query())
                    ->select(['owners.elementId', 'owners.ownerId', 'ownerElements.draftId', 'ownerElements.revisionId', 'ownerElements.dateDeleted'])
                    ->from(['owners' => Table::ELEMENTS_OWNERS])
                    ->innerJoin(['ownerElements' => Table::ELEMENTS], '[[ownerElements.id]] = [[owners.ownerId]]')
                    ->where(['owners.elementId' => $chunk])
                    ->orderBy(['owners.ownerId' => SORT_ASC])
                    ->all());
            }

            $live = [];
            $owned = [];

            foreach ($owners as $row) {
                $owned[(int)$row['elementId']] = true;

                if ($row['draftId'] === null && $row['revisionId'] === null && $row['dateDeleted'] === null) {
                    $live[(int)$row['elementId']] ??= (int)$row['ownerId'];
                }
            }

            foreach ($current as $original => $reached) {
                if (!isset($owned[$reached])) {
                    $roots[$original] = $reached;
                    unset($current[$original]);
                } elseif (isset($live[$reached])) {
                    $current[$original] = $live[$reached];
                } else {
                    unset($current[$original]);
                }
            }
        }

        return $roots;
    }

    /**
     * Which of these elements are live content in a site: not a draft, revision or trashed, and
     * present in that site.
     *
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function liveInSite(array $ids, int $siteId): array
    {
        $live = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $found = (new Query())
                ->select(['elements.id'])
                ->from(['elements' => Table::ELEMENTS])
                ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elements.id]] AND [[elements_sites.siteId]] = :siteId', [':siteId' => $siteId])
                ->where(['elements.id' => $chunk, 'elements.draftId' => null, 'elements.revisionId' => null, 'elements.dateDeleted' => null])
                ->column();

            foreach ($found as $id) {
                $live[(int)$id] = true;
            }
        }

        return $live;
    }

    /**
     * Elements by ID, in one site, loaded a type at a time.
     *
     * @param list<int> $ids
     * @return array<int, ElementInterface>
     */
    private function load(array $ids, int $siteId): array
    {
        if ($ids === []) {
            return [];
        }

        $types = (new Query())->select(['type', 'id'])->from(Table::ELEMENTS)->where(['id' => $ids])->all();
        $byType = [];

        foreach ($types as $row) {
            $byType[(string)$row['type']][] = (int)$row['id'];
        }

        $elements = [];

        foreach ($byType as $type => $typeIds) {
            if (!class_exists($type) || !is_subclass_of($type, ElementInterface::class)) {
                continue;
            }

            /** @var class-string<ElementInterface> $type */
            $query = $type::find()->id($typeIds)->siteId($siteId)->status(null);

            foreach ($query->all() as $element) {
                $elements[(int)$element->id] = $element;
            }
        }

        return $elements;
    }
}
