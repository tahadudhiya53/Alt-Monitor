<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\models\Site;
use craft\web\Controller;
use Tahadudhiya\AltMonitor\AltMonitor;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The Pages list: content ranked by how many of its images need work, one site at a time, so
 * people can fix a site page by page. Only reads.
 */
class PagesController extends Controller
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

    /**
     * @throws BadRequestHttpException if the site or the page is malformed.
     */
    public function actionIndex(): Response
    {
        $criteria = $this->criteria();

        $plugin = AltMonitor::getInstance();
        $user = $this->user();
        $site = $criteria->siteId !== null ? Craft::$app->getSites()->getSiteById($criteria->siteId) : $this->defaultSite();
        $usageChecked = $plugin->getPages()->usageChecked();

        return $this->renderTemplate('alt-monitor/pages/_index', [
            'criteria' => $criteria,
            'site' => $site,
            'page' => $usageChecked && $site !== null ? $plugin->getPages()->page((int)$site->id, $criteria->page, $user) : null,
            'usageChecked' => $usageChecked,
            'canScan' => $user->can(AltMonitor::PERMISSION_RUN_SCANS),
            'activeScan' => $usageChecked ? null : $plugin->getScans()->active(),
        ]);
    }

    /**
     * The primary site, or on a multi-site install the first site the user may edit: lists only
     * show images in sites they may edit.
     */
    private function defaultSite(): Site
    {
        $sites = Craft::$app->getSites();
        $user = $this->user();

        if (!Craft::$app->getIsMultiSite() || $user->admin || $user->can('editSite:' . $sites->getPrimarySite()->uid)) {
            return $sites->getPrimarySite();
        }

        foreach ($sites->getAllSites() as $site) {
            if ($user->can("editSite:$site->uid")) {
                return $site;
            }
        }

        return $sites->getPrimarySite();
    }
}
