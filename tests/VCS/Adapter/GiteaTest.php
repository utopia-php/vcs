<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Gitea;

class GiteaTest extends Base
{
    protected static string $accessToken = '';
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';

    protected string $webhookEventHeader = 'X-Gitea-Event';
    protected string $webhookSignatureHeader = 'X-Gitea-Signature';
    protected string $avatarDomain = 'gravatar.com';

    public function setupAdapter(): void
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

    public function testListBranchesEmptyRepo(): void
    {
        // Base::testListBranchesEmptyRepo hardcodes owner 'test-kh' (a GitHub username).
        // In Gitea we use a generated test org, so override to use static::$owner.
        $owner = static::$owner;
        $repositoryName = 'test-list-branches-empty-' . \uniqid();
        $this->vcsAdapter->createRepository($owner, $repositoryName, false);

        try {
            $branches = $this->vcsAdapter->listBranches($owner, $repositoryName);

            $this->assertIsArray($branches);
            $this->assertEmpty($branches);
        } finally {
            $this->vcsAdapter->deleteRepository($owner, $repositoryName);
        }
    }

    public function testCommentWorkflow(): void
    {
        $repositoryName = 'test-comment-workflow-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'comment-test', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', 'Add test file', 'comment-test');

            $pr = $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Comment Test PR',
                'comment-test',
                static::$defaultBranch
            );

            $prNumber = $pr['number'] ?? 0;
            $this->assertGreaterThan(0, $prNumber);

            $originalComment = 'This is a test comment';
            $commentId = $this->vcsAdapter->createComment(static::$owner, $repositoryName, $prNumber, $originalComment);

            $this->assertNotEmpty($commentId);
            $this->assertIsString($commentId);

            $retrievedComment = $this->vcsAdapter->getComment(static::$owner, $repositoryName, $commentId);
            $this->assertSame($originalComment, $retrievedComment);

            $updatedCommentText = 'This comment has been updated';
            $updatedCommentId = $this->vcsAdapter->updateComment(static::$owner, $repositoryName, $commentId, $updatedCommentText);

            $this->assertSame($commentId, $updatedCommentId);

            $finalComment = $this->vcsAdapter->getComment(static::$owner, $repositoryName, $commentId);
            $this->assertSame($updatedCommentText, $finalComment);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testHasAccessToAllRepositories(): void
    {
        $this->assertTrue($this->vcsAdapter->hasAccessToAllRepositories());
    }

    public function testGetRepositoryTreeWithSlashInBranchName(): void
    {
        $repositoryName = 'test-branch-with-slash-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature/test-branch', static::$defaultBranch);

        $tree = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, 'feature/test-branch');

        $this->assertIsArray($tree);
        $this->assertNotEmpty($tree);
        $this->assertContains('README.md', $tree);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetRepositoryName(): void
    {
        $repositoryName = 'test-get-repository-name-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $this->assertIsArray($created);
        $this->assertArrayHasKey('id', $created);
        $this->assertIsScalar($created['id']);
        $repositoryId = (string) $created['id'];
        $result = $this->vcsAdapter->getRepositoryName($repositoryId);

        $this->assertSame($repositoryName, $result);
        $this->assertTrue($this->vcsAdapter->deleteRepository(static::$owner, $repositoryName));
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $repositoryName = 'test-clone-tag-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            // Create initial file and get commit hash
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test Tag');

            $commit = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
            $commitHash = $commit['commitHash'];

            // Create a tag
            $this->vcsAdapter->createTag(static::$owner, $repositoryName, 'v1.0.0', $commitHash, 'Release v1.0.0');

            $command = $this->vcsAdapter->generateCloneCommand(
                static::$owner,
                $repositoryName,
                'v1.0.0',
                \Utopia\VCS\Adapter\Git::CLONE_TYPE_TAG,
                '/tmp/test-clone-tag-' . \uniqid(),
                '/'
            );

            // Verify the command contains tag-specific git commands
            $this->assertIsString($command);
            $this->assertStringContainsString('git init', $command);
            $this->assertStringContainsString('git remote add origin', $command);
            $this->assertStringContainsString('git config core.sparseCheckout true', $command);
            $this->assertStringContainsString('refs/tags', $command);
            $this->assertStringContainsString('v1.0.0', $command);
            $this->assertStringContainsString('git checkout FETCH_HEAD', $command);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testUpdateCommitStatusWithInvalidCommit(): void
    {
        $repositoryName = 'test-update-status-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->updateCommitStatus(
                $repositoryName,
                'invalid-commit-hash',
                static::$owner,
                'success'
            );
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testUpdateCommitStatusWithNonExistingRepository(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->updateCommitStatus(
            'nonexistent-repo-' . \uniqid(),
            'abc123def456abc123def456abc123def456abc123',
            static::$owner,
            'success'
        );
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

    public function testValidateWebhookEvent(): void
    {
        $payload = 'test payload content';
        $secret = 'my-webhook-secret';
        $validSignature = hash_hmac('sha256', $payload, $secret);

        $result = $this->vcsAdapter->validateWebhookEvent($payload, $validSignature, $secret);

        $this->assertTrue($result);
    }

    public function testValidateWebhookEventInvalid(): void
    {
        $payload = 'test payload content';
        $secret = 'my-webhook-secret';
        $invalidSignature = 'wrong-signature';

        $result = $this->vcsAdapter->validateWebhookEvent($payload, $invalidSignature, $secret);

        $this->assertFalse($result);
    }

    public function testGetEventInvalidPayload(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid payload');

        $this->vcsAdapter->getEvent('push', 'invalid json');
    }

    public function testSearchRepositoriesPagination(): void
    {
        $repo1 = 'test-pagination-1-' . \uniqid();
        $repo2 = 'test-pagination-2-' . \uniqid();

        $this->vcsAdapter->createRepository(static::$owner, $repo1, false);
        $this->vcsAdapter->createRepository(static::$owner, $repo2, false);

        try {
            $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 1, 'test-pagination');

            $this->assertSame(1, count($result['items']));
            $this->assertGreaterThanOrEqual(2, $result['total']);

            $result2 = $this->vcsAdapter->searchRepositories(static::$owner, 2, 1, 'test-pagination');
            $this->assertSame(1, count($result2['items']));

            $result20 = $this->vcsAdapter->searchRepositories(static::$owner, 20, 1, 'test-pagination');
            $this->assertIsArray($result20);
            $this->assertEmpty($result20['items']);

        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repo1);
            $this->vcsAdapter->deleteRepository(static::$owner, $repo2);
        }
    }

    public function testSearchRepositoriesNoResults(): void
    {
        $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10, 'nonexistent-repo-xyz-' . \uniqid());

        $this->assertIsArray($result);
        $this->assertEmpty($result['items']);
        $this->assertSame(0, $result['total']);
    }

    public function testSearchRepositoriesInvalidOwner(): void
    {
        $result = $this->vcsAdapter->searchRepositories('nonexistent-owner-' . \uniqid(), 1, 10);

        $this->assertIsArray($result);
        $this->assertEmpty($result['items']);
        $this->assertSame(0, $result['total']);
    }

    public function testGetOwnerName(): void
    {
        $repositoryName = 'test-get-owner-name-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->assertIsArray($created);
            $this->assertArrayHasKey('id', $created);
            $this->assertIsScalar($created['id']);
            $repositoryId = (int) $created['id'];

            $ownerName = $this->vcsAdapter->getOwnerName('', $repositoryId);

            $this->assertSame(static::$owner, $ownerName);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetOwnerNameWithZeroRepositoryId(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('repositoryId is required for this adapter');

        $this->vcsAdapter->getOwnerName('', 0);
    }

    public function testGetOwnerNameWithoutRepositoryId(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('repositoryId is required for this adapter');

        $this->vcsAdapter->getOwnerName('');
    }

    public function testGetOwnerNameWithInvalidRepositoryId(): void
    {
        $this->expectException(\Utopia\VCS\Exception\RepositoryNotFound::class);

        $this->vcsAdapter->getOwnerName('', 999999999);
    }

    public function testGetOwnerNameWithNullRepositoryId(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('repositoryId is required for this adapter');

        $this->vcsAdapter->getOwnerName('', null);
    }

    public function testGetInstallationRepository(): void
    {
        // This method is not applicable for this adapter
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not applicable for this adapter');

        $this->vcsAdapter->getInstallationRepository('any-repo-name');
    }

    public function testCreateTag(): void
    {
        $repositoryName = 'test-create-tag-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $commit = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
            $commitHash = $commit['commitHash'];

            $result = $this->vcsAdapter->createTag(
                static::$owner,
                $repositoryName,
                'v1.0.0',
                $commitHash,
                'First release'
            );

            $this->assertIsArray($result);
            $this->assertNotEmpty($result);
            $this->assertArrayHasKey('name', $result);
            $this->assertSame('v1.0.0', $result['name']);
            $this->assertArrayHasKey('commit', $result);
            $this->assertArrayHasKey('sha', $result['commit']);
            $this->assertSame($commitHash, $result['commit']['sha']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testWebhookPushEvent(): void
    {
        $repositoryName = 'test-webhook-push-' . \uniqid();
        $secret = 'test-webhook-secret-' . \uniqid();

        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $catcherUrl = System::getEnv('TESTS_REQUEST_CATCHER_URL', 'http://request-catcher:5000');
            $this->deleteLastWebhookRequest();
            $this->vcsAdapter->createWebhook(static::$owner, $repositoryName, $catcherUrl . '/webhook', $secret);

            // Trigger a real push by creating a file
            $this->vcsAdapter->createFile(
                static::$owner,
                $repositoryName,
                'README.md',
                '# Webhook Test',
                'Initial commit'
            );

            // Wait for push webhook to arrive automatically
            $webhookData = [];
            $this->assertEventually(function () use (&$webhookData) {
                $webhookData = $this->getLastWebhookRequest();
                $this->assertNotEmpty($webhookData, 'No webhook received');
                $this->assertNotEmpty($webhookData['data'] ?? '', 'Webhook payload is empty');
                $this->assertSame('push', $webhookData['headers'][$this->webhookEventHeader] ?? '', 'Expected push event');
            }, 15000, 500);

            $payload = $webhookData['data'];
            $headers = $webhookData['headers'] ?? [];
            $signature = $headers[$this->webhookSignatureHeader] ?? '';

            $this->assertNotEmpty($signature, 'Missing ' . $this->webhookSignatureHeader . ' header');
            $this->assertTrue(
                $this->vcsAdapter->validateWebhookEvent($payload, $signature, $secret),
                'Webhook signature validation failed'
            );

            $event = $this->vcsAdapter->getEvent('push', $payload);
            $this->assertIsArray($event);
            $this->assertSame(static::$defaultBranch, $event['branch']);
            $this->assertSame($repositoryName, $event['repositoryName']);
            $this->assertSame(static::$owner, $event['owner']);
            $this->assertNotEmpty($event['commitHash']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testWebhookPullRequestEvent(): void
    {
        $repositoryName = 'test-webhook-pr-' . \uniqid();
        $secret = 'test-webhook-secret-' . \uniqid();

        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            // Create all files BEFORE configuring webhook
            // so those push events don't pollute the catcher
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-branch', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'feature.txt', 'content', 'Add feature', 'feature-branch');

            $catcherUrl = System::getEnv('TESTS_REQUEST_CATCHER_URL', 'http://request-catcher:5000');
            $this->vcsAdapter->createWebhook(static::$owner, $repositoryName, $catcherUrl . '/webhook', $secret, ['pull_request']);

            // Clear after setup so only PR event will arrive
            $this->deleteLastWebhookRequest();

            // Trigger real PR event
            $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Test Webhook PR',
                'feature-branch',
                static::$defaultBranch
            );

            // Wait for pull_request webhook to arrive automatically
            $webhookData = [];
            $this->assertEventually(function () use (&$webhookData) {
                $webhookData = $this->getLastWebhookRequest();
                $this->assertNotEmpty($webhookData, 'No webhook received');
                $this->assertNotEmpty($webhookData['data'] ?? '', 'Webhook payload is empty');
                $this->assertSame('pull_request', $webhookData['headers'][$this->webhookEventHeader] ?? '', 'Expected pull_request event');
            }, 15000, 500);

            $payload = $webhookData['data'];
            $headers = $webhookData['headers'] ?? [];
            $signature = $headers[$this->webhookSignatureHeader] ?? '';

            $this->assertNotEmpty($signature, 'Missing ' . $this->webhookSignatureHeader . ' header');
            $this->assertTrue(
                $this->vcsAdapter->validateWebhookEvent($payload, $signature, $secret),
                'Webhook signature validation failed'
            );

            $event = $this->vcsAdapter->getEvent('pull_request', $payload);
            $this->assertIsArray($event);
            $this->assertSame('feature-branch', $event['branch']);
            $this->assertSame($repositoryName, $event['repositoryName']);
            $this->assertSame(static::$owner, $event['owner']);
            $this->assertContains($event['action'], ['opened', 'synchronized']);
            $this->assertGreaterThan(0, $event['pullRequestNumber']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

}
