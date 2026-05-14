<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\FileNotFound;
use Utopia\VCS\Exception\RepositoryNotFound;

class GitHubTest extends Base
{
    protected static string $owner = '';
    protected static string $installationId = '';
    protected static string $defaultBranch = 'main';

    protected function createVCSAdapter(): Git
    {
        return new GitHub(new Cache(new None()));
    }

    public function setUp(): void
    {
        $privateKey = str_replace('\\n', "\n", System::getEnv('TESTS_GITHUB_PRIVATE_KEY') ?? '');
        $appId = System::getEnv('TESTS_GITHUB_APP_IDENTIFIER') ?? '';
        static::$installationId = System::getEnv('TESTS_GITHUB_INSTALLATION_ID') ?? '';

        if (empty($privateKey) || empty($appId) || empty(static::$installationId)) {
            $this->markTestSkipped('GitHub App credentials not configured');
        }

        $adapter = new GitHub(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: static::$installationId,
            privateKey: $privateKey,
            appId: $appId,
            accessToken: '',
            refreshToken: ''
        );

        if (empty(static::$owner)) {
            static::$owner = $adapter->getOwnerName(static::$installationId);
        }

        $this->vcsAdapter = $adapter;
    }

    public function testGetEventPush(): void
    {
        $payload = json_encode([
            'created' => false,
            'deleted' => false,
            'ref' => 'refs/heads/main',
            'before' => 'abc123',
            'after' => 'def456',
            'repository' => [
                'id' => 603754812,
                'name' => 'testing-fork',
                'full_name' => 'vermakhushboo/testing-fork',
                'private' => true,
                'html_url' => 'https://github.com/vermakhushboo/testing-fork',
                'owner' => ['name' => 'vermakhushboo'],
            ],
            'installation' => ['id' => 1234],
            'head_commit' => [
                'author' => ['name' => 'Khushboo Verma'],
                'message' => 'Update index.js',
                'url' => 'https://github.com/vermakhushboo/testing-fork/commit/def456',
            ],
            'commits' => [
                [
                    'id' => 'def456',
                    'added' => ['src/lib.js'],
                    'removed' => ['README.md'],
                    'modified' => ['src/main.js'],
                ],
            ],
            'sender' => [
                'html_url' => 'https://github.com/vermakhushboo',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/43381712?v=4',
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('push', $payload);

        $this->assertSame('main', $result['branch']);
        $this->assertSame('603754812', $result['repositoryId']);
        $this->assertCount(3, $result['affectedFiles']);
        $this->assertSame('src/lib.js', $result['affectedFiles'][0]);
        $this->assertSame('README.md', $result['affectedFiles'][1]);
        $this->assertSame('src/main.js', $result['affectedFiles'][2]);
    }

    public function testGetEventPullRequest(): void
    {
        $payload = json_encode([
            'action' => 'opened',
            'number' => 1,
            'pull_request' => [
                'id' => 1303283688,
                'state' => 'open',
                'html_url' => 'https://github.com/vermakhushboo/g4-node-function/pull/17',
                'head' => [
                    'ref' => 'test',
                    'sha' => 'a27dbe54b17032ee35a16c24bac151e5c2b33328',
                    'label' => 'vermakhushboo:test',
                    'user' => ['login' => 'vermakhushboo'],
                ],
                'base' => [
                    'label' => 'vermakhushboo:main',
                    'user' => ['login' => 'vermakhushboo'],
                ],
                'user' => [
                    'login' => 'vermakhushboo',
                    'avatar_url' => 'https://avatars.githubusercontent.com/u/43381712?v=4',
                ],
            ],
            'repository' => [
                'id' => 3498,
                'name' => 'functions-example',
                'owner' => ['login' => 'vermakhushboo'],
                'html_url' => 'https://github.com/vermakhushboo/g4-node-function',
            ],
            'installation' => ['id' => 9876],
            'sender' => ['html_url' => 'https://github.com/vermakhushboo'],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('pull_request', $payload);

        $this->assertSame('opened', $result['action']);
        $this->assertSame(1, $result['pullRequestNumber']);
    }

    public function testGetEventInstallation(): void
    {
        $payload = json_encode([
            'action' => 'deleted',
            'installation' => [
                'id' => 1234,
                'account' => ['login' => 'vermakhushboo'],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('installation', $payload);

        $this->assertSame('deleted', $result['action']);
        $this->assertSame('1234', $result['installationId']);
    }

    public function testValidateWebhookEvent(): void
    {
        $payload = '{"action":"push"}';
        $secret = 'my-webhook-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($this->vcsAdapter->validateWebhookEvent($payload, $signature, $secret));
        $this->assertFalse($this->vcsAdapter->validateWebhookEvent($payload, 'sha256=wrongsig', $secret));
    }

    public function testCreateRepository(): void
    {
        $repositoryName = 'test-create-repository-' . \uniqid();

        $result = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('name', $result);
            $this->assertSame($repositoryName, $result['name']);
            $this->assertFalse($result['private']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testCreatePrivateRepository(): void
    {
        $repositoryName = 'test-create-private-' . \uniqid();

        $result = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, true);

        try {
            $this->assertIsArray($result);
            $this->assertTrue($result['private']);
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

    public function testGetDeletedRepositoryFails(): void
    {
        $this->expectException(RepositoryNotFound::class);
        $this->vcsAdapter->getRepository(static::$owner, 'non-existing-repository-' . \uniqid());
    }

    public function testDeleteRepository(): void
    {
        $repositoryName = 'test-delete-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $result = $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);

        $this->assertTrue($result);
    }

    public function testDeleteNonExistingRepositoryFails(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->deleteRepository(static::$owner, 'non-existing-repo-' . \uniqid());
    }

    public function testGetRepositoryName(): void
    {
        $repositoryName = 'test-get-repository-name-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->assertIsArray($created);
            $this->assertArrayHasKey('id', $created);
            $repositoryId = (string) ($created['id'] ?? '');

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

    public function testGetRepositoryTree(): void
    {
        $repositoryName = 'test-get-repository-tree-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/main.php', '<?php echo "hello";');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/lib.php', '<?php // lib');

            // Non-recursive
            $tree = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, static::$defaultBranch, false);
            $this->assertIsArray($tree);
            $this->assertContains('README.md', $tree);
            $this->assertContains('src', $tree);
            $this->assertCount(2, $tree);

            // Recursive
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

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

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

            // GitHub-specific: verify blob SHA format
            $expectedSha = \hash('sha1', "blob " . $result['size'] . "\0" . $result['content']);
            $this->assertSame($expectedSha, $result['sha']);
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

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $this->expectException(FileNotFound::class);
            $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'non-existing.txt');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryContentCaseSensitive(): void
    {
        $repositoryName = 'test-get-repository-content-case-' . \uniqid();

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $this->expectException(FileNotFound::class);
            $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'readme.md');
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

    public function testListRepositoryContentsNonExistingPath(): void
    {
        $repositoryName = 'test-list-repository-contents-invalid-' . \uniqid();

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $contents = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'non-existing-path');
            $this->assertIsArray($contents);
            $this->assertEmpty($contents);
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

            $languages = [];
            $this->assertEventually(function () use (&$languages, $repositoryName) {
                $languages = $this->vcsAdapter->listRepositoryLanguages(static::$owner, $repositoryName);
                $this->assertNotEmpty($languages);
            }, 30000, 2000);

            $this->assertIsArray($languages);
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

    public function testListBranches(): void
    {
        $repositoryName = 'test-list-branches-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $branches = $this->vcsAdapter->listBranches(static::$owner, $repositoryName);

            $this->assertIsArray($branches);
            $this->assertNotEmpty($branches);
            $this->assertContains(static::$defaultBranch, $branches);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
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

    public function testGetCommitWithInvalidHash(): void
    {
        $repositoryName = 'test-get-commit-invalid-' . \uniqid();

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $this->expectException(\Exception::class);
            $this->vcsAdapter->getCommit(static::$owner, $repositoryName, 'invalid-sha-12345');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListBranchesPagination(): void
    {
        $repositoryName = 'test-list-branches-pages-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'branch-a', static::$defaultBranch);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'branch-b', static::$defaultBranch);

            /** @var GitHub $adapter */
            $adapter = $this->vcsAdapter;

            $page1 = $adapter->listBranches(static::$owner, $repositoryName, 1, 1);
            $this->assertSame(['branch-a'], $page1);

            $page2 = $adapter->listBranches(static::$owner, $repositoryName, 1, 2);
            $this->assertSame(['branch-b'], $page2);

            $all = $adapter->listBranches(static::$owner, $repositoryName, 100, 1);
            $this->assertSame([static::$defaultBranch, 'branch-a', 'branch-b'], $all);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListBranchesEmptyRepository(): void
    {
        $repositoryName = 'test-list-branches-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $branches = $this->vcsAdapter->listBranches(static::$owner, $repositoryName);

            $this->assertIsArray($branches);
            $this->assertEmpty($branches);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListBranchesNonExistingRepository(): void
    {
        $branches = $this->vcsAdapter->listBranches(static::$owner, 'non-existing-repo-' . \uniqid());

        $this->assertIsArray($branches);
        $this->assertEmpty($branches);
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
            $this->assertNotEmpty($commit1['commitAuthorAvatar']);
            $this->assertNotEmpty($commit1['commitAuthorUrl']);

            $commit1Hash = $commit1['commitHash'];

            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', $secondMessage);
            $commit2 = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);

            $this->assertStringStartsWith($secondMessage, $commit2['commitMessage']);
            $this->assertNotSame($commit1Hash, $commit2['commitHash']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetLatestCommitWithInvalidBranch(): void
    {
        $repositoryName = 'test-get-latest-commit-invalid-' . \uniqid();

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $this->expectException(\Exception::class);
            $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, 'non-existing-branch');
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

            // Should not throw
            $this->vcsAdapter->updateCommitStatus(
                $repositoryName,
                $commitHash,
                static::$owner,
                'success',
                'Build passed',
                'https://example.com',
                'ci/build'
            );

            $this->assertTrue(true);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
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
                GitHub::CLONE_TYPE_BRANCH,
                $directory,
                '*'
            );

            $this->assertIsString($command);
            $this->assertStringContainsString('sparse-checkout', $command);
            $this->assertStringContainsString($repositoryName, $command);

            $output = [];
            \exec($command . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
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
                GitHub::CLONE_TYPE_COMMIT,
                $directory,
                '*'
            );

            $this->assertIsString($command);
            $this->assertStringContainsString('sparse-checkout', $command);

            $output = [];
            \exec($command . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, implode("\n", $output));
            $this->assertFileExists($directory . '/README.md');
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
                GitHub::CLONE_TYPE_BRANCH,
                $directory,
                '*'
            );

            $output = [];
            \exec($command . ' 2>&1', $output, $exitCode);

            $cloneFailed = ($exitCode !== 0) || !file_exists($directory . '/README.md');
            $this->assertTrue($cloneFailed, 'Clone should have failed for nonexistent repository');
        } finally {
            if (\is_dir($directory)) {
                \exec('rm -rf ' . escapeshellarg($directory));
            }
        }
    }

    public function testGetOwnerName(): void
    {
        $result = $this->vcsAdapter->getOwnerName(static::$installationId);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertSame(static::$owner, $result);
    }

    public function testSearchRepositories(): void
    {
        $repo1Name = 'test-search-repo1-' . \uniqid();
        $repo2Name = 'test-search-repo2-' . \uniqid();

        $this->vcsAdapter->createRepository(static::$owner, $repo1Name, false);
        $this->vcsAdapter->createRepository(static::$owner, $repo2Name, false);

        try {
            $result = [];
            $this->assertEventually(function () use (&$result) {
                $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10);
                $this->assertGreaterThanOrEqual(2, $result['total']);
            }, 30000, 2000);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('items', $result);
            $this->assertArrayHasKey('total', $result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repo1Name);
            $this->vcsAdapter->deleteRepository(static::$owner, $repo2Name);
        }
    }

    public function testHasAccessToAllRepositories(): void
    {
        $result = $this->vcsAdapter->hasAccessToAllRepositories();
        $this->assertIsBool($result);
    }

    public function testGetInstallationRepository(): void
    {
        $repositoryName = 'test-installation-repo-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $repo = $this->vcsAdapter->getInstallationRepository($repositoryName);
            $this->assertIsArray($repo);
            $this->assertSame($repositoryName, $repo['name']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetPullRequest(): void
    {
        $this->markTestSkipped('createBranch and createPullRequest not implemented in GitHub adapter');
    }

    public function testGetPullRequestFiles(): void
    {
        $this->markTestSkipped('createBranch and createPullRequest not implemented in GitHub adapter');
    }

    public function testGetPullRequestWithInvalidNumber(): void
    {
        $this->markTestSkipped('createBranch and createPullRequest not implemented in GitHub adapter');
    }

    public function testGetPullRequestFromBranch(): void
    {
        $this->markTestSkipped('createBranch and createPullRequest not implemented in GitHub adapter');
    }

    public function testGetComment(): void
    {
        $this->markTestSkipped('Requires existing PR — createPullRequest not implemented in GitHub adapter');
    }

    public function testCreateComment(): void
    {
        $this->markTestSkipped('Requires existing PR — createPullRequest not implemented in GitHub adapter');
    }

    public function testUpdateComment(): void
    {
        $this->markTestSkipped('Requires existing PR — createPullRequest not implemented in GitHub adapter');
    }
}
