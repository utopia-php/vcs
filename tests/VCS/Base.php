<?php

namespace Utopia\Tests;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\Fetch\Client;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git;
use Utopia\VCS\Exception\FileNotFound;
use Utopia\VCS\Exception\RepositoryNotFound;

abstract class Base extends TestCase
{
    protected Git $vcsAdapter;
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';

    /**
     * Username of an account that exists on the instance under test.
     */
    protected static string $existingUser = 'root';

    /**
     * Field the provider reports a user's handle under.
     */
    protected static string $userHandleField = 'username';

    /**
     * State the provider reports for a freshly opened pull request.
     */
    protected static string $openPullRequestState = 'open';

    /**
     * Scopes the provider accepts webhooks at. Only GitHub registers them
     * once per installation as well as per repository.
     *
     * @var array<string>
     */
    protected static array $supportedWebhookScopes = [Git::WEBHOOK_SCOPE_REPOSITORY];

    /**
     * Headers the provider sends its webhook event type and signature under.
     */
    protected static string $eventHeader = '';

    protected static string $signatureHeader = '';

    /**
     * Build the adapter under test and assign it to $this->vcsAdapter.
     */
    abstract protected function setupAdapter(): void;

    /**
     * Webhook payloads and signature schemes are provider specific.
     */
    abstract public function testGetEventPush(): void;

    abstract public function testGetEventPullRequest(): void;

    abstract public function testValidateWebhookEvent(): void;

    protected function setUp(): void
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

    /**
     * Webhook headers keep the casing the provider sent them with, so look them up case-insensitively.
     *
     * @param array<string, mixed> $headers
     */
    protected function findHeader(array $headers, string $name): string
    {
        foreach ($headers as $header => $value) {
            if (\strcasecmp($header, $name) === 0) {
                return \is_string($value) ? $value : '';
            }
        }

        return '';
    }

    /**
     * Path of the owner under test, as the provider reports it.
     */
    protected function ownerPath(): string
    {
        return static::$owner;
    }

    /**
     * Owner of a repository, as GitHub and Gitea report it. GitLab overrides this.
     *
     * @param array<string, mixed> $repository
     */
    protected function ownerOf(array $repository): string
    {
        $this->assertArrayHasKey('owner', $repository);
        $this->assertIsArray($repository['owner']);
        $this->assertArrayHasKey('login', $repository['owner']);

        return (string) $repository['owner']['login'];
    }

    /**
     * Visibility as GitHub and Gitea report it, a boolean flag. GitLab overrides this.
     *
     * @param array<string, mixed> $repository
     */
    protected function isPrivate(array $repository): bool
    {
        $this->assertArrayHasKey('private', $repository);
        $this->assertIsBool($repository['private']);

        return $repository['private'];
    }

    /**
     * Number of a pull request, as every provider but GitLab reports it.
     *
     * @param array<string, mixed> $pullRequest
     */
    protected function pullRequestNumberOf(array $pullRequest): int
    {
        $this->assertArrayHasKey('number', $pullRequest);
        $this->assertIsNumeric($pullRequest['number']);

        return (int) $pullRequest['number'];
    }

    /**
     * Every provider reports pushed_at as a timestamp, including for a
     * repository that has no commits yet.
     *
     * @param array<string, mixed> $repository
     */
    protected function assertPushedAt(array $repository): void
    {
        $this->assertArrayHasKey('pushed_at', $repository);
        $this->assertNotFalse(
            \strtotime((string) $repository['pushed_at']),
            'pushed_at is not a parseable timestamp'
        );
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

    /** @return array<string, mixed> */
    protected function getLatestCommitEventually(string $repositoryName): array
    {
        $commit = [];
        $this->assertEventually(function () use (&$commit, $repositoryName) {
            $commit = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
            $this->assertNotEmpty($commit['commitHash']);
        }, 15000, 1000);
        return $commit;
    }

    /**
     * Remove a repository during cleanup, tolerating one that was never created.
     * Deleting is asserted on its own in the delete tests.
     */
    protected function deleteRepositoryIfExists(string $repositoryName): void
    {
        try {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        } catch (\Throwable) {
            // nothing to clean up
        }
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

    public function testWebhookHeaderNames(): void
    {
        $this->assertSame(static::$eventHeader, $this->vcsAdapter->getEventHeaderName());
        $this->assertSame(static::$signatureHeader, $this->vcsAdapter->getSignatureHeaderName());
    }

    public function testGetSupportedWebhookScopes(): void
    {
        $this->assertSame(static::$supportedWebhookScopes, $this->vcsAdapter->getSupportedWebhookScopes());
    }

    public function testCreateRepository(): void
    {
        $repositoryName = 'test-create-repository-' . \uniqid();

        $result = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->assertIsArray($result);
            $this->assertArrayHasKey('name', $result);
            $this->assertSame($repositoryName, $result['name']);
            $this->assertPushedAt($result);

            $this->assertFalse($this->isPrivate($result), 'createRepository() reported the new repository as private');
            $this->assertSame($this->ownerPath(), $this->ownerOf($result));

            $fetched = $this->vcsAdapter->getRepository(static::$owner, $repositoryName);
            $this->assertFalse($this->isPrivate($fetched), 'getRepository() reported the new repository as private');
            $this->assertSame($this->ownerPath(), $this->ownerOf($fetched));
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
            $this->assertSame($repositoryName, $result['name']);
            $this->assertTrue($this->isPrivate($result), 'createRepository() did not report the new repository as private');

            $fetched = $this->vcsAdapter->getRepository(static::$owner, $repositoryName);
            $this->assertTrue($this->isPrivate($fetched), 'getRepository() did not report the new repository as private');
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
            $this->assertPushedAt($result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetDeletedRepositoryFails(): void
    {
        $this->expectException(RepositoryNotFound::class);
        $this->vcsAdapter->getRepository(static::$owner, 'non-existing-repository-' . \uniqid());
    }

    public function testGetRepositoryWithNonExistingOwner(): void
    {
        $this->expectException(Exception::class);
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

        try {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
            $this->fail('Deleting the same repository twice should have thrown');
        } catch (Exception $e) {
            $this->assertGreaterThanOrEqual(400, $e->getCode(), 'Exception should carry the HTTP status code');
        }
    }

    public function testDeleteNonExistingRepositoryFails(): void
    {
        try {
            $this->vcsAdapter->deleteRepository(static::$owner, 'non-existing-repo-' . \uniqid());
            $this->fail('Deleting a non existing repository should have thrown');
        } catch (Exception $e) {
            $this->assertGreaterThanOrEqual(400, $e->getCode(), 'Exception should carry the HTTP status code');
        }
    }

    public function testGetRepositoryName(): void
    {
        $repositoryName = 'test-get-repository-name-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->assertIsArray($created);
            $this->assertArrayHasKey('id', $created);
            $this->assertIsNumeric($created['id']);
            $repositoryId = (string) $created['id'];

            $result = $this->vcsAdapter->getRepositoryName($repositoryId);

            $this->assertIsString($result);
            $this->assertSame($repositoryName, $result);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryNameWithInvalidId(): void
    {
        $this->expectException(Exception::class);
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

            $tree = [];
            $this->assertEventually(function () use (&$tree, $repositoryName) {
                $tree = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, static::$defaultBranch, false);
                $this->assertContains('src', $tree);
            });

            $this->assertIsArray($tree);
            $this->assertContains('README.md', $tree);
            $this->assertCount(2, $tree);

            $treeRecursive = [];
            $this->assertEventually(function () use (&$treeRecursive, $repositoryName) {
                $treeRecursive = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, static::$defaultBranch, true);
                $this->assertContains('src/lib.php', $treeRecursive);
            });

            $this->assertIsArray($treeRecursive);
            $this->assertContains('README.md', $treeRecursive);
            $this->assertContains('src/main.php', $treeRecursive);
            $this->assertGreaterThanOrEqual(3, \count($treeRecursive));
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
            $this->assertIsString($result['sha']);
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

            $contents = [];
            $this->assertEventually(function () use (&$contents, $repositoryName) {
                $contents = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName);
                $this->assertCount(3, $contents);
            });

            $this->assertIsArray($contents);

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
            $this->getLatestCommitEventually($repositoryName);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-1', static::$defaultBranch);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-2', static::$defaultBranch);

            $branches = [];
            $this->assertEventually(function () use (&$branches, $repositoryName) {
                $branches = $this->vcsAdapter->listBranches(static::$owner, $repositoryName);
                $this->assertContains('feature-1', $branches);
                $this->assertContains('feature-2', $branches);
            }, 15000, 500);

            $this->assertIsArray($branches);
            $this->assertNotEmpty($branches);
            $this->assertContains(static::$defaultBranch, $branches);
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

    public function testListTags(): void
    {
        $repositoryName = 'test-list-tags-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $this->vcsAdapter->createTag(static::$owner, $repositoryName, 'v1.0.0', $commitHash);
            $this->vcsAdapter->createTag(static::$owner, $repositoryName, 'v1.1.0', $commitHash);
            $this->vcsAdapter->createTag(static::$owner, $repositoryName, 'v2.0.0', $commitHash);

            $tags = [];
            $this->assertEventually(function () use (&$tags, $repositoryName) {
                $tags = $this->vcsAdapter->listTags(static::$owner, $repositoryName);
                $this->assertCount(3, $tags);
            }, 15000, 500);

            $this->assertEqualsCanonicalizing(['v1.0.0', 'v1.1.0', 'v2.0.0'], $tags);

            // Glob filtering
            $this->assertEqualsCanonicalizing(['v1.0.0', 'v1.1.0'], $this->vcsAdapter->listTags(static::$owner, $repositoryName, 'v1.*'));
            $this->assertSame(['v2.0.0'], $this->vcsAdapter->listTags(static::$owner, $repositoryName, 'v2.0.0'));
            $this->assertEmpty($this->vcsAdapter->listTags(static::$owner, $repositoryName, 'nope-*'));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListTagsEmptyRepository(): void
    {
        $repositoryName = 'test-list-tags-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $this->assertSame([], $this->vcsAdapter->listTags(static::$owner, $repositoryName));

            // Glob against a repository with no tags stays empty
            $this->assertSame([], $this->vcsAdapter->listTags(static::$owner, $repositoryName, 'v*'));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListTagsNonExistingRepository(): void
    {
        $this->assertSame([], $this->vcsAdapter->listTags(static::$owner, 'non-existing-repo-' . \uniqid()));
    }

    public function testGetCommit(): void
    {
        $repositoryName = 'test-get-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $customMessage = 'Test commit message';
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test', $customMessage);

            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

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
            $this->assertStringContainsString($repositoryName, $result['commitUrl']);
            $this->assertNotEmpty($result['commitAuthor']);
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

            // Wait for first commit to be indexed
            $commit1 = $this->getLatestCommitEventually($repositoryName);

            $this->assertIsArray($commit1);
            $this->assertNotEmpty($commit1['commitHash']);
            $this->assertStringStartsWith($firstMessage, $commit1['commitMessage']);
            $this->assertStringContainsString($repositoryName, $commit1['commitUrl']);
            $this->assertNotEmpty($commit1['commitAuthor']);

            $commit1Hash = $commit1['commitHash'];

            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', $secondMessage);

            // Wait until commit hash is DIFFERENT from first — not just non-empty
            $commit2 = [];
            $this->assertEventually(function () use (&$commit2, $repositoryName, $commit1Hash) {
                $commit2 = $this->vcsAdapter->getLatestCommit(static::$owner, $repositoryName, static::$defaultBranch);
                $this->assertNotSame($commit1Hash, $commit2['commitHash']);
            }, 15000, 1000);

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

            $this->expectException(Exception::class);
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

            $commit = $this->getLatestCommitEventually($repositoryName);
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

            $statuses = $this->vcsAdapter->getCommitStatuses(static::$owner, $repositoryName, $commitHash);
            $this->assertIsArray($statuses);
            $this->assertNotEmpty($statuses);

            $written = null;
            foreach ($statuses as $status) {
                $this->assertArrayHasKey('context', $status);
                if ($status['context'] === 'ci/build') {
                    $written = $status;
                    break;
                }
            }

            $this->assertNotNull($written, 'No status reported under the context it was written with');
            $this->assertSame('success', $written['state']);
            $this->assertSame('Build passed', $written['description']);
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
            $this->assertStringContainsString('git init', $command);
            $this->assertStringContainsString('git remote add origin', $command);
            $this->assertStringContainsString('git config core.sparseCheckout true', $command);
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
        $directory = '/tmp/test-clone-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $commit = $this->getLatestCommitEventually($repositoryName);
            $commitHash = $commit['commitHash'];

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
            $this->assertStringContainsString($commitHash, $command);
            $this->assertStringContainsString('--depth=1', $command);

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

            // The command sets up a local repository first, so a missing remote
            // does not have to fail it outright - what matters is that nothing
            // from the repository was checked out.
            $this->assertFileDoesNotExist($directory . '/README.md');
        } finally {
            if (\is_dir($directory)) {
                \exec('rm -rf ' . escapeshellarg($directory));
            }
        }
    }

    public function testGetOwnerNameWithoutRepositoryId(): void
    {
        $this->assertSame(static::$existingUser, $this->vcsAdapter->getOwnerName(''));
    }

    public function testGetOwnerNameWithZeroRepositoryId(): void
    {
        $this->assertSame(static::$existingUser, $this->vcsAdapter->getOwnerName('', 0));
    }

    public function testGetOwnerNameWithNullRepositoryId(): void
    {
        $this->assertSame(static::$existingUser, $this->vcsAdapter->getOwnerName('', null));
    }

    public function testGetOwnerName(): void
    {
        $repositoryName = 'test-get-owner-name-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->assertIsArray($created);
            $this->assertArrayHasKey('id', $created);
            $this->assertIsNumeric($created['id']);
            $repositoryId = (int) $created['id'];

            $this->assertSame($this->ownerPath(), $this->vcsAdapter->getOwnerName('', $repositoryId));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testCreateRepositoryWithInvalidName(): void
    {
        $this->expectException(Exception::class);
        $this->vcsAdapter->createRepository(static::$owner, 'invalid name with spaces', false);
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $repositoryName = 'test-clone-tag-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $directory = '/tmp/test-clone-tag-' . \uniqid();

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test Tag');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $this->vcsAdapter->createTag(static::$owner, $repositoryName, 'v1.0.0', $commitHash, 'Release v1.0.0');

            $command = $this->vcsAdapter->generateCloneCommand(
                static::$owner,
                $repositoryName,
                'v1.0.0',
                Git::CLONE_TYPE_TAG,
                $directory,
                '/'
            );

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

    public function testSearchRepositoriesMatchesName(): void
    {
        $match = 'test-search-match-' . \uniqid();
        $other = 'test-search-other-' . \uniqid();

        try {
            $this->vcsAdapter->createRepository(static::$owner, $match, false);
            $this->vcsAdapter->createRepository(static::$owner, $other, false);

            $names = [];
            $this->assertEventually(function () use (&$names, $match) {
                $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10, $match);
                $names = array_column($result['items'], 'name');
                $this->assertContains($match, $names);
            }, 60000, 2000);

            $this->assertNotContains($other, $names);
        } finally {
            $this->deleteRepositoryIfExists($match);
            $this->deleteRepositoryIfExists($other);
        }
    }

    public function testSearchRepositories(): void
    {
        $repo1Name = 'test-search-repo1-' . \uniqid();
        $repo2Name = 'test-search-repo2-' . \uniqid();

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repo1Name, false);
            $this->vcsAdapter->createRepository(static::$owner, $repo2Name, false);

            $result = [];
            $this->assertEventually(function () use (&$result) {
                $result = $this->vcsAdapter->searchRepositories(static::$owner, 1, 10);
                $this->assertGreaterThanOrEqual(2, $result['total']);
            }, 30000, 2000);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('items', $result);
            $this->assertArrayHasKey('total', $result);

            $this->assertNotEmpty($result['items']);

            foreach ($result['items'] as $repository) {
                $this->assertArrayHasKey('id', $repository);
                $this->assertArrayHasKey('name', $repository);
                $this->assertArrayHasKey('private', $repository);
                $this->assertPushedAt($repository);
            }
        } finally {
            $this->deleteRepositoryIfExists($repo1Name);
            $this->deleteRepositoryIfExists($repo2Name);
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

            $prNumber = $this->pullRequestNumberOf($pr);
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
            $this->assertSame(static::$openPullRequestState, $result['state']);
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

            $prNumber = $this->pullRequestNumberOf($pr);

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
            $this->expectException(Exception::class);
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
            $this->assertSame('my-feature', $result['head']['ref']);
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
            $this->getLatestCommitEventually($repositoryName);
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

            $prNumber = $this->pullRequestNumberOf($pr);
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

            $prNumber = $this->pullRequestNumberOf($pr);
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

            $prNumber = $this->pullRequestNumberOf($pr);
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
            $this->expectException(Exception::class);
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
        $result = $this->vcsAdapter->getUser(static::$existingUser);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertNotEmpty($result['id']);
        // GitLab reports the handle as 'username', Gitea and its forks as 'login'
        $this->assertArrayHasKey(static::$userHandleField, $result);
        $this->assertSame(static::$existingUser, $result[static::$userHandleField]);
    }

    public function testGetUserWithInvalidUsername(): void
    {
        $this->expectException(Exception::class);
        $this->vcsAdapter->getUser('non-existent-user-' . \uniqid());
    }

    public function testGetCommitWithInvalidHash(): void
    {
        $repositoryName = 'test-get-commit-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->expectException(Exception::class);
            $this->vcsAdapter->getCommit(static::$owner, $repositoryName, 'invalid-sha-12345');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetEventInvalidPayload(): void
    {
        $this->expectException(Exception::class);
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

    public function testCreateFile(): void
    {
        $repositoryName = 'test-create-file-' . \uniqid();
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
        $repositoryName = 'test-create-file-branch-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Main');
            $this->getLatestCommitEventually($repositoryName);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature', static::$defaultBranch);

            $result = $this->vcsAdapter->createFile(
                static::$owner,
                $repositoryName,
                'feature.md',
                '# Feature',
                'Add feature file',
                'feature'
            );

            $this->assertIsArray($result);

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

    public function testListRepositoryContentsInSubdirectory(): void
    {
        $repositoryName = 'test-list-repository-contents-subdir-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/file1.php', '<?php');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/file2.php', '<?php');

            $contents = [];
            $this->assertEventually(function () use (&$contents, $repositoryName) {
                $contents = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'src');
                $this->assertCount(2, $contents);
            });

            $this->assertIsArray($contents);

            $names = array_column($contents, 'name');
            $this->assertContains('file1.php', $names);
            $this->assertContains('file2.php', $names);
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

    public function testUpdateCommitStatusWithInvalidCommit(): void
    {
        $repositoryName = 'test-update-status-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->expectException(Exception::class);
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
        $this->expectException(Exception::class);
        $this->vcsAdapter->updateCommitStatus(
            'nonexistent-repo-' . \uniqid(),
            'abc123def456abc123def456abc123def456abc123',
            static::$owner,
            'success'
        );
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
}
