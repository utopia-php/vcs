<?php

namespace Utopia\Tests\VCS\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Gitea;

class GiteaTest extends Base
{
    private static bool $setupDone = false;
    private static string $accessToken = '';

    protected function createVCSAdapter(): Git
    {
        return new Gitea(new Cache(new None()));
    }

    public function setUp(): void
    {
        if (!self::$setupDone) {
            $this->setupGitea();
            self::$setupDone = true;
        }

        $this->vcsAdapter = new Gitea(new Cache(new None()));
        $giteaUrl = System::getEnv('GITEA_URL') ?? 'http://gitea:3000';

        $this->vcsAdapter->initializeVariables(
            '',                  // installationId
            '',                  // privateKey
            '',                  // appId
            self::$accessToken,  // accessToken
            ''                   // refreshToken
        );
        $this->vcsAdapter->setEndpoint($giteaUrl);
    }

    private function setupGitea(): void
    {
        $tokenFile = '/data/gitea/token.txt';

        if (file_exists($tokenFile)) {
            self::$accessToken = trim(file_get_contents($tokenFile));
        }
    }

    public function testCreateRepository(): void
    {
        $owner = System::getEnv('GITEA_TEST_OWNER');
        $repositoryName = 'test-repo-' . time();

        $result = $this->vcsAdapter->createRepository($owner, $repositoryName, false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame($repositoryName, $result['name']);
        $this->assertArrayHasKey('owner', $result);
        $this->assertSame($owner, $result['owner']['login']);
    }

    public function testGetComment(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetRepositoryName(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetRepositoryTree(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetRepositoryContent(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testListRepositoryContents(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetPullRequest(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGenerateCloneCommand(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGenerateCloneCommandWithCommitHash(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testUpdateComment(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetCommit(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetLatestCommit(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testgetEvent(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }
    public function testSearchRepositories(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testDeleteRepository(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetOwnerName(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetPullRequestFromBranch(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testCreateComment(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testListBranches(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testListRepositoryLanguages(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }
}
