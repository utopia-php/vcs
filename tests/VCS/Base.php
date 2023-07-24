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

    public function testDownloadRepositoryZip(): void
    {
        // download the zip archive of the repo
        $zipContents = $this->vcsAdapter->downloadRepositoryZip('test-kh', 'test2', 'main');

        // Save the ZIP archive to a file
        file_put_contents('./hello-world.zip', $zipContents);

        // Assert that the file was saved successfully
        $this->assertFileExists('./hello-world.zip');
    }

    public function testDownloadRepositoryTar(): void
    {
        // download the tar archive of the repo
        $tarContents = $this->vcsAdapter->downloadRepositoryTar('appwrite', 'demos-for-react', 'main');

        // Save the TAR archive to a file
        file_put_contents('./hello-world.tar', $tarContents);

        // Assert that the file was saved successfully
        $this->assertFileExists('./hello-world.tar');
    }

    public function testForkRepository(): void
    {
        // Fork a repository into authenticated user's account with custom name
        $response = $this->vcsAdapter->forkRepository('appwrite', 'demos-for-astro', name: 'fork-api-test-clone');
        // Assert that the forked repo has the expected name
        $this->assertEquals('fork-api-test-clone', $response);
        $this->vcsAdapter->deleteRepository("test-kh", "fork-api-test-clone");
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
