<?php

namespace Tahadudhiya\AltMonitor\Tests\security;

use Craft;
use craft\elements\Asset;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as Psr7Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\controllers\AssetsController;
use Tahadudhiya\AltMonitor\controllers\DashboardController;
use Tahadudhiya\AltMonitor\controllers\ModelController;
use Tahadudhiya\AltMonitor\controllers\ScanController;
use Tahadudhiya\AltMonitor\controllers\SettingsController;
use Tahadudhiya\AltMonitor\controllers\SuggestionsController;
use Tahadudhiya\AltMonitor\enums\ScanType;
use Tahadudhiya\AltMonitor\Tests\_support\AltMonitorFixture;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;

/**
 * AltMonitor's controllers as a privileged boundary: every action's permission, CSRF, request
 * methods, malformed input, volume permissions, and where the AI connection may point.
 */
class ControllerSecurityTest extends TestCase
{
    use AltMonitorFixture;

    private const VIEW = ['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW];

    /**
     * Each state-changing action, and the permission it needs beyond viewing.
     *
     * @return iterable<string, array{class-string, string, string, string}>
     */
    public static function protectedActions(): iterable
    {
        yield 'start a scan' => [ScanController::class, 'scan', 'start', AltMonitor::PERMISSION_RUN_SCANS];
        yield 'save alt text' => [AssetsController::class, 'assets', 'save', AltMonitor::PERMISSION_EDIT_ALT];
        yield 'mark decorative' => [AssetsController::class, 'assets', 'decorative', AltMonitor::PERMISSION_EDIT_ALT];
        yield 'approve' => [SuggestionsController::class, 'suggestions', 'approve', AltMonitor::PERMISSION_APPROVE];
        yield 'reject' => [SuggestionsController::class, 'suggestions', 'reject', AltMonitor::PERMISSION_APPROVE];
        yield 'bulk approve' => [SuggestionsController::class, 'suggestions', 'bulk-approve', AltMonitor::PERMISSION_APPROVE];
        yield 'regenerate' => [SuggestionsController::class, 'suggestions', 'regenerate', AltMonitor::PERMISSION_GENERATE];
        yield 'generate all' => [SuggestionsController::class, 'suggestions', 'generate-all', AltMonitor::PERMISSION_GENERATE];
        yield 'save settings' => [SettingsController::class, 'settings', 'save', AltMonitor::PERMISSION_MANAGE_SETTINGS];
        yield 'test connection' => [SettingsController::class, 'settings', 'test-connection', AltMonitor::PERMISSION_MANAGE_SETTINGS];
    }

    /**
     * @param class-string<\craft\web\Controller> $class
     */
    #[DataProvider('protectedActions')]
    public function testEveryActionRequiresItsPermission(string $class, string $id, string $action, string $permission): void
    {
        $image = self::savedImage('IMG_0100.jpg');
        $this->signIn(false, [...self::VIEW, ...self::assetPermissions()]);

        try {
            $this->runAction($class, $id, $action, ['type' => 'full', 'assetId' => $image->id, 'siteId' => self::$primarySiteId, 'alt' => 'x', 'id' => '1', 'ids' => ['1']]);
            self::fail("$action ran without $permission.");
        } catch (ForbiddenHttpException) {
            self::assertNull(Asset::find()->id($image->id)->siteId(self::$primarySiteId)->one()?->alt);
        }
    }

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function pages(): iterable
    {
        yield 'dashboard' => [DashboardController::class, 'dashboard', 'index'];
        yield 'images' => [AssetsController::class, 'assets', 'index'];
        yield 'suggestions' => [SuggestionsController::class, 'suggestions', 'index'];
        yield 'settings' => [SettingsController::class, 'settings', 'index'];
        yield 'image model' => [ModelController::class, 'model', 'index'];
    }

    /**
     * @param class-string<\craft\web\Controller> $class
     */
    #[DataProvider('pages')]
    public function testPagesNeedTheViewPermission(string $class, string $id, string $action): void
    {
        $this->signIn(false, ['accessCp', 'accessPlugin-alt-monitor']);

        $this->expectException(ForbiddenHttpException::class);
        $this->runAction($class, $id, $action, [], method: 'GET', json: false);
    }

    public function testChangesNeedACsrfTokenAndAPostRequest(): void
    {
        $image = self::savedImage('IMG_0101.jpg');
        $user = $this->signIn(true);
        $params = ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'alt' => 'Forged'];

        try {
            $this->runAction(AssetsController::class, 'assets', 'save', $params, $user, withCsrf: false);
            self::fail('Saved without a CSRF token.');
        } catch (BadRequestHttpException) {
        }

        try {
            $this->runAction(AssetsController::class, 'assets', 'save', $params, $user, method: 'GET');
            self::fail('Saved through a GET request.');
        } catch (HttpException $exception) {
            self::assertContains($exception->statusCode, [400, 405]);
        }

        self::assertNull(Asset::find()->id($image->id)->siteId(self::$primarySiteId)->one()?->alt);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function malformedRequests(): iterable
    {
        yield 'non-numeric asset ID' => ['save', ['assetId' => '1 OR 1=1', 'siteId' => '1', 'alt' => 'x']];
        yield 'negative site ID' => ['save', ['assetId' => '1', 'siteId' => '-1', 'alt' => 'x']];
        yield 'alt text as an array' => ['save', ['assetId' => '1', 'siteId' => '1', 'alt' => ['x']]];
        yield 'malformed row key' => ['bulk', ['intent' => 'save', 'selected' => ['1-1; DROP TABLE']]];
        yield 'unknown bulk intent' => ['bulk', ['intent' => 'delete', 'selected' => ['1-1']]];
        yield 'too many rows' => ['bulk', ['intent' => 'save', 'selected' => array_map(static fn(int $i): string => "$i-1", range(1, 51))]];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('malformedRequests')]
    public function testMalformedInputIsRefused(string $action, array $params): void
    {
        $this->signIn(true);

        $this->expectException(BadRequestHttpException::class);
        $this->runAction(AssetsController::class, 'assets', $action, $params);
    }

    public function testOnlyImagesInVolumesTheUserMayViewAreShown(): void
    {
        $visible = self::savedImage('visible.jpg');
        $hidden = self::savedImage('hidden.jpg', null, self::$translated);
        AltMonitor::getInstance()->getScans()->start(ScanType::FULL);
        $this->runJobs();
        $sites = array_map(static fn($site): string => "editSite:$site->uid", Craft::$app->getSites()->getAllSites());
        // Without viewPeerAssets, other people's uploads stay hidden, as everywhere in Craft.
        $this->signIn(false, [...self::VIEW, ...$sites, 'viewAssets:' . self::$shared->uid]);
        self::assertStringNotContainsString('visible.jpg', self::html($this->runAction(AssetsController::class, 'assets', 'index', [], method: 'GET', json: false)));

        $this->signIn(false, [...self::VIEW, AltMonitor::PERMISSION_EDIT_ALT, ...$sites, 'viewAssets:' . self::$shared->uid, 'viewPeerAssets:' . self::$shared->uid, 'saveAssets:' . self::$shared->uid, 'savePeerAssets:' . self::$shared->uid]);

        $html = self::html($this->runAction(AssetsController::class, 'assets', 'index', [], method: 'GET', json: false));
        self::assertStringContainsString('visible.jpg', $html);
        self::assertStringNotContainsString('hidden.jpg', $html);

        try {
            $this->runAction(AssetsController::class, 'assets', 'view', ['assetId' => $hidden->id, 'siteId' => self::$primarySiteId], method: 'GET', json: false);
            self::fail('An image in a volume the user may not view was shown.');
        } catch (\yii\web\NotFoundHttpException) {
        }

        // Nor can it be changed: Craft's own asset permissions decide.
        $response = $this->runAction(AssetsController::class, 'assets', 'save', ['assetId' => $hidden->id, 'siteId' => self::$primarySiteId, 'alt' => 'Changed']);
        self::assertSame(400, $response?->getStatusCode());
        self::assertNull(Asset::find()->id($hidden->id)->siteId(self::$primarySiteId)->one()?->alt);
        self::assertSame(200, $this->runAction(AssetsController::class, 'assets', 'save', ['assetId' => $visible->id, 'siteId' => self::$primarySiteId, 'alt' => 'A visible thing'])?->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenEndpoints(): iterable
    {
        yield 'cloud metadata' => ['http://169.254.169.254'];
        yield 'a host not on the allowlist' => ['https://ollama.example.com'];
        yield 'credentials in the URL' => ['http://user:secret@localhost:11434'];
        yield 'a path' => ['http://localhost:11434/api/../../etc'];
        yield 'another scheme' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://localhost:11434'];
    }

    #[DataProvider('forbiddenEndpoints')]
    public function testTheConnectionTestNeverContactsAForbiddenEndpoint(string $endpoint): void
    {
        $this->signIn(true);
        $mock = new MockHandler([new Psr7Response(200, [], '{"models":[]}')]);
        $plugin = AltMonitor::getInstance();
        $plugin->getAiProviders()->httpHandler = $mock;
        $settings = $plugin->getSettings();
        $settings->ollamaEndpoint = $endpoint;

        try {
            $response = $this->runAction(SettingsController::class, 'settings', 'test-connection');
        } finally {
            $settings->ollamaEndpoint = 'http://localhost:11434';
        }

        self::assertFalse($response?->data['reachable']);
        self::assertCount(1, $mock, 'A request was sent.');
        self::assertStringNotContainsString('secret', (string)$response->data['message']);
    }

    public function testNothingAboutAiCanBeChangedFromTheControlPanel(): void
    {
        $this->signIn(true);
        Craft::$app->getConfig()->getGeneral()->allowAdminChanges = true;
        $settings = AltMonitor::getInstance()->getSettings();
        $before = $settings->getAttributes();

        $this->runAction(SettingsController::class, 'settings', 'save', ['settings' => [
            'allowedAiHosts' => ['evil.example.com'],
            'aiEnabled' => '1',
            'ollamaEndpoint' => 'http://evil.example.com',
            'ollamaModel' => 'llava:7b',
            'browserModel' => 'evil/model',
            'browserModelHost' => 'https://evil.example.com',
            'fallbackToRules' => '1',
            'scanBatchSize' => '50',
        ]]);

        $after = $settings->getAttributes();
        self::assertSame(50, $after['scanBatchSize']);
        unset($before['scanBatchSize'], $after['scanBatchSize']);
        self::assertSame($before, $after);
        self::assertArrayNotHasKey('allowedAiHosts', $settings->toArray());
        $settings->scanBatchSize = 10;
    }

    public function testDescribingInTheBrowserNeedsTheGeneratePermissionAndACsrfToken(): void
    {
        $image = self::savedImage('IMG_0200.jpg');
        $params = ['assetId' => $image->id, 'siteId' => self::$primarySiteId, 'text' => 'A forged description'];

        $this->signIn(false, [...self::VIEW, ...self::assetPermissions()]);

        foreach ([['store', $params, 'POST'], ['next', [], 'GET']] as [$action, $query, $method]) {
            try {
                $this->runAction(SuggestionsController::class, 'suggestions', $action, $query, method: $method);
                self::fail("$action ran without the generate permission.");
            } catch (ForbiddenHttpException) {
                self::addToAssertionCount(1);
            }
        }

        $this->signIn(false, [...self::VIEW, AltMonitor::PERMISSION_GENERATE, ...self::assetPermissions()]);

        try {
            $this->runAction(SuggestionsController::class, 'suggestions', 'store', $params, withCsrf: false);
            self::fail('Stored without a CSRF token.');
        } catch (BadRequestHttpException) {
        }

        self::assertNull(AltMonitor::getInstance()->getSuggestions()->get((int)$image->id, self::$primarySiteId));
    }

    public function testTheImageModelPageExplainsTheDownloadAndOnlyLeadsBackIntoAltMonitor(): void
    {
        $this->signIn(false, self::VIEW);

        try {
            $this->runAction(ModelController::class, 'model', 'index', [], method: 'GET', json: false);
            self::fail('The image model page opened without the generate permission.');
        } catch (ForbiddenHttpException) {
        }

        $this->signIn(false, [...self::VIEW, AltMonitor::PERMISSION_GENERATE]);
        $page = fn(array $query): string => self::html($this->runAction(ModelController::class, 'model', 'index', $query, method: 'GET', json: false));
        $html = $page([]);

        // What is downloaded, from whom, what they see, where it is kept; asked before downloading.
        foreach (['huggingface.co', 'cdn.jsdelivr.net', 'about 304 MB', 'IP address', 'Your privacy (GDPR)', 'Remove from this browser', 'https://huggingface.co/privacy'] as $text) {
            self::assertStringContainsString($text, $html);
        }

        self::assertMatchesRegularExpression('/<input type="checkbox"[^>]*data-agree/', $html);
        self::assertMatchesRegularExpression('/<button[^>]*data-download disabled/', $html);
        self::assertStringNotContainsString('data-return', $html);

        $back = \craft\helpers\UrlHelper::cpUrl('alt-monitor/images', ['status' => 'missing']);
        self::assertStringContainsString('data-return="' . htmlspecialchars($back) . '"', $page(['return' => $back]));

        $base = strtok(\craft\helpers\UrlHelper::cpUrl('alt-monitor'), '?');

        foreach (['https://evil.example/admin/alt-monitor', $base . '-evil', 'javascript:alert(1)', '//evil.example', $base . "/x\n"] as $elsewhere) {
            self::assertStringNotContainsString('data-return', $page(['return' => $elsewhere]), $elsewhere);
        }

        self::assertStringEndsWith('alt-monitor/image-model', strtok(AltMonitor::getInstance()->getAiProviders()->browserConfig()['setupUrl'], '?'));
    }

    public function testSettingsCannotBeSavedWhereAdminChangesAreOff(): void
    {
        $this->signIn(true);
        $general = Craft::$app->getConfig()->getGeneral();
        $allowed = $general->allowAdminChanges;
        $general->allowAdminChanges = false;

        try {
            $this->expectException(ForbiddenHttpException::class);
            $this->runAction(SettingsController::class, 'settings', 'save', ['settings' => ['ollamaModel' => 'llava:7b']]);
        } finally {
            $general->allowAdminChanges = $allowed;
        }
    }

    public function testGeneratingAndRejectingOnlyReachImagesTheUserMayView(): void
    {
        $visible = self::savedImage('IMG_0300.jpg');
        $hidden = self::savedImage('hidden-red-door.jpg', null, self::$translated);
        $plugin = AltMonitor::getInstance();
        $plugin->getSettings()->fallbackToRules = true;
        $hiddenSuggestion = $plugin->getSuggestions()->generate((int)$hidden->id, self::$primarySiteId);
        $plugin->getSettings()->aiEnabled = true;
        $sites = array_map(static fn($site): string => "editSite:$site->uid", Craft::$app->getSites()->getAllSites());
        $this->signIn(false, [...self::VIEW, AltMonitor::PERMISSION_GENERATE, AltMonitor::PERMISSION_APPROVE, ...$sites, 'viewAssets:' . self::$shared->uid, 'viewPeerAssets:' . self::$shared->uid]);

        $response = $this->runAction(AssetsController::class, 'assets', 'bulk', ['intent' => 'generate', 'selected' => [$visible->id . '-' . self::$primarySiteId, $hidden->id . '-' . self::$primarySiteId, '999999999-' . self::$primarySiteId]]);

        self::assertSame([$hidden->id . '-' . self::$primarySiteId, '999999999-' . self::$primarySiteId], array_column($response?->data['failures'] ?? [], 'key'));
        self::assertSame('queued', $plugin->getSuggestions()->get((int)$visible->id, self::$primarySiteId)?->status->value);
        self::assertSame('pending', $plugin->getSuggestions()->get((int)$hidden->id, self::$primarySiteId)?->status->value);

        $reject = $this->runAction(SuggestionsController::class, 'suggestions', 'reject', ['id' => (string)$hiddenSuggestion?->id]);
        self::assertSame(400, $reject?->getStatusCode());
        self::assertSame('pending', $plugin->getSuggestions()->get((int)$hidden->id, self::$primarySiteId)?->status->value);

        // The server's walk covers only the volumes of whoever started it.
        $this->runAction(SuggestionsController::class, 'suggestions', 'generate-all');
        $walk = end($this->pushed);
        self::assertInstanceOf(\Tahadudhiya\AltMonitor\jobs\GenerateAltTextJob::class, $walk);
        self::assertTrue($walk->walk);
        self::assertSame([(int)self::$shared->id], $walk->volumeIds);
    }

    public function testAUserWhoMayViewNoVolumeIsListedNoImages(): void
    {
        self::savedImage('IMG_0400.jpg');
        $this->signIn(false, [...self::VIEW, AltMonitor::PERMISSION_GENERATE]);

        self::assertSame([], $this->runAction(SuggestionsController::class, 'suggestions', 'next', ['after' => '0'], method: 'GET')?->data['images']);
    }

    public function testWhereAnImageIsUsedHidesContentTheUserMayNotView(): void
    {
        $image = self::savedImage('IMG_0500.jpg');
        self::savedEntry('Board meeting minutes', [self::IMAGES => [$image->id]]);
        AltMonitor::getInstance()->getScans()->start(ScanType::USAGE);
        $this->runJobs();
        $viewer = $this->signIn(false, [...self::VIEW, ...self::assetPermissions()]);

        $references = AltMonitor::getInstance()->getContentUsage()->references((int)$image->id, user: $viewer);

        self::assertCount(1, $references);
        self::assertSame('Content you may not view', $references[0]->label);
        self::assertNull($references[0]->cpEditUrl);
    }
}
