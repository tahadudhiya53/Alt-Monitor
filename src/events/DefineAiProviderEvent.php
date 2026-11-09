<?php

namespace Tahadudhiya\AltMonitor\events;

use Tahadudhiya\AltMonitor\ai\AiProviderInterface;
use yii\base\Event;

/**
 * Lets a module or plugin supply the AI provider AltMonitor uses, in place of Ollama.
 */
class DefineAiProviderEvent extends Event
{
    /** The provider AltMonitor built from its settings; null when AI is turned off. */
    public ?AiProviderInterface $provider = null;
}
