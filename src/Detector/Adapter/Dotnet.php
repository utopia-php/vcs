<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Dotnet extends Adapter
{
    public function getLanguages(): array
    {
        return ['C#', 'Visual Basic .NET'];
    }

    public function getRuntime(): string
    {
        return 'dotnet';
    }

    public function getFileExtensions(): array
    {
        return ['cs', 'vb', 'sln', 'csproj', 'vbproj'];
    }

    public function getFiles(): array
    {
        return ['Program.cs', 'Solution.sln', 'Function.csproj', 'Program.vb'];
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
}
