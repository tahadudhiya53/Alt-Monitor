<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\View;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\web\assets\cp\CpAsset;
use yii\base\Component;

/**
 * What AltMonitor adds to Craft's own asset screens: a box in the asset editor's sidebar (the
 * status, the suggestion waiting, describing the image) and an “Alt text status” column for the
 * Assets index. Both are only shown to people who may view AltMonitor.
 *
 * Nothing here writes to an asset: “Use” in the box only fills the editor's Alt Text field, and
 * the asset is saved the usual way.
 */
class AssetsUi extends Component
{
    /** The Assets index column's attribute name. */
    public const TABLE_ATTRIBUTE = 'altMonitorStatus';

    /** @var array<string, array{status: \Tahadudhiya\AltMonitor\enums\AltStatus, issues: list<\Tahadudhiya\AltMonitor\enums\AltIssue>, decorative: bool}|null> */
    private array $statuses = [];

    /** Whether the signed-in user may see what AltMonitor knows about images. */
    public function userMayView(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user instanceof User && $user->can(AltMonitor::PERMISSION_VIEW);
    }

    /**
     * The sidebar box for an asset's editor, or '' for anything that isn't an image, or for
     * people who may not view AltMonitor.
     *
     * @param bool $static Whether the editor is read-only for this person.
     */
    public function sidebarHtml(Asset $asset, bool $static): string
    {
        $user = Craft::$app->getUser()->getIdentity();

        if (!$asset->id || $asset->kind !== Asset::KIND_IMAGE || !$user instanceof User || !$user->can(AltMonitor::PERMISSION_VIEW)) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(CpAsset::class);

        return $view->renderTemplate('alt-monitor/_sidebar', $this->sidebarVariables($asset, $static, $user), View::TEMPLATE_MODE_CP);
    }

    /**
     * @return array<string, mixed>
     */
    private function sidebarVariables(Asset $asset, bool $static, User $user): array
    {
        $plugin = AltMonitor::getInstance();
        $auditSiteId = $plugin->getAssetScanner()->auditSiteId((int)$asset->volumeId, (int)$asset->siteId);
        $item = $plugin->getAudits()->item((int)$asset->id, $auditSiteId, $user);
        $serverAi = $plugin->getSuggestions()->generatesOnServer();
        $canGenerate = $user->can(AltMonitor::PERMISSION_GENERATE);
        $canSave = !$static && Craft::$app->getElements()->canSave($asset, $user);
        // Built once: whoever describes in the browser also translates there.
        $describer = $canGenerate ? $plugin->getAiProviders()->describerConfig() : null;

        return [
            'asset' => $asset,
            // A read-only editor stays read-only when the box is fetched again.
            'static' => $static,
            'auditSiteId' => $auditSiteId,
            'item' => $item,
            'suggestion' => $item?->hasNewSuggestion() ? $item->suggestion : null,
            'canSave' => $canSave,
            'canApprove' => $canSave && $user->can(AltMonitor::PERMISSION_APPROVE),
            'canDismiss' => $user->can(AltMonitor::PERMISSION_APPROVE),
            'describer' => $describer,
            // Translate works with Ollama too (it writes English as well).
            'translator' => $canGenerate ? $describer ?? $plugin->getAiProviders()->translatorConfig() : null,
            'canGenerateOnServer' => $canGenerate && $serverAi && $canSave,
            'auditOnSave' => $plugin->getSettings()->auditOnSave,
            'pageUrl' => $item !== null && $user->can('accessPlugin-alt-monitor')
                ? UrlHelper::cpUrl('alt-monitor/images/' . $asset->id . '/' . $auditSiteId)
                : null,
            'editUrl' => $asset->getCpEditUrl(),
        ];
    }

    /**
     * The Assets index cell: the image's status, with its issues as a tooltip. Statuses for the
     * whole page of assets are read together, the first time one of them is asked for.
     */
    public function tableAttributeHtml(Asset $asset): string
    {
        if ($asset->kind !== Asset::KIND_IMAGE) {
            return '';
        }

        $key = $asset->id . '-' . $asset->siteId;

        if (!array_key_exists($key, $this->statuses)) {
            $page = array_filter($asset->elementQueryResult ?? [$asset], static fn($element): bool => $element instanceof Asset);
            $found = AltMonitor::getInstance()->getAudits()->statusesFor($page);

            foreach ($page as $element) {
                $this->statuses[$element->id . '-' . $element->siteId] = $found[$element->id . '-' . $element->siteId] ?? null;
            }

            $this->statuses[$key] ??= null;
        }

        $status = $this->statuses[$key];

        if ($status === null) {
            return Html::tag('span', Html::encode(Craft::t('alt-monitor', 'Not checked')), ['class' => 'light']);
        }

        $label = $status['decorative'] ? Craft::t('alt-monitor', 'Decorative') : $status['status']->label();
        $issues = $status['decorative'] ? [] : array_map(static fn($issue): string => $issue->label(), $status['issues']);

        $html = (string)Cp::statusLabelHtml([
            'color' => $status['status']->color(),
            'label' => $label,
        ]);

        if ($issues === []) {
            return $html;
        }

        // The reasons, on hover and for screen readers, without widening the column.
        return Html::tag('span', $html . Html::tag('span', Html::encode(': ' . implode(', ', $issues)), ['class' => 'visually-hidden']), [
            'title' => implode(', ', $issues),
        ]);
    }

    /** Forgets statuses read for the current page, as a test or a long-running process may need. */
    public function reset(): void
    {
        $this->statuses = [];
    }
}
