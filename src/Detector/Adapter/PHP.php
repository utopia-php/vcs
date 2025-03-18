<?php

namespace Utopia\VCS\Detector\Adapter;

use Utopia\VCS\Detector\Adapter;

class PHP extends Adapter
{
    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return ['PHP'];
    }

    public function getRuntime(): string
    {
        return 'php';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['php'];
    }

    /**
     * @return string[]
     */
    public function getFiles(): array
    {
        return ['composer.json', 'composer.lock'];
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
}
