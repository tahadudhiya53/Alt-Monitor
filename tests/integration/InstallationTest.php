<?php

namespace Tahadudhiya\AltMonitor\Tests\integration;

use Craft;
use craft\db\Query;
use craft\web\twig\variables\Cp;
use craft\web\View;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\migrations\Install;
use Tahadudhiya\AltMonitor\records\AuditRecord;
use Tahadudhiya\AltMonitor\records\ScanRecord;
use Tahadudhiya\AltMonitor\records\SuggestionRecord;
use Tahadudhiya\AltMonitor\records\UsageRecord;
use Tahadudhiya\AltMonitor\Tests\_support\TestUser;

/**
 * AltMonitor installed in the surrounding Craft project: booted by Craft, its permissions and
 * control panel section registered, its templates compiling, and its migration reversible.
 */
class InstallationTest extends TestCase
{
    private const TABLES = [ScanRecord::TABLE, AuditRecord::TABLE, SuggestionRecord::TABLE, UsageRecord::TABLE];

    private ?object $consoleRequest = null;

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        if ($this->consoleRequest !== null) {
            Craft::$app->set('request', $this->consoleRequest);
            $this->consoleRequest = null;
        }
    }

    private function plugin(): AltMonitor
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('alt-monitor');
        self::assertInstanceOf(AltMonitor::class, $plugin, 'AltMonitor must be installed and enabled in the host project.');

        return $plugin;
    }

    /**
     * @param array<string, array{nested?: array<string, mixed>}> $permissions
     * @return list<string>
     */
    private static function permissionNames(array $permissions): array
    {
        $names = [];

        foreach ($permissions as $name => $definition) {
            $names[] = $name;
            array_push($names, ...self::permissionNames($definition['nested'] ?? []));
        }

        return $names;
    }

    /**
     * @param list<string> $permissions
     * @return list<string>
     */
    private function navFor(array $permissions): array
    {
        $_SERVER = array_merge($_SERVER, ['HTTP_HOST' => 'localhost', 'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'REQUEST_URI' => '/admin/dashboard', 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '127.0.0.1']);
        $this->consoleRequest ??= Craft::$app->getRequest();
        Craft::$app->set('request', new \craft\web\Request());

        $user = new TestUser();
        $user->grantedPermissions = $permissions;
        Craft::$app->getUser()->setIdentity($user);

        foreach ((new Cp())->nav() as $item) {
            if (($item['id'] ?? null) === 'nav-alt-monitor') {
                return array_keys($item['subnav'] ?? []);
            }
        }

        return [];
    }

    public function testCraftBootsThePluginWithItsServices(): void
    {
        $plugin = $this->plugin();

        self::assertSame('alt-monitor', $plugin->id);
        self::assertTrue(Craft::$app->getPlugins()->isPluginEnabled('alt-monitor'));

        foreach (['aiProviders', 'analyzer', 'altTextEditor', 'assetScanner', 'audits', 'contentUsage', 'scans', 'suggestionGenerator', 'suggestions'] as $id) {
            self::assertTrue($plugin->has($id), "The $id service is not registered.");
        }

        // AI is opt-in: nothing is sent anywhere until it is turned on.
        self::assertFalse((new \Tahadudhiya\AltMonitor\models\Settings())->aiEnabled);
    }

    public function testThePermissionsAreRegistered(): void
    {
        $this->plugin();
        $names = self::permissionNames(array_merge(...array_column(Craft::$app->getUserPermissions()->getAllPermissions(), 'permissions')));

        foreach ([AltMonitor::PERMISSION_VIEW, AltMonitor::PERMISSION_RUN_SCANS, AltMonitor::PERMISSION_GENERATE, AltMonitor::PERMISSION_EDIT_ALT, AltMonitor::PERMISSION_APPROVE, AltMonitor::PERMISSION_MANAGE_SETTINGS, 'accessPlugin-alt-monitor'] as $permission) {
            self::assertContains($permission, $names);
        }
    }

    public function testTheNavFollowsThePermissions(): void
    {
        $this->plugin();

        self::assertSame([], $this->navFor(['accessCp']));
        self::assertSame(['dashboard', 'images', 'suggestions'], $this->navFor(['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW]));
        self::assertSame(['dashboard', 'images', 'suggestions', 'settings'], $this->navFor(['accessCp', 'accessPlugin-alt-monitor', AltMonitor::PERMISSION_VIEW, AltMonitor::PERMISSION_MANAGE_SETTINGS]));
    }

    public function testEveryTemplateCompiles(): void
    {
        $this->plugin();
        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        foreach (['_dashboard', '_macros', 'images/_index', 'images/_view', 'suggestions/_index', 'settings/_index'] as $template) {
            self::assertSame("alt-monitor/$template", $view->getTwig()->load("alt-monitor/$template")->getTemplateName());
        }

        // The pages' styles and scripts publish from the plugin.
        $view->registerAssetBundle(\Tahadudhiya\AltMonitor\web\assets\cp\CpAsset::class);
        $bundle = $view->getAssetManager()->getBundle(\Tahadudhiya\AltMonitor\web\assets\cp\CpAsset::class);
        self::assertFileExists($bundle->basePath . '/cp.js');
        self::assertFileExists($bundle->basePath . '/cp.css');
    }

    public function testTheMigrationRollsBackAndInstallsAgain(): void
    {
        $this->plugin();
        $db = Craft::$app->getDb();

        foreach (self::TABLES as $table) {
            if ((new Query())->from($table)->exists()) {
                self::markTestSkipped("$table holds data, which rolling back would delete.");
            }
        }

        $migration = new Install(['db' => $db]);
        $schema = $db->getSchema();

        try {
            self::assertTrue($migration->safeDown());
            $schema->refresh();

            foreach (self::TABLES as $table) {
                self::assertNull($schema->getTableSchema($table), "$table is still there.");
            }
        } finally {
            self::assertTrue($migration->safeUp());
            $schema->refresh();
        }

        foreach (self::TABLES as $table) {
            self::assertNotNull($schema->getTableSchema($table), "$table was not created.");
        }

        $audits = $schema->getTableSchema(AuditRecord::TABLE);
        self::assertNotNull($audits);
        self::assertSame(['id', 'assetId', 'siteId', 'volumeId', 'scanId', 'altStatus', 'issues', 'decorative', 'usageStatus', 'altHash', 'dateScanned', 'dateAltSaved', 'dateCreated', 'dateUpdated', 'uid'], $audits->getColumnNames());
        self::assertCount(4, $audits->foreignKeys);
    }
}
