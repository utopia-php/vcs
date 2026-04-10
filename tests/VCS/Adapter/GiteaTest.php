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

    protected function createVCSAdapter(): Git
    {
        return new Gitea(new Cache(new None()));
    }

    public function setUp(): void
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

    public function testCreateRepository(): void
    {
        $owner = static::$owner;
        $repositoryName = 'test-create-repository-' . \uniqid();

        $result = $this->vcsAdapter->createRepository($owner, $repositoryName, false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame($repositoryName, $result['name']);
        $this->assertArrayHasKey('owner', $result);
        $this->assertSame($owner, $result['owner']['login']);
        $this->assertFalse($result['private']);

        $this->assertTrue($this->vcsAdapter->deleteRepository(static::$owner, $repositoryName));
    }

    public function testGetDeletedRepositoryFails(): void
    {
        $repositoryName = 'test-get-deleted-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);

        $this->expectException(\Exception::class);
        $this->vcsAdapter->getRepository(static::$owner, $repositoryName);
    }

    public function testCreatePrivateRepository(): void
    {
        $repositoryName = 'test-create-private-repository-' . \uniqid();

        $result = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, true);

        $this->assertIsArray($result);
        $this->assertTrue($result['private']);

        // Verify with getRepository
        $fetched = $this->vcsAdapter->getRepository(static::$owner, $repositoryName);
        $this->assertTrue($fetched['private']);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
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
            $updatedCommentId = $this->vcsAdapter->updateComment(static::$owner, $repositoryName, (int)$commentId, $updatedCommentText);

            $this->assertSame($commentId, $updatedCommentId);

            $finalComment = $this->vcsAdapter->getComment(static::$owner, $repositoryName, $commentId);
            $this->assertSame($updatedCommentText, $finalComment);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetComment(): void
    {
        $repositoryName = 'test-get-comment-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'test-branch', static::$defaultBranch);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', 'Add test', 'test-branch');

        // Create PR
        $pr = $this->vcsAdapter->createPullRequest(
            static::$owner,
            $repositoryName,
            'Test PR',
            'test-branch',
            static::$defaultBranch
        );

        $prNumber = $pr['number'] ?? 0;

        // Create a comment
        $commentId = $this->vcsAdapter->createComment(static::$owner, $repositoryName, $prNumber, 'Test comment');

        // Test getComment
        $result = $this->vcsAdapter->getComment(static::$owner, $repositoryName, $commentId);

        $this->assertIsString($result);
        $this->assertSame('Test comment', $result);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
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

        $result = $this->vcsAdapter->getComment(static::$owner, $repositoryName, '99999999');

        $this->assertIsString($result);
        $this->assertSame('', $result);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
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

    public function testGetRepository(): void
    {
        $repositoryName = 'test-get-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $result = $this->vcsAdapter->getRepository(static::$owner, $repositoryName);

        $this->assertIsArray($result);
        $this->assertSame($repositoryName, $result['name']);
        $this->assertSame(static::$owner, $result['owner']['login']);
        $this->assertTrue($this->vcsAdapter->deleteRepository(static::$owner, $repositoryName));
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

    public function testGetRepositoryNameWithInvalidId(): void
    {
        try {
            $this->vcsAdapter->getRepositoryName('999999999');
            $this->fail('Expected exception for non-existing repository ID');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    public function testCreateRepositoryWithInvalidName(): void
    {
        $repositoryName = 'invalid name with spaces';

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
            $this->fail('Expected exception for invalid repository name');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    public function testGetRepositoryWithNonExistingOwner(): void
    {
        $repositoryName = 'test-non-existing-owner-' . \uniqid();

        try {
            $this->vcsAdapter->getRepository('non-existing-owner-' . \uniqid(), $repositoryName);
            $this->fail('Expected exception for non-existing owner');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    public function testGetRepositoryTree(): void
    {
        $repositoryName = 'test-get-repository-tree-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        // Create files in repo
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test Repo');
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/main.php', '<?php echo "hello";');
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/lib.php', '<?php // library');

        // Test non-recursive (should only show root level)
        $tree = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, static::$defaultBranch, false);

        $this->assertIsArray($tree);
        $this->assertContains('README.md', $tree);
        $this->assertContains('src', $tree);
        $this->assertCount(2, $tree); // Only README.md and src folder at root

        // Test recursive (should show all files including nested)
        $treeRecursive = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, static::$defaultBranch, true);

        $this->assertIsArray($treeRecursive);
        $this->assertContains('README.md', $treeRecursive);
        $this->assertContains('src', $treeRecursive);
        $this->assertContains('src/main.php', $treeRecursive);
        $this->assertContains('src/lib.php', $treeRecursive);
        $this->assertGreaterThanOrEqual(4, count($treeRecursive));

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetRepositoryTreeWithInvalidBranch(): void
    {
        $repositoryName = 'test-get-repository-tree-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        $tree = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, 'non-existing-branch', false);

        $this->assertIsArray($tree);
        $this->assertEmpty($tree);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetRepositoryContent(): void
    {
        $repositoryName = 'test-get-repository-content-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $fileContent = '# Hello World';
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', $fileContent);

        $result = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'README.md');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('sha', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertSame($fileContent, $result['content']);
        $this->assertIsString($result['sha']);
        $this->assertGreaterThan(0, $result['size']);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetRepositoryContentWithRef(): void
    {
        $repositoryName = 'test-get-repository-content-ref-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'main branch content');

        $result = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'test.txt', static::$defaultBranch);

        $this->assertIsArray($result);
        $this->assertSame('main branch content', $result['content']);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetRepositoryContentFileNotFound(): void
    {
        $repositoryName = 'test-get-repository-content-not-found-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        $this->expectException(\Utopia\VCS\Exception\FileNotFound::class);
        $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'non-existing.txt');

    }

    public function testListRepositoryContents(): void
    {
        $repositoryName = 'test-list-repository-contents-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'file1.txt', 'content1');
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/main.php', '<?php');

        // List root directory
        $contents = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName);

        $this->assertIsArray($contents);
        $this->assertCount(3, $contents); // README.md, file1.txt, src folder

        $names = array_column($contents, 'name');
        $this->assertContains('README.md', $names);
        $this->assertContains('file1.txt', $names);
        $this->assertContains('src', $names);

        // Verify types
        foreach ($contents as $item) {
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('type', $item);
            $this->assertArrayHasKey('size', $item);
        }

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testListRepositoryContentsInSubdirectory(): void
    {
        $repositoryName = 'test-list-repository-contents-subdir-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/file1.php', '<?php');
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/file2.php', '<?php');

        $contents = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'src');

        $this->assertIsArray($contents);
        $this->assertCount(2, $contents);

        $names = array_column($contents, 'name');
        $this->assertContains('file1.php', $names);
        $this->assertContains('file2.php', $names);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testListRepositoryContentsNonExistingPath(): void
    {
        $repositoryName = 'test-list-repository-contents-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        $contents = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'non-existing-path');

        $this->assertIsArray($contents);
        $this->assertEmpty($contents);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetPullRequest(): void
    {
        $repositoryName = 'test-get-pull-request-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

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

        $prNumber = $pr['number'] ?? 0;
        $this->assertGreaterThan(0, $prNumber);

        // Now test getPullRequest
        $result = $this->vcsAdapter->getPullRequest(static::$owner, $repositoryName, $prNumber);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('number', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('head', $result);
        $this->assertArrayHasKey('base', $result);

        $this->assertSame($prNumber, $result['number']);
        $this->assertSame('Test PR', $result['title']);
        $this->assertSame('open', $result['state']);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetPullRequestFiles(): void
    {
        $repositoryName = 'test-get-pull-request-files-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

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

        $prNumber = $pr['number'] ?? 0;
        $this->assertGreaterThan(0, $prNumber);

        $result = $this->vcsAdapter->getPullRequestFiles(static::$owner, $repositoryName, $prNumber);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        $filenames = array_column($result, 'filename');
        $this->assertContains('feature.txt', $filenames);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetPullRequestWithInvalidNumber(): void
    {
        $repositoryName = 'test-get-pull-request-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->getPullRequest(static::$owner, $repositoryName, 99999);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }

    }

    public function testGenerateCloneCommand(): void
    {
        $repositoryName = 'test-clone-command-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $command = $this->vcsAdapter->generateCloneCommand(
                static::$owner,
                $repositoryName,
                static::$defaultBranch,
                \Utopia\VCS\Adapter\Git::CLONE_TYPE_BRANCH,
                '/tmp/test-clone-' . \uniqid(),
                '/'
            );

            $this->assertIsString($command);
            $this->assertStringContainsString('git init', $command);
            $this->assertStringContainsString('git remote add origin', $command);
            $this->assertStringContainsString('git config core.sparseCheckout true', $command);
            $this->assertStringContainsString($repositoryName, $command);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
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

            $command = $this->vcsAdapter->generateCloneCommand(
                static::$owner,
                $repositoryName,
                $commitHash,
                \Utopia\VCS\Adapter\Git::CLONE_TYPE_COMMIT,
                '/tmp/test-clone-commit-' . \uniqid(),
                '/'
            );
            $this->assertIsString($command);
            $this->assertStringContainsString('git fetch --depth=1', $command);
            $this->assertStringContainsString($commitHash, $command);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
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

    public function testGenerateCloneCommandWithInvalidRepository(): void
    {
        $directory = '/tmp/test-clone-invalid-' . \uniqid();

        try {
            $command = $this->vcsAdapter->generateCloneCommand(
                'nonexistent-owner-' . \uniqid(),
                'nonexistent-repo-' . \uniqid(),
                static::$defaultBranch,
                \Utopia\VCS\Adapter\Git::CLONE_TYPE_BRANCH,
                $directory,
                '/'
            );

            $output = [];
            exec($command . ' 2>&1', $output, $exitCode);

            $cloneFailed = ($exitCode !== 0) || !file_exists($directory . '/README.md');

            $this->assertTrue(
                $cloneFailed,
                'Clone should have failed for nonexistent repository. Exit code: ' . $exitCode
            );
        } finally {
            if (\is_dir($directory)) {
                exec('rm -rf ' . escapeshellarg($directory));
            }
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

            // Create PR
            $pr = $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Test PR',
                'test-branch',
                static::$defaultBranch
            );

            $prNumber = $pr['number'] ?? 0;
            $this->assertGreaterThan(0, $prNumber);

            // Create comment
            $commentId = $this->vcsAdapter->createComment(static::$owner, $repositoryName, $prNumber, 'Original comment');

            // Test updateComment
            $updatedCommentId = $this->vcsAdapter->updateComment(static::$owner, $repositoryName, (int)$commentId, 'Updated comment');

            $this->assertSame($commentId, $updatedCommentId);

            // Verify comment was updated
            $finalComment = $this->vcsAdapter->getComment(static::$owner, $repositoryName, $commentId);
            $this->assertSame('Updated comment', $finalComment);
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
                'Tests passed',
                'https://example.com/build/123',
                'ci/tests'
            );

            $statuses = $this->vcsAdapter->getCommitStatuses(static::$owner, $repositoryName, $commitHash);
            $this->assertIsArray($statuses);
            $this->assertNotEmpty($statuses);

            $found = false;
            foreach ($statuses as $status) {
                if (($status['context'] ?? '') === 'ci/tests') {
                    $this->assertSame('success', $status['status'] ?? '');
                    $this->assertSame('Tests passed', $status['description'] ?? '');
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'Expected status with context ci/tests was not found');
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

    public function testGetCommit(): void
    {
        $repositoryName = 'test-get-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $customMessage = 'Test commit message';
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test Commit', $customMessage);

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
        $this->assertSame('utopia', $result['commitAuthor']);
        $this->assertStringStartsWith($customMessage, $result['commitMessage']);
        $this->assertStringContainsString($this->avatarDomain, $result['commitAuthorAvatar']);
        $this->assertNotEmpty($result['commitUrl']);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetLatestCommit(): void
    {
        $repositoryName = 'test-get-latest-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $firstMessage = 'First commit';
        $secondMessage = 'Second commit';
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test', $firstMessage);

        $commit1 = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);

        $this->assertIsArray($commit1);
        $this->assertArrayHasKey('commitHash', $commit1);
        $this->assertArrayHasKey('commitMessage', $commit1);
        $this->assertArrayHasKey('commitAuthor', $commit1);
        $this->assertArrayHasKey('commitUrl', $commit1);
        $this->assertArrayHasKey('commitAuthorAvatar', $commit1);
        $this->assertArrayHasKey('commitAuthorUrl', $commit1);

        $this->assertNotEmpty($commit1['commitHash']);
        $this->assertSame('utopia', $commit1['commitAuthor']);
        $this->assertStringStartsWith($firstMessage, $commit1['commitMessage']);
        $this->assertStringContainsString($this->avatarDomain, $commit1['commitAuthorAvatar']);
        $this->assertNotEmpty($commit1['commitUrl']);

        $commit1Hash = $commit1['commitHash'];

        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test content', $secondMessage);

        $commit2 = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);

        $this->assertIsArray($commit2);
        $this->assertNotEmpty($commit2['commitHash']);
        $this->assertStringStartsWith($secondMessage, $commit2['commitMessage']);

        $commit2Hash = $commit2['commitHash'];

        $this->assertNotSame($commit1Hash, $commit2Hash);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetCommitWithInvalidSha(): void
    {
        $repositoryName = 'test-get-commit-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

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

    public function testGetEventUnsupportedEvent(): void
    {
        $payload = json_encode(['test' => 'data']);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('unsupported_event', $payload);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testSearchRepositories(): void
    {
        // Create multiple repositories
        $repo1Name = 'test-search-repo1-' . \uniqid();
        $repo2Name = 'test-search-repo2-' . \uniqid();
        $repo3Name = 'other-repo-' . \uniqid();

        $this->vcsAdapter->createRepository(static::$owner, $repo1Name, false);
        $this->vcsAdapter->createRepository(static::$owner, $repo2Name, false);
        $this->vcsAdapter->createRepository(static::$owner, $repo3Name, false);

        try {
            // Search without filter - should return all
            $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('items', $result);
            $this->assertArrayHasKey('total', $result);
            $this->assertGreaterThanOrEqual(3, $result['total']);

            // Search with filter
            $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10, 'test-search');

            $this->assertIsArray($result);
            $this->assertGreaterThanOrEqual(2, $result['total']);

            // Verify the filtered repos are in results
            $repoNames = array_column($result['items'], 'name');
            $this->assertContains($repo1Name, $repoNames);
            $this->assertContains($repo2Name, $repoNames);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repo1Name);
            $this->vcsAdapter->deleteRepository(static::$owner, $repo2Name);
            $this->vcsAdapter->deleteRepository(static::$owner, $repo3Name);
        }
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

    public function testDeleteRepository(): void
    {
        $repositoryName = 'test-delete-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $result = $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);

        $this->assertTrue($result);
    }

    public function testDeleteRepositoryTwiceFails(): void
    {
        $repositoryName = 'test-delete-repository-' . \uniqid();
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

    public function testGetUser(): void
    {
        // Get current authenticated user's info
        $ownerInfo = $this->vcsAdapter->getUser(static::$owner);

        $this->assertIsArray($ownerInfo);
        $this->assertArrayHasKey('login', $ownerInfo);
        $this->assertArrayHasKey('id', $ownerInfo);
        $this->assertSame(static::$owner, $ownerInfo['login']);
    }

    public function testGetUserWithInvalidUsername(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->getUser('non-existent-user-' . \uniqid());
    }

    public function testGetInstallationRepository(): void
    {
        // This method is not applicable for this adapter
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not applicable for this adapter');

        $this->vcsAdapter->getInstallationRepository('any-repo-name');
    }

    public function testGetPullRequestFromBranch(): void
    {
        $repositoryName = 'test-get-pr-from-branch-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'my-feature', static::$defaultBranch);
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'feature.txt', 'content', 'Add feature', 'my-feature');

        // Create PR
        $pr = $this->vcsAdapter->createPullRequest(
            static::$owner,
            $repositoryName,
            'Feature PR',
            'my-feature',
            static::$defaultBranch
        );

        $this->assertArrayHasKey('number', $pr);

        // Test getPullRequestFromBranch
        $result = $this->vcsAdapter->getPullRequestFromBranch(static::$owner, $repositoryName, 'my-feature');

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('head', $result);

        $resultHead = $result['head'] ?? [];
        $this->assertSame('my-feature', $resultHead['ref'] ?? '');

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testGetPullRequestFromBranchNoPR(): void
    {
        $repositoryName = 'test-get-pr-no-pr-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'lonely-branch', static::$defaultBranch);

        // Don't create a PR - just test the method
        $result = $this->vcsAdapter->getPullRequestFromBranch(static::$owner, $repositoryName, 'lonely-branch');

        $this->assertIsArray($result);
        $this->assertEmpty($result);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testCreateComment(): void
    {
        $repositoryName = 'test-create-comment-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'test-branch', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', 'Add test', 'test-branch');

            // Create PR
            $pr = $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Test PR',
                'test-branch',
                static::$defaultBranch
            );

            $prNumber = $pr['number'] ?? 0;
            $this->assertGreaterThan(0, $prNumber);

            // Test createComment
            $commentId = $this->vcsAdapter->createComment(static::$owner, $repositoryName, $prNumber, 'Test comment');

            $this->assertNotEquals('', $commentId);
            $this->assertIsString($commentId);
            $this->assertIsNumeric($commentId);

            // Verify comment was created
            $retrievedComment = $this->vcsAdapter->getComment(static::$owner, $repositoryName, $commentId);
            $this->assertSame('Test comment', $retrievedComment);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testCreateFile(): void
    {
        $repositoryName = 'test-create-file-'.\uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $result = $this->vcsAdapter->createFile(
                static::$owner,
                $repositoryName,
                'test.md',
                '# Test',
                'Add test file'
            );

            $this->assertIsArray($result);
            $this->assertNotEmpty($result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testCreateFileOnBranch(): void
    {
        $repositoryName = 'test-create-file-branch-'.\uniqid();
        $res = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Main');
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature', static::$defaultBranch);

            // Create file on specific branch
            $result = $this->vcsAdapter->createFile(
                static::$owner,
                $repositoryName,
                'feature.md',
                '# Feature',
                'Add feature file',
                'feature'  // ← Branch parameter
            );

            $this->assertIsArray($result);

            // Verify it's on the right branch
            $content = $this->vcsAdapter->getRepositoryContent(
                static::$owner,
                $repositoryName,
                'feature.md',
                'feature'
            );
            $this->assertSame('# Feature', $content['content']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListBranches(): void
    {
        $repositoryName = 'test-list-branches-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            // Create initial file on main branch
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            // Create additional branches
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-1', static::$defaultBranch);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-2', static::$defaultBranch);

            $branches = [];
            $maxAttempts = 10;
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $branches = $this->vcsAdapter->listBranches(static::$owner, $repositoryName);

                if (in_array('feature-1', $branches, true) && in_array('feature-2', $branches, true)) {
                    break;
                }

                usleep(500000);
            }

            $this->assertIsArray($branches);
            $this->assertNotEmpty($branches);
            $this->assertContains(static::$defaultBranch, $branches);
            $this->assertContains('feature-1', $branches);
            $this->assertContains('feature-2', $branches);
            $this->assertGreaterThanOrEqual(3, count($branches));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
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

    public function testListRepositoryLanguages(): void
    {
        $repositoryName = 'test-list-repository-languages-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'main.php', '<?php echo "test";');
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'script.js', 'console.log("test");');
        $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'style.css', 'body { margin: 0; }');

        sleep(2);

        $languages = $this->vcsAdapter->listRepositoryLanguages(static::$owner, $repositoryName);

        $this->assertIsArray($languages);
        $this->assertNotEmpty($languages);
        $this->assertContains('PHP', $languages);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testListRepositoryLanguagesEmptyRepo(): void
    {
        $repositoryName = 'test-list-repository-languages-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        $languages = $this->vcsAdapter->listRepositoryLanguages(static::$owner, $repositoryName);

        $this->assertIsArray($languages);
        $this->assertEmpty($languages);

        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
    }

    public function testWebhookPushEvent(): void
    {
        $repositoryName = 'test-webhook-push-' . \uniqid();
        $secret = 'test-webhook-secret-' . \uniqid();

        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $catcherUrl = System::getEnv('TESTS_GITEA_REQUEST_CATCHER_URL', 'http://request-catcher:5000');
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

            $catcherUrl = System::getEnv('TESTS_GITEA_REQUEST_CATCHER_URL', 'http://request-catcher:5000');
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
