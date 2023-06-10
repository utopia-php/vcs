<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class PHP extends Adapter
{
    public function getLanguages(): array
    {
        return ['PHP'];
    }

    public function getRuntime(): string
    {
        return 'php';
    }

    public function getFileExtensions(): array
    {
        return ['php'];
    }

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
