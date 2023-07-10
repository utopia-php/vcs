<?php

namespace Utopia\Detector;

abstract class Adapter
{
    /**
     * @return string[]
     */
    abstract public function getLanguages(): array;

    abstract public function getRuntime(): string;

    /**
     * @return string[]
     */
    abstract public function getFileExtensions(): array;

    /**
     * @return string[]
     */
    abstract public function getFiles(): array;

    abstract public function getInstallCommand(): ?string;

    abstract public function getBuildCommand(): ?string;

    abstract public function getEntryPoint(): ?string;
}
