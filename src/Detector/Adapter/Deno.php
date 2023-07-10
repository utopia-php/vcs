<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Deno extends Adapter
{
    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return ['TypeScript'];
    }

    public function getRuntime(): string
    {
        return 'deno';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['ts', 'tsx'];
    }

    /**
     * @return string[]
     */
    public function getFiles(): array
    {
        return ['mod.ts', 'deps.ts'];
    }

    public function getInstallCommand(): string
    {
        return 'deno install';
    }

    public function getBuildCommand(): string
    {
        return '';
    }

    public function getEntryPoint(): string
    {
        return 'mod.ts';
    }
}
