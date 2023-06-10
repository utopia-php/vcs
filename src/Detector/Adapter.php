<?php

namespace Utopia\Detector;

abstract class Adapter
{
    abstract public function getLanguage(): string;

    abstract public function getRuntime(): string;

    abstract public function getFileExtensions(): array;

    abstract public function getFiles(): array;

    abstract public function getInstallCommand(): ?string;

    abstract public function getBuildCommand(): ?string;

    abstract public function getEntryPoint(): ?string;
}
