<?php

namespace Tahadudhiya\AltMonitor\Tests\_support;

use Craft;
use craft\services\ProjectConfig;
use yii\base\Application;

/**
 * Project config changes a test makes and then throws away.
 *
 * Craft keeps changes in its working config, and writes them to the database and YAML only when
 * a request ends, which never happens in a test. While a sandbox is open, YAML is not written
 * even then; closing it discards the changes and releases the lock Craft took for them.
 */
final class ProjectConfigSandbox
{
    private static ?bool $writeYaml = null;

    public static function open(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        self::$writeYaml ??= $projectConfig->writeYamlAutomatically;
        $projectConfig->writeYamlAutomatically = false;
    }

    public static function close(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // reset() runs init() again, which attaches these handlers again without detaching the
        // earlier ones; every later change would then be handled once per reset.
        foreach ([ProjectConfig::EVENT_ADD_ITEM, ProjectConfig::EVENT_UPDATE_ITEM, ProjectConfig::EVENT_REMOVE_ITEM] as $name) {
            $projectConfig->off($name, [$projectConfig, 'handleChangeEvent']);
        }
        Craft::$app->off(Application::EVENT_AFTER_REQUEST, [$projectConfig, 'flush']);
        $projectConfig->reset();

        if (self::$writeYaml !== null) {
            $projectConfig->writeYamlAutomatically = self::$writeYaml;
            self::$writeYaml = null;
        }

        // Craft holds the project config lock from the first change until the changes are saved
        // when a request ends. None ends here, and the changes must not be saved, so the lock is
        // released as that save would release it; otherwise any other process (e.g. a test's
        // subprocess) waits for it. The flag is the service's own record of holding it.
        Craft::$app->getMutex()->release(ProjectConfig::MUTEX_NAME);
        (fn() => $this->_locked = false)->call($projectConfig);
    }
}
