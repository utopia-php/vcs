<?php

namespace Utopia\VCS\Detector\Adapter;

use Utopia\VCS\Detector\Adapter;

class Python extends Adapter
{
    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return ['Python'];
    }

    public function getRuntime(): string
    {
        return 'python';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['py'];
    }

    /**
     * @return string[]
     */
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
