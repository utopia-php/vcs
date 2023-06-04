<?php

class PHP extends Detector
{
    const DETECTOR_PHP = 'PHP';

    const RUNTIME_PHP = 'php';

    public function getLanguage(): string
    {
        return self::DETECTOR_PHP;
    }

    public function getInstallCommand(): string
    {
        return 'composer install';
    }

    public function getBuildCommand(): string
    {
        return '';
    }

    public function getEntryPoint(): string
    {
        return 'index.php';
    }

    public function getRuntime(): string
    {
        return self::RUNTIME_PHP;
    }

    public function detect(): ?bool
    {
        if (in_array('composer.json', $this->files)) {
            return true;
        }

        if (isset($this->languages[self::DETECTOR_PHP])) {
            return true;
        }

        // foreach ($this->files as $file) {
        //     if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        //         return $this;
        //     }
        // }
        
        return false;
    }
}
