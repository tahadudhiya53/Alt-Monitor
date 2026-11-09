<?php

namespace Tahadudhiya\AltMonitor\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Tahadudhiya\AltMonitor\AltMonitor;
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

        return $this->renderTemplate('alt-monitor/model/_index', [
            'config' => $config,
            'modelName' => basename($settings->browserModel),
            'services' => [
                self::service($settings->browserModelHost ?? 'https://huggingface.co/', Craft::t('alt-monitor', 'The image model (Florence-2, quantized)'), 277, 'https://huggingface.co/privacy'),
                self::service($settings->browserWasmPath ?? 'https://cdn.jsdelivr.net/npm/onnxruntime-web/', Craft::t('alt-monitor', 'The AI runtime (ONNX Runtime for WebAssembly)'), 27, 'https://www.jsdelivr.com/terms/privacy-policy-jsdelivr-net'),
            ],
            'serverAi' => $plugin->getSuggestions()->generatesOnServer(),
            'returnUrl' => $this->returnUrl(),
        ]);
    }

    /**
     * Where to go back to once the model is ready: only an AltMonitor page of this control panel,
     * so the link can't send anyone elsewhere.
     */
    private function returnUrl(): ?string
    {
        $return = $this->request->getQueryParam('return');
        $base = strtok(UrlHelper::cpUrl('alt-monitor'), '?');

        if (!is_string($return) || $base === false || strlen($return) > 2000 || preg_match('/[\s\\\\]/', $return)) {
            return null;
        }

        return str_starts_with($return, $base) && in_array(substr($return, strlen($base), 1), ['', '/', '?', '#'], true)
            ? $return
            : null;
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
