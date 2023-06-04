<?php

class DetectorFactory
{
    protected $detectors = [];

    public function __construct(array $detectors = [])
    {
        $this->detectors = $detectors;
    }

    public function addDetector(Detector $detector): self
    {
        $this->detectors[] = $detector;
        return $this;
    }

    public function detect(): ?Detector
    {
        foreach ($this->detectors as $detector) {
            if ($detector->detect()) {
                return $detector->getRuntime();
            }
        }
        return null;
    }
}