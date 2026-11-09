<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\web\Controller;
use InvalidArgumentException;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\SuggestionSource;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\errors\AiProviderException;
use Tahadudhiya\AltMonitor\errors\AltTextException;
use Tahadudhiya\AltMonitor\models\ListCriteria;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Reviewing suggestions: approving them (optionally edited) onto the asset, rejecting them, and
 * generating them.
 *
 * Generating normally happens in the browser: it fetches each image from {@see actionImage()},
 * describes it with Florence-2, and sends the description to {@see actionStore()}; it walks the
 * images wanting one with {@see actionNext()}. Where Ollama is configured instead, generation is
 * queued on the server ({@see actionRegenerate()}, {@see actionGenerateAll()}).
 */
class SuggestionsController extends Controller
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
     * @throws BadRequestHttpException if a filter or the page is malformed.
     */
    public function actionIndex(): Response
    {
        try {
            $criteria = ListCriteria::fromParams($this->request->getQueryParams());
        } catch (InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }

        $plugin = AltMonitor::getInstance();
        $user = Craft::$app->getUser();

        return $this->renderTemplate('alt-monitor/suggestions/_index', [
            'criteria' => $criteria,
            'page' => $plugin->getSuggestions()->page($criteria, $plugin->getAudits()->viewableVolumeIds($this->user()), $this->user()),
            'pendingCount' => $plugin->getSuggestions()->count(SuggestionStatus::PENDING, $plugin->getAudits()->viewableVolumeIds($this->user())),
            'sources' => SuggestionSource::cases(),
            'suggestionStatuses' => SuggestionStatus::cases(),
            'failures' => $this->failures(),
            'canApprove' => $user->checkPermission(AltMonitor::PERMISSION_APPROVE),
            'canGenerate' => $user->checkPermission(AltMonitor::PERMISSION_GENERATE),
            'canEditAlt' => $user->checkPermission(AltMonitor::PERMISSION_EDIT_ALT),
        ]);
    }

    /**
     * Approves one suggestion, as suggested or as edited.
     */
    public function actionApprove(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(AltMonitor::PERMISSION_APPROVE);

        $edited = $this->request->getBodyParam('alt');

        if ($edited !== null && !is_string($edited)) {
            throw new BadRequestHttpException('The “alt” parameter must be text.');
        }

        $plugin = AltMonitor::getInstance();

        try {
            $suggestion = $plugin->getSuggestions()->approve($this->positiveInt('id'), $edited, $this->user());
        } catch (AltTextException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        // The image's status after saving, so a list can update its row without reloading.
        $status = $plugin->getAudits()->item($suggestion->assetId, $suggestion->siteId, $this->user())?->status;

        return $this->asSuccess(Craft::t('alt-monitor', 'Suggestion approved and saved to the image.'), [
            'status' => $status ? ['value' => $status->value, 'label' => $status->label(), 'color' => $status->color()] : null,
        ]);
    }

    public function actionReject(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(AltMonitor::PERMISSION_APPROVE);

        try {
            AltMonitor::getInstance()->getSuggestions()->reject($this->positiveInt('id'), $this->user());
        } catch (AltTextException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(Craft::t('alt-monitor', 'Suggestion rejected.'));
    }

    /**
     * Approves the selected suggestions, each with the text in its field. Each succeeds or fails
     * on its own, and every failure is reported.
     */
    public function actionBulkApprove(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(AltMonitor::PERMISSION_APPROVE);

        $ids = [];

        foreach ((array)$this->request->getBodyParam('ids', []) as $id) {
            if (!is_string($id) || !preg_match('/^[1-9]\d{0,9}$/', $id)) {
                throw new BadRequestHttpException('Malformed suggestion ID.');
            }

            $ids[] = (int)$id;
        }

        if ($ids === [] || count($ids) > ListCriteria::PER_PAGE) {
            return $this->asFailure(Craft::t('alt-monitor', 'Select between 1 and {max, number} suggestions.', ['max' => ListCriteria::PER_PAGE]));
        }

        $texts = array_filter((array)$this->request->getBodyParam('alt', []), 'is_string');
        $edited = [];

        foreach ($ids as $id) {
            if (isset($texts[$id])) {
                $edited[$id] = $texts[$id];
            }
        }

        $results = AltMonitor::getInstance()->getSuggestions()->approveMany($ids, $edited, $this->user());
        $failures = array_filter($results, static fn(?string $error): bool => $error !== null);
        $approved = count($results) - count($failures);
        $labels = array_map(static fn(int $id, string $reason): array => ['key' => (string)$id, 'reason' => $reason], array_keys($failures), $failures);

        if ($failures === []) {
            return $this->asSuccess(Craft::t('alt-monitor', '{count, number} {count, plural, =1{suggestion} other{suggestions}} approved.', ['count' => $approved]));
        }

        $this->keepFailures($labels);

        $summary = Craft::t('alt-monitor', '{approved, number} approved, {failed, number} could not be.', ['approved' => $approved, 'failed' => count($failures)]);

        return $approved > 0 ? $this->asSuccess($summary, ['failures' => $labels]) : $this->asFailure($summary, ['failures' => $labels]);
    }

    /**
     * The image, resized to a JPEG, for the browser to describe. Served through Craft rather than
     * by URL, so images in volumes without public URLs can be described too.
     */
    public function actionImage(): Response
    {
        $this->requirePermission(AltMonitor::PERMISSION_GENERATE);

        try {
            $asset = AltMonitor::getInstance()->getSuggestions()->viewableImage($this->queryInt('assetId'), $this->queryInt('siteId'), $this->user());
            $jpeg = AltMonitor::getInstance()->getAiProviders()->getImageProcessor()->jpeg($asset);
        } catch (AltTextException) {
            throw new NotFoundHttpException(Craft::t('alt-monitor', 'There is no such image.'));
        } catch (AiProviderException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), 0, $exception);
        }

        // What the image already has, so describing it again looks for something new.
        $known = array_values(array_unique(array_filter([
            (string)$asset->alt,
            (string)AltMonitor::getInstance()->getSuggestions()->get((int)$asset->id, (int)$asset->siteId)?->suggestedAlt,
        ])));

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->content = $jpeg;
        $response->getHeaders()
            ->set('Content-Type', 'image/jpeg')
            ->set('Cache-Control', 'private, no-store')
            ->set('X-Content-Type-Options', 'nosniff')
            // Header values must be ASCII: the JSON is URL-encoded.
            ->set('X-AltMonitor-Known', rawurlencode((string)json_encode($known)))
            ->set('X-AltMonitor-Name', rawurlencode((string)$asset->getFilename()));

        return $response;
    }

    /**
     * The next images with missing or weak alt text and no suggestion, in volumes the user may
     * view, for the browser to describe one after another.
     */
    public function actionNext(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission(AltMonitor::PERMISSION_GENERATE);

        $plugin = AltMonitor::getInstance();
        $after = $this->request->getQueryParam('after', '0');

        if (!is_string($after) || !preg_match('/^\d{1,10}$/', $after)) {
            throw new BadRequestHttpException('The “after” parameter must be a whole number.');
        }

        $rows = $plugin->getAudits()->needingSuggestions((int)$after, 10, $plugin->getAudits()->viewableVolumeIds($this->user()));

        return $this->asJson(['images' => array_map(static fn(array $row): array => [
            'auditId' => $row['id'],
            'assetId' => $row['assetId'],
            'siteId' => $row['siteId'],
        ], $rows)]);
    }

    /**
     * Stores the description the browser's model wrote for an image.
     */
    public function actionStore(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(AltMonitor::PERMISSION_GENERATE);

        try {
            $suggestion = AltMonitor::getInstance()->getSuggestions()->storeDescription($this->positiveInt('assetId'), $this->positiveInt('siteId'), $this->text('text'), $this->user());
        } catch (AltTextException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        if ($suggestion === null) {
            return $this->asSuccess(Craft::t('alt-monitor', 'The image already has this alt text.'), ['suggestion' => null]);
        }

        return $this->asSuccess(Craft::t('alt-monitor', 'Suggestion saved for review.'), ['suggestion' => [
            'id' => $suggestion->id,
            'text' => $suggestion->suggestedAlt,
            'status' => $suggestion->status->value,
        ]]);
    }

    /**
     * Queues one image's suggestion to be generated again on the server.
     */
    public function actionRegenerate(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(AltMonitor::PERMISSION_GENERATE);

        $plugin = AltMonitor::getInstance();

        if (!$plugin->getSuggestions()->generatesOnServer()) {
            return $this->asFailure(self::inBrowserMessage());
        }

        try {
            $asset = $plugin->getAltTextEditor()->editableImage($this->positiveInt('assetId'), $this->positiveInt('siteId'), $this->user());
        } catch (AltTextException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        if ($plugin->getSuggestions()->queue([[(int)$asset->id, (int)$asset->siteId]]) === 0) {
            return $this->asFailure(Craft::t('alt-monitor', 'A suggestion for this image is already being generated.'));
        }

        return $this->asSuccess(Craft::t('alt-monitor', 'A new suggestion is being generated.'));
    }

    /**
     * Queues suggestions for every image with missing or weak alt text that has none yet.
     */
    public function actionGenerateAll(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(AltMonitor::PERMISSION_GENERATE);

        if (!AltMonitor::getInstance()->getSuggestions()->generatesOnServer()) {
            return $this->asFailure(self::inBrowserMessage());
        }

        $plugin = AltMonitor::getInstance();
        $plugin->getSuggestions()->queueForAllWanting($plugin->getAudits()->viewableVolumeIds($this->user()));

        return $this->asSuccess(Craft::t('alt-monitor', 'Suggestions are being generated for images with missing or weak alt text.'));
    }

    /** Why the server didn't generate: that happens in the browser, which needs JavaScript. */
    public static function inBrowserMessage(): string
    {
        return Craft::t('alt-monitor', 'Suggestions are described from the image in your browser. Use the Describe buttons, which need JavaScript.');
    }

    /**
     * @throws BadRequestHttpException
     */
    private function queryInt(string $name): int
    {
        $value = $this->request->getQueryParam($name);

        if (!is_string($value) || !preg_match('/^[1-9]\d{0,9}$/', $value)) {
            throw new BadRequestHttpException("The “{$name}” parameter must be a positive whole number.");
        }

        return (int)$value;
    }
}
