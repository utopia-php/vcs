<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Swift extends Adapter
{
    const DETECTOR_SWIFT = 'Swift';

    const RUNTIME_SWIFT = 'swift';

    public function getLanguage(): string
    {
        return self::DETECTOR_SWIFT;
    }

    public function getInstallCommand(): string
    {
        return 'swift build';
    }

    public function getBuildCommand(): string
    {
        return '';
    }

    public function getEntryPoint(): string
    {
        return 'main.swift';
    }

    public function getRuntime(): string
    {
        return self::RUNTIME_SWIFT;
    }

    public function detect(): ?bool
    {
        if (in_array('Package.swift', $this->files)) {
            return true;
        }

        if (in_array(self::DETECTOR_SWIFT, $this->languages)) {
            return true;
        }

        if (
            in_array('.xcodeproj', $this->files) ||
            in_array('.xcworkspace', $this->files)
        ) {
            return true;
        }

        if (
            in_array('project.pbxproj', $this->files) ||
            in_array('Podfile', $this->files)
        ) {
            return true;
        }

        // foreach ($this->files as $file) {
        //     if (pathinfo($file, PATHINFO_EXTENSION) === 'swift') {
        //         return true;
        //     }
        // }

        return false;
    }
}
