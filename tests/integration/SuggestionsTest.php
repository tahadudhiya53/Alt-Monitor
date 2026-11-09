<?php

namespace Tahadudhiya\AltMonitor\Tests\integration;

use Craft;
use craft\elements\Asset;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\controllers\AssetsController;
use Tahadudhiya\AltMonitor\controllers\SettingsController;
use Tahadudhiya\AltMonitor\controllers\SuggestionsController;
use Tahadudhiya\AltMonitor\enums\ScanType;
use Tahadudhiya\AltMonitor\enums\SuggestionSource;
use Tahadudhiya\AltMonitor\enums\SuggestionStatus;
use Tahadudhiya\AltMonitor\errors\AltTextException;
use Tahadudhiya\AltMonitor\jobs\GenerateAltTextJob;
use Tahadudhiya\AltMonitor\services\SuggestionGenerator;
use Tahadudhiya\AltMonitor\Tests\_support\AltMonitorFixture;

/**
 * Suggestions and alt text editing end to end: rule-based and (mocked) AI generation, fallbacks,
 * duplicate prevention, approval onto the asset, bulk actions, and the pages showing them.
 */
class SuggestionsTest extends TestCase
{
    use AltMonitorFixture {
        setUp as fixtureSetUp;
    }

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        // Most tests here exercise the server path, which builds suggestions from the caption,
        // title or file name only when this is on. It is off by default.
        self::plugin()->getSettings()->fallbackToRules = true;
    }

    /** @var list<RequestInterface> Requests the mocked Ollama received. */
    private array $requests = [];

    private static function plugin(): AltMonitor
    {
        return AltMonitor::getInstance();
    }

    private static function altOf(Asset $asset, ?int $siteId = null): ?string
    {
        return Asset::find()->id($asset->id)->siteId($siteId ?? self::$primarySiteId)->status(null)->one()?->alt;
    }

    /**
     * Turns AI on, with Ollama answering through the given responses.
     *
     * @param list<Psr7Response|\Throwable> $responses
     */
    private function mockOllama(array $responses): void
    {
        $this->requests = [];
        $queue = array_map(fn($response) => function(RequestInterface $request) use ($response) {
            $this->requests[] = $request;

            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        }, $responses);

        self::plugin()->getSettings()->aiEnabled = true;
        self::plugin()->getAiProviders()->httpHandler = new MockHandler($queue);
    }

    private static function json(array $data, int $status = 200): Psr7Response
    {
        return new Psr7Response($status, ['Content-Type' => 'application/json'], (string)json_encode($data));
    }

    private function editor(): \Tahadudhiya\AltMonitor\Tests\_support\TestUser
    {
        return $this->signIn(false, ['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW, AltMonitor::PERMISSION_EDIT_ALT, AltMonitor::PERMISSION_APPROVE, AltMonitor::PERMISSION_GENERATE, ...self::assetPermissions()]);
    }

    // Generation

    public function testRuleBasedSuggestionsUseTheCaptionTitleOrFileNameWithoutAi(): void
    {
        $captioned = self::savedImage('IMG_0001.jpg', null, null, [self::CAPTION => 'Volunteers planting trees in the park. Photo by Sam.']);
        $named = self::savedImage('red-kite_over_the-hills-2048x1536@2x.jpg');
        $meaningless = self::savedImage('DSC_4482.jpg');
        self::savedEntry('Spring newsletter', [self::IMAGES => [$meaningless->id]]);
        self::plugin()->getScans()->start(ScanType::USAGE);
        $this->runJobs();

        $suggestions = self::plugin()->getSuggestions();
        $caption = $suggestions->generate((int)$captioned->id, self::$primarySiteId);
        $filename = $suggestions->generate((int)$named->id, self::$primarySiteId);
        $usage = $suggestions->generate((int)$meaningless->id, self::$primarySiteId);

        self::assertSame(['Volunteers planting trees in the park', SuggestionSource::RULES, SuggestionGenerator::BASIS_CAPTION, false], [$caption?->suggestedAlt, $caption->source, $caption->basis, $caption->needsReview]);
        self::assertSame(['Red kite over the hills', SuggestionGenerator::BASIS_FILENAME, true], [$filename?->suggestedAlt, $filename->basis, $filename->needsReview]);
        self::assertSame(['Spring newsletter', SuggestionGenerator::BASIS_USAGE, true], [$usage?->suggestedAlt, $usage->basis, $usage->needsReview]);
        self::assertSame(SuggestionStatus::PENDING, $caption->status);

        // Nothing reaches the asset until someone approves it.
        self::assertNull(self::altOf($captioned));
    }

    public function testAnImageNothingDescribesGetsNoSuggestionAndSaysWhy(): void
    {
        $image = self::savedImage('IMG_9999.jpg');

        $suggestion = self::plugin()->getSuggestions()->generate((int)$image->id, self::$primarySiteId);

        self::assertSame(SuggestionStatus::FAILED, $suggestion?->status);
        self::assertNull($suggestion->suggestedAlt);
        self::assertNotEmpty($suggestion->error);
    }

    public function testAnAiSuggestionDescribesTheImageSentToOllama(): void
    {
        $image = self::savedImage('IMG_0002.png', null, null, [self::CAPTION => 'Annual report cover']);
        $this->mockOllama([self::json(['model' => 'qwen2.5vl:3b', 'response' => "Alt text: \"An image of a blue square on a white page.\"\n\nThis is a simple graphic."])]);

        $suggestion = self::plugin()->getSuggestions()->generate((int)$image->id, self::$primarySiteId);

        self::assertSame(SuggestionSource::AI, $suggestion?->source);
        self::assertSame('A blue square on a white page.', $suggestion->suggestedAlt);
        self::assertSame('qwen2.5vl:3b', $suggestion->basis);
        self::assertTrue($suggestion->needsReview);

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/generate', $request->getUri()->getPath());
        $body = json_decode((string)$request->getBody(), true);
        self::assertSame('qwen2.5vl:3b', $body['model']);
        self::assertFalse($body['stream']);
        self::assertStringContainsString('Describe only what is clearly visible', $body['prompt']);
        self::assertStringContainsString('Annual report cover', $body['prompt']);
        // The image itself, resized to a JPEG, is what is sent: not a URL.
        $jpeg = base64_decode($body['images'][0], true);
        self::assertIsString($jpeg);
        self::assertSame("\xFF\xD8", substr($jpeg, 0, 2));
    }

    public function testWhenOllamaIsUnavailableTheSuggestionFallsBackToRulesAndSaysSo(): void
    {
        $image = self::savedImage('mountain-lake.jpg');
        $this->mockOllama([new ConnectException('Connection refused', new Request('POST', 'http://localhost:11434/api/generate'))]);

        $suggestion = self::plugin()->getSuggestions()->generate((int)$image->id, self::$primarySiteId);

        self::assertSame(SuggestionSource::RULES, $suggestion?->source);
        self::assertSame('Mountain lake', $suggestion->suggestedAlt);
        self::assertStringContainsString('could not be reached', (string)$suggestion->error);
    }

    public function testWithoutTheFallbackAnAiFailureIsRecordedAsFailed(): void
    {
        $image = self::savedImage('mountain-lake.jpg');
        self::plugin()->getSettings()->fallbackToRules = false;
        $this->mockOllama([self::json(['error' => 'model "qwen2.5vl:3b" not found'], 404)]);

        $suggestion = self::plugin()->getSuggestions()->generate((int)$image->id, self::$primarySiteId);

        self::assertSame(SuggestionStatus::FAILED, $suggestion?->status);
        self::assertSame(SuggestionSource::AI, $suggestion->source);
        self::assertStringContainsString('not installed', (string)$suggestion->error);
    }

    public function testAnUnusableAiAnswerIsNotOffered(): void
    {
        $image = self::savedImage('IMG_0003.png');
        self::plugin()->getSettings()->fallbackToRules = false;
        $this->mockOllama([self::json(['response' => 'IMG_0003.png'])]);

        $suggestion = self::plugin()->getSuggestions()->generate((int)$image->id, self::$primarySiteId);

        self::assertSame(SuggestionStatus::FAILED, $suggestion?->status);
        self::assertNull($suggestion->suggestedAlt);
    }

    public function testTheSameImageIsNeverQueuedTwiceAndRegeneratingReplacesTheSuggestion(): void
    {
        $image = self::savedImage('harbour-at-night.jpg');
        $pair = [(int)$image->id, self::$primarySiteId];
        $suggestions = self::plugin()->getSuggestions();

        self::assertSame(1, $suggestions->queue([$pair, $pair]));
        self::assertSame(0, $suggestions->queue([$pair]));
        self::assertSame(SuggestionStatus::QUEUED, $suggestions->get(...$pair)?->status);
        self::assertCount(1, $this->pushed);

        $this->runJobs();
        $first = $suggestions->get(...$pair);
        self::assertSame(SuggestionStatus::PENDING, $first->status);

        self::assertSame(1, $suggestions->queue([$pair]));
        $this->runJobs();
        $second = $suggestions->get(...$pair);
        self::assertSame($first->id, $second->id, 'Regenerating replaces the suggestion rather than adding one.');
    }

    public function testGeneratingForEveryImageWantingOneWalksTheAudit(): void
    {
        $images = [];

        for ($i = 0; $i < 7; $i++) {
            $images[] = self::savedImage("walk-image-$i.jpg");
        }

        $good = self::savedImage('walk-good.jpg', 'A dog catching a frisbee');
        self::plugin()->getSuggestions()->queueForAllWanting();
        $jobs = $this->runJobs();

        self::assertGreaterThanOrEqual(2, $jobs);
        self::assertInstanceOf(GenerateAltTextJob::class, $this->pushed[0] ?? new GenerateAltTextJob());

        foreach ($images as $image) {
            self::assertSame(SuggestionStatus::PENDING, self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId)?->status);
        }

        self::assertNull(self::plugin()->getSuggestions()->get((int)$good->id, self::$primarySiteId));
    }

    public function testAJobThatFailsMarksItsImagesFailedNotGenerating(): void
    {
        $image = self::savedImage('IMG_9400.jpg');
        $pair = [(int)$image->id, self::$primarySiteId];
        $plugin = self::plugin();
        $plugin->getSettings()->aiEnabled = true;
        $plugin->getSuggestions()->queue([$pair]);
        $plugin->set('suggestionGenerator', static fn() => throw new \RuntimeException('boom'));
        $plugin->getAiProviders()->httpHandler = new MockHandler([new ConnectException('down', new Request('POST', 'http://localhost:11434/api/generate'))]);

        $this->runJobs();

        self::assertSame(SuggestionStatus::FAILED, $plugin->getSuggestions()->get(...$pair)?->status);
    }

    public function testAReservationWhoseJobDiedIsWantedAgain(): void
    {
        $image = self::savedImage('IMG_9500.jpg');
        $pair = [(int)$image->id, self::$primarySiteId];
        $suggestions = self::plugin()->getSuggestions();
        self::plugin()->getSettings()->aiEnabled = true;
        $suggestions->reserve([$pair]);
        $wanting = static fn(): array => array_column(self::plugin()->getAudits()->needingSuggestions(0, 1000), 'assetId');

        self::assertNotContains($pair[0], $wanting());

        \craft\helpers\Db::update(\Tahadudhiya\AltMonitor\records\SuggestionRecord::TABLE, ['dateUpdated' => '2000-01-01 00:00:00'], ['assetId' => $pair[0]], updateTimestamp: false);

        self::assertContains($pair[0], $wanting());
        self::assertSame([$pair], $suggestions->reserve([$pair]));
    }

    // Approval and editing

    public function testApprovingASuggestionSavesItAsTheAssetsAltText(): void
    {
        $image = self::savedImage('red-kite.jpg');
        $suggestion = self::plugin()->getSuggestions()->generate((int)$image->id, self::$primarySiteId);
        $user = $this->editor();

        $response = $this->runAction(SuggestionsController::class, 'suggestions', 'approve', ['id' => $suggestion?->id, 'alt' => 'A red kite gliding over green hills']);

        self::assertSame(200, $response?->getStatusCode());
        self::assertSame('A red kite gliding over green hills', self::altOf($image));
        self::assertSame(SuggestionStatus::APPROVED, self::plugin()->getSuggestions()->getById((int)$suggestion->id)?->status);
        $item = self::plugin()->getAudits()->item((int)$image->id, self::$primarySiteId, $user);
        self::assertSame('good', $item?->status->value);
        self::assertNotNull($item->dateAltSaved);

        // An approved suggestion can't be approved again.
        $again = $this->runAction(SuggestionsController::class, 'suggestions', 'approve', ['id' => $suggestion->id]);
        self::assertSame(400, $again?->getStatusCode());
    }

    public function testRejectingKeepsTheAssetUnchanged(): void
    {
        $image = self::savedImage('red-kite.jpg');
        $suggestion = self::plugin()->getSuggestions()->generate((int)$image->id, self::$primarySiteId);
        $this->editor();

        $this->runAction(SuggestionsController::class, 'suggestions', 'reject', ['id' => $suggestion?->id]);

        self::assertNull(self::altOf($image));
        self::assertSame(SuggestionStatus::REJECTED, self::plugin()->getSuggestions()->getById((int)$suggestion->id)?->status);
    }

    public function testBulkApprovalUpdatesOnlyTheSelectedImagesAndReportsEachFailure(): void
    {
        $suggestions = self::plugin()->getSuggestions();
        $one = self::savedImage('golden-retriever.jpg');
        $two = self::savedImage('city-skyline.jpg');
        $notSelected = self::savedImage('quiet-library.jpg');
        $ids = [];

        foreach ([$one, $two, $notSelected] as $image) {
            $ids[] = $suggestions->generate((int)$image->id, self::$primarySiteId)?->id;
        }

        $suggestions->reject((int)$ids[1], $this->editor());

        $response = $this->runAction(SuggestionsController::class, 'suggestions', 'bulk-approve', [
            'ids' => [(string)$ids[0], (string)$ids[1], '999999999'],
            'alt' => [$ids[0] => 'A golden retriever lying on grass'],
        ]);

        self::assertSame(200, $response?->getStatusCode());
        self::assertSame([(string)$ids[1], '999999999'], array_column($response->data['failures'] ?? [], 'key'));
        self::assertSame('A golden retriever lying on grass', self::altOf($one));
        self::assertNull(self::altOf($two));
        self::assertNull(self::altOf($notSelected));
    }

    public function testTheImageListSavesTypedAltTextAndApprovesPerSelectedRow(): void
    {
        $typed = self::savedImage('IMG_7000.jpg');
        $approved = self::savedImage('snowy-owl.jpg');
        $untouched = self::savedImage('IMG_7001.jpg');
        self::plugin()->getSuggestions()->generate((int)$approved->id, self::$primarySiteId);
        $this->editor();
        $key = static fn(Asset $asset): string => $asset->id . '-' . self::$primarySiteId;

        $save = $this->runAction(AssetsController::class, 'assets', 'bulk', [
            'save' => $key($typed),
            'alt' => [$key($typed) => '  A <b>wooden</b> jetty   at dawn ', $key($untouched) => 'Should not be saved'],
        ]);
        self::assertSame(200, $save?->getStatusCode());
        // Saved as plain, single-spaced text.
        self::assertSame('A wooden jetty at dawn', self::altOf($typed));
        self::assertNull(self::altOf($untouched));

        $approve = $this->runAction(AssetsController::class, 'assets', 'bulk', ['intent' => 'approve', 'selected' => [$key($approved), $key($untouched)]]);
        self::assertSame(200, $approve?->getStatusCode());
        self::assertSame('Snowy owl', self::altOf($approved));
        self::assertSame([$key($untouched)], array_column($approve->data['failures'] ?? [], 'key'));

        // Without Ollama, generating happens in the browser, so the server refuses to queue it.
        $inBrowser = $this->runAction(AssetsController::class, 'assets', 'bulk', ['intent' => 'generate', 'selected' => [$key($untouched)]]);
        self::assertSame(400, $inBrowser?->getStatusCode());

        self::plugin()->getSettings()->aiEnabled = true;
        $generate = $this->runAction(AssetsController::class, 'assets', 'bulk', ['intent' => 'generate', 'selected' => [$key($untouched)]]);
        self::assertSame(200, $generate?->getStatusCode());
        self::assertSame(SuggestionStatus::QUEUED, self::plugin()->getSuggestions()->get((int)$untouched->id, self::$primarySiteId)?->status);
    }

    public function testAltTextIsSavedPerSiteWhereItIsTranslatable(): void
    {
        $image = self::savedImage('market.jpg', 'A busy market', self::$translated);
        $other = (int)array_values(array_diff(Craft::$app->getSites()->getAllSiteIds(), [self::$primarySiteId]))[0];

        self::plugin()->getAltTextEditor()->save((int)$image->id, $other, 'Ein belebter Markt', $this->editor());

        self::assertSame('A busy market', self::altOf($image));
        self::assertSame('Ein belebter Markt', self::altOf($image, $other));
    }

    public function testOnlyExistingImagesTheUserMaySaveCanBeEdited(): void
    {
        $editor = self::plugin()->getAltTextEditor();
        $file = self::savedFile('notes.txt');
        $image = self::savedImage('IMG_8000.jpg');
        $user = $this->editor();
        $refusals = [];

        foreach ([[999999999, self::$primarySiteId], [(int)$image->id, 999999], [(int)$file->id, self::$primarySiteId]] as [$assetId, $siteId]) {
            try {
                $editor->save($assetId, $siteId, 'Anything', $user);
            } catch (AltTextException $exception) {
                $refusals[] = $exception->getMessage();
            }
        }

        self::assertSame(['There is no such image.', 'There is no such image.', 'Only images have alt text managed here.'], $refusals);

        $viewer = $this->signIn(false, ['accessCp', 'viewAssets:' . self::$shared->uid]);
        $this->expectException(AltTextException::class);
        $this->expectExceptionMessage('You may not edit this image.');
        $editor->save((int)$image->id, self::$primarySiteId, 'Anything', $viewer);
    }

    public function testTooLongAltTextIsRefused(): void
    {
        $image = self::savedImage('IMG_8001.jpg');
        $this->editor();

        $response = $this->runAction(AssetsController::class, 'assets', 'save', ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'alt' => str_repeat('a', 1001)]);

        self::assertSame(400, $response?->getStatusCode());
        self::assertNull(self::altOf($image));
    }

    public function testMarkingAnImageDecorativeMakesItsEmptyAltTextIntended(): void
    {
        $image = self::savedImage('IMG_8002.jpg');
        $user = $this->editor();

        $this->runAction(AssetsController::class, 'assets', 'decorative', ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'decorative' => '1']);
        self::assertSame('good', self::plugin()->getAudits()->item((int)$image->id, self::$primarySiteId, $user)?->status->value);

        // A rescan keeps the mark.
        self::plugin()->getScans()->start(ScanType::FULL);
        $this->runJobs();
        self::assertTrue(self::plugin()->getAudits()->item((int)$image->id, self::$primarySiteId, $user)->decorative);

        $this->runAction(AssetsController::class, 'assets', 'decorative', ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'decorative' => '']);
        self::assertSame('missing', self::plugin()->getAudits()->item((int)$image->id, self::$primarySiteId, $user)->status->value);
    }

    // Pages

    public function testTheImagePagesShowTheAuditFiltersAndEscapeContent(): void
    {
        $image = self::savedImage('xss.jpg', '<script>alert(1)</script>');
        $missing = self::savedImage('IMG_8100.jpg');
        self::savedEntry('A page using it', [self::IMAGES => [$missing->id]]);
        self::plugin()->getScans()->start(ScanType::FULL);
        $this->runJobs();
        self::plugin()->getSuggestions()->generate((int)$missing->id, self::$primarySiteId);
        $this->editor();

        $all = self::html($this->runAction(AssetsController::class, 'assets', 'index', [], method: 'GET', json: false));
        self::assertStringContainsString('xss.jpg', $all);
        self::assertStringNotContainsString('<script>alert(1)</script>', $all);
        self::assertStringContainsString('&lt;script&gt;', $all);

        $filtered = self::html($this->runAction(AssetsController::class, 'assets', 'index', ['status' => 'missing', 'search' => 'IMG_8100'], method: 'GET', json: false));
        self::assertStringContainsString('IMG_8100.jpg', $filtered);
        self::assertStringNotContainsString('xss.jpg', $filtered);
        self::assertStringContainsString('1 place', $filtered);

        $none = self::html($this->runAction(AssetsController::class, 'assets', 'index', ['search' => 'no-such-file'], method: 'GET', json: false));
        self::assertStringContainsString('No images match these filters.', $none);

        $view = self::html($this->runAction(AssetsController::class, 'assets', 'view', ['assetId' => $missing->id, 'siteId' => self::$primarySiteId], method: 'GET', json: false));
        self::assertStringContainsString('A page using it', $view);
        self::assertStringContainsString('data-describe-one', $view);

        $suggestions = self::html($this->runAction(SuggestionsController::class, 'suggestions', 'index', [], method: 'GET', json: false));
        self::assertStringContainsString('IMG_8100.jpg', $suggestions);
        self::assertStringContainsString('A page using it', $suggestions);
        self::assertStringContainsString('Rule-based', $suggestions);

        $bySource = self::html($this->runAction(SuggestionsController::class, 'suggestions', 'index', ['source' => 'ai'], method: 'GET', json: false));
        self::assertStringContainsString('No suggestions match these filters.', $bySource);
    }

    public function testMalformedFiltersAreRefused(): void
    {
        $this->editor();

        foreach ([['status' => 'excellent'], ['page' => '0'], ['inSite' => 'nowhere'], ['search' => ['array']]] as $params) {
            try {
                $this->runAction(AssetsController::class, 'assets', 'index', $params, method: 'GET', json: false);
                self::fail('Accepted ' . json_encode($params));
            } catch (\yii\web\BadRequestHttpException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // Describing in the browser

    public function testByDefaultTheServerDescribesNothingAndLeavesItToTheBrowser(): void
    {
        $image = self::savedImage('red-kite.jpg');
        self::plugin()->getSettings()->fallbackToRules = false;
        $this->editor();

        self::assertFalse(self::plugin()->getSuggestions()->generatesOnServer());
        // No file name suggestion: the image itself is what gets described.
        $suggestion = self::plugin()->getSuggestions()->generate((int)$image->id, self::$primarySiteId);
        self::assertSame(SuggestionStatus::FAILED, $suggestion?->status);
        self::assertNull($suggestion->suggestedAlt);

        foreach (['regenerate' => ['assetId' => $image->id, 'siteId' => self::$primarySiteId], 'generate-all' => []] as $action => $params) {
            $response = $this->runAction(SuggestionsController::class, 'suggestions', $action, $params);
            self::assertSame(400, $response?->getStatusCode(), $action);
            self::assertStringContainsString('in your browser', (string)$response->data['message']);
        }

        self::assertSame([], $this->pushed);
    }

    public function testTheBrowserIsServedTheImageAsAJpegEvenFromAVolumeWithoutUrls(): void
    {
        $image = self::savedImage('private-volume.png');
        self::assertNull(self::$shared->getFs()->hasUrls ? 'has urls' : null);
        $this->editor();

        $response = $this->runAction(SuggestionsController::class, 'suggestions', 'image', ['assetId' => (string)$image->id, 'siteId' => (string)self::$primarySiteId], method: 'GET', json: false);

        self::assertSame('image/jpeg', $response?->getHeaders()->get('Content-Type'));
        self::assertSame('private, no-store', $response->getHeaders()->get('Cache-Control'));
        self::assertSame("\xFF\xD8", substr((string)$response->content, 0, 2));
        self::assertNotFalse(imagecreatefromstring((string)$response->content));
    }

    public function testTheImageComesWithWhatItAlreadyHasSoDescribingAgainFindsSomethingNew(): void
    {
        $image = self::savedImage('known-ünïcode.png', 'Une jetée au coucher du soleil');
        $user = $this->editor();
        self::plugin()->getSuggestions()->storeDescription((int)$image->id, self::$primarySiteId, 'A pier at sunset', $user);
        $headers = $this->runAction(SuggestionsController::class, 'suggestions', 'image', ['assetId' => (string)$image->id, 'siteId' => (string)self::$primarySiteId], method: 'GET', json: false)?->getHeaders();

        // URL-encoded, so non-ASCII text survives as a header value.
        $known = json_decode(rawurldecode((string)$headers?->get('X-AltMonitor-Known')), true);
        self::assertSame(['Une jetée au coucher du soleil', 'A pier at sunset'], $known);
        self::assertSame('known-ünïcode.png', rawurldecode((string)$headers->get('X-AltMonitor-Name')));
        self::assertMatchesRegularExpression('/^[\x20-\x7E]*$/', (string)$headers->get('X-AltMonitor-Known'));
    }

    public function testTheImageIsOnlyServedToUsersWhoMayViewIt(): void
    {
        $image = self::savedImage('guarded.png');
        $file = self::savedFile('guarded.txt');
        $params = static fn($asset): array => ['assetId' => (string)$asset->id, 'siteId' => (string)self::$primarySiteId];

        $this->signIn(false, ['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW, AltMonitor::PERMISSION_GENERATE, 'viewAssets:' . self::$translated->uid]);
        foreach ([$params($image), $params($file), ['assetId' => '999999999', 'siteId' => (string)self::$primarySiteId]] as $query) {
            try {
                $this->runAction(SuggestionsController::class, 'suggestions', 'image', $query, method: 'GET', json: false);
                self::fail('Served ' . json_encode($query));
            } catch (\yii\web\NotFoundHttpException) {
                self::addToAssertionCount(1);
            }
        }

        $this->signIn(false, ['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW, ...self::assetPermissions()]);
        $this->expectException(\yii\web\ForbiddenHttpException::class);
        $this->runAction(SuggestionsController::class, 'suggestions', 'image', $params($image), method: 'GET', json: false);
    }

    public function testTheBrowserWalksImagesWantingADescriptionInVolumesItMayView(): void
    {
        $missing = self::savedImage('IMG_9100.jpg');
        $weak = self::savedImage('weak.jpg', 'Photo');
        $good = self::savedImage('fine.jpg', 'A pier at sunset');
        $hidden = self::savedImage('IMG_9101.jpg', null, self::$translated);
        $described = self::savedImage('IMG_9102.jpg');
        $user = $this->signIn(false, ['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW, AltMonitor::PERMISSION_GENERATE, 'viewAssets:' . self::$shared->uid, 'viewPeerAssets:' . self::$shared->uid, ...array_map(static fn($site): string => "editSite:$site->uid", Craft::$app->getSites()->getAllSites())]);
        self::plugin()->getSuggestions()->storeDescription((int)$described->id, self::$primarySiteId, 'A heron in a pond', $user);

        $ids = [];
        $after = '0';

        do {
            $images = $this->runAction(SuggestionsController::class, 'suggestions', 'next', ['after' => $after], method: 'GET')?->data['images'] ?? [];
            array_push($ids, ...array_column($images, 'assetId'));
            $after = $images ? (string)end($images)['auditId'] : $after;
        } while ($images);

        self::assertContains((int)$missing->id, $ids);
        self::assertContains((int)$weak->id, $ids);
        self::assertNotContains((int)$good->id, $ids);
        self::assertNotContains((int)$hidden->id, $ids);
        self::assertNotContains((int)$described->id, $ids);
    }

    public function testADescriptionFromTheBrowserIsCleanedAndStoredForReview(): void
    {
        $image = self::savedImage('IMG_9200.jpg');
        $this->editor();

        $response = $this->runAction(SuggestionsController::class, 'suggestions', 'store', ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'text' => "The image shows two cats laying on a <b>pink</b> blanket.\n"]);

        self::assertSame(200, $response?->getStatusCode());
        $suggestion = self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId);
        self::assertSame('Two cats laying on a pink blanket.', $suggestion?->suggestedAlt);
        self::assertSame(SuggestionSource::AI, $suggestion->source);
        self::assertSame(SuggestionStatus::PENDING, $suggestion->status);
        self::assertSame('Florence-2-base-ft (in the browser)', $suggestion->basis);
        self::assertTrue($suggestion->needsReview);
        // Still nothing on the asset until someone approves it.
        self::assertNull(self::altOf($image));

        foreach (['', '   ', 'IMG_9200.jpg', 'image', '12345'] as $useless) {
            $refused = $this->runAction(SuggestionsController::class, 'suggestions', 'store', ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'text' => $useless]);
            self::assertSame(400, $refused?->getStatusCode(), "Accepted “{$useless}”.");
        }

        self::assertSame('Two cats laying on a pink blanket.', self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId)?->suggestedAlt);
    }

    public function testADescriptionThatRepeatsTheAltTextIsNotSuggestedAndApprovingReportsTheNewStatus(): void
    {
        $image = self::savedImage('IMG_9250.jpg', 'Two cats on a pink blanket');
        $this->editor();
        $store = fn(string $text) => $this->runAction(SuggestionsController::class, 'suggestions', 'store', ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'text' => $text]);

        // Same words, other case and a full stop: nothing new to suggest.
        $same = $store('two cats on a pink  blanket.');
        self::assertSame(200, $same?->getStatusCode());
        self::assertNull($same->data['suggestion']);
        self::assertNull(self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId));

        $store('Two cats asleep on a sofa');
        $suggestion = self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId);
        self::assertSame(SuggestionStatus::PENDING, $suggestion?->status);

        $approved = $this->runAction(SuggestionsController::class, 'suggestions', 'approve', ['id' => (string)$suggestion->id]);
        self::assertSame('Two cats asleep on a sofa', self::altOf($image));
        self::assertSame('good', $approved?->data['status']['value'] ?? null);

        // A suggestion repeating the alt text it now has is not offered again.
        $item = self::plugin()->getAudits()->item((int)$image->id, self::$primarySiteId, Craft::$app->getUser()->getIdentity());
        self::assertFalse($item?->hasNewSuggestion());
    }

    public function testTheSiteFilterIgnoresTheSiteParameterCraftAddsToControlPanelUrls(): void
    {
        $handle = Craft::$app->getSites()->getPrimarySite()->handle;

        self::assertNull(\Tahadudhiya\AltMonitor\models\ListCriteria::fromParams(['site' => $handle])->siteId);
        self::assertSame(self::$primarySiteId, \Tahadudhiya\AltMonitor\models\ListCriteria::fromParams(['inSite' => $handle])->siteId);
        self::assertSame(['inSite' => $handle], \Tahadudhiya\AltMonitor\models\ListCriteria::fromParams(['inSite' => $handle, 'site' => $handle])->params());
    }

    public function testThePagesOfferDescribingInTheBrowser(): void
    {
        $image = self::savedImage('IMG_9300.jpg');
        self::plugin()->getScans()->start(ScanType::FULL);
        $this->runJobs();
        $this->editor();

        $dashboard = self::html($this->runAction(\Tahadudhiya\AltMonitor\controllers\DashboardController::class, 'dashboard', 'index', [], method: 'GET', json: false));
        $list = self::html($this->runAction(AssetsController::class, 'assets', 'index', [], method: 'GET', json: false));
        $view = self::html($this->runAction(AssetsController::class, 'assets', 'view', ['assetId' => $image->id, 'siteId' => self::$primarySiteId], method: 'GET', json: false));

        self::assertStringContainsString('data-describe-all', $dashboard);
        self::assertStringContainsString('data-describe-selected', $list);
        self::assertStringContainsString('data-describe-one="' . $image->id . '-' . self::$primarySiteId . '"', $view);

        foreach ([$dashboard, $list, $view] as $html) {
            preg_match('/data-config="([^"]+)"/', $html, $match);
            $config = json_decode(html_entity_decode($match[1] ?? ''), true);
            self::assertSame('onnx-community/Florence-2-base-ft', $config['model'] ?? null);
            self::assertStringEndsWith('/describe-worker.js', (string)parse_url((string)$config['worker'], PHP_URL_PATH));
            self::assertStringEndsWith('/vendor/transformers.min.js', (string)parse_url((string)$config['library'], PHP_URL_PATH));
        }

        // The published worker and library are the plugin's own files.
        $worker = (string)file_get_contents(Craft::getAlias('@webroot') . parse_url((string)$config['worker'], PHP_URL_PATH));
        self::assertStringContainsString('Florence2ForConditionalGeneration', $worker);
    }

    // Settings

    public function testSettingsAreValidatedAndTheConnectionTestReportsTheModel(): void
    {
        $this->signIn(true);
        Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;

        $invalid = $this->runAction(SettingsController::class, 'settings', 'save', ['settings' => ['aiEnabled' => '1', 'ollamaEndpoint' => 'http://169.254.169.254', 'scanBatchSize' => '']]);
        self::assertSame(400, $invalid?->getStatusCode());
        $settings = self::plugin()->getSettings();
        self::assertNotEmpty($settings->getErrors('scanBatchSize'));
        // AI can't be switched on or pointed anywhere from the control panel.
        self::assertFalse($settings->aiEnabled);
        self::assertSame('http://localhost:11434', $settings->ollamaEndpoint);
        $settings->clearErrors();
        $settings->scanBatchSize = 10;
        $settings->setAttributes(['ollamaEndpoint' => 'http://localhost:11434', 'ollamaModel' => 'qwen2.5vl:3b'], false);

        self::plugin()->getAiProviders()->httpHandler = new MockHandler([
            self::json(['models' => [['name' => 'llava:7b'], ['name' => 'qwen2.5vl:3b']]]),
            self::json(['capabilities' => ['completion', 'vision']]),
        ]);
        $ready = $this->runAction(SettingsController::class, 'settings', 'test-connection');
        self::assertSame([true, true], [$ready?->data['reachable'], $ready->data['modelAvailable']]);

        self::plugin()->getAiProviders()->httpHandler = new MockHandler([self::json(['models' => [['name' => 'llava:7b']]])]);
        $missing = $this->runAction(SettingsController::class, 'settings', 'test-connection');
        self::assertSame([true, false], [$missing?->data['reachable'], $missing->data['modelAvailable']]);
        self::assertStringContainsString('ollama pull qwen2.5vl:3b', $missing->data['message']);

        // AI was never turned on by testing it.
        self::assertFalse(self::plugin()->getSettings()->aiEnabled);

        $page = self::html($this->runAction(SettingsController::class, 'settings', 'index', [], method: 'GET', json: false));
        // The settings page points to the Image model page, which names the model.
        self::assertStringContainsString('alt-monitor/image-model', $page);
        self::assertStringContainsString('name="settings[scanBatchSize]"', $page);
        self::assertStringNotContainsString('settings[aiEnabled]', $page);
        self::assertStringNotContainsString('settings[ollama', $page);
        self::assertStringNotContainsString('data-altmonitor-test', $page);

        // Where Ollama is set up in the config file, the page says so and can test it.
        self::plugin()->getSettings()->aiEnabled = true;
        $ollama = self::html($this->runAction(SettingsController::class, 'settings', 'index', [], method: 'GET', json: false));
        self::assertStringContainsString('data-altmonitor-test', $ollama);
        self::assertStringContainsString('qwen2.5vl:3b', $ollama);
        self::assertStringNotContainsString('settings[allowedAiHosts]', $page);
    }
}
