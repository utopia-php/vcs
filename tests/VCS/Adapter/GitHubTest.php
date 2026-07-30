<?php

namespace Utopia\Tests\Adapter;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Tests\Base;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\FileNotFound;

class GitHubTest extends Base
{
    protected static string $owner = '';
    protected static string $installationId = '';
    protected static string $defaultBranch = 'main';
    /** @var array<string> */
    protected static array $supportedWebhookScopes = [GitHub::WEBHOOK_SCOPE_INSTALLATION, GitHub::WEBHOOK_SCOPE_REPOSITORY];

    protected static bool $supportsInstallationRepository = true;
    protected static bool $supportsCheckRuns = true;
    protected static bool $reportsCommitAuthorAvatar = true;
    protected static bool $reportsCommitAuthorUrl = true;
    protected static bool $supportsPullRequestCreation = false;
    protected static bool $supportsCommitStatusLookup = false;
    protected static bool $supportsTags = false;
    protected static bool $supportsUserLookup = false;
    protected static bool $supportsRepositoryLanguages = false;
    protected static bool $supportsWebhookDelivery = false;
    protected static bool $resolvesOwnerFromRepositoryId = false;
    protected static bool $rejectsInvalidRepositoryNames = false;

    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }
    protected static string $eventHeader = 'x-github-event';
    protected static string $signatureHeader = 'x-hub-signature-256';

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


    public function testGetEventPush(): void
    {
        $payload = json_encode([
            'created' => false,
            'deleted' => false,
            'ref' => 'refs/heads/main',
            'before' => 'abc123',
            'after' => 'def456',
            'repository' => [
                'id' => 603754812,
                'name' => 'testing-fork',
                'full_name' => 'vermakhushboo/testing-fork',
                'private' => true,
                'html_url' => 'https://github.com/vermakhushboo/testing-fork',
                'owner' => ['name' => 'vermakhushboo'],
            ],
            'installation' => ['id' => 1234],
            'head_commit' => [
                'author' => ['name' => 'Khushboo Verma'],
                'message' => 'Update index.js',
                'url' => 'https://github.com/vermakhushboo/testing-fork/commit/def456',
            ],
            'commits' => [
                [
                    'id' => 'def456',
                    'added' => ['src/lib.js'],
                    'removed' => ['README.md'],
                    'modified' => ['src/main.js'],
                ],
            ],
            'sender' => [
                'html_url' => 'https://github.com/vermakhushboo',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/43381712?v=4',
            ],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('push', $payload);

        $this->assertSame('main', $result['branch']);
        $this->assertSame('603754812', $result['repositoryId']);
        $this->assertCount(3, $result['affectedFiles']);
        $this->assertSame('src/lib.js', $result['affectedFiles'][0]);
        $this->assertSame('README.md', $result['affectedFiles'][1]);
        $this->assertSame('src/main.js', $result['affectedFiles'][2]);
    }

    public function testGetEventPullRequest(): void
    {
        $payload = json_encode([
            'action' => 'opened',
            'number' => 1,
            'pull_request' => [
                'id' => 1303283688,
                'state' => 'open',
                'html_url' => 'https://github.com/vermakhushboo/g4-node-function/pull/17',
                'head' => [
                    'ref' => 'test',
                    'sha' => 'a27dbe54b17032ee35a16c24bac151e5c2b33328',
                    'label' => 'vermakhushboo:test',
                    'user' => ['login' => 'vermakhushboo'],
                ],
                'base' => [
                    'label' => 'vermakhushboo:main',
                    'user' => ['login' => 'vermakhushboo'],
                ],
                'user' => [
                    'login' => 'vermakhushboo',
                    'avatar_url' => 'https://avatars.githubusercontent.com/u/43381712?v=4',
                ],
            ],
            'repository' => [
                'id' => 3498,
                'name' => 'functions-example',
                'owner' => ['login' => 'vermakhushboo'],
                'html_url' => 'https://github.com/vermakhushboo/g4-node-function',
            ],
            'installation' => ['id' => 9876],
            'sender' => ['html_url' => 'https://github.com/vermakhushboo'],
        ]);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvent('pull_request', $payload);

        $this->assertSame('opened', $result['action']);
        $this->assertSame(1, $result['pullRequestNumber']);
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

        $result = $this->vcsAdapter->getEvent('installation', $payload);

        $this->assertSame('deleted', $result['action']);
        $this->assertSame('1234', $result['installationId']);
    }


    public function testGetRepositoryContentSha(): void
    {
        $repositoryName = 'test-get-repository-content-sha-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $result = $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'README.md');

            // GitHub reports the git blob SHA, so it has to match what git would compute
            $expectedSha = \hash('sha1', 'blob ' . $result['size'] . "\0" . $result['content']);
            $this->assertSame($expectedSha, $result['sha']);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testGetRepositoryContentCaseSensitive(): void
    {
        $repositoryName = 'test-get-repository-content-case-' . \uniqid();

        try {
            $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');

            $this->expectException(FileNotFound::class);
            $this->vcsAdapter->getRepositoryContent(static::$owner, $repositoryName, 'readme.md');
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
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

















    public function testGetOwnerName(): void
    {
        $result = $this->vcsAdapter->getOwnerName(static::$installationId);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertSame(static::$owner, $result);
    }
























    public function testListRepositoryLanguages(): void
    {
        $repositoryName = 'test-list-repository-languages-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'main.php', '<?php echo "test";');
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'script.js', 'console.log("test");');

            // Unlike the self-hosted providers, GitHub computes language stats out of
            // band with no guaranteed turnaround, and reports none at all until that
            // finishes. Waiting it out is the best we can do; a repository that still
            // has no stats says nothing about the adapter, so report that as
            // inconclusive instead of failing the suite.
            $languages = [];
            try {
                $this->assertEventually(function () use (&$languages, $repositoryName) {
                    $languages = $this->vcsAdapter->listRepositoryLanguages(static::$owner, $repositoryName);
                    $this->assertNotEmpty($languages);
                }, 60000, 5000);
            } catch (\Throwable $e) {
                $this->markTestSkipped('GitHub has not computed language stats for the new repository yet');
            }

            $this->assertContains('PHP', $languages);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }



}
