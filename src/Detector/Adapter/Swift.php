<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Swift extends Adapter
{
    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return ['Swift'];
    }

    public function getRuntime(): string
    {
        return 'swift';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['swift', 'xcodeproj', 'xcworkspace'];
    }

    /**
     * @return string[]
     */
    public function getFiles(): array
    {
        return ['Package.swift', 'Podfile', 'project.pbxproj'];
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
}
