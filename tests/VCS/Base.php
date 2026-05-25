<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Fetch\Client;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\FileNotFound;

abstract class Base extends TestCase
{
    protected Git $vcsAdapter;
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';

    abstract protected function setupAdapter(): void;

    public function setUp(): void
    {
        $this->setupAdapter();
    }

    /** @return array<mixed> */
    protected function getLastWebhookRequest(): array
    {
        $catcherUrl = System::getEnv('TESTS_REQUEST_CATCHER_URL', 'http://request-catcher:5000');

        $client = new Client();
        $response = $client->fetch(
            url: "{$catcherUrl}/__last_request__",
            method: 'GET'
        );

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return [];
        }

        $body = $response->text();

        if (empty($body)) {
            return [];
        }

        return json_decode($body, true) ?? [];
    }

    protected function assertEventually(callable $probe, int $timeoutMs = 15000, int $waitMs = 500): void
    {
        $start = microtime(true) * 1000;
        $lastException = null;

        while ((microtime(true) * 1000 - $start) < $timeoutMs) {
            try {
                $probe();
                return;
            } catch (\Throwable $e) {
                $lastException = $e;
                usleep($waitMs * 1000);
            }
        }

        throw $lastException ?? new \Exception('assertEventually timed out');
    }

    protected function deleteLastWebhookRequest(): void
    {
        $catcherUrl = System::getEnv('TESTS_REQUEST_CATCHER_URL', 'http://request-catcher:5000');

        $client = new Client();
        $client->fetch(
            url: "{$catcherUrl}/__clear__",
            method: 'DELETE'
        );
    }

    public function testCreateRepository(): void
    {
        $repositoryName = 'test-create-repository-' . \uniqid();

        $result = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('name', $result);
            $this->assertSame($repositoryName, $result['name']);
            $this->assertArrayHasKey('pushed_at', $result);
            $this->assertTrue(
                $result['pushed_at'] === null || \strtotime($result['pushed_at']) !== false
            );
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
            $this->assertArrayHasKey('name', $result);
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
            $this->assertTrue(
                $result['pushed_at'] === null || \strtotime($result['pushed_at']) !== false
            );
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetDeletedRepositoryFails(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->getRepository(static::$owner, 'non-existing-repository-' . \uniqid());
    }

    public function testGetRepositoryWithNonExistingOwner(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->getRepository('non-existing-owner-' . \uniqid(), 'non-existing-repo');
    }

    public function testDeleteRepository(): void
    {
        $repositoryName = 'test-delete-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $result = $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        $this->assertTrue($result);
    }

    public function testDeleteRepositoryTwiceFails(): void
    {
        $repositoryName = 'test-delete-repository-twice-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);

        $this->expectException(\Exception::class);
        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testDeleteNonExistingRepositoryFails(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->deleteRepository(static::$owner, 'non-existing-repo-' . \uniqid());
    }

    public function testCreateRepositoryWithInvalidName(): void
    {
        try {
            $this->vcsAdapter->createRepository(static::$owner, 'invalid name with spaces', false);
            $this->fail('Expected exception for invalid repository name');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
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

            $tree = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, static::$defaultBranch, false);
            $this->assertIsArray($tree);
            $this->assertContains('README.md', $tree);
            $this->assertContains('src', $tree);
            $this->assertCount(2, $tree);

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

    public function testListBranchesEmptyRepo(): void
    {
        $this->markTestSkipped('Each adapter handles empty repos differently - override in adapter-specific test');
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
                Git::CLONE_TYPE_BRANCH,
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
                Git::CLONE_TYPE_COMMIT,
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
                Git::CLONE_TYPE_BRANCH,
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
        $repositoryName = 'test-get-owner-name-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->assertIsArray($created);
            $this->assertArrayHasKey('id', $created);
            $repositoryId = (int) ($created['id'] ?? 0);

            $result = $this->vcsAdapter->getOwnerName('', $repositoryId);

            $this->assertIsString($result);
            $this->assertNotEmpty($result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
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
    
            $this->assertNotEmpty($result['items']);
            $this->assertArrayHasKey('pushed_at', $result['items'][0]);
            $this->assertTrue(
                $result['items'][0]['pushed_at'] === null || \strtotime($result['items'][0]['pushed_at']) !== false
            );
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repo1Name);
            $this->vcsAdapter->deleteRepository(static::$owner, $repo2Name);
        }
    }

    public function testGetPullRequest(): void
    {
        $repositoryName = 'test-get-pull-request-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-branch', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'feature.txt', 'feature content', 'Add feature', 'feature-branch');

            $pr = $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Test PR',
                'feature-branch',
                static::$defaultBranch,
                'Test PR description'
            );

            $prNumber = $pr['iid'] ?? $pr['number'] ?? 0;
            $this->assertGreaterThan(0, $prNumber);

            $result = $this->vcsAdapter->getPullRequest(static::$owner, $repositoryName, $prNumber);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('number', $result);
            $this->assertArrayHasKey('title', $result);
            $this->assertArrayHasKey('state', $result);
            $this->assertArrayHasKey('head', $result);
            $this->assertArrayHasKey('base', $result);
            $this->assertSame($prNumber, $result['number']);
            $this->assertSame('Test PR', $result['title']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetPullRequestFiles(): void
    {
        $repositoryName = 'test-get-pull-request-files-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-branch', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'feature.txt', 'feature content', 'Add feature', 'feature-branch');

            $pr = $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Test PR Files',
                'feature-branch',
                static::$defaultBranch
            );

            $prNumber = $pr['iid'] ?? $pr['number'] ?? 0;

            $result = [];
            $this->assertEventually(function () use (&$result, $repositoryName, $prNumber) {
                $result = $this->vcsAdapter->getPullRequestFiles(static::$owner, $repositoryName, $prNumber);
                $this->assertNotEmpty($result);
            }, 15000, 1000);

            $this->assertIsArray($result);
            $filenames = array_column($result, 'filename');
            $this->assertContains('feature.txt', $filenames);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetPullRequestWithInvalidNumber(): void
    {
        $repositoryName = 'test-get-pull-request-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->getPullRequest(static::$owner, $repositoryName, 99999);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetPullRequestFromBranch(): void
    {
        $repositoryName = 'test-get-pr-from-branch-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'my-feature', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'feature.txt', 'content', 'Add feature', 'my-feature');

            $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Feature PR',
                'my-feature',
                static::$defaultBranch
            );

            $result = $this->vcsAdapter->getPullRequestFromBranch(static::$owner, $repositoryName, 'my-feature');

            $this->assertIsArray($result);
            $this->assertNotEmpty($result);
            $this->assertArrayHasKey('head', $result);
            $this->assertSame('my-feature', $result['head']['ref'] ?? '');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetPullRequestFromBranchNoPR(): void
    {
        $repositoryName = 'test-get-pr-no-pr-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'lonely-branch', static::$defaultBranch);

            $result = $this->vcsAdapter->getPullRequestFromBranch(static::$owner, $repositoryName, 'lonely-branch');

            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testCreateComment(): void
    {
        $repositoryName = 'test-create-comment-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'test-branch', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', 'Add test', 'test-branch');

            $pr = $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Test PR',
                'test-branch',
                static::$defaultBranch
            );

            $prNumber = $pr['iid'] ?? $pr['number'] ?? 0;
            $this->assertGreaterThan(0, $prNumber);

            $commentId = $this->vcsAdapter->createComment(static::$owner, $repositoryName, $prNumber, 'Test comment');

            $this->assertNotEmpty($commentId);
            $this->assertIsString($commentId);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetComment(): void
    {
        $repositoryName = 'test-get-comment-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'test-branch', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', 'Add test', 'test-branch');

            $pr = $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Test PR',
                'test-branch',
                static::$defaultBranch
            );

            $prNumber = $pr['iid'] ?? $pr['number'] ?? 0;
            $commentId = $this->vcsAdapter->createComment(static::$owner, $repositoryName, $prNumber, 'Test comment');

            $result = $this->vcsAdapter->getComment(static::$owner, $repositoryName, $commentId);

            $this->assertIsString($result);
            $this->assertSame('Test comment', $result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testUpdateComment(): void
    {
        $repositoryName = 'test-update-comment-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'test-branch', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', 'Add test', 'test-branch');

            $pr = $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Test PR',
                'test-branch',
                static::$defaultBranch
            );

            $prNumber = $pr['iid'] ?? $pr['number'] ?? 0;
            $commentId = $this->vcsAdapter->createComment(static::$owner, $repositoryName, $prNumber, 'Original comment');

            $updatedCommentId = $this->vcsAdapter->updateComment(static::$owner, $repositoryName, $commentId, 'Updated comment');

            $this->assertSame($commentId, $updatedCommentId);

            $finalComment = $this->vcsAdapter->getComment(static::$owner, $repositoryName, $commentId);
            $this->assertSame('Updated comment', $finalComment);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testCreateCommentInvalidPR(): void
    {
        $repositoryName = 'test-comment-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->createComment(static::$owner, $repositoryName, 99999, 'Test comment');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetCommentInvalidId(): void
    {
        $repositoryName = 'test-get-comment-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $result = $this->vcsAdapter->getComment(static::$owner, $repositoryName, '99999999');
            $this->assertIsString($result);
            $this->assertSame('', $result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetUser(): void
    {
        $result = $this->vcsAdapter->getUser('root');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('username', $result);
    }

    public function testGetUserWithInvalidUsername(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->getUser('non-existent-user-' . \uniqid());
    }

    public function testGetEventPush(): void
    {
        $this->markTestSkipped('Override in adapter-specific test');
    }

    public function testGetEventPullRequest(): void
    {
        $this->markTestSkipped('Override in adapter-specific test');
    }

    public function testValidateWebhookEvent(): void
    {
        $this->markTestSkipped('Override in adapter-specific test');
    }
}
