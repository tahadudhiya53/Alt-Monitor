<?php

namespace Tahadudhiya\AltMonitor\ai;

use Craft;
use craft\elements\Asset;
use craft\helpers\Image;
use Tahadudhiya\AltMonitor\errors\AiProviderException;

/**
 * Turns an image asset into the small JPEG a vision model reads: sent to Ollama, or served to the
 * browser that describes it.
 *
 * The file is read through the asset's volume, so it works for any filesystem, and copied to
 * Craft's temp directory only while it is being resized; the copy is always removed. Nothing is
 * read through a URL, so private volumes stay private.
 */
class ImageProcessor
{
    /** Longest edge, in pixels, of the image sent. Vision models rarely use more. */
    public const MAX_DIMENSION = 1024;

    /** Files larger than this are not processed, in bytes. */
    public const MAX_FILE_SIZE = 40 * 1024 * 1024;

    /**
     * @return string The resized image, as a base64-encoded JPEG.
     * @throws AiProviderException
     */
    public function prepare(Asset $asset): string
    {
        return base64_encode($this->jpeg($asset));
    }

    /**
     * @return string The resized image's JPEG bytes.
     * @throws AiProviderException
     */
    public function jpeg(Asset $asset): string
    {
        if ($asset->kind !== Asset::KIND_IMAGE || !Image::canManipulateAsImage((string)$asset->getExtension())) {
            throw new AiProviderException(Craft::t('alt-monitor', 'This type of image can’t be sent to the model.'));
        }

        if ($asset->size !== null && $asset->size > self::MAX_FILE_SIZE) {
            throw new AiProviderException(Craft::t('alt-monitor', 'The image is too large for the model.'));
        }

        $source = null;
        $target = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . 'altmonitor-' . bin2hex(random_bytes(8)) . '.jpg';

        try {
            $source = $asset->getCopyOfFile();
            $images = Craft::$app->getImages();

            if (!$images->checkMemoryForImage($source)) {
                throw new AiProviderException(Craft::t('alt-monitor', 'There isn’t enough memory to resize this image.'));
            }

            $image = $images->loadImage($source, true, self::MAX_DIMENSION);
            $image->scaleToFit(self::MAX_DIMENSION, self::MAX_DIMENSION, false);

            if (!$image->saveAs($target)) {
                throw new AiProviderException(Craft::t('alt-monitor', 'The image could not be read or resized.'));
            }

            return (string)file_get_contents($target);
        } catch (AiProviderException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Craft::warning(sprintf('Asset %d could not be prepared for the AI model: %s', $asset->id, $exception->getMessage()), __METHOD__);

            throw new AiProviderException(Craft::t('alt-monitor', 'The image could not be read or resized.'), false, $exception);
        } finally {
            foreach ([$source, $target] as $path) {
                if ($path !== null && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }
}
