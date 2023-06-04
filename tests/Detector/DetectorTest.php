<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use DetectorFactory;
use Dart;
use JavaScript;
use PHP;
use Python;
use Ruby;

class DetectorTest extends TestCase
{
    public function testDetect(){

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

        // // Ensure that detect() returns null when no detector matches
        // $this->assertNull($detectorFactory->detect());
    }
}
