<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Java extends Adapter
{
    public function getLanguages(): array
    {
        return ['Java'];
    }

    public function getRuntime(): string
    {
        return 'java';
    }

    public function getFileExtensions(): array
    {
        return ['java', 'class', 'jar'];
    }

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
