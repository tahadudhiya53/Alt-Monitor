<?php

namespace Tahadudhiya\AltMonitor;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use Tahadudhiya\AltMonitor\models\Settings;
use Tahadudhiya\AltMonitor\services\AiProviders;
use Tahadudhiya\AltMonitor\services\AltTextAnalyzer;
use Tahadudhiya\AltMonitor\services\AltTextEditor;
use Tahadudhiya\AltMonitor\services\AssetScanner;
use Tahadudhiya\AltMonitor\services\Audits;
use Tahadudhiya\AltMonitor\services\ContentUsageScanner;
use Tahadudhiya\AltMonitor\services\Scans;
use Tahadudhiya\AltMonitor\services\SuggestionGenerator;
use Tahadudhiya\AltMonitor\services\Suggestions;
use yii\base\Event;

/**
 * AltMonitor — image alt text auditing and management for Craft CMS.
 *
 * The plugin class is bootstrap and registration only. Domain behaviour belongs in services.
 *
 * Craft gates the control panel section and its nav item behind the “Access AltMonitor”
 * permission it registers for every plugin with a section. Within it, every controller action
 * also requires AltMonitor's own permissions, since Craft's section gate does not cover action
 * requests, and editing an image always also requires Craft's own right to save that asset.
 *
 * @property-read AiProviders $aiProviders
 * @property-read AltTextAnalyzer $analyzer
 * @property-read AltTextEditor $altTextEditor
 * @property-read AssetScanner $assetScanner
 * @property-read Audits $audits
 * @property-read ContentUsageScanner $contentUsage
 * @property-read Scans $scans
 * @property-read SuggestionGenerator $suggestionGenerator
 * @property-read Suggestions $suggestions
 */
class AltMonitor extends Plugin
{
    /** See the dashboard, the image list, suggestions and where images are used. */
    public const PERMISSION_VIEW = 'altMonitor:view';

    /** Start asset and content usage scans. Nested under viewing. */
    public const PERMISSION_RUN_SCANS = 'altMonitor:runScans';

    /** Queue suggestions to be generated. Nested under viewing. */
    public const PERMISSION_GENERATE = 'altMonitor:generateSuggestions';

    /** Edit alt text directly and mark images decorative. Nested under viewing. */
    public const PERMISSION_EDIT_ALT = 'altMonitor:editAlt';

    /** Approve or reject suggestions. Nested under viewing. */
    public const PERMISSION_APPROVE = 'altMonitor:approveSuggestions';

    /** Change AltMonitor's settings and test the AI connection. */
    public const PERMISSION_MANAGE_SETTINGS = 'altMonitor:manageSettings';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'aiProviders' => ['class' => AiProviders::class],
                'analyzer' => static fn(): AltTextAnalyzer => new AltTextAnalyzer([
                    'additionalGenericTerms' => self::getInstance()->getSettings()->additionalGenericTerms,
                ]),
                'altTextEditor' => ['class' => AltTextEditor::class],
                'assetScanner' => ['class' => AssetScanner::class],
                'audits' => ['class' => Audits::class],
                'contentUsage' => ['class' => ContentUsageScanner::class],
                'scans' => ['class' => Scans::class],
                'suggestionGenerator' => static fn(): SuggestionGenerator => new SuggestionGenerator(self::getInstance()->getAnalyzer()),
                'suggestions' => ['class' => Suggestions::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = [
                'heading' => Craft::t('alt-monitor', 'AltMonitor'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('alt-monitor', 'View AltMonitor'),
                        'info' => Craft::t('alt-monitor', 'Only images in volumes the user may view are shown.'),
                        'nested' => [
                            self::PERMISSION_RUN_SCANS => ['label' => Craft::t('alt-monitor', 'Run scans')],
                            self::PERMISSION_GENERATE => ['label' => Craft::t('alt-monitor', 'Generate suggestions')],
                            self::PERMISSION_EDIT_ALT => ['label' => Craft::t('alt-monitor', 'Edit alt text'), 'info' => Craft::t('alt-monitor', 'The user must also be allowed to save the asset.')],
                            self::PERMISSION_APPROVE => ['label' => Craft::t('alt-monitor', 'Approve suggestions'), 'info' => Craft::t('alt-monitor', 'The user must also be allowed to save the asset.')],
                        ],
                    ],
                    self::PERMISSION_MANAGE_SETTINGS => ['label' => Craft::t('alt-monitor', 'Manage settings')],
                ],
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event): void {
            $event->rules['alt-monitor'] = 'alt-monitor/dashboard/index';
            $event->rules['alt-monitor/images'] = 'alt-monitor/assets/index';
            $event->rules['alt-monitor/images/<assetId:\d+>/<siteId:\d+>'] = 'alt-monitor/assets/view';
            $event->rules['alt-monitor/suggestions'] = 'alt-monitor/suggestions/index';
            $event->rules['alt-monitor/settings'] = 'alt-monitor/settings/index';
            $event->rules['alt-monitor/image-model'] = 'alt-monitor/model/index';
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event): void {
            if ($event->element instanceof Asset && !$event->element->propagating && $this->getSettings()->auditOnSave) {
                $this->safely(fn() => $this->getAssetScanner()->auditAsset($event->element), 'auditing a saved asset');
            }
        });
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, function(ElementEvent $event): void {
            if ($event->element instanceof Asset) {
                $this->safely(fn() => $this->getAssetScanner()->auditAsset($event->element), 'auditing a restored asset');
            }
        });
        // Trashed assets are forgotten; hard deletion is handled by foreign keys.
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event): void {
            if ($event->element instanceof Asset) {
                $this->safely(function() use ($event): void {
                    $this->getAudits()->forget((int)$event->element->id);
                    $this->getSuggestions()->forget((int)$event->element->id);
                }, 'forgetting a deleted asset');
            }
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        if ($item === null || !$user->checkPermission(self::PERMISSION_VIEW)) {
            return $item;
        }

        $item['subnav'] = [
            'dashboard' => ['label' => Craft::t('alt-monitor', 'Dashboard'), 'url' => 'alt-monitor'],
            'images' => ['label' => Craft::t('alt-monitor', 'Images'), 'url' => 'alt-monitor/images'],
            'suggestions' => ['label' => Craft::t('alt-monitor', 'Suggestions'), 'url' => 'alt-monitor/suggestions'],
        ];

        if ($user->checkPermission(self::PERMISSION_GENERATE) && !$this->getSuggestions()->generatesOnServer()) {
            $item['subnav']['image-model'] = ['label' => Craft::t('alt-monitor', 'Image model'), 'url' => 'alt-monitor/image-model'];
        }

        if ($user->checkPermission(self::PERMISSION_MANAGE_SETTINGS)) {
            $item['subnav']['settings'] = ['label' => Craft::t('alt-monitor', 'Settings'), 'url' => 'alt-monitor/settings'];
        }

        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('alt-monitor/settings'));
    }

    public function getSettings(): Settings
    {
        /** @var Settings */
        return parent::getSettings();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getAiProviders(): AiProviders
    {
        /** @var AiProviders */
        return $this->get('aiProviders');
    }

    public function getAnalyzer(): AltTextAnalyzer
    {
        /** @var AltTextAnalyzer */
        return $this->get('analyzer');
    }

    public function getAltTextEditor(): AltTextEditor
    {
        /** @var AltTextEditor */
        return $this->get('altTextEditor');
    }

    public function getAssetScanner(): AssetScanner
    {
        /** @var AssetScanner */
        return $this->get('assetScanner');
    }

    public function getAudits(): Audits
    {
        /** @var Audits */
        return $this->get('audits');
    }

    public function getContentUsage(): ContentUsageScanner
    {
        /** @var ContentUsageScanner */
        return $this->get('contentUsage');
    }

    public function getScans(): Scans
    {
        /** @var Scans */
        return $this->get('scans');
    }

    public function getSuggestionGenerator(): SuggestionGenerator
    {
        /** @var SuggestionGenerator */
        return $this->get('suggestionGenerator');
    }

    public function getSuggestions(): Suggestions
    {
        /** @var Suggestions */
        return $this->get('suggestions');
    }

    /**
     * Runs work triggered by someone else's save, so a failure is logged instead of breaking it.
     */
    private function safely(callable $work, string $what): void
    {
        try {
            $work();
        } catch (\Throwable $exception) {
            Craft::error(sprintf('AltMonitor failed %s: %s', $what, $exception->getMessage()), __METHOD__);
        }
    }
}
