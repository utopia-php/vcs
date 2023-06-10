<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Python extends Adapter
{
    public function getLanguages(): array
    {
        return ['Python'];
    }

    public function getRuntime(): string
    {
        return 'python';
    }

    public function getFileExtensions(): array
    {
        return ['py'];
    }

    public function getFiles(): array
    {
        return ['requirements.txt', 'setup.py'];
    }

    public function getInstallCommand(): string
    {
        return 'pip install';
    }

    public function getBuildCommand(): string
    {
        return '';
    }

    public function getEntryPoint(): string
    {
        return 'main.py';
    }
}
