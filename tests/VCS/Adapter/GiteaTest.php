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
    private static string $accessToken = '';
    private static string $owner = '';

    protected function createVCSAdapter(): Git
    {
        return new Gitea(new Cache(new None()));
    }

    public function setUp(): void
    {
        if (empty(self::$accessToken)) {
            $this->setupGitea();
        }

        $this->vcsAdapter = new Gitea(new Cache(new None()));
        $giteaUrl = System::getEnv('GITEA_URL') ?? 'http://gitea:3000';

        $this->vcsAdapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: self::$accessToken,
            refreshToken: ''
        );
        $this->vcsAdapter->setEndpoint($giteaUrl);
        if (empty(self::$owner)) {
            $orgName = 'test-org-' . \uniqid();
            self::$owner = $this->vcsAdapter->createOrganization($orgName);
        }
    }

    private function setupGitea(): void
    {
        $tokenFile = '/data/gitea/token.txt';

        if (file_exists($tokenFile)) {
            $contents = file_get_contents($tokenFile);
            if ($contents !== false) {
                self::$accessToken = trim($contents);
            }
        }
    }

    public function testCreateRepository(): void
    {
        $owner = self::$owner;
        $repositoryName = 'test-repo-' . time();

        $result = $this->vcsAdapter->createRepository($owner, $repositoryName, false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame($repositoryName, $result['name']);
        $this->assertArrayHasKey('owner', $result);
        $this->assertSame($owner, $result['owner']['login']);
        $this->assertTrue($this->vcsAdapter->deleteRepository(self::$owner, $repositoryName));
    }

    public function testGetComment(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetRepository(): void
    {
        $repositoryName = 'test-repo-' . time();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $result = $this->vcsAdapter->getRepository(self::$owner, $repositoryName);

        $this->assertIsArray($result);
        $this->assertSame($repositoryName, $result['name']);
        $this->assertSame(self::$owner, $result['owner']['login']);
        $this->assertTrue($this->vcsAdapter->deleteRepository(self::$owner, $repositoryName));
    }

    public function testGetRepositoryName(): void
    {
        $repositoryName = 'test-repo-' . time();
        $created = $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $repositoryId = (string) $created['id'];
        $result = $this->vcsAdapter->getRepositoryName($repositoryId);

        $this->assertSame($repositoryName, $result);
        $this->assertTrue($this->vcsAdapter->deleteRepository(self::$owner, $repositoryName));
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

    public function testGetEvent(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }
    public function testSearchRepositories(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testDeleteRepository(): void
    {
        $repositoryName = 'test-repo-' . time();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $result = $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);

        $this->assertTrue($result);
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
