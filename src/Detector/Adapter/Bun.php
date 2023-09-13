<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Bun extends Adapter
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
        return 'bun';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['ts', 'tsx', 'js', 'jsx'];
    }

    /**
     * @return string[]
     */
    public function getFiles(): array
    {
        return ['bun.lockb'];
    }

    public function getInstallCommand(): string
    {
        return 'bun install';
    }

    public function getBuildCommand(): string
    {
        return '';
    }

    public function getEntryPoint(): string
    {
        return 'main.ts';
    }
}
