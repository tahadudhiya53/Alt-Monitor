<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use InvalidArgumentException;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\enums\UsageStatus;
use Tahadudhiya\AltMonitor\errors\AltTextException;
use Tahadudhiya\AltMonitor\models\ListCriteria;
use Tahadudhiya\AltMonitor\services\AltTextEditor;
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
        $stats = $audits->stats($volumeIds, $this->user());

        return $this->renderTemplate('alt-monitor/images/_index', [
            'criteria' => $criteria,
            'page' => $audits->page($criteria, $volumeIds, $this->user()),
            'stats' => $stats,
            'volumes' => array_values(array_filter(Craft::$app->getVolumes()->getAllVolumes(), static fn($volume): bool => in_array((int)$volume->id, $volumeIds, true))),
            'statuses' => AltStatus::cases(),
            'usageStatuses' => UsageStatus::cases(),
            'suggestionStatuses' => SuggestionStatus::cases(),
            'failures' => $this->failures(),
            // For the empty list: a scan already on its way, rather than a button to start one.
            'activeScan' => $stats->audited === 0 ? AltMonitor::getInstance()->getScans()->active() : null,
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
            throw new NotFoundHttpException(Craft::t('alt-monitor', 'This image hasn’t been checked yet, or you can’t see it.'));
        }

        return $this->renderTemplate('alt-monitor/images/_view', [
            'item' => $item,
            'references' => $plugin->getContentUsage()->references($assetId, user: $this->user()),
        ] + $this->abilities() + $this->describer());
    }

    /**
     * How alt text being typed would be graded, without saving anything: the same rules a scan
     * applies, so editors see the hints while they write.
     */
    public function actionCheck(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = AltMonitor::getInstance();
        $typed = $this->text('alt');
        $item = $plugin->getAudits()->item($this->positiveInt('assetId'), $this->positiveInt('siteId'), $this->user());

        if ($item === null || !in_array((int)$item->asset->volumeId, $plugin->getAudits()->viewableVolumeIds($this->user()), true)) {
            throw new NotFoundHttpException(Craft::t('alt-monitor', 'This image hasn’t been checked yet, or you can’t see it.'));
        }

        try {
            $text = AltTextEditor::sanitize($typed);
        } catch (AltTextException $exception) {
            return $this->asJson(['status' => null, 'issues' => [$exception->getMessage()]]);
        }

        $analysis = $plugin->getAnalyzer()->analyze($text, $item->asset->filename, $item->decorative);

        return $this->asJson([
            'status' => ['value' => $analysis->status->value, 'label' => $analysis->status->label(), 'color' => $analysis->status->color()],
            'issues' => array_map(static fn($issue): string => $issue->label(), $analysis->issues),
        ]);
    }

    /**
     * The box in Craft's asset editor sidebar, rendered again after something changed it (an
     * image described), so it updates without reloading the editor and losing unsaved changes.
     */
    public function actionSidebar(): Response
    {
        $this->requireAcceptsJson();
        $asset = Craft::$app->getElements()->getElementById($this->queryInt('assetId'), Asset::class, $this->queryInt('siteId'));

        if (!$asset instanceof Asset || !Craft::$app->getElements()->canView($asset, $this->user())) {
            throw new NotFoundHttpException(Craft::t('alt-monitor', 'This image hasn’t been checked yet, or you can’t see it.'));
        }

        // The box sends whether its editor is read-only, so it stays so after a refresh. Saving
        // still checks the user may save the asset.
        $static = (bool)$this->request->getQueryParam('static');

        return $this->asJson(['html' => AltMonitor::getInstance()->getAssetsUi()->sidebarHtml($asset, $static)]);
    }

    /**
     * Saves one image's alt text. The answer carries the image's status afterwards, so the
     * images list can update the row in place.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(AltMonitor::PERMISSION_EDIT_ALT);

        $plugin = AltMonitor::getInstance();
        $siteId = $this->positiveInt('siteId');

        try {
            $asset = $plugin->getAltTextEditor()->save(
                $this->positiveInt('assetId'),
                $siteId,
                $this->text('alt'),
                $this->user(),
            );
        } catch (AltTextException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        $item = $plugin->getAudits()->item($asset->id, $siteId, $this->user());
        $status = $item?->status;

        return $this->asSuccess(Craft::t('alt-monitor', 'Alt text saved.'), [
            'alt' => $asset->alt,
            'status' => $status ? ['value' => $status->value, 'label' => $status->label(), 'color' => $status->color()] : null,
            'issues' => $item && $status !== AltStatus::MISSING ? array_map(fn($issue) => $issue->label(), $item->issues) : [],
            'hasSuggestion' => $item?->hasNewSuggestion() ?? false,
        ]);
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
     * The Images list's form. “Save changes” (`save`) saves every row whose alt text differs from
     * what the page showed, whatever is selected; the other actions act on the selected images:
     * mark them decorative, approve their pending suggestions, or queue suggestions for them.
     * Each image succeeds or fails on its own, and every failure is reported.
     */
    public function actionBulk(): ?Response
    {
        $this->requirePostRequest();

        $intent = (string)$this->request->getBodyParam('intent');

        if ($intent === 'save') {
            return $this->saveChanged();
        }

        $pairs = $this->pairs((array)$this->request->getBodyParam('selected', []));

        if ($pairs === []) {
            return $this->asFailure(Craft::t('alt-monitor', 'Select at least one image.'));
        }

        $plugin = AltMonitor::getInstance();
        $user = $this->user();
        $failures = [];
        $done = 0;

        switch ($intent) {
            case 'decorative':
                // As on an image's page: the flag only, never the alt text, which someone wrote.
                $this->requirePermission(AltMonitor::PERMISSION_EDIT_ALT);
                $withAlt = 0;

                foreach ($pairs as $key => [$assetId, $siteId]) {
                    try {
                        $asset = $plugin->getAltTextEditor()->editableImage($assetId, $siteId, $user);
                    } catch (AltTextException $exception) {
                        $failures[$key] = $exception->getMessage();
                        continue;
                    }

                    $plugin->getAudits()->setDecorative((int)$asset->id, (int)$asset->siteId, true);
                    $plugin->getAssetScanner()->auditAsset($asset);
                    $done++;
                    $withAlt += trim((string)$asset->alt) === '' ? 0 : 1;
                }

                $message = Craft::t('alt-monitor', 'Marked {count, number} {count, plural, =1{image} other{images}} as decorative.', ['count' => $done]);

                if ($withAlt > 0) {
                    $message .= ' ' . Craft::t('alt-monitor', '{count, number} of them still {count, plural, =1{has} other{have}} alt text. Clear it if the image is only decoration.', ['count' => $withAlt]);
                }
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
     * Saves the rows whose typed text differs from the text they were shown with (`original`),
     * so text someone else saved meanwhile in a row left alone isn't overwritten.
     */
    private function saveChanged(): ?Response
    {
        $this->requirePermission(AltMonitor::PERMISSION_EDIT_ALT);

        $texts = $this->request->getBodyParam('alt', []);
        $originals = $this->request->getBodyParam('original', []);

        if (!is_array($texts) || !is_array($originals)) {
            throw new BadRequestHttpException('Malformed alt text.');
        }

        $editor = AltMonitor::getInstance()->getAltTextEditor();
        $user = $this->user();
        $failures = [];
        $done = 0;

        foreach ($this->pairs(array_map('strval', array_keys($texts))) as $key => [$assetId, $siteId]) {
            $text = $texts[$key];
            $original = $originals[$key] ?? null;

            if (!is_string($text) || ($original !== null && !is_string($original))) {
                throw new BadRequestHttpException('Malformed alt text.');
            }

            if ($text === $original) {
                continue;
            }

            try {
                $editor->save($assetId, $siteId, $text, $user);
                $done++;
            } catch (AltTextException $exception) {
                $failures[$key] = $exception->getMessage();
            }
        }

        if ($done === 0 && $failures === []) {
            return $this->asSuccess(Craft::t('alt-monitor', 'Nothing changed, so nothing was saved.'));
        }

        return $this->respond(Craft::t('alt-monitor', 'Alt text saved for {count, number} {count, plural, =1{image} other{images}}.', ['count' => $done]), $failures, $done);
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
     * @return array{describer: array<string, mixed>|null, translator: array<string, mixed>|null}
     */
    private function describer(): array
    {
        $providers = AltMonitor::getInstance()->getAiProviders();
        $describer = $providers->describerConfig();

        // Whoever describes in the browser translates there too: the same config, built once.
        return ['describer' => $describer, 'translator' => $describer ?? $providers->translatorConfig()];
    }
}
