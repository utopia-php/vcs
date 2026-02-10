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
    protected function createVCSAdapter(): Git
    {
        return new Gitea(new Cache(new None()));
    }

    public function setUp(): void
    {
        $this->vcsAdapter = new Gitea(new Cache(new None()));
        
        // Gitea uses OAuth2 tokens instead of GitHub's installation flow
        // Parameters are mapped for interface compatibility:
        // - GITEA_ACCESS_TOKEN -> installationId parameter
        // - GITEA_REFRESH_TOKEN -> privateKey parameter  
        // - GITEA_URL -> githubAppId parameter
        $accessToken = System::getEnv('GITEA_ACCESS_TOKEN') ?? '';
        $refreshToken = System::getEnv('GITEA_REFRESH_TOKEN') ?? '';
        $giteaUrl = System::getEnv('GITEA_URL') ?? 'http://gitea:3000';
        
        $this->vcsAdapter->initializeVariables($accessToken, $refreshToken, $giteaUrl);
    }

    public function testCreateRepository(): void
    {
        $owner = System::getEnv('GITEA_TEST_OWNER') ?? 'jayesh-vcs';
        $repositoryName = 'test-create-repo-' . time();
        
        // Create repository
        $result = $this->vcsAdapter->createRepository($owner, $repositoryName, false);
        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame($repositoryName, $result['name']);
        $this->assertArrayHasKey('owner', $result);
        $this->assertSame($owner, $result['owner']['login']);
        
        // Cleanup: delete the repository
        // Note: deleteRepository will be implemented in follow-up PR
        // For now, repositories will need to be manually cleaned up
    }

    // Stub methods required by Base class - will be implemented in follow-up PRs
    
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
}