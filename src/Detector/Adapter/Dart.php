<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Detector;

class Dart extends Detector
{
    const DETECTOR_DART = 'Dart';

    const RUNTIME_DART = 'dart';

    public function getLanguage(): string
    {
        return self::DETECTOR_DART;
    }

    public function getInstallCommand(): string
    {
        return 'flutter pub get';
    }

    public function getBuildCommand(): string
    {
        return 'flutter build';
    }

    public function getEntryPoint(): string
    {
        return 'lib/main.dart';
    }

    public function getRuntime(): string
    {
        return self::RUNTIME_DART;
    }

    public function detect(): bool
    {
        if (in_array('pubspec.yaml', $this->files)) {
            return true;
        }

        if (isset($this->languages[self::DETECTOR_DART])) {
            return true;
        }

        if (
            in_array('lib/main.dart', $this->files) ||
            in_array('pubspec.lock', $this->files)
        ) {
            return true;
        }

        // foreach ($this->files as $file) {
        //     if (pathinfo($file, PATHINFO_EXTENSION) === 'dart') {
        //         return true;
        //     }
        // }

        return false;
    }
}
