<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Detector\Adapter\Bun;
use Utopia\VCS\Detector\Adapter\CPP;
use Utopia\VCS\Detector\Adapter\Dart;
use Utopia\VCS\Detector\Adapter\Deno;
use Utopia\VCS\Detector\Adapter\Dotnet;
use Utopia\VCS\Detector\Adapter\Java;
use Utopia\VCS\Detector\Adapter\JavaScript;
use Utopia\VCS\Detector\Adapter\PHP;
use Utopia\VCS\Detector\Adapter\Python;
use Utopia\VCS\Detector\Adapter\Ruby;
use Utopia\VCS\Detector\Adapter\Swift;
use Utopia\VCS\Detector\Detector;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

class DetectorTest extends TestCase
{
    protected GitHub $github;

    /**
     * @param array<string> $files
     * @param array<string> $languages
     */
    public function detect($files, $languages): ?string
    {
        $detectorFactory = new Detector($files, $languages);

        $detectorFactory
            ->addDetector(new JavaScript())
            ->addDetector(new Bun())
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
        $privateKey = System::getEnv('PRIVATE_KEY') ?? '';
        $githubAppId = System::getEnv('APP_IDENTIFIER') ?? '';
        $installationId = System::getEnv('INSTALLATION_ID') ?? '';
        $this->github->initializeVariables($installationId, $privateKey, $githubAppId);
    }

    public function testLanguageDetection(): void
    {
        // test for SUCCESS
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
            ['mono', 'mono-basic', 'dotnet'],
            ['vermakhushboo', 'bun-function', 'bun'],
            ['cytoscape', 'cytoscape.js', 'node']
        ];

        foreach ($languageMap as [$owner, $repositoryName, $expectedRuntime]) {
            $files = $this->github->listRepositoryContents($owner, $repositoryName);
            $files = \array_column($files, 'name');
            $languages = $this->github->listRepositoryLanguages($owner, $repositoryName);
            $runtime = $this->detect($files, $languages);
            $this->assertEquals($expectedRuntime, $runtime);
        }

        // test for FAILURE
        $files = $this->github->listRepositoryContents('', '');
        $files = \array_column($files, 'name');
        $languages = $this->github->listRepositoryLanguages('', '');
        $runtime = $this->detect($files, $languages);
        $this->assertEquals(null, $runtime);
    }
}
