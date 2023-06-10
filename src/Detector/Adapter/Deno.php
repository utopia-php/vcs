<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Deno extends Adapter
{
    public function getLanguages(): array
    {
        return ['TypeScript'];
    }

    public function getRuntime(): string
    {
        return 'deno';
    }

    public function getFileExtensions(): array
    {
        return ['ts', 'tsx'];
    }

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
