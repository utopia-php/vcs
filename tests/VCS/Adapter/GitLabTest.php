<?php

namespace Utopia\Tests\Adapter;

use Utopia\Command;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Console;
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
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListRepositoryContentsNonExistingPath(): void
    {
        $repositoryName = 'test-list-repository-contents-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $contents = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'non-existing-path');

            $this->assertIsArray($contents);
            $this->assertEmpty($contents);
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
        $result = $this->vcsAdapter->getOwnerName('', null);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testGetOwnerNameWithRepositoryId(): void
    {
        $repositoryName = 'test-get-owner-name-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $repo = $this->vcsAdapter->getRepository(static::$owner, $repositoryName);
            $repositoryId = $repo['id'] ?? 0;

            $result = $this->vcsAdapter->getOwnerName('', $repositoryId);

            $this->assertIsString($result);
            $this->assertNotEmpty($result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
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
        $repositoryName = 'test-clone-command-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $directory = '/tmp/test-clone-' . \uniqid();

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $command = $this->vcsAdapter->generateCloneCommand(
                static::$owner,
                $repositoryName,
                static::$defaultBranch,
                \Utopia\VCS\Adapter\Git::CLONE_TYPE_BRANCH,
                $directory,
                '/'
            );

            $this->assertInstanceOf(Command::class, $command);
            $commandString = $command->toString();
            $this->assertStringContainsString('git', $commandString);
            $this->assertStringContainsString('remote', $commandString);
            $this->assertStringContainsString('sparse-checkout', $commandString);
            $this->assertStringContainsString($repositoryName, $commandString);

            $output = '';
            $stderr = '';
            $exitCode = Console::execute($command, '', $output, $stderr, 30);
            $this->assertSame(0, $exitCode, $stdout = trim($output . "\n" . $stderr));
            $this->assertFileExists($directory . '/README.md');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
            if (\is_dir($directory)) {
                \exec('rm -rf ' . escapeshellarg($directory));
            }
        }
    }

    public function testGenerateCloneCommandWithCommitHash(): void
    {
        $repositoryName = 'test-clone-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $commit = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
            $commitHash = $commit['commitHash'];

            $directory = '/tmp/test-clone-commit-' . \uniqid();
            $command = $this->vcsAdapter->generateCloneCommand(
                static::$owner,
                $repositoryName,
                $commitHash,
                \Utopia\VCS\Adapter\Git::CLONE_TYPE_COMMIT,
                $directory,
                '/'
            );

            $this->assertInstanceOf(Command::class, $command);
            $commandString = $command->toString();
            $this->assertStringContainsString('fetch', $commandString);
            $this->assertStringContainsString('--depth=1', $commandString);
            $this->assertStringContainsString($commitHash, $commandString);
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


    public function testUpdateCommitStatus(): void
    {
        $repositoryName = 'test-update-commit-status-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commit = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
            $commitHash = $commit['commitHash'];

            $this->vcsAdapter->updateCommitStatus(
                $repositoryName,
                $commitHash,
                static::$owner,
                'success',
                'Build passed',
                'https://example.com',
                'ci/build'
            );

            $statuses = $this->vcsAdapter->getCommitStatuses(static::$owner, $repositoryName, $commitHash);

            $this->assertIsArray($statuses);
            $this->assertNotEmpty($statuses);

            $states = array_column($statuses, 'state');
            $this->assertContains('success', $states);
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

            $this->assertInstanceOf(Command::class, $command);
            $commandString = $command->toString();
            $this->assertStringContainsString('refs/tags', $commandString);
            $this->assertStringContainsString('v1.0.0', $commandString);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGenerateCloneCommandWithInvalidRepository(): void
    {
        $directory = '/tmp/test-clone-invalid-' . \uniqid();

        try {
            $command = $this->vcsAdapter->generateCloneCommand(
                static::$owner,
                'nonexistent-repo-' . \uniqid(),
                static::$defaultBranch,
                \Utopia\VCS\Adapter\Git::CLONE_TYPE_BRANCH,
                $directory,
                '/'
            );

            $this->assertInstanceOf(Command::class, $command);

            $output = '';
            $stderr = '';
            $exitCode = Console::execute($command, '', $output, $stderr, 30);

            $cloneFailed = ($exitCode !== 0) || !file_exists($directory . '/README.md');
            $this->assertTrue($cloneFailed, 'Clone should have failed for nonexistent repository');
        } finally {
            if (\is_dir($directory)) {
                \exec('rm -rf ' . escapeshellarg($directory));
            }
        }
    }

    public function testGetCommit(): void
    {
        $repositoryName = 'test-get-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $customMessage = 'Test commit message';
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test', $customMessage);

            $latestCommit = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
            $commitHash = $latestCommit['commitHash'];

            $result = $this->vcsAdapter->getCommit(static::$owner, $repositoryName, $commitHash);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('commitHash', $result);
            $this->assertArrayHasKey('commitMessage', $result);
            $this->assertArrayHasKey('commitAuthor', $result);
            $this->assertArrayHasKey('commitUrl', $result);
            $this->assertArrayHasKey('commitAuthorAvatar', $result);
            $this->assertArrayHasKey('commitAuthorUrl', $result);
            $this->assertSame($commitHash, $result['commitHash']);
            $this->assertStringStartsWith($customMessage, $result['commitMessage']);
            $this->assertNotEmpty($result['commitUrl']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetLatestCommit(): void
    {
        $repositoryName = 'test-get-latest-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $firstMessage = 'First commit';
            $secondMessage = 'Second commit';

            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test', $firstMessage);
            $commit1 = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);

            $this->assertIsArray($commit1);
            $this->assertNotEmpty($commit1['commitHash']);
            $this->assertStringStartsWith($firstMessage, $commit1['commitMessage']);
            $this->assertNotEmpty($commit1['commitUrl']);

            $commit1Hash = $commit1['commitHash'];

            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', $secondMessage);
            $commit2 = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);

            $this->assertStringStartsWith($secondMessage, $commit2['commitMessage']);
            $this->assertNotSame($commit1Hash, $commit2['commitHash']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetCommitWithInvalidHash(): void
    {
        $repositoryName = 'test-get-commit-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->getCommit(static::$owner, $repositoryName, 'invalid-sha-12345');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetLatestCommitWithInvalidBranch(): void
    {
        $repositoryName = 'test-get-latest-commit-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, 'non-existing-branch');
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
            $this->assertSame('open', $payload['object_attributes']['action'] ?? '');

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

    public function testGetRepositoryName(): void
    {
        $repositoryName = 'test-get-repository-name-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $repo = $this->vcsAdapter->getRepository(static::$owner, $repositoryName);
            $repositoryId = (string) ($repo['id'] ?? '');

            $result = $this->vcsAdapter->getRepositoryName($repositoryId);

            $this->assertIsString($result);
            $this->assertSame($repositoryName, $result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryNameWithInvalidId(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->getRepositoryName('99999999');
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
        $repositoryName = 'test-get-repository-tree-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/main.php', '<?php echo "hello";');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/lib.php', '<?php // lib');

            // Non recursive — root level only
            $tree = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, static::$defaultBranch, false);

            $this->assertIsArray($tree);
            $this->assertContains('README.md', $tree);
            $this->assertContains('src', $tree);
            $this->assertCount(2, $tree);

            // Recursive — all files
            $treeRecursive = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, static::$defaultBranch, true);

            $this->assertIsArray($treeRecursive);
            $this->assertContains('README.md', $treeRecursive);
            $this->assertContains('src/main.php', $treeRecursive);
            $this->assertContains('src/lib.php', $treeRecursive);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryTreeWithInvalidBranch(): void
    {
        $repositoryName = 'test-get-repository-tree-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $tree = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, 'non-existing-branch', false);

            $this->assertIsArray($tree);
            $this->assertEmpty($tree);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryContent(): void
    {
        $repositoryName = 'test-get-repository-content-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $fileContent = '# Hello World';
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', $fileContent);

            $result = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'README.md');

            $this->assertIsArray($result);
            $this->assertArrayHasKey('content', $result);
            $this->assertArrayHasKey('sha', $result);
            $this->assertArrayHasKey('size', $result);
            $this->assertSame($fileContent, $result['content']);
            $this->assertGreaterThan(0, $result['size']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryContentWithRef(): void
    {
        $repositoryName = 'test-get-repository-content-ref-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'main branch content');

            $result = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'test.txt', static::$defaultBranch);

            $this->assertIsArray($result);
            $this->assertSame('main branch content', $result['content']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryContentFileNotFound(): void
    {
        $repositoryName = 'test-get-repository-content-not-found-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $this->expectException(\Utopia\VCS\Exception\FileNotFound::class);
            $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'non-existing.txt');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListBranches(): void
    {
        $repositoryName = 'test-list-branches-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-branch', static::$defaultBranch);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'another-branch', static::$defaultBranch);

            $result = $this->vcsAdapter->listBranches(static::$owner, $repositoryName);

            $this->assertIsArray($result);
            $this->assertNotEmpty($result);

            $this->assertContains(static::$defaultBranch, $result);
            $this->assertContains('feature-branch', $result);
            $this->assertContains('another-branch', $result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListRepositoryLanguages(): void
    {
        $repositoryName = 'test-list-repository-languages-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'main.php', '<?php echo "test";');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'script.js', 'console.log("test");');

            sleep(5); // ← increase from 2 to 5

            $languages = $this->vcsAdapter->listRepositoryLanguages(static::$owner, $repositoryName);

            $this->assertIsArray($languages);
            $this->assertNotEmpty($languages);
            $this->assertContains('PHP', $languages);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListRepositoryLanguagesEmptyRepo(): void
    {
        $repositoryName = 'test-list-repository-languages-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $languages = $this->vcsAdapter->listRepositoryLanguages(static::$owner, $repositoryName);

            $this->assertIsArray($languages);
            $this->assertEmpty($languages);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListRepositoryContents(): void
    {
        $repositoryName = 'test-list-repository-contents-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'file1.txt', 'content1');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/main.php', '<?php');

            $contents = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName);

            $this->assertIsArray($contents);
            $this->assertCount(3, $contents);

            $names = array_column($contents, 'name');
            $this->assertContains('README.md', $names);
            $this->assertContains('file1.txt', $names);
            $this->assertContains('src', $names);

            foreach ($contents as $item) {
                $this->assertArrayHasKey('name', $item);
                $this->assertArrayHasKey('type', $item);
                $this->assertArrayHasKey('size', $item);
            }
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
}
