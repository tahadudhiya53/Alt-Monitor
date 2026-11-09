<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\ScanType;
use Tahadudhiya\AltMonitor\errors\ScanInProgressException;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Starting scans. The scans themselves run in Craft's queue.
 */
class ScanController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(AltMonitor::PERMISSION_VIEW);
        $this->requirePermission(AltMonitor::PERMISSION_RUN_SCANS);

        return true;
    }

    public function actionStart(): Response
    {
        $this->requirePostRequest();

        $type = ScanType::tryFrom((string)$this->request->getBodyParam('type')) ?? throw new BadRequestHttpException('Unknown scan type.');

        try {
            $scan = AltMonitor::getInstance()->getScans()->start($type);
        } catch (ScanInProgressException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('alt-monitor', '{type} queued. Images stay available meanwhile.', ['type' => $scan->type->label()]), ['scan' => $scan->toArray()], 'alt-monitor');
    }
}
