<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Detector;

class Python extends Detector
{
    const DETECTOR_PYTHON = 'Python';

    const RUNTIME_PYTHON = 'python';

    public function getLanguage(): string
    {
        return self::DETECTOR_PYTHON;
    }

    public function getInstallCommand(): string
    {
        return 'pip install';
    }

    public function getBuildCommand(): string
    {
        return ''; // No build command for Python
    }

    public function getEntryPoint(): string
    {
        return 'main.py'; // Replace with your Python entry point file name
    }

    public function getRuntime(): string
    {
        return self::RUNTIME_PYTHON;
    }

    public function detect(): ?bool
    {
        if (in_array('requirements.txt', $this->files)) {
            return true;
        }

        if (isset($this->languages[self::DETECTOR_PYTHON])) {
            return true;
        }

        // foreach ($this->files as $file) {
        //     if (pathinfo($file, PATHINFO_EXTENSION) === 'py') {
        //         return true;
        //     }
        // }

        return false;
    }
}
