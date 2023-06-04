<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Detector;

class JavaScript extends Detector
{
    const DETECTOR_JAVASCRIPT = 'JavaScript';

    const RUNTIME_NODE = 'node';

    public function getLanguage(): string
    {
        return self::DETECTOR_JAVASCRIPT;
    }

    public function getInstallCommand(): string
    {
        return 'npm install';
    }

    public function getBuildCommand(): string
    {
        return 'npm build';
    }

    public function getEntryPoint(): string
    {
        return 'src/index.js';
    }

    public function getRuntime(): string
    {
        return self::RUNTIME_NODE;
    }

    public function detect(): ?bool
    {
        if (in_array('package.json', $this->files)) {
            return true;
        }

        if (isset($this->languages[self::DETECTOR_JAVASCRIPT])) {
            return true;
        }

        if (
            in_array('src/index.js', $this->files) ||
            in_array('webpack.config.js', $this->files) ||
            in_array('.babelrc', $this->files) ||
            in_array('.eslintrc.js', $this->files)
        ) {
            return true;
        }

        if (
            in_array('package-lock.json', $this->files) ||
            in_array('yarn.lock', $this->files)
        ) {
            return true;
        }

        // Check if any JavaScript files are present in the project
        // foreach ($this->files as $file) {
        //     if (pathinfo($file, PATHINFO_EXTENSION) === 'js') {
        //         return true;
        //     }
        // }

        return false;
    }
}
