<?php

namespace Tahadudhiya\AltMonitor\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use Tahadudhiya\AltMonitor\ai\EndpointPolicy;

/**
 * AltMonitor's configuration. Craft stores it in project config, and `config/alt-monitor.php`
 * overrides any of it. It holds no secret: nothing AltMonitor uses needs one.
 *
 * Image descriptions come from Florence-2 running in the browser of whoever generates them, with
 * no setting to turn on. Everything about AI that is configurable (the browser model's sources, and
 * Ollama as a server-side alternative) is set in the config file only, never in the control panel.
 */
class Settings extends Model
{
    /** A Florence-2 model on the Hugging Face hub, in ONNX form, that the browser runs. */
    public string $browserModel = 'onnx-community/Florence-2-base-ft';

    /** Where the browser downloads the model from, if not Hugging Face (to self-host it). */
    public ?string $browserModelHost = null;

    /** Where the browser downloads the ONNX runtime's WebAssembly files from, if not jsDelivr. */
    public ?string $browserWasmPath = null;

    /**
     * Whether suggestions are generated on the server, in the queue, by a vision model through
     * Ollama, instead of in the browser. Config file only.
     */
    public bool $aiEnabled = false;

    /** Ollama's base URL. May be an environment variable such as `$OLLAMA_URL`. */
    public string $ollamaEndpoint = 'http://localhost:11434';

    /** An installed Ollama model that accepts images. */
    public string $ollamaModel = 'qwen2.5vl:3b';

    /** Seconds to wait for one image to be described. Local models on modest hardware are slow. */
    public int $aiTimeout = 120;

    /**
     * Hosts the Ollama endpoint may name. Set only in `config/alt-monitor.php`, never from the
     * control panel, so whoever can edit settings there cannot point image data anywhere else.
     *
     * @var list<string>
     */
    public array $allowedAiHosts = ['localhost', '127.0.0.1', '::1', 'host.docker.internal', 'ollama'];

    /** Image assets read per queue job while scanning. */
    public int $scanBatchSize = 100;

    /** Whether an image's audit is refreshed when the asset is saved, without waiting for a scan. */
    public bool $auditOnSave = true;

    /** Whether a full scan goes on to scan content usage. */
    public bool $scanUsageAfterFullScan = true;

    /**
     * Whether a completed asset scan queues suggestions for images with missing or weak alt text.
     * Only with Ollama ({@see $aiEnabled}): in the browser, generating needs someone on the page.
     */
    public bool $suggestAfterScan = false;

    /**
     * Whether a suggestion built from the caption, title or file name is stored when Ollama can't
     * describe an image. Off: suggestions describe the image itself.
     */
    public bool $fallbackToRules = false;

    /**
     * Extra words or phrases that, on their own, count as generic alt text.
     *
     * @var list<string>
     */
    public array $additionalGenericTerms = [];

    /**
     * Handles of asset fields (such as a caption) whose text rule-based suggestions may use.
     *
     * @var list<string>
     */
    public array $contextFieldHandles = ['caption', 'description'];

    /** The endpoint with any environment variable resolved. */
    public function endpoint(): string
    {
        return rtrim((string)App::parseEnv($this->ollamaEndpoint), '/');
    }

    public function fields(): array
    {
        // Saved to project config from the control panel, so the host allowlist is left out:
        // it is only ever read from the config file.
        $fields = parent::fields();
        unset($fields['allowedAiHosts']);

        return $fields;
    }

    protected function defineRules(): array
    {
        return [
            [['ollamaEndpoint', 'ollamaModel'], 'trim'],
            [['ollamaEndpoint', 'ollamaModel'], 'required'],
            [['ollamaEndpoint'], 'string', 'max' => 255],
            [['ollamaModel'], 'match', 'pattern' => '/^[a-z0-9][a-z0-9._\-\/]*(:[a-z0-9._\-]+)?$/i', 'message' => Craft::t('alt-monitor', 'A model name is letters, digits, “.”, “-”, “_” and “/”, with an optional “:tag”.')],
            [['ollamaModel', 'browserModel'], 'string', 'max' => 100],
            [['browserModelHost', 'browserWasmPath'], 'url', 'validSchemes' => ['https', 'http']],
            [['aiEnabled', 'auditOnSave', 'scanUsageAfterFullScan', 'suggestAfterScan', 'fallbackToRules'], 'boolean'],
            [['aiTimeout'], 'integer', 'min' => 10, 'max' => 600],
            [['scanBatchSize'], 'integer', 'min' => 10, 'max' => 1000],
            [['additionalGenericTerms', 'contextFieldHandles'], 'filter', 'filter' => [self::class, 'normalizeList']],
            [['contextFieldHandles'], 'each', 'rule' => ['match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/']],
            [['ollamaEndpoint'], function(string $attribute): void {
                $problem = (new EndpointPolicy($this->allowedAiHosts))->problem($this->endpoint());

                if ($problem !== null) {
                    $this->addError($attribute, $problem);
                }
            }, 'when' => fn(): bool => $this->aiEnabled],
        ];
    }

    /**
     * A list from a form's textarea (one per line) or from config (an array): trimmed, without
     * blanks or duplicates.
     *
     * @return list<string>
     */
    public static function normalizeList(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string)$value);
        $items = array_map(static fn(mixed $item): string => trim((string)$item), $items ?: []);

        return array_values(array_unique(array_filter($items, static fn(string $item): bool => $item !== '')));
    }

    public function attributeLabels(): array
    {
        return [
            'ollamaEndpoint' => Craft::t('alt-monitor', 'Ollama endpoint'),
            'ollamaModel' => Craft::t('alt-monitor', 'Model'),
            'aiTimeout' => Craft::t('alt-monitor', 'Timeout'),
            'scanBatchSize' => Craft::t('alt-monitor', 'Scan batch size'),
        ];
    }
}
