<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git\GitLab;

class GitLabTest extends Base
{
    protected static string $accessToken = '';
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';

    public function setupAdapter(): void
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


    public function testSearchRepositories(): void
    {
        $repositoryName = 'test-search-repositories-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10);

            $this->assertIsArray($result);
            $this->assertNotEmpty($result);

            $names = array_column($result, 'name');
            $this->assertContains($repositoryName, $names);

            foreach ($result as $repo) {
                $this->assertArrayHasKey('id', $repo);
                $this->assertArrayHasKey('name', $repo);
                $this->assertArrayHasKey('private', $repo);
            }
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testSearchRepositoriesWithSearch(): void
    {
        $uniqueId = \uniqid();
        $repositoryName = 'test-search-unique-' . $uniqueId;
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10, $uniqueId);

            $this->assertIsArray($result);
            $this->assertNotEmpty($result);

            $names = array_column($result, 'name');
            $this->assertContains($repositoryName, $names);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetCommitStatuses(): void
    {
        $repositoryName = 'test-get-commit-statuses-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commit = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
            $commitHash = $commit['commitHash'];

            $this->vcsAdapter->updateCommitStatus(
                $repositoryName,
                $commitHash,
                static::$owner,
                'pending',
                'Build started',
                '',
                'ci/test'
            );

            $result = $this->vcsAdapter->getCommitStatuses(static::$owner, $repositoryName, $commitHash);

            $this->assertIsArray($result);
            $this->assertNotEmpty($result);

            foreach ($result as $status) {
                $this->assertArrayHasKey('state', $status);
                $this->assertArrayHasKey('description', $status);
                $this->assertArrayHasKey('target_url', $status);
                $this->assertArrayHasKey('context', $status);
            }
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetCommitStatusesEmptyForNewCommit(): void
    {
        $repositoryName = 'test-get-commit-statuses-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commit = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
            $commitHash = $commit['commitHash'];

            $result = $this->vcsAdapter->getCommitStatuses(static::$owner, $repositoryName, $commitHash);

            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $repositoryName = 'test-clone-tag-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $commit = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
            $commitHash = $commit['commitHash'];

            $this->vcsAdapter->createTag(static::$owner, $repositoryName, 'v1.0.0', $commitHash);

            $directory = '/tmp/test-clone-tag-' . \uniqid();
            $command = $this->vcsAdapter->generateCloneCommand(
                static::$owner,
                $repositoryName,
                'v1.0.0',
                \Utopia\VCS\Adapter\Git::CLONE_TYPE_TAG,
                $directory,
                '/'
            );

            $this->assertIsString($command);
            $this->assertStringContainsString('refs/tags', $command);
            $this->assertStringContainsString('v1.0.0', $command);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testValidateWebhookEvent(): void
    {
        $secret = 'my-secret-token';
        $payload = '{"object_kind":"push"}';

        // Valid token — should return true
        $result = $this->vcsAdapter->validateWebhookEvent($payload, $secret, $secret);
        $this->assertTrue($result);

        // Invalid token — should return false
        $result = $this->vcsAdapter->validateWebhookEvent($payload, 'wrong-token', $secret);
        $this->assertFalse($result);
    }

    public function testWebhookPushEvent(): void
    {
        $repositoryName = 'test-webhook-push-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            // Clear previous requests
            $this->deleteLastWebhookRequest();

            // Create webhook
            $webhookId = $this->vcsAdapter->createWebhook(
                static::$owner,
                $repositoryName,
                System::getEnv('TESTS_REQUEST_CATCHER_URL', 'http://request-catcher:5000'),
                'test-secret',
                ['push']
            );
            $this->assertGreaterThan(0, $webhookId);

            // Trigger push by creating a file
            $this->vcsAdapter->createFile(
                static::$owner,
                $repositoryName,
                'README.md',
                '# Test',
                'Initial commit'
            );

            // Wait for webhook delivery using assertEventually
            $payload = [];
            $this->assertEventually(function () use (&$payload) {
                $data = $this->getLastWebhookRequest();
                $this->assertNotEmpty($data);
                $payload = \json_decode($data['data'] ?? '{}', true);
                $this->assertNotEmpty($payload);
            }, 15000, 1000);

            $this->assertSame('push', $payload['object_kind'] ?? '');
            $this->assertNotEmpty($payload['checkout_sha'] ?? '');

        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testWebhookPullRequestEvent(): void
    {
        $repositoryName = 'test-webhook-mr-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            // Clear previous requests
            $this->deleteLastWebhookRequest();

            // Create webhook
            $webhookId = $this->vcsAdapter->createWebhook(
                static::$owner,
                $repositoryName,
                System::getEnv('TESTS_REQUEST_CATCHER_URL', 'http://request-catcher:5000'),
                'test-secret',
                ['pull_request']
            );
            $this->assertGreaterThan(0, $webhookId);

            // Setup and create MR
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'feature.txt', 'feature', 'Add feature', 'feature');
            $this->vcsAdapter->createPullRequest(static::$owner, $repositoryName, 'Test MR', 'feature', static::$defaultBranch);

            // Wait for webhook delivery
            $payload = [];
            $this->assertEventually(function () use (&$payload) {
                $data = $this->getLastWebhookRequest();
                $this->assertNotEmpty($data);
                $payload = \json_decode($data['data'] ?? '{}', true);
                $this->assertNotEmpty($payload);
            }, 15000, 1000);

            $this->assertSame('merge_request', $payload['object_kind'] ?? '');
            $this->assertContains($payload['object_attributes']['action'] ?? '', ['open', 'update']);

        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetEventPush(): void
    {
        $payload = json_encode([
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'checkout_sha' => 'abc123',
            'project' => [
                'name' => 'test-repo',
                'namespace' => 'test-org',
            ],
            'commits' => [
                [
                    'message' => 'Test commit',
                    'url' => 'http://example.com/commit/abc123',
                    'author' => ['name' => 'Test User'],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('Push Hook', $payload);

        $this->assertIsArray($result);
        $this->assertSame('push', $result['type']);
        $this->assertSame('main', $result['branch']);
        $this->assertSame('abc123', $result['commitHash']);
        $this->assertSame('Test commit', $result['commitMessage']);
        $this->assertSame('Test User', $result['commitAuthor']);
        $this->assertSame('test-repo', $result['name']);
    }

    public function testGetEventPullRequest(): void
    {
        $payload = json_encode([
            'object_kind' => 'merge_request',
            'project' => [
                'name' => 'test-repo',
                'namespace' => 'test-org',
            ],
            'object_attributes' => [
                'iid' => 1,
                'title' => 'Test MR',
                'action' => 'open',
                'source_branch' => 'feature',
                'target_branch' => 'main',
                'url' => 'http://example.com/mr/1',
                'last_commit' => [
                    'id' => 'abc123',
                    'message' => 'Test commit',
                    'url' => 'http://example.com/commit/abc123',
                    'author' => ['name' => 'Test User'],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('Merge Request Hook', $payload);

        $this->assertIsArray($result);
        $this->assertSame('pull_request', $result['type']);
        $this->assertSame('feature', $result['branch']);
        $this->assertSame('open', $result['action']);
        $this->assertSame(1, $result['pullRequestNumber']);
        $this->assertSame('Test MR', $result['pullRequestTitle']);
        $this->assertSame('abc123', $result['commitHash']);
    }

    public function testGetEventUnknown(): void
    {
        $result = $this->vcsAdapter->getEvent('Unknown Hook', '{}');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCreateWebhook(): void
    {
        $repositoryName = 'test-create-webhook-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $webhookId = $this->vcsAdapter->createWebhook(
                static::$owner,
                $repositoryName,
                'http://example.com/webhook',
                'secret-token',
                ['push', 'pull_request']
            );

            $this->assertIsInt($webhookId);
            $this->assertGreaterThan(0, $webhookId);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListBranchesEmptyRepo(): void
    {
        $repositoryName = 'test-list-branches-empty-' . \uniqid();

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

            $branches = $this->vcsAdapter->listBranches(static::$owner, $repositoryName);

            $this->assertIsArray($branches);
            $this->assertEmpty($branches);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetEventPushMatchesCheckoutSha(): void
    {
        $payload = json_encode([
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'checkout_sha' => 'def456',
            'project' => [
                'name' => 'test-repo',
                'namespace' => 'test-org',
            ],
            'commits' => [
                [
                    'id' => 'abc123',
                    'message' => 'Older commit',
                    'url' => 'http://example.com/commit/abc123',
                    'author' => ['name' => 'Old Author'],
                ],
                [
                    'id' => 'def456',
                    'message' => 'Head commit',
                    'url' => 'http://example.com/commit/def456',
                    'author' => ['name' => 'Head Author'],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('Push Hook', $payload);

        $this->assertIsArray($result);
        $this->assertSame('def456', $result['commitHash']);
        $this->assertSame('Head Author', $result['commitAuthor']);
        $this->assertSame('Head commit', $result['commitMessage']);
        $this->assertSame('http://example.com/commit/def456', $result['commitUrl']);
    }

    public function testValidateWebhookEventUsesPlainToken(): void
    {
        $secret = 'my-secret-token';
        $payload = '{"object_kind":"push"}';

        $this->assertTrue(
            $this->vcsAdapter->validateWebhookEvent($payload, $secret, $secret)
        );

        $hmacSignature = hash_hmac('sha256', $payload, $secret);
        $this->assertFalse(
            $this->vcsAdapter->validateWebhookEvent($payload, $hmacSignature, $secret)
        );

        $this->assertFalse(
            $this->vcsAdapter->validateWebhookEvent($payload, 'wrong-token', $secret)
        );
    }

    public function testCreateRepositoryWithInvalidName(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->createRepository(static::$owner, 'invalid name with spaces', false);
    }
}
