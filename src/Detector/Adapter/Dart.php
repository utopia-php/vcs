<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Dart extends Adapter
{
    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return ['Dart'];
    }

    public function getRuntime(): string
    {
        return 'dart';
    }

    /**
     * @return string[]
     */
    public function getFileExtensions(): array
    {
        return ['dart'];
    }

    /**
     * @return string[]
     */
    public function getFiles(): array
    {
        return ['pubspec.yaml', 'pubspec.lock'];
    }

    public function getInstallCommand(): string
    {
        return 'dart pub get';
    }

    public function getBuildCommand(): string
    {
        return 'dart build';
    }

    public function getEntryPoint(): string
    {
        return 'lib/main.dart';
    }
}
