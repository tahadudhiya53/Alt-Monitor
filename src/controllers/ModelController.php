<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\AltMonitor\ai\Translations;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\services\AiProviders;
use yii\web\Response;

/**
 * The image model page: what downloading the browser model involves (what is fetched, from whom,
 * what they see, where it is kept), and the download itself, which needs the user's go-ahead.
 *
 * Describing images sends people here first when their browser doesn't have the model yet.
 */
class ModelController extends Controller
{
    use RequestInput;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(AltMonitor::PERMISSION_VIEW);
        $this->requirePermission(AltMonitor::PERMISSION_GENERATE);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = AltMonitor::getInstance();
        $settings = $plugin->getSettings();
        $config = $plugin->getAiProviders()->browserConfig();
        $modelHost = $settings->browserModelHost ?? 'https://huggingface.co/';
        $services = [
            self::service($modelHost, Craft::t('alt-monitor', 'The image model (Florence-2)'), AiProviders::MODEL_MEGABYTES, 'https://huggingface.co/privacy'),
            self::service($settings->browserWasmPath ?? 'https://cdn.jsdelivr.net/npm/onnxruntime-web/', Craft::t('alt-monitor', 'The AI engine (ONNX Runtime)'), AiProviders::RUNTIME_MEGABYTES, 'https://www.jsdelivr.com/terms/privacy-policy-jsdelivr-net'),
        ];
        // Translation models are separate, optional downloads: one per language the sites use
        // other than English, where one exists.
        $translations = [];
        $untranslated = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $language = Translations::languageFor($site);
            $translation = Translations::forLanguage($language);

            if ($translation !== null) {
                $translations[$translation['language']] ??= $translation + [
                    'name' => Translations::languageName($translation['language']),
                    'sites' => [],
                ];
                $translations[$translation['language']]['sites'][] = $site->getName();
            } elseif (!Translations::isEnglish($language)) {
                $untranslated[$language] = Translations::languageName($language);
            }
        }

        // Asked for by a Translate button: that language's row is the one to show.
        $wanted = $this->request->getQueryParam('translation');

        return $this->renderTemplate('alt-monitor/model/_index', [
            'config' => $config,
            'modelName' => basename($settings->browserModel),
            'services' => $services,
            'translations' => array_values($translations),
            'wantedTranslation' => is_string($wanted) && isset($translations[$wanted]) ? $wanted : null,
            'untranslatedLanguages' => array_values(array_unique($untranslated)),
            'serverAi' => $plugin->getSuggestions()->generatesOnServer(),
            'returnUrl' => $this->returnUrl(),
        ]);
    }

    /**
     * Where to go back to once the model is ready: only an AltMonitor page or Craft's asset
     * editor (where the AltMonitor sidebar box describes images) in this control panel, so the
     * link can't send anyone elsewhere.
     */
    private function returnUrl(): ?string
    {
        $return = $this->request->getQueryParam('return');

        // No spaces, backslashes, dot segments or encoded slashes and dots: nothing that could
        // climb out of the paths below once the browser or Craft resolves it.
        if (!is_string($return) || strlen($return) > 2000 || preg_match('/[\s\\\\]|\.\.|%2e|%2f|%5c/i', $return)) {
            return null;
        }

        // Compared with the full control panel URL, including “?p=admin/…” where the control panel
        // is reached through a query string, so other pages on the same host don't pass.
        foreach (['alt-monitor' => ['', '/', '?', '&', '#'], 'assets/edit' => ['/']] as $path => $next) {
            $base = self::withoutSite(UrlHelper::cpUrl($path));

            if (str_starts_with($return, $base) && in_array(substr($return, strlen($base), 1), $next, true)) {
                return $return;
            }
        }

        return null;
    }

    /**
     * A control panel URL without the `site` parameter Craft adds on multi-site installs
     * (“/admin/alt-monitor?site=en”), which the pages linked back to carry after their own path.
     */
    public static function withoutSite(string $url): string
    {
        return rtrim((string)preg_replace('/([?&])site=[^&#]*(?:&|(?=#)|$)/', '$1', $url), '?&');
    }

    /**
     * One place the browser downloads from, as the page describes it.
     *
     * @return array{host: string, what: string, megabytes: int, privacyUrl: string|null, external: bool}
     */
    private static function service(string $url, string $what, int $megabytes, string $privacyUrl): array
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $known = in_array($host, ['huggingface.co', 'cdn.jsdelivr.net'], true);
        $siteHost = (string)parse_url(UrlHelper::cpUrl(), PHP_URL_HOST);

        return [
            'host' => $host,
            'what' => $what,
            'megabytes' => $megabytes,
            'privacyUrl' => $known ? $privacyUrl : null,
            'external' => $host !== $siteHost,
        ];
    }
}
