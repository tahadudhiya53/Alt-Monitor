<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\base\Element;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\BaseRelationField;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\models\EntryImage;
use Tahadudhiya\AltMonitor\web\assets\cp\CpAsset;
use yii\base\Component;

/**
 * What AltMonitor adds to Craft's entry editor: a box in its sidebar listing the entry's images
 * with missing or weak alt text, and (when the setting is on) a check that stops an entry being
 * published while any image in it has no alt text.
 *
 * The images are read from the entry as it is being edited (its field values, so a draft's or
 * the values just posted), not from the last usage scan: Assets fields, nested entries (Matrix)
 * at any depth up to {@see MAX_DEPTH}, and asset reference tags in any other field (rich text).
 * Their alt text is checked now with {@see AltTextAnalyzer}, so the box is right even for images
 * no scan has seen.
 *
 * Nothing here writes: the box's buttons go through the same endpoints as the rest of AltMonitor.
 */
class EntryCheck extends Component
{
    /** How deep nested entries (Matrix in Matrix…) are followed. */
    public const MAX_DEPTH = 5;

    /**
     * The images an element uses, in the order found, each with the handle of the field (at the
     * top level of the element) it was first found in.
     *
     * @return array<int, string> Asset ID => field handle.
     */
    public function imageIds(ElementInterface $element): array
    {
        $found = [];
        $this->collect($element, null, 0, $found);

        if ($found === []) {
            return [];
        }

        $images = array_flip(array_map('intval', Asset::find()
            ->id(array_keys($found))
            ->kind(Asset::KIND_IMAGE)
            ->status(null)
            ->site('*')
            ->unique()
            ->ids()));

        return array_intersect_key($found, $images);
    }

    /**
     * The element's images as the box shows them, and how many more the user may not see.
     *
     * @param User|null $user Null: every image (the publish check), regardless of who saves.
     * @return array{images: list<EntryImage>, hidden: int}
     */
    public function images(ElementInterface $element, ?User $user): array
    {
        $ids = $this->imageIds($element);

        if ($ids === []) {
            return ['images' => [], 'hidden' => 0];
        }

        $plugin = AltMonitor::getInstance();
        $scanner = $plugin->getAssetScanner();
        $analyzer = $plugin->getAnalyzer();
        /** @var array<int, Asset> $assets */
        $assets = Asset::find()->id(array_keys($ids))->siteId($element->siteId)->status(null)->indexBy('id')->all();
        $siteIds = [];

        foreach ($assets as $asset) {
            $siteIds[$asset->id] = $scanner->auditSiteId((int)$asset->volumeId, (int)$asset->siteId);
        }

        $decorative = [];

        foreach (array_unique($siteIds) as $siteId) {
            $assetIds = array_keys(array_filter($siteIds, static fn(int $id): bool => $id === $siteId));

            foreach ($plugin->getAudits()->stored($siteId, $assetIds) as $assetId => $stored) {
                $decorative[$assetId . '-' . $siteId] = $stored['decorative'];
            }
        }

        $suggestions = $plugin->getSuggestions()->forPairs(array_map(null, array_keys($siteIds), array_values($siteIds)));
        $fieldNames = [];

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $fieldNames[(string)$field->handle] = $field->name;
        }

        $images = [];
        $hidden = 0;

        foreach ($ids as $assetId => $handle) {
            $asset = $assets[$assetId] ?? null;

            if (!$asset instanceof Asset) {
                continue;
            }

            if ($user !== null && !$scanner->userMay($asset, $user)) {
                $hidden++;
                continue;
            }

            $key = $assetId . '-' . $siteIds[$assetId];
            $isDecorative = $decorative[$key] ?? false;
            $analysis = $analyzer->analyze($asset->alt, (string)$asset->getFilename(), $isDecorative);

            $images[] = new EntryImage(
                asset: $asset,
                auditSiteId: $siteIds[$assetId],
                status: $analysis->status,
                issues: $analysis->issues,
                decorative: $isDecorative,
                suggestion: $suggestions[$key] ?? null,
                fieldHandle: $handle,
                fieldName: $fieldNames[$handle] ?? null,
                canSave: $user !== null && $scanner->userMay($asset, $user, true),
            );
        }

        return ['images' => $images, 'hidden' => $hidden];
    }

    /**
     * Stops an entry being published (saved live) while an image in it has no alt text: one
     * error per field holding such images, naming them. Only for entries edited in the control
     * panel with the setting on; disabled entries, drafts being autosaved, nested entries (their
     * owner is checked), propagation and resaves are left alone.
     */
    public function validate(ElementInterface $element): void
    {
        if (!$this->checksOnPublish($element)) {
            return;
        }

        $missing = [];

        foreach ($this->images($element, null)['images'] as $image) {
            if (!$image->decorative && $image->status === AltStatus::MISSING) {
                $missing[$image->fieldHandle][] = $image->asset->getFilename();
            }
        }

        // At the field holding them, so the editor shows it there and in the error summary.
        foreach ($missing as $handle => $filenames) {
            $element->addError($handle, Craft::t('alt-monitor', '{count, plural, =1{This image has} other{These images have}} no alt text: {names}. Add alt text or mark {count, plural, =1{it} other{them}} decorative before publishing.', [
                'count' => count($filenames),
                'names' => implode(', ', $filenames),
            ]));
        }
    }

    /** Whether saving this element now is publishing it, in a way the setting should stop. */
    public function checksOnPublish(ElementInterface $element): bool
    {
        $request = Craft::$app->getRequest();

        return AltMonitor::getInstance()->getSettings()->requireAltToPublish
            && $element instanceof Entry
            && $element->getScenario() === Element::SCENARIO_LIVE
            && !$element->propagating
            && !$element->resaving
            && !$element->getIsRevision()
            && $element->getPrimaryOwnerId() === null
            // Also false for console requests (imports, resave commands).
            && $request->getIsCpRequest();
    }

    /**
     * The entry editor's sidebar box, or '' for people who may not view AltMonitor.
     */
    public function sidebarHtml(ElementInterface $element): string
    {
        $user = Craft::$app->getUser()->getIdentity();

        if (!$element->id || !$user instanceof User || !$user->can(AltMonitor::PERMISSION_VIEW)) {
            return '';
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(CpAsset::class);

        return $view->renderTemplate('alt-monitor/_entry-sidebar', $this->sidebarVariables($element, $user), View::TEMPLATE_MODE_CP);
    }

    /**
     * @return array<string, mixed>
     */
    private function sidebarVariables(ElementInterface $element, User $user): array
    {
        $plugin = AltMonitor::getInstance();
        ['images' => $images, 'hidden' => $hidden] = $this->images($element, $user);
        $attention = array_values(array_filter($images, static fn(EntryImage $image): bool => $image->needsAttention()));
        $problems = count(array_filter($attention, static fn(EntryImage $image): bool => $image->hasProblem()));
        $canGenerate = $user->can(AltMonitor::PERMISSION_GENERATE);
        $describer = $canGenerate ? $plugin->getAiProviders()->describerConfig() : null;
        $canonical = $element->getCanonical(true);

        return [
            'element' => $element,
            // The box is kept per entry, whichever draft of it is open.
            'boxKey' => 'entry-' . $canonical->id . '-' . $element->siteId,
            'images' => $images,
            'attention' => $attention,
            'problems' => $problems,
            'hidden' => $hidden,
            'canApprove' => $user->can(AltMonitor::PERMISSION_APPROVE),
            'describer' => $describer,
            'translator' => $canGenerate ? $describer ?? $plugin->getAiProviders()->translatorConfig() : null,
            'canGenerateOnServer' => $canGenerate && $plugin->getSuggestions()->generatesOnServer(),
            'requireAltToPublish' => $plugin->getSettings()->requireAltToPublish,
            'listUrl' => $user->can('accessPlugin-alt-monitor') ? UrlHelper::cpUrl('alt-monitor/images') : null,
            'editUrl' => $canonical->getCpEditUrl(),
            // Changes when anything the box shows changes, so a refresh after each autosave only
            // replaces the box when it has something new to show.
            'signature' => sha1(Json::encode(array_map(static fn(EntryImage $image): array => [
                $image->key(),
                $image->status->value,
                $image->decorative,
                $image->asset->alt,
                $image->suggestion?->id,
                $image->suggestion?->status->value,
                $image->suggestion?->suggestedAlt,
            ], $images)) . ':' . $hidden),
        ];
    }

    /**
     * @param array<int, string> $found
     */
    private function collect(ElementInterface $element, ?string $topHandle, int $depth, array &$found): void
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return;
        }

        foreach ($layout->getCustomFields() as $field) {
            $handle = $topHandle ?? (string)$field->handle;

            try {
                $value = $element->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }

            if ($field instanceof BaseRelationField) {
                if ($field::elementType() === Asset::class || is_subclass_of($field::elementType(), Asset::class)) {
                    foreach ($this->elementIds($value) as $id) {
                        $found[$id] ??= $handle;
                    }
                }

                // Other relations (entries, categories…) are other content, checked on their own.
                continue;
            }

            if ($field instanceof ElementContainerFieldInterface && $depth < self::MAX_DEPTH) {
                foreach ($this->nestedElements($field, $value, $element) as $nested) {
                    $this->collect($nested, $handle, $depth + 1, $found);
                }
            }

            if (!$field instanceof ElementContainerFieldInterface || !$value instanceof ElementQueryInterface) {
                // Text that may hold reference tags (rich text): as it would be stored.
                $this->collectReferenceTags($field, $value, $element, $handle, $found);
            }
        }
    }

    /**
     * @param array<int, string> $found
     */
    private function collectReferenceTags(FieldInterface $field, mixed $value, ElementInterface $element, string $handle, array &$found): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        try {
            $serialized = $field->serializeValue($value, $element);
        } catch (\Throwable) {
            return;
        }

        $text = is_string($serialized) ? $serialized : Json::encode($serialized);

        if (!str_contains($text, '{asset:')) {
            return;
        }

        preg_match_all(ContentUsageScanner::REFERENCE_TAG, $text, $matches);

        foreach ($matches[1] as $id) {
            $found[(int)$id] ??= $handle;
        }
    }

    /**
     * IDs of the elements a relation field's value holds: posted or eager-loaded values as they
     * are, otherwise as queried (disabled ones too).
     *
     * @return list<int>
     */
    private function elementIds(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            $cached = $value->getCachedResult();

            return $cached !== null
                ? array_map(static fn(ElementInterface $element): int => (int)$element->id, $cached)
                : array_map('intval', (clone $value)->status(null)->ids());
        }

        if ($value instanceof ElementCollection) {
            return array_map('intval', $value->ids()->all());
        }

        return [];
    }

    /**
     * Nested entries of a field (Matrix, or entries in rich text), as being edited.
     *
     * @return list<ElementInterface>
     */
    private function nestedElements(FieldInterface $field, mixed $value, ElementInterface $owner): array
    {
        if ($value instanceof ElementQueryInterface) {
            return array_values($value->getCachedResult() ?? (clone $value)->status(null)->all());
        }

        if ($value instanceof ElementCollection) {
            return array_values($value->all());
        }

        // Fields whose value isn't the nested entries themselves (rich text with entries).
        if (!$owner->id) {
            return [];
        }

        return Entry::find()
            ->ownerId($owner->id)
            ->fieldId($field->id)
            ->siteId($owner->siteId)
            ->status(null)
            ->all();
    }
}
