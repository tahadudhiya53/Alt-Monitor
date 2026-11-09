<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\models\Settings;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * AltMonitor's settings, and testing the Ollama connection configured in `config/alt-monitor.php`.
 *
 * Settings live in project config, so they can only be saved where admin changes are allowed.
 * Nothing about AI can be changed here: the browser model needs no setting, and Ollama is set in
 * the config file only.
 */
class SettingsController extends Controller
{
    use RequestInput;

    /** The settings this page may change. */
    private const EDITABLE = ['scanBatchSize', 'auditOnSave', 'scanUsageAfterFullScan', 'additionalGenericTerms'];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(AltMonitor::PERMISSION_MANAGE_SETTINGS);

        return true;
    }

    public function actionIndex(?Settings $settings = null): Response
    {
        $plugin = AltMonitor::getInstance();

        return $this->renderTemplate('alt-monitor/settings/_index', [
            'settings' => $settings ?? $plugin->getSettings(),
            'browser' => $plugin->getAiProviders()->browserConfig(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile('alt-monitor')),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Settings can only be changed where admin changes are allowed.');
        }

        $plugin = AltMonitor::getInstance();
        $values = $this->posted();

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            return $this->asModelFailure($plugin->getSettings(), Craft::t('alt-monitor', 'Couldn’t save the settings.'), 'settings');
        }

        return $this->asSuccess(Craft::t('alt-monitor', 'Settings saved.'));
    }

    /**
     * Checks the Ollama endpoint and model set in the config file: that Ollama answers, and that
     * the model is installed and accepts images.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = AltMonitor::getInstance();
        $settings = clone $plugin->getSettings();
        $settings->aiEnabled = true;

        if (!$settings->validate(['ollamaEndpoint', 'ollamaModel'])) {
            return $this->asJson(['reachable' => false, 'modelAvailable' => false, 'message' => implode(' ', $settings->getFirstErrors())]);
        }

        $provider = $plugin->getAiProviders()->getProvider($settings);
        $status = $provider?->status();

        return $this->asJson([
            'reachable' => $status->reachable ?? false,
            'modelAvailable' => $status->modelAvailable ?? false,
            'message' => $status->message ?? Craft::t('alt-monitor', 'No AI provider is available.'),
            'models' => $status->models ?? [],
        ]);
    }

    /**
     * The editable settings in the request, and nothing else.
     *
     * @return array<string, mixed>
     */
    private function posted(): array
    {
        $posted = $this->request->getBodyParam('settings', []);

        if (!is_array($posted)) {
            throw new BadRequestHttpException('Settings must be sent as a set of values.');
        }

        $values = array_intersect_key($posted, array_flip(self::EDITABLE));

        // Form values arrive as text; the settings' properties are typed. A value that isn't a
        // number becomes 0, which validation then refuses with a message.
        if (array_key_exists('scanBatchSize', $values)) {
            $values['scanBatchSize'] = is_numeric($values['scanBatchSize']) ? (int)$values['scanBatchSize'] : 0;
        }

        foreach (['auditOnSave', 'scanUsageAfterFullScan'] as $name) {
            if (array_key_exists($name, $values)) {
                $values[$name] = (bool)$values[$name];
            }
        }

        if (array_key_exists('additionalGenericTerms', $values)) {
            $values['additionalGenericTerms'] = Settings::normalizeList($values['additionalGenericTerms']);
        }

        return $values;
    }
}
