<?php

namespace Tahadudhiya\AltMonitor\Tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Db;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\controllers\DashboardController;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\ScanStatus;
use Tahadudhiya\AltMonitor\enums\ScanType;
use Tahadudhiya\AltMonitor\enums\UsageKind;
use Tahadudhiya\AltMonitor\enums\UsageStatus;
use Tahadudhiya\AltMonitor\errors\ScanInProgressException;
use Tahadudhiya\AltMonitor\jobs\ScanAssetsJob;
use Tahadudhiya\AltMonitor\records\AuditRecord;
use Tahadudhiya\AltMonitor\records\ScanRecord;
use Tahadudhiya\AltMonitor\services\AssetScanner;
use Tahadudhiya\AltMonitor\Tests\_support\AltMonitorFixture;

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
}
