<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use Tahadudhiya\AltMonitor\AltMonitor;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The box in Craft's entry editor sidebar, rendered again after the entry is autosaved or one of
 * its images changes, so it stays current without reloading the editor. Only reads.
 */
class EntriesController extends Controller
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
     * The box for an entry, or a draft of it (the editor sends the ID it is editing now).
     */
    public function actionSidebar(): Response
    {
        $this->requireAcceptsJson();
        $entry = Craft::$app->getElements()->getElementById($this->queryInt('elementId'), Entry::class, $this->queryInt('siteId'));

        if (!$entry instanceof Entry || !Craft::$app->getElements()->canView($entry, $this->user())) {
            throw new NotFoundHttpException(Craft::t('alt-monitor', 'This entry doesn’t exist, or you can’t see it.'));
        }

        return $this->asJson(['html' => AltMonitor::getInstance()->getEntryCheck()->sidebarHtml($entry)]);
    }
}
