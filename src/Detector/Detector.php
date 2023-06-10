<?php

namespace Utopia\Detector;

class Detector
{
    /**
     * @var Adapter[]
     */
    protected array $detectors = [];

    /**
     * @var string[]
     */
    protected array $files;

    /**
     * @var string[]
     */
    protected array $languages;

    public function __construct(array $files = [], array $languages = [])
    {
        $this->files = $files;
        $this->languages = $languages;
    }

    public function addDetector(Adapter $detector): self
    {
        $this->detectors[] = $detector;

        return $this;
    }

    public function detect(): ?string
    {
        // 1. Look for specific files
        foreach ($this->detectors as $detector) {
            $detectorFiles = $detector->getFiles();

            $matches = \array_intersect($detectorFiles, $this->files);
            if (\count($matches) > 0) {
                return $detector->getRuntime();
            }
        }

        // 2. Look for files with extension
        foreach ($this->detectors as $detector) {
            foreach ($this->files as $file) {
                if (\in_array(pathinfo($file, PATHINFO_EXTENSION), $detector->getFileExtensions())) {
                    return $detector->getRuntime();
                }
            }
        }

        // 3. Look for mathch with Git language
        foreach ($this->languages as $language) {
            foreach ($this->detectors as $detector) {
                if ($language === $detector->getLanguage()) {
                    return $detector->getRuntime();
                }
            }
        }

        return null;
    }
}
