<?php

namespace Tahadudhiya\AltMonitor\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The few styles and scripts AltMonitor's pages add to Craft's own: dashboard cards, scan
 * progress polling, and the settings page's connection test. Plain files, no build step.
 */
class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CraftCpAsset::class];
        $this->css = ['cp.css'];
        $this->js = ['cp.js'];

        parent::init();
    }
}
