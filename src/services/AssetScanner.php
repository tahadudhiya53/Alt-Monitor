<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\base\Field;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\User;
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
     * The site whose audit covers an asset's alt text as seen in a site: that site, or the primary
     * site for a volume whose alt text is the same in every site.
     */
    public function auditSiteId(int $volumeId, int $siteId): int
    {
        // The same rule as volumeIds(), for one volume, without going through all of them: this
        // runs for every asset in the Assets index and in the asset editor.
        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        return $volume !== null && $volume->altTranslationMethod !== Field::TRANSLATION_METHOD_NONE
            ? $siteId
            : (int)Craft::$app->getSites()->getPrimarySite()->id;
    }

    /**
     * Whether a user may view, or save, an image as it is audited. Craft checks the asset's own
     * site (the `editSite:` permission), but a volume whose alt text is the same in every site is
     * audited in the primary site only, and its alt text can be edited from any site, so there the
     * asset in any site the user may edit counts.
     */
    public function userMay(Asset $asset, User $user, bool $save = false): bool
    {
        $elements = Craft::$app->getElements();
        $may = static fn(Asset $element): bool => $save ? $elements->canSave($element, $user) : $elements->canView($element, $user);

        if ($may($asset)) {
            return true;
        }

        $sites = Craft::$app->getSites();

        if (!Craft::$app->getIsMultiSite() || !in_array((int)$asset->volumeId, $this->sharedAltVolumeIds(), true)) {
            return false;
        }

        foreach ($sites->getAllSites() as $site) {
            if ((int)$site->id === (int)$asset->siteId || !$user->can('editSite:' . $site->uid)) {
                continue;
            }

            $other = Asset::find()->id($asset->id)->siteId($site->id)->status(null)->one();

            if ($other instanceof Asset) {
                return $may($other);
            }
        }

        return false;
    }

    /**
     * The volumes whose alt text is the same in every site, so they are only audited in the
     * primary site.
     *
     * @return list<int>
     */
    public function sharedAltVolumeIds(): array
    {
        $ids = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($volume->altTranslationMethod === Field::TRANSLATION_METHOD_NONE) {
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

    /**
     * A hash of what an audit depends on, so a scan can skip images where nothing changed. The
     * extra generic words are part of it: changing them changes how alt text is judged, so the
     * next full scan checks every image again. (A changes-only scan only visits images updated
     * since the last scan, so it can't; saving new words says to run a full scan.)
     */
    public static function hash(Asset $asset, bool $decorative): string
    {
        $terms = implode("\n", AltMonitor::getInstance()->getSettings()->additionalGenericTerms);

        return sha1(implode("\0", [(string)$asset->alt, (string)$asset->getFilename(), (string)$asset->title, (string)$asset->volumeId, $decorative ? '1' : '0', $terms]));
    }

    private function record(Asset $asset, int $siteId, bool $decorative, ?int $scanId): void
    {
        $plugin = AltMonitor::getInstance();
        $analysis = $plugin->getAnalyzer()->analyze($asset->alt, (string)$asset->getFilename(), $decorative);

        $plugin->getAudits()->save($asset, $siteId, $analysis, self::hash($asset, $decorative), $scanId);
    }
}
