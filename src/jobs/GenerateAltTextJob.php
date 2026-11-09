<?php

namespace Tahadudhiya\AltMonitor\jobs;

use Craft;
use craft\helpers\Queue as QueueHelper;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Tahadudhiya\AltMonitor\AltMonitor;
use yii\queue\RetryableJobInterface;

/**
 * Generates suggestions: for the images it was given, or, walking the audit, for every image with
 * missing or weak alt text that has none yet, a few per job.
 *
 * Images are reserved (their suggestion set to “generating”) before a job is queued for them, so
 * the same image is never queued twice, and each is generated under its own lock.
 */
class GenerateAltTextJob extends BaseJob implements RetryableJobInterface
{
    /** Images per job while walking the audit; a local model can take a minute for each. */
    public const WALK_BATCH = 5;

    /** @var list<array{0: int, 1: int}> Asset and site IDs. */
    public array $targets = [];

    /** Whether the job walks the audit for images wanting a suggestion. */
    public bool $walk = false;

    /** The last audit ID an earlier job of a walk read. */
    public int $afterAuditId = 0;

    /** @var list<int>|null The volumes a walk covers: those of the user who started it. All when null. */
    public ?array $volumeIds = null;

    public function execute($queue): void
    {
        $plugin = AltMonitor::getInstance();
        $suggestions = $plugin->getSuggestions();
        $targets = $this->targets;
        $last = null;

        if ($this->walk) {
            $rows = $plugin->getAudits()->needingSuggestions($this->afterAuditId, self::WALK_BATCH, $this->volumeIds);
            $last = $rows === [] ? null : end($rows)['id'];
            $targets = $suggestions->reserve(array_map(static fn(array $row): array => [$row['assetId'], $row['siteId']], $rows));
        }

        foreach ($targets as $i => [$assetId, $siteId]) {
            $this->setProgress($queue, $i / max(1, count($targets)));

            // One image failing never leaves the others, or itself, marked as generating.
            try {
                $suggestions->generate((int)$assetId, (int)$siteId);
            } catch (\Throwable $exception) {
                Craft::error(sprintf('AltMonitor could not generate a suggestion for asset %d: %s', $assetId, $exception->getMessage()), __METHOD__);
                $suggestions->markFailed((int)$assetId, (int)$siteId, Craft::t('alt-monitor', 'Generating failed because of an error. The Craft logs have the details.'));
            }
        }

        $this->setProgress($queue, 1);

        if ($last !== null) {
            QueueHelper::push(new self(['walk' => true, 'afterAuditId' => $last, 'volumeIds' => $this->volumeIds]));
        }
    }

    public function getTtr(): int
    {
        $count = $this->walk ? self::WALK_BATCH : max(1, count($this->targets));

        return max((int)Craft::$app->getQueue()->ttr, $count * (AltMonitor::getInstance()->getSettings()->aiTimeout + 30) + 60);
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 2;
    }

    protected function defaultDescription(): ?string
    {
        return $this->walk
            ? Translation::prep('alt-monitor', 'Generating alt text suggestions')
            : Translation::prep('alt-monitor', 'Generating alt text suggestions for {count, number} {count, plural, =1{image} other{images}}', ['count' => count($this->targets)]);
    }
}
