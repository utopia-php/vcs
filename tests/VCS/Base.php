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
    /**
     * Facts the webhook payload builders below carry, asserted back out of the
     * normalized event. Bitbucket overrides the repository id, having none.
     */
    protected const EVENT_REPOSITORY_ID = '123';

    protected const EVENT_REPOSITORY_NAME = 'test-repo';

    protected const EVENT_OWNER = 'test-owner';

    protected const EVENT_COMMIT_HASH = 'def456';

    protected const EVENT_COMMIT_MESSAGE = 'Test commit message';

    protected const EVENT_AUTHOR_NAME = 'Test Author';

    protected const EVENT_AUTHOR_EMAIL = 'author@example.com';

    protected const EVENT_HEAD_BRANCH = 'feature-branch';

    protected const EVENT_PULL_REQUEST_NUMBER = 42;

    protected Git $vcsAdapter;
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';

    /**
     * Username of an account that exists on the instance under test.
     */
    protected static string $existingUser = 'root';

    /**
     * Installation the credentials belong to, for providers that resolve an
     * owner from it rather than from a repository.
     */
    protected static string $installationId = '';

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
     * Names the provider uses for the events it delivers.
     */
    protected static string $pushEventName = 'push';

    protected static string $pullRequestEventName = 'pull_request';

    /**
     * Actions the provider may report for a newly opened pull request. Gitea
     * follows the opened event with a synchronized one for the head it just
     * pushed, and the catcher only keeps the last delivery.
     *
     * @var array<string>
     */
    protected static array $pullRequestOpenedActions = ['opened'];

    /**
     * Fragments the provider's archive URLs are built from.
     */
    protected static string $presignedTarballFragment = '.tar.gz';

    protected static string $presignedZipballFragment = '.zip';

    /**
     * Whether the provider's credentials reach every repository, and whether it
     * can look one up through an installation at all.
     */
    protected static bool $hasAccessToAllRepositories = true;

    protected static bool $supportsInstallationRepository = true;

    /**
     * Exception the provider raises for a repository id that does not exist.
     *
     * @var class-string<\Throwable>
     */
    protected static string $repositoryNotFoundException = RepositoryNotFound::class;

    /**
     * Parts of the contract a provider may not offer at all. Each one skips the
     * tests that need it, instead of every adapter overriding them to say so.
     */
    protected static bool $supportsPullRequestCreation = true;

    protected static bool $supportsPullRequestLookup = true;

    protected static bool $supportsCommitStatuses = true;

    protected static bool $supportsCommitStatusLookup = true;

    protected static bool $supportsTags = true;

    protected static bool $supportsUserLookup = true;

    protected static bool $supportsRepositoryLanguages = true;

    protected static bool $supportsWebhookDelivery = true;

    protected static bool $resolvesOwnerFromRepositoryId = true;

    protected static bool $rejectsInvalidRepositoryNames = true;

    protected static bool $supportsCheckRuns = true;

    protected static bool $supportsNamespaceListing = true;

    /**
     * Whether a push event names the files it touched. Bitbucket's payload
     * carries no file lists at all.
     */
    protected static bool $reportsAffectedFilesInPushEvent = true;

    /**
     * Whether the provider computes language stats out of band. GitHub does,
     * with no guaranteed turnaround, so a repository that still has none says
     * nothing about the adapter.
     */
    protected static bool $computesLanguagesAsynchronously = false;

    /**
     * Host the provider serves commit author avatars from.
     */
    protected static string $avatarDomain = '';

    /**
     * Whether a repository is gone as soon as delete returns. GitLab schedules
     * it instead.
     */
    protected static bool $deletesRepositoriesSynchronously = true;

    /**
     * Whether a new repository starts with no commits. The Gogs adapter creates
     * one with an initial commit, so it never has an empty repository.
     */
    protected static bool $createsEmptyRepositories = true;

    /**
     * Whether the provider links the commit author back to an account. GitLab
     * reports neither, Gitea an avatar but no profile url.
     */
    protected static bool $reportsCommitAuthorAvatar = true;

    protected static bool $reportsCommitAuthorUrl = true;

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
     * Sign a payload the way the provider signs its webhooks.
     */
    abstract protected function signWebhookPayload(string $payload, string $secret): string;

    /**
     * Build a push payload shaped the way this provider sends one, carrying the
     * EVENT_* facts above.
     *
     * @param array<string> $added
     * @param array<string> $removed
     * @param array<string> $modified
     */
    abstract protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false): string;

    /**
     * Build a pull request payload shaped the way this provider sends one,
     * opening EVENT_HEAD_BRANCH against the default branch.
     */
    abstract protected function pullRequestPayload(bool $external = false): string;

    /**
     * URL an anonymous git client would clone the repository from over HTTP,
     * with no credentials embedded.
     */
    abstract protected function anonymousCloneUrl(string $repositoryName): string;

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

    /**
     * @param array<string, mixed> $commit
     */
    protected function assertCommitAuthorLinks(array $commit): void
    {
        if (static::$reportsCommitAuthorAvatar) {
            $this->assertNotEmpty($commit['commitAuthorAvatar']);
        }

        if (static::$reportsCommitAuthorUrl) {
            $this->assertNotEmpty($commit['commitAuthorUrl']);
        }
    }

    protected function skipUnlessSupported(bool $supported, string $capability): void
    {
        if (!$supported) {
            $this->markTestSkipped(static::class . ' does not support ' . $capability);
        }
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
     * Remove repositories a test created.
     *
     * A repository that was never created is nothing to clean up. Anything else
     * is retried first, because a provider hiccup during teardown once failed a
     * test that had passed, and then reported if it still will not delete - a
     * repository left behind contaminates later runs, so it has to be visible
     * in the result rather than only in the log.
     */
    protected function discardRepositories(string ...$repositoryNames): void
    {
        $failures = [];

        foreach ($repositoryNames as $repositoryName) {
            try {
                $this->deleteRepositoryWithRetries($repositoryName);
            } catch (\Throwable $e) {
                $failures[] = "{$repositoryName}: {$e->getMessage()}";
            }
        }

        if ($failures !== []) {
            throw new Exception('Cleanup left repositories behind - ' . \implode(', ', $failures));
        }
    }

    private function deleteRepositoryWithRetries(string $repositoryName, int $attempts = 3): void
    {
        for ($attempt = 1;; $attempt++) {
            try {
                $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);

                return;
            } catch (RepositoryNotFound) {
                return;
            } catch (\Throwable $e) {
                // Adapters carry the HTTP status as the exception code
                if ($e->getCode() === 404) {
                    return;
                }

                if ($attempt >= $attempts) {
                    throw $e;
                }

                \usleep(2000000);
            }
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
        }
    }

    /**
     * Response an anonymous git client gets for the repository: the ref
     * advertisement request `git clone` opens with, sent without credentials.
     *
     * @return array{0: int, 1: string} Status code and body
     */
    private function fetchAnonymousRefAdvertisement(string $repositoryName): array
    {
        $client = new Client();
        $response = $client->fetch(
            url: $this->anonymousCloneUrl($repositoryName) . '/info/refs',
            method: 'GET',
            query: ['service' => 'git-upload-pack']
        );

        return [$response->getStatusCode(), $response->text()];
    }

    /**
     * The visibility flag alone proves nothing about what reaches the
     * outside; a public repository has to answer an anonymous git client.
     * A private repository has to refuse the same request, or the public
     * answer would say nothing beyond the server being up.
     */
    public function testPublicRepositoryIsPubliclyAccessible(): void
    {
        $this->skipUnlessSupported($this->vcsAdapter->supportsPublicRepositories(), 'public repositories');

        $publicRepository = 'test-public-access-' . \uniqid();
        $privateRepository = 'test-private-access-' . \uniqid();

        $this->vcsAdapter->createRepository(static::$owner, $publicRepository, false);
        $this->vcsAdapter->createRepository(static::$owner, $privateRepository, true);

        try {
            $this->assertEventually(function () use ($publicRepository) {
                [$status, $body] = $this->fetchAnonymousRefAdvertisement($publicRepository);
                $this->assertSame(200, $status, 'An anonymous git client cannot reach the public repository');
                $this->assertStringContainsString('git-upload-pack', $body, 'The anonymous response is not a git ref advertisement');
            });

            [$status] = $this->fetchAnonymousRefAdvertisement($privateRepository);
            $this->assertNotSame(200, $status, 'An anonymous git client can read the private repository');
        } finally {
            $this->discardRepositories($publicRepository, $privateRepository);
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
            $this->discardRepositories($repositoryName);
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
            $result = $this->vcsAdapter->getRepositoryName((string) $created['id']);

            $this->assertIsString($result);
            $this->assertSame($repositoryName, $result);
        } finally {
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testListRepositoryLanguages(): void
    {
        $this->skipUnlessSupported(static::$supportsRepositoryLanguages, 'repository languages');

        $repositoryName = 'test-list-repository-languages-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'main.php', '<?php echo "test";');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'script.js', 'console.log("test");');

            $languages = [];
            try {
                $this->assertEventually(function () use (&$languages, $repositoryName) {
                    $languages = $this->vcsAdapter->listRepositoryLanguages(static::$owner, $repositoryName);
                    $this->assertNotEmpty($languages);
                }, static::$computesLanguagesAsynchronously ? 60000 : 30000, static::$computesLanguagesAsynchronously ? 5000 : 2000);
            } catch (\Throwable $e) {
                if (!static::$computesLanguagesAsynchronously) {
                    throw $e;
                }

                $this->markTestSkipped('The provider has not computed language stats for the new repository yet');
            }

            $this->assertIsArray($languages);
            $this->assertContains('PHP', $languages);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testListRepositoryLanguagesEmptyRepo(): void
    {
        $this->skipUnlessSupported(static::$supportsRepositoryLanguages, 'repository languages');

        $repositoryName = 'test-list-repository-languages-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $languages = $this->vcsAdapter->listRepositoryLanguages(static::$owner, $repositoryName);
            $this->assertIsArray($languages);
            $this->assertEmpty($languages);
        } finally {
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testListBranchesEmptyRepository(): void
    {
        $this->skipUnlessSupported(static::$createsEmptyRepositories, 'repositories without an initial commit');

        $repositoryName = 'test-list-branches-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $branches = $this->vcsAdapter->listBranches(static::$owner, $repositoryName);

            $this->assertIsArray($branches);
            $this->assertEmpty($branches);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testListTags(): void
    {
        $this->skipUnlessSupported(static::$supportsTags, 'creating tags');

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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->assertCommitAuthorLinks($result);
        } finally {
            $this->discardRepositories($repositoryName);
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
            $this->assertCommitAuthorLinks($commit1);

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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testUpdateCommitStatus(): void
    {
        $this->skipUnlessSupported(static::$supportsCommitStatuses, 'commit statuses');

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

            if (!static::$supportsCommitStatusLookup) {
                return;
            }

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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
        $this->skipUnlessSupported(static::$resolvesOwnerFromRepositoryId, 'resolving an owner from a repository id');

        $this->assertSame(static::$existingUser, $this->vcsAdapter->getOwnerName(''));
    }

    public function testGetOwnerNameWithZeroRepositoryId(): void
    {
        $this->skipUnlessSupported(static::$resolvesOwnerFromRepositoryId, 'resolving an owner from a repository id');

        $this->assertSame(static::$existingUser, $this->vcsAdapter->getOwnerName('', 0));
    }

    public function testGetOwnerNameWithNullRepositoryId(): void
    {
        $this->skipUnlessSupported(static::$resolvesOwnerFromRepositoryId, 'resolving an owner from a repository id');

        $this->assertSame(static::$existingUser, $this->vcsAdapter->getOwnerName('', null));
    }

    public function testGetOwnerName(): void
    {
        $repositoryName = 'test-get-owner-name-' . \uniqid();
        $created = $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            // GitHub resolves the owner from the installation and Bitbucket from
            // the account its token belongs to, the others from the repository, so
            // pass both and let each use what it reads
            $this->assertSame(
                $this->ownerPath(),
                $this->vcsAdapter->getOwnerName(static::$installationId, (int) $created['id'])
            );
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testCreateRepositoryWithInvalidName(): void
    {
        $this->skipUnlessSupported(static::$rejectsInvalidRepositoryNames, 'rejecting invalid repository names');

        $this->expectException(Exception::class);
        $this->vcsAdapter->createRepository(static::$owner, 'invalid name with spaces', false);
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $this->skipUnlessSupported(static::$supportsTags, 'creating tags');

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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($match, $other);
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
            $this->discardRepositories($repo1Name, $repo2Name);
        }
    }

    public function testGetPullRequest(): void
    {
        $this->skipUnlessSupported(static::$supportsPullRequestCreation, 'creating pull requests');

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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetPullRequestFiles(): void
    {
        $this->skipUnlessSupported(static::$supportsPullRequestCreation, 'creating pull requests');

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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetPullRequestWithInvalidNumber(): void
    {
        $this->skipUnlessSupported(static::$supportsPullRequestLookup, 'looking up pull requests');

        $repositoryName = 'test-get-pull-request-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->expectException(Exception::class);
            $this->vcsAdapter->getPullRequest(static::$owner, $repositoryName, 99999);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetPullRequestFromBranch(): void
    {
        $this->skipUnlessSupported(static::$supportsPullRequestCreation, 'creating pull requests');

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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetPullRequestFromBranchNoPR(): void
    {
        $this->skipUnlessSupported(static::$supportsPullRequestLookup, 'looking up pull requests');

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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testCreateComment(): void
    {
        $this->skipUnlessSupported(static::$supportsPullRequestCreation, 'creating pull requests');

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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetComment(): void
    {
        $this->skipUnlessSupported(static::$supportsPullRequestCreation, 'creating pull requests');

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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testUpdateComment(): void
    {
        $this->skipUnlessSupported(static::$supportsPullRequestCreation, 'creating pull requests');

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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetUser(): void
    {
        $this->skipUnlessSupported(static::$supportsUserLookup, 'looking up users');

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
        $this->skipUnlessSupported(static::$supportsUserLookup, 'looking up users');

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
            $this->discardRepositories($repositoryName);
        }
    }

    /**
     * @return array<string, mixed> The event the provider delivered, parsed
     */
    protected function awaitWebhook(string $eventName, string $secret): array
    {
        $eventHeader = $this->vcsAdapter->getEventHeaderName();

        $webhookData = [];
        $this->assertEventually(function () use (&$webhookData, $eventHeader, $eventName) {
            $webhookData = $this->getLastWebhookRequest();
            $this->assertNotEmpty($webhookData, 'No webhook was delivered');
            $this->assertNotEmpty($webhookData['data'] ?? '', 'Webhook payload was empty');
            $this->assertSame($eventName, $this->findHeader($webhookData['headers'] ?? [], $eventHeader));
        }, 60000, 2000);

        $payload = $webhookData['data'];
        $signatureHeader = $this->vcsAdapter->getSignatureHeaderName();
        $signature = $this->findHeader($webhookData['headers'] ?? [], $signatureHeader);

        $this->assertNotEmpty($signature, "Missing {$signatureHeader} header");
        $this->assertTrue(
            $this->vcsAdapter->validateWebhookEvent($payload, $signature, $secret),
            'Webhook signature did not validate'
        );

        $events = $this->vcsAdapter->getEvents($eventName, $payload);
        $this->assertIsArray($events);
        $this->assertCount(1, $events);

        return $events[0];
    }

    public function testValidateWebhookEvent(): void
    {
        $payload = '{"object_kind":"push","action":"push"}';
        $secret = 'my-webhook-secret';

        $this->assertTrue(
            $this->vcsAdapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, $secret), $secret)
        );
        $this->assertFalse($this->vcsAdapter->validateWebhookEvent($payload, 'not-the-signature', $secret));
        $this->assertFalse(
            $this->vcsAdapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, 'another-secret'), $secret)
        );
    }

    public function testGetRepositoryPresignedUrl(): void
    {
        $repositoryName = 'test-presigned-url-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $tarball = $this->vcsAdapter->getRepositoryPresignedUrl(static::$owner, $repositoryName, static::$defaultBranch);
            $this->assertStringStartsWith('http', $tarball);
            $this->assertStringContainsString(static::$presignedTarballFragment, $tarball);

            $zipball = $this->vcsAdapter->getRepositoryPresignedUrl(static::$owner, $repositoryName, static::$defaultBranch, 'zipball');
            $this->assertStringContainsString(static::$presignedZipballFragment, $zipball);
            $this->assertNotSame($tarball, $zipball);

            // Without a ref the provider falls back to the default branch
            $this->assertStringStartsWith('http', $this->vcsAdapter->getRepositoryPresignedUrl(static::$owner, $repositoryName));
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetRepositoryPresignedUrlWithInvalidFormat(): void
    {
        $this->expectException(Exception::class);
        $this->vcsAdapter->getRepositoryPresignedUrl(static::$owner, 'some-repo', static::$defaultBranch, 'invalid');
    }

    public function testHasAccessToAllRepositories(): void
    {
        $this->assertSame(static::$hasAccessToAllRepositories, $this->vcsAdapter->hasAccessToAllRepositories());
    }

    public function testGetInstallationRepository(): void
    {
        if (!static::$supportsInstallationRepository) {
            $this->expectException(Exception::class);
            $this->vcsAdapter->getInstallationRepository('any-repo-name');

            return;
        }

        $repositoryName = 'test-installation-repo-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $repository = $this->vcsAdapter->getInstallationRepository($repositoryName);

            $this->assertIsArray($repository);
            $this->assertSame($repositoryName, $repository['name']);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetOwnerNameWithInvalidRepositoryId(): void
    {
        if (!static::$resolvesOwnerFromRepositoryId) {
            // GitHub reads the owner off the installation and Bitbucket off the
            // account its token belongs to, so an id that resolves to nothing
            // does not change the answer
            $this->assertSame(
                $this->ownerPath(),
                $this->vcsAdapter->getOwnerName(static::$installationId, 999999999)
            );

            return;
        }

        $this->expectException(static::$repositoryNotFoundException);
        $this->vcsAdapter->getOwnerName('', 999999999);
    }

    public function testWebhookPushEvent(): void
    {
        $this->skipUnlessSupported(static::$supportsWebhookDelivery, 'webhook delivery to the test catcher');

        $repositoryName = 'test-webhook-push-' . \uniqid();
        $secret = 'test-webhook-secret-' . \uniqid();
        $catcherUrl = System::getEnv('TESTS_REQUEST_CATCHER_URL', 'http://request-catcher:5000');

        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->deleteLastWebhookRequest();

            $webhookId = $this->vcsAdapter->createWebhook(
                static::$owner,
                $repositoryName,
                $catcherUrl . '/webhook',
                $secret,
                ['push']
            );
            $this->assertGreaterThan(0, $webhookId);

            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Webhook Test', 'Initial commit');

            $event = $this->awaitWebhook(static::$pushEventName, $secret);

            $this->assertSame(static::$defaultBranch, $event['branch']);
            $this->assertSame($repositoryName, $event['repositoryName']);
            $this->assertSame($this->ownerPath(), $event['owner']);
            $this->assertNotEmpty($event['commitHash']);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testWebhookPullRequestEvent(): void
    {
        $this->skipUnlessSupported(static::$supportsWebhookDelivery, 'webhook delivery to the test catcher');
        $this->skipUnlessSupported(static::$supportsPullRequestCreation, 'creating pull requests');

        $repositoryName = 'test-webhook-pr-' . \uniqid();
        $secret = 'test-webhook-secret-' . \uniqid();
        $catcherUrl = System::getEnv('TESTS_REQUEST_CATCHER_URL', 'http://request-catcher:5000');

        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            // Everything the pull request needs happens before the hook exists,
            // so those pushes cannot land on the catcher instead of the event
            // being tested.
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->getLatestCommitEventually($repositoryName);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature-branch', static::$defaultBranch);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'feature.txt', 'content', 'Add feature', 'feature-branch');

            $webhookId = $this->vcsAdapter->createWebhook(
                static::$owner,
                $repositoryName,
                $catcherUrl . '/webhook',
                $secret,
                ['pull_request']
            );
            $this->assertGreaterThan(0, $webhookId);

            $this->deleteLastWebhookRequest();

            $this->vcsAdapter->createPullRequest(
                static::$owner,
                $repositoryName,
                'Test Webhook PR',
                'feature-branch',
                static::$defaultBranch
            );

            $event = $this->awaitWebhook(static::$pullRequestEventName, $secret);

            $this->assertSame('feature-branch', $event['branch']);
            $this->assertSame($repositoryName, $event['repositoryName']);
            $this->assertSame($this->ownerPath(), $event['owner']);
            $this->assertContains($event['action'], static::$pullRequestOpenedActions);
            $this->assertGreaterThan(0, $event['pullRequestNumber']);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetRepositoryTreeWithSlashInBranchName(): void
    {
        $repositoryName = 'test-branch-with-slash-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->getLatestCommitEventually($repositoryName);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'feature/test-branch', static::$defaultBranch);

            $tree = [];
            $this->assertEventually(function () use (&$tree, $repositoryName) {
                $tree = $this->vcsAdapter->getRepositoryTree(static::$owner, $repositoryName, 'feature/test-branch');
                $this->assertContains('README.md', $tree);
            });
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testCreateTag(): void
    {
        $this->skipUnlessSupported(static::$supportsTags, 'creating tags');

        $repositoryName = 'test-create-tag-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $result = $this->vcsAdapter->createTag(static::$owner, $repositoryName, 'v1.0.0', $commitHash, 'First release');

            $this->assertIsArray($result);
            $this->assertArrayHasKey('name', $result);
            $this->assertSame('v1.0.0', $result['name']);

            // Providers describe the tagged commit differently, so read it back
            $this->assertEventually(function () use ($repositoryName) {
                $this->assertContains('v1.0.0', $this->vcsAdapter->listTags(static::$owner, $repositoryName));
            });
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testSearchRepositoriesPagination(): void
    {
        $prefix = 'test-pagination-' . \uniqid();
        $repo1 = $prefix . '-1';
        $repo2 = $prefix . '-2';

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repo1, false);
            $this->vcsAdapter->createRepository(static::$owner, $repo2, false);

            $page1 = [];
            $this->assertEventually(function () use (&$page1, $prefix) {
                $page1 = $this->vcsAdapter->searchRepositories(static::$owner, 1, 1, $prefix);
                $this->assertGreaterThanOrEqual(2, $page1['total']);
            }, 60000, 2000);

            $this->assertCount(1, $page1['items']);
            $this->assertCount(1, $this->vcsAdapter->searchRepositories(static::$owner, 2, 1, $prefix)['items']);
            $this->assertEmpty($this->vcsAdapter->searchRepositories(static::$owner, 20, 1, $prefix)['items']);
        } finally {
            $this->discardRepositories($repo1, $repo2);
        }
    }

    public function testListTagsCommitlessRepository(): void
    {
        $this->skipUnlessSupported(static::$createsEmptyRepositories, 'repositories without an initial commit');

        $repositoryName = 'test-list-tags-commitless-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            // No commits at all, which some providers answer differently from
            // a repository that simply has no tags
            $this->assertSame([], $this->vcsAdapter->listTags(static::$owner, $repositoryName));
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetCommitStatuses(): void
    {
        $this->skipUnlessSupported(static::$supportsCommitStatusLookup, 'reading commit statuses');

        $repositoryName = 'test-get-commit-statuses-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $this->vcsAdapter->updateCommitStatus($repositoryName, $commitHash, static::$owner, 'pending', 'Build started', '', 'ci/test');

            $result = $this->vcsAdapter->getCommitStatuses(static::$owner, $repositoryName, $commitHash);

            $this->assertIsArray($result);
            $this->assertNotEmpty($result);

            foreach ($result as $status) {
                $this->assertArrayHasKey('state', $status);
                $this->assertArrayHasKey('description', $status);
                $this->assertArrayHasKey('target_url', $status);
                $this->assertArrayHasKey('context', $status);
            }
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetCommitStatusesEmptyForNewCommit(): void
    {
        $this->skipUnlessSupported(static::$supportsCommitStatusLookup, 'reading commit statuses');

        $repositoryName = 'test-get-commit-statuses-empty-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $this->assertSame([], $this->vcsAdapter->getCommitStatuses(static::$owner, $repositoryName, $commitHash));
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testCreateCheckRun(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $repositoryName = 'test-create-check-run-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commit = $this->getLatestCommitEventually($repositoryName);
            $commitHash = $commit['commitHash'];

            $checkRun = $this->vcsAdapter->createCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                headSha: $commitHash,
                name: 'ci/build',
                status: 'in_progress',
                startedAt: gmdate('Y-m-d\TH:i:s\Z'),
            );

            $this->assertArrayHasKey('id', $checkRun);
            $this->assertIsString($checkRun['id']);
            $this->assertEquals('ci/build', $checkRun['name']);
            $this->assertEquals('in_progress', $checkRun['status']);
            $this->assertNull($checkRun['conclusion']);
            $this->assertEquals($commitHash, $checkRun['head_sha']);
            $this->assertNotEmpty($checkRun['url']);
            $this->assertNotEmpty($checkRun['html_url']);
            $this->assertNotEmpty($checkRun['started_at']);
            $this->assertNull($checkRun['completed_at']);

            $fetched = $this->vcsAdapter->getCheckRun(static::$owner, $repositoryName, $checkRun['id']);
            $this->assertEquals($checkRun['id'], $fetched['id']);
            $this->assertEquals('ci/build', $fetched['name']);
            $this->assertEquals('in_progress', $fetched['status']);
            $this->assertNull($fetched['conclusion']);
            $this->assertEquals($commitHash, $fetched['head_sha']);
            $this->assertNotEmpty($fetched['url']);
            $this->assertNotEmpty($fetched['html_url']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testCreateCheckRunWithInvalidRepository(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $this->expectException(\Exception::class);
        $this->vcsAdapter->createCheckRun(
            owner: static::$owner,
            repositoryName: 'non-existing-repository-' . \uniqid(),
            headSha: 'a' . str_repeat('0', 39),
            name: 'ci/build',
        );
    }
    public function testGetCheckRunWithInvalidId(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $repositoryName = 'test-get-check-run-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->getCheckRun(static::$owner, $repositoryName, '999999999');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testCreateTwoCheckRunsOnSameCommit(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $repositoryName = 'test-two-check-runs-same-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $commit = $this->getLatestCommitEventually($repositoryName);
            $commitHash = $commit['commitHash'];

            $first = $this->vcsAdapter->createCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                headSha: $commitHash,
                name: 'ci/build',
                status: 'in_progress',
            );

            $second = $this->vcsAdapter->createCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                headSha: $commitHash,
                name: 'ci/build',
                status: 'in_progress',
            );

            $this->assertArrayHasKey('id', $first);
            $this->assertArrayHasKey('id', $second);
            $this->assertNotEquals($first['id'], $second['id']);
            $this->assertEquals($commitHash, $first['head_sha']);
            $this->assertEquals($commitHash, $second['head_sha']);
            $this->assertEquals('ci/build', $first['name']);
            $this->assertEquals('ci/build', $second['name']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testCreateCheckRunsWithSameNameOnDifferentCommits(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $repositoryName = 'test-check-runs-different-commits-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commit1 = $this->getLatestCommitEventually($repositoryName);
            $commitHash1 = $commit1['commitHash'];

            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'second.md', '# Second');
            $commit2 = $this->getLatestCommitEventually($repositoryName);
            $commitHash2 = $commit2['commitHash'];

            $first = $this->vcsAdapter->createCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                headSha: $commitHash1,
                name: 'ci/build',
                status: 'in_progress',
            );

            $second = $this->vcsAdapter->createCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                headSha: $commitHash2,
                name: 'ci/build',
                status: 'in_progress',
            );

            $this->assertArrayHasKey('id', $first);
            $this->assertArrayHasKey('id', $second);
            $this->assertNotEquals($first['id'], $second['id']);
            $this->assertEquals($commitHash1, $first['head_sha']);
            $this->assertEquals($commitHash2, $second['head_sha']);
            $this->assertEquals('ci/build', $first['name']);
            $this->assertEquals('ci/build', $second['name']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testCreateCheckRunCompleted(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $repositoryName = 'test-create-check-run-completed-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $commit = $this->getLatestCommitEventually($repositoryName);
            $commitHash = $commit['commitHash'];

            $checkRun = $this->vcsAdapter->createCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                headSha: $commitHash,
                name: 'ci/build',
                conclusion: 'success',
                title: 'Build passed',
                summary: 'All checks passed successfully.',
            );

            $this->assertArrayHasKey('id', $checkRun);
            $this->assertIsString($checkRun['id']);
            $this->assertEquals('ci/build', $checkRun['name']);
            $this->assertEquals('completed', $checkRun['status']);
            $this->assertEquals('success', $checkRun['conclusion']);
            $this->assertEquals($commitHash, $checkRun['head_sha']);
            $this->assertNotEmpty($checkRun['url']);
            $this->assertNotEmpty($checkRun['html_url']);
            $this->assertNotEmpty($checkRun['completed_at']);
            $this->assertEquals('Build passed', $checkRun['output']['title']);
            $this->assertEquals('All checks passed successfully.', $checkRun['output']['summary']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testUpdateCheckRun(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $repositoryName = 'test-update-check-run-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commit = $this->getLatestCommitEventually($repositoryName);
            $commitHash = $commit['commitHash'];

            $checkRun = $this->vcsAdapter->createCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                headSha: $commitHash,
                name: 'ci/build',
                status: 'in_progress',
                startedAt: gmdate('Y-m-d\TH:i:s\Z'),
            );

            $this->assertArrayHasKey('id', $checkRun);
            $this->assertEquals('in_progress', $checkRun['status']);

            $updated = $this->vcsAdapter->updateCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                checkRunId: $checkRun['id'],
                status: 'completed',
                conclusion: 'neutral',
                title: 'Deployment skipped',
                summary: 'Deployment skipped because the branch does not match the configured branch triggers.',
                completedAt: gmdate('Y-m-d\TH:i:s\Z'),
            );

            $this->assertEquals($checkRun['id'], $updated['id']);
            $this->assertEquals('completed', $updated['status']);
            $this->assertEquals('neutral', $updated['conclusion']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testUpdateCheckRunWithInvalidRepository(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $this->expectException(\Exception::class);
        $this->vcsAdapter->updateCheckRun(
            owner: static::$owner,
            repositoryName: 'non-existing-repository-' . \uniqid(),
            checkRunId: '999999999',
            conclusion: 'success',
        );
    }
    public function testUpdateCheckRunWithInvalidId(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $repositoryName = 'test-update-check-run-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->updateCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                checkRunId: '999999999',
                conclusion: 'success',
            );
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testUpdateCheckRunWithMissingConclusion(): void
    {
        $this->skipUnlessSupported(static::$supportsCheckRuns, 'check runs');

        $repositoryName = 'test-update-check-run-no-conclusion-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $commit = $this->getLatestCommitEventually($repositoryName);
            $commitHash = $commit['commitHash'];

            $checkRun = $this->vcsAdapter->createCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                headSha: $commitHash,
                name: 'ci/build',
                status: 'in_progress',
            );

            $this->expectException(\Exception::class);
            $this->vcsAdapter->updateCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                checkRunId: $checkRun['id'],
                status: 'completed',
            );
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testListNamespaces(): void
    {
        $this->skipUnlessSupported(static::$supportsNamespaceListing, 'listing namespaces');

        $result = $this->vcsAdapter->listNamespaces(1, 20);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertNotEmpty($result['items']);

        $kinds = array_column($result['items'], 'kind');
        $this->assertContains('user', $kinds);
        $this->assertContains('group', $kinds);

        foreach ($result['items'] as $namespace) {
            $this->assertArrayHasKey('id', $namespace);
            $this->assertArrayHasKey('name', $namespace);
            $this->assertArrayHasKey('path', $namespace);
            $this->assertArrayHasKey('kind', $namespace);
            $this->assertNotEmpty($namespace['path']);
        }
    }
    public function testListNamespacesWithSearch(): void
    {
        $this->skipUnlessSupported(static::$supportsNamespaceListing, 'listing namespaces');

        $ownerPath = $this->ownerPath();

        $result = $this->vcsAdapter->listNamespaces(1, 20, $ownerPath);

        $this->assertNotEmpty($result['items']);
        $paths = array_column($result['items'], 'path');
        $this->assertContains($ownerPath, $paths);
    }
    public function testListRepositoryContentsRootSentinels(): void
    {
        $repositoryName = 'test-list-repository-contents-root-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $empty = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, '');
            $dot = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, '.');
            $dotSlash = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, './');

            $repeatedDotSlash = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, './././');

            $this->assertNotEmpty($empty);
            $this->assertEquals(array_column($empty, 'name'), array_column($dot, 'name'));
            $this->assertEquals(array_column($empty, 'name'), array_column($dotSlash, 'name'));
            $this->assertEquals(array_column($empty, 'name'), array_column($repeatedDotSlash, 'name'));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testGetRepositoryContentRootSentinelPrefix(): void
    {
        $repositoryName = 'test-get-repository-content-root-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $direct = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'README.md');
            $prefixed = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, './README.md');
            $repeatedPrefix = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, './././README.md');

            $this->assertEquals($direct['content'], $prefixed['content']);
            $this->assertEquals($direct['content'], $repeatedPrefix['content']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testListRepositoryContentsMalformedNestedPath(): void
    {
        $repositoryName = 'test-list-repository-contents-malformed-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'src/main.php', '<?php');

            $clean = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'src');
            $embeddedDot = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'src/.');
            $doubleSlash = $this->vcsAdapter->listRepositoryContents(static::$owner, $repositoryName, 'src//');

            $this->assertNotEmpty($clean);
            $this->assertEquals(array_column($clean, 'name'), array_column($embeddedDot, 'name'));
            $this->assertEquals(array_column($clean, 'name'), array_column($doubleSlash, 'name'));
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryContentIsCaseSensitive(): void
    {
        $repositoryName = 'test-get-repository-content-case-' . \uniqid();

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $this->expectException(FileNotFound::class);
            $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'readme.md');
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetRepositoryContentReportsBlobSha(): void
    {
        $repositoryName = 'test-get-repository-content-sha-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $result = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'README.md');

            // Every provider here is git backed, so the sha is the blob hash
            $expected = \hash('sha1', 'blob ' . $result['size'] . "\0" . $result['content']);
            $this->assertSame($expected, $result['sha']);
        } finally {
            $this->discardRepositories($repositoryName);
        }
    }

    public function testGetCommitAuthorAvatar(): void
    {
        $this->skipUnlessSupported(static::$reportsCommitAuthorAvatar, 'commit author avatars');

        $repositoryName = 'test-get-commit-avatar-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            $commit = $this->vcsAdapter->getCommit(static::$owner, $repositoryName, $commitHash);

            $this->assertNotEmpty($commit['commitAuthorAvatar']);
            $this->assertStringContainsString(static::$avatarDomain, $commit['commitAuthorAvatar']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
    public function testGetRepositoryAfterDeleteFails(): void
    {
        $this->skipUnlessSupported(static::$deletesRepositoriesSynchronously, 'deleting a repository straight away');

        $repositoryName = 'test-get-deleted-repository-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
        $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);

        $this->expectException(RepositoryNotFound::class);
        $this->vcsAdapter->getRepository(static::$owner, $repositoryName);
    }

    public function testGetEventPush(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, ['file1.txt'], ['file2.txt'], ['file3.txt'])
        );
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame(static::$defaultBranch, $result['branch']);
        $this->assertSame(static::EVENT_REPOSITORY_ID, $result['repositoryId']);
        $this->assertSame(self::EVENT_REPOSITORY_NAME, $result['repositoryName']);
        $this->assertSame(self::EVENT_OWNER, $result['owner']);
        $this->assertSame(self::EVENT_COMMIT_HASH, $result['commitHash']);
        $this->assertSame(self::EVENT_COMMIT_MESSAGE, $result['headCommitMessage']);
        $this->assertSame(self::EVENT_AUTHOR_NAME, $result['headCommitAuthorName']);
        $this->assertSame(self::EVENT_AUTHOR_EMAIL, $result['headCommitAuthorEmail']);
        $this->assertNotEmpty($result['headCommitUrl']);
        $this->assertNotEmpty($result['repositoryUrl']);
        $this->assertNotEmpty($result['branchUrl']);
        $this->assertFalse($result['branchCreated']);
        $this->assertFalse($result['branchDeleted']);
        $this->assertEqualsCanonicalizing(
            static::$reportsAffectedFilesInPushEvent ? ['file1.txt', 'file2.txt', 'file3.txt'] : [],
            $result['affectedFiles']
        );
    }

    public function testGetEventPushDetectsBranchCreated(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, created: true)
        );
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertTrue($result['branchCreated']);
        $this->assertFalse($result['branchDeleted']);
    }

    public function testGetEventPushDetectsBranchDeleted(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, deleted: true)
        );
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertFalse($result['branchCreated']);
        $this->assertTrue($result['branchDeleted']);
    }

    public function testGetEventPullRequest(): void
    {
        $events = $this->vcsAdapter->getEvents(static::$pullRequestEventName, $this->pullRequestPayload());
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame('opened', $result['action']);
        $this->assertSame(self::EVENT_HEAD_BRANCH, $result['branch']);
        $this->assertSame(self::EVENT_PULL_REQUEST_NUMBER, $result['pullRequestNumber']);
        $this->assertSame(static::EVENT_REPOSITORY_ID, $result['repositoryId']);
        $this->assertSame(self::EVENT_REPOSITORY_NAME, $result['repositoryName']);
        $this->assertSame(self::EVENT_OWNER, $result['owner']);
        $this->assertSame(self::EVENT_COMMIT_HASH, $result['commitHash']);
        $this->assertFalse($result['external']);
    }

    public function testGetEventPullRequestDetectsExternal(): void
    {
        $events = $this->vcsAdapter->getEvents(static::$pullRequestEventName, $this->pullRequestPayload(external: true));
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertTrue($result['external']);
    }

    public function testGetEventInvalidPayload(): void
    {
        $this->expectException(Exception::class);
        $this->vcsAdapter->getEvents('push', 'invalid json');
    }

    public function testGetEventUnsupportedEvent(): void
    {
        $payload = json_encode(['test' => 'data']);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvents('unsupported_event', $payload);

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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
            $this->discardRepositories($repositoryName);
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
        $this->skipUnlessSupported(static::$supportsCommitStatuses, 'commit statuses');

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
            $this->discardRepositories($repositoryName);
        }
    }

    public function testUpdateCommitStatusWithNonExistingRepository(): void
    {
        $this->skipUnlessSupported(static::$supportsCommitStatuses, 'commit statuses');

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
