<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class JavaScript extends Adapter
{
    public function getLanguages(): array
    {
        return ['JavaScript', 'TypeScript'];
    }

    public function getRuntime(): string
    {
        return 'node';
    }

    public function getFiles(): array
    {
        return ['js', 'ts'];
    }

    public function getFileExtensions(): array
    {
        return ['pakcage.json', 'package-lock.json', 'yarn.lock', 'tsconfig.json'];
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
