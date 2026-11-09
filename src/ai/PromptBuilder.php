<?php

namespace Tahadudhiya\AltMonitor\ai;

use Craft;
use Tahadudhiya\AltMonitor\errors\AiProviderException;
use Tahadudhiya\AltMonitor\models\ImageContext;

/**
 * Writes the instruction a vision model gets, and turns its answer into alt text.
 *
 * Context (title, caption, where the image is used) is offered to help the model choose what
 * matters, never as something to describe: the model is told to describe only what it sees.
 */
final class PromptBuilder
{
    /** The longest suggestion kept; longer answers are cut at a word boundary. */
    public const MAX_LENGTH = 250;

    /** Characters of each context item given to the model. */
    private const CONTEXT_LENGTH = 120;

    public function build(ImageContext $context): string
    {
        $lines = [
            'Write alt text for this image, for people using a screen reader.',
            'Rules:',
            '- Describe only what is clearly visible in the image. Do not guess names, places, brands, dates, emotions or intentions that cannot be seen.',
            '- Do not invent people, objects, locations or activities.',
            '- If the image contains legible text that matters, include it.',
            '- Be concise: one sentence, at most 125 characters.',
            '- Do not start with "image of", "picture of", "photo of" or similar.',
            '- No keywords, hashtags, quotation marks, labels or commentary.',
            '- Reply with the alt text only.',
        ];
        $hints = [];

        if ($context->title !== null && $context->title !== '') {
            $hints[] = sprintf('The image is titled "%s".', self::clean($context->title));
        }

        foreach (array_slice($context->captions, 0, 1) as $caption) {
            $hints[] = sprintf('Its caption is "%s".', self::clean($caption));
        }

        if ($context->usedOn !== []) {
            $hints[] = sprintf('It is used on: %s.', implode(', ', array_map(static fn(string $title): string => '"' . self::clean($title) . '"', array_slice($context->usedOn, 0, 3))));
        }

        if ($hints !== []) {
            $lines[] = 'Context, which may help decide what matters but must not be described unless it is visible: ' . implode(' ', $hints);
        }

        return implode("\n", $lines);
    }

    /**
     * The alt text in a model's answer.
     *
     * @throws AiProviderException when nothing usable is left.
     */
    public function parse(string $answer): string
    {
        // Reasoning models may think aloud before answering.
        $text = (string)preg_replace('/<think>.*?<\/think>/is', '', $answer);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), static fn(string $line): bool => $line !== ''));
        $text = $lines[0] ?? '';

        $text = (string)preg_replace('/^(?:[-*•>#]+\s*)+/u', '', $text);
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = (string)preg_replace('/^(?:suggested\s+)?(?:alt(?:ernative)?(?:\s+text)?|description|caption)\s*[:\-–]\s*/iu', '', $text);
        $text = trim($text, " \t\"'“”‘’«»");
        $text = (string)preg_replace('/^(?:(?:this|the)\s+(?:image|picture|photo(?:graph)?)\s+(?:shows|depicts|features|contains)\s+|(?:an?\s+)?(?:image|picture|photo(?:graph)?)\s+(?:of|showing)\s+)/iu', '', $text);
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $cut = mb_substr($text, 0, self::MAX_LENGTH);
            $space = mb_strrpos($cut, ' ');
            $text = rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ' ,;:-');
        }

        if ($text === '' || !preg_match('/\p{L}/u', $text)) {
            throw new AiProviderException(Craft::t('alt-monitor', 'The model returned no usable description.'));
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private static function clean(string $value): string
    {
        $value = trim((string)preg_replace('/[\p{C}"]+/u', ' ', strip_tags($value)));

        return mb_substr((string)preg_replace('/\s+/u', ' ', $value), 0, self::CONTEXT_LENGTH);
    }
}
