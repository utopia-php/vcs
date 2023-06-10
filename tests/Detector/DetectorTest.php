<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\App;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Detector\Adapter\CPP;
use Utopia\Detector\Adapter\Dart;
use Utopia\Detector\Adapter\Deno;
use Utopia\Detector\Adapter\Dotnet;
use Utopia\Detector\Adapter\Java;
use Utopia\Detector\Adapter\JavaScript;
use Utopia\Detector\Adapter\PHP;
use Utopia\Detector\Adapter\Python;
use Utopia\Detector\Adapter\Ruby;
use Utopia\Detector\Adapter\Swift;
use Utopia\Detector\Detector;
use Utopia\VCS\Adapter\Git\GitHub;

class DetectorTest extends TestCase
{
    public function testDetect()
    {
        $github = new GitHub(new Cache(new None()));
        $privateKey = App::getEnv('GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('GITHUB_APP_IDENTIFIER');
        $installationId = '37569846'; //your GitHub App Installation ID here
        $github->initialiseVariables($installationId, $privateKey, $githubAppId, 'vermakhushboo');

        $files = $github->listRepositoryContents('mxcl', 'PromiseKit');
        $languages = $github->getRepositoryLanguages('mxcl', 'PromiseKit');

        $detectorFactory = new Detector($files, $languages);

        // Add some detectors to the factory
        $detectorFactory
            ->addDetector(new JavaScript())
            ->addDetector(new PHP())
            ->addDetector(new Python())
            ->addDetector(new Dart())
            ->addDetector(new Swift())
            ->addDetector(new Ruby())
            ->addDetector(new Java())
            ->addDetector(new CPP())
            ->addDetector(new Deno())
            ->addDetector(new Dotnet());

        $runtime = $detectorFactory->detect();
        var_dump($runtime);

        // Ensure that detect() returns null when no detector matches
        // $this->assertNull($detectorFactory->detect());
    }
}
