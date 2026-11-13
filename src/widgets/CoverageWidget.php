<?php

namespace Tahadudhiya\AltMonitor\widgets;

use Craft;
use craft\base\Widget;
use craft\web\View;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\ScanType;
use Tahadudhiya\AltMonitor\web\assets\cp\CpAsset;

/**
 * Craft's dashboard: alt text coverage at a glance, with what needs attention linking into
 * AltMonitor. Counts cover only the volumes the viewer may see, as AltMonitor's own pages do.
 */
class CoverageWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('alt-monitor', 'Alt text coverage');
    }

    public static function icon(): ?string
    {
        return 'universal-access';
    }

    public static function isSelectable(): bool
    {
        return (bool)Craft::$app->getUser()->getIdentity()?->can(AltMonitor::PERMISSION_VIEW);
    }

    protected static function allowMultipleInstances(): bool
    {
        return false;
    }

    public function getBodyHtml(): ?string
    {
        $user = Craft::$app->getUser()->getIdentity();

        // Someone who lost the permission after adding the widget sees nothing of it.
        if ($user === null || !$user->can(AltMonitor::PERMISSION_VIEW)) {
            return null;
        }

        $plugin = AltMonitor::getInstance();
        $audits = $plugin->getAudits();
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CpAsset::class);

        return $view->renderTemplate('alt-monitor/_widget', [
            'stats' => $audits->stats($audits->viewableVolumeIds($user), $user),
            'lastScan' => $plugin->getScans()->latestCompleted([ScanType::FULL, ScanType::INCREMENTAL]),
            'canOpen' => $user->can('accessPlugin-alt-monitor'),
        ], View::TEMPLATE_MODE_CP);
    }
}
