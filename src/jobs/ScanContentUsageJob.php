<?php

namespace Tahadudhiya\AltMonitor\jobs;

use craft\helpers\Db;
use craft\i18n\Translation;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\models\Scan;
use yii\queue\Queue;

/**
 * Finds where content references images: first through Craft's relations, then through
 * reference tags in field content, one batch per job. When both are walked, references no
 * longer found are forgotten and every audit's usage status is set.
 */
class ScanContentUsageJob extends BaseScanJob
{
    public const PHASE_RELATIONS = 'relations';
    public const PHASE_REFERENCE_TAGS = 'referenceTags';

    public string $phase = self::PHASE_RELATIONS;

    /** The last relation or content row ID an earlier batch read. */
    public int $afterId = 0;

    protected function run(Queue $queue, Scan $scan): ?BaseScanJob
    {
        $plugin = AltMonitor::getInstance();
        $usage = $plugin->getContentUsage();

        if ($this->total === null) {
            $this->total = $usage->workCount();
            $plugin->getScans()->markRunning($scan->id, $this->total);
        }

        [$last, $count] = $this->phase === self::PHASE_RELATIONS
            // Relation rows are small, so more are read at once.
            ? $usage->scanRelations($this->afterId, $this->batchSize() * 5)
            : $usage->scanReferenceTags($this->afterId, $this->batchSize());
        $done = $this->done + $count;
        $this->report($queue, $done);

        if ($last !== null || $this->phase === self::PHASE_RELATIONS) {
            return new self([
                'scanId' => $scan->id,
                'phase' => $last !== null ? $this->phase : self::PHASE_REFERENCE_TAGS,
                'afterId' => $last ?? 0,
                'done' => $done,
                'total' => $this->total,
            ]);
        }

        $startedAt = $plugin->getScans()->get($scan->id)?->startedAt;

        if ($startedAt !== null) {
            $usage->pruneNotFoundSince(Db::prepareDateForDb($startedAt));
        }

        $plugin->getAudits()->applyUsageStatuses();
        $plugin->getScans()->complete($scan->id);

        return null;
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('alt-monitor', 'Finding where images are used');
    }
}
