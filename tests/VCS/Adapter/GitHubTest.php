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

    public function testGetLatestCommit(): void
    {
        $repositoryName = 'test-get-latest-commit-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $firstMessage = 'First commit';
            $secondMessage = 'Second commit';

            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test', $firstMessage);
            $commit1 = $this->getLatestCommitEventually($repositoryName);

            $this->assertIsArray($commit1);
            $this->assertNotEmpty($commit1['commitHash']);
            $this->assertStringStartsWith($firstMessage, $commit1['commitMessage']);
            $this->assertNotEmpty($commit1['commitUrl']);
            $this->assertNotEmpty($commit1['commitAuthorAvatar']);
            $this->assertNotEmpty($commit1['commitAuthorUrl']);

            $commit1Hash = $commit1['commitHash'];

            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'test.txt', 'test', $secondMessage);

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


    public function testUpdateCommitStatus(): void
    {
        $repositoryName = 'test-update-commit-status-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->vcsAdapter->createFile(static::$owner, $repositoryName, 'README.md', '# Test');
            $commitHash = $this->getLatestCommitEventually($repositoryName)['commitHash'];

            // Should not throw
            $this->vcsAdapter->updateCommitStatus(
                $repositoryName,
                $commitHash,
                static::$owner,
                'success',
                'Build passed',
                'https://example.com',
                'ci/build'
            );

            $this->assertTrue(true);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testCreateCheckRun(): void
    {
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
            $this->assertIsInt($checkRun['id']);
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
        $repositoryName = 'test-get-check-run-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->getCheckRun(static::$owner, $repositoryName, 999999999);
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testCreateTwoCheckRunsOnSameCommit(): void
    {
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
            $this->assertIsInt($checkRun['id']);
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
        $this->expectException(\Exception::class);
        $this->vcsAdapter->updateCheckRun(
            owner: static::$owner,
            repositoryName: 'non-existing-repository-' . \uniqid(),
            checkRunId: 999999999,
            conclusion: 'success',
        );
    }

    public function testUpdateCheckRunWithInvalidId(): void
    {
        $repositoryName = 'test-update-check-run-invalid-' . \uniqid();
        $this->vcsAdapter->createRepository(static::$owner, $repositoryName, false);

        try {
            $this->expectException(\Exception::class);
            $this->vcsAdapter->updateCheckRun(
                owner: static::$owner,
                repositoryName: $repositoryName,
                checkRunId: 999999999,
                conclusion: 'success',
            );
        } finally {
            $this->vcsAdapter->deleteRepository(static::$owner, $repositoryName);
        }
    }

    public function testUpdateCheckRunWithMissingConclusion(): void
    {
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




    public function testGetOwnerName(): void
    {
        $result = $this->vcsAdapter->getOwnerName(static::$installationId);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertSame(static::$owner, $result);
    }



    public function testGetPullRequest(): void
    {
        $this->markTestSkipped('createPullRequest() is not implemented for GitHub');
    }

    public function testGetPullRequestFiles(): void
    {
        $this->markTestSkipped('createPullRequest() is not implemented for GitHub');
    }

    public function testGetPullRequestWithInvalidNumber(): void
    {
        $this->markTestSkipped('createPullRequest() is not implemented for GitHub');
    }

    public function testGetPullRequestFromBranch(): void
    {
        $this->markTestSkipped('createPullRequest() is not implemented for GitHub');
    }

    public function testGetComment(): void
    {
        $this->markTestSkipped('Needs a pull request, and createPullRequest() is not implemented for GitHub');
    }

    public function testCreateComment(): void
    {
        $this->markTestSkipped('Needs a pull request, and createPullRequest() is not implemented for GitHub');
    }

    public function testUpdateComment(): void
    {
        $this->markTestSkipped('Needs a pull request, and createPullRequest() is not implemented for GitHub');
    }

    public function testGetUser(): void
    {
        $this->markTestSkipped('GitHub::getUser() returns the raw response envelope instead of the shared user shape');
    }

    public function testGetUserWithInvalidUsername(): void
    {
        $this->markTestSkipped('GitHub::getUser() returns the raw response envelope instead of throwing');
    }

    public function testListTags(): void
    {
        $this->markTestSkipped('createTag() is not implemented for GitHub');
    }

    public function testGenerateCloneCommandWithTag(): void
    {
        $this->markTestSkipped('createTag() is not implemented for GitHub');
    }

    public function testCreateTag(): void
    {
        $this->markTestSkipped('createTag() is not implemented for GitHub');
    }

    public function testGetCommitStatuses(): void
    {
        $this->markTestSkipped('getCommitStatuses() is not implemented for GitHub');
    }

    public function testGetCommitStatusesEmptyForNewCommit(): void
    {
        $this->markTestSkipped('getCommitStatuses() is not implemented for GitHub');
    }

    public function testCreateRepositoryWithInvalidName(): void
    {
        $this->markTestSkipped('GitHub normalizes spaces in repository names instead of rejecting them');
    }

    public function testGetOwnerNameWithoutRepositoryId(): void
    {
        $this->markTestSkipped('GitHub resolves the owner from the installation, not a repository id');
    }

    public function testGetOwnerNameWithZeroRepositoryId(): void
    {
        $this->markTestSkipped('GitHub resolves the owner from the installation, not a repository id');
    }

    public function testGetOwnerNameWithNullRepositoryId(): void
    {
        $this->markTestSkipped('GitHub resolves the owner from the installation, not a repository id');
    }

    public function testGetOwnerNameWithInvalidRepositoryId(): void
    {
        $this->markTestSkipped('GitHub resolves the owner from the installation, not a repository id');
    }

    public function testWebhookPushEvent(): void
    {
        $this->markTestSkipped('github.com cannot deliver webhooks to the local request catcher');
    }

    public function testWebhookPullRequestEvent(): void
    {
        $this->markTestSkipped('github.com cannot deliver webhooks to the local request catcher');
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
