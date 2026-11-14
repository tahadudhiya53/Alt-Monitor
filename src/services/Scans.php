<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Queue;
use craft\queue\QueueInterface;
use DateTime;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\ScanStatus;
use Tahadudhiya\AltMonitor\enums\ScanType;
use Tahadudhiya\AltMonitor\errors\ScanInProgressException;
use Tahadudhiya\AltMonitor\jobs\ScanAssetsJob;
use Tahadudhiya\AltMonitor\jobs\ScanContentUsageJob;
use Tahadudhiya\AltMonitor\models\Scan;
use Tahadudhiya\AltMonitor\records\ScanRecord;
use yii\base\Component;

/**
 * Starts scans, tracks their progress and finishes them: the only writer of `altmonitor_scans`.
 *
 * One scan runs at a time. A scan whose jobs stopped reporting progress (a worker that died
 * mid-batch) is treated as failed once {@see STALE_AFTER} has passed, so it never blocks the
 * next one for good.
 */
class Scans extends Component
{
    /** Seconds without progress after which an active scan is considered abandoned. */
    public const STALE_AFTER = 1800;

    /** Seconds without progress after which a scan is abandoned even while the queue is busy. */
    public const ABANDONED_AFTER = 21600;

    private const MUTEX = 'altmonitor:scans';

    /**
     * Queues a scan.
     *
     * An incremental scan with no earlier asset scan to build on runs as a full one.
     *
     * @throws ScanInProgressException when another scan is queued or running.
     */
    public function start(ScanType $type): Scan
    {
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire(self::MUTEX, 5)) {
            throw new ScanInProgressException(Craft::t('alt-monitor', 'Another scan is starting. Try again in a moment.'));
        }

        try {
            if ($this->active() !== null) {
                throw new ScanInProgressException(Craft::t('alt-monitor', 'A scan is already running or waiting to start.'));
            }

            if ($type === ScanType::INCREMENTAL && $this->changedSince() === null) {
                $type = ScanType::FULL;
            }

            $record = new ScanRecord();
            $record->type = $type->value;
            $record->status = ScanStatus::QUEUED->value;
            $record->save(false);
        } finally {
            $mutex->release(self::MUTEX);
        }

        $id = (int)$record->id;
        Queue::push($type === ScanType::USAGE ? new ScanContentUsageJob(['scanId' => $id]) : new ScanAssetsJob(['scanId' => $id]));

        return $this->get($id) ?? throw new \LogicException('A scan just saved could not be read.');
    }

    public function get(int $id): ?Scan
    {
        $row = (new Query())->from(ScanRecord::TABLE)->where(['id' => $id])->one();

        return $row ? self::model($row) : null;
    }

    /** The most recent scan of any kind. */
    public function latest(): ?Scan
    {
        $row = (new Query())->from(ScanRecord::TABLE)->orderBy(['id' => SORT_DESC])->one();

        return $row ? self::model($row) : null;
    }

    /**
     * The most recent completed scan of the given kinds.
     *
     * @param list<ScanType> $types
     */
    public function latestCompleted(array $types): ?Scan
    {
        $row = (new Query())
            ->from(ScanRecord::TABLE)
            ->where(['status' => ScanStatus::COMPLETED->value, 'type' => array_map(static fn(ScanType $type): string => $type->value, $types)])
            ->orderBy(['completedAt' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        return $row ? self::model($row) : null;
    }

    /**
     * The scan that is queued or running, if any. One without progress for longer than
     * {@see STALE_AFTER} while the queue has nothing left (or {@see ABANDONED_AFTER} in any
     * case) is marked failed instead.
     */
    public function active(): ?Scan
    {
        $row = (new Query())
            ->from(ScanRecord::TABLE)
            ->where(['status' => [ScanStatus::QUEUED->value, ScanStatus::RUNNING->value]])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        if (!$row) {
            return null;
        }

        $scan = self::model($row);

        $idle = time() - $scan->dateUpdated->getTimestamp();

        if ($idle > self::ABANDONED_AFTER || ($idle > self::STALE_AFTER && !self::queueIsBusy())) {
            $this->fail($scan->id, Craft::t('alt-monitor', 'The scan stopped responding and was cancelled.'));

            return null;
        }

        return $scan;
    }

    /**
     * Whether Craft's queue still has jobs waiting or running. A scan's next job waits behind
     * the others (a long run of suggestions, say), so it only counts as abandoned once the queue
     * has nothing left that could be it.
     */
    private static function queueIsBusy(): bool
    {
        $queue = Craft::$app->getQueue();

        return $queue instanceof QueueInterface && ($queue->getHasWaitingJobs() || $queue->getHasReservedJobs());
    }

    /**
     * Whether a job of this scan may carry on: the scan is active, or it failed and is still
     * the latest scan, which a retried job resumes.
     */
    public function mayContinue(Scan $scan): bool
    {
        if ($scan->status->isActive()) {
            return true;
        }

        return $scan->status === ScanStatus::FAILED && $this->latest()?->id === $scan->id;
    }

    /**
     * When an incremental scan starts reading from: the start of the last completed asset scan.
     */
    public function changedSince(): ?string
    {
        $scan = $this->latestCompleted([ScanType::FULL, ScanType::INCREMENTAL]);

        // With its offset: element queries read a date without one in the app's own timezone.
        return $scan?->startedAt !== null ? DateTimeHelper::toIso8601($scan->startedAt) : null;
    }

    public function markRunning(int $id, int $total): void
    {
        Db::update(ScanRecord::TABLE, [
            'status' => ScanStatus::RUNNING->value,
            'totalAssets' => $total,
            'scannedAssets' => 0,
            'error' => null,
            'startedAt' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $id]);
    }

    /** Records progress, and resumes a scan a retried job carries on. */
    public function advance(int $id, int $scanned): void
    {
        Db::update(ScanRecord::TABLE, ['status' => ScanStatus::RUNNING->value, 'scannedAssets' => $scanned, 'error' => null], ['id' => $id]);
    }

    /** Completes a scan, keeping the audit totals it ended with. */
    public function complete(int $id): void
    {
        $stats = AltMonitor::getInstance()->getAudits()->stats(AltMonitor::getInstance()->getAudits()->viewableVolumeIds(null));

        Db::update(ScanRecord::TABLE, [
            'status' => ScanStatus::COMPLETED->value,
            'missingAlt' => $stats->missing,
            'weakAlt' => $stats->weak,
            'goodAlt' => $stats->good,
            'reviewCount' => $stats->review,
            'completedAt' => Db::prepareDateForDb(new DateTime()),
        ], ['id' => $id]);
    }

    public function fail(int $id, string $message): void
    {
        Db::update(ScanRecord::TABLE, ['status' => ScanStatus::FAILED->value, 'error' => mb_substr($message, 0, 1000)], ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function model(array $row): Scan
    {
        $date = static fn(?string $value): ?DateTime => $value ? (DateTimeHelper::toDateTime($value) ?: null) : null;

        return new Scan(
            id: (int)$row['id'],
            type: ScanType::from($row['type']),
            status: ScanStatus::from($row['status']),
            totalAssets: (int)$row['totalAssets'],
            scannedAssets: (int)$row['scannedAssets'],
            missingAlt: (int)$row['missingAlt'],
            weakAlt: (int)$row['weakAlt'],
            goodAlt: (int)$row['goodAlt'],
            reviewCount: (int)$row['reviewCount'],
            error: $row['error'],
            startedAt: $date($row['startedAt']),
            completedAt: $date($row['completedAt']),
            dateCreated: $date($row['dateCreated']) ?? new DateTime(),
            dateUpdated: $date($row['dateUpdated']) ?? new DateTime(),
        );
    }
}
