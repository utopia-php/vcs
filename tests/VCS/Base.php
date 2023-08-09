<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\App;
use Utopia\VCS\Adapter\Git;

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

    abstract public function testgetEvent(): void;

    abstract public function testGetRepositoryName(): void;

    abstract public function testGetPullRequest(): void;

    abstract public function testGetComment(): void;

    public function testGetOwnerName(): void
    {
        $installationId = App::getEnv('INSTALLATION_ID') ?? '';
        $owner = $this->vcsAdapter->getOwnerName($installationId);
        $this->assertEquals('test-kh', $owner);
    }

    public function testListRepositories(): void
    {
        $repos = $this->vcsAdapter->listRepositoriesForVCSApp(1, 2);
        $this->assertCount(2, $repos);
    }

    public function testGetTotalReposCount(): void
    {
        $count = $this->vcsAdapter->getTotalReposCount();
        $this->assertGreaterThanOrEqual(0, $count);
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

    public function testGetRepositoryLanguages(): void
    {
        $languages = $this->vcsAdapter->getRepositoryLanguages('vermakhushboo', 'basic-js-crud');

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
    }

    public function testGetBranchPullRequest(): void
    {
        $result = $this->vcsAdapter->getBranchPullRequest('vermakhushboo', 'basic-js-crud', 'test');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }
}
