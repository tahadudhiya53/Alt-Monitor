<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\base\Field;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use Tahadudhiya\AltMonitor\AltMonitor;
use yii\base\Component;

/**
 * Finds image assets through Craft's asset queries and records their audit, a batch at a time.
 *
 * Alt text belongs to an asset in a site. A volume whose alt text is not translatable has the
 * same alt text in every site, so its images are audited in the primary site only; images in
 * volumes with translatable alt text are audited in every site.
 *
 * Scanning only ever reads assets. Nothing here writes to an asset.
 */
class AssetScanner extends Component
{
    /**
     * Sites in the order a scan walks them, primary first.
     *
     * @return list<int>
     */
    public function siteIds(): array
    {
        $sites = Craft::$app->getSites();
        $primary = (int)$sites->getPrimarySite()->id;

        return array_values(array_unique([$primary, ...array_map('intval', $sites->getAllSiteIds(true))]));
    }

    /**
     * The volumes whose images are audited in a site.
     *
     * @return list<int>
     */
    public function volumeIds(int $siteId): array
    {
        $primary = $siteId === (int)Craft::$app->getSites()->getPrimarySite()->id;
        $ids = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($primary || $volume->altTranslationMethod !== Field::TRANSLATION_METHOD_NONE) {
                $ids[] = (int)$volume->id;
            }
        }

        return $ids;
    }

    /**
     * The image assets audited in a site, optionally only those changed since a time.
     */
    public function imageQuery(int $siteId, ?string $changedSince = null): AssetQuery
    {
        $query = Asset::find()
            ->kind(Asset::KIND_IMAGE)
            ->siteId($siteId)
            ->volumeId($this->volumeIds($siteId) ?: [0])
            ->status(null);

        if ($changedSince !== null) {
            $query->dateUpdated('>= ' . $changedSince);
        }

        return $query;
    }

    /** How many images a scan will read across every site, for progress. */
    public function countImages(?string $changedSince = null): int
    {
        $count = 0;

        foreach ($this->siteIds() as $siteId) {
            $count += (int)$this->imageQuery($siteId, $changedSince)->count();
        }

        return $count;
    }

    /**
     * Audits one batch of images in a site: those after the given asset ID, in ID order.
     *
     * @return array{0: int|null, 1: int} The last asset ID read, or null at the end; how many were read.
     */
    public function scanBatch(int $siteId, int $afterId, int $limit, ?string $changedSince, ?int $scanId): array
    {
        /** @var Asset[] $assets */
        $assets = $this->imageQuery($siteId, $changedSince)
            ->id("> $afterId")
            ->orderBy(['elements.id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $audits = AltMonitor::getInstance()->getAudits();
        $stored = $audits->stored($siteId, array_map(static fn(Asset $asset): int => (int)$asset->id, $assets));
        $unchanged = [];

        foreach ($assets as $asset) {
            $decorative = $stored[(int)$asset->id]['decorative'] ?? false;

            // Nothing the analysis reads has changed, so only the time it was seen is recorded.
            if (($stored[(int)$asset->id]['altHash'] ?? null) === self::hash($asset, $decorative)) {
                $unchanged[] = (int)$asset->id;
                continue;
            }

            $this->record($asset, $siteId, $decorative, $scanId);
        }

        $audits->touch($siteId, $unchanged, $scanId);

        return [$assets === [] ? null : (int)end($assets)->id, count($assets)];
    }

    /**
     * Audits one image now, in every site it is audited in: after it is saved, restored, or
     * marked decorative. Assets that are not (or no longer) images are forgotten.
     */
    public function auditAsset(Asset $asset): void
    {
        $audits = AltMonitor::getInstance()->getAudits();

        if ($asset->kind !== Asset::KIND_IMAGE || $asset->trashed) {
            $audits->forget((int)$asset->id);

            return;
        }

        foreach ($this->siteIds() as $siteId) {
            if (!in_array((int)$asset->volumeId, $this->volumeIds($siteId), true)) {
                $audits->forget((int)$asset->id, $siteId);
                continue;
            }

            $inSite = (int)$asset->siteId === $siteId ? $asset : Asset::find()->id($asset->id)->siteId($siteId)->status(null)->one();

            if ($inSite instanceof Asset) {
                $this->record($inSite, $siteId, $audits->stored($siteId, [(int)$asset->id])[(int)$asset->id]['decorative'] ?? false, null);
            }
        }
    }

    /** A hash of what an audit records, so changes to it can be told apart. */
    public static function hash(Asset $asset, bool $decorative): string
    {
        return sha1(implode("\0", [(string)$asset->alt, (string)$asset->getFilename(), (string)$asset->title, (string)$asset->volumeId, $decorative ? '1' : '0']));
    }

    private function record(Asset $asset, int $siteId, bool $decorative, ?int $scanId): void
    {
        $plugin = AltMonitor::getInstance();
        $analysis = $plugin->getAnalyzer()->analyze($asset->alt, (string)$asset->getFilename(), $decorative);

        $plugin->getAudits()->save($asset, $siteId, $analysis, self::hash($asset, $decorative), $scanId);
    }
}
