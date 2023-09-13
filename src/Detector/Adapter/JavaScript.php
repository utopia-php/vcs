<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class JavaScript extends Adapter
{
    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return ['JavaScript', 'TypeScript'];
    }

    public function getRuntime(): string
    {
        return 'node';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['js', 'ts'];
    }

    /**
     * @return string[]
     */
    public function getFiles(): array
    {
        return ['package-lock.json', 'yarn.lock', 'tsconfig.json'];
    }

    public function getInstallCommand(): string
    {
        return 'npm install';
    }

    public function getBuildCommand(): string
    {
        return 'npm build';
    }

    public function getEntrypoint(): string
    {
        return 'src/index.js';
    }
}
