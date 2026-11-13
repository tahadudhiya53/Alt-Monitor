<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\elements\Asset;
use craft\elements\User;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\errors\AltTextException;
use yii\base\Component;

/**
 * Saves alt text to assets: the one place AltMonitor changes content.
 *
 * Every save goes through Craft's element saving, so validation, events, propagation to other
 * sites and revisions behave exactly as when the asset is edited by hand. The asset must exist,
 * be an image, and be one the user may save.
 */
class AltTextEditor extends Component
{
    /** The longest alt text accepted. */
    public const MAX_LENGTH = 1000;

    /**
     * Alt text as it will be saved: plain text on one line.
     *
     * @throws AltTextException when it is too long.
     */
    public static function sanitize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = trim((string)preg_replace(['/[\p{Cc}\p{Cf}]+/u', '/\s+/u'], ' ', $text));

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw new AltTextException(Craft::t('alt-monitor', 'Alt text can be at most {max, number} characters long.', ['max' => self::MAX_LENGTH]));
        }

        return $text;
    }

    /**
     * The image a user may edit, loaded in the given site.
     *
     * @throws AltTextException when there is no such image, or the user may not save it.
     */
    public function editableImage(int $assetId, int $siteId, User $user): Asset
    {
        if ($assetId <= 0 || Craft::$app->getSites()->getSiteById($siteId) === null) {
            throw new AltTextException(Craft::t('alt-monitor', 'There is no such image.'));
        }

        $asset = Asset::find()->id($assetId)->siteId($siteId)->status(null)->one();

        if (!$asset instanceof Asset) {
            throw new AltTextException(Craft::t('alt-monitor', 'There is no such image.'));
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            throw new AltTextException(Craft::t('alt-monitor', 'Only images are managed here.'));
        }

        if (!AltMonitor::getInstance()->getAssetScanner()->userMay($asset, $user, true)) {
            throw new AltTextException(Craft::t('alt-monitor', 'You can’t edit this image.'));
        }

        return $asset;
    }

    /**
     * Saves an image's alt text, and refreshes its audit.
     *
     * @throws AltTextException with a message for the user when it can't be saved.
     */
    public function save(int $assetId, int $siteId, string $text, User $user): Asset
    {
        $text = self::sanitize($text);
        $asset = $this->editableImage($assetId, $siteId, $user);

        if ((string)$asset->alt === $text) {
            return $asset;
        }

        $asset->alt = $text;

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new AltTextException(Craft::t('alt-monitor', 'The image could not be saved: {errors}', [
                'errors' => implode(' ', $asset->getFirstErrors()) ?: Craft::t('alt-monitor', 'Craft didn’t allow it.'),
            ]));
        }

        $plugin = AltMonitor::getInstance();
        $plugin->getAssetScanner()->auditAsset($asset);
        $plugin->getAudits()->markAltSaved($assetId, $siteId);

        return $asset;
    }
}
