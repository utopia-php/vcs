<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Gogs;

class GogsTest extends GiteaTest
{
    protected static string $accessToken = '';
    protected static string $owner = '';

    protected string $webhookEventHeader = 'X-Gogs-Event';
    protected string $webhookSignatureHeader = 'X-Gogs-Signature';
    protected string $avatarDomain = 'gravatar.com';
    protected static string $defaultBranch = 'master';

    protected function createVCSAdapter(): Git
    {
        return new Gogs(new Cache(new None()));
    }

    public function setUp(): void
    {
        if (empty(static::$accessToken)) {
            $this->setupGogs();
        }

        $adapter = new Gogs(new Cache(new None()));
        $gogsUrl = System::getEnv('TESTS_GOGS_URL', 'http://gogs:3000');

        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: static::$accessToken,
            refreshToken: ''
        );
        $adapter->setEndpoint($gogsUrl);

        if (empty(static::$owner)) {
            $orgName = 'test-org-' . \uniqid();
            static::$owner = $adapter->createOrganization($orgName);
        }

        $this->vcsAdapter = $adapter;
    }

    protected function setupGogs(): void
    {
        $tokenFile = '/gogs-data/gogs/token.txt';

        if (file_exists($tokenFile)) {
            $contents = file_get_contents($tokenFile);
            if ($contents !== false) {
                static::$accessToken = trim($contents);
            }
        }
    }


    // --- Skip tests for unsupported Gogs features ---

    // Pull request API
    public function testCommentWorkflow(): void
    {
        $this->markTestSkipped('Gogs does not support pull request API');
    }
    public function testGetComment(): void
    {
        $this->markTestSkipped('Gogs does not support pull request API');
    }
    public function testGetPullRequest(): void
    {
        $this->markTestSkipped('Gogs does not support pull request API');
    }
    public function testGetPullRequestWithInvalidNumber(): void
    {
        $this->markTestSkipped('Gogs does not support pull request API');
    }
    public function testGetPullRequestFromBranch(): void
    {
        $this->markTestSkipped('Gogs does not support pull request API');
    }
    public function testGetPullRequestFromBranchNoPR(): void
    {
        $this->markTestSkipped('Gogs does not support pull request API');
    }
    public function testUpdateComment(): void
    {
        $this->markTestSkipped('Gogs does not support pull request API');
    }
    public function testCreateComment(): void
    {
        $this->markTestSkipped('Gogs does not support pull request API');
    }
    public function testWebhookPullRequestEvent(): void
    {
        $this->markTestSkipped('Gogs does not support pull request API');
    }

    // Commit status
    public function testUpdateCommitStatus(): void
    {
        $this->markTestSkipped('Gogs does not support commit status API');
    }
    public function testUpdateCommitStatusWithInvalidCommit(): void
    {
        $this->markTestSkipped('Gogs does not support commit status API');
    }
    public function testUpdateCommitStatusWithNonExistingRepository(): void
    {
        $this->markTestSkipped('Gogs does not support commit status API');
    }

    // Repository languages
    public function testListRepositoryLanguages(): void
    {
        $this->markTestSkipped('Gogs does not support repository languages endpoint');
    }
    public function testListRepositoryLanguagesEmptyRepo(): void
    {
        $this->markTestSkipped('Gogs does not support repository languages endpoint');
    }

    public function testGetPullRequestFiles(): void
    {
        $this->markTestSkipped('Gogs does not support pull request files API');
    }

    public function testListBranchesEmptyRepo(): void
    {
        // The Gogs adapter creates repositories with `auto_init: true` (plus a
        // default README), so a default branch always exists on creation —
        // an empty repository is not reachable through this adapter. This
        // also avoids Gogs' HTTP 500 response from `/branches` on commit-less
        // repos. Same rationale as GitHubTest::testListBranchesEmptyRepo.
        $this->markTestSkipped('Gogs adapter creates repositories with auto_init, so a default branch always exists');
    }
}
