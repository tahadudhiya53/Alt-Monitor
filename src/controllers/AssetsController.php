<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\web\Controller;
use InvalidArgumentException;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\enums\UsageStatus;
use Tahadudhiya\AltMonitor\errors\AltTextException;
use Tahadudhiya\AltMonitor\models\ListCriteria;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The image list and each image's page: viewing audits, editing alt text, marking images
 * decorative, and acting on a selection.
 *
 * Images are listed only from volumes the user may view; every change also needs Craft's own
 * permission to save the asset, checked per image.
 */
class AssetsController extends Controller
{
    use RequestInput;

    /** Most images one bulk request acts on: a page's worth. */
    private const MAX_SELECTION = ListCriteria::PER_PAGE;

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
     * @throws BadRequestHttpException if a filter or the page is malformed.
     */
    public function actionIndex(): Response
    {
        try {
            $criteria = ListCriteria::fromParams($this->request->getQueryParams());
        } catch (InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }

        $audits = AltMonitor::getInstance()->getAudits();
        $volumeIds = $audits->viewableVolumeIds($this->user());

        return $this->renderTemplate('alt-monitor/images/_index', [
            'criteria' => $criteria,
            'page' => $audits->page($criteria, $volumeIds, $this->user()),
            'stats' => $audits->stats($volumeIds),
            'volumes' => array_values(array_filter(Craft::$app->getVolumes()->getAllVolumes(), static fn($volume): bool => in_array((int)$volume->id, $volumeIds, true))),
            'statuses' => AltStatus::cases(),
            'usageStatuses' => UsageStatus::cases(),
            'suggestionStatuses' => SuggestionStatus::cases(),
            'failures' => $this->failures(),
        ] + $this->abilities() + $this->describer());
    }

    /**
     * One image in one site: its audit, suggestion and where it is used.
     */
    public function actionView(int $assetId, int $siteId): Response
    {
        $plugin = AltMonitor::getInstance();
        $item = $plugin->getAudits()->item($assetId, $siteId, $this->user());

        if ($item === null || !in_array((int)$item->asset->volumeId, $plugin->getAudits()->viewableVolumeIds($this->user()), true)) {
            throw new NotFoundHttpException(Craft::t('alt-monitor', 'This image has not been audited, or you may not view it.'));
        }

        return $this->renderTemplate('alt-monitor/images/_view', [
            'item' => $item,
            'references' => $plugin->getContentUsage()->references($assetId, user: $this->user()),
        ] + $this->abilities() + $this->describer());
    }

    /**
     * Saves one image's alt text.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(AltMonitor::PERMISSION_EDIT_ALT);

        try {
            $asset = AltMonitor::getInstance()->getAltTextEditor()->save(
                $this->positiveInt('assetId'),
                $this->positiveInt('siteId'),
                $this->text('alt'),
                $this->user(),
            );
        } catch (AltTextException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('alt-monitor', 'Alt text saved.'), ['alt' => $asset->alt]);
    }

    /**
     * Marks an image as decorative, meaning its empty alt text is intended, or unmarks it.
     */
    public function actionDecorative(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(AltMonitor::PERMISSION_EDIT_ALT);

        $plugin = AltMonitor::getInstance();
        $decorative = (bool)$this->request->getBodyParam('decorative');

        try {
            $asset = $plugin->getAltTextEditor()->editableImage($this->positiveInt('assetId'), $this->positiveInt('siteId'), $this->user());
        } catch (AltTextException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        $plugin->getAudits()->setDecorative((int)$asset->id, (int)$asset->siteId, $decorative);
        $plugin->getAssetScanner()->auditAsset($asset);

        return $this->asSuccess($decorative ? Craft::t('alt-monitor', 'Marked as decorative.') : Craft::t('alt-monitor', 'No longer marked as decorative.'));
    }

    /**
     * Acts on the images selected in the list: saves the alt text typed for them, approves their
     * pending suggestions, or queues suggestions for them. A row's own Save button saves just
     * that row. Each image succeeds or fails on its own, and every failure is reported.
     */
    public function actionBulk(): ?Response
    {
        $this->requirePostRequest();

        $single = $this->request->getBodyParam('save');
        $keys = is_string($single) ? [$single] : (array)$this->request->getBodyParam('selected', []);
        $intent = is_string($single) ? 'save' : (string)$this->request->getBodyParam('intent');
        $pairs = $this->pairs($keys);

        if ($pairs === []) {
            return $this->asFailure(Craft::t('alt-monitor', 'Select at least one image.'));
        }

        $plugin = AltMonitor::getInstance();
        $user = $this->user();
        $failures = [];
        $done = 0;

        switch ($intent) {
            case 'save':
                $this->requirePermission(AltMonitor::PERMISSION_EDIT_ALT);
                $texts = (array)$this->request->getBodyParam('alt', []);

                foreach ($pairs as $key => [$assetId, $siteId]) {
                    if (!isset($texts[$key]) || !is_string($texts[$key])) {
                        $failures[$key] = Craft::t('alt-monitor', 'No alt text was sent for this image.');
                        continue;
                    }

                    try {
                        $plugin->getAltTextEditor()->save($assetId, $siteId, $texts[$key], $user);
                        $done++;
                    } catch (AltTextException $exception) {
                        $failures[$key] = $exception->getMessage();
                    }
                }

                $message = Craft::t('alt-monitor', 'Alt text saved for {count, number} {count, plural, =1{image} other{images}}.', ['count' => $done]);
                break;
            case 'approve':
                $this->requirePermission(AltMonitor::PERMISSION_APPROVE);
                $suggestions = $plugin->getSuggestions()->forPairs(array_values($pairs));
                $ids = [];

                foreach ($pairs as $key => $pair) {
                    $suggestion = $suggestions[$key] ?? null;

                    if ($suggestion === null || !$suggestion->canApprove()) {
                        $failures[$key] = Craft::t('alt-monitor', 'There is no suggestion waiting for review.');
                    } else {
                        $ids[$suggestion->id] = $key;
                    }
                }

                foreach ($plugin->getSuggestions()->approveMany(array_keys($ids), [], $user) as $id => $error) {
                    $error === null ? $done++ : $failures[$ids[$id]] = $error;
                }

                $message = Craft::t('alt-monitor', '{count, number} {count, plural, =1{suggestion} other{suggestions}} approved.', ['count' => $done]);
                break;
            case 'generate':
                $this->requirePermission(AltMonitor::PERMISSION_GENERATE);

                if (!$plugin->getSuggestions()->generatesOnServer()) {
                    return $this->asFailure(SuggestionsController::inBrowserMessage());
                }

                $allowed = [];

                foreach ($pairs as $key => [$assetId, $siteId]) {
                    try {
                        $plugin->getSuggestions()->viewableImage($assetId, $siteId, $user);
                        $allowed[] = [$assetId, $siteId];
                    } catch (AltTextException $exception) {
                        $failures[$key] = $exception->getMessage();
                    }
                }

                $done = $plugin->getSuggestions()->queue($allowed);
                $message = Craft::t('alt-monitor', 'Suggestions queued for {count, number} {count, plural, =1{image} other{images}}.', ['count' => $done]);
                break;
            default:
                throw new BadRequestHttpException('Unknown action.');
        }

        return $this->respond($message, $failures, $done);
    }

    /**
     * @param array<string, string> $failures Why each failed image failed, by row key.
     */
    private function respond(string $message, array $failures, int $done): ?Response
    {
        if ($failures === []) {
            return $this->asSuccess($message);
        }

        $labels = [];

        foreach ($failures as $key => $reason) {
            $labels[] = ['key' => $key, 'reason' => $reason];
        }

        $this->keepFailures($labels);

        $summary = Craft::t('alt-monitor', '{failed, number} {failed, plural, =1{image} other{images}} could not be updated.', ['failed' => count($failures)]);

        return $done > 0
            ? $this->asSuccess("$message $summary", ['failures' => $labels])
            : $this->asFailure($summary, ['failures' => $labels]);
    }

    /**
     * Row keys (`assetId-siteId`) as asset and site IDs, refusing anything else.
     *
     * @param array<mixed> $keys
     * @return array<string, array{0: int, 1: int}>
     * @throws BadRequestHttpException
     */
    private function pairs(array $keys): array
    {
        if (count($keys) > self::MAX_SELECTION) {
            throw new BadRequestHttpException('Too many images selected.');
        }

        $pairs = [];

        foreach ($keys as $key) {
            if (!is_string($key) || !preg_match('/^([1-9]\d{0,9})-([1-9]\d{0,9})$/', $key, $match)) {
                throw new BadRequestHttpException('Malformed image key.');
            }

            $pairs[$key] = [(int)$match[1], (int)$match[2]];
        }

        return $pairs;
    }

    /**
     * @return array<string, mixed>
     */
    private function abilities(): array
    {
        $user = Craft::$app->getUser();

        return [
            'canEditAlt' => $user->checkPermission(AltMonitor::PERMISSION_EDIT_ALT),
            'canApprove' => $user->checkPermission(AltMonitor::PERMISSION_APPROVE),
            'canGenerate' => $user->checkPermission(AltMonitor::PERMISSION_GENERATE),
            'canScan' => $user->checkPermission(AltMonitor::PERMISSION_RUN_SCANS),
            'serverAi' => AltMonitor::getInstance()->getSuggestions()->generatesOnServer(),
        ];
    }

    /**
     * @return array{describer: array<string, string|null>|null}
     */
    private function describer(): array
    {
        $plugin = AltMonitor::getInstance();

        return ['describer' => Craft::$app->getUser()->checkPermission(AltMonitor::PERMISSION_GENERATE) && !$plugin->getSuggestions()->generatesOnServer()
            ? $plugin->getAiProviders()->browserConfig()
            : null, ];
    }
}
