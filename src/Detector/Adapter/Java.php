<?php

namespace Utopia\VCS\Detector\Adapter;

use Utopia\VCS\Detector\Adapter;

class Java extends Adapter
{
    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return ['Java'];
    }

    public function getRuntime(): string
    {
        return 'java';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['java', 'class', 'jar'];
    }

    /**
     * @return string[]
     */
    public function getFiles(): array
    {
        return ['pom.xml', 'pmd.xml', 'build.gradle', 'build.gradle.kts'];
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
}
