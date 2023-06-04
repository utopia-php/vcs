<?php

abstract class Detector
{
    protected $files;
    protected $languages;

    public function __construct(array $files, array $languages)
    {
        $this->files = $files;
        $this->languages = $languages;
    }

    abstract public function getLanguage(): ?string;

    abstract public function getRuntime(): ?string;

    abstract public function getInstallCommand(): ?string;

    abstract public function getBuildCommand(): ?string;

    abstract public function getEntryPoint(): ?string;

    abstract public function detect(): ?bool;
}