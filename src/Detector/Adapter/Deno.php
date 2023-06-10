<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Deno extends Adapter
{
    const DETECTOR_DENO = 'Deno';

    const RUNTIME_DENO = 'deno';

    public function getLanguage(): string
    {
        return self::DETECTOR_DENO;
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

    public function getRuntime(): string
    {
        return self::RUNTIME_DENO;
    }

    public function detect(): ?bool
    {
        if (in_array('mod.ts', $this->files)) {
            return true;
        }

        if (in_array('deps.ts', $this->files)) {
            return true;
        }

        if (in_array(self::DETECTOR_DENO, $this->languages)) {
            return true;
        }

        return false;
    }
}
