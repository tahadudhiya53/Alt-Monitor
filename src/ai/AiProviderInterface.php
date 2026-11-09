<?php

namespace Tahadudhiya\AltMonitor\ai;

use Tahadudhiya\AltMonitor\errors\AiProviderException;
use Tahadudhiya\AltMonitor\models\AiStatus;

/**
 * A service that describes images. Ollama is the built-in one; others can be registered with
 * {@see \Tahadudhiya\AltMonitor\services\AiProviders::EVENT_REGISTER_PROVIDERS}.
 *
 * A provider receives image data AltMonitor has already prepared and a finished prompt, and returns
 * the model's raw answer. Cleaning that answer up is AltMonitor's job, not the provider's.
 */
interface AiProviderInterface
{
    /** A stable handle, stored with each suggestion. */
    public static function handle(): string;

    public static function displayName(): string;

    /** Whether the provider is reachable and can describe images with its configured model. */
    public function status(): AiStatus;

    /**
     * @param string $imageBase64 A JPEG, base64-encoded.
     * @return string The model's answer, as it gave it.
     * @throws AiProviderException
     */
    public function describe(string $imageBase64, string $prompt): string;

    /** What to record as a suggestion's basis, e.g. the model's name. */
    public function modelName(): string;
}
