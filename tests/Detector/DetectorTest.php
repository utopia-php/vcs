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
    protected $github;

    public function detect($files, $languages): string
    {
        $detectorFactory = new Detector($files, $languages);

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
        return $runtime;
    }

    public function setUp(): void
    {
        $this->github = new GitHub(new Cache(new None()));
        $privateKey = App::getEnv('GITHUB_PRIVATE_KEY');
        $githubAppId = App::getEnv('GITHUB_APP_IDENTIFIER');
        $installationId = '1234'; //your GitHub App Installation ID here
        $this->github->initialiseVariables($installationId, $privateKey, $githubAppId, 'vermakhushboo');
    }

    public function testLanguageDetection()
    {
        $languageMap = [
            ['vermakhushboo', 'basic-js-crud', 'node'],
            ['appwrite', 'appwrite', 'php'],
            ['joblib', 'joblib', 'python'],
            ['smartherd', 'DartTutorial', 'dart'],
            ['realm', 'realm-swift', 'swift'],
            ['aws', 'aws-sdk-ruby', 'ruby'],
            ['functionaljava', 'functionaljava', 'java'],
            ['Dobiasd', 'FunctionalPlus', 'cpp'],
            ['anthonychu', 'azure-functions-deno-worker', 'deno'],
            ['mono', 'mono-basic', 'dotnet']
        ];

        foreach ($languageMap as [$owner, $repositoryName, $expectedRuntime]) {
            $files = $this->github->listRepositoryContents($owner, $repositoryName);
            $languages = $this->github->getRepositoryLanguages($owner, $repositoryName);
            $runtime = $this->detect($files, $languages);
            $this->assertEquals($expectedRuntime, $runtime);
        }
    }
}
