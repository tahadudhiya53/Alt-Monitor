<?php

namespace Tahadudhiya\AltMonitor\jobs;

use Craft;
use craft\helpers\Queue as QueueHelper;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use yii\queue\RetryableJobInterface;

/**
 * Generates suggestions for the images it was given. A walk finds every image with missing or
 * weak alt text that has none yet, a few per step, and queues a job for each few.
 *
 * Images are reserved (their suggestion set to “generating”) before a job is queued for them, so
 * the same image is never queued twice, and each is generated under its own lock.
 */
class GenerateAltTextJob extends BaseJob implements RetryableJobInterface
{
    /** Images per job while walking the audit; a local model can take a minute for each. */
    public const WALK_BATCH = 5;

    /** @var list<array{0: int, 1: int, 2?: string}> Asset and site IDs, and the status of the suggestion each had. */
    public array $targets = [];

    /** Whether the job walks the audit for images wanting a suggestion. */
    public bool $walk = false;

    /** The last audit ID an earlier job of a walk read. */
    public int $afterAuditId = 0;

    /** @var list<int>|null The volumes a walk covers: those of the user who started it. All when null. */
    public ?array $volumeIds = null;

    /** The user who started a walk: only images they may see. Everyone's when null (after a scan). */
    public ?int $userId = null;

    public function execute($queue): void
    {
        if ($this->walk) {
            $this->walkStep();

            return;
        }

        $suggestions = AltMonitor::getInstance()->getSuggestions();
        $targets = $this->targets;

        foreach ($targets as $i => $target) {
            [$assetId, $siteId] = $target;
            $previous = isset($target[2]) ? SuggestionStatus::tryFrom($target[2]) : null;
            $this->setProgress($queue, $i / max(1, count($targets)));

            // One image failing never leaves the others, or itself, marked as generating.
            try {
                $suggestions->generate((int)$assetId, (int)$siteId, $previous);
            } catch (\Throwable $exception) {
                Craft::error(sprintf('AltMonitor could not generate a suggestion for asset %d: %s', $assetId, $exception->getMessage()), __METHOD__);
                $reason = Craft::t('alt-monitor', 'Something went wrong while making suggestions. See the Craft logs for details.');

                if ($previous === null || !$suggestions->keepPrevious((int)$assetId, (int)$siteId, $previous, $reason)) {
                    $suggestions->markFailed((int)$assetId, (int)$siteId, $reason);
                }
            }
        }

        $this->setProgress($queue, 1);
    }

    /**
     * Reserves the next few images and queues a job for them, then the next step. Generating
     * happens in that job, so a retried step never loses the images it reserved.
     */
    private function walkStep(): void
    {
        $plugin = AltMonitor::getInstance();
        $user = $this->userId === null ? null : Craft::$app->getUsers()->getUserById($this->userId);

        if ($this->userId !== null && $user === null) {
            return;
        }

        $rows = $plugin->getAudits()->needingSuggestions($this->afterAuditId, self::WALK_BATCH, $this->volumeIds, $user);

        if ($rows === []) {
            return;
        }

        $targets = $plugin->getSuggestions()->reserve(array_map(static fn(array $row): array => [$row['assetId'], $row['siteId']], $rows));

        if ($targets !== []) {
            QueueHelper::push(new self(['targets' => $targets]));
        }

        QueueHelper::push(new self([
            'walk' => true,
            'afterAuditId' => end($rows)['id'],
            'volumeIds' => $this->volumeIds,
            'userId' => $this->userId,
        ]));
    }

    public function getTtr(): int
    {
        if ($this->walk) {
            return (int)Craft::$app->getQueue()->ttr;
        }

        $count = max(1, count($this->targets));

        return max((int)Craft::$app->getQueue()->ttr, $count * (AltMonitor::getInstance()->getSettings()->aiTimeout + 30) + 60);
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 2;
    }

    protected function defaultDescription(): ?string
    {
        return $this->walk
            ? Translation::prep('alt-monitor', 'Making alt text suggestions')
            : Translation::prep('alt-monitor', 'Making alt text suggestions for {count, number} {count, plural, =1{image} other{images}}', ['count' => count($this->targets)]);
    }
}
