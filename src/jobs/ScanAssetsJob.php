<?php

namespace Tahadudhiya\AltMonitor\jobs;

use Craft;
use craft\helpers\Db;
use craft\i18n\Translation;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\ScanType;
use Tahadudhiya\AltMonitor\errors\ScanInProgressException;
use Tahadudhiya\AltMonitor\models\Scan;
use yii\queue\Queue;

/**
 * Audits image assets, one site and one batch of asset IDs per job, walking sites in turn.
 *
 * A full scan reads every image and then forgets audits it did not reach; an incremental scan
 * reads only images changed since the last asset scan began, and forgets audits of assets that
 * were trashed or are no longer images.
 */
class ScanAssetsJob extends BaseScanJob
{
    /** Which of the scanned sites this job reads. */
    public int $siteIndex = 0;

    /** The last asset ID an earlier batch read in this site. */
    public int $afterId = 0;

    /** Where an incremental scan starts reading from, fixed by its first job. */
    public ?string $changedSince = null;

    protected function run(Queue $queue, Scan $scan): ?BaseScanJob
    {
        $plugin = AltMonitor::getInstance();
        $scanner = $plugin->getAssetScanner();

        if ($this->total === null) {
            $this->changedSince = $scan->type === ScanType::INCREMENTAL ? $plugin->getScans()->changedSince() : null;
            $this->total = $scanner->countImages($this->changedSince);
            $plugin->getScans()->markRunning($scan->id, $this->total);
        }

        $siteId = $scanner->siteIds()[$this->siteIndex] ?? null;

        if ($siteId !== null) {
            [$last, $count] = $scanner->scanBatch($siteId, $this->afterId, $this->batchSize(), $this->changedSince, $scan->id);
            $done = $this->done + $count;
            $this->report($queue, $done);

            return new self([
                'scanId' => $scan->id,
                'siteIndex' => $last !== null ? $this->siteIndex : $this->siteIndex + 1,
                'afterId' => $last ?? 0,
                'done' => $done,
                'total' => $this->total,
                'changedSince' => $this->changedSince,
            ]);
        }

        $this->finish($scan);

        return null;
    }

    private function finish(Scan $scan): void
    {
        $plugin = AltMonitor::getInstance();
        $settings = $plugin->getSettings();
        $startedAt = $plugin->getScans()->get($scan->id)?->startedAt;

        if ($scan->type === ScanType::FULL && $startedAt !== null) {
            $plugin->getAudits()->pruneNotScannedSince(Db::prepareDateForDb($startedAt));
        } else {
            $plugin->getAudits()->pruneGone();
        }

        $plugin->getSuggestions()->pruneUnaudited();
        $plugin->getScans()->complete($scan->id);

        if ($scan->type === ScanType::FULL && $settings->scanUsageAfterFullScan) {
            try {
                $plugin->getScans()->start(ScanType::USAGE);
            } catch (ScanInProgressException $exception) {
                Craft::info('The content usage scan after a full scan was not started: ' . $exception->getMessage(), __METHOD__);
            }
        }

        // Only the server can generate unattended; in the browser, someone has to be there.
        if ($settings->suggestAfterScan && $plugin->getSuggestions()->generatesOnServer()) {
            $plugin->getSuggestions()->queueForAllWanting();
        }
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('alt-monitor', 'Scanning images for alt text');
    }
}
