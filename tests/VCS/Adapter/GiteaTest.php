<?php

namespace Utopia\Tests\VCS\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\Gitea;

class GiteaTest extends Base
{
    private static string $accessToken = '';
    private static string $owner = '';

    protected function createVCSAdapter(): Git
    {
        return new Gitea(new Cache(new None()));
    }

    public function setUp(): void
    {
        if (empty(self::$accessToken)) {
            $this->setupGitea();
        }

        $adapter = new Gitea(new Cache(new None()));
        $giteaUrl = System::getEnv('TESTS_GITEA_URL', 'http://gitea:3000') ?? '';

        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: self::$accessToken,
            refreshToken: ''
        );
        $adapter->setEndpoint($giteaUrl);
        if (empty(self::$owner)) {
            $orgName = 'test-org-' . \uniqid();
            self::$owner = $adapter->createOrganization($orgName);
        }

        $this->vcsAdapter = $adapter;
    }

    private function setupGitea(): void
    {
        $tokenFile = '/data/gitea/token.txt';

        if (file_exists($tokenFile)) {
            $contents = file_get_contents($tokenFile);
            if ($contents !== false) {
                self::$accessToken = trim($contents);
            }
        }
    }
    public function testCreateRepository(): void
    {
        $owner = self::$owner;
        $repositoryName = 'test-create-repository-' . \uniqid();

        $result = $this->vcsAdapter->createRepository($owner, $repositoryName, false);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertSame($repositoryName, $result['name']);
        $this->assertArrayHasKey('owner', $result);
        $this->assertSame($owner, $result['owner']['login']);
        $this->assertFalse($result['private']);

        $this->assertTrue($this->vcsAdapter->deleteRepository(self::$owner, $repositoryName));
    }

    public function testGetDeletedRepositoryFails(): void
    {
        $repositoryName = 'test-get-deleted-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);

        $this->expectException(\Exception::class);
        $this->vcsAdapter->getRepository(self::$owner, $repositoryName);
    }

    public function testCreatePrivateRepository(): void
    {
        $repositoryName = 'test-create-private-repository-' . \uniqid();

        $result = $this->vcsAdapter->createRepository(self::$owner, $repositoryName, true);

        $this->assertIsArray($result);
        $this->assertTrue($result['private']);

        // Verify with getRepository
        $fetched = $this->vcsAdapter->getRepository(self::$owner, $repositoryName);
        $this->assertTrue($fetched['private']);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testCommentWorkflow(): void
    {
        $repositoryName = 'test-comment-workflow-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(self::$owner, $repositoryName, 'comment-test', 'main');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'test.txt', 'test', 'Add test file', 'comment-test');

        $pr = $this->vcsAdapter->createPullRequest(
            self::$owner,
            $repositoryName,
            'Comment Test PR',
            'comment-test',
            'main'
        );

        $prNumber = $pr['number'] ?? 0;
        $this->assertGreaterThan(0, $prNumber);

        $originalComment = 'This is a test comment';
        $commentId = $this->vcsAdapter->createComment(self::$owner, $repositoryName, $prNumber, $originalComment);

        $this->assertNotEmpty($commentId);
        $this->assertIsString($commentId);

        // Test getComment
        $retrievedComment = $this->vcsAdapter->getComment(self::$owner, $repositoryName, $commentId);

        $this->assertSame($originalComment, $retrievedComment);
        $this->assertIsString($commentId);
        $this->assertNotEmpty($commentId);

        // Test updateComment
        $updatedCommentText = 'This comment has been updated';
        $updatedCommentId = $this->vcsAdapter->updateComment(self::$owner, $repositoryName, (int)$commentId, $updatedCommentText);

        $this->assertSame($commentId, $updatedCommentId);

        // Verify the update
        $finalComment = $this->vcsAdapter->getComment(self::$owner, $repositoryName, $commentId);
        $this->assertSame($updatedCommentText, $finalComment);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetComment(): void
    {
        $repositoryName = 'test-get-comment-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(self::$owner, $repositoryName, 'test-branch', 'main');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'test.txt', 'test', 'Add test', 'test-branch');

        // Create PR
        $pr = $this->vcsAdapter->createPullRequest(
            self::$owner,
            $repositoryName,
            'Test PR',
            'test-branch',
            'main'
        );

        $prNumber = $pr['number'] ?? 0;

        // Create a comment
        $commentId = $this->vcsAdapter->createComment(self::$owner, $repositoryName, $prNumber, 'Test comment');

        // Test getComment
        $result = $this->vcsAdapter->getComment(self::$owner, $repositoryName, $commentId);

        $this->assertIsString($result);
        $this->assertSame('Test comment', $result);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testCreateCommentInvalidPR(): void
    {
        $repositoryName = 'test-comment-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->createComment(self::$owner, $repositoryName, 99999, 'Test comment');
        } finally {
            $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
        }
    }

    public function testGetCommentInvalidId(): void
    {
        $repositoryName = 'test-get-comment-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');

        // Getting invalid comment should return empty string
        $result = $this->vcsAdapter->getComment(self::$owner, $repositoryName, '99999999');

        $this->assertIsString($result);
        // May be empty or throw exception depending on API

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetRepositoryTreeWithSlashInBranchName(): void
    {
        $repositoryName = 'test-branch-with-slash-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(self::$owner, $repositoryName, 'feature/test-branch', 'main');

        $tree = $this->vcsAdapter->getRepositoryTree(self::$owner, $repositoryName, 'feature/test-branch');

        $this->assertIsArray($tree);
        $this->assertNotEmpty($tree);
        $this->assertContains('README.md', $tree);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetRepository(): void
    {
        $repositoryName = 'test-get-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $result = $this->vcsAdapter->getRepository(self::$owner, $repositoryName);

        $this->assertIsArray($result);
        $this->assertSame($repositoryName, $result['name']);
        $this->assertSame(self::$owner, $result['owner']['login']);
        $this->assertTrue($this->vcsAdapter->deleteRepository(self::$owner, $repositoryName));
    }

    public function testGetRepositoryName(): void
    {
        $repositoryName = 'test-get-repository-name-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->assertIsArray($created);
        $this->assertArrayHasKey('id', $created);
        $this->assertIsScalar($created['id']);
        $repositoryId = (string) $created['id'];
        $result = $this->vcsAdapter->getRepositoryName($repositoryId);

        $this->assertSame($repositoryName, $result);
        $this->assertTrue($this->vcsAdapter->deleteRepository(self::$owner, $repositoryName));
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
            $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
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
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        // Create files in repo
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test Repo');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'src/main.php', '<?php echo "hello";');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'src/lib.php', '<?php // library');

        // Test non-recursive (should only show root level)
        $tree = $this->vcsAdapter->getRepositoryTree(self::$owner, $repositoryName, 'main', false);

        $this->assertIsArray($tree);
        $this->assertContains('README.md', $tree);
        $this->assertContains('src', $tree);
        $this->assertCount(2, $tree); // Only README.md and src folder at root

        // Test recursive (should show all files including nested)
        $treeRecursive = $this->vcsAdapter->getRepositoryTree(self::$owner, $repositoryName, 'main', true);

        $this->assertIsArray($treeRecursive);
        $this->assertContains('README.md', $treeRecursive);
        $this->assertContains('src', $treeRecursive);
        $this->assertContains('src/main.php', $treeRecursive);
        $this->assertContains('src/lib.php', $treeRecursive);
        $this->assertGreaterThanOrEqual(4, count($treeRecursive));

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetRepositoryTreeWithInvalidBranch(): void
    {
        $repositoryName = 'test-get-repository-tree-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');

        $tree = $this->vcsAdapter->getRepositoryTree(self::$owner, $repositoryName, 'non-existing-branch', false);

        $this->assertIsArray($tree);
        $this->assertEmpty($tree);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetRepositoryContent(): void
    {
        $repositoryName = 'test-get-repository-content-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $fileContent = '# Hello World';
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', $fileContent);

        $result = $this->vcsAdapter->getRepositoryContent(self::$owner, $repositoryName, 'README.md');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('sha', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertSame($fileContent, $result['content']);
        $this->assertIsString($result['sha']);
        $this->assertGreaterThan(0, $result['size']);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetRepositoryContentWithRef(): void
    {
        $repositoryName = 'test-get-repository-content-ref-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'test.txt', 'main branch content');

        $result = $this->vcsAdapter->getRepositoryContent(self::$owner, $repositoryName, 'test.txt', 'main');

        $this->assertIsArray($result);
        $this->assertSame('main branch content', $result['content']);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetRepositoryContentFileNotFound(): void
    {
        $repositoryName = 'test-get-repository-content-not-found-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');

        $this->expectException(\Utopia\VCS\Exception\FileNotFound::class);
        $this->vcsAdapter->getRepositoryContent(self::$owner, $repositoryName, 'non-existing.txt');

    }

    public function testListRepositoryContents(): void
    {
        $repositoryName = 'test-list-repository-contents-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'file1.txt', 'content1');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'src/main.php', '<?php');

        // List root directory
        $contents = $this->vcsAdapter->listRepositoryContents(self::$owner, $repositoryName);

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

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testListRepositoryContentsInSubdirectory(): void
    {
        $repositoryName = 'test-list-repository-contents-subdir-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'src/file1.php', '<?php');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'src/file2.php', '<?php');

        $contents = $this->vcsAdapter->listRepositoryContents(self::$owner, $repositoryName, 'src');

        $this->assertIsArray($contents);
        $this->assertCount(2, $contents);

        $names = array_column($contents, 'name');
        $this->assertContains('file1.php', $names);
        $this->assertContains('file2.php', $names);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testListRepositoryContentsNonExistingPath(): void
    {
        $repositoryName = 'test-list-repository-contents-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');

        $contents = $this->vcsAdapter->listRepositoryContents(self::$owner, $repositoryName, 'non-existing-path');

        $this->assertIsArray($contents);
        $this->assertEmpty($contents);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetPullRequest(): void
    {
        $repositoryName = 'test-get-pull-request-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(self::$owner, $repositoryName, 'feature-branch', 'main');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'feature.txt', 'feature content', 'Add feature', 'feature-branch');

        $pr = $this->vcsAdapter->createPullRequest(
            self::$owner,
            $repositoryName,
            'Test PR',
            'feature-branch',
            'main',
            'Test PR description'
        );

        $prNumber = $pr['number'] ?? 0;
        $this->assertGreaterThan(0, $prNumber);

        // Now test getPullRequest
        $result = $this->vcsAdapter->getPullRequest(self::$owner, $repositoryName, $prNumber);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('number', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('head', $result);
        $this->assertArrayHasKey('base', $result);

        $this->assertSame($prNumber, $result['number']);
        $this->assertSame('Test PR', $result['title']);
        $this->assertSame('open', $result['state']);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetPullRequestWithInvalidNumber(): void
    {
        $repositoryName = 'test-get-pull-request-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');

        // Try to get non-existent PR
        $result = $this->vcsAdapter->getPullRequest(self::$owner, $repositoryName, 99999);

        // Should return empty or have error handling
        $this->assertIsArray($result);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGenerateCloneCommand(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGenerateCloneCommandWithCommitHash(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testUpdateComment(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetCommit(): void
    {
        $repositoryName = 'test-get-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $customMessage = 'Test commit message';
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test Commit', $customMessage);

        $latestCommit = $this->vcsAdapter->getLatestCommit(self::$owner, $repositoryName, 'main');
        $commitHash = $latestCommit['commitHash'];

        $result = $this->vcsAdapter->getCommit(self::$owner, $repositoryName, $commitHash);

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
        $this->assertStringContainsString('gravatar.com', $result['commitAuthorAvatar']);
        $this->assertNotEmpty($result['commitUrl']);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetLatestCommit(): void
    {
        $repositoryName = 'test-get-latest-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $firstMessage = 'First commit';
        $secondMessage = 'Second commit';
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test', $firstMessage);

        $commit1 = $this->vcsAdapter->getLatestCommit(self::$owner, $repositoryName, 'main');

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
        $this->assertStringContainsString('gravatar.com', $commit1['commitAuthorAvatar']);
        $this->assertNotEmpty($commit1['commitUrl']);

        $commit1Hash = $commit1['commitHash'];

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'test.txt', 'test content', $secondMessage);

        $commit2 = $this->vcsAdapter->getLatestCommit(self::$owner, $repositoryName, 'main');

        $this->assertIsArray($commit2);
        $this->assertNotEmpty($commit2['commitHash']);
        $this->assertStringStartsWith($secondMessage, $commit2['commitMessage']);

        $commit2Hash = $commit2['commitHash'];

        $this->assertNotSame($commit1Hash, $commit2Hash);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetCommitWithInvalidSha(): void
    {
        $repositoryName = 'test-get-commit-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->getCommit(self::$owner, $repositoryName, 'invalid-sha-12345');
        } finally {
            $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
        }
    }

    public function testGetLatestCommitWithInvalidBranch(): void
    {
        $repositoryName = 'test-get-latest-commit-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->getLatestCommit(self::$owner, $repositoryName, 'non-existing-branch');
        } finally {
            $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
        }
    }

    public function testGetEvent(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }
    public function testSearchRepositories(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testDeleteRepository(): void
    {
        $repositoryName = 'test-delete-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $result = $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);

        $this->assertTrue($result);
    }

    public function testDeleteRepositoryTwiceFails(): void
    {
        $repositoryName = 'test-delete-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);
        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);

        $this->expectException(\Exception::class);
        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testDeleteNonExistingRepositoryFails(): void
    {
        $this->expectException(\Exception::class);
        $this->vcsAdapter->deleteRepository(self::$owner, 'non-existing-repo-' . \uniqid());
    }

    public function testGetOwnerName(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetPullRequestFromBranch(): void
    {
        $repositoryName = 'test-get-pr-from-branch-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(self::$owner, $repositoryName, 'my-feature', 'main');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'feature.txt', 'content', 'Add feature', 'my-feature');

        // Create PR
        $pr = $this->vcsAdapter->createPullRequest(
            self::$owner,
            $repositoryName,
            'Feature PR',
            'my-feature',
            'main'
        );

        $this->assertArrayHasKey('number', $pr);

        // Test getPullRequestFromBranch
        $result = $this->vcsAdapter->getPullRequestFromBranch(self::$owner, $repositoryName, 'my-feature');

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('head', $result);

        $resultHead = $result['head'] ?? [];
        $this->assertSame('my-feature', $resultHead['ref'] ?? '');

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testGetPullRequestFromBranchNoPR(): void
    {
        $repositoryName = 'test-get-pr-no-pr-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'README.md', '# Test');
        $this->vcsAdapter->createBranch(self::$owner, $repositoryName, 'lonely-branch', 'main');

        // Don't create a PR - just test the method
        $result = $this->vcsAdapter->getPullRequestFromBranch(self::$owner, $repositoryName, 'lonely-branch');

        $this->assertIsArray($result);
        $this->assertEmpty($result);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testCreateComment(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testListBranches(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testListRepositoryLanguages(): void
    {
        $repositoryName = 'test-list-repository-languages-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'main.php', '<?php echo "test";');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'script.js', 'console.log("test");');
        $this->vcsAdapter->createFile(self::$owner, $repositoryName, 'style.css', 'body { margin: 0; }');

        sleep(2);

        $languages = $this->vcsAdapter->listRepositoryLanguages(self::$owner, $repositoryName);

        $this->assertIsArray($languages);
        $this->assertNotEmpty($languages);
        $this->assertContains('PHP', $languages);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }

    public function testListRepositoryLanguagesEmptyRepo(): void
    {
        $repositoryName = 'test-list-repository-languages-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(self::$owner, $repositoryName, false);

        $languages = $this->vcsAdapter->listRepositoryLanguages(self::$owner, $repositoryName);

        $this->assertIsArray($languages);
        $this->assertEmpty($languages);

        $this->vcsAdapter->deleteRepository(self::$owner, $repositoryName);
    }
}
