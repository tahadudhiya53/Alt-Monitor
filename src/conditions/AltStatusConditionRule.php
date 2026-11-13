<?php

namespace Tahadudhiya\AltMonitor\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Asset;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\records\AuditRecord;

/**
 * “Alt text status” for asset conditions: the Assets index filters, custom sources and
 * relational field conditions. Matches images by their last audit; “Not checked” matches images
 * no scan or save has audited yet.
 *
 * Each asset is matched in the site it is queried in, or for volumes whose alt text is the same
 * in every site, by its audit in the primary site.
 */
class AltStatusConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /** The option for images without an audit. */
    public const NOT_CHECKED = 'notChecked';

    public function getLabel(): string
    {
        return Craft::t('alt-monitor', 'Alt text status');
    }

    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    protected function operators(): array
    {
        return [self::OPERATOR_IN, self::OPERATOR_NOT_IN];
    }

    protected function options(): array
    {
        $options = array_map(static fn(AltStatus $status): array => ['value' => $status->value, 'label' => $status->label()], AltStatus::cases());
        $options[] = ['value' => self::NOT_CHECKED, 'label' => Craft::t('alt-monitor', 'Not checked')];

        return $options;
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        $values = $this->getValues();
        $statuses = array_values(array_intersect($values, array_map(static fn(AltStatus $status): string => $status->value, AltStatus::cases())));
        $matches = ['or'];

        if ($statuses !== []) {
            $matches[] = ['exists', $this->auditQuery()->andWhere(['am_audits.altStatus' => $statuses])];
        }

        if (in_array(self::NOT_CHECKED, $values, true)) {
            $matches[] = ['and', ['assets.kind' => Asset::KIND_IMAGE], ['not exists', $this->auditQuery()]];
        }

        if (count($matches) === 1) {
            return;
        }

        $query->andWhere($this->operator === self::OPERATOR_NOT_IN ? ['not', $matches] : $matches);
    }

    public function matchElement(ElementInterface $element): bool
    {
        if (!$element instanceof Asset) {
            return false;
        }

        $status = AltMonitor::getInstance()->getAudits()->statusesFor([$element])[$element->id . '-' . $element->siteId] ?? null;
        $value = match (true) {
            $status !== null => $status['status']->value,
            $element->kind === Asset::KIND_IMAGE => self::NOT_CHECKED,
            default => null,
        };

        return $this->matchValue($value);
    }

    /**
     * The audit of the asset being queried, in the site it is queried in (or the primary site,
     * for volumes audited only there). Correlated with Craft's element query tables.
     */
    private function auditQuery(): Query
    {
        $shared = AltMonitor::getInstance()->getAssetScanner()->sharedAltVolumeIds();
        $site = ['or', '[[am_audits.siteId]] = [[elements_sites.siteId]]'];

        if ($shared !== []) {
            $site[] = ['and', ['am_audits.siteId' => (int)Craft::$app->getSites()->getPrimarySite()->id], ['am_audits.volumeId' => $shared]];
        }

        return (new Query())
            ->select(['am_audits.id'])
            ->from(['am_audits' => AuditRecord::TABLE])
            ->where('[[am_audits.assetId]] = [[elements.id]]')
            ->andWhere($site);
    }
}
