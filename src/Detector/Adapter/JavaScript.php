<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class JavaScript extends Adapter
{
    const DETECTOR_JAVASCRIPT = 'JavaScript';

    const RUNTIME_NODE = 'node';

    const FILE_EXTENSIONS = ['js'];

    const FILES = ['pakcage.json', 'package-lock.json', 'yarn.lock'];

    public function getLanguage(): string
    {
        return self::DETECTOR_JAVASCRIPT;
    }

    public function getRuntime(): string
    {
        return self::RUNTIME_NODE;
    }

    public function getFiles(): array
    {
        return self::FILES;
    }

    public function getFileExtensions(): array
    {
        return self::FILE_EXTENSIONS;
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
