<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Detector\Adapter\Dart;
use Utopia\Detector\Adapter\JavaScript;
use Utopia\Detector\Adapter\PHP;
use Utopia\Detector\Adapter\Python;
use Utopia\Detector\Adapter\Ruby;
use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Detector\Detector;
use Utopia\VCS\Adapter\Git\GitHub;

class DetectorTest extends TestCase
{
    public function testDetect() {
        $github = new GitHub(new Cache(new None()));
        $privateKey = App::getEnv('GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('GITHUB_APP_IDENTIFIER');
        $installationId = '1234'; //your GitHub App Installation ID here
        $github->initialiseVariables($installationId, $privateKey, $githubAppId, 'vermakhushboo');

        $files = $github->listRepositoryContents('appwrite', 'appwrite');
        $languages = $github->getRepositoryLanguages('appwrite', 'appwrite');
        $detectorFactory = new Detector($files, $languages);

        // Add some detectors to the factory
        $detectorFactory
            ->addDetector(new JavaScript())
            ->addDetector(new PHP())
            ->addDetector(new Python())
            ->addDetector(new Dart())
            ->addDetector(new Ruby());


        $runtime = $detectorFactory->detect();

        // Ensure that detect() returns null when no detector matches
        // $this->assertNull($detectorFactory->detect());
    }
}
