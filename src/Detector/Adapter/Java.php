<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Java extends Adapter
{
    const DETECTOR_JAVA = 'Java';

    const RUNTIME_JAVA = 'java';

    public function getLanguage(): string
    {
        return self::DETECTOR_JAVA;
    }

    public function getInstallCommand(): string
    {
        return 'mvn install';
    }

    public function getBuildCommand(): string
    {
        return 'mvn package';
    }

    public function getEntryPoint(): string
    {
        return 'Main.java';
    }

    public function getRuntime(): string
    {
        return self::RUNTIME_JAVA;
    }

    public function detect(): ?bool
    {
        if (in_array('pom.xml', $this->files) || in_array('pmd.xml', $this->files)) {
            return true;
        }

        if (in_array('build.gradle', $this->files) || in_array('build.gradle.kts', $this->files)) {
            return true;
        }

        if (in_array(self::DETECTOR_JAVA, $this->languages)) {
            return true;
        }

        return false;
    }
}
