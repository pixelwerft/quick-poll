<?php

namespace pixelwerft\quickpoll\assets;

use craft\web\AssetBundle;

/**
 * The base stylesheet, shipped on its own so it can be turned off (setting
 * `loadBaseCss`) or loaded standalone via craft.quickPoll.baseCssUrl when a
 * template override doesn't register the widget asset.
 */
class PollCssAsset extends AssetBundle
{
    public $sourcePath = '@pixelwerft/quickpoll/resources';
    public $depends = [];
    public $css = ['poll.css'];
}
