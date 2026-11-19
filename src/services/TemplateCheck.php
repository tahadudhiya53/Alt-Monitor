<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\web\View;
use Tahadudhiya\AltMonitor\AltMonitor;
use Tahadudhiya\AltMonitor\records\AuditRecord;
use Twig\Markup;
use yii\base\Component;

/**
 * Alt text on the front end: the `altText(asset)` Twig helper, and the check that warns in the
 * browser console about `<img>` tags a page printed without an `alt` attribute.
 *
 * The check runs only in Craft's dev mode, on site pages rendered from a template, and only adds
 * a script when it finds a problem. In production nothing here changes a page. Never writes.
 */
class TemplateCheck extends Component
{
    /**
     * Added by the helper, in dev mode only, next to the `alt=""` it prints for an image that has
     * no alt text in Craft and isn't marked decorative, so the check can name it.
     */
    public const NO_TEXT_MARKER = 'data-altmonitor-no-alt-text';

    /** Tags listed per warning; any more are counted. */
    private const MAX_LISTED = 50;

    /** Characters of each tag shown in a warning. */
    private const MAX_TAG_LENGTH = 300;

    /** @var array<string, true>|null Images marked decorative, by `assetId-siteId` of their audit; read once per page. */
    private ?array $decorative = null;

    /** Whether the check is on: dev mode, and not turned off with `templateCheck`. */
    public function enabled(): bool
    {
        return Craft::$app->getConfig()->getGeneral()->devMode && AltMonitor::getInstance()->getSettings()->templateCheck;
    }

    /**
     * Whether a rendered page template is checked: a full site page (not the control panel, not a
     * console render), from a template that renders HTML rather than JSON, XML or the like.
     */
    public function appliesTo(string $templateMode, string $template): bool
    {
        if (!$this->enabled() || $templateMode !== View::TEMPLATE_MODE_SITE) {
            return false;
        }

        // False for the control panel, and always for console requests (rendering an email, say).
        if (!Craft::$app->getRequest()->getIsSiteRequest()) {
            return false;
        }

        $mimeType = FileHelper::getMimeTypeByExtension($template);

        return $mimeType === null || $mimeType === 'text/html';
    }

    /**
     * The alt attribute for an image, for `{{ altText(asset) }}` in a template: its alt text, or
     * `alt=""` when it is marked decorative in AltMonitor. An image without alt text (or no asset
     * at all) also gets `alt=""`, so screen readers skip it instead of reading its file name; in
     * dev mode it is marked so the check can warn about it.
     */
    public function altAttribute(mixed $asset): Markup
    {
        $text = $this->altText($asset);
        $html = 'alt="' . Html::encode($text ?? '') . '"';

        if ($text === null && $this->enabled()) {
            $html .= ' ' . self::NO_TEXT_MARKER . '="' . ($asset instanceof Asset ? (int)$asset->id : '') . '"';
        }

        return new Markup($html, Craft::$app->charset);
    }

    /**
     * The alt text a page should print for an image: '' when it is decorative, its alt text on
     * one line, or null when it has none (or isn't an asset).
     */
    public function altText(mixed $asset): ?string
    {
        if (!$asset instanceof Asset) {
            return null;
        }

        if ($this->isDecorative($asset)) {
            return '';
        }

        $text = trim((string)preg_replace('/\s+/u', ' ', (string)$asset->alt));

        return $text === '' ? null : $text;
    }

    /**
     * Whether an image is marked decorative, in the site its alt text is audited in. Every
     * decorative image is read in one query the first time a page asks (people mark few), so a
     * page printing many images doesn't query once per image.
     */
    public function isDecorative(Asset $asset): bool
    {
        if (!$asset->id || !$asset->volumeId) {
            return false;
        }

        $siteId = AltMonitor::getInstance()->getAssetScanner()->auditSiteId((int)$asset->volumeId, (int)$asset->siteId);
        $this->decorative ??= array_fill_keys(array_map(
            static fn(array $row): string => $row['assetId'] . '-' . $row['siteId'],
            (new Query())->select(['assetId', 'siteId'])->from(AuditRecord::TABLE)->where(['decorative' => true])->all(),
        ), true);

        return isset($this->decorative[$asset->id . '-' . $siteId]);
    }

    /**
     * The `<img>` tags in rendered HTML with no alt attribute, and those the helper printed for an
     * image with no alt text. Comments, scripts and styles are ignored; `alt` with no value counts
     * as an empty alt attribute, as it does in browsers.
     *
     * @return array{noAttribute: list<string>, noText: list<string>}
     */
    public static function findProblems(string $html): array
    {
        $html = preg_replace(['/<!--.*?-->/s', '/<(script|style)\b[^>]*>.*?<\/\1\s*>/is'], '', $html) ?? $html;
        preg_match_all('/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $html, $matches);
        $found = ['noAttribute' => [], 'noText' => []];

        foreach ($matches[0] as $tag) {
            // Quoted values emptied, so text inside them (title="… alt=…") can't look like one.
            $names = (string)preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $tag);

            if (!self::hasAttribute($names, 'alt')) {
                $found['noAttribute'][] = self::shorten($tag);
            } elseif (self::hasAttribute($names, self::NO_TEXT_MARKER)) {
                $found['noText'][] = self::shorten($tag);
            }
        }

        return $found;
    }

    /**
     * The page with a script before `</body>` that warns in the browser console about the images
     * {@see findProblems()} found; unchanged when it finds none, or when it isn't a full page.
     */
    public static function withWarnings(string $html): string
    {
        $end = strripos($html, '</body>');

        if ($end === false) {
            return $html;
        }

        $found = self::findProblems($html);
        $warnings = [];

        if ($found['noAttribute'] !== []) {
            $warnings[] = self::warning(Craft::t('alt-monitor', 'Images on this page with no alt attribute: {count}. Screen readers read their file name instead. Print the alt text with {helper}, or add alt="" to images that are only decoration.', [
                'count' => count($found['noAttribute']),
                'helper' => '{{ altText(asset) }}',
            ]), $found['noAttribute']);
        }

        if ($found['noText'] !== []) {
            $warnings[] = self::warning(Craft::t('alt-monitor', 'Images on this page with no alt text in Craft: {count}. Add it on the AltMonitor Images page, or mark the image as decorative there.', [
                'count' => count($found['noText']),
            ]), $found['noText']);
        }

        if ($warnings === []) {
            return $html;
        }

        $calls = array_map(static fn(string $warning): string => 'console.warn(' . Json::encode($warning, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');', $warnings);

        return substr_replace($html, '<script>' . implode('', $calls) . '</script>', $end, 0);
    }

    /** Forgets what was read for the current request, as a test or a long-running process may need. */
    public function reset(): void
    {
        $this->decorative = null;
    }

    private static function hasAttribute(string $tagWithoutValues, string $name): bool
    {
        return preg_match('/\s' . preg_quote($name, '/') . '(?=[\s=\/>])/i', $tagWithoutValues) === 1;
    }

    /**
     * @param list<string> $tags
     */
    private static function warning(string $message, array $tags): string
    {
        $lines = ['AltMonitor (dev mode): ' . $message];

        foreach (array_slice($tags, 0, self::MAX_LISTED) as $tag) {
            $lines[] = '• ' . $tag;
        }

        if (count($tags) > self::MAX_LISTED) {
            $lines[] = Craft::t('alt-monitor', '…and {count} more.', ['count' => count($tags) - self::MAX_LISTED]);
        }

        return implode("\n", $lines);
    }

    private static function shorten(string $tag): string
    {
        $tag = (string)preg_replace('/\s+/', ' ', $tag);

        return mb_strlen($tag) > self::MAX_TAG_LENGTH ? mb_substr($tag, 0, self::MAX_TAG_LENGTH - 1) . '…' : $tag;
    }
}
