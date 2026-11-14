<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\web\Controller;
use Tahadudhiya\AltMonitor\ai\Translations;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\models\Settings;
use Tahadudhiya\AltMonitor\services\AltTextAnalyzer;
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
    private const EDITABLE = ['scanBatchSize', 'auditOnSave', 'requireAltToPublish', 'scanUsageAfterFullScan', 'additionalGenericTerms', 'siteLanguages'];

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

        $settings ??= $plugin->getSettings();

        return $this->renderTemplate('alt-monitor/settings/_index', [
            'settings' => $settings,
            'siteLanguages' => self::siteLanguages($settings),
            'rules' => self::rules(),
            'genericTerms' => AltTextAnalyzer::GENERIC_TERMS,
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
        $wordsBefore = $plugin->getSettings()->additionalGenericTerms;

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $values)) {
            // Errors in settings only the config file sets have no field on this page: say so.
            $fileErrors = array_merge(...array_values(array_diff_key($plugin->getSettings()->getErrors(), array_flip(self::EDITABLE))));

            return $this->asModelFailure($plugin->getSettings(), $fileErrors !== []
                ? Craft::t('alt-monitor', 'Couldn’t save the settings: config/alt-monitor.php has a value that isn’t valid. {errors}', ['errors' => implode(' ', $fileErrors)])
                : Craft::t('alt-monitor', 'Couldn’t save the settings.'), 'settings');
        }

        // New generic words only judge existing images again when a full scan reads them.
        if ($plugin->getSettings()->additionalGenericTerms !== $wordsBefore) {
            return $this->asSuccess(Craft::t('alt-monitor', 'Settings saved. Run a full scan to re-check images with the new generic words.'));
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
            'message' => $status->message ?? Craft::t('alt-monitor', 'No AI is set up.'),
            'models' => $status->models ?? [],
        ]);
    }

    /**
     * How a scan gives each image its status, in words, for the Rules section. The limits come
     * from {@see AltTextAnalyzer}, so the page says what the checks do.
     *
     * @return list<array{status: AltStatus, reasons: list<string>}>
     */
    private static function rules(): array
    {
        return [
            ['status' => AltStatus::MISSING, 'reasons' => [
                Craft::t('alt-monitor', 'No alt text, and the image isn’t marked as decorative.'),
            ]],
            ['status' => AltStatus::WEAK, 'reasons' => [
                Craft::t('alt-monitor', 'It’s the file name or has a file extension, like “team-photo.jpg”.'),
                Craft::t('alt-monitor', 'It’s a code or mostly numbers, like “IMG 1234” or “DSC_0042”.'),
                Craft::t('alt-monitor', 'It’s only a generic word, like “image” or “logo” (see the list below).'),
                Craft::t('alt-monitor', 'It repeats the same words over and over.'),
            ]],
            ['status' => AltStatus::REVIEW, 'reasons' => [
                Craft::t('alt-monitor', 'It starts with “image of”, “picture of” or “photo of”.'),
                Craft::t('alt-monitor', 'It’s shorter than 3 characters or longer than {max, number}.', ['max' => AltTextAnalyzer::LONG_TEXT]),
                Craft::t('alt-monitor', 'No alt text, but the file name looks decorative, like “divider” or “background”.'),
            ]],
            ['status' => AltStatus::GOOD, 'reasons' => [
                Craft::t('alt-monitor', 'None of the above.'),
                Craft::t('alt-monitor', 'Or marked as decorative: screen readers skip it.'),
            ]],
        ];
    }

    /**
     * Per site: its language, the suggestion language chosen for it, the choices, and what that
     * means for its suggestions.
     *
     * @return list<array{uid: string, name: string, language: string, value: string, options: list<array{label: string, value: string}>, effect: string}>
     */
    private static function siteLanguages(Settings $settings): array
    {
        $rows = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $own = Translations::primary($site->language);
            $ownName = Translations::languageName($own);
            $options = [['label' => Craft::t('alt-monitor', 'Same as the site ({language})', ['language' => $ownName]), 'value' => '']];
            $others = [];

            foreach (Translations::choices() as $language) {
                if ($language !== $own) {
                    $others[] = ['label' => Translations::languageName($language), 'value' => $language];
                }
            }

            usort($others, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
            $value = $settings->siteLanguages[$site->uid] ?? '';
            // What describing and translating will use (after a failed save, what was posted).
            $language = Translations::languageFor($site);
            $rows[] = [
                'uid' => (string)$site->uid,
                'name' => $site->getName(),
                'language' => $ownName,
                'value' => $value,
                'options' => [...$options, ...$others],
                'effect' => match (true) {
                    Translations::isEnglish($language) => Craft::t('alt-monitor', 'Written in English.'),
                    Translations::forLanguage($language) !== null => Craft::t('alt-monitor', 'Written in English, then translated into {language} in the browser.', ['language' => Translations::languageName(Translations::primary($language))]),
                    default => Craft::t('alt-monitor', 'Stays in English and is marked: there’s no translation model for {language}.', ['language' => Translations::languageName($language)]),
                },
            ];
        }

        return $rows;
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

        // Settings the config file sets can't be changed here: whatever is posted for them is
        // left out, so nothing is saved that wouldn't take effect.
        $values = array_diff_key(array_intersect_key($posted, array_flip(self::EDITABLE)), Craft::$app->getConfig()->getConfigFromFile('alt-monitor'));

        // Form values arrive as text; the settings' properties are typed. A value that isn't a
        // number becomes 0, which validation then refuses with a message.
        if (array_key_exists('scanBatchSize', $values)) {
            $values['scanBatchSize'] = is_numeric($values['scanBatchSize']) ? (int)$values['scanBatchSize'] : 0;
        }

        foreach (['auditOnSave', 'requireAltToPublish', 'scanUsageAfterFullScan'] as $name) {
            if (array_key_exists($name, $values)) {
                $values[$name] = (bool)$values[$name];
            }
        }

        if (array_key_exists('siteLanguages', $values)) {
            $values['siteLanguages'] = Settings::normalizeSiteLanguages($values['siteLanguages']);
        }

        if (array_key_exists('additionalGenericTerms', $values)) {
            $values['additionalGenericTerms'] = Settings::normalizeList($values['additionalGenericTerms']);
        }

        return $values;
    }
}
