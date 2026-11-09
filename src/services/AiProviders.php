<?php

namespace Tahadudhiya\AltMonitor\services;

use craft\helpers\UrlHelper;
use Tahadudhiya\AltMonitor\ai\AiProviderInterface;
use Tahadudhiya\AltMonitor\ai\EndpointPolicy;
use Tahadudhiya\AltMonitor\ai\ImageProcessor;
use Tahadudhiya\AltMonitor\ai\OllamaProvider;
use Tahadudhiya\AltMonitor\ai\PromptBuilder;
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
     * which model to load from where.
     *
     * @return array{worker: string, library: string, model: string, label: string, remoteHost: string|null, wasmPaths: string|null, setupUrl: string}
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
        ];
    }

    /** What a suggestion from the browser model records as its basis. */
    public static function browserModelLabel(string $model): string
    {
        return basename($model) . ' (in the browser)';
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
