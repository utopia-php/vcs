<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git\GitHub;

class GitHubTest extends Base
{
    protected static string $owner = '';
    protected static string $defaultBranch = 'main';
    /** @var array<string> */
    protected static array $supportedWebhookScopes = [GitHub::WEBHOOK_SCOPE_INSTALLATION, GitHub::WEBHOOK_SCOPE_REPOSITORY];

    protected static string $avatarDomain = 'githubusercontent.com';
    protected static bool $supportsPullRequestCreation = false;
    protected static bool $supportsNamespaceListing = false;
    protected static bool $supportsCommitStatusLookup = false;
    protected static bool $supportsTags = false;
    protected static bool $supportsUserLookup = false;
    protected static bool $computesLanguagesAsynchronously = true;
    protected static bool $supportsWebhookDelivery = false;
    protected static bool $resolvesOwnerFromRepositoryId = false;
    protected static bool $rejectsInvalidRepositoryNames = false;

    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }
    protected static string $eventHeader = 'x-github-event';
    protected static string $signatureHeader = 'x-hub-signature-256';

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return 'https://github.com/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }

    protected function setupAdapter(): void
    {
        $privateKey = str_replace('\\n', "\n", System::getEnv('TESTS_GITHUB_PRIVATE_KEY') ?? '');
        $appId = System::getEnv('TESTS_GITHUB_APP_IDENTIFIER') ?? '';
        static::$installationId = System::getEnv('TESTS_GITHUB_INSTALLATION_ID') ?? '';

        if (empty($privateKey) || empty($appId) || empty(static::$installationId)) {
            $this->markTestSkipped('GitHub App credentials not configured');
        }

        $adapter = new GitHub(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: static::$installationId,
            privateKey: $privateKey,
            appId: $appId,
            accessToken: '',
            refreshToken: ''
        );

        if (empty(static::$owner)) {
            static::$owner = $adapter->getOwnerName(static::$installationId);
        }

        $this->vcsAdapter = $adapter;
    }

    protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false): string
    {
        return (string) json_encode([
            'created' => $created,
            'deleted' => $deleted,
            'ref' => 'refs/heads/' . $branch,
            'before' => 'abc123',
            'after' => self::EVENT_COMMIT_HASH,
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'full_name' => self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'private' => true,
                'html_url' => 'https://github.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'owner' => ['name' => self::EVENT_OWNER, 'login' => self::EVENT_OWNER],
            ],
            'installation' => ['id' => 1234],
            'head_commit' => [
                'id' => self::EVENT_COMMIT_HASH,
                'message' => self::EVENT_COMMIT_MESSAGE,
                'url' => 'https://github.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME . '/commit/' . self::EVENT_COMMIT_HASH,
                'author' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
            ],
            'commits' => [[
                'id' => self::EVENT_COMMIT_HASH,
                'added' => $added,
                'removed' => $removed,
                'modified' => $modified,
            ]],
            'sender' => [
                'html_url' => 'https://github.com/' . self::EVENT_AUTHOR_NAME,
                'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4',
            ],
        ]);
    }

    protected function pullRequestPayload(bool $external = false): string
    {
        $headOwner = $external ? 'someone-else' : self::EVENT_OWNER;

        return (string) json_encode([
            'action' => 'opened',
            'number' => self::EVENT_PULL_REQUEST_NUMBER,
            'pull_request' => [
                'id' => 1303283688,
                'state' => 'open',
                'html_url' => 'https://github.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME . '/pull/' . self::EVENT_PULL_REQUEST_NUMBER,
                'head' => [
                    'ref' => self::EVENT_HEAD_BRANCH,
                    'sha' => self::EVENT_COMMIT_HASH,
                    'label' => $headOwner . ':' . self::EVENT_HEAD_BRANCH,
                    'user' => ['login' => $headOwner],
                ],
                'base' => [
                    'ref' => static::$defaultBranch,
                    'label' => self::EVENT_OWNER . ':' . static::$defaultBranch,
                    'user' => ['login' => self::EVENT_OWNER],
                ],
                'user' => ['login' => $headOwner, 'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4'],
            ],
            'repository' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'full_name' => self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
                'owner' => ['login' => self::EVENT_OWNER, 'name' => self::EVENT_OWNER],
                'html_url' => 'https://github.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
            ],
            'installation' => ['id' => 9876],
            'sender' => ['html_url' => 'https://github.com/' . $headOwner],
        ]);
    }

    public function testGetEventInstallation(): void
    {
        $payload = json_encode([
            'action' => 'deleted',
            'installation' => [
                'id' => 1234,
                'account' => ['login' => 'vermakhushboo'],
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $events = $this->vcsAdapter->getEvents('installation', $payload);
        $this->assertIsArray($events);
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame('deleted', $result['action']);
        $this->assertSame('1234', $result['installationId']);
    }

    public function testListBranchesPagination(): void
    {
        $repositoryName = 'test-list-branches-pages-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $this->getLatestCommitEventually($repositoryName);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'branch-a', static::$defaultBranch);
            $this->vcsAdapter->createBranch(static::$owner, $repositoryName, 'branch-b', static::$defaultBranch);

            /** @var GitHub $adapter */
            $adapter = $this->vcsAdapter;

            // Both branches have to be listable before paging through them
            $this->assertEventually(function () use ($adapter, $repositoryName) {
                $this->assertCount(3, $adapter->listBranches(static::$owner, $repositoryName, 100, 1));
            });

            $page1 = $adapter->listBranches(static::$owner, $repositoryName, 1, 1);
            $this->assertSame(['branch-a'], $page1);

            $page2 = $adapter->listBranches(static::$owner, $repositoryName, 1, 2);
            $this->assertSame(['branch-b'], $page2);

            $all = $adapter->listBranches(static::$owner, $repositoryName, 100, 1);
            $this->assertEqualsCanonicalizing([static::$defaultBranch, 'branch-a', 'branch-b'], $all);

            $searchResults = $adapter->listBranches(static::$owner, $repositoryName, 100, 1, 'branch');
            $this->assertEqualsCanonicalizing(['branch-a', 'branch-b'], $searchResults);

            $noMatch = $adapter->listBranches(static::$owner, $repositoryName, 100, 1, 'xyz');
            $this->assertEmpty($noMatch);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }
}
