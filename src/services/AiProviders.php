<?php

namespace Tahadudhiya\AltMonitor\services;

use craft\helpers\UrlHelper;
use Tahadudhiya\AltMonitor\ai\AiProviderInterface;
use Tahadudhiya\AltMonitor\ai\EndpointPolicy;
use Tahadudhiya\AltMonitor\ai\ImageProcessor;
use Tahadudhiya\AltMonitor\ai\OllamaProvider;
use Tahadudhiya\AltMonitor\ai\PromptBuilder;
use Tahadudhiya\AltMonitor\ai\Translations;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\events\DefineAiProviderEvent;
use Tahadudhiya\AltMonitor\models\Settings;
use yii\base\Component;

/**
 * Builds the AI provider from AltMonitor's settings, and holds the pieces every provider shares:
 * the prompt builder and the image processor.
 *
 * AI is optional. With it turned off there is no provider, and suggestions come from rules alone.
 */
class AiProviders extends Component
{
    /**
     * @event DefineAiProviderEvent Lets a module or plugin replace the provider.
     */
    public const EVENT_DEFINE_PROVIDER = 'defineProvider';

    /** The image model's download (Florence-2, q8), in MB, as its files report it. */
    public const MODEL_MEGABYTES = 277;

    /** The ONNX runtime's download, in MB. */
    public const RUNTIME_MEGABYTES = 27;

    /** A Guzzle handler every provider request goes through; tests set one to mock Ollama. */
    public mixed $httpHandler = null;

    private ?PromptBuilder $promptBuilder = null;
    private ?ImageProcessor $imageProcessor = null;

    /**
     * The provider for the current settings, or null when AI is turned off.
     */
    public function getProvider(?Settings $settings = null): ?AiProviderInterface
    {
        $settings ??= AltMonitor::getInstance()->getSettings();
        $event = new DefineAiProviderEvent([
            'provider' => $settings->aiEnabled ? new OllamaProvider(
                $settings->endpoint(),
                $settings->ollamaModel,
                $settings->aiTimeout,
                new EndpointPolicy($settings->allowedAiHosts),
                $this->httpHandler,
            ) : null,
        ]);

        $this->trigger(self::EVENT_DEFINE_PROVIDER, $event);

        return $event->provider;
    }

    /**
     * What the browser needs to describe images: where the worker and library are published, and
     * which model to load from where, and per site not in English the model that translates the
     * descriptions into its language.
     *
     * @return array{worker: string, library: string, model: string, label: string, remoteHost: string|null, wasmPaths: string|null, setupUrl: string, modelMegabytes: int, translations: array<int, array{model: string, language: string, megabytes: int}>}
     */
    public function browserConfig(): array
    {
        $settings = AltMonitor::getInstance()->getSettings();
        $assets = \Craft::$app->getAssetManager();
        $dist = dirname(__DIR__) . '/web/assets/cp/dist';

        return [
            'worker' => (string)$assets->getPublishedUrl($dist, true, 'describe-worker.js'),
            'library' => (string)$assets->getPublishedUrl($dist, true, 'vendor/transformers.min.js'),
            'model' => $settings->browserModel,
            'label' => self::browserModelLabel($settings->browserModel),
            'remoteHost' => $settings->browserModelHost,
            'wasmPaths' => $settings->browserWasmPath,
            // Where describing sends people whose browser doesn't have the model yet.
            'setupUrl' => UrlHelper::cpUrl('alt-monitor/image-model'),
            // Known download sizes, so progress is measured against the whole download from the
            // start instead of against the files that happen to have started.
            'modelMegabytes' => self::MODEL_MEGABYTES,
            'translations' => array_map(static fn(array $translation): array => [
                'model' => $translation['model'],
                'language' => $translation['language'],
                'megabytes' => $translation['megabytes'],
            ], Translations::forSites()),
        ];
    }

    /**
     * What a suggestion from the browser model records as its basis, with the model that
     * translated it, if any (“Florence-2-base-ft + opus-mt-en-de (in the browser)”).
     */
    public static function browserModelLabel(string $model, ?string $translationModel = null): string
    {
        return basename($model) . ($translationModel !== null ? ' + ' . basename($translationModel) : '') . ' (in the browser)';
    }

    /**
     * The browser's config for the current user, or null when they don't describe images in the
     * browser (no permission to generate, or images are described on the server).
     *
     * @return array<string, mixed>|null
     */
    public function describerConfig(): ?array
    {
        $plugin = AltMonitor::getInstance();

        return \Craft::$app->getUser()->checkPermission(AltMonitor::PERMISSION_GENERATE) && !$plugin->getSuggestions()->generatesOnServer()
            ? $this->browserConfig()
            : null;
    }

    /**
     * The browser's config for translating suggestions (Translate), or null when the current
     * user may not. Unlike describing, this doesn't depend on where images are described: Ollama
     * writes English too. Without any site to translate for, it is only there to describe.
     *
     * @return array<string, mixed>|null
     */
    public function translatorConfig(): ?array
    {
        $describer = $this->describerConfig();

        if ($describer !== null || !\Craft::$app->getUser()->checkPermission(AltMonitor::PERMISSION_GENERATE)) {
            return $describer;
        }

        $config = $this->browserConfig();

        return $config['translations'] !== [] ? $config : null;
    }

    /**
     * A suggestion's basis once its text was translated afterwards: the translation model is
     * added (“Florence-2-base-ft + opus-mt-en-de (in the browser)”).
     */
    public static function translatedBasis(?string $basis, string $translationModel): string
    {
        $suffix = ' (in the browser)';
        $basis = (string)$basis;

        if (str_ends_with($basis, $suffix)) {
            return substr($basis, 0, -strlen($suffix)) . ' + ' . basename($translationModel) . $suffix;
        }

        return ($basis !== '' ? $basis : 'AI') . ' + ' . basename($translationModel) . $suffix;
    }

    /** Whether a suggestion's basis says it was translated into its site's language. */
    public static function isTranslated(?string $basis): bool
    {
        return $basis !== null && str_contains($basis, ' + ');
    }

    public function getPromptBuilder(): PromptBuilder
    {
        return $this->promptBuilder ??= new PromptBuilder();
    }

    public function getImageProcessor(): ImageProcessor
    {
        return $this->imageProcessor ??= new ImageProcessor();
    }
}
