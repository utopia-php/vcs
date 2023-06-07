<?php

namespace Utopia\Detector;

abstract class Detector
{
    protected $files;
    protected $languages;

    abstract public function getLanguage(): ?string;

    abstract public function getRuntime(): ?string;

    abstract public function getInstallCommand(): ?string;

    abstract public function getBuildCommand(): ?string;

    abstract public function getEntryPoint(): ?string;

    abstract public function detect(): ?bool;

    public function setFiles(array $files): void
    {
        $this->files = $files;
    }

    public function setLanguages(array $languages): void
    {
        $this->languages = $languages;
    }
}