<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Adapter\Git\GitHub;

abstract class Base extends TestCase
{
    protected Git $vcsAdapter;

    protected function setUp(): void
    {
        $this->vcsAdapter = $this->createVCSAdapter();
    }

    abstract protected function createVCSAdapter(): Git;

    abstract public function testUpdateComment(): void;

    abstract public function testGenerateCloneCommand(): void;

    abstract public function testGenerateCloneCommandWithCommitHash(): void;

    abstract public function testGetEvent(): void;

    abstract public function testGetRepositoryName(): void;

    abstract public function testGetComment(): void;

    abstract public function testGetPullRequest(): void;

    abstract public function testGetRepositoryTree(): void;

    public function testGetPullRequestFromBranch(): void
    {
        $result = $this->vcsAdapter->getPullRequestFromBranch('vermakhushboo', 'basic-js-crud', 'test');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testGetOwnerName(): void
    {
        $installationId = System::getEnv('TESTS_GITHUB_INSTALLATION_ID') ?? '';
        $owner = $this->vcsAdapter->getOwnerName($installationId);
        $this->assertSame('test-kh', $owner);
    }

    public function testSearchRepositories(): void
    {
        $installationId = System::getEnv('TESTS_GITHUB_INSTALLATION_ID') ?? '';
        ['items' => $repos, 'total' => $total] = $this->vcsAdapter->searchRepositories($installationId, 'test-kh', 1, 2);
        $this->assertCount(2, $repos);
        $this->assertSame(6, $total);
    }

    public function testCreateComment(): void
    {
        $commentId = $this->vcsAdapter->createComment('test-kh', 'test2', 1, 'hello');
        $this->assertNotEmpty($commentId);
    }

    public function testListBranches(): void
    {
        $branches = $this->vcsAdapter->listBranches('vermakhushboo', 'basic-js-crud');
        $this->assertIsArray($branches);
        $this->assertNotEmpty($branches);
    }

    public function testListRepositoryLanguages(): void
    {
        $languages = $this->vcsAdapter->listRepositoryLanguages('vermakhushboo', 'basic-js-crud');

        $this->assertIsArray($languages);

        $this->assertContains('JavaScript', $languages);
        $this->assertContains('HTML', $languages);
        $this->assertContains('CSS', $languages);
    }

    public function testListRepositoryContents(): void
    {
        $contents = $this->vcsAdapter->listRepositoryContents('appwrite', 'appwrite', 'src/Appwrite');
        $this->assertIsArray($contents);
        $this->assertNotEmpty($contents);

        $contents = $this->vcsAdapter->listRepositoryContents('appwrite', 'appwrite', '');
        $this->assertIsArray($contents);
        $this->assertNotEmpty($contents);
        $this->assertGreaterThan(0, \count($contents));

        // Test with ref parameter
        $contents = $this->vcsAdapter->listRepositoryContents('appwrite', 'appwrite', '', 'main');
        $this->assertIsArray($contents);
        $this->assertNotEmpty($contents);
        $this->assertGreaterThan(0, \count($contents));

        $fileContent = null;
        foreach ($contents as $content) {
            if ($content['type'] === GitHub::CONTENTS_FILE) {
                $fileContent = $content;
                break;
            }
        }
        $this->assertNotNull($fileContent);
        $this->assertNotEmpty($fileContent['name']);
        $this->assertStringContainsString('.', $fileContent['name']);
        $this->assertIsNumeric($fileContent['size']);
        $this->assertGreaterThan(0, $fileContent['size']);

        $directoryContent = null;
        foreach ($contents as $content) {
            if ($content['type'] === GitHub::CONTENTS_DIRECTORY) {
                $directoryContent = $content;
                break;
            }
        }
        $this->assertNotNull($directoryContent);
        $this->assertNotEmpty($directoryContent['name']);
        $this->assertIsNumeric($directoryContent['size']);
        $this->assertSame(0, $directoryContent['size']);
    }

    public function testCreateRepository(): void
    {
        $repository = $this->vcsAdapter->createRepository('test-kh', 'new-repo', true);
        $this->assertIsArray($repository);
        $this->assertSame('test-kh/new-repo', $repository['full_name']);
    }

    /**
     * @depends testCreateRepository
     */
    public function testDeleteRepository(): void
    {
        $result = $this->vcsAdapter->deleteRepository('test-kh', 'new-repo');
        $this->assertSame(true, $result);
        $this->expectException(Exception::class);
        $result = $this->vcsAdapter->deleteRepository('test-kh', 'new-repo-2');
    }
}
