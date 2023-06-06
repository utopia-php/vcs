<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Detector\Adapter\Dart;
use Utopia\Detector\Adapter\JavaScript;
use Utopia\Detector\Adapter\PHP;
use Utopia\Detector\Adapter\Python;
use Utopia\Detector\Adapter\Ruby;
use Utopia\Detector\DetectorFactory;

class DetectorTest extends TestCase
{
    public function testDetect() {

        $files = ['package.json', 'src/index.js', 'src/components/main.svelte'];
        $languages = ['Javascript'];
        $detectorFactory = new DetectorFactory();

        // Add some detectors to the factory
        $detectorFactory
            ->addDetector(new JavaScript($files, $languages))
            ->addDetector(new PHP($files, $languages))
            ->addDetector(new Python($files, $languages))
            ->addDetector(new Dart($files, $languages))
            ->addDetector(new Ruby($files, $languages));


        var_dump($detectorFactory->detect());

        // Ensure that detect() returns null when no detector matches
        // $this->assertNull($detectorFactory->detect());
    }
}
