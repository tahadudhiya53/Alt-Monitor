<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\ScanType;
use yii\web\Response;

/**
 * The dashboard: what the audit found, and the scans behind it.
 */
class DashboardController extends Controller
{
    use RequestInput;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(AltMonitor::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = AltMonitor::getInstance();
        $scans = $plugin->getScans();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('alt-monitor/_dashboard', [
            'stats' => $plugin->getAudits()->stats($plugin->getAudits()->viewableVolumeIds($this->user())),
            'activeScan' => $scans->active(),
            'lastScan' => $scans->latestCompleted([ScanType::FULL, ScanType::INCREMENTAL]),
            'lastUsageScan' => $scans->latestCompleted([ScanType::USAGE]),
            'latestScan' => $scans->latest(),
            'serverAi' => $settings->aiEnabled,
            'aiModel' => $settings->aiEnabled ? $settings->ollamaModel : $plugin->getAiProviders()->browserConfig()['label'],
            'describer' => Craft::$app->getUser()->checkPermission(AltMonitor::PERMISSION_GENERATE) && !$plugin->getSuggestions()->generatesOnServer()
                ? $plugin->getAiProviders()->browserConfig()
                : null,
            'canScan' => Craft::$app->getUser()->checkPermission(AltMonitor::PERMISSION_RUN_SCANS),
            'canGenerate' => Craft::$app->getUser()->checkPermission(AltMonitor::PERMISSION_GENERATE),
        ]);
    }

    /**
     * The active or latest scan's progress, which the dashboard polls while a scan runs.
     */
    public function actionProgress(): Response
    {
        $this->requireAcceptsJson();
        $scans = AltMonitor::getInstance()->getScans();
        $scan = $scans->active() ?? $scans->latest();

        return $this->asJson(['scan' => $scan?->toArray()]);
    }
}
