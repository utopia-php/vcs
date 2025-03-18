<?php

namespace Utopia\VCS\Detector\Adapter;

use Utopia\VCS\Detector\Adapter;

class CPP extends Adapter
{
    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return ['C++'];
    }

    public function getRuntime(): string
    {
        return 'cpp';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['cpp', 'h', 'hpp', 'cxx', 'cc'];
    }

    /**
     * @return string[]
     */
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
