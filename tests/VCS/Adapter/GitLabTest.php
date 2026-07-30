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
    protected static string $openPullRequestState = 'opened';
    protected static string $eventHeader = 'x-gitlab-event';
    protected static string $signatureHeader = 'x-gitlab-token';
    protected static string $pushEventName = 'Push Hook';
    protected static string $pullRequestEventName = 'Merge Request Hook';

    /** @var array<string> */
    protected static array $pullRequestOpenedActions = ['opened', 'synchronize'];

    protected static string $presignedTarballFragment = '/repository/archive.tar.gz?access_token=';
    protected static string $presignedZipballFragment = '/repository/archive.zip?access_token=';
    protected static string $repositoryNotFoundException = \Exception::class;

    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return $secret;
    }

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
            // GitLab answers its health probe well before the API serves traffic, so
            // give the first call room to get past 502s rather than failing every test.
            $this->assertEventually(function () use ($adapter) {
                static::$owner = $adapter->createOrganization('test-org-' . \uniqid());
            }, 60000, 2000);
        }

        $this->vcsAdapter = $adapter;
    }

    /**
     * GitLab owners are carried as "id:path", but it reports the path alone.
     */
    protected function ownerPath(): string
    {
        return \explode(':', static::$owner)[1] ?? static::$owner;
    }

    /**
     * GitLab reports a project's owner as its namespace.
     *
     * @param array<string, mixed> $repository
     */
    protected function ownerOf(array $repository): string
    {
        $this->assertArrayHasKey('namespace', $repository);
        $this->assertIsArray($repository['namespace']);
        $this->assertArrayHasKey('path', $repository['namespace']);

        return (string) $repository['namespace']['path'];
    }

    /**
     * GitLab reports visibility as a string rather than a boolean flag.
     *
     * @param array<string, mixed> $repository
     */
    protected function isPrivate(array $repository): bool
    {
        $this->assertArrayHasKey('visibility', $repository);
        $this->assertIsString($repository['visibility']);

        return $repository['visibility'] === 'private';
    }

    /**
     * GitLab numbers merge requests per project, under 'iid'.
     *
     * @param array<string, mixed> $pullRequest
     */
    protected function pullRequestNumberOf(array $pullRequest): int
    {
        $this->assertArrayHasKey('iid', $pullRequest);
        $this->assertIsNumeric($pullRequest['iid']);

        return (int) $pullRequest['iid'];
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
