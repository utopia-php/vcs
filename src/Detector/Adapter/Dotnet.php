<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Dotnet extends Adapter
{
    const DETECTOR_DOTNET = '.NET';

    const RUNTIME_DOTNET = 'dotnet';

    public function getLanguage(): string
    {
        return self::DETECTOR_DOTNET;
    }

    public function getInstallCommand(): string
    {
        return 'dotnet restore';
    }

    public function getBuildCommand(): string
    {
        return 'dotnet build';
    }

    public function getEntryPoint(): string
    {
        return 'Program.cs';
    }

    public function getRuntime(): string
    {
        return self::RUNTIME_DOTNET;
    }

    public function detect(): ?bool
    {
        if (in_array('Program.cs', $this->files)) {
            return true;
        }

        if (in_array('Function.csproj', $this->files)) {
            return true;
        }

        if (in_array('Solution.sln', $this->files)) {
            return true;
        }

        if (in_array('web.config', $this->files)) {
            return true;
        }

        if (in_array(self::DETECTOR_DOTNET, $this->languages)) {
            return true;
        }

        return false;
    }
}
