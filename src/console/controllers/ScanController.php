<?php

namespace Tahadudhiya\AltMonitor\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\queue\QueueInterface;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\ScanStatus;
use Tahadudhiya\AltMonitor\enums\ScanType;
use Tahadudhiya\AltMonitor\errors\ScanInProgressException;
use yii\base\Event;
use yii\console\ExitCode;
use yii\queue\ExecEvent;
use yii\queue\Queue;

/**
 * Starts AltMonitor scans from the command line, for cron jobs and deployment scripts.
 *
 * The scan is queued exactly as the dashboard's buttons queue it (one scan at a time). With
 * --wait the command also runs Craft's queue until the scan is done and reports the result.
 */
class ScanController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var bool Scan every image asset instead of only those changed since the last scan.
     */
    public bool $full = false;

    /**
     * @var bool Scan only where images are used in content.
     */
    public bool $usage = false;

    /**
     * @var bool Run Craft's queue now, until the scan is done, and report the result.
     */
    public bool $wait = false;

    /** Seconds between checks while the queue is busy elsewhere (another worker has the job). */
    private const POLL_SECONDS = 2;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'full', 'usage', 'wait'];
    }

    /**
     * Queues a scan: changed images by default (all of them on the first scan), every image with
     * --full, or content usage only with --usage.
     *
     * Exit codes: 0 queued (or, with --wait, completed), 1 the scan failed, 64 conflicting
     * options, 75 another scan is already queued or running (try again later).
     */
    public function actionIndex(): int
    {
        if ($this->full && $this->usage) {
            $this->stderr("Choose --full or --usage, not both.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $type = match (true) {
            $this->full => ScanType::FULL,
            $this->usage => ScanType::USAGE,
            default => ScanType::INCREMENTAL,
        };

        try {
            $scan = AltMonitor::getInstance()->getScans()->start($type);
        } catch (ScanInProgressException $exception) {
            $this->stderr($exception->getMessage() . "\n", Console::FG_YELLOW);

            return ExitCode::TEMPFAIL;
        }

        // An incremental scan with nothing to compare against becomes a full one.
        $this->stdout("{$scan->type->label()} queued.\n", Console::FG_GREEN);

        if (!$this->wait) {
            $this->stdout("It runs with Craft's queue. Add --wait to run it now and see the result.\n");

            return ExitCode::OK;
        }

        return $this->waitFor($scan->id);
    }

    private function waitFor(int $scanId): int
    {
        $scans = AltMonitor::getInstance()->getScans();
        $reported = null;
        $report = function() use ($scans, $scanId, &$reported): void {
            $scan = $scans->get($scanId);

            if ($scan !== null && $scan->status === ScanStatus::RUNNING && $scan->totalAssets > 0 && $scan->scannedAssets !== $reported) {
                $reported = $scan->scannedAssets;
                $this->stdout(sprintf("  %s of %s images (%d%%)\n", number_format($scan->scannedAssets), number_format($scan->totalAssets), round($scan->progress() * 100)));
            }
        };

        $afterJob = static function(ExecEvent $event) use ($report): void {
            $report();
        };
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, $afterJob);

        try {
            while (true) {
                $this->runQueue();

                // active() also gives up on a scan that stopped reporting progress.
                $active = $scans->active();
                $scan = $scans->get($scanId);

                if ($scan === null) {
                    $this->stderr("The scan could not be found.\n", Console::FG_RED);

                    return ExitCode::UNSPECIFIED_ERROR;
                }

                if ($scan->status === ScanStatus::COMPLETED) {
                    $this->summary();

                    return ExitCode::OK;
                }

                if ($scan->status === ScanStatus::FAILED || $active?->id !== $scanId) {
                    $this->stderr('The scan failed' . ($scan->error ? ": $scan->error" : '.') . "\n", Console::FG_RED);

                    return ExitCode::UNSPECIFIED_ERROR;
                }

                $report();
                sleep(self::POLL_SECONDS);
            }
        } finally {
            Event::off(Queue::class, Queue::EVENT_AFTER_EXEC, $afterJob);
        }
    }

    /**
     * Every job waiting runs, as with `craft queue/run`, so the scan's jobs (each queues the next
     * batch) run here unless another worker already has them.
     */
    protected function runQueue(): void
    {
        $queue = Craft::$app->getQueue();

        if ($queue instanceof QueueInterface) {
            $queue->run();
        }
    }

    /** Where things stand afterwards, over every volume. */
    private function summary(): void
    {
        $audits = AltMonitor::getInstance()->getAudits();
        $stats = $audits->stats($audits->viewableVolumeIds(null));
        $coverage = $stats->coverage();

        $this->stdout("Scan completed.\n", Console::FG_GREEN);
        $this->stdout(sprintf(
            "Images checked: %s. No known problem: %s. Missing: %s. Weak: %s. Needs review: %s. Suggestions to review: %s.\n",
            number_format($stats->audited),
            $coverage === null ? '-' : $coverage . '%',
            number_format($stats->missing),
            number_format($stats->weak),
            number_format($stats->review),
            number_format($stats->pendingSuggestions),
        ));
    }
}
