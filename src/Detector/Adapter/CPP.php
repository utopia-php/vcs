<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class CPP extends Adapter
{
    public function getLanguages(): array
    {
        return ['C++'];
    }

    public function getRuntime(): string
    {
        return 'cpp';
    }

    public function getFileExtensions(): array
    {
        return ['cpp', 'h', 'hpp', 'cxx', 'cc'];
    }

    public function getFiles(): array
    {
        return ['main.cpp', 'Solution', 'CMakeLists.txt', '.clang-format'];
    }

    public function getInstallCommand(): string
    {
        return 'apt-get install g++';
    }

    public function getBuildCommand(): string
    {
        return 'g++ -o output';
    }

    public function getEntryPoint(): string
    {
        return '';
    }
}
