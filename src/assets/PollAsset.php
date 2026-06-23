<?php

namespace pixelwerft\quickpoll\assets;

use craft\web\AssetBundle;

/**
 * Self-contained front-end widget assets. Deliberately published by the plugin
 * rather than folded into the host's Vite/SCSS build, so polls never depend on
 * (or risk breaking) the global asset pipeline.
 */
class PollAsset extends AssetBundle
{
    public $sourcePath = '@pixelwerft/quickpoll/resources';
    public $depends = [];
    public $js = ['poll.js'];
    // CSS is shipped separately (PollCssAsset) so it can be disabled / replaced.
}
