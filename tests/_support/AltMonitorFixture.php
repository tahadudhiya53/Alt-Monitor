<?php

namespace Tahadudhiya\AltMonitor\Tests\_support;

use Craft;
use craft\base\Field;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Assets as AssetsField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Volume;
use craft\queue\Queue;
use PHPUnit\Framework\Assert;
use Tahadudhiya\AltMonitor\AltMonitor;
use yii\base\Event;
use yii\db\Transaction;
use yii\queue\JobInterface;
use yii\queue\PushEvent;
use yii\web\Response;

/**
 * Real Craft content for AltMonitor's integration tests: two volumes (alt text shared by every
 * site, and alt text per site), a caption field on assets, a section whose entries relate images
 * through an Assets field, a Matrix field and a plain text field holding reference tags, and a
 * global set relating an image.
 *
 * Everything is created inside one transaction that is rolled back afterwards; each test runs in
 * a savepoint of its own; project config changes are never written to YAML; files go to a
 * temporary directory that is removed. Jobs pushed to the queue are caught and run in the test.
 */
trait AltMonitorFixture
{
    protected const IMAGES = 'altMonitorImages';
    protected const BODY = 'altMonitorBody';
    protected const BLOCKS = 'altMonitorBlocks';
    protected const BLOCK_TYPE = 'altMonitorBlock';
    protected const CAPTION = 'altMonitorCaption';

    private static Transaction $transaction;
    protected static string $root;
    protected static Volume $shared;
    protected static Volume $translated;
    protected static Section $section;
    protected static EntryType $entryType;
    protected static GlobalSet $globals;
    protected static int $primarySiteId;

    /** @var array<string, mixed> Settings as they were before the test. */
    private static array $savedSettings = [];

    private ?Transaction $savepoint = null;

    /** @var list<JobInterface> */
    protected array $pushed = [];

    /** @var array<string, object> */
    private array $consoleComponents = [];

    public static function setUpBeforeClass(): void
    {
        if (!AltMonitor::getInstance() instanceof AltMonitor) {
            Assert::fail('AltMonitor must be installed and enabled in the host project.');
        }

        ProjectConfigSandbox::open();
        self::$transaction = Craft::$app->getDb()->beginTransaction();
        self::$primarySiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        self::$root = sys_get_temp_dir() . '/alt-monitor-test-' . bin2hex(random_bytes(6));

        $fields = Craft::$app->getFields();
        $caption = $fields->createField(['type' => PlainText::class, 'name' => 'AltMonitor caption', 'handle' => self::CAPTION]);
        Assert::assertTrue($fields->saveField($caption), Json::encode($caption->getErrors()));

        self::$shared = self::createVolume('altMonitorTestShared', Field::TRANSLATION_METHOD_NONE, [new CustomField($caption)]);
        self::$translated = self::createVolume('altMonitorTestTranslated', Field::TRANSLATION_METHOD_SITE);

        $images = $fields->createField(['type' => AssetsField::class, 'name' => 'AltMonitor images', 'handle' => self::IMAGES]);
        Assert::assertTrue($fields->saveField($images), Json::encode($images->getErrors()));
        $body = $fields->createField(['type' => PlainText::class, 'name' => 'AltMonitor body', 'handle' => self::BODY, 'multiline' => true]);
        Assert::assertTrue($fields->saveField($body), Json::encode($body->getErrors()));

        $blockType = new EntryType(['name' => 'AltMonitor block', 'handle' => self::BLOCK_TYPE, 'hasTitleField' => false, 'titleFormat' => 'Block']);
        $blockType->setFieldLayout(self::layout(Entry::class, [new CustomField($images)]));
        Assert::assertTrue(Craft::$app->getEntries()->saveEntryType($blockType), Json::encode($blockType->getErrors()));
        $blocks = $fields->createField(['type' => Matrix::class, 'name' => 'AltMonitor blocks', 'handle' => self::BLOCKS, 'entryTypes' => [$blockType]]);
        Assert::assertTrue($fields->saveField($blocks), Json::encode($blocks->getErrors()));

        self::$entryType = new EntryType(['name' => 'AltMonitor page', 'handle' => 'altMonitorPage']);
        self::$entryType->setFieldLayout(self::layout(Entry::class, [new EntryTitleField(), new CustomField($images), new CustomField($body), new CustomField($blocks)]));
        Assert::assertTrue(Craft::$app->getEntries()->saveEntryType(self::$entryType), Json::encode(self::$entryType->getErrors()));

        $siteSettings = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new Section_SiteSettings(['siteId' => $site->id, 'hasUrls' => false]);
        }

        self::$section = new Section(['name' => 'AltMonitor pages', 'handle' => 'altMonitorPages', 'type' => Section::TYPE_CHANNEL, 'siteSettings' => $siteSettings]);
        self::$section->setEntryTypes([self::$entryType]);
        Assert::assertTrue(Craft::$app->getEntries()->saveSection(self::$section), Json::encode(self::$section->getErrors()));

        self::$globals = new GlobalSet(['name' => 'AltMonitor globals', 'handle' => 'altMonitorGlobals']);
        self::$globals->setFieldLayout(self::layout(GlobalSet::class, [new CustomField($images)]));
        Assert::assertTrue(Craft::$app->getGlobals()->saveSet(self::$globals), Json::encode(self::$globals->getErrors()));

        $settings = AltMonitor::getInstance()->getSettings();
        self::$savedSettings = $settings->getAttributes();
        $settings->contextFieldHandles = [self::CAPTION];
        $settings->aiEnabled = false;
        $settings->scanBatchSize = 10;
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$transaction) && self::$transaction->getIsActive()) {
            self::$transaction->rollBack();
        }

        AltMonitor::getInstance()->getSettings()->setAttributes(self::$savedSettings, false);
        ProjectConfigSandbox::close();

        // Craft's services remember the rolled-back volumes, fields, section and global set.
        $components = Craft::$app->getComponents();

        foreach (['fields', 'entries', 'volumes', 'fs', 'globals', 'elements'] as $id) {
            Craft::$app->set($id, $components[$id]);
        }

        if (isset(self::$root) && is_dir(self::$root)) {
            FileHelper::removeDirectory(self::$root);
        }
    }

    protected function setUp(): void
    {
        $this->savepoint = Craft::$app->getDb()->beginTransaction();
        $this->pushed = [];
        Event::on(Queue::class, Queue::EVENT_AFTER_PUSH, [$this, 'catchJob']);

        // Services are rebuilt per test, so settings a test changes reach them.
        $plugin = AltMonitor::getInstance();
        $plugin->set('analyzer', AltMonitor::config()['components']['analyzer']);
        $plugin->set('suggestionGenerator', AltMonitor::config()['components']['suggestionGenerator']);
        $plugin->getAiProviders()->httpHandler = null;
    }

    protected function tearDown(): void
    {
        Event::off(Queue::class, Queue::EVENT_AFTER_PUSH, [$this, 'catchJob']);
        $this->restoreConsoleRequest();
        Craft::$app->getUser()->setIdentity(null);
        AltMonitor::getInstance()->getSettings()->setAttributes(['aiEnabled' => false, 'fallbackToRules' => false, 'auditOnSave' => true, 'scanUsageAfterFullScan' => true, 'suggestAfterScan' => false, 'requireAltToPublish' => false, 'siteLanguages' => [], 'additionalGenericTerms' => []], false);

        if ($this->savepoint?->getIsActive()) {
            $this->savepoint->rollBack();
        }
    }

    public function catchJob(PushEvent $event): void
    {
        $this->pushed[] = $event->job;
    }

    /**
     * Runs every job pushed, and every job those push, as a queue worker would, in order.
     *
     * @return int How many jobs ran.
     */
    protected function runJobs(int $limit = 500): int
    {
        $ran = 0;

        while ($this->pushed !== []) {
            Assert::assertLessThan($limit, $ran, 'The jobs never stopped queueing more.');
            $job = array_shift($this->pushed);
            $job->execute(Craft::$app->getQueue());
            $ran++;
        }

        return $ran;
    }

    // Content

    /**
     * @param list<\craft\base\FieldLayoutElement> $elements
     */
    private static function layout(string $type, array $elements): FieldLayout
    {
        $layout = new FieldLayout(['type' => $type]);
        $layout->setTabs([['name' => 'Content', 'elements' => $elements]]);

        return $layout;
    }

    /**
     * @param list<CustomField> $fields
     */
    private static function createVolume(string $handle, string $altTranslationMethod, array $fields = []): Volume
    {
        $path = self::$root . '/' . $handle;
        FileHelper::createDirectory($path);
        $fs = new Local(['name' => $handle, 'handle' => $handle, 'path' => $path, 'hasUrls' => false]);
        Assert::assertTrue(Craft::$app->getFs()->saveFilesystem($fs), Json::encode($fs->getErrors()));

        $volume = new Volume(['name' => $handle, 'handle' => $handle, 'altTranslationMethod' => $altTranslationMethod]);
        $volume->setFsHandle($handle);
        $volume->setFieldLayout(self::layout(Asset::class, $fields));
        Assert::assertTrue(Craft::$app->getVolumes()->saveVolume($volume), Json::encode($volume->getErrors()));

        return $volume;
    }

    /**
     * A real PNG, saved as an asset in the primary site.
     *
     * @param array<string, mixed> $fields
     */
    protected static function savedImage(string $filename, ?string $alt = null, ?Volume $volume = null, array $fields = []): Asset
    {
        $temp = tempnam(sys_get_temp_dir(), 'altmonitor') . '.png';
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int)imagecolorallocate($image, 30, 120, 200));
        imagepng($image, $temp);

        return self::savedAsset($volume ?? self::$shared, $filename, $temp, $alt, $fields);
    }

    protected static function savedFile(string $filename): Asset
    {
        $temp = tempnam(sys_get_temp_dir(), 'altmonitor');
        file_put_contents($temp, 'Not an image.');

        return self::savedAsset(self::$shared, $filename, $temp, null);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function savedAsset(Volume $volume, string $filename, string $temp, ?string $alt, array $fields = []): Asset
    {
        $asset = new Asset();
        $asset->tempFilePath = $temp;
        $asset->setFilename($filename);
        $asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volume->id)?->id;
        $asset->volumeId = $volume->id;
        $asset->siteId = self::$primarySiteId;
        $asset->alt = $alt;
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        foreach ($fields as $handle => $value) {
            $asset->setFieldValue($handle, $value);
        }

        Assert::assertTrue(Craft::$app->getElements()->saveElement($asset), Json::encode($asset->getErrors()));

        return $asset;
    }

    /**
     * @param array<string, mixed> $values
     */
    protected static function savedEntry(string $title, array $values = []): Entry
    {
        $entry = new Entry(['sectionId' => self::$section->id, 'typeId' => self::$entryType->id, 'siteId' => self::$primarySiteId, 'title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title))]);

        foreach ($values as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }

        Assert::assertTrue(Craft::$app->getElements()->saveElement($entry), Json::encode($entry->getErrors()));

        return $entry;
    }

    /**
     * A Matrix block in an entry, relating the given images.
     *
     * @param list<int> $assetIds
     */
    protected static function savedBlock(Entry $owner, array $assetIds): Entry
    {
        $block = new Entry([
            'typeId' => (int)Craft::$app->getEntries()->getEntryTypeByHandle(self::BLOCK_TYPE)?->id,
            'fieldId' => Craft::$app->getFields()->getFieldByHandle(self::BLOCKS)?->id,
            'ownerId' => $owner->id,
            'siteId' => self::$primarySiteId,
        ]);
        $block->setFieldValue(self::IMAGES, $assetIds);
        Assert::assertTrue(Craft::$app->getElements()->saveElement($block), Json::encode($block->getErrors()));

        return $block;
    }

    // Requests

    /**
     * A page's block rendered with the variables its controller gave it. The control panel
     * layout around it needs a web session, which the console app running tests can't have.
     */
    /**
     * A CSV download: its file name, whether it starts with a UTF-8 BOM, and its rows.
     *
     * @return array{filename: string, bom: bool, rows: list<list<string>>}
     */
    protected static function csv(?Response $response): array
    {
        Assert::assertNotNull($response);
        Assert::assertIsArray($response->stream);
        [$handle, $begin, $end] = $response->stream;
        fseek($handle, $begin);
        $body = (string)stream_get_contents($handle, $end - $begin + 1);
        preg_match('/filename="([^"]+)"/', (string)$response->getHeaders()->get('Content-Disposition'), $match);
        $bom = str_starts_with($body, "\u{FEFF}");
        $lines = fopen('php://memory', 'w+');
        fwrite($lines, $bom ? substr($body, 3) : $body);
        rewind($lines);
        $rows = [];

        while (($row = fgetcsv($lines, null, ',', '"', '')) !== false) {
            $rows[] = array_map('strval', $row);
        }

        return ['filename' => $match[1] ?? '', 'bom' => $bom, 'rows' => $rows];
    }

    protected static function html(?Response $response, string $block = 'content'): string
    {
        Assert::assertNotNull($response);
        $behavior = $response->getBehavior(\craft\web\TemplateResponseBehavior::NAME);
        Assert::assertInstanceOf(\craft\web\TemplateResponseBehavior::class, $behavior);
        $view = Craft::$app->getView();
        $view->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);

        return $view->getTwig()->load($behavior->template)->renderBlock($block, $behavior->variables + $view->getTwig()->getGlobals());
    }

    /**
     * @param string[] $permissions
     */
    protected function signIn(bool $admin, array $permissions = ['accessCp']): TestUser
    {
        $user = new TestUser();
        $user->id = 1;
        $user->admin = $admin;
        $user->grantedPermissions = $permissions;
        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }

    /** Permissions to view and save assets in both test volumes. */
    protected static function assetPermissions(): array
    {
        $permissions = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $permissions[] = "editSite:$site->uid";
        }

        foreach ([self::$shared, self::$translated] as $volume) {
            array_push($permissions, "viewAssets:$volume->uid", "saveAssets:$volume->uid", "viewPeerAssets:$volume->uid", "savePeerAssets:$volume->uid");
        }

        return $permissions;
    }

    /**
     * Runs an AltMonitor controller action as a control panel request, as Craft runs a real one,
     * with a valid CSRF token unless told otherwise.
     *
     * @param class-string<\craft\web\Controller> $class
     * @param array<string, mixed> $params Body params, and for a GET the query and route params.
     */
    protected function runAction(string $class, string $id, string $action, array $params = [], ?TestUser $user = null, bool $withCsrf = true, string $method = 'POST', bool $json = true): ?Response
    {
        $path = "/admin/actions/alt-monitor/$id/$action";
        $_COOKIE = [];
        $headers = $json ? ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] : [];
        $this->useWebRequest($method, $path, $headers);
        $user ??= Craft::$app->getUser()->getIdentity();

        if ($withCsrf) {
            $request = Craft::$app->getRequest();
            $token = $request->getCsrfToken();
            $cookie = Craft::$app->getResponse()->getCookies()->get($request->csrfParam);
            Assert::assertNotNull($cookie);
            $_COOKIE[$request->csrfParam] = Craft::$app->getSecurity()->hashData(serialize([$request->csrfParam, $cookie->value]), $request->cookieValidationKey);
            $params[$request->csrfParam] = $token;
            $this->useWebRequest($method, $path, $headers);
        }

        if ($user !== null) {
            Craft::$app->getUser()->setIdentity($user);
        }

        $request = Craft::$app->getRequest();

        if ($method === 'GET') {
            $request->setQueryParams($params);
        } else {
            $request->setBodyParams($params);
        }

        $controller = new $class($id, AltMonitor::getInstance());
        $response = $controller->runAction($action, $method === 'GET' ? $params : []);

        return $response instanceof Response ? $response : null;
    }

    /** Back to the console request tests otherwise run in. */
    protected function tearDownRequest(): void
    {
        $this->restoreConsoleRequest();
    }

    /** Makes what follows run as a control panel request (an entry being saved in its editor). */
    protected function asCpRequest(): void
    {
        $this->useWebRequest('POST', '/admin/actions/elements/save', []);
    }

    /**
     * @param array<string, string> $headers
     */
    private function useWebRequest(string $method, string $path, array $headers): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = '/var/www/html/web/index.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);

        foreach ($headers as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        foreach (['request', 'response'] as $id) {
            $this->consoleComponents[$id] ??= Craft::$app->get($id);
        }

        Craft::$app->set('request', Craft::createObject(App::webRequestConfig()));
        Craft::$app->set('response', Craft::createObject(App::webResponseConfig()));
    }

    private function restoreConsoleRequest(): void
    {
        foreach ($this->consoleComponents as $id => $component) {
            Craft::$app->set($id, $component);
        }

        $this->consoleComponents = [];
        $_COOKIE = [];
    }
}
