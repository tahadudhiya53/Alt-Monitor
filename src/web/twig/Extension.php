<?php

namespace Tahadudhiya\AltMonitor\web\twig;

use Tahadudhiya\AltMonitor\AltMonitor;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * What AltMonitor adds to Twig, for site and control panel templates alike:
 *
 * ```twig
 * <img src="{{ asset.url }}" {{ altText(asset) }}>
 * ```
 *
 * prints the image's alt text as an `alt` attribute, or `alt=""` when it is marked decorative.
 */
class Extension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // Returns Markup (already escaped), so Twig doesn't escape the attribute again.
            new TwigFunction('altText', static fn(mixed $asset = null): Markup => AltMonitor::getInstance()->getTemplateCheck()->altAttribute($asset)),
        ];
    }
}
