<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\elements\User;
use craft\helpers\UrlHelper;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\UsageStatus;
use Tahadudhiya\AltMonitor\models\AuditItem;
use Tahadudhiya\AltMonitor\models\ListCriteria;
use yii\base\Component;

/**
 * The Images list as a CSV file, for content teams who work outside the control panel: every
 * image the list shows with its filters (all pages), what is wrong with its alt text, the
 * suggestion waiting, where to see it, and an empty “New alt text” column to fill in.
 *
 * Only reads. Rows are exactly those the user may see in the list.
 */
class ImageExport extends Component
{
    /** Spreadsheet apps run a cell starting with one of these as a formula. */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Writes the CSV to an open stream.
     *
     * @param resource $handle
     * @return int How many images were written.
     */
    public function write(ListCriteria $criteria, User $user, $handle): int
    {
        $audits = AltMonitor::getInstance()->getAudits();
        $count = 0;

        // So Excel reads the file as UTF-8 (accents, non-Latin file names).
        fwrite($handle, "\u{FEFF}");
        $this->row($handle, $this->headers());

        foreach ($audits->all($criteria, $audits->viewableVolumeIds($user), $user) as $item) {
            $this->row($handle, $this->values($item));
            $count++;
        }

        return $count;
    }

    /** The download's file name, e.g. `alt-text-missing-2026-11-13.csv`. */
    public function filename(ListCriteria $criteria): string
    {
        return sprintf('alt-text-%s-%s.csv', $criteria->status->value ?? 'all', date('Y-m-d'));
    }

    /**
     * A cell as text a spreadsheet shows as written, never runs: one starting like a formula
     * gets a leading apostrophe (file names, titles and alt text are typed by people).
     */
    public static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], self::FORMULA_STARTS, true) ? "'" . $value : $value;
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return [
            Craft::t('alt-monitor', 'File name'),
            Craft::t('alt-monitor', 'Title'),
            Craft::t('alt-monitor', 'Volume'),
            Craft::t('alt-monitor', 'Site'),
            Craft::t('alt-monitor', 'Status'),
            Craft::t('alt-monitor', 'Issues'),
            Craft::t('alt-monitor', 'Current alt text'),
            Craft::t('alt-monitor', 'Decorative'),
            Craft::t('alt-monitor', 'Suggested alt text'),
            Craft::t('alt-monitor', 'Content usage'),
            Craft::t('alt-monitor', 'Image URL'),
            Craft::t('alt-monitor', 'Link in AltMonitor'),
            Craft::t('alt-monitor', 'Last checked'),
            Craft::t('alt-monitor', 'New alt text'),
        ];
    }

    /**
     * @return list<string>
     */
    private function values(AuditItem $item): array
    {
        $asset = $item->asset;
        $site = Craft::$app->getSites()->getSiteById($item->siteId);

        return [
            $asset->filename,
            (string)$asset->title,
            Craft::t('site', $asset->getVolume()->name),
            $site ? Craft::t('site', $site->getName()) : '',
            $item->status->label(),
            $item->status === AltStatus::MISSING ? '' : implode('; ', array_map(static fn($issue): string => $issue->label(), $item->issues)),
            (string)$asset->alt,
            $item->decorative ? Craft::t('alt-monitor', 'Yes') : Craft::t('alt-monitor', 'No'),
            $item->hasNewSuggestion() ? (string)$item->suggestion?->suggestedAlt : '',
            // Only references Craft records can be found, so never “unused”.
            $item->usage === UsageStatus::USED && $item->usageCount > 0
                ? Craft::t('alt-monitor', 'Used in {count, number} {count, plural, =1{place} other{places}}', ['count' => $item->usageCount])
                : $item->usage->label(),
            (string)$asset->getUrl(),
            UrlHelper::cpUrl('alt-monitor/images/' . $asset->id . '/' . $item->siteId),
            $item->dateScanned->format('Y-m-d H:i'),
            '',
        ];
    }

    /**
     * @param resource $handle
     * @param list<string> $values
     */
    private function row($handle, array $values): void
    {
        fputcsv($handle, array_map(self::cell(...), $values), ',', '"', '');
    }
}
