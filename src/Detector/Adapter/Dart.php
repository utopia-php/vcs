<?php

namespace Utopia\Detector\Adapter;

use Utopia\Detector\Adapter;

class Dart extends Adapter
{
    public function getLanguages(): array
    {
        return ['Dart'];
    }

    public function getRuntime(): string
    {
        return 'dart';
    }

    public function getFileExtensions(): array
    {
        return ['dart'];
    }

    public function getFiles(): array
    {
        return ['pubspec.yaml', 'pubspec.lock'];
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
}
