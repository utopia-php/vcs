<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git\Gitea;
use Utopia\VCS\Exception\RepositoryNotFound;

class GiteaTest extends Base
{
    protected static string $accessToken = '';
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';
    protected static string $existingUser = 'utopia';
    protected static string $userHandleField = 'login';
    protected static string $eventHeader = 'x-gitea-event';
    protected static string $signatureHeader = 'x-gitea-signature';

    /** @var array<string> */
    protected static array $pullRequestOpenedActions = ['opened', 'synchronized'];

    protected static string $presignedTarballFragment = '.tar.gz?token=';
    protected static string $presignedZipballFragment = '.zip?token=';

    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
    protected static string $avatarDomain = 'gravatar.com';

    protected function setupAdapter(): void
    {
        if (empty(static::$accessToken)) {
            $this->setupGitea();
        }

        $adapter = new Gitea(new Cache(new None()));
        $giteaUrl = System::getEnv('TESTS_GITEA_URL', 'http://gitea:3000');

        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: static::$accessToken,
            refreshToken: ''
        );
        $adapter->setEndpoint($giteaUrl);
        if (empty(static::$owner)) {
            $orgName = 'test-org-' . \uniqid();
            static::$owner = $adapter->createOrganization($orgName);
        }

        $this->vcsAdapter = $adapter;
    }

    protected function setupGitea(): void
    {
        $tokenFile = '/data/gitea/token.txt';

        if (file_exists($tokenFile)) {
            $contents = file_get_contents($tokenFile);
            if ($contents !== false) {
                static::$accessToken = trim($contents);
            }
        }
    }


    public function testGetRepositoryAfterDeleteFails(): void
    {
        $repositoryName = 'test-get-deleted-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);

        $this->expectException(RepositoryNotFound::class);
        $this->vcsAdapter->getRepository(static::$owner, $repositoryName);
    }



    public function testGetCommitAuthorAvatar(): void
    {
        $repositoryName = 'test-get-commit-avatar-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $commit = $this->vcsAdapter->getCommit(static::$owner, $repositoryName, $commitHash);

            $this->assertNotEmpty($commit['commitAuthorAvatar']);
            $this->assertStringContainsString(static::$avatarDomain, $commit['commitAuthorAvatar']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }





    public function testGetEventPush(): void
    {
        $payload = json_encode([
            'ref' => 'refs/heads/' . static::$defaultBranch,
            'before' => 'abc123',
            'after' => 'def456',
            'created' => false,
            'deleted' => false,
            'repository' => [
                'id' => 123,
                'name' => 'test-repo',
                'html_url' => 'http://gitea:3000/test-owner/test-repo',
                'owner' => [
                    'login' => 'test-owner',
                ],
            ],
            'sender' => [
                'login' => 'pusher-user',
                'html_url' => 'http://gitea:3000/pusher-user',
                'avatar_url' => 'http://gitea:3000/avatars/pusher',
            ],
            'head_commit' => [
                'id' => 'def456',
                'message' => 'Test commit message',
                'url' => 'http://gitea:3000/test-owner/test-repo/commit/def456',
                'author' => [
                    'name' => 'Test Author',
                    'email' => 'author@example.com',
                ],
            ],
            'commits' => [
                [
                    'id' => 'def456',
                    'added' => ['file1.txt'],
                    'removed' => ['file2.txt'],
                    'modified' => ['file3.txt'],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('push', $payload);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('branch', $result);
        $this->assertArrayHasKey('commitHash', $result);
        $this->assertArrayHasKey('repositoryName', $result);
        $this->assertArrayHasKey('owner', $result);
        $this->assertArrayHasKey('affectedFiles', $result);

        $this->assertSame(static::$defaultBranch, $result['branch']);
        $this->assertSame('def456', $result['commitHash']);
        $this->assertSame('test-repo', $result['repositoryName']);
        $this->assertSame('test-owner', $result['owner']);
        $this->assertSame('Test commit message', $result['headCommitMessage']);
        $this->assertSame('Test Author', $result['headCommitAuthorName']);
        $this->assertSame('author@example.com', $result['headCommitAuthorEmail']);

        $this->assertIsArray($result['affectedFiles']);
        $this->assertContains('file1.txt', $result['affectedFiles']);
        $this->assertContains('file2.txt', $result['affectedFiles']);
        $this->assertContains('file3.txt', $result['affectedFiles']);
    }

    public function testGetEventPullRequest(): void
    {
        $payload = json_encode([
            'action' => 'opened',
            'number' => 42,
            'pull_request' => [
                'id' => 1,
                'number' => 42,
                'state' => 'open',
                'title' => 'Test PR',
                'head' => [
                    'ref' => 'feature-branch',
                    'sha' => 'abc123',
                    'repo' => [
                        'full_name' => 'test-owner/test-repo',
                    ],
                    'user' => [
                        'login' => 'pr-author',
                    ],
                ],
                'base' => [
                    'ref' => static::$defaultBranch,
                    'sha' => 'def456',
                    'user' => [
                        'login' => 'base-owner',
                    ],
                ],
                'user' => [
                    'login' => 'pr-author',
                    'avatar_url' => 'http://gitea:3000/avatars/pr-author',
                ],
            ],
            'repository' => [
                'id' => 123,
                'name' => 'test-repo',
                'full_name' => 'test-owner/test-repo',
                'html_url' => 'http://gitea:3000/test-owner/test-repo',
                'owner' => [
                    'login' => 'test-owner',
                ],
            ],
            'sender' => [
                'login' => 'sender-user',
                'html_url' => 'http://gitea:3000/sender-user',
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('pull_request', $payload);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('branch', $result);
        $this->assertArrayHasKey('pullRequestNumber', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('commitHash', $result);
        $this->assertArrayHasKey('external', $result);

        $this->assertSame('feature-branch', $result['branch']);
        $this->assertSame(42, $result['pullRequestNumber']);
        $this->assertSame('opened', $result['action']);
        $this->assertSame('abc123', $result['commitHash']);
        $this->assertSame('test-repo', $result['repositoryName']);
        $this->assertSame('test-owner', $result['owner']);
        $this->assertFalse($result['external']);
    }

    public function testGetEventPullRequestExternal(): void
    {
        $payload = json_encode([
            'action' => 'opened',
            'number' => 42,
            'pull_request' => [
                'head' => [
                    'ref' => 'feature-branch',
                    'sha' => 'abc123',
                    'repo' => [
                        'full_name' => 'external-user/forked-repo',
                    ],
                ],
                'base' => [
                    'ref' => static::$defaultBranch,
                ],
                'user' => [
                    'avatar_url' => 'http://gitea:3000/avatars/external',
                ],
            ],
            'repository' => [
                'id' => 123,
                'name' => 'test-repo',
                'full_name' => 'test-owner/test-repo',
                'html_url' => 'http://gitea:3000/test-owner/test-repo',
                'owner' => [
                    'login' => 'test-owner',
                ],
            ],
            'sender' => [
                'html_url' => 'http://gitea:3000/external-user',
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('pull_request', $payload);

        $this->assertTrue($result['external']);
    }
















}
