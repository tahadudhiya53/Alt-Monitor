<?php

namespace Tahadudhiya\AltMonitor\Tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Db;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\console\controllers\ScanController as ConsoleScanController;
use Tahadudhiya\AltMonitor\controllers\DashboardController;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\ScanStatus;
use Tahadudhiya\AltMonitor\enums\ScanType;
use Tahadudhiya\AltMonitor\enums\UsageKind;
use Tahadudhiya\AltMonitor\enums\UsageStatus;
use Tahadudhiya\AltMonitor\errors\ScanInProgressException;
use Tahadudhiya\AltMonitor\jobs\ScanAssetsJob;
use Tahadudhiya\AltMonitor\models\ListCriteria;
use Tahadudhiya\AltMonitor\records\AuditRecord;
use Tahadudhiya\AltMonitor\records\ScanRecord;
use Tahadudhiya\AltMonitor\records\UsageRecord;
use Tahadudhiya\AltMonitor\services\AssetScanner;
use Tahadudhiya\AltMonitor\Tests\_support\AltMonitorFixture;
use Tahadudhiya\AltMonitor\widgets\CoverageWidget;

/**
 * Scanning real assets and content through the queue: discovery, classification, sites and
 * volumes, incremental scans, deletion, content usage, failures and concurrency.
 */
class ScanTest extends TestCase
{
    use AltMonitorFixture;

    private static function plugin(): AltMonitor
    {
        return AltMonitor::getInstance();
    }

    /**
     * @return array<int, array<string, mixed>> Audit rows of an asset, by site ID.
     */
    private static function audits(Asset $asset): array
    {
        return (new Query())->from(AuditRecord::TABLE)->where(['assetId' => $asset->id])->indexBy('siteId')->all();
    }

    private static function altStatusOf(Asset $asset): ?string
    {
        return self::audits($asset)[self::$primarySiteId]['altStatus'] ?? null;
    }

    /** The asset as stored, as code editing it would load it. */
    private static function fresh(Asset $asset, ?int $siteId = null): Asset
    {
        $fresh = Asset::find()->id($asset->id)->siteId($siteId ?? self::$primarySiteId)->status(null)->one();
        self::assertInstanceOf(Asset::class, $fresh);

        return $fresh;
    }

    private function scan(ScanType $type): int
    {
        $id = self::plugin()->getScans()->start($type)->id;
        $this->runJobs();

        return $id;
    }

    public function testAFullScanAuditsEveryImageThroughTheQueueAndExcludesOtherFiles(): void
    {
        // Batches of 10, so the scan takes several jobs.
        $missing = [];

        for ($i = 0; $i < 12; $i++) {
            $missing[] = self::savedImage("IMG_10$i.jpg");
        }

        $weak = self::savedImage('sunset-beach.jpg', 'sunset-beach.jpg');
        $generic = self::savedImage('hero.png', 'Image');
        $good = self::savedImage('team.png', 'Five people around a meeting table');
        $short = self::savedImage('logo-acme.png', 'Acme');
        $divider = self::savedImage('section-divider.png');
        $pdf = self::savedFile('price-list.txt');
        $before = clone $weak;

        $id = $this->scan(ScanType::FULL);
        $scan = self::plugin()->getScans()->get($id);

        self::assertSame(ScanStatus::COMPLETED, $scan?->status);
        self::assertGreaterThanOrEqual(17, $scan->scannedAssets);
        self::assertSame($scan->totalAssets, $scan->scannedAssets);
        self::assertSame('missing', self::altStatusOf($missing[0]));
        self::assertSame('missing', self::altStatusOf($missing[11]));
        self::assertSame('weak', self::altStatusOf($weak));
        self::assertSame('weak', self::altStatusOf($generic));
        self::assertSame('good', self::altStatusOf($good));
        // Short is not the same as poor.
        self::assertSame('good', self::altStatusOf($short));
        // Empty alt text on what may be decoration is left for a person to decide.
        self::assertSame('review', self::altStatusOf($divider));
        self::assertSame([], self::audits($pdf));

        // Scanning never touches the assets themselves.
        $after = Asset::find()->id($weak->id)->siteId(self::$primarySiteId)->one();
        self::assertSame($before->alt, $after?->alt);
        self::assertEquals($before->dateUpdated, $after?->dateUpdated);

        // A full scan goes on to scan usage.
        self::assertSame(ScanStatus::COMPLETED, self::plugin()->getScans()->latestCompleted([ScanType::USAGE])?->status);
    }

    public function testAddingAnExtraGenericWordReChecksImagesThatDidNotChange(): void
    {
        $image = self::savedImage('IMG_2010.jpg', 'Slide');
        $plugin = self::plugin();

        $this->scan(ScanType::FULL);
        self::assertSame('good', self::altStatusOf($image));

        // The team says “slide” describes nothing. The image itself didn't change, but the next
        // scan checks it again with the new word.
        $plugin->getSettings()->additionalGenericTerms = ['slide'];
        $plugin->set('analyzer', AltMonitor::config()['components']['analyzer']);
        $this->scan(ScanType::FULL);
        self::assertSame('weak', self::altStatusOf($image));
        self::assertStringContainsString('generic', (string)(self::audits($image)[self::$primarySiteId]['issues'] ?? ''));

        $plugin->getSettings()->additionalGenericTerms = [];
    }

    public function testRescanningIsIdempotentAndKeepsOneAuditPerImageAndSite(): void
    {
        $image = self::savedImage('IMG_2000.jpg');

        $this->scan(ScanType::FULL);
        $first = self::audits($image);
        $this->scan(ScanType::FULL);
        $second = self::audits($image);

        self::assertCount(1, $second);
        self::assertSame(array_column($first, 'id'), array_column($second, 'id'));
        self::assertSame(array_column($first, 'altHash'), array_column($second, 'altHash'));
    }

    public function testAltTextSharedByEverySiteIsAuditedOnceAndTranslatableAltTextPerSite(): void
    {
        $shared = self::savedImage('shared.png', 'A red bicycle against a wall');
        $translated = self::savedImage('translated.png', 'A red bicycle against a wall', self::$translated);

        $this->scan(ScanType::FULL);

        self::assertSame([self::$primarySiteId], array_keys(self::audits($shared)));
        self::assertEqualsCanonicalizing(Craft::$app->getSites()->getAllSiteIds(), array_keys(self::audits($translated)));
    }

    public function testAnIncrementalScanReadsOnlyImagesChangedSinceTheLastScan(): void
    {
        $settings = self::plugin()->getSettings();
        $settings->auditOnSave = false;
        $unchanged = self::savedImage('unchanged.png');
        $changed = self::savedImage('changed.png');
        $this->scan(ScanType::FULL);
        // The previous scan started just now, after the unchanged image was last saved. (A short
        // window, so images people upload to the host site meanwhile aren't counted.)
        Db::update(ScanRecord::TABLE, ['startedAt' => Db::prepareDateForDb(new \DateTime('-10 seconds'))], []);
        Db::update(\craft\db\Table::ELEMENTS, ['dateUpdated' => Db::prepareDateForDb(new \DateTime('-1 hour'))], ['id' => $unchanged->id], updateTimestamp: false);

        $changed = self::fresh($changed);
        $changed->alt = 'A paper boat on a puddle';
        self::assertTrue(Craft::$app->getElements()->saveElement($changed));
        $new = self::savedImage('new.png');
        self::assertSame('missing', self::altStatusOf($changed), 'Audit on save is off, so only a scan updates it.');

        $id = $this->scan(ScanType::INCREMENTAL);
        $scan = self::plugin()->getScans()->get($id);

        self::assertSame(ScanType::INCREMENTAL, $scan?->type);
        self::assertSame(2, $scan->scannedAssets);
        self::assertSame('good', self::altStatusOf($changed));
        self::assertSame('missing', self::altStatusOf($new));
        self::assertSame('missing', self::altStatusOf($unchanged));
    }

    public function testAnIncrementalScanWithNothingToBuildOnRunsAsAFullScan(): void
    {
        Db::delete(ScanRecord::TABLE);

        self::assertSame(ScanType::FULL, self::plugin()->getScans()->start(ScanType::INCREMENTAL)->type);
    }

    public function testSavingAnImageKeepsItsAuditCurrentWithoutAScan(): void
    {
        $image = self::savedImage('IMG_3000.png');
        self::assertSame('missing', self::altStatusOf($image));

        $image = self::fresh($image);
        $image->alt = 'A lighthouse at dusk';
        self::assertTrue(Craft::$app->getElements()->saveElement($image));

        self::assertSame('good', self::altStatusOf($image));
    }

    public function testTrashedAndDeletedImagesAreForgotten(): void
    {
        $trashed = self::savedImage('trashed.png');
        $deleted = self::savedImage('deleted.png');
        $this->scan(ScanType::FULL);
        self::plugin()->getSuggestions()->generate((int)$trashed->id, self::$primarySiteId);

        $trashed = self::fresh($trashed);
        // As the control panel deletes assets, so they can be restored.
        $trashed->keepFileOnDelete = true;
        self::assertTrue(Craft::$app->getElements()->deleteElement($trashed));
        self::assertTrue(Craft::$app->getElements()->deleteElement(self::fresh($deleted), true));

        self::assertSame([], self::audits($trashed));
        self::assertSame([], self::audits($deleted));
        self::assertNull(self::plugin()->getSuggestions()->get((int)$trashed->id, self::$primarySiteId));

        // Restoring it audits it again.
        $inTrash = Asset::find()->id($trashed->id)->siteId(self::$primarySiteId)->trashed()->one();
        self::assertInstanceOf(Asset::class, $inTrash);
        self::assertTrue(Craft::$app->getElements()->restoreElement($inTrash), \craft\helpers\Json::encode($inTrash->getErrors()));
        self::assertSame('missing', self::altStatusOf($trashed));
    }

    public function testAFullScanForgetsAuditsInSitesWhereAnImageIsNoLongerAudited(): void
    {
        $image = self::savedImage('per-site.png', 'A kite over a field', self::$translated);
        $this->scan(ScanType::FULL);
        self::assertCount(count(Craft::$app->getSites()->getAllSiteIds()), self::audits($image));

        // The volume's alt text stops being translatable, so it is audited in the primary site only.
        $volumes = Craft::$app->getVolumes();
        $volume = $volumes->getVolumeById((int)self::$translated->id);
        self::assertNotNull($volume);
        $volume->altTranslationMethod = \craft\base\Field::TRANSLATION_METHOD_NONE;
        self::assertTrue($volumes->saveVolume($volume));
        // As if the earlier scan had been a while ago.
        Db::update(AuditRecord::TABLE, ['dateScanned' => '2000-01-01 00:00:00'], ['assetId' => $image->id]);

        try {
            $this->scan(ScanType::FULL);
            self::assertSame([self::$primarySiteId], array_keys(self::audits($image)));
        } finally {
            $volume->altTranslationMethod = \craft\base\Field::TRANSLATION_METHOD_SITE;
            $volumes->saveVolume($volume);
        }
    }

    public function testContentUsageFindsRelationsNestedContentGlobalsAndEmbeddedImagesButNotDrafts(): void
    {
        $related = self::savedImage('related.png');
        $nested = self::savedImage('nested.png');
        $global = self::savedImage('global.png');
        $embedded = self::savedImage('embedded.png');
        $draftOnly = self::savedImage('draft-only.png');
        $unused = self::savedImage('unused.png');

        $page = self::savedEntry('Our new office', [self::IMAGES => [$related->id], self::BODY => "Intro <img src=\"{asset:{$embedded->id}:url||https://example.test/x.png}\">"]);
        self::savedBlock($page, [(int)$nested->id]);
        $globals = Craft::$app->getGlobals()->getSetByHandle('altMonitorGlobals');
        self::assertNotNull($globals);
        $globals->setFieldValue(self::IMAGES, [$global->id]);
        self::assertTrue(Craft::$app->getElements()->saveElement($globals));
        $draft = Craft::$app->getDrafts()->createDraft($page, 1);
        $draft->setFieldValue(self::IMAGES, [$draftOnly->id]);
        self::assertTrue(Craft::$app->getElements()->saveElement($draft));

        $this->scan(ScanType::FULL);

        $usage = self::plugin()->getContentUsage();
        $byKind = static fn(Asset $asset): array => array_map(static fn($reference): array => [$reference->kind, $reference->label, $reference->nestedIn !== null], $usage->references((int)$asset->id));

        self::assertSame([[UsageKind::RELATION, 'Our new office', false]], $byKind($related));
        self::assertSame([[UsageKind::RELATION, 'Our new office', true]], $byKind($nested));
        self::assertSame([[UsageKind::RELATION, 'AltMonitor globals', false]], $byKind($global));
        self::assertSame([[UsageKind::REFERENCE_TAG, 'Our new office', false]], $byKind($embedded));
        self::assertSame([], $byKind($draftOnly));

        $usageOf = static fn(Asset $asset): ?string => self::audits($asset)[self::$primarySiteId]['usageStatus'] ?? null;
        self::assertSame(UsageStatus::USED->value, $usageOf($related));
        self::assertSame(UsageStatus::USED->value, $usageOf($nested));
        self::assertSame(UsageStatus::USED->value, $usageOf($embedded));
        self::assertSame(UsageStatus::UNUSED->value, $usageOf($draftOnly));
        self::assertSame(UsageStatus::UNUSED->value, $usageOf($unused));
        self::assertSame(['Our new office'], $usage->titles((int)$related->id));

        // A reference removed from content is forgotten by the next usage scan.
        $page = \craft\elements\Entry::find()->id($page->id)->siteId(self::$primarySiteId)->one();
        self::assertNotNull($page);
        $page->setFieldValue(self::IMAGES, []);
        self::assertTrue(Craft::$app->getElements()->saveElement($page));
        Db::update(\Tahadudhiya\AltMonitor\records\UsageRecord::TABLE, ['dateUpdated' => '2000-01-01 00:00:00'], [], updateTimestamp: false);
        $this->scan(ScanType::USAGE);

        self::assertSame([], $byKind($related));
        self::assertSame(UsageStatus::UNUSED->value, $usageOf($related));
        self::assertCount(1, $byKind($nested));
    }

    /**
     * @return array<int, bool|null> Whether the content gives each image alt text, by asset ID, as stored for the primary site
     *         (or all sites, for an Assets field that isn't translated).
     */
    private static function altInContent(Asset ...$assets): array
    {
        $rows = (new Query())
            ->select(['assetId', 'altInContent'])
            ->from(UsageRecord::TABLE)
            ->where(['assetId' => array_map(static fn(Asset $asset): int => (int)$asset->id, $assets), 'sourceSiteId' => [self::$primarySiteId, null]])
            ->all();

        return array_map(static fn(?string $value): ?bool => $value === null ? null : (bool)$value, array_column($rows, 'altInContent', 'assetId'));
    }

    public function testTheUsageScanRecordsWhetherTextGivesEmbeddedImagesAltText(): void
    {
        $noAlt = self::savedImage('embedded-no-alt.png', 'A harbour at dawn');
        $withAlt = self::savedImage('embedded-alt.png', 'A red bicycle');
        $twice = self::savedImage('embedded-twice.png');
        $linked = self::savedImage('linked.png');
        $related = self::savedImage('related-only.png');
        $body = <<<HTML
            <p><img src="{asset:{$noAlt->id}:url||https://example.test/a.png}"></p>
            <figure><img alt="A bike leaning on a wall" src="{asset:{$withAlt->id}:transform:large||https://example.test/b.png}"></figure>
            <img src="{asset:{$twice->id}:url}" alt="Shown once with text"><img src="{asset:{$twice->id}:url}" alt=" ">
            <a href="{asset:{$linked->id}:url}">Download the photo</a>
            HTML;
        $page = self::savedEntry('Embedded images', [self::IMAGES => [$related->id], self::BODY => $body]);

        $this->scan(ScanType::FULL);

        // Stored as yes or no for <img> tags (any tag without text counts), nothing for links and fields.
        self::assertSame([(int)$noAlt->id => false, (int)$withAlt->id => true, (int)$twice->id => false, (int)$linked->id => null, (int)$related->id => null], self::altInContent($noAlt, $withAlt, $twice, $linked, $related) + [(int)$related->id => 'not recorded']);

        // The image page reads what the text says now, not what the scan stored.
        $references = static fn(Asset $asset): array => self::plugin()->getContentUsage()->references((int)$asset->id);
        self::assertSame([''], $references($noAlt)[0]->contentAlts);
        self::assertTrue($references($noAlt)[0]->hasNoAltInContent());
        self::assertSame(['A bike leaning on a wall'], $references($withAlt)[0]->otherAltsInContent('A red bicycle'));
        self::assertSame([], $references($withAlt)[0]->otherAltsInContent('a bike leaning on a wall.'));
        self::assertSame(['Shown once with text', ''], $references($twice)[0]->contentAlts);
        self::assertNull($references($linked)[0]->contentAlts);
        self::assertNull($references($related)[0]->contentAlts);

        // Fixed in the content: the image page shows it at once, the stored answer at the next usage scan.
        $page = \craft\elements\Entry::find()->id($page->id)->siteId(self::$primarySiteId)->one();
        self::assertNotNull($page);
        $page->setFieldValue(self::BODY, str_replace('<p><img src', '<p><img alt="Boats in a harbour" src', $body));
        self::assertTrue(Craft::$app->getElements()->saveElement($page));
        self::assertSame(['Boats in a harbour'], $references($noAlt)[0]->contentAlts);
        self::assertFalse(self::altInContent($noAlt)[(int)$noAlt->id]);

        $this->scan(ScanType::USAGE);
        self::assertTrue(self::altInContent($noAlt)[(int)$noAlt->id]);
    }

    public function testPagesRankContentByTheImagesThatNeedWorkCountingNestedContent(): void
    {
        $missingA = self::savedImage('page-missing-a.png');
        $missingB = self::savedImage('page-missing-b.png');
        $weak = self::savedImage('page-weak.png', 'IMG_2231');
        $good = self::savedImage('page-good.png', 'A lighthouse on a cliff');
        $embedded = self::savedImage('page-embedded.png', 'A dog on a beach');
        $decorative = self::savedImage('page-divider.png');
        self::plugin()->getAudits()->setDecorative((int)$decorative->id, self::$primarySiteId, true);

        $home = self::savedEntry('Home', [
            self::IMAGES => [$missingA->id, $missingB->id, $good->id],
            self::BODY => "<img src=\"{asset:{$embedded->id}:url}\"> <img src=\"{asset:{$decorative->id}:url}\" alt=\"\">",
        ]);
        $block = self::savedBlock($home, [(int)$weak->id]);
        $about = self::savedEntry('About', [self::IMAGES => [$missingA->id]]);
        $fine = self::savedEntry('All fine', [self::IMAGES => [$good->id]]);

        $this->scan(ScanType::FULL);
        $pages = self::plugin()->getPages();
        $ours = array_flip([(int)$home->id, (int)$about->id, (int)$fine->id, (int)$block->id]);
        // The host's own content may be listed too: only this test's content is compared.
        $ranked = array_intersect_key($pages->ranked(self::$primarySiteId, null), $ours);

        // Home: two missing, the nested block's weak one, and one embedded without alt text (the
        // decorative one is fine without). The block itself is never a row. “All fine” isn't listed.
        self::assertSame([(int)$home->id, (int)$about->id], array_keys($ranked));
        self::assertSame(['problems' => 4, 'missing' => 2, 'weak' => 1, 'review' => 0, 'noAltInText' => 1], $ranked[(int)$home->id]);
        self::assertSame(['problems' => 1, 'missing' => 1, 'weak' => 0, 'review' => 0, 'noAltInText' => 0], $ranked[(int)$about->id]);

        // The list's rows carry the content itself.
        $items = [];
        for ($number = 1; $number <= 20 && count($items) < 2; $number++) {
            $page = $pages->page(self::$primarySiteId, $number, null);
            foreach ($page->items as $item) {
                if (isset($ours[$item->elementId])) {
                    $items[$item->elementId] = $item;
                }
            }
            if ($number >= $page->pageCount()) {
                break;
            }
        }
        self::assertSame('Home', $items[(int)$home->id]->element?->title);
        self::assertSame(1, $items[(int)$home->id]->noAltInText);

        // “Fix images” narrows the Images list to every image Home uses, nested ones included.
        self::assertContains((int)$block->id, $pages->contentIds((int)$home->id));
        $audits = self::plugin()->getAudits();
        $listed = static fn(array $params): array => array_map(static fn($item): int => (int)$item->asset->id, $audits->page(ListCriteria::fromParams($params), $audits->viewableVolumeIds(null), null)->items);
        $expected = array_map('intval', [$missingA->id, $missingB->id, $weak->id, $good->id, $embedded->id, $decorative->id]);
        self::assertEqualsCanonicalizing($expected, $listed(['usedIn' => (string)$home->id]));
        self::assertEqualsCanonicalizing([(int)$missingA->id, (int)$missingB->id], $listed(['usedIn' => (string)$home->id, 'status' => 'missing']));
        self::assertSame([(int)$missingA->id], $listed(['usedIn' => (string)$about->id]));

        // Trashed since the scan: left out of the ranking and the totals at once.
        self::assertTrue(Craft::$app->getElements()->deleteElement($about));
        self::assertArrayNotHasKey((int)$about->id, $pages->ranked(self::$primarySiteId, null));
    }

    public function testPagesCountImagesAsEachSiteShowsThem(): void
    {
        $otherSiteId = (int)current(array_diff(Craft::$app->getSites()->getAllSiteIds(), [self::$primarySiteId]));

        if ($otherSiteId === 0) {
            self::markTestSkipped('Needs a second site.');
        }

        // Alt text per site: missing in the primary site, given in the other one.
        $perSite = self::savedImage('per-site.png', null, self::$translated);
        $inOther = Asset::find()->id($perSite->id)->siteId($otherSiteId)->status(null)->one();
        self::assertNotNull($inOther);
        $inOther->alt = 'Ein Leuchtturm';
        self::assertTrue(Craft::$app->getElements()->saveElement($inOther));
        // Alt text all sites share, audited in the primary site: missing everywhere.
        $shared = self::savedImage('shared-missing.png');
        $entry = self::savedEntry('Site page', [self::IMAGES => [$perSite->id, $shared->id]]);

        $this->scan(ScanType::FULL);
        $pages = self::plugin()->getPages();

        self::assertSame(2, $pages->ranked(self::$primarySiteId, null)[(int)$entry->id]['missing'] ?? null);
        self::assertSame(1, $pages->ranked($otherSiteId, null)[(int)$entry->id]['missing'] ?? null);

        // The Images list for that page in the other site shows both images as that site does.
        $audits = self::plugin()->getAudits();
        $site = Craft::$app->getSites()->getSiteById($otherSiteId);
        self::assertNotNull($site);
        $items = $audits->page(ListCriteria::fromParams(['usedIn' => (string)$entry->id, 'inSite' => $site->handle]), $audits->viewableVolumeIds(null), null)->items;
        $statuses = [];
        foreach ($items as $item) {
            $statuses[(int)$item->asset->id] = $item->status->value;
        }
        self::assertSame([(int)$perSite->id => 'good', (int)$shared->id => 'missing'], $statuses + [(int)$perSite->id => 'absent', (int)$shared->id => 'absent']);

        // With alt text per site, the image page shows the content of its own site only, so what
        // the text says there is compared with that site's alt text.
        $body = self::savedEntry('Per-site text', [self::BODY => "<img src=\"{asset:{$perSite->id}:url}\">"]);
        $this->scan(ScanType::USAGE);
        Db::update(UsageRecord::TABLE, ['sourceSiteId' => self::$primarySiteId], ['assetId' => $perSite->id, 'sourceElementId' => $body->id]);
        $usage = self::plugin()->getContentUsage();
        $inText = static fn(?int $siteId): int => count(array_filter($usage->references((int)$perSite->id, siteId: $siteId), static fn($reference): bool => $reference->contentAlts !== null));
        self::assertSame(1, $inText(self::$primarySiteId));
        self::assertSame(0, $inText($otherSiteId));
        // Relations that apply in every site are listed for every site.
        self::assertCount(1, array_filter($usage->references((int)$perSite->id, siteId: $otherSiteId), static fn($reference): bool => $reference->label === 'Site page'));
    }

    public function testEachAuditKeepsAKeyOfItsAltTextForFindingImagesThatShareIt(): void
    {
        $first = self::savedImage('key-a.png', ' Team  Photo ');
        $second = self::savedImage('key-b.png', 'team photo');
        $none = self::savedImage('key-c.png');
        $key = static fn(Asset $asset): ?string => self::audits($asset)[self::$primarySiteId]['altKey'] ?? null;

        self::assertNotNull($key($first));
        self::assertSame($key($first), $key($second));
        self::assertNull($key($none));
        // Only a hash: the alt text itself is never stored.
        self::assertSame(40, strlen((string)$key($first)));

        // Changed alt text changes the key when the image is saved.
        $fresh = self::fresh($second);
        $fresh->alt = 'A different photo';
        self::assertTrue(Craft::$app->getElements()->saveElement($fresh));
        self::assertNotSame($key($first), $key($second));
    }

    public function testImagesScannedSinceTheLastUsageScanHaveUnknownUsage(): void
    {
        $this->scan(ScanType::FULL);
        $image = self::savedImage('later.png');

        self::assertSame(UsageStatus::UNKNOWN->value, self::audits($image)[self::$primarySiteId]['usageStatus']);
    }

    public function testOnlyOneScanRunsAtATimeAndAnAbandonedScanStopsBlocking(): void
    {
        $scans = self::plugin()->getScans();
        $first = $scans->start(ScanType::FULL);

        try {
            $scans->start(ScanType::USAGE);
            self::fail('A second scan started while one was queued.');
        } catch (ScanInProgressException) {
        }

        Db::update(ScanRecord::TABLE, ['dateUpdated' => '2000-01-01 00:00:00'], ['id' => $first->id], updateTimestamp: false);

        $second = $scans->start(ScanType::USAGE);
        self::assertSame(ScanStatus::FAILED, $scans->get($first->id)?->status);
        self::assertSame(ScanStatus::QUEUED, $second->status);

        // The abandoned scan's jobs, if they ever run, do nothing.
        $this->pushed = array_values(array_filter($this->pushed, static fn($job): bool => $job instanceof ScanAssetsJob));
        $this->runJobs();
        self::assertSame(ScanStatus::FAILED, $scans->get($first->id)->status);
    }

    public function testAFailedBatchFailsTheScanAndARetryResumesIt(): void
    {
        $image = self::savedImage('IMG_4000.png');
        $plugin = self::plugin();
        $scan = $plugin->getScans()->start(ScanType::FULL);
        $job = array_shift($this->pushed);
        self::assertInstanceOf(ScanAssetsJob::class, $job);

        $plugin->set('assetScanner', new class() extends AssetScanner {
            public function scanBatch(int $siteId, int $afterId, int $limit, ?string $changedSince, ?int $scanId): array
            {
                throw new \RuntimeException('Storage is unreachable at /secret/path');
            }
        });

        try {
            $job->execute(Craft::$app->getQueue());
            self::fail('The failure was swallowed.');
        } catch (\RuntimeException) {
        } finally {
            $plugin->set('assetScanner', ['class' => AssetScanner::class]);
        }

        $failed = $plugin->getScans()->get($scan->id);
        self::assertSame(ScanStatus::FAILED, $failed?->status);
        self::assertStringNotContainsString('/secret/path', (string)$failed->error);
        self::assertTrue($job->canRetry(1, new \RuntimeException()));
        self::assertFalse($job->canRetry(ScanAssetsJob::ATTEMPTS, new \RuntimeException()));

        // Craft's queue runs the same job again.
        $job->execute(Craft::$app->getQueue());
        $this->runJobs();

        self::assertSame(ScanStatus::COMPLETED, $plugin->getScans()->get($scan->id)?->status);
        self::assertSame('missing', self::altStatusOf($image));
    }

    public function testTheDashboardShowsCountsFromTheAudit(): void
    {
        self::savedImage('IMG_5000.png');
        self::savedImage('IMG_5001.png');
        self::savedImage('dash-good.png', 'A cat asleep on a windowsill');
        $this->scan(ScanType::FULL);

        $stats = self::plugin()->getAudits()->stats(self::plugin()->getAudits()->viewableVolumeIds(null));
        $counted = (int)(new Query())->from(AuditRecord::TABLE)->where(['altStatus' => AltStatus::MISSING->value])->count();
        self::assertSame($counted, $stats->missing);
        self::assertGreaterThanOrEqual(3, $stats->totalImages);

        $this->signIn(false, ['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW, AltMonitor::PERMISSION_RUN_SCANS, ...self::assetPermissions()]);
        $response = $this->runAction(DashboardController::class, 'dashboard', 'index', [], method: 'GET', json: false);
        $html = self::html($response);
        $html .= self::html($response, 'actionButton');

        self::assertStringContainsString('id="altmonitor-stats"', $html);
        self::assertStringContainsString('Missing alt text', $html);
        self::assertStringContainsString('Full scan', $html);

        $progress = $this->runAction(DashboardController::class, 'dashboard', 'progress', [], method: 'GET');
        self::assertSame('completed', $progress?->data['scan']['status'] ?? null);
    }

    public function testImagesCheckedOnSaveAloneDontCountAsAScan(): void
    {
        // As on a fresh install: no scan yet, but saving an image checks it.
        \craft\helpers\Db::delete(\Tahadudhiya\AltMonitor\records\ScanRecord::TABLE);
        self::savedImage('IMG_5100.png');
        $this->signIn(false, ['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW, AltMonitor::PERMISSION_RUN_SCANS, ...self::assetPermissions()]);
        $dashboard = fn(): string => self::html($this->runAction(DashboardController::class, 'dashboard', 'index', [], method: 'GET', json: false));

        $html = $dashboard();
        self::assertStringContainsString('Scan all images', $html);
        self::assertStringNotContainsString('images checked.', $html);

        $this->scan(ScanType::FULL);
        $html = $dashboard();
        self::assertStringNotContainsString('Scan all images', $html);
        self::assertStringContainsString('images checked.', $html);
    }

    public function testEachBatchIsQueuedOnlyOnceItsLockIsReleased(): void
    {
        self::savedImage('IMG_6000.png');
        $scan = self::plugin()->getScans()->start(ScanType::FULL);
        $lockFreeAtPush = [];
        $listener = static function(\yii\queue\PushEvent $event) use ($scan, &$lockFreeAtPush): void {
            // The scan's own batches; the usage scan a full scan starts has a lock of its own.
            if (!$event->job instanceof \Tahadudhiya\AltMonitor\jobs\BaseScanJob || $event->job->scanId !== $scan->id) {
                return;
            }

            $mutex = Craft::$app->getMutex();
            $free = $mutex->acquire("altmonitor:scan:$scan->id");
            $lockFreeAtPush[] = $free;

            if ($free) {
                $mutex->release("altmonitor:scan:$scan->id");
            }
        };
        \yii\base\Event::on(\craft\queue\Queue::class, \craft\queue\Queue::EVENT_AFTER_PUSH, $listener);

        try {
            $this->runJobs();
        } finally {
            \yii\base\Event::off(\craft\queue\Queue::class, \craft\queue\Queue::EVENT_AFTER_PUSH, $listener);
        }

        self::assertNotEmpty($lockFreeAtPush);
        self::assertNotContains(false, $lockFreeAtPush, 'A batch was queued while its lock was held, so another worker would skip it.');
        self::assertSame(ScanStatus::COMPLETED, self::plugin()->getScans()->get($scan->id)?->status);
    }

    public function testCraftsDashboardWidgetShowsCoverageOnlyToThoseWhoMayViewAltMonitor(): void
    {
        self::savedImage('IMG_6200.png');
        self::plugin()->getScans()->start(ScanType::FULL);
        $this->runJobs();

        $this->signIn(true);
        self::assertTrue(CoverageWidget::isSelectable());
        $html = (string)(new CoverageWidget())->getBodyHtml();
        $stats = self::plugin()->getAudits()->stats(self::plugin()->getAudits()->viewableVolumeIds(null));
        self::assertStringContainsString($stats->coverage() . '%', $html);
        self::assertStringContainsString('role="progressbar"', $html);
        self::assertStringContainsString('alt-monitor/images?status=missing', $html);
        self::assertStringContainsString('Last scan:', $html);
        // Never a claim of compliance: only “no known problem”.
        self::assertStringNotContainsStringIgnoringCase('wcag', $html);

        $this->signIn(false, ['accessCp']);
        self::assertFalse(CoverageWidget::isSelectable());
        self::assertNull((new CoverageWidget())->getBodyHtml());
    }

    // The console command

    public function testTheConsoleCommandQueuesAScanAndRefusesASecondOne(): void
    {
        self::savedImage('IMG_6100.png');

        [$code, $out] = $this->command(['full' => true, 'usage' => true]);
        self::assertSame(64, $code);
        self::assertStringContainsString('not both', $out);
        self::assertNull(self::plugin()->getScans()->active());

        [$code, $out] = $this->command(['full' => true]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Full scan queued.', $out);
        self::assertStringContainsString('--wait', $out);
        self::assertSame(ScanType::FULL, self::plugin()->getScans()->active()?->type);

        // One scan at a time: a cron job finding one running says so with a “try again” code.
        [$code, $out] = $this->command([]);
        self::assertSame(75, $code);
        self::assertStringContainsString('already running or waiting to start', $out);
    }

    public function testWithWaitTheConsoleCommandRunsTheScanAndReportsTheResult(): void
    {
        self::savedImage('IMG_6101.png');
        self::savedImage('harbour.png', 'A harbour at night');

        [$code, $out] = $this->command(['full' => true, 'wait' => true]);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Full scan queued.', $out);
        self::assertStringContainsString('Scan completed.', $out);
        self::assertMatchesRegularExpression('/Missing: [1-9]/', $out);
        self::assertSame(ScanStatus::COMPLETED, self::plugin()->getScans()->latest()?->status);

        // Later ones only read what changed.
        [$code, $out] = $this->command(['wait' => true]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Changes-only scan queued.', $out);
    }

    /**
     * Runs `alt-monitor/scan` with the given options. Its queue runs the jobs this test caught
     * (never the host's real queue), and its output is captured.
     *
     * @param array<string, bool> $options
     * @return array{int, string} Exit code and output.
     */
    private function command(array $options): array
    {
        $controller = new class('scan', self::plugin()) extends ConsoleScanController {
            public string $output = '';
            public ?\Closure $runner = null;

            /** @return int */
            public function stdout($string)
            {
                $this->output .= $string;

                return strlen($string);
            }

            /** @return int */
            public function stderr($string)
            {
                $this->output .= $string;

                return strlen($string);
            }

            protected function runQueue(): void
            {
                ($this->runner)();
            }
        };
        $controller->runner = fn() => $this->runJobs();
        $controller->interactive = false;

        foreach ($options as $name => $value) {
            $controller->$name = $value;
        }

        return [$controller->actionIndex(), $controller->output];
    }
}
