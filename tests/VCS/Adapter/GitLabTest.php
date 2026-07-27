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

    protected function setupAdapter(): void
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

    public function testSearchRepositoriesIncludesCreatedRepository(): void
    {
        $repositoryName = 'test-search-repositories-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('items', $result);
            $this->assertNotEmpty($result['items']);

            $names = array_column($result['items'], 'name');
            $this->assertContains($repositoryName, $names);

            foreach ($result['items'] as $repo) {
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
            $this->assertArrayHasKey('items', $result);
            $this->assertNotEmpty($result['items']);

            $names = array_column($result['items'], 'name');
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

        // GitLab sends the secret verbatim rather than an HMAC of the payload
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

            // GitLab queues hook deliveries, so allow well over the default wait
            $payload = [];
            $this->assertEventually(function () use (&$payload) {
                $data = $this->getLastWebhookRequest();
                $this->assertNotEmpty($data);
                $payload = \json_decode($data['data'] ?? '{}', true);
                $this->assertNotEmpty($payload);
            }, 30000, 1000);

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
            }, 30000, 1000);

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
            'before' => 'before123',
            'after' => 'abc123',
            'checkout_sha' => 'abc123',
            'user_avatar' => 'http://example.com/avatar.png',
            'project' => [
                'id' => 123,
                'name' => 'test-repo',
                'namespace' => 'test-org',
                'web_url' => 'http://example.com/test-org/test-repo',
            ],
            'commits' => [
                [
                    'id' => 'abc123',
                    'message' => 'Test commit',
                    'url' => 'http://example.com/commit/abc123',
                    'author' => ['name' => 'Test User', 'email' => 'test@example.com'],
                    'added' => ['file1.txt'],
                    'modified' => [],
                    'removed' => [],
                ],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('Push Hook', $payload);

        $this->assertIsArray($result);
        $this->assertFalse($result['branchDeleted']);
        $this->assertSame('main', $result['branch']);
        $this->assertSame('http://example.com/test-org/test-repo/-/tree/main', $result['branchUrl']);
        $this->assertSame('123', $result['repositoryId']);
        $this->assertSame('test-repo', $result['repositoryName']);
        $this->assertSame('http://example.com/test-org/test-repo', $result['repositoryUrl']);
        $this->assertSame('test-org', $result['owner']);
        $this->assertSame('abc123', $result['commitHash']);
        $this->assertSame('Test User', $result['headCommitAuthorName']);
        $this->assertSame('test@example.com', $result['headCommitAuthorEmail']);
        $this->assertSame('Test commit', $result['headCommitMessage']);
        $this->assertSame('http://example.com/commit/abc123', $result['headCommitUrl']);
        $this->assertSame(['file1.txt'], $result['affectedFiles']);
    }

    public function testGetEventPullRequest(): void
    {
        $payload = json_encode([
            'object_kind' => 'merge_request',
            'project' => [
                'id' => 123,
                'name' => 'test-repo',
                'namespace' => 'test-org',
                'web_url' => 'http://example.com/test-org/test-repo',
            ],
            'object_attributes' => [
                'iid' => 1,
                'title' => 'Test MR',
                'action' => 'open',
                'source_branch' => 'feature',
                'target_branch' => 'main',
                'source_project_id' => 123,
                'target_project_id' => 123,
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
        $this->assertSame('feature', $result['branch']);
        $this->assertSame('opened', $result['action']);
        $this->assertFalse($result['external']);
        $this->assertSame(1, $result['pullRequestNumber']);
        $this->assertSame('123', $result['repositoryId']);
        $this->assertSame('test-repo', $result['repositoryName']);
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
        $this->assertSame('Head Author', $result['headCommitAuthorName']);
        $this->assertSame('Head commit', $result['headCommitMessage']);
        $this->assertSame('http://example.com/commit/def456', $result['headCommitUrl']);
    }

    public function testCreateRepositoryWithInvalidName(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->createRepository(static::$owner, 'invalid name with spaces', false);
    }

    public function testGetOwnerNameWithoutRepositoryId(): void
    {
        $this->assertSame(static::$existingUser, $this->vcsAdapter->getOwnerName(''));
    }

    public function testGetOwnerNameWithZeroRepositoryId(): void
    {
        $this->assertSame(static::$existingUser, $this->vcsAdapter->getOwnerName('', 0));
    }

    public function testGetEventPushDetectsBranchCreated(): void
    {
        $allZeroSha = str_repeat('0', 40);
        $payload = json_encode([
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'before' => $allZeroSha,
            'after' => 'abc123',
            'checkout_sha' => 'abc123',
            'project' => ['id' => 123, 'name' => 'test-repo', 'namespace' => 'test-org', 'web_url' => 'http://example.com/test-org/test-repo'],
            'commits' => [],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('Push Hook', $payload);
        $this->assertTrue($result['branchCreated']);
        $this->assertFalse($result['branchDeleted']);
    }

    public function testGetEventPushDetectsBranchDeleted(): void
    {
        $allZeroSha = str_repeat('0', 40);
        $payload = json_encode([
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'before' => 'abc123',
            'after' => $allZeroSha,
            'checkout_sha' => '',
            'project' => ['id' => 123, 'name' => 'test-repo', 'namespace' => 'test-org', 'web_url' => 'http://example.com/test-org/test-repo'],
            'commits' => [],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('Push Hook', $payload);
        $this->assertFalse($result['branchCreated']);
        $this->assertTrue($result['branchDeleted']);
    }

    public function testGetEventPullRequestActionMapping(): void
    {
        foreach (['open' => 'opened', 'reopen' => 'reopened', 'update' => 'synchronize', 'close' => 'closed', 'merge' => 'closed'] as $native => $mapped) {
            $payload = json_encode([
                'object_kind' => 'merge_request',
                'project' => ['id' => 1, 'name' => 'r', 'namespace' => 'o', 'web_url' => 'http://example.com/o/r'],
                'object_attributes' => ['iid' => 1, 'action' => $native, 'source_branch' => 'f', 'target_branch' => 'main'],
            ]);

            if ($payload === false) {
                $this->fail('Failed to encode JSON payload');
            }

            $result = $this->vcsAdapter->getEvent('Merge Request Hook', $payload);
            $this->assertSame($mapped, $result['action'], "native action '{$native}' should map to '{$mapped}'");
        }
    }

    public function testGetEventPullRequestDetectsExternal(): void
    {
        $payload = json_encode([
            'object_kind' => 'merge_request',
            'project' => ['id' => 1, 'name' => 'r', 'namespace' => 'o', 'web_url' => 'http://example.com/o/r'],
            'object_attributes' => [
                'iid' => 1,
                'action' => 'open',
                'source_branch' => 'f',
                'target_branch' => 'main',
                'source_project_id' => 456,
                'target_project_id' => 123,
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('Merge Request Hook', $payload);
        $this->assertTrue($result['external']);
    }

    public function testGetRepositoryPresignedUrl(): void
    {
        /** @var GitLab $adapter */
        $adapter = $this->vcsAdapter;
        $owner = static::$owner;

        $url = $adapter->getRepositoryPresignedUrl($owner, 'some-repo', static::$defaultBranch);
        $this->assertStringContainsString('/repository/archive.tar.gz?access_token=', $url);
        $this->assertStringContainsString('&sha=' . static::$defaultBranch, $url);

        $zip = $adapter->getRepositoryPresignedUrl($owner, 'some-repo', static::$defaultBranch, 'zipball');
        $this->assertStringContainsString('/repository/archive.zip?access_token=', $zip);

        // Without a ref the sha param is omitted so the server uses the default branch
        $noRef = $adapter->getRepositoryPresignedUrl($owner, 'some-repo');
        $this->assertStringNotContainsString('sha=', $noRef);

        $this->expectException(\Exception::class);
        $adapter->getRepositoryPresignedUrl($owner, 'some-repo', static::$defaultBranch, 'invalid');
    }

    public function testListRepositoryContentsRootSentinels(): void
    {
        $repositoryName = 'test-list-repository-contents-root-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $empty = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, '');
            $dot = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, '.');
            $dotSlash = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, './');

            $repeatedDotSlash = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, './././');

            $this->assertNotEmpty($empty);
            $this->assertEquals(array_column($empty, 'name'), array_column($dot, 'name'));
            $this->assertEquals(array_column($empty, 'name'), array_column($dotSlash, 'name'));
            $this->assertEquals(array_column($empty, 'name'), array_column($repeatedDotSlash, 'name'));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryContentRootSentinelPrefix(): void
    {
        $repositoryName = 'test-get-repository-content-root-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $direct = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'README.md');
            $prefixed = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, './README.md');
            $repeatedPrefix = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, './././README.md');

            $this->assertEquals($direct['content'], $prefixed['content']);
            $this->assertEquals($direct['content'], $repeatedPrefix['content']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListRepositoryContentsMalformedNestedPath(): void
    {
        $repositoryName = 'test-list-repository-contents-malformed-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/main.php', '<?php');

            $clean = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'src');
            $embeddedDot = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'src/.');
            $doubleSlash = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'src//');

            $this->assertNotEmpty($clean);
            $this->assertEquals(array_column($clean, 'name'), array_column($embeddedDot, 'name'));
            $this->assertEquals(array_column($clean, 'name'), array_column($doubleSlash, 'name'));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListNamespaces(): void
    {
        /** @var GitLab $adapter */
        $adapter = $this->vcsAdapter;

        $result = $adapter->listNamespaces(1, 20);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertNotEmpty($result['items']);

        $kinds = array_column($result['items'], 'kind');
        $this->assertContains('user', $kinds);
        $this->assertContains('group', $kinds);

        foreach ($result['items'] as $namespace) {
            $this->assertArrayHasKey('id', $namespace);
            $this->assertArrayHasKey('name', $namespace);
            $this->assertArrayHasKey('path', $namespace);
            $this->assertArrayHasKey('kind', $namespace);
            $this->assertNotEmpty($namespace['path']);
        }
    }

    public function testListNamespacesWithSearch(): void
    {
        /** @var GitLab $adapter */
        $adapter = $this->vcsAdapter;
        $ownerPath = explode(':', static::$owner)[1] ?? static::$owner;

        $result = $adapter->listNamespaces(1, 20, $ownerPath);

        $this->assertNotEmpty($result['items']);
        $paths = array_column($result['items'], 'path');
        $this->assertContains($ownerPath, $paths);
    }

}
