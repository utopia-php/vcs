<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Fetch\Client;
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

    abstract public function testGetRepositoryName(): void;

    abstract public function testGetComment(): void;

    abstract public function testGetPullRequest(): void;

    abstract public function testGetPullRequestFiles(): void;

    abstract public function testGetRepositoryTree(): void;

    /** @return array<mixed> */
    protected function getLastWebhookRequest(): array
    {
        $catcherUrl = System::getEnv('TESTS_GITEA_REQUEST_CATCHER_URL', 'http://request-catcher:5000');

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
        $catcherUrl = System::getEnv('TESTS_GITEA_REQUEST_CATCHER_URL', 'http://request-catcher:5000');

        $client = new Client();
        $client->fetch(
            url: "{$catcherUrl}/__clear__",
            method: 'DELETE'
        );
    }

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
        ['items' => $repos, 'total' => $total] = $this->vcsAdapter->searchRepositories('test-kh', 1, 2);
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

    public function testListBranchesEmptyRepo(): void
    {
        $repositoryName = 'test-list-branches-empty-' . \uniqid();
        $this->vcsAdapter->createRepository('test-kh', $repositoryName, false);

        try {
            $branches = $this->vcsAdapter->listBranches('test-kh', $repositoryName);

            $this->assertIsArray($branches);
            $this->assertEmpty($branches);
        } finally {
            $this->vcsAdapter->deleteRepository('test-kh', $repositoryName);
        }
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
