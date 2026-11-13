<?php

namespace Tahadudhiya\AltMonitor\ai;

use Craft;
use craft\models\Site;
use Tahadudhiya\AltMonitor\AltMonitor;

/**
 * Which model translates the browser model's English descriptions into a site's language.
 *
 * Florence-2 only writes English. For sites in another language the browser translates each
 * description with an Opus-MT model (Helsinki-NLP, University of Helsinki; Apache 2.0, en→de
 * CC-BY 4.0), converted to ONNX by Xenova, one model per language. Languages without one get
 * English suggestions, marked as such ({@see \Tahadudhiya\AltMonitor\models\Suggestion::needsTranslation()}).
 */
final class Translations
{
    /**
     * Per language (ISO 639-1): the model and its download in MB (q8 weights, tokenizer, config).
     * Only models translating into one language; Opus-MT's Japanese model is left out (trained on
     * Bible text only, its captions read badly).
     */
    public const MODELS = [
        'af' => ['Xenova/opus-mt-en-af', 111],
        'ar' => ['Xenova/opus-mt-en-ar', 118],
        'cs' => ['Xenova/opus-mt-en-cs', 117],
        'da' => ['Xenova/opus-mt-en-da', 113],
        'de' => ['Xenova/opus-mt-en-de', 112],
        'es' => ['Xenova/opus-mt-en-es', 119],
        'fi' => ['Xenova/opus-mt-en-fi', 119],
        'fr' => ['Xenova/opus-mt-en-fr', 113],
        'hi' => ['Xenova/opus-mt-en-hi', 117],
        'hu' => ['Xenova/opus-mt-en-hu', 117],
        'id' => ['Xenova/opus-mt-en-id', 108],
        'it' => ['Xenova/opus-mt-en-it', 136],
        'nl' => ['Xenova/opus-mt-en-nl', 122],
        'ro' => ['Xenova/opus-mt-en-ro', 113],
        'ru' => ['Xenova/opus-mt-en-ru', 118],
        'sv' => ['Xenova/opus-mt-en-sv', 110],
        'uk' => ['Xenova/opus-mt-en-uk', 117],
        'vi' => ['Xenova/opus-mt-en-vi', 107],
        'xh' => ['Xenova/opus-mt-en-xh', 115],
        'zh' => ['Xenova/opus-mt-en-zh', 119],
    ];

    /**
     * The language suggestions for a site are written in: the one chosen for it in AltMonitor's
     * settings ({@see \Tahadudhiya\AltMonitor\models\Settings::$siteLanguages}), or else the
     * site's own language.
     */
    public static function languageFor(Site $site): string
    {
        $chosen = AltMonitor::getInstance()->getSettings()->siteLanguages[$site->uid] ?? '';

        return $chosen !== '' ? $chosen : $site->language;
    }

    /**
     * The languages a site's suggestions can be chosen to be in: English (what the models write)
     * and every language a translation model covers.
     *
     * @return list<string>
     */
    public static function choices(): array
    {
        return ['en', ...array_keys(self::MODELS)];
    }

    /** The primary subtag of a language (“de” for “de-CH”). */
    public static function primary(string $language): string
    {
        return (preg_split('/[-_]/', strtolower(trim($language))) ?: [''])[0];
    }

    /** Whether a Craft site language (“en”, “en-GB”…) is English. */
    public static function isEnglish(string $language): bool
    {
        return self::primary($language) === 'en';
    }

    /**
     * The translation for a Craft site language (“de-CH”, “fr_FR”…), or null for English and
     * languages no model covers.
     *
     * @return array{model: string, language: string, megabytes: int}|null
     */
    public static function forLanguage(string $language): ?array
    {
        $parts = preg_split('/[-_]/', strtolower(trim($language))) ?: [];
        $primary = $parts[0] ?? '';

        // The Chinese model writes Simplified Chinese.
        if ($primary === 'zh' && array_intersect(array_slice($parts, 1), ['hant', 'tw', 'hk', 'mo'])) {
            return null;
        }

        if (!isset(self::MODELS[$primary])) {
            return null;
        }

        [$model, $megabytes] = self::MODELS[$primary];

        return ['model' => $model, 'language' => $primary, 'megabytes' => $megabytes];
    }

    /**
     * Every site whose suggestion language has a translation model, by site ID.
     *
     * @return array<int, array{model: string, language: string, megabytes: int}>
     */
    public static function forSites(): array
    {
        $sites = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $translation = self::forLanguage(self::languageFor($site));

            if ($translation !== null) {
                $sites[(int)$site->id] = $translation;
            }
        }

        return $sites;
    }

    /** A language's name in the control panel's language, e.g. “German” for “de”. */
    public static function languageName(string $language): string
    {
        $locale = Craft::$app->getI18n()->getLocaleById($language);

        return $locale->getDisplayName(Craft::$app->language) ?: $language;
    }
}
