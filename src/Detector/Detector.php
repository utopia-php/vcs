<?php

namespace Utopia\Detector;

class Detector
{
    protected $detectors = [];
    protected $files;
    protected $languages;

    public function __construct(array $files = [], array $languages = [])
    {
        $this->files = $files;
        $this->languages = $languages;
    }

    public function addDetector(Adapter $detector): self
    {
        $detector->setFiles($this->files);
        $detector->setLanguages($this->languages);
        $this->detectors[] = $detector;
        return $this;
    }

    public function detect(): ?string
    {
        foreach ($this->detectors as $detector) {
            if ($detector->detect()) {
                return $detector->getRuntime();
            }
        }
        return null;
    }
}