<?php

namespace Tahadudhiya\AltMonitor\jobs;

use Craft;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseJob;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\models\Scan;
use yii\queue\Queue;
use yii\queue\RetryableJobInterface;

/**
 * One batch of a scan. Each job does a bounded amount of work, then queues the next, so no job
 * outlasts the queue's time limit and nothing is held in memory between batches.
 *
 * Every write is an upsert keyed to the asset and site, so a batch run twice changes nothing,
 * and a failed job can be retried: the scan is marked failed meanwhile, and a retry resumes it
 * as long as no newer scan has started.
 */
abstract class BaseScanJob extends BaseJob implements RetryableJobInterface
{
    /** How many times a job runs at most. */
    public const ATTEMPTS = 3;

    public int $scanId = 0;

    /** Items earlier batches read, for progress. */
    public int $done = 0;

    /** Items the scan expects to read, counted once by its first job. Null in the first job. */
    public ?int $total = null;

    public function execute($queue): void
    {
        $scans = AltMonitor::getInstance()->getScans();
        $scan = $scans->get($this->scanId);

        // A job of a scan that finished, or was superseded, has nothing left to do.
        if ($scan === null || !$scans->mayContinue($scan)) {
            return;
        }

        $mutex = Craft::$app->getMutex();
        $lock = "altmonitor:scan:$this->scanId";

        if (!$mutex->acquire($lock)) {
            return;
        }

        try {
            $next = $this->run($queue, $scan);
        } catch (\Throwable $exception) {
            Craft::error(sprintf('AltMonitor scan %d failed: %s', $this->scanId, $exception->getMessage()), __METHOD__);
            $scans->fail($this->scanId, Craft::t('alt-monitor', 'The scan stopped because of an error. The Craft logs have the details.'));

            throw $exception;
        } finally {
            $mutex->release($lock);
        }

        // Queued only once the lock is released: a second queue worker picking it up at once
        // would otherwise find the lock held, skip it, and leave the scan unfinished.
        if ($next !== null) {
            QueueHelper::push($next);
        }
    }

    /**
     * Does one batch.
     *
     * @return BaseScanJob|null The job doing the next batch, or null when the scan is finished.
     */
    abstract protected function run(Queue $queue, Scan $scan): ?self;

    protected function batchSize(): int
    {
        return AltMonitor::getInstance()->getSettings()->scanBatchSize;
    }

    protected function report(Queue $queue, int $done): void
    {
        AltMonitor::getInstance()->getScans()->advance($this->scanId, $done);
        $this->setProgress($queue, $this->total ? min(1, $done / $this->total) : 1);
    }

    public function getTtr(): int
    {
        return (int)Craft::$app->getQueue()->ttr;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::ATTEMPTS;
    }
}
