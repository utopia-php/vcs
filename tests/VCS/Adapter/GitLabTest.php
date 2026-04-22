<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitLab;

class GitLabTest extends Base
{
    protected static string $accessToken = '';
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';

    protected function createVCSAdapter(): Git
    {
        return new GitLab(new Cache(new None()));
    }

    public function setUp(): void
    {
        if (empty(static::$accessToken)) {
            $this->setupGitLab();
        }

        if (empty(static::$accessToken)) {
            $this->markTestSkipped('GitLab access token not available');
        }

        $adapter = new GitLab(new Cache(new None()));
        $gitlabUrl = System::getEnv('TESTS_GITLAB_URL', 'http://gitlab:80');

        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: static::$accessToken,
            refreshToken: ''
        );
        $adapter->setEndpoint($gitlabUrl);

        if (empty(static::$owner)) {
            $orgName = 'test-org-' . \uniqid();
            static::$owner = $adapter->createOrganization($orgName);
        }

        $this->vcsAdapter = $adapter;
    }

    protected function setupGitLab(): void
    {
        $tokenFile = '/gitlab-data/token.txt';

        if (file_exists($tokenFile)) {
            $contents = file_get_contents($tokenFile);
            if ($contents !== false) {
                static::$accessToken = trim($contents);
            }
        }
    }


    public function testCreateRepository(): void
    {
        $repositoryName = 'test-create-repository-' . \uniqid();

        $result = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('name', $result);
            $this->assertSame($repositoryName, $result['name']);
            $this->assertFalse($result['visibility'] === 'private');
            $this->assertArrayHasKey('pushed_at', $result);
            $this->assertNotFalse(\strtotime($result['pushed_at']));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepository(): void
    {
        $repositoryName = 'test-get-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $result = $this->vcsAdapter->getRepository(static::$owner, $repositoryName);

            $this->assertIsArray($result);
            $this->assertSame($repositoryName, $result['name']);
            $this->assertArrayHasKey('pushed_at', $result);
            $this->assertNotFalse(\strtotime($result['pushed_at']));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testDeleteRepository(): void
    {
        $repositoryName = 'test-delete-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $result = $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);

        $this->assertTrue($result);
    }

    public function testGetDeletedRepositoryFails(): void
    {
        $repositoryName = 'non-existing-repository-' . \uniqid();

        $this->expectException(\Exception::class);
        $this->vcsAdapter->getRepository(static::$owner, $repositoryName);
    }

    public function testGetPullRequestFromBranch(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGetOwnerName(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testSearchRepositories(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testCreateComment(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testUpdateComment(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGenerateCloneCommand(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGenerateCloneCommandWithCommitHash(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGenerateCloneCommandWithInvalidRepository(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testWebhookPushEvent(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testWebhookPullRequestEvent(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGetEventPush(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGetRepositoryName(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGetComment(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGetPullRequest(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGetPullRequestFiles(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGetPullRequestWithInvalidNumber(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testGetRepositoryTree(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testListBranches(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testListBranchesEmptyRepo(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testListRepositoryLanguages(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }

    public function testListRepositoryContents(): void
    {
        $this->markTestSkipped('Not implemented for GitLab yet');
    }
}
