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
use Tahadudhiya\AltMonitor\enums\AltIssue;
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
        self::assertStringContainsString('Can’t reach Ollama', (string)$suggestion->error);
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

    public function testARegenerateThatFailsKeepsTheLastSuggestion(): void
    {
        $image = self::savedImage('IMG_9300.jpg');
        $pair = [(int)$image->id, self::$primarySiteId];
        $suggestions = self::plugin()->getSuggestions();
        $this->mockOllama([
            self::json(['model' => 'qwen2.5vl:3b', 'response' => 'A red bicycle leaning on a brick wall.']),
            new ConnectException('down', new Request('POST', 'http://localhost:11434/api/generate')),
        ]);

        $suggestions->queue([$pair]);
        $this->runJobs();
        self::assertSame('A red bicycle leaning on a brick wall.', $suggestions->get(...$pair)?->suggestedAlt);

        // Ollama is down when it is made again: the suggestion waiting for review stays.
        self::assertSame(1, $suggestions->queue([$pair]));
        $this->runJobs();
        $kept = $suggestions->get(...$pair);
        self::assertSame(SuggestionStatus::PENDING, $kept->status);
        self::assertSame('A red bicycle leaning on a brick wall.', $kept->suggestedAlt);
        self::assertStringContainsString('the last one was kept', (string)$kept->error);
    }

    public function testARegenerateThatCrashesKeepsTheLastSuggestionToo(): void
    {
        $image = self::savedImage('IMG_9310.jpg');
        $pair = [(int)$image->id, self::$primarySiteId];
        $plugin = self::plugin();
        $suggestions = $plugin->getSuggestions();
        $this->mockOllama([
            self::json(['model' => 'qwen2.5vl:3b', 'response' => 'A green door in a stone wall.']),
            new ConnectException('down', new Request('POST', 'http://localhost:11434/api/generate')),
        ]);
        $suggestions->queue([$pair]);
        $this->runJobs();

        // Ollama is down, and the fallback then throws: the job's catch keeps it as well.
        $plugin->getSettings()->fallbackToRules = true;
        $plugin->set('suggestionGenerator', static fn() => throw new \RuntimeException('boom'));
        $suggestions->queue([$pair]);
        $this->runJobs();
        $kept = $suggestions->get(...$pair);

        self::assertSame(SuggestionStatus::PENDING, $kept?->status);
        self::assertSame('A green door in a stone wall.', $kept->suggestedAlt);
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

    public function testTheImageListExportsEveryFilteredImageAsCsv(): void
    {
        $missing = self::savedImage('export-missing.jpg');
        self::savedEntry('A page using it', [self::IMAGES => [$missing->id]]);
        $formula = self::savedImage('export-formula.jpg', '=HYPERLINK("https://example.com","Click")');
        $good = self::savedImage('export-good.jpg', 'A grey heron standing on a wooden post');
        self::plugin()->getScans()->start(ScanType::FULL);
        $this->runJobs();
        self::plugin()->getSuggestions()->generate((int)$missing->id, self::$primarySiteId);
        $this->editor();
        $export = fn(array $params): array => self::csv($this->runAction(AssetsController::class, 'assets', 'export', $params, method: 'GET', json: false));

        // The Missing filter: those images only, on every page, with what to write next to them.
        $csv = $export(['status' => 'missing', 'search' => 'export-']);
        self::assertTrue($csv['bom']);
        self::assertMatchesRegularExpression('/^alt-text-missing-\d{4}-\d{2}-\d{2}\.csv$/', $csv['filename']);
        [$headers, $row] = $csv['rows'] + [1 => []];
        self::assertCount(2, $csv['rows']);
        self::assertSame(['File name', 'Title', 'Volume', 'Site', 'Status', 'Issues', 'Current alt text', 'Decorative', 'Suggested alt text', 'Content usage', 'Image URL', 'Link in AltMonitor', 'Last checked', 'New alt text'], $headers);
        $cells = array_combine($headers, $row);
        self::assertSame($missing->filename, $cells['File name']);
        self::assertSame('Missing', $cells['Status']);
        self::assertSame('', $cells['Current alt text']);
        self::assertSame((string)self::plugin()->getSuggestions()->get((int)$missing->id, self::$primarySiteId)?->suggestedAlt, $cells['Suggested alt text']);
        self::assertNotSame('', $cells['Suggested alt text']);
        self::assertSame('Used in 1 place', $cells['Content usage']);
        self::assertStringEndsWith('alt-monitor/images/' . $missing->id . '/' . self::$primarySiteId, $cells['Link in AltMonitor']);
        self::assertSame('', $cells['New alt text']);

        // All statuses: every matching image, and alt text that looks like a formula stays text.
        $all = $export(['search' => 'export-']);
        self::assertMatchesRegularExpression('/^alt-text-all-/', $all['filename']);
        $byFile = array_column(array_slice($all['rows'], 1), null, 0);
        self::assertSame([$formula->filename, $good->filename, $missing->filename], array_keys($byFile));
        self::assertSame('\'=HYPERLINK("https://example.com","Click")', $byFile[$formula->filename][6]);
        self::assertSame('Good', $byFile[$good->filename][4]);

        // The list offers it with the same filters.
        $page = self::html($this->runAction(AssetsController::class, 'assets', 'index', ['status' => 'missing'], method: 'GET', json: false));
        self::assertStringContainsString('alt-monitor/images/export?status=missing', $page);

        // Nothing matching: only the headers.
        self::assertCount(1, $export(['search' => 'no-such-file'])['rows']);

        // A malformed filter is refused, as on the list.
        $this->expectException(\yii\web\BadRequestHttpException::class);
        $export(['status' => 'nope']);
    }

    public function testTheImageListSavesChangedRowsAndApprovesPerSelectedRow(): void
    {
        $typed = self::savedImage('IMG_7000.jpg');
        $approved = self::savedImage('snowy-owl.jpg');
        $untouched = self::savedImage('IMG_7001.jpg');
        // Shown with no alt text; someone else saved some since. Leaving the row alone must not
        // overwrite it.
        $savedElsewhere = self::savedImage('harbour-lights.jpg', 'Written by someone else');
        self::plugin()->getSuggestions()->generate((int)$approved->id, self::$primarySiteId);
        $this->editor();
        $key = static fn(Asset $asset): string => $asset->id . '-' . self::$primarySiteId;

        // “Save changes” saves the rows whose text differs from what they showed, selected or not.
        $save = $this->runAction(AssetsController::class, 'assets', 'bulk', [
            'intent' => 'save',
            'selected' => [$key($untouched)],
            'alt' => [$key($typed) => '  A <b>wooden</b> jetty   at dawn ', $key($untouched) => '', $key($savedElsewhere) => ''],
            'original' => [$key($typed) => '', $key($untouched) => '', $key($savedElsewhere) => ''],
        ]);
        self::assertSame(200, $save?->getStatusCode());
        self::assertStringContainsString('1 image', $save->data['message'] ?? '');
        // Saved as plain, single-spaced text.
        self::assertSame('A wooden jetty at dawn', self::altOf($typed));
        self::assertNull(self::altOf($untouched));
        self::assertSame('Written by someone else', self::altOf($savedElsewhere));

        $nothing = $this->runAction(AssetsController::class, 'assets', 'bulk', [
            'intent' => 'save',
            'alt' => [$key($untouched) => ''],
            'original' => [$key($untouched) => ''],
        ]);
        self::assertSame(200, $nothing?->getStatusCode());
        self::assertSame('Nothing changed, so nothing was saved.', $nothing->data['message'] ?? null);

        // Each row fails on its own: too long text is reported, the other row still saved.
        $partly = $this->runAction(AssetsController::class, 'assets', 'bulk', [
            'intent' => 'save',
            'alt' => [$key($typed) => str_repeat('a', 1001), $key($untouched) => 'A rowing boat on a lake'],
            'original' => [$key($typed) => 'A wooden jetty at dawn', $key($untouched) => ''],
        ]);
        self::assertSame(200, $partly?->getStatusCode());
        self::assertSame([$key($typed)], array_column($partly->data['failures'] ?? [], 'key'));
        self::assertSame('A wooden jetty at dawn', self::altOf($typed));
        self::assertSame('A rowing boat on a lake', self::altOf($untouched));
        $untouched->alt = null;
        Craft::$app->getElements()->saveElement($untouched);

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

    public function testSelectedImagesCanBeMarkedDecorativeTogetherKeepingTheirAltText(): void
    {
        $divider = self::savedImage('divider-gold.png');
        $icon = self::savedImage('icon-phone.png');
        $described = self::savedImage('IMG_7004.jpg', 'A phone icon');
        AltMonitor::getInstance()->getScans()->start(ScanType::FULL);
        $this->runJobs();
        $user = $this->editor();
        $key = static fn(Asset $asset): string => $asset->id . '-' . self::$primarySiteId;
        $item = static fn(Asset $asset) => self::plugin()->getAudits()->item((int)$asset->id, self::$primarySiteId, $user);
        self::assertSame('missing', $item($icon)?->status->value);

        $response = $this->runAction(AssetsController::class, 'assets', 'bulk', [
            'intent' => 'decorative',
            'selected' => [$key($divider), $key($icon), $key($described), '999999999-' . self::$primarySiteId],
        ]);

        // Done for the three images; the one that doesn't exist is reported on its own.
        self::assertSame(200, $response?->getStatusCode());
        self::assertSame(['999999999-' . self::$primarySiteId], array_column($response->data['failures'] ?? [], 'key'));
        self::assertStringContainsString('Marked 3 images as decorative.', (string)$response->data['message']);
        self::assertStringContainsString('1 of them still has alt text', (string)$response->data['message']);

        foreach ([$divider, $icon, $described] as $asset) {
            self::assertTrue($item($asset)?->decorative);
        }

        // Empty alt text is now intended; written alt text is kept and still graded by itself.
        self::assertSame('good', $item($icon)?->status->value);
        self::assertSame('good', $item($divider)?->status->value);
        self::assertSame('A phone icon', self::altOf($described));

        // Like saving, it needs the edit permission.
        $this->signIn(false, ['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW, ...self::assetPermissions()]);
        $this->expectException(\yii\web\ForbiddenHttpException::class);
        $this->runAction(AssetsController::class, 'assets', 'bulk', ['intent' => 'decorative', 'selected' => [$key($icon)]]);
    }

    public function testARowSavedInPlaceGetsItsNewStatusIssuesAndWhetherASuggestionStillWaits(): void
    {
        $named = self::savedImage('IMG_7002.jpg');
        $owl = self::savedImage('snowy-owl.jpg');
        self::plugin()->getSuggestions()->generate((int)$owl->id, self::$primarySiteId);
        $this->editor();
        $save = fn(Asset $asset, string $alt) => $this->runAction(AssetsController::class, 'assets', 'save', ['assetId' => (string)$asset->id, 'siteId' => (string)self::$primarySiteId, 'alt' => $alt]);

        // The file name as alt text: saved, and the row learns it is weak and why.
        $weak = $save($named, ' IMG_7002.jpg ');
        self::assertSame(200, $weak?->getStatusCode());
        self::assertSame('IMG_7002.jpg', $weak->data['alt'] ?? null);
        self::assertSame('weak', $weak->data['status']['value'] ?? null);
        self::assertContains('The alt text is the file name.', array_map('strval', $weak->data['issues'] ?? []));
        self::assertFalse($weak->data['hasSuggestion'] ?? null);

        // Other text than the suggestion: the suggestion still waits.
        $other = $save($owl, 'A white owl on a fence post in the snow');
        self::assertSame('good', $other?->data['status']['value'] ?? null);
        self::assertSame([], $other->data['issues'] ?? null);
        self::assertTrue($other->data['hasSuggestion'] ?? null);

        // The suggestion's own text: it counts as used, so the row drops it.
        $used = $save($owl, 'Snowy owl');
        self::assertSame(200, $used?->getStatusCode());
        self::assertFalse($used->data['hasSuggestion'] ?? null);
        self::assertSame(SuggestionStatus::APPROVED, self::plugin()->getSuggestions()->get((int)$owl->id, self::$primarySiteId)?->status);
    }

    public function testTypedAltTextIsGradedByTheScanRulesWithoutBeingSaved(): void
    {
        $image = self::savedImage('IMG_7003.jpg', 'A heron on a post');
        $this->editor();
        $check = fn(string $alt) => $this->runAction(AssetsController::class, 'assets', 'check', ['assetId' => (string)$image->id, 'siteId' => (string)self::$primarySiteId, 'alt' => $alt]);
        $grade = static fn($response): array => [$response?->data['status']['value'] ?? null, $response?->data['issues'] ?? null];

        self::assertSame(['weak', [AltIssue::FILENAME->label(), AltIssue::FILE_EXTENSION->label()]], $grade($check('IMG_7003.jpg')));
        self::assertSame(['review', [AltIssue::REDUNDANT_PREFIX->label()]], $grade($check('Image of a heron')));
        self::assertSame(['review', [AltIssue::TOO_LONG->label()]], $grade($check(implode(' ', array_map(static fn(int $i): string => "heron$i", range(1, 40))) . ' standing still')));
        self::assertSame(['missing', [AltIssue::EMPTY->label()]], $grade($check('  ')));
        self::assertSame(['good', []], $grade($check('A grey heron on a <b>wooden</b> post')));

        // Too long to save at all: said, not graded.
        $tooLong = $check(str_repeat('x', 1001));
        self::assertNull($tooLong?->data['status']);
        self::assertCount(1, $tooLong->data['issues']);

        // Nothing changed: the asset and its audit are as they were.
        self::assertSame('A heron on a post', self::altOf($image));
        self::assertSame('good', self::plugin()->getAudits()->item((int)$image->id, self::$primarySiteId, null)?->status->value);
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

        self::assertSame(['There is no such image.', 'There is no such image.', 'Only images are managed here.'], $refusals);

        $viewer = $this->signIn(false, ['accessCp', 'viewAssets:' . self::$shared->uid]);
        $this->expectException(AltTextException::class);
        $this->expectExceptionMessage('You can’t edit this image.');
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
        // One way to save: “Save changes”, with what each field started with for the server.
        self::assertSame(1, substr_count($filtered, 'name="intent" value="save"'));
        self::assertStringNotContainsString('name="save"', $filtered);
        self::assertStringContainsString('name="original[' . $missing->id . '-' . self::$primarySiteId . ']"', $filtered);

        $none = self::html($this->runAction(AssetsController::class, 'assets', 'index', ['search' => 'no-such-file'], method: 'GET', json: false));
        self::assertStringContainsString('No images match these filters.', $none);

        $view = self::html($this->runAction(AssetsController::class, 'assets', 'view', ['assetId' => $missing->id, 'siteId' => self::$primarySiteId], method: 'GET', json: false));
        self::assertStringContainsString('A page using it', $view);
        self::assertStringContainsString('data-describe-one', $view);

        $suggestions = self::html($this->runAction(SuggestionsController::class, 'suggestions', 'index', [], method: 'GET', json: false));
        self::assertStringContainsString('IMG_8100.jpg', $suggestions);
        self::assertStringContainsString('A page using it', $suggestions);
        self::assertStringContainsString('From image details', $suggestions);

        $bySource = self::html($this->runAction(SuggestionsController::class, 'suggestions', 'index', ['source' => 'ai'], method: 'GET', json: false));
        self::assertStringContainsString('No suggestions match these filters.', $bySource);
    }

    public function testTheSuggestionsPageOffersFastReviewOnlyToApprovers(): void
    {
        $image = self::savedImage('snowy-owl.jpg');
        self::plugin()->getSuggestions()->generate((int)$image->id, self::$primarySiteId);
        $this->editor();
        $page = fn() => self::html($this->runAction(SuggestionsController::class, 'suggestions', 'index', [], method: 'GET', json: false));

        $html = $page();
        // The list is wrapped so it can be loaded again in place; the shortcuts are said once.
        self::assertStringContainsString('data-altmonitor-review', $html);
        self::assertStringContainsString('<kbd>Esc</kbd> rejects it.', $html);
        self::assertStringContainsString('aria-keyshortcuts="Control+Enter Meta+Enter"', $html);
        self::assertStringContainsString('aria-describedby="altmonitor-shortcuts"', $html);
        self::assertStringContainsString('data-approve-all', $html);

        $this->signIn(false, ['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW, ...self::assetPermissions()]);
        $html = $page();
        self::assertStringContainsString('<p>Snowy owl</p>', $html);
        self::assertStringNotContainsString('data-approve-all', $html);
        self::assertStringNotContainsString('altmonitor-shortcuts', $html);
        self::assertStringNotContainsString('aria-keyshortcuts', $html);
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

    public function testLongAltTextIsLeftOutOfTheHeaderSoItStaysSmall(): void
    {
        // 600 Chinese characters would be over 5 KB URL-encoded: more than nginx's default buffer.
        $image = self::savedImage('long-alt.png', str_repeat('港口的夜景', 120));
        $user = $this->editor();
        self::plugin()->getSuggestions()->storeDescription((int)$image->id, self::$primarySiteId, 'A harbour at night', $user);
        $header = (string)$this->runAction(SuggestionsController::class, 'suggestions', 'image', ['assetId' => (string)$image->id, 'siteId' => (string)self::$primarySiteId], method: 'GET', json: false)?->getHeaders()->get('X-AltMonitor-Known');

        self::assertSame(['A harbour at night'], json_decode(rawurldecode($header), true));
        self::assertLessThanOrEqual(3000, strlen($header));
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
        $totals = [];

        do {
            $data = $this->runAction(SuggestionsController::class, 'suggestions', 'next', ['after' => $after], method: 'GET')->data ?? [];
            $images = $data['images'] ?? [];
            $totals[] = $data['total'] ?? null;
            array_push($ids, ...array_column($images, 'assetId'));
            $after = $images ? (string)end($images)['auditId'] : $after;
        } while ($images);

        // The first answer says how many there are in all (for progress and the time left).
        self::assertSame(count($ids), $totals[0]);
        self::assertSame([null], array_unique(array_slice($totals, 1)));

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

    public function testForASiteInAnotherLanguageTheBrowserTranslatesAndEnglishSuggestionsAreMarked(): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $language = $site->language;
        $site->language = 'de-CH';

        try {
            $image = self::savedImage('IMG_9220.jpg');
            $this->editor();
            $store = fn(string $text, int $translated) => $this->runAction(SuggestionsController::class, 'suggestions', 'store', ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'text' => $text, 'translated' => $translated]);
            $suggestion = fn() => self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId);
            $view = fn() => self::html($this->runAction(AssetsController::class, 'assets', 'view', ['assetId' => $image->id, 'siteId' => self::$primarySiteId], method: 'GET', json: false));

            // The browser is told which model translates into the site's language…
            self::assertSame(['model' => 'Xenova/opus-mt-en-de', 'language' => 'de', 'megabytes' => 112], self::plugin()->getAiProviders()->browserConfig()['translations'][self::$primarySiteId] ?? null);
            // …which the image model page offers as a download of its own, apart from the image
            // model's, with its own agreement.
            $model = self::html($this->runAction(\Tahadudhiya\AltMonitor\controllers\ModelController::class, 'model', 'index', ['translation' => 'de'], method: 'GET', json: false));
            self::assertStringContainsString('Download (304 MB)', $model);
            self::assertMatchesRegularExpression('/<tr data-translation="Xenova\/opus-mt-en-de" data-language="de" data-wanted/', $model);
            self::assertStringContainsString('data-translation-agree', $model);

            // Translated while describing: recorded as such, nothing to mark.
            $store('Zwei Katzen auf einer rosa Decke', 1);
            self::assertSame('Florence-2-base-ft + opus-mt-en-de (in the browser)', $suggestion()?->basis);
            self::assertFalse($suggestion()->needsTranslation());
            self::assertStringNotContainsString('data-translation-note', $view());

            // Described in English (this browser hasn't the translation model): marked, with
            // Translate, wherever it is shown.
            $store('Two cats on a pink blanket', 0);
            $english = $suggestion();
            self::assertSame('Florence-2-base-ft (in the browser)', $english?->basis);
            self::assertTrue($english->needsTranslation());
            self::assertStringContainsString('This suggestion is in English. Translate it before using it.', $view());
            self::assertStringContainsString('data-translate-suggestion="' . $english->id . '"', $view());
            // In the control panel's language.
            self::assertStringContainsString('Translate into ' . \Tahadudhiya\AltMonitor\ai\Translations::languageName('de'), $view());
            self::assertStringContainsString('data-translate-suggestion', self::plugin()->getAssetsUi()->sidebarHtml($image, false));
            $review = self::html($this->runAction(SuggestionsController::class, 'suggestions', 'index', [], method: 'GET', json: false));
            self::assertStringContainsString('data-translate-suggestion="' . $english->id . '"', $review);
            self::assertStringContainsString('data-altmonitor-describer', $review);

            // Translate replaces the text alone: the same suggestion, still waiting for review.
            $translate = fn(string $text) => $this->runAction(SuggestionsController::class, 'suggestions', 'translate', ['id' => (string)$english->id, 'text' => $text]);
            self::assertSame(400, $translate('image')?->getStatusCode());
            self::assertSame(200, $translate('Zwei Katzen auf einer <b>rosa</b> Decke')?->getStatusCode());
            $translated = $suggestion();
            self::assertSame($english->id, $translated?->id);
            self::assertSame('Zwei Katzen auf einer rosa Decke', $translated->suggestedAlt);
            self::assertSame('Florence-2-base-ft + opus-mt-en-de (in the browser)', $translated->basis);
            self::assertSame(SuggestionStatus::PENDING, $translated->status);
            self::assertFalse($translated->needsTranslation());
            self::assertStringNotContainsString('data-translate-suggestion', $view());
            // Once translated, there is nothing more to translate.
            self::assertSame(400, $translate('Noch einmal')?->getStatusCode());

            // A language without a translation model: nothing to translate with, a claimed
            // translation isn't recorded, and there is no Translate.
            $site->language = 'pt-BR';
            self::assertArrayNotHasKey(self::$primarySiteId, self::plugin()->getAiProviders()->browserConfig()['translations']);
            $store('Two cats asleep on a pink blanket', 1);
            self::assertSame('Florence-2-base-ft (in the browser)', $suggestion()?->basis);
            self::assertTrue($suggestion()->needsTranslation());
            self::assertStringNotContainsString('data-translate-suggestion', $view());
            self::assertSame(400, $this->runAction(SuggestionsController::class, 'suggestions', 'translate', ['id' => (string)$suggestion()->id, 'text' => 'Dois gatos'])?->getStatusCode());

            // In English, nothing is marked.
            $site->language = 'en-GB';
            self::assertFalse($suggestion()->needsTranslation());
        } finally {
            $site->language = $language;
        }
    }

    public function testWithOllamaEnglishSuggestionsCanStillBeTranslatedInTheBrowser(): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $language = $site->language;
        $site->language = 'fr-FR';

        try {
            $image = self::savedImage('IMG_9230.jpg');
            $this->editor();
            $this->runAction(SuggestionsController::class, 'suggestions', 'store', ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'text' => 'Two cats on a pink blanket']);
            \craft\helpers\Db::update(\Tahadudhiya\AltMonitor\records\SuggestionRecord::TABLE, ['basis' => 'qwen2.5vl:3b'], ['assetId' => $image->id, 'siteId' => self::$primarySiteId]);
            // Ollama describes images on the server, in English.
            $this->mockOllama([]);
            $suggestion = self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId);
            self::assertTrue($suggestion?->needsTranslation());

            // No describing in the browser, but Translate, with the popup it reports into.
            $view = self::html($this->runAction(AssetsController::class, 'assets', 'view', ['assetId' => $image->id, 'siteId' => self::$primarySiteId], method: 'GET', json: false));
            self::assertStringNotContainsString('data-describe-one', $view);
            self::assertStringContainsString('altmonitor-regenerate-form', $view);
            self::assertStringContainsString('data-translate-suggestion="' . $suggestion->id . '"', $view);
            self::assertStringContainsString('data-altmonitor-describer', $view);
            self::assertStringContainsString('data-translate-suggestion', self::plugin()->getAssetsUi()->sidebarHtml($image, false));
            self::assertStringContainsString('data-translate-suggestion', self::html($this->runAction(SuggestionsController::class, 'suggestions', 'index', [], method: 'GET', json: false)));

            // The image model page offers only the translation models.
            $model = self::html($this->runAction(\Tahadudhiya\AltMonitor\controllers\ModelController::class, 'model', 'index', [], method: 'GET', json: false));
            self::assertStringNotContainsString('data-consent', $model);
            self::assertStringContainsString('data-translation="Xenova/opus-mt-en-fr"', $model);

            self::assertSame(200, $this->runAction(SuggestionsController::class, 'suggestions', 'translate', ['id' => (string)$suggestion->id, 'text' => 'Deux chats sur une couverture rose'])?->getStatusCode());
            $translated = self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId);
            self::assertSame('Deux chats sur une couverture rose', $translated?->suggestedAlt);
            self::assertSame('qwen2.5vl:3b + opus-mt-en-fr (in the browser)', $translated->basis);
            self::assertFalse($translated->needsTranslation());
        } finally {
            $site->language = $language;
        }
    }

    public function testOnlySuggestionsWaitingForReviewAreMarkedForTranslating(): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $language = $site->language;
        $site->language = 'fr-FR';
        $make = fn(SuggestionStatus $status) => new \Tahadudhiya\AltMonitor\models\Suggestion(1, 1, self::$primarySiteId, 'Two cats', SuggestionSource::AI, $status, true, 'Florence-2-base-ft (in the browser)', null, null);

        try {
            self::assertTrue($make(SuggestionStatus::PENDING)->needsTranslation());
            self::assertSame('fr', $make(SuggestionStatus::PENDING)->translation()['language'] ?? null);

            // Already saved to the image, or turned down: nothing to translate any more.
            foreach ([SuggestionStatus::APPROVED, SuggestionStatus::REJECTED, SuggestionStatus::FAILED] as $status) {
                self::assertFalse($make($status)->needsTranslation(), $status->value);
                self::assertNull($make($status)->translation(), $status->value);
            }
        } finally {
            $site->language = $language;
        }
    }

    public function testAltTextWithALineBreakIsNotSeenAsChangedOnTheImagesList(): void
    {
        // Craft's Alt Text field is a textarea; a text box on the list shows line breaks as spaces.
        $image = self::savedImage('IMG_9260.jpg', "Line one\nLine two");
        self::plugin()->getScans()->start(ScanType::FULL);
        $this->runJobs();
        $this->editor();
        $key = $image->id . '-' . self::$primarySiteId;

        $list = self::html($this->runAction(AssetsController::class, 'assets', 'index', [], method: 'GET', json: false));
        self::assertMatchesRegularExpression('/name="alt\[' . $key . '\]" value="Line one Line two"/', $list);
        self::assertMatchesRegularExpression('/name="original\[' . $key . '\]" value="Line one Line two"/', $list);

        // Saving the list as it was (without JavaScript) leaves the image's alt text untouched.
        $saved = $this->runAction(AssetsController::class, 'assets', 'bulk', ['intent' => 'save', 'alt' => [$key => 'Line one Line two'], 'original' => [$key => 'Line one Line two']]);
        self::assertSame('Nothing changed, so nothing was saved.', $saved?->data['message'] ?? null);
        self::assertSame("Line one\nLine two", self::altOf($image));
    }

    public function testEachSitesSuggestionLanguageCanBeChosenInTheSettings(): void
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $language = $site->language;
        $site->language = 'fr-FR';
        $general = Craft::$app->getConfig()->getGeneral();
        $allowAdminChanges = $general->allowAdminChanges;
        $general->allowAdminChanges = true;
        $settings = self::plugin()->getSettings();
        $save = fn(array $languages) => $this->runAction(SettingsController::class, 'settings', 'save', ['settings' => ['siteLanguages' => $languages]]);

        try {
            $image = self::savedImage('IMG_9240.jpg');
            $this->signIn(true);

            // Each site has a choice, the site's own language by default.
            $page = self::html($this->runAction(SettingsController::class, 'settings', 'index', [], method: 'GET', json: false));
            self::assertStringContainsString('name="settings[siteLanguages][' . $site->uid . ']"', $page);
            // Two of Craft's tabs: Scanning & rules open, Languages hidden until chosen.
            self::assertStringContainsString('<div id="altmonitor-tab-checks" class="altmonitor-settings">', $page);
            self::assertStringContainsString('<div id="altmonitor-tab-languages" class="altmonitor-settings hidden">', $page);
            // The rules are explained, one card per status, with the built-in generic words.
            self::assertSame(4, substr_count($page, 'class="altmonitor-rule"'));
            self::assertStringContainsString('<code>placeholder</code>', $page);
            self::assertStringContainsString('longer than 250', $page);
            self::assertStringContainsString('Same as the site (' . \Tahadudhiya\AltMonitor\ai\Translations::languageName('fr') . ')', $page);

            // English for the French site: nothing to translate, nothing marked. Unknown sites
            // aren't kept.
            $save([$site->uid => 'en', 'no-such-site' => 'de']);
            self::assertSame([$site->uid => 'en'], $settings->siteLanguages);
            self::assertArrayNotHasKey(self::$primarySiteId, self::plugin()->getAiProviders()->browserConfig()['translations']);
            $this->runAction(SuggestionsController::class, 'suggestions', 'store', ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'text' => 'Two cats on a pink blanket']);
            self::assertFalse(self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId)?->needsTranslation());

            // Choosing the site's own language is the default, so nothing is stored.
            $save([$site->uid => 'fr']);
            self::assertSame([], $settings->siteLanguages);
            self::assertSame('Xenova/opus-mt-en-fr', self::plugin()->getAiProviders()->browserConfig()['translations'][self::$primarySiteId]['model'] ?? null);

            // German instead: the English suggestion now wants translating into German.
            $save([$site->uid => 'de']);
            self::assertSame('Xenova/opus-mt-en-de', self::plugin()->getAiProviders()->browserConfig()['translations'][self::$primarySiteId]['model'] ?? null);
            $suggestion = self::plugin()->getSuggestions()->get((int)$image->id, self::$primarySiteId);
            self::assertTrue($suggestion?->needsTranslation());
            self::assertSame('de', $suggestion->translation()['language'] ?? null);

            // Only listed languages.
            self::assertSame(400, $save([$site->uid => 'xx'])?->getStatusCode());
        } finally {
            $site->language = $language;
            $general->allowAdminChanges = $allowAdminChanges;
            $settings->siteLanguages = [];
        }
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

    // Craft's own asset screens

    public function testTheAssetEditorSidebarShowsTheStatusAndTheSuggestionToUse(): void
    {
        $image = self::savedImage('IMG_9400.jpg');
        $user = $this->editor();
        $ui = self::plugin()->getAssetsUi();

        // Not described yet: the status, why it matters, and describing it.
        $html = $ui->sidebarHtml($image, false);
        self::assertStringContainsString('data-altmonitor-sidebar', $html);
        self::assertStringContainsString('data-alt-status="missing"', $html);
        self::assertStringContainsString('Screen readers can’t tell people what this image shows.', $html);
        self::assertStringContainsString('data-sidebar-describe', $html);
        self::assertStringNotContainsString('data-sidebar-use', $html);
        // Its describer is the box's own, not one AltMonitor's pages would pick up.
        self::assertStringContainsString('data-altmonitor-sidebar-describer', $html);
        self::assertStringNotContainsString('data-altmonitor-describer', $html);
        self::assertStringNotContainsString('data-review', $html);
        self::assertStringContainsString('alt-monitor/images/' . $image->id . '/' . self::$primarySiteId, $html);

        self::plugin()->getSuggestions()->storeDescription((int)$image->id, self::$primarySiteId, 'A heron & a "fish" in a pond', $user);
        $html = $ui->sidebarHtml($image, false);

        self::assertStringContainsString('data-sidebar-use=', $html);
        self::assertStringContainsString('data-sidebar-dismiss=', $html);
        self::assertStringContainsString('data-can-approve="1"', $html);
        self::assertStringContainsString('A heron &amp; a &quot;fish&quot; in a pond', $html);
        self::assertStringNotContainsString('a "fish"', $html);
        self::assertStringContainsString('Describe again', $html);

        // Inside the editor's form, no button may submit it.
        preg_match_all('/<button\b[^>]*>/', $html, $buttons);
        self::assertNotEmpty($buttons[0]);

        foreach ($buttons[0] as $button) {
            self::assertStringContainsString('type="button"', $button);
        }

        // Read-only editor: the status and suggestion, nothing to use.
        self::assertStringNotContainsString('data-sidebar-use=', $ui->sidebarHtml($image, true));

        // Only images get a box.
        self::assertSame('', $ui->sidebarHtml(self::savedFile('notes.txt'), false));
    }

    public function testTheSidebarSaysWhenAnImageIsDecorativeOrNotCheckedYet(): void
    {
        $image = self::savedImage('divider.png');
        $this->editor();
        $ui = self::plugin()->getAssetsUi();

        self::plugin()->getAudits()->setDecorative((int)$image->id, self::$primarySiteId, true);
        self::plugin()->getAssetScanner()->auditAsset($image);
        self::assertStringContainsString('Screen readers skip it, so no alt text is needed.', $ui->sidebarHtml($image, false));

        self::plugin()->getAudits()->forget((int)$image->id);
        $html = $ui->sidebarHtml($image, false);
        self::assertStringContainsString('Not checked yet.', $html);
        self::assertStringNotContainsString('data-sidebar-describe', $html);
    }

    public function testTheSidebarRendersAgainThroughItsEndpoint(): void
    {
        $image = self::savedImage('IMG_9410.jpg');
        $this->editor();

        $response = $this->runAction(AssetsController::class, 'assets', 'sidebar', ['assetId' => (string)$image->id, 'siteId' => (string)self::$primarySiteId], method: 'GET');

        self::assertStringContainsString('data-asset-id="' . $image->id . '"', (string)($response?->data['html'] ?? ''));
        self::assertStringNotContainsString('data-static', (string)($response?->data['html'] ?? ''));

        // Fetched again from a read-only editor, it stays read-only: no Use.
        self::plugin()->getSuggestions()->storeDescription((int)$image->id, self::$primarySiteId, 'A heron by a pond', Craft::$app->getUser()->getIdentity());
        $static = $this->runAction(AssetsController::class, 'assets', 'sidebar', ['assetId' => (string)$image->id, 'siteId' => (string)self::$primarySiteId, 'static' => '1'], method: 'GET');
        self::assertStringContainsString('data-static', (string)($static?->data['html'] ?? ''));
        self::assertStringNotContainsString('data-sidebar-use=', (string)($static?->data['html'] ?? ''));
        $editable = $this->runAction(AssetsController::class, 'assets', 'sidebar', ['assetId' => (string)$image->id, 'siteId' => (string)self::$primarySiteId], method: 'GET');
        self::assertStringContainsString('data-sidebar-use=', (string)($editable?->data['html'] ?? ''));
    }

    public function testSavingAnAssetWithTheSuggestedTextMarksTheSuggestionUsed(): void
    {
        $user = $this->editor();
        $suggestions = self::plugin()->getSuggestions();
        $used = self::savedImage('IMG_9420.jpg');
        $other = self::savedImage('IMG_9421.jpg');
        $suggestions->storeDescription((int)$used->id, self::$primarySiteId, 'A heron fishing in a pond', $user);
        $suggestions->storeDescription((int)$other->id, self::$primarySiteId, 'A fox in the snow', $user);

        // As after “Use” in the asset editor, then saving the asset the usual way.
        $fresh = static fn(Asset $asset): Asset => Asset::find()->id($asset->id)->siteId(self::$primarySiteId)->status(null)->one();
        $edited = $fresh($used);
        $edited->alt = 'A heron fishing in a pond';
        self::assertTrue(Craft::$app->getElements()->saveElement($edited), implode(' ', $edited->getFirstErrors()));
        $edited = $fresh($other);
        $edited->alt = 'A red fox';
        self::assertTrue(Craft::$app->getElements()->saveElement($edited), implode(' ', $edited->getFirstErrors()));

        self::assertSame(SuggestionStatus::APPROVED, $suggestions->get((int)$used->id, self::$primarySiteId)?->status);
        self::assertSame(SuggestionStatus::PENDING, $suggestions->get((int)$other->id, self::$primarySiteId)?->status);
    }

    public function testTheAssetsIndexShowsAndFiltersByAltStatus(): void
    {
        $missing = self::savedImage('IMG_9430.jpg');
        $good = self::savedImage('IMG_9431.jpg', 'A heron fishing in a pond');
        $unchecked = self::savedImage('IMG_9432.jpg');
        $file = self::savedFile('notes.txt');
        self::plugin()->getAudits()->forget((int)$unchecked->id);
        $this->editor();

        self::assertArrayHasKey('altMonitorStatus', Craft::$app->getElementSources()->getAvailableTableAttributes(Asset::class));

        // One page of the index: statuses read together, one query for all of them.
        $ids = [(int)$missing->id, (int)$good->id, (int)$unchecked->id, (int)$file->id];
        $page = Asset::find()->id($ids)->siteId(self::$primarySiteId)->status(null)->indexBy('id')->all();
        $ui = self::plugin()->getAssetsUi();
        $ui->reset();

        self::assertStringContainsString('Missing', $page[$missing->id]->getAttributeHtml('altMonitorStatus'));
        self::assertStringContainsString('Good', $page[$good->id]->getAttributeHtml('altMonitorStatus'));
        self::assertStringContainsString('Not checked', $page[$unchecked->id]->getAttributeHtml('altMonitorStatus'));
        self::assertSame('', $page[$file->id]->getAttributeHtml('altMonitorStatus'));

        $filter = function(array $values, string $operator = 'in') use ($ids): array {
            $rule = new \Tahadudhiya\AltMonitor\conditions\AltStatusConditionRule();
            $rule->operator = $operator;
            $rule->setValues($values);
            $query = Asset::find()->id($ids)->siteId(self::$primarySiteId)->status(null);
            $rule->modifyQuery($query);
            $found = array_map('intval', $query->ids());
            sort($found);

            // Matching one asset in memory agrees with the query.
            foreach (Asset::find()->id($ids)->siteId(self::$primarySiteId)->status(null)->all() as $asset) {
                self::assertSame(in_array((int)$asset->id, $found, true), $rule->matchElement($asset), "$asset->filename for " . implode(',', $values));
            }

            return $found;
        };

        self::assertSame([(int)$missing->id], $filter(['missing']));
        self::assertSame([(int)$missing->id, (int)$good->id], $filter(['missing', 'good']));
        self::assertSame([(int)$unchecked->id], $filter(['notChecked']));
        self::assertSame([(int)$good->id, (int)$unchecked->id, (int)$file->id], $filter(['missing'], 'ni'));

        // The rule is offered in asset conditions (the Assets index filters, custom sources).
        $condition = Craft::$app->getConditions()->createCondition(['class' => \craft\elements\conditions\assets\AssetCondition::class, 'elementType' => Asset::class]);
        $types = array_map(static fn($rule): string => $rule::class, $condition->getSelectableConditionRules());
        self::assertContains(\Tahadudhiya\AltMonitor\conditions\AltStatusConditionRule::class, $types);
    }

    public function testInAnotherSiteSharedAltTextIsReadFromThePrimarySitesAudit(): void
    {
        $other = (int)array_values(array_diff(Craft::$app->getSites()->getAllSiteIds(), [self::$primarySiteId]))[0];
        $shared = self::savedImage('IMG_9440.jpg');
        $translated = self::savedImage('IMG_9441.jpg', 'A heron fishing in a pond', self::$translated);
        self::plugin()->getAltTextEditor()->save((int)$translated->id, $other, '', $this->editor());

        $inOther = Asset::find()->id([$shared->id, $translated->id])->siteId($other)->status(null)->indexBy('id')->all();
        $statuses = self::plugin()->getAudits()->statusesFor(array_values($inOther));

        // The shared volume is audited once, in the primary site; the translated one per site.
        self::assertSame('missing', $statuses[$shared->id . '-' . $other]['status']->value ?? null);
        self::assertSame('missing', $statuses[$translated->id . '-' . $other]['status']->value ?? null);
        self::assertSame('good', self::plugin()->getAudits()->statusesFor([$translated])[$translated->id . '-' . self::$primarySiteId]['status']->value ?? null);

        $rule = new \Tahadudhiya\AltMonitor\conditions\AltStatusConditionRule();
        $rule->setValues(['missing']);
        $query = Asset::find()->id([$shared->id, $translated->id])->siteId($other)->status(null);
        $rule->modifyQuery($query);
        $found = array_map('intval', $query->ids());
        sort($found);
        self::assertSame([(int)$shared->id, (int)$translated->id], $found);
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
