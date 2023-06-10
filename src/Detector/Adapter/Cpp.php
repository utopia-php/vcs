<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class CPP extends Adapter
{
    const DETECTOR_CPP = 'C++';

    const RUNTIME_CPP = 'cpp';

    public function getLanguage(): string
    {
        return self::DETECTOR_CPP;
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

    public function getRuntime(): string
    {
        return self::RUNTIME_CPP;
    }

    public function detect(): ?bool
    {
        if (in_array('main.cpp', $this->files)) {
            return true;
        }

        if (in_array('Makefile', $this->files) || in_array('Solution', $this->files) || in_array('CMakeLists.txt', $this->files) || in_array('.clang-format', $this->files)) {
            return true;
        }

        if (in_array(self::DETECTOR_CPP, $this->languages)) {
            return true;
        }

        // foreach ($this->files as $file) {
        //     $extension = pathinfo($file, PATHINFO_EXTENSION);
        //     if (in_array($extension, ['cpp', 'cxx', 'cc', 'c++'])) {
        //         return true;
        //     }
        // }

        return false;
    }
}
