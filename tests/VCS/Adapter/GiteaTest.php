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

    public function testGetComment(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
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
        $this->markTestSkipped('Will be implemented in follow-up PR');
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
        $this->markTestSkipped('Will be implemented in follow-up PR');
    }

    public function testGetLatestCommit(): void
    {
        $this->markTestSkipped('Will be implemented in follow-up PR');
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
        $this->markTestSkipped('Will be implemented in follow-up PR');
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
